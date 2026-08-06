<?php

namespace App\Services\Auth;

use App\Models\User;
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
    ) {}

    public function __invoke(string $email, string $password): AuthenticationResult
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

        foreach ($accounts as $account) {
            // Hash::check is constant-time internally, but skipping it for
            // inactive users would make "inactive" measurably faster than
            // "wrong password" — so verify first, filter after.
            $passwordOk = Hash::check($password, $account->password);

            if ($passwordOk && $account->is_active && $account->organization !== null) {
                $matched[] = $account;
            }
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
    public function completeChoice(string $userId, string $email, string $password): AuthenticationResult
    {
        $user = $this->tenant->runWithoutTenant(
            fn () => User::query()->with('organization')->find($userId)
        );

        if ($user === null
            || $user->email !== $email
            || ! $user->is_active
            || ! Hash::check($password, $user->password)) {
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
}
