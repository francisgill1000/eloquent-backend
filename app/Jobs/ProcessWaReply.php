<?php

namespace App\Jobs;

use App\Actions\Wa\OnboardBusiness;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\PersonaResolver;
use App\Services\Wa\Speech;
use App\Services\Wa\Transcriber;
use App\Services\Wa\WebPush;
use App\Services\WhatsAppCloud;
use App\Support\Wa\ConversationHistory;
use App\Support\Wa\Greetings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generate and send the auto-reply for one stored inbound WhatsApp message.
 * Ports the pipeline of the retired whatsapp-autoreply Node service:
 * skip reactions → canned greeting → transcribe voice → resolve persona →
 * Claude (tool-enabled for sales leads) → voice-out → send → record.
 *
 * Fail-quiet: any error logs and stops. The inbound is already stored, so
 * the shop can always answer manually in bizrezzy. Never retried ($tries=1)
 * so a half-failure can never double-send.
 */
class ProcessWaReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $waMessageId)
    {
    }

    public function handle(
        WhatsAppCloud $wa,
        ClaudeClient $claude,
        PersonaResolver $personas,
        Transcriber $transcriber,
        Speech $speech,
        WebPush $push,
        OnboardBusiness $onboard,
    ): void {
        $message = WaMessage::with('waContact.waAccount.shop')->find($this->waMessageId);
        if (!$message || $message->direction !== 'in') {
            return;
        }

        $contact = $message->waContact;
        $account = $contact?->waAccount;
        if (!$contact || !$account) {
            return;
        }

        // Idempotency: if anything was sent on this thread after the inbound
        // arrived (manual reply, earlier job run), never auto-reply again.
        $alreadyAnswered = $contact->messages()
            ->where('direction', 'out')
            ->where('id', '>', $message->id)
            ->exists();
        if ($alreadyAnswered) {
            return;
        }

        $from = $contact->wa_number;
        $name = $contact->name ?: ('+' . $from);

        // Emoji-like signals (reactions 👍, stickers) — store-only, never reply.
        if (in_array($message->type, ['reaction', 'sticker'], true)) {
            $push->notify($name, "[{$message->type}]", $from);
            return;
        }

        // Emoji-only / symbol-only texts ("👍", "❤️🙏") — store-only, never reply.
        if ($message->type === 'text' && !preg_match('/[\p{L}\p{N}]/u', (string) $message->body)) {
            $push->notify($name, (string) $message->body, $from);
            return;
        }

        $salesNumber = $personas->isSalesNumber($account);
        // When an override is active we're in a live persona test — the canned
        // greeting must NOT fire; every message goes through the override prompt.
        $overrideActive = $salesNumber && $personas->salesOverride() !== null;

        // Bare greetings: instant canned welcome — no Claude call, no API cost.
        if ($message->type === 'text' && !$overrideActive && Greetings::isBare($message->body)) {
            $welcome = $salesNumber
                ? "Hi! 😊 Welcome to Rezzy — we help businesses get more bookings on WhatsApp, 24/7. What kind of business do you run?"
                : 'Hi! 😊 Welcome to ' . ($account->shop?->name ?? 'our shop') . '. How can I help you today?';
            $push->notify($name, (string) $message->body, $from);
            $this->sendText($wa, $account, $contact, $welcome);
            return;
        }

        // Voice notes: transcribe, then answer them like normal text.
        $isVoice = false;
        if (in_array($message->type, ['audio', 'voice'], true) && $transcriber->available()) {
            $transcript = $this->transcribe($wa, $transcriber, $account, $message);
            if ($transcript) {
                $isVoice = true;
                $message->update(['body' => '🎤 ' . $transcript]);
                $contact->update(['last_message_preview' => mb_substr($message->body, 0, 500)]);
            }
        }

        // Remaining non-text (images, files, unheard voice) — polite fallback.
        if ($message->type !== 'text' && !$isVoice) {
            $push->notify($name, "Sent a {$message->type} message", $from);
            $this->sendText(
                $wa, $account, $contact,
                "Hi! 😊 I couldn't open that — could you please type your message? I'll help you right away!"
            );
            return;
        }

        $push->notify($name, (string) $message->body, $from);

        ['prompt' => $prompt, 'offerTools' => $offerTools] = $personas->resolve($account, $from);
        $history = ConversationHistory::for($contact);
        if (!$history) {
            return;
        }

        try {
            $onboarded = false;
            if ($offerTools) {
                $agent = $claude->agentReply($prompt, $history, [OnboardBusiness::TOOL]);
                $reply = $agent['text'];
                if (($agent['toolUse']['name'] ?? null) === 'create_business_account') {
                    // Deterministic credentials message — the model never types IDs/PINs.
                    $reply = $onboard->run($agent['toolUse']['input'], $from);
                    $onboarded = true;
                }
                $reply = $reply !== '' ? $reply : 'One moment…';
            } else {
                $reply = $claude->reply($prompt, $history);
            }

            // Voice in → voice out. Credential messages always go as TEXT so
            // they can be copied. Any TTS hiccup falls back to plain text.
            if ($isVoice && !$onboarded && $speech->available()) {
                try {
                    $audio = $speech->synthesize($reply);
                    $mediaId = $wa->uploadMedia($account, $audio, 'audio/ogg');
                    $sent = $wa->sendVoice($account, $from, $mediaId);
                    $contact->recordMessage('out', '🔊 ' . $reply, 'audio', $sent['messages'][0]['id'] ?? null, 'sent', ['media_id' => $mediaId]);
                    return;
                } catch (\Throwable $e) {
                    Log::warning("WA voice reply failed for {$from}: " . $e->getMessage());
                }
            }

            $this->sendText($wa, $account, $contact, $reply);
        } catch (\Throwable $e) {
            // Fail quiet: the inbound is stored; the shop can answer manually.
            Log::error("WA auto-reply failed for {$from}: " . $e->getMessage());
        }
    }

    private function sendText(WhatsAppCloud $wa, WaAccount $account, WaContact $contact, string $text): void
    {
        try {
            $sent = $wa->sendText($account, $contact->wa_number, $text);
            $contact->recordMessage('out', $text, 'text', $sent['messages'][0]['id'] ?? null, 'sent');
        } catch (\Throwable $e) {
            Log::error("WA send failed for {$contact->wa_number}: " . $e->getMessage());
        }
    }

    /** Audio bytes come from the already-downloaded media file, else Graph. */
    private function transcribe(WhatsAppCloud $wa, Transcriber $transcriber, WaAccount $account, WaMessage $message): ?string
    {
        try {
            $bytes = null;
            $mime = $message->media_mime ?: 'audio/ogg';
            if ($message->media_path && Storage::disk('public')->exists($message->media_path)) {
                $bytes = Storage::disk('public')->get($message->media_path);
            } elseif ($message->media_id) {
                $download = $wa->downloadMedia($account, $message->media_id);
                if ($download) {
                    $bytes = $download['data'];
                    $mime = $download['mime'];
                }
            }

            return $bytes ? $transcriber->transcribe($bytes, $mime) : null;
        } catch (\Throwable $e) {
            Log::warning('WA voice transcription failed: ' . $e->getMessage());
            return null;
        }
    }
}
