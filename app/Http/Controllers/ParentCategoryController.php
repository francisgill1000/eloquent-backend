<?php

namespace App\Http\Controllers;

use App\Models\ParentCategory;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ParentCategoryController extends Controller
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
            $shop->parentCategories()->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $shop = $this->requireShop($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('parent_categories', 'name')->where('shop_id', $shop->id),
            ],
            'image' => ['nullable'],
        ]);

        $data['image'] = $this->resolveImage($data['image'] ?? null);

        $category = $shop->parentCategories()->create($data);

        return response()->json([
            'message' => 'Parent category created successfully',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, ParentCategory $parentCategory)
    {
        $shop = $this->requireShop($request);

        if ((int) $parentCategory->shop_id !== (int) $shop->id) {
            return response()->json(['message' => 'Parent category not found'], 404);
        }

        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('parent_categories', 'name')
                    ->where('shop_id', $shop->id)
                    ->ignore($parentCategory->id),
            ],
            'image' => ['sometimes', 'nullable'],
        ]);

        if (array_key_exists('image', $data)) {
            $data['image'] = $this->resolveImage($data['image']);
        }

        $parentCategory->update($data);

        return response()->json([
            'message' => 'Parent category updated successfully',
            'data' => $parentCategory->fresh(),
        ]);
    }

    public function destroy(Request $request, ParentCategory $parentCategory)
    {
        $shop = $this->requireShop($request);

        if ((int) $parentCategory->shop_id !== (int) $shop->id) {
            return response()->json(['message' => 'Parent category not found'], 404);
        }

        // Detach services so they become uncategorised rather than orphaned.
        $parentCategory->catalogs()->update(['parent_category_id' => null]);
        $parentCategory->delete();

        return response()->json([
            'message' => 'Parent category deleted successfully',
        ]);
    }

    /**
     * Persist a base64 image, leave an existing URL untouched, or null it out.
     */
    private function resolveImage($image): ?string
    {
        if (empty($image)) {
            return null;
        }

        if (is_string($image) && str_starts_with($image, 'data:')) {
            return Shop::saveBase64Image($image, 'parent-categories');
        }

        return $image;
    }
}
