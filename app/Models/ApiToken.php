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
class ApiToken extends PersonalAccessToken implements \App\Support\Privacy\HoldsPersonalData
{
    /*
     * §9. Found by the inventory's derived check rather than by anybody
     * remembering: this table carries `ip_address` and `user_agent` per session,
     * which is a record of where a person has been and on what.
     */
    public static function personalDataPurpose(): string
    {
        return 'One row per signed-in device, so somebody can see their own sessions and '
            .'end one they do not recognise. That list is only useful because it says '
            .'where and on what, which is what makes the row personal data.';
    }

    public static function retention(): \App\Support\Privacy\RetentionPolicy
    {
        return \App\Support\Privacy\RetentionPolicy::elsewhere('signing out, and token expiry',
            'Deleted when the session ends, when the token expires, or when the person '
            .'signs out everywhere. Nothing retains it beyond the session it describes, so '
            .'an age-based sweep would have nothing left to find.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new \App\Support\Privacy\PersonalDataEntry(
                'ip_address, user_agent',
                \App\Support\Privacy\PersonalDataEntry::TECHNICAL,
                'Naming a session well enough that its owner can recognise it — "Chrome on '
                .'Windows, from the office" — and end the one they do not.',
                /*
                 * The row goes rather than being blanked, and for once that is
                 * the LESS destructive choice: a token row with its address and
                 * device removed is a live credential nobody can recognise, so
                 * blanking would leave the sign-in working while destroying the
                 * only thing that makes it revocable.
                 *
                 * Erasing somebody should also end their sessions. Anything else
                 * leaves an authenticated browser open on an account whose owner
                 * has just been told their data is gone.
                 */
                \App\Support\Privacy\Erasure::drop('A session is nothing but the person '
                    .'and the device it belongs to. Blanking it would leave a credential '
                    .'that still authenticates and can no longer be recognised or revoked; '
                    .'removing it signs them out, which is what erasing an account should '
                    .'do anyway.'),
            ),
            new \App\Support\Privacy\PersonalDataEntry(
                'name',
                \App\Support\Privacy\PersonalDataEntry::TECHNICAL,
                'The device label the client sent, not the person.',
                \App\Support\Privacy\Erasure::drop('Goes with the row above; a device label '
                    .'without the session it names is not a record of anything.'),
            ),
        ];
    }

    /**
     * Sessions belonging to this account.
     *
     * `whereMorphedTo` rather than a column comparison: `tokenable` is a morph,
     * and platform staff hold tokens in the same table (§4.2). Matching on
     * `tokenable_id` alone would delete a staff member's sessions whenever a user
     * happened to share their uuid — which cannot happen today and is exactly the
     * assumption that stops being true quietly.
     */
    public static function personalDataAbout(\Illuminate\Database\Eloquent\Model $subject): ?\Illuminate\Database\Eloquent\Builder
    {
        return $subject instanceof User
            ? static::query()->whereMorphedTo('tokenable', $subject)
            : null;
    }

    /**
     * How this table narrows to one organization: through the user holding it.
     *
     * There is no `organization_id` here — a token belongs to an account, and
     * the account belongs to the organization. Without this the inventory can
     * only answer "we hold this and cannot tell you how much", which is honest
     * and much less useful than the number.
     */
    public static function narrowToOrganization($query, string $organizationId)
    {
        return $query->whereHasMorph(
            'tokenable',
            [User::class],
            fn ($user) => $user->withoutGlobalScopes()
                ->where('organization_id', $organizationId),
        );
    }

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
