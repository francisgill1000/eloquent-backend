<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LocationController extends Controller
{
    public function index()
    {
        $lat = request("lat");
        $lon = request("lon");

        if ($lat === null || $lon === null) {
            return response()->json([
                'message' => 'lat and lon are required',
            ], 422);
        }

        // Check if address exists in DB first
        $location = Location::where('lat', $lat)->where('lon', $lon)->first();

        if ($location) {
            info("location found based on lat $lat, lon $lon");
            return response()->json($location);
        }

        // Otherwise, call Google Maps API
        $apiKey = env('GOOGLE_MAPS_KEY');

        info("Calling api for new $lat, lon $lon");

        $res = Http::withoutVerifying()->timeout(20)->get("https://maps.googleapis.com/maps/api/geocode/json", [
            'latlng' => "$lat,$lon",
            'key' => $apiKey,
        ]);

        if (! $res->successful()) {
            info('Google Maps HTTP request failed', [
                'status' => $res->status(),
                'body' => $res->body(),
                'lat' => $lat,
                'lon' => $lon,
            ]);

            return response()->json([
                'lat' => $lat,
                'lon' => $lon,
                'address' => null,
                'google_status' => 'HTTP_'.$res->status(),
            ]);
        }

        $data = $res->json();
        if ($data['status'] === "OK" && count($data['results']) > 0) {
            $savedLocation = Location::create([
                'lat' => $lat,
                'lon' => $lon,
                'address' => $data['results'][0]['formatted_address']
            ]);

            return response()->json($savedLocation);
        }

        info('Google Maps geocode did not resolve address', [
            'google_status' => $data['status'] ?? 'UNKNOWN',
            'google_error_message' => $data['error_message'] ?? null,
            'lat' => $lat,
            'lon' => $lon,
        ]);

        return response()->json([
            'lat' => $lat,
            'lon' => $lon,
            'address' => null,
            'google_status' => $data['status'] ?? 'UNKNOWN',
            'google_error_message' => $data['error_message'] ?? null,
        ]);
    }
}
