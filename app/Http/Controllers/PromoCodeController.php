<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use Illuminate\Http\Request;

class PromoCodeController extends Controller
{
    public function index(Request $request)
    {
        $shopId = (int) $request->user()->id;

        $codes = PromoCode::where('shop_id', $shopId)
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $codes]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'max:32'],
            'label'          => ['nullable', 'string', 'max:120'],
            'discount_type'  => ['required', 'in:percent,flat'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'valid_from'     => ['nullable', 'date'],
            'valid_until'    => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses'       => ['nullable', 'integer', 'min:1'],
            'is_active'      => ['boolean'],
        ]);

        // Tenant is the authenticated shop — never a request-supplied shop_id.
        $data['shop_id'] = (int) $request->user()->id;

        // Normalise code to uppercase, no spaces.
        $data['code'] = strtoupper(preg_replace('/\s+/', '', $data['code']));

        // Uniqueness per shop.
        $exists = PromoCode::where('shop_id', $data['shop_id'])
            ->where('code', $data['code'])
            ->exists();
        abort_if($exists, 422, 'A code with that value already exists for this shop.');

        $code = PromoCode::create($data);
        return response()->json(['data' => $code], 201);
    }

    public function update(Request $request, PromoCode $promoCode)
    {
        abort_unless($request->user() && $promoCode->shop_id === (int) $request->user()->id, 403, 'This action is not permitted.');

        $data = $request->validate([
            'label'          => ['nullable', 'string', 'max:120'],
            'discount_type'  => ['sometimes', 'in:percent,flat'],
            'discount_value' => ['sometimes', 'numeric', 'min:0'],
            'valid_from'     => ['nullable', 'date'],
            'valid_until'    => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses'       => ['nullable', 'integer', 'min:1'],
            'is_active'      => ['sometimes', 'boolean'],
        ]);

        $promoCode->update($data);
        return response()->json(['data' => $promoCode->fresh()]);
    }

    public function destroy(Request $request, PromoCode $promoCode)
    {
        abort_unless($request->user() && $promoCode->shop_id === (int) $request->user()->id, 403, 'This action is not permitted.');

        $promoCode->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Public-ish lookup used during booking creation:
     * GET /shops/{shop}/promo-codes/lookup?code=XYZ
     * Returns { found, redeemable, discount_type, discount_value, ... }.
     */
    public function lookup(Request $request, $shopId)
    {
        $code = strtoupper(preg_replace('/\s+/', '', (string) $request->query('code', '')));
        if ($code === '') {
            return response()->json(['found' => false]);
        }
        $promo = PromoCode::where('shop_id', $shopId)->where('code', $code)->first();
        if (! $promo) {
            return response()->json(['found' => false]);
        }
        return response()->json([
            'found' => true,
            'id' => $promo->id,
            'code' => $promo->code,
            'label' => $promo->label,
            'discount_type' => $promo->discount_type,
            'discount_value' => (float) $promo->discount_value,
            'redeemable' => $promo->isRedeemable(),
            'reason' => $promo->isRedeemable() ? null : 'expired_or_inactive',
        ]);
    }
}
