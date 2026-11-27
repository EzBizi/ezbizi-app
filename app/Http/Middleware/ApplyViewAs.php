<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use App\Support\ViewAs\ViewAs;
use App\Support\ViewAs\ViewAsUser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * "View as" while it is on (Jose, 2026-11-23). Runs right after auth:sanctum on
 * the authenticated v1 group: finds the active session for THIS token and runs
 * the request as the target (ViewAsUser), with the real user kept on the
 * request for AuditLogger and /me.
 *
 * The session ends on its own: on expiry, when the token is deleted (sign-out,
 * the row cascades), and when the actor stops being an Administrator with a
 * staff link (checked on every request here).
 */
class ApplyViewAs
{
    /**
     * Refused (403) while viewing as somebody: the real user's security and
     * account, credentials, another view-as, and developer/staff endpoints.
     * Matched against the path after `api/v1/`; `null` method means any.
     *
     * @var list<array{0: ?string, 1: string}>
     */
    public const BLOCKED = [
        [null, 'me/password'],
        [null, 'me/totp(/.*)?'],
        [null, 'me/sms-codes(/.*)?'],
        [null, 'hr/people/[^/]+/totp/reset'],
        [null, 'me/api-keys(/.*)?'],
        [null, 'api-keys/.*'],
        [null, 'mcp'],
        [null, 'me/sessions'],
        [null, 'me/deletion'],
        ['DELETE', 'me'],
        [null, 'me/development/.*'],
        [null, 'release/upcoming'],
        ['POST', 'view-as'],
        [null, 'view-as/options'],
    ];

    /**
     * Viewing as a PERSON: the target's own private rows that the target
     * could not reach themselves are refused with 404. `{id}` is the target.
     *
     * @var list<array{0: ?string, 1: string}>
     */
    public const PERSON_HIDDEN = [
        [null, 'hr/people/{id}/(totp/reset|reset-link|deletion|consents)'],
        ['DELETE', 'hr/people/{id}'],
    ];

    public function __construct(
        private readonly ViewAs $viewAs,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $token = $actor instanceof User ? $actor->currentAccessToken() : null;
        if (! $actor instanceof User || ! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $session = ViewAs::activeFor($token);
        if ($session === null) {
            return $next($request);
        }

        $effective = $this->tenant->run($actor->organization, function () use ($session, $actor) {
            if ($session->expires_at->isPast()) {
                $this->viewAs->end($session, 'expired');

                return null;
            }
            if ($session->actor_id !== $actor->getKey() || ! ViewAs::allowed($actor)) {
                $this->viewAs->end($session, 'actor no longer allowed');

                return null;
            }

            if ($session->target_user_id !== null) {
                $target = User::query()->whereKey($session->target_user_id)->first();
                if ($target === null || ! $target->is_active || $target->isAdministrator()
                    || $target->organization_id !== $actor->organization_id) {
                    $this->viewAs->end($session, 'target no longer viewable');

                    return null;
                }

                return ViewAsUser::asPerson($target, $actor, $session);
            }

            $role = Role::withoutGlobalScopes()->find($session->role_id);
            if ($role === null || $role->isAdministrator()) {
                $this->viewAs->end($session, 'role no longer viewable');

                return null;
            }

            return ViewAsUser::asRole($actor, $role, $session->scope_type, $session->scope_id, $session);
        });

        if ($effective === null) {
            return $next($request);
        }

        $path = (string) preg_replace('#^api/v1/#', '', $request->path());
        if ($this->matches($request, $path, self::BLOCKED)) {
            return response()->json([
                'message' => 'Not available while viewing the app as somebody else. Stop viewing as them first.',
            ], 403);
        }
        if (! $effective->viewAsRoleClone
            && $this->matches($request, $path, self::PERSON_HIDDEN, $effective->getKey())) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $request->attributes->set(ViewAs::ACTOR, $actor);
        $request->attributes->set(ViewAs::USER, $effective);
        Auth::guard('sanctum')->setUser($effective);
        Auth::setUser($effective);
        $request->setUserResolver(fn () => $effective);

        // The client's view header names a place the REAL user stands in. If
        // the target cannot stand there, drop it so ViewScope resolves the
        // target's own view (or the chosen place) instead of refusing.
        if ($request->headers->has('X-EzBizi-View')) {
            try {
                $this->tenant->run($actor->organization, fn () => ViewScope::forUser($effective));
            } catch (AuthorizationException) {
                $request->headers->remove('X-EzBizi-View');
            }
        }

        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->tenant->run($actor->organization, fn () => $this->audit->log('view_as.request', metadata: [
                'viewing_as' => ViewAs::describe($session),
                'method' => $request->method(),
                'path' => $path,
                'status' => $response->getStatusCode(),
            ], actor: $actor));
        }

        return $response;
    }

    /** @param list<array{0: ?string, 1: string}> $patterns */
    private function matches(Request $request, string $path, array $patterns, ?string $id = null): bool
    {
        foreach ($patterns as [$method, $pattern]) {
            if ($method !== null && $method !== $request->method()) {
                continue;
            }
            $regex = str_replace('{id}', preg_quote((string) $id, '#'), $pattern);
            if (preg_match('#^'.$regex.'$#D', $path)) {
                return true;
            }
        }

        return false;
    }
}
