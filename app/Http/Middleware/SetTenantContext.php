<?php

namespace App\Http\Middleware;

use App\Models\PlatformStaff;
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

        // A staff token is not a tenant identity. Sanctum resolves any bearer
        // token to its issuer regardless of guard, so without this a staff token
        // would reach tenant endpoints with no organization in context — and the
        // failure would surface later as a confusing 500 rather than a refusal.
        if ($user instanceof PlatformStaff) {
            abort(
                Response::HTTP_FORBIDDEN,
                'Staff tokens cannot be used on tenant endpoints. Start an impersonation session (§4.2).'
            );
        }

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

        // headers->set rather than ->header(): the latter is Laravel's own
        // Response, and a streamed download is a bare Symfony StreamedResponse
        // that does not have it. Every response type has ->headers.
        $response->headers->set('X-Correlation-Id', $this->audit->correlationId());

        return $response;
    }
}
