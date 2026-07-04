<?php

namespace App\Support;

use App\Models\ShopUser;

/**
 * Per-request holder for the acting ShopUser, populated by SetRbacContext.
 * Read via the current_shop_user() helper.
 */
class CurrentShopUser
{
    private static ?ShopUser $user = null;

    public static function set(?ShopUser $user): void
    {
        self::$user = $user;
    }

    public static function get(): ?ShopUser
    {
        return self::$user;
    }
}
