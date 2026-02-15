<?php

namespace App\Http\Controllers;

use App\Models\Catalog;
use App\Models\Shop;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CatalogController extends Controller
{
    private function requireShop(Request $request): Shop
    {
        $user = $request->user();

        if (!$user || !($user instanceof Shop)) {
            throw new HttpException(403, 'Shop authentication required');
        }

        return $user;
    }

    public function index(Request $request)
    {
        $shop = $this->requireShop($request);

        return response()->json(
            $shop->catalogs()->orderByDesc('id')->get()
        );
    }

    public function store(Request $request)
    {
        $shop = $this->requireShop($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable'],
        ]);

        if (array_key_exists('image', $data) && empty($data['image'])) {
            $data['image'] = null;
        }

        if (!empty($data['image'])) {
            $data['image'] = Shop::saveBase64Image($data['image'], 'catalogs');
        }

        $catalog = $shop->catalogs()->create($data);

        return response()->json([
            'message' => 'Catalog created successfully',
            'data' => $catalog,
        ], 201);
    }

    public function show(Request $request, Catalog $catalog)
    {
        $shop = $this->requireShop($request);

        if ((int) $catalog->shop_id !== (int) $shop->id) {
            return response()->json(['message' => 'Catalog not found'], 404);
        }

        return response()->json($catalog);
    }

    public function update(Request $request, Catalog $catalog)
    {
        $shop = $this->requireShop($request);

        if ((int) $catalog->shop_id !== (int) $shop->id) {
            return response()->json(['message' => 'Catalog not found'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'image' => ['sometimes', 'nullable'],
        ]);

        if (array_key_exists('image', $data)) {
            if (empty($data['image'])) {
                $data['image'] = null;
            } else {
                $data['image'] = Shop::saveBase64Image($data['image'], 'catalogs');
            }
        }

        $catalog->update($data);

        return response()->json([
            'message' => 'Catalog updated successfully',
            'data' => $catalog->fresh(),
        ]);
    }

    public function destroy(Request $request, Catalog $catalog)
    {
        $shop = $this->requireShop($request);

        if ((int) $catalog->shop_id !== (int) $shop->id) {
            return response()->json(['message' => 'Catalog not found'], 404);
        }

        $catalog->delete();

        return response()->json([
            'message' => 'Catalog deleted successfully',
        ]);
    }
}
