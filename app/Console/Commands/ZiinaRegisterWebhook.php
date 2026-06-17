<?php

namespace App\Console\Commands;

use App\Services\Ziina;
use Illuminate\Console\Command;

class ZiinaRegisterWebhook extends Command
{
    protected $signature = 'ziina:register-webhook {url? : Override the webhook URL}';

    protected $description = 'Register (overwrite) the Ziina account webhook URL';

    public function handle(Ziina $ziina): int
    {
        $url = $this->argument('url')
            ?? rtrim((string) config('app.url'), '/') . '/api/ziina/webhook';

        $secret = config('services.ziina.webhook_secret');

        $this->info("Registering Ziina webhook: {$url}");
        if (empty($secret)) {
            $this->warn('No ZIINA_WEBHOOK_SECRET set — webhooks will be unsigned (not recommended).');
        }

        try {
            $result = $ziina->registerWebhook($url, $secret);
        } catch (\Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Done.');
        $this->line(json_encode($result, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
