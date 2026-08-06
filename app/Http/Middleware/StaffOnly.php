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
 * Gate the control plane (plan §4.2).
 *
 * The class check is not belt-and-braces, it is the actual gate. Sanctum's guard
 * resolves a bearer token to whatever model issued it and does NOT check that
 * model against the guard's configured provider — so `auth:sanctum` alone would
 * happily let an ordinary tenant user's token through to staff routes. Asking
 * what the principal actually IS closes that.
 */
class StaffOnly
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();

        if (! $staff instanceof PlatformStaff || ! $staff->canAuthenticate()) {
            abort(Response::HTTP_FORBIDDEN, 'This endpoint is restricted to EzBizi staff.');
        }

        $this->audit->setCorrelationId(
            $request->header('X-Correlation-Id') ?? (string) Str::uuid7()
        );

        // Platform scope, not "no tenant" — TenantContext keeps them distinct so a
        // missing tenant caused by a bug can't pass for legitimate staff access.
        $this->tenant->enterPlatformScope();

        try {
            $response = $staff->suppressesBusinessAudit()
                ? $this->audit->suppressForLeadDeveloper(fn () => $next($request))
                : $next($request);
        } finally {
            $this->tenant->forget();
        }

        return $response->header('X-Correlation-Id', $this->audit->correlationId());
    }
}
