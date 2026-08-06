<?php

namespace App\Http\Middleware;

use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Put the authenticated user's organization into context (plan §5.1).
 *
 * The tenant comes from the AUTHENTICATED USER, never from the request. A header
 * or query parameter naming the organization would be a trivial cross-tenant
 * read: change one value, get someone else's data. The user's own
 * organization_id is the only trustworthy source, because it was established at
 * login and cannot be influenced by the caller.
 *
 * Also clears the context afterwards. Under a persistent worker (Octane, or a
 * queue process handling several jobs) a leftover tenant would be inherited by
 * whatever ran next.
 */
class SetTenantContext
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->organization !== null) {
            $this->tenant->set($user->organization);
        }

        // One correlation id per request, so every audit event raised while
        // handling it can be reconstructed as a single chain (§10).
        $this->audit->setCorrelationId(
            $request->header('X-Correlation-Id') ?? (string) Str::uuid7()
        );

        try {
            $response = $next($request);
        } finally {
            $this->tenant->forget();
        }

        return $response->header('X-Correlation-Id', $this->audit->correlationId());
    }
}
