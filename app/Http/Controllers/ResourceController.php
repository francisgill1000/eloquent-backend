<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * Owner-managed bookable resources (rooms, chairs, machines). Tenant-scoped:
 * every resource is verified to belong to the shop.
 */
class ResourceController extends Controller
{
    public function index(Shop $shop)
    {
        return response()->json(['data' => $shop->resources()->orderBy('id')->get()]);
    }

    public function store(Request $request, Shop $shop)
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'type'      => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $resource = $shop->resources()->create([
            'name'      => $data['name'],
            'type'      => $data['type'] ?? 'room',
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['data' => $resource], 201);
    }

    public function update(Request $request, Shop $shop, Resource $resource)
    {
        abort_unless((int) $resource->shop_id === (int) $shop->id, 404);

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'type'      => ['sometimes', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $resource->update($data);

        return response()->json(['data' => $resource->fresh()]);
    }

    public function destroy(Shop $shop, Resource $resource)
    {
        abort_unless((int) $resource->shop_id === (int) $shop->id, 404);
        $resource->update(['is_active' => false]);
        return response()->json(['data' => $resource->fresh()]);
    }
}
