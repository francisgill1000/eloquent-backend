<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\User;
use App\Models\WaContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Customer-facing in-app Live Chat with a shop. Same guest-first identity as
 * favourites/bookings: the rezzy-customer device id (X-Device-Id header) keys
 * the thread, no login required. Replies come from the shared AI pipeline
 * (ProcessWaReply) and land as stored rows this controller serves back.
 */
class ChatController extends Controller
{
    public function messages(Request $request, Shop $shop)
    {
        $contact = WaContact::where('shop_id', $shop->id)
            ->where('channel', 'app')
            ->where('device_id', $this->deviceId($request))
            ->first();

        if (!$contact) {
            return response()->json(['data' => []]);
        }

        $query = $contact->messages()->orderBy('id');

        $sinceId = (int) $request->query('since_id', 0);
        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        } else {
            // initial load: last 200 messages
            $ids = $contact->messages()->orderByDesc('id')->limit(200)->pluck('id');
            $query->whereIn('id', $ids);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function send(Request $request, Shop $shop)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
        ]);

        $contact = $this->contactFor($request, $shop);
        $message = $contact->recordMessage('in', $data['text']);

        ProcessWaReply::dispatch($message->id);

        return response()->json(['data' => $message], 201);
    }

    /**
     * Customer records a voice note in any language. We store the audio, the
     * reply pipeline transcribes it (Whisper) and answers — in voice and text.
     */
    public function voice(Request $request, Shop $shop)
    {
        $request->validate([
            'audio' => ['required', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/m4a,audio/wav,video/webm', 'max:15360'],
        ]);

        $contact = $this->contactFor($request, $shop);

        $file = $request->file('audio');
        $ext = match (true) {
            str_contains((string) $file->getMimeType(), 'webm') => 'webm',
            str_contains((string) $file->getMimeType(), 'ogg') => 'ogg',
            str_contains((string) $file->getMimeType(), 'mp4'), str_contains((string) $file->getMimeType(), 'm4a') => 'm4a',
            str_contains((string) $file->getMimeType(), 'mpeg') => 'mp3',
            str_contains((string) $file->getMimeType(), 'wav') => 'wav',
            default => 'webm',
        };

        $path = "wa-media/app/{$contact->id}/" . Str::uuid() . ".{$ext}";
        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        // Stored as a voice message; ProcessWaReply transcribes from the file.
        $message = $contact->recordMessage('in', '🎤 …', 'audio', null, null, [
            'media_path' => $path,
            'media_mime' => $file->getMimeType(),
        ]);

        ProcessWaReply::dispatch($message->id);

        return response()->json(['data' => $message], 201);
    }

    /** Resolve (or create) the app-channel contact for this device, attaching customer identity. */
    private function contactFor(Request $request, Shop $shop): WaContact
    {
        $contact = WaContact::firstOrCreate(
            ['shop_id' => $shop->id, 'device_id' => $this->deviceId($request)],
            ['channel' => 'app']
        );

        // A logged-in customer's name/phone makes the owner inbox readable.
        $user = $request->user('sanctum');
        if ($user instanceof User) {
            $updates = [];
            if (!$contact->name && $user->name) {
                $updates['name'] = $user->name;
            }
            if (!$contact->wa_number && $user->phone) {
                $updates['wa_number'] = preg_replace('/\D+/', '', (string) $user->phone) ?: null;
            }
            if ($updates) {
                $contact->update($updates);
            }
        }

        return $contact;
    }

    private function deviceId(Request $request): string
    {
        $id = trim((string) $request->header('X-Device-Id'));
        abort_if($id === '' || strlen($id) > 64, 422, 'X-Device-Id header required');

        return $id;
    }
}
