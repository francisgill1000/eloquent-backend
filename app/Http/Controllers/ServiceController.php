<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        // Hardcoded service_type
        $service_type = [
            ['id' => 1, 'code' => '0001', 'name' => 'Barber',       'icon' => "Scissors"],
            ['id' => 2, 'code' => '0002', 'name' => 'Plumbing',     'icon' => 'Pickaxe'],
            ['id' => 3, 'code' => '0003', 'name' => 'AC Repair',    'icon' => 'Wind'],
            ['id' => 4, 'code' => '0004', 'name' => 'Electrician',  'icon' => 'Zap'],
            ['id' => 5, 'code' => '0005', 'name' => 'Car Wash',     'icon' => 'Car'],
            ['id' => 6, 'code' => '0006', 'name' => 'Painting',     'icon' => 'Paintbrush'],
            ['id' => 7, 'code' => '0007', 'name' => 'Cleaning',     'icon' => 'Home'],
            ['id' => 8, 'code' => '0008', 'name' => 'Pest Control', 'icon' => 'ShieldCheck'],
        ];

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
