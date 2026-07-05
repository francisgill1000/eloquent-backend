<?php

namespace App\Services\Leads\Exceptions;

use RuntimeException;

/**
 * Thrown when a shop has exhausted its monthly (billable) search allowance.
 * The controller renders this as HTTP 402 with a machine-readable code, mirroring
 * the EnsureSubscribed convention.
 */
class SearchLimitReached extends RuntimeException
{
    public function __construct(public int $used, public int $limit)
    {
        parent::__construct('Monthly search allowance reached.');
    }
}
