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
 * A request that cannot be given a tenant is REFUSED, not waved through — see
 * the abort below. Continuing without one is how a single removed organization
 * turns into a read of every organization.
 *
 * Also clears the context afterwards. Under a persistent worker (Octane, or a
 * queue process handling several jobs) a leftover tenant would be inherited by
 * whatever ran next.
 *
 * Runs BEFORE Illuminate\Routing\Middleware\SubstituteBindings, which is not the
 * order the route file's middleware list implies — the framework sorts against
 * the priority map, and bootstrap/app.php is where that is arranged. Route model
 * binding is a tenant-scoped read, so it cannot be allowed to happen first.
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

        /*
         * No organization, no request. Refuse rather than continue.
         *
         * Organization uses SoftDeletes, so `$user->organization` is null for
         * every user of a trashed one — while their tokens go on authenticating,
         * because nothing revokes them when the tenant is removed. This used to
         * skip the `set()` and let the request through with NO tenant in
         * context, which meant the reads below ran unscoped: TenantScope adds no
         * predicate when there is none, so a deleted client's account was a key
         * to the whole platform.
         *
         * TenantScope now throws instead, so this is belt to that brace. It
         * still belongs here: a 500 from deep inside a query is not an answer,
         * and the refusal should name the reason at the door.
         *
         * 401, not 403 or 503:
         *
         *   403 says "you are somebody, and this is not for you", which invites
         *   the client to keep the token and retry elsewhere — there is nowhere
         *   else, every tenant endpoint answers the same way.
         *
         *   503 says "come back later". Nothing here is transient.
         *
         *   401 says the credential itself is finished, which is the truth: the
         *   account behind this token can no longer be signed in to either —
         *   AuthenticateUser skips accounts whose organization is null — so the
         *   token can never become good again. It is also the only status the
         *   client acts on: Session::refresh() drops the token on 401 and shows
         *   the sign-in screen, and treats everything else as "the server did
         *   not answer", leaving somebody stuck on a screen that never loads.
         */
        abort_if(
            $user?->organization === null,
            Response::HTTP_UNAUTHORIZED,
            'This account is no longer attached to an organization. Sign in again.',
        );

        $this->tenant->set($user->organization);

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
