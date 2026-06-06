<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        // Single source of truth shared with shop registration + bot personas.
        $service_type = \App\Support\ServiceCategories::all();

        return $service_type;

        // Get search query
        $search = $request->query('search');

        // Only filter if search exists and length > 3
        if ($search && strlen($search) > 3) {
            $service_type = array_filter($service_type, function ($service) use ($search) {
                return stripos($service['name'], $search) !== false;
            });
            // Re-index array keys after filtering
            $service_type = array_values($service_type);
        }

        return response()->json($service_type);
    }
}
