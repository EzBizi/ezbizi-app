<?php

namespace App\Models;

use App\Models\Concerns\TenantScope;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum's token, with the one thing it cannot know about us.
 *
 * ── Why this class exists at all ─────────────────────────────────
 *
 * Turning a bearer token into a principal is the SAME act as login: it happens
 * before there is a tenant, because the tenant is a property of the account the
 * token names. AuthenticateUser already says so out loud — its user lookup runs
 * inside `runWithoutTenant()`, with a comment explaining that at login there is
 * no tenant yet.
 *
 * Token authentication had no equivalent, because the lookup is Sanctum's, not
 * ours: Guard::__invoke reads `$accessToken->tokenable` twice — once to check
 * the token's provider, once to hand the model to the request. User is
 * tenant-scoped, so that relation goes through TenantScope with nothing in
 * context. While the scope failed open it silently returned an unfiltered query
 * and nobody noticed; now that it refuses, every authenticated request would
 * 500 inside the auth middleware.
 *
 * Neither answer is acceptable, and both come from the same mistake — leaving
 * the read implicit. So it is stated here, once, in the only place the framework
 * offers: `Sanctum::usePersonalAccessTokenModel()` in AppServiceProvider points
 * the guard at this class.
 *
 * ── What this deliberately does NOT do ───────────────────────────
 *
 * Dropping the tenant filter here identifies the account; it authorizes nothing.
 * SetTenantContext runs immediately after, reads the resolved user's OWN
 * organization, and refuses the request if there is not one — so a token still
 * cannot reach outside the organization it was issued for. The widening is one
 * row deep and lasts one lookup.
 */
class ApiToken extends PersonalAccessToken
{
    /**
     * Named for what it is, so the table has to be stated.
     *
     * Eloquent derives a table from the class name, and Sanctum's migration
     * owns `personal_access_tokens`. Calling this class PersonalAccessToken too
     * would let the derivation stand, at the cost of two classes with one name
     * in a codebase where `Laravel\Sanctum\PersonalAccessToken` is still
     * imported elsewhere — a worse trade than one line here.
     */
    protected $table = 'personal_access_tokens';

    /**
     * The account this token belongs to, found without a tenant.
     *
     * TenantScope by name rather than `withoutGlobalScopes()`, which would take
     * every scope with it. PlatformStaff — the other thing a token can belong to
     * — is soft-deleted, and dropping SoftDeletes here would let a removed staff
     * account go on authenticating. Naming the one scope that has to go keeps
     * the rest of the model's rules intact, and harmlessly no-ops for a model
     * that never had it.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScope(TenantScope::class);
    }
}
