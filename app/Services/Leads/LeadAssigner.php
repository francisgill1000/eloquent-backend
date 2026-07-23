<?php

namespace App\Services\Leads;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\Rbac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Round-robin hand-out of newly saved leads. The rotation is every active shop
 * user EXCEPT the Owner (Francis: the admin/main account stays out of it) and
 * except anyone with no Hunt access at all, who would otherwise be handed leads
 * they cannot open.
 *
 * Fairness comes from shops.lead_assign_cursor — the id that last received a
 * lead. The cursor is read and written under a row lock so two concurrent
 * imports cannot hand the same position to both.
 */
class LeadAssigner
{
    /**
     * Eligible agents for this shop, ordered by id (stable rotation order).
     *
     * @return Collection<int, ShopUser>
     */
    public function pool(Shop $shop): Collection
    {
        // spatie is in teams mode — permission checks need the shop context,
        // which a queue/console caller may not have set.
        setPermissionsTeamId($shop->id);

        return ShopUser::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (ShopUser $u) => ! Rbac::isOwner($u) && Rbac::userCan($u, 'leads.view'))
            ->values();
    }

    /**
     * The next agent in the rotation, advancing and persisting the cursor.
     * Null when nobody is eligible — the caller leaves the lead in the pool.
     */
    public function next(Shop $shop): ?ShopUser
    {
        $pool = $this->pool($shop);
        if ($pool->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($shop, $pool) {
            $locked = Shop::whereKey($shop->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            $ids = $pool->pluck('id')->all();
            $at = array_search($locked->lead_assign_cursor, $ids, true);
            $chosen = $pool[$at === false ? 0 : ($at + 1) % count($ids)];

            $locked->lead_assign_cursor = $chosen->id;
            $locked->save();

            // Keep the caller's instance in step so a multi-row import advances
            // instead of handing every lead to the same person.
            $shop->lead_assign_cursor = $chosen->id;

            return $chosen;
        });
    }
}
