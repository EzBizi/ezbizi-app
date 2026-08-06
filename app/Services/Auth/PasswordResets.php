<?php

namespace App\Services\Auth;

use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
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
     * @return array{token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function issue(Model $subject, ?string $ip = null): array
    {
        // Anything outstanding for this account stops working. Two live tokens
        // means an old email sitting in a mailbox is still a way in.
        DB::table('password_resets')
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = Str::random(48);

        DB::table('password_resets')->insert([
            'id'            => (string) Str::uuid7(),
            'subject_type'  => $subject::class,
            'subject_id'    => $subject->getKey(),
            // Hashed with sha256 rather than bcrypt: it is a 48-character random
            // string, not a human-chosen password, so there is nothing to slow a
            // guesser down about — and a reset check should not cost 100ms.
            'token_hash'    => hash('sha256', $token),
            'expires_at'    => now()->addMinutes(self::LIFETIME_MINUTES),
            'requested_ip'  => $ip,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return ['token' => $token, 'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES)];
    }

    /**
     * Redeem a token and set the new password.
     *
     * @return Model|null the account whose password changed, or null
     */
    public function redeem(string $token, string $password): ?Model
    {
        $row = DB::table('password_resets')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var Model|null $subject */
        $subject = $row->subject_type === PlatformStaff::class
            ? PlatformStaff::find($row->subject_id)
            : $this->tenant->runWithoutTenant(fn () => User::find($row->subject_id));

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
        });

        $this->audit->logError('auth.password_reset', [
            'subject' => class_basename($subject),
            'email'   => $subject->email,
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
        });

        $this->audit->log('auth.password_changed', $subject instanceof User ? $subject : null, metadata: [
            'subject' => class_basename($subject),
        ]);
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
