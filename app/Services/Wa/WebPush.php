<?php

namespace App\Services\Wa;

use App\Models\WaPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as PushClient;

/**
 * Browser push notifications for new WhatsApp messages (single-tenant: all
 * subscriptions are Francis's). Fire-and-forget; dead endpoints are pruned.
 * Ported from the push logic in whatsapp-autoreply/server.js.
 */
class WebPush
{
    public function enabled(): bool
    {
        return (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
    }

    public function notify(string $title, string $body, ?string $tag = null): void
    {
        if (!$this->enabled()) {
            return;
        }

        $subscriptions = WaPushSubscription::all();
        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $client = new PushClient(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]]);

            $payload = json_encode(['title' => $title, 'body' => $body, 'tag' => $tag]);
            foreach ($subscriptions as $sub) {
                $client->queueNotification(Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->p256dh,
                    'authToken' => $sub->auth,
                ]), $payload);
            }

            foreach ($client->flush() as $report) {
                $status = $report->getResponse()?->getStatusCode();
                if (!$report->isSuccess() && in_array($status, [404, 410], true)) {
                    WaPushSubscription::where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (\Throwable $e) {
            // Push must never break the reply pipeline.
            Log::warning('WA web push failed: ' . $e->getMessage());
        }
    }
}
