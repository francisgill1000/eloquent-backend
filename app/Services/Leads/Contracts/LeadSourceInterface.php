<?php

namespace App\Services\Leads\Contracts;

/**
 * A pluggable business-discovery source. Swap the implementation (Google Places
 * now, Explorium later) by binding a different class in the service provider —
 * no controller/service changes required.
 */
interface LeadSourceInterface
{
    /**
     * Search for real businesses.
     *
     * @param  string       $query  e.g. "salon", "car wash"
     * @param  string|null  $area   e.g. "Dubai Marina", "Abu Dhabi"
     * @return array<int, array{
     *     name: string,
     *     phone: ?string,
     *     website: ?string,
     *     address: ?string,
     *     category: ?string,
     *     lat: ?float,
     *     lng: ?float,
     *     rating: ?float,
     *     external_ref: string,
     *     source: string
     * }>  Normalized lead DTOs. Returns [] on provider failure — never throws
     *     for network/API errors (those fail gracefully).
     */
    public function search(string $query, ?string $area): array;

    /** Provider key stored on cache/lead rows, e.g. "google_places". */
    public function key(): string;
}
