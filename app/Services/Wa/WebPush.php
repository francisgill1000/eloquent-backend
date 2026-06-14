<?php

namespace App\Services\Wa;

use App\Models\Shop;
use App\Models\WaPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as PushClient;

/**
 * Browser push notifications for new chat messages. A message notifies the
 * owning shop's browsers AND the master account (the platform owner, who
 * watches every shop's leads). Non-master shops never see each other's
 * notifications. Fire-and-forget; dead endpoints are pruned.
 */
class WebPush
{
    public function enabled(): bool
    {
        return (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
    }

    public function notify(string $title, string $body, ?string $tag = null, ?int $shopId = null): void
    {
        if (!$this->enabled()) {
            return;
        }

        // No owner shop (e.g. a WA number not linked to any shop) → notify
        // nobody rather than everybody.
        if (!$shopId) {
            return;
        }

        $subscriptions = $this->recipientsFor($shopId);
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

    /**
     * Browsers to notify for a message owned by $shopId: that shop's own
     * subscribers plus the master account (the platform owner sees every
     * shop's leads). Non-master shops never receive each other's messages.
     */
    public function recipientsFor(int $shopId): \Illuminate\Support\Collection
    {
        $masterId = Shop::where('is_master', true)->value('id');
        $shopIds = array_values(array_unique(array_filter([$shopId, $masterId])));

        return WaPushSubscription::whereIn('shop_id', $shopIds)->get();
    }
}
