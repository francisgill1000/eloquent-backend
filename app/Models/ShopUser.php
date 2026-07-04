<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Traits\HasRoles;

/**
 * A login-able person within a shop (the RBAC subject).
 *
 * NOTE: ShopUser is NOT the Sanctum authenticatable — the Shop is. The acting
 * ShopUser is carried on the token via personal_access_tokens.shop_user_id and
 * resolved per-request by SetRbacContext middleware. Roles are team-scoped by
 * shop_id (spatie teams mode).
 */
class ShopUser extends Model
{
    use HasFactory, HasRoles;

    /** spatie guard the roles/permissions live under. */
    protected $guard_name = 'web';

    protected $fillable = ['shop_id', 'name', 'login_pin', 'is_active'];

    // Login credential must never leak through API responses.
    protected $hidden = ['login_pin'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
