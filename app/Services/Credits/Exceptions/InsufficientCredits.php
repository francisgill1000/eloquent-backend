<?php

namespace App\Services\Credits\Exceptions;

use RuntimeException;

/**
 * Thrown when a shop lacks the Business Hunt credits a debit requires. The
 * controller renders this as HTTP 429 with a machine-readable code — NOT 402,
 * which the admin SPA reserves for the Lens subscription paywall (it redirects
 * 402 to /subscribe, which would be wrong for a Hunt top-up).
 */
class InsufficientCredits extends RuntimeException
{
    public function __construct(public int $balance, public int $required)
    {
        parent::__construct('Insufficient Business Hunt credits.');
    }
}
