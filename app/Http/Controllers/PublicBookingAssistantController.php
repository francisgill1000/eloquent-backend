<?php
namespace App\Http\Controllers;

use App\Models\Shop;
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
    ) {}

    public function text(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']]);
        return $this->respond($shop, $data['text'], null, $this->readState($request), $this->readHistory($request));
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

        return $this->respond($shop, $transcript, $transcript, $this->readState($request), $this->readHistory($request));
    }

    /**
     * @param array<string,mixed> $state
     * @param array<int,array{role:string,content:string}> $history prior turns, oldest first
     */
    protected function respond(Shop $shop, string $text, ?string $transcript, array $state, array $history = []): JsonResponse
    {
        $shop->loadMissing('catalogs');
        $system = PublicBookingPrompt::for($shop, $state);

        // Give the model the conversation so far so it doesn't re-ask what the
        // customer already answered, then the current turn last.
        $messages = array_merge($history, [['role' => 'user', 'content' => $text]]);

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

        $payload = ['reply_text' => $reply, 'fields' => (object) $fields, 'ready' => $ready];
        if ($transcript !== null) {
            $payload['transcript'] = $transcript;
        }
        return response()->json($payload, 201);
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
