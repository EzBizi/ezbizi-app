<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A write refused because the subscription has ended (plan §13).
 *
 * Its own type rather than a plain RuntimeException so the two failures
 * BelongsToTenant can raise stay distinguishable. A cross-tenant write is a BUG
 * and should surface as a 500 that somebody investigates; a lapsed subscription
 * is an expected business state and should surface as a 402 the client can
 * explain. Collapsing them would bury one inside the other.
 */
class SubscriptionLapsedException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        // 402 Payment Required, matching the middleware — and the client already
        // distinguishes it from 403, because the fix is billing rather than
        // permissions and telling somebody "access denied" when they simply need
        // to pay is a support call.
        return 402;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
