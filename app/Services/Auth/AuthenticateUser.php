<?php

namespace App\Services\Auth;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Services\Staff\StaffAuthenticator;
use App\Support\Auth\FactorAttempts;
use App\Support\Auth\PasswordTiming;
use App\Support\Auth\TotpEnrolment;
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

        // Accounts whose password was right and whose factor was simply not
        // supplied yet. Kept apart from a WRONG code, which is a different
        // answer to a different question.
        $awaitingCode = [];
        $codeWasWrong = false;

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

            // A user may have enrolled their own factor (§6) even without a
            // staff link. Either way, once there is one it is required — an
            // account that has switched 2FA on and can still be entered with a
            // password alone has not switched anything on.
            $needsCode = $staff !== null || $account->hasTotp();

            if ($needsCode) {
                if ($code === null || $code === '') {
                    // Password proven, factor missing. Saying so is safe: it is
                    // only reachable by someone who already has the password.
                    //
                    // The ACCOUNT is remembered, not just the fact. Somebody
                    // with an account in two organizations — one protected by an
                    // authenticator, one not — used to be signed silently into
                    // the unprotected one and could never reach the other: it
                    // was skipped here, the other matched, one match meant
                    // straight in, and the client only offers a code field once
                    // the server has asked for one, which never happened.
                    $needsSecondFactor = true;
                    $awaitingCode[] = $account;

                    continue;
                }

                /*
                 * The user's own verification runs INSIDE their organization.
                 *
                 * Confirming a code writes the time-step it consumed, and User is
                 * tenant-scoped — at login there is no tenant yet, so the write
                 * guard refuses it. Entering the account's own organization is
                 * the precise fix; widening the guard would be the loose one.
                 *
                 * Staff are not tenant-scoped, so theirs needs no such thing.
                 */
                $verified = app(FactorAttempts::class)->verify($staff ?? $account, $code, fn () => $staff !== null
                    ? $this->staffAuth->verifySecondFactor($staff, $code)
                    : $this->tenant->run(
                        $account->organization,
                        fn () => app(TotpEnrolment::class)->verify($account, $code),
                    ));

                if (! $verified) {
                    // Distinct from a missing code. Falling through to the
                    // generic failure told somebody their PASSWORD was wrong
                    // when their password was fine and they had fat-fingered a
                    // digit — which sends people resetting a password that was
                    // never the problem.
                    $codeWasWrong = true;

                    continue;
                }
            }

            $matched[] = $account;
        }

        if ($matched === [] && $codeWasWrong) {
            return AuthenticationResult::secondFactorRequired(
                'That code did not verify. Check the clock on the device, then try again.'
            );
        }

        if ($matched === [] && $needsSecondFactor) {
            return AuthenticationResult::secondFactorRequired();
        }

        /*
         * Some accounts matched, and at least one more is waiting for a code.
         *
         * Signing them into the one that needed nothing would be the convenient
         * answer and the wrong one: it silently picks an organization on their
         * behalf and makes the other unreachable. Offering the choice keeps both
         * doors open — /auth/choose already accepts a code, so picking the
         * protected one asks for it.
         */
        if ($matched !== [] && $awaitingCode !== []) {
            return AuthenticationResult::ambiguous([...$matched, ...$awaitingCode]);
        }

        if ($matched === []) {
            // One dummy hash so a non-existent address costs the same as a
            // wrong password. Without it, response time reveals which addresses
            // are registered. Built once, not with Hash::make on every miss —
            // that made unknown emails slower, which is an oracle too.
            if ($accounts->isEmpty()) {
                PasswordTiming::equalise($password);
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

        // Case-insensitive, as the first step's lookup is (MySQL's collation):
        // typing Jose@… there offered the picker, and an exact compare here
        // then refused every choice it had just offered.
        if ($user === null || strcasecmp((string) $user->email, trim($email)) !== 0 || ! $user->is_active) {
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

        if ($staff !== null) {
            if (! app(FactorAttempts::class)->verify($staff, (string) $code,
                fn () => $this->staffAuth->verifySecondFactor($staff, (string) $code))) {
                return AuthenticationResult::failed();
            }
        } elseif ($user->hasTotp()) {
            $verified = app(FactorAttempts::class)->verify($user, (string) $code, fn () => $this->tenant->run(
                $user->organization,
                fn () => app(TotpEnrolment::class)->verify($user, (string) $code),
            ));

            if (! $verified) {
                return AuthenticationResult::failed();
            }
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
