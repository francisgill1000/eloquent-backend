<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestGoogleMapsLocation extends Command
{
    protected $signature = 'location:test-google {lat=25.3376477} {lon=55.3932222}';

    protected $description = 'Test location API endpoint for a latitude and longitude';

    public function handle()
    {
        $lat = $this->argument('lat');
        $lon = $this->argument('lon');

        $this->info("Testing location endpoint for: {$lat}, {$lon}");

        $response = Http::withoutVerifying()
            ->timeout(20)
            ->get('https://api.eloquentservice.com/api/location', [
                'lat' => $lat,
                'lon' => $lon,
            ]);

        if (! $response->successful()) {
            $this->error('Request failed with HTTP status: '.$response->status());
            $this->line($response->body());
            return self::FAILURE;
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->warn('Response is not valid JSON.');
            $this->line($response->body());
            return self::FAILURE;
        }

        $this->info('Location endpoint test successful.');
        $resultLat = $data['lat'] ?? $lat;
        $resultLon = $data['lon'] ?? $lon;
        $address = $data['address'] ?? 'N/A';

        $this->table(
            ['Latitude', 'Longitude', 'Address'],
            [[$resultLat, $resultLon, $address]]
        );

        $this->line('Raw JSON:');
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
