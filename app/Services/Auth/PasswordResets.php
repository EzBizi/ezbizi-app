<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing and redeeming password resets (plan §4, §4.2).
 *
 * ── What a reset must not do ─────────────────────────────────────
 *
 * It must not tell a stranger whether an address is registered. Every request
 * gets the same answer whether or not anything matched, and the work happens
 * regardless so the timing does not give it away either.
 *
 * It must not bypass the second factor. Resetting a password proves control of a
 * mailbox; it proves nothing about the authenticator, and an account with TOTP
 * still needs a code afterwards. That is the whole point of a second factor and
 * the most common place implementations quietly drop it.
 *
 * It must not survive its own use. A token is single-use and short-lived, and
 * redeeming one revokes every existing session — because the usual reason
 * somebody resets a password is that they think it is known.
 */
class PasswordResets
{
    /** Long enough to walk to a computer, short enough not to sit in a mailbox. */
    private const LIFETIME_MINUTES = 60;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every account that address could mean.
     *
     * A linked user is represented by its STAFF record, because that is where
     * the password lives — resetting the user row would change a value nothing
     * reads (§4.2, revised).
     *
     * @return Model[]
     */
    public function subjectsFor(string $email): array
    {
        $email = strtolower(trim($email));

        $subjects = [];

        $staff = PlatformStaff::where('email', $email)->first();

        if ($staff !== null && $staff->is_active) {
            $subjects[] = $staff;
        }

        // Users are tenant-scoped and this runs before any tenant exists.
        $users = $this->tenant->runWithoutTenant(
            fn () => User::query()->with('organization')->where('email', $email)->get()
        );

        foreach ($users as $user) {
            if (! $user->is_active) {
                continue;
            }

            $linked = PlatformStaffLink::staffFor($user->getKey());

            // Already covered by the staff record above; adding it again would
            // send two emails that reset the same password.
            if ($linked !== null) {
                continue;
            }

            $subjects[] = $user;
        }

        return $subjects;
    }

    /**
     * @return array{token: string, expires_at: Carbon}
     */
    public function issue(Model $subject, ?string $ip = null): array
    {
        $minutes = self::LIFETIME_MINUTES;

        // Anything outstanding for this account stops working. Two live tokens
        // means an old email sitting in a mailbox is still a way in.
        DB::table('password_resets')
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(48);

        DB::table('password_resets')->insert([
            'id' => (string) Str::uuid7(),
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            // Hashed with sha256 rather than bcrypt: it is a 48-character random
            // string, not a human-chosen password, so there is nothing to slow a
            // guesser down about — and a reset check should not cost 100ms.
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($minutes),
            'requested_ip' => $ip,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => now()->addMinutes($minutes)];
    }

    /**
     * Whose account a live token belongs to, WITHOUT spending it.
     *
     * Exists because the password policy is a property of the organization
     * (`security.password_min_length`, §6) and the reset form arrives with no
     * session — so the only route to the right minimum is the token. The check
     * has to happen before the write, and the write consumes the token, so the
     * lookup has to be separable from redeeming.
     *
     * Returns null for anything expired, spent or unknown, exactly as redeem()
     * does. A caller that gets null should NOT branch on it: hand the token to
     * redeem() and let that produce the single "expired or already used" answer,
     * or this becomes a second place where the wording could differ.
     */
    public function subjectFor(string $token): ?Model
    {
        $row = $this->liveToken($token);

        return $row === null ? null : $this->subjectOf($row);
    }

    /**
     * The organization whose policy governs this token's password, if any.
     *
     * Null for a platform staff reset: staff are not tenant-scoped, so no
     * organization's setting has any claim on their password. Their floor is the
     * one in StaffAccountsController, which is higher than any tenant's anyway.
     */
    public function organizationFor(string $token): ?Organization
    {
        $subject = $this->subjectFor($token);

        return $subject instanceof User ? $subject->organization : null;
    }

    /**
     * Redeem a token and set the new password.
     *
     * @return Model|null the account whose password changed, or null
     */
    public function redeem(string $token, string $password): ?Model
    {
        $row = $this->liveToken($token);

        if ($row === null) {
            return null;
        }

        $subject = $this->subjectOf($row);

        if ($subject === null) {
            return null;
        }

        DB::transaction(function () use ($row, $subject, $password) {
            DB::table('password_resets')->where('id', $row->id)->update([
                'used_at' => now(), 'updated_at' => now(),
            ]);

            $this->write($subject, $password);

            // Every session goes. The usual reason for a reset is that somebody
            // believes the old password is known, and leaving live tokens would
            // make the reset cosmetic.
            $subject->tokens()->delete();
            $this->revokeLinkedUsers($subject);
        });

        $this->audit->logError('auth.password_reset', [
            'subject' => class_basename($subject),
            'email' => $subject->email,
        ]);

        return $subject;
    }

    /**
     * Change a password for somebody already signed in.
     *
     * Keeps the CURRENT session alive and revokes the rest: being logged out of
     * the browser you just used to change your password is confusing, and being
     * left signed in everywhere else is the actual risk.
     */
    public function change(Model $subject, string $password, ?string $keepTokenId = null): void
    {
        DB::transaction(function () use ($subject, $password, $keepTokenId) {
            $this->write($subject, $password);

            $subject->tokens()
                ->when($keepTokenId, fn ($q, $id) => $q->where('id', '!=', $id))
                ->delete();
            $this->revokeLinkedUsers($subject, $keepTokenId);
        });

        $this->audit->log('auth.password_changed', $subject instanceof User ? $subject : null, metadata: [
            'subject' => class_basename($subject),
        ]);
    }

    /**
     * A staff password also signs in every CRM account linked to it (§4.2,
     * revised), so a CRM token is a session of the same credential. Revoking
     * only the staff tokens left a stolen CRM token working for up to 30 days
     * after the reset that was meant to end it.
     *
     * Straight on personal_access_tokens: User is tenant-scoped and a reset
     * runs with no tenant, and these are other organizations' rows besides.
     */
    private function revokeLinkedUsers(Model $subject, int|string|null $keepTokenId = null): void
    {
        if (! $subject instanceof PlatformStaff) {
            return;
        }

        $userIds = PlatformStaffLink::query()->where('staff_id', $subject->getKey())->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->when($keepTokenId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->delete();
    }

    /** The row for a token that is still good, or null. */
    private function liveToken(string $token): ?object
    {
        return DB::table('password_resets')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * The account a reset row points at.
     *
     * `with('organization')` on the user branch because both callers need it —
     * the write below enters that organization, and the policy check reads a
     * setting against it — and a lazy load would run outside any tenant, where
     * the read scope refuses rather than quietly matching every row.
     */
    private function subjectOf(object $row): ?Model
    {
        return $row->subject_type === PlatformStaff::class
            ? PlatformStaff::find($row->subject_id)
            : $this->tenant->runWithoutTenant(
                fn () => User::with('organization')->find($row->subject_id)
            );
    }

    /**
     * Writing to a tenant-scoped model needs a tenant, and a reset runs before
     * anybody has signed in — so the account's own organization is entered for
     * exactly the write.
     */
    private function write(Model $subject, string $password): void
    {
        if ($subject instanceof User) {
            $this->tenant->run($subject->organization, function () use ($subject, $password) {
                $subject->forceFill(['password' => $password])->save();
            });

            return;
        }

        $subject->forceFill(['password' => $password])->save();
    }
}
