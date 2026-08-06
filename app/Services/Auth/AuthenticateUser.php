<?php

namespace App\Services\Auth;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Services\Staff\StaffAuthenticator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * Email + password → an authenticated user (plan §4).
 *
 * The interesting case is that one email may exist in several organizations,
 * because a person working for two clients holds two separate accounts.
 *
 * ── The trap, and why the order below matters ────────────────────
 *
 * The obvious design is: type an email, pick your organization, then type a
 * password. That leaks. Anyone who types an address learns which organizations
 * that person belongs to — which is a customer list, an employment record, and
 * for a competitor a genuinely useful signal.
 *
 * So the password is verified FIRST, against every account sharing the address,
 * and only then is a choice offered — among the accounts that actually matched.
 * Someone with the password already has that account; they learn nothing new.
 *
 * Almost always exactly one matches (two accounts rarely share a password), so
 * the picker is a rare fallback rather than a normal step.
 */
class AuthenticateUser
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StaffAuthenticator $staffAuth,
    ) {}

    /**
     * @param  string|null  $code  the second factor, required only for accounts
     *                             linked to a staff record (§4.2)
     */
    public function __invoke(string $email, string $password, ?string $code = null): AuthenticationResult
    {
        // Users are tenant-scoped, and at login there is no tenant yet — so the
        // lookup deliberately runs outside tenancy. This is one of the few
        // legitimate uses of runWithoutTenant, and the reason it's explicit.
        $accounts = $this->tenant->runWithoutTenant(
            fn () => User::query()
                ->with('organization')
                ->where('email', $email)
                ->get()
        );

        $matched = [];

        $needsSecondFactor = false;

        foreach ($accounts as $account) {
            /*
             * A linked account authenticates against its STAFF credentials.
             *
             * The owner asked for one account across both faces (§4.2, revised).
             * Rather than copying a password between two rows — which makes one
             * compromise two, and drifts the moment either changes — the staff
             * record becomes the single authority for anyone linked to it.
             *
             * Consequence, stated because it is a real one: their CRM login now
             * demands the second factor too. That is the right direction. An
             * account that can reach every customer on the platform should not
             * have a weaker door into one organization than into the control
             * plane, and it applies only to linked accounts — never to ordinary
             * customers.
             */
            $staff = PlatformStaffLink::staffFor($account->getKey());

            $passwordOk = $staff !== null
                ? Hash::check($password, $staff->password)
                // Hash::check is constant-time internally, but skipping it for
                // inactive users would make "inactive" measurably faster than
                // "wrong password" — so verify first, filter after.
                : Hash::check($password, $account->password);

            if (! $passwordOk || ! $account->is_active || $account->organization === null) {
                continue;
            }

            if ($staff !== null) {
                if ($code === null || $code === '') {
                    // Password proven, factor missing. Saying so is safe: it is
                    // only reachable by someone who already has the password.
                    $needsSecondFactor = true;
                    continue;
                }

                if (! $this->staffAuth->verifySecondFactor($staff, $code)) {
                    continue;
                }
            }

            $matched[] = $account;
        }

        if ($matched === [] && $needsSecondFactor) {
            return AuthenticationResult::secondFactorRequired();
        }

        if ($matched === []) {
            // One dummy hash so a non-existent address costs the same as a
            // wrong password. Without it, response time reveals which addresses
            // are registered.
            if ($accounts->isEmpty()) {
                Hash::check($password, Hash::make('timing-equalisation'));
            }

            return AuthenticationResult::failed();
        }

        if (count($matched) === 1) {
            return AuthenticationResult::success($this->enter($matched[0]));
        }

        // Several accounts, same address, same password. Let them choose.
        return AuthenticationResult::ambiguous($matched);
    }

    /**
     * Complete an ambiguous login once the user has chosen.
     *
     * Re-verifies the password rather than trusting the earlier step. The choice
     * arrives from the client, so treating it as proof of anything would let
     * someone swap in another user's id and skip authentication entirely.
     */
    public function completeChoice(string $userId, string $email, string $password, ?string $code = null): AuthenticationResult
    {
        $user = $this->tenant->runWithoutTenant(
            fn () => User::query()->with('organization')->find($userId)
        );

        if ($user === null || $user->email !== $email || ! $user->is_active) {
            return AuthenticationResult::failed();
        }

        // Same authority as the first step — a linked user is verified against
        // the staff record here too, or choosing an organization would be a way
        // round the stronger credential.
        $staff = PlatformStaffLink::staffFor($user->getKey());

        $passwordOk = $staff !== null
            ? Hash::check($password, $staff->password)
            : Hash::check($password, $user->password);

        if (! $passwordOk) {
            return AuthenticationResult::failed();
        }

        if ($staff !== null && ! $this->staffAuth->verifySecondFactor($staff, (string) $code)) {
            return AuthenticationResult::failed();
        }

        return AuthenticationResult::success($this->enter($user));
    }

    /**
     * Put the authenticated user's organization into context.
     *
     * Note a lapsed trial does NOT block login: §13 is explicit that the owner
     * may still sign in and view their data — they simply cannot write. That is
     * enforced on write paths, not here, because locking people out of their own
     * records is how you turn a billing lapse into a support incident.
     */
    private function enter(User $user): User
    {
        $this->tenant->set($user->organization);

        return $user;
    }

    /**
     * The staff account behind a signed-in user, if any.
     *
     * The login controller uses this to issue a staff token alongside the tenant
     * one — both factors have just been proven, so making them sign in a second
     * time to reach the Development tab would be ceremony rather than security.
     */
    public function staffFor(User $user): ?PlatformStaff
    {
        return PlatformStaffLink::staffFor($user->getKey());
    }
}
