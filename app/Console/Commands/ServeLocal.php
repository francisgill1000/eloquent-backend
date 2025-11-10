<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class ServeLocal extends Command
{
    protected $signature = 'serve:local {--port=8000 : The port to serve the application on}';
    protected $description = 'Serve the application on the local network IP (accessible by other devices)';

    public function handle()
    {
        // Get local IP address
        // $localIp = getHostByName(getHostName());
        $localIp = "192.168.56.1";

        $port = $this->option('port');

        $this->info("Starting Laravel development server on http://{$localIp}:{$port}");
        $this->newLine();

        Artisan::call('serve', [
            '--host' => $localIp,
            '--port' => $port
        ]);

    }

    private function getLocalIp()
    {
        $ifconfig = shell_exec('ipconfig');
        preg_match_all('/IPv4 Address[^\:]*:\s*([\d\.]+)/', $ifconfig, $matches);

        foreach ($matches[1] as $ip) {
            if (str_starts_with($ip, '192.168.')) {
                return $ip;
            }
        }

        return '127.0.0.1'; // fallback
    }
}
