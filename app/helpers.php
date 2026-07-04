<?php

use App\Models\ShopUser;
use App\Support\CurrentShopUser;

if (!function_exists('current_shop_user')) {
    /**
     * The ShopUser acting on the current request, or null for an untagged
     * (legacy / owner-equivalent) session.
     */
    function current_shop_user(): ?ShopUser
    {
        return CurrentShopUser::get();
    }
}
