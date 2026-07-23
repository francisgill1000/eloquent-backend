<?php

namespace App\Models\Scopes;

use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows every Lead read to the acting agent unless they hold leads.view_all
 * or the Owner role. Applied globally rather than at each call site because a
 * missed call site leaks another agent's leads silently — and there are a dozen
 * of them across the controller, the assistant tools and reports.
 *
 * The one deliberate bypass is LeadImporter, which must see the whole shop to
 * dedupe on (shop_id, external_ref).
 *
 * NOTE: raw query-builder reads (DB::table('leads')) do NOT go through this —
 * see ReportsAggregator, which filters explicitly.
 *
 * A null acting user (console, queue, legacy untagged token) is owner-equivalent
 * throughout Rbac, so no filter is applied.
 */
class AssignedLeadScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = current_shop_user();
        if (Rbac::seesAllLeads($user)) {
            return;
        }

        $builder->where($model->qualifyColumn('assigned_to_id'), $user->id);
    }
}
