<?php

namespace App\Http\Controllers;

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
        ]);

        $category = $shop->parentCategories()->create($data);

        return response()->json([
            'message' => 'Parent category created successfully',
            'data' => $category,
        ], 201);
    }
}
