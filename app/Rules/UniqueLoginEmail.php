<?php

namespace App\Rules;

use App\Models\Shop;
use App\Models\ShopUser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Email is the platform-wide login identifier — one email must resolve to
 * exactly one identity across both Shop (owner) and ShopUser (staff)
 * accounts. A DB unique constraint can't span two tables, so this is
 * enforced here instead.
 */
class UniqueLoginEmail implements ValidationRule
{
    public function __construct(
        private ?int $ignoreShopId = null,
        private ?int $ignoreShopUserId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $shopClash = Shop::where('email', $value)
            ->when($this->ignoreShopId, fn ($q) => $q->where('id', '!=', $this->ignoreShopId))
            ->exists();

        $shopUserClash = ShopUser::where('email', $value)
            ->when($this->ignoreShopUserId, fn ($q) => $q->where('id', '!=', $this->ignoreShopUserId))
            ->exists();

        if ($shopClash || $shopUserClash) {
            $fail('The :attribute has already been taken.');
        }
    }
}
