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

        // Check if address exists in DB first
        $location = Location::where('lat', $lat)->where('lon', $lon)->first();

        if ($location) { 
            info("location found based on lat $lat, lon $lon");
            return $location;
         }

        // Otherwise, call Google Maps API
        $apiKey = env('GOOGLE_MAPS_KEY');

        $res = Http::withoutVerifying()->get("https://maps.googleapis.com/maps/api/geocode/json", [
            'latlng' => "$lat,$lon",
            'key' => $apiKey,
        ]);

        $data = $res->json();
        if ($data['status'] === "OK" && count($data['results']) > 0) {
            return Location::create([
                'lat' => $lat,
                'lon' => $lon,
                'address' => $data['results'][0]['formatted_address']
            ]);
        }
    }
}
