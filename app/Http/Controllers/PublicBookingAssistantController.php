<?php
namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Assistant\ConversationStore;
use App\Services\Tts\TtsSynthesizer;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\Transcriber;
use App\Support\Assistant\PublicBookingPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public, customer-facing booking assistant. No auth: a customer opens the
 * shop's booking link and speaks/types their request. Deliberately minimal —
 * it can ONLY read the shop's services and fill booking fields. It never
 * touches revenue, other bookings, or any owner tool.
 */
class PublicBookingAssistantController extends Controller
{
    public function __construct(
        protected ClaudeClient $claude,
        protected Transcriber $transcriber,
        protected ConversationStore $store,
        protected TtsSynthesizer $tts,
    ) {}

    public function text(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);
        return $this->respond($shop, $data['text'], null, $this->readState($request), $this->readHistory($request), $this->deviceId($request));
    }

    public function voice(Request $request, Shop $shop): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/m4a,audio/wav,video/webm', 'max:25600'],
        ]);

        $file = $request->file('audio');
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $file->getMimeType() ?: 'audio/webm';

        $transcript = null;
        try {
            $transcript = $this->transcriber->transcribe($bytes, $mime);
        } catch (\Throwable $e) {
            Log::warning('public booking transcription failed: ' . $e->getMessage());
        }

        if (! $transcript) {
            return response()->json([
                'transcript' => '',
                'reply_text' => "Sorry, I didn't catch that — please try again.",
                'fields' => (object) [],
                'ready' => false,
            ], 201);
        }

        return $this->respond($shop, $transcript, $transcript, $this->readState($request), $this->readHistory($request), $this->deviceId($request));
    }

    /**
     * @param array<string,mixed> $state
     * @param array<int,array{role:string,content:string}> $history prior turns, oldest first
     */
    protected function respond(Shop $shop, string $text, ?string $transcript, array $state, array $history, string $deviceId): JsonResponse
    {
        $shop->loadMissing('catalogs');
        $system = PublicBookingPrompt::for($shop, $state);

        // Persist the conversation (tagged 'customer') so it shows in the shop's
        // Conversations list, exactly like the owner's own threads. When we have a
        // stored thread we rebuild context from it — more reliable than trusting
        // the browser to send history — falling back to the client history only
        // when there's no device id to key the thread by.
        $conversation = $deviceId !== '' ? $this->store->forCustomer($shop, $deviceId, $text) : null;
        $context = $conversation ? $this->store->contextFor($conversation) : $history;
        $messages = array_merge($context, [['role' => 'user', 'content' => $text]]);

        $fields = [];
        $ready = false;
        $reply = '';
        try {
            $res = $this->claude->agentReply($system, $messages, [$this->setBookingTool()]);
            $reply = $res['text'];
            if ($res['toolUse'] && $res['toolUse']['name'] === 'set_booking') {
                $input = $res['toolUse']['input'];
                $ready = (bool) ($input['ready'] ?? false);
                unset($input['ready']);
                $allowed = ['service', 'date', 'start_time', 'customer_name', 'customer_phone'];
                $fields = collect($input)
                    ->only($allowed)
                    ->filter(fn ($v) => $v !== null && $v !== '')
                    ->all();
            }
        } catch (\Throwable $e) {
            Log::warning('public booking assistant failed: ' . $e->getMessage());
        }

        if (trim($reply) === '') {
            $reply = $this->fallbackReply(array_merge($state, $fields));
        }

        // Save the turn (both sides). Once we learn the customer's name, use it as
        // the thread title so the owner's list reads well.
        if ($conversation) {
            $this->store->append($conversation, 'user', $text);
            $this->store->append($conversation, 'assistant', $reply);
            if (! empty($fields['customer_name']) && ! str_contains((string) $conversation->title, (string) $fields['customer_name'])) {
                $conversation->update(['title' => 'Booking — ' . $fields['customer_name']]);
            }
        }

        $payload = ['reply_text' => $reply, 'fields' => (object) $fields, 'ready' => $ready];
        if ($transcript !== null) {
            $payload['transcript'] = $transcript;
        }
        // Voice the reply here and return it inline (base64 MP3) so the client
        // plays it straight away instead of making a second /tts round trip —
        // one fewer mobile network hop per turn. Best-effort: if TTS is down the
        // client falls back to its own /tts call.
        $audio = $this->tts->mp3($reply, 'nova');
        if ($audio !== null) {
            $payload['reply_audio'] = base64_encode($audio);
        }
        return response()->json($payload, 201);
    }

    private function deviceId(Request $request): string
    {
        return trim((string) $request->header('X-Device-Id'));
    }

    /**
     * Record the confirmed booking's reference as a final line in the customer's
     * saved conversation, so staff reviewing the thread can tie it to the actual
     * booking. Best-effort: never errors the customer's flow.
     */
    public function recordBooking(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate(['booking_id' => ['required', 'integer']]);
        $deviceId = $this->deviceId($request);

        $booking = \App\Models\Booking::where('shop_id', $shop->id)->find($data['booking_id']);
        if (! $booking || $deviceId === '') {
            return response()->json(['ok' => false]);
        }

        $ref = $booking->booking_reference ?: ('#' . $booking->id);
        $services = collect($booking->services ?? [])->pluck('title')->filter()->implode(', ') ?: 'Appointment';
        $time = substr((string) $booking->getRawOriginal('start_time'), 0, 5);
        $note = "✅ Booked — Reference {$ref}. {$services} on {$booking->date} at {$time} for {$booking->customer_name} ({$booking->customer_whatsapp}).";

        $conversation = $this->store->forCustomer($shop, $deviceId, 'Booking');
        $this->store->append($conversation, 'assistant', $note);

        return response()->json(['ok' => true, 'reference' => $ref]);
    }

    /** @param array<string,mixed> $f */
    private function fallbackReply(array $f): string
    {
        $asks = [
            'service' => 'Which service would you like?',
            'date' => 'What day works for you?',
            'start_time' => 'What time would you like?',
            'customer_name' => 'And your name?',
            'customer_phone' => "What's the best number to reach you?",
        ];
        foreach ($asks as $key => $question) {
            if (empty($f[$key])) {
                return $question;
            }
        }
        return "Perfect — I'm booking that for you now.";
    }

    private function readState(Request $request): array
    {
        $state = $request->input('state', []);
        if (is_string($state)) {
            $state = json_decode($state, true);
        }
        return is_array($state) ? $state : [];
    }

    /**
     * The recent conversation, sanitised: only user/assistant turns with a
     * non-empty string content, each capped in length, most-recent 12 kept.
     * Accepts a JSON string (multipart) or an array (JSON body).
     *
     * @return array<int,array{role:string,content:string}>
     */
    private function readHistory(Request $request): array
    {
        $history = $request->input('history', []);
        if (is_string($history)) {
            $history = json_decode($history, true);
        }
        if (! is_array($history)) {
            return [];
        }

        return collect($history)
            ->filter(fn ($m) => is_array($m)
                && in_array($m['role'] ?? null, ['user', 'assistant'], true)
                && is_string($m['content'] ?? null)
                && trim($m['content']) !== '')
            ->map(fn ($m) => ['role' => $m['role'], 'content' => mb_substr($m['content'], 0, 1000)])
            ->slice(-12)
            ->values()
            ->all();
    }

    private function setBookingTool(): array
    {
        return [
            'name' => 'set_booking',
            'description' => 'Record the booking details the customer has given so far. Call whenever any detail is provided or changed.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'service' => ['type' => 'string', 'description' => 'Service title, matching one the shop offers.'],
                    'date' => ['type' => 'string', 'description' => 'Booking date, YYYY-MM-DD.'],
                    'start_time' => ['type' => 'string', 'description' => '24-hour start time, HH:MM.'],
                    'customer_name' => ['type' => 'string'],
                    'customer_phone' => ['type' => 'string', 'description' => 'Customer phone / WhatsApp number.'],
                    'ready' => ['type' => 'boolean', 'description' => 'True only when all five details are known.'],
                ],
            ],
        ];
    }
}
