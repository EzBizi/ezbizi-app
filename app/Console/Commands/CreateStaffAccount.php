<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ShowsTotpEnrolment;
use App\Models\PlatformStaff;
use App\Services\Staff\StaffAuthenticator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create an EzBizi staff account (plan §4.2).
 *
 * THE BOOTSTRAP PATH, and the only one that needs no existing account.
 * `POST /api/v1/staff/accounts` also creates staff, behind a session that is
 * already a Lead Developer's — which is fine and is how the second account
 * onwards should be made. This one exists for the first, and for the evening
 * when nobody can sign in to the control plane at all.
 *
 * The account cannot be used until TOTP enrolment is completed — this command
 * prints the enrolment secret and the account stays unusable until a code
 * proves the authenticator works.
 */
class CreateStaffAccount extends Command
{
    use ShowsTotpEnrolment;

    protected $signature = 'ezbizi:staff-create
                            {email : The staff email address}
                            {--name= : Display name}
                            {--lead : Create as Lead Developer rather than Developer}';

    protected $description = 'Create an EzBizi platform staff account (§4.2)';

    public function handle(StaffAuthenticator $auth): int
    {
        $email = strtolower(trim($this->argument('email')));

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'unique:platform_staff,email'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Display name');

        $password = $this->secret('Password (min 16 characters)');
        if (strlen((string) $password) < 16) {
            // Longer than the tenant default (12). This account reaches every
            // customer on the platform; the ordinary policy is not enough.
            $this->error('Staff passwords must be at least 16 characters.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm password')) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $tier = $this->option('lead') ? PlatformStaff::TIER_LEAD : PlatformStaff::TIER_DEV;

        if ($tier === PlatformStaff::TIER_LEAD) {
            $this->warn('');
            $this->warn('  Creating a LEAD DEVELOPER account. This tier:');
            $this->warn('    · reaches every organization on the platform');
            $this->warn('    · may enter a tenant without client approval');
            $this->warn('    · may manage every other staff account');
            $this->warn('  Impersonations are always recorded, whatever the tier.');
            $this->newLine();
            // Said here because the tier used to carry it, and anyone who has
            // read this warning before will remember that it did. It is now a
            // separate flag on one account (§4.2 — "per-account, not per-role"),
            // and this command does not grant it.
            $this->warn('  It is NOT exempt from the audit trail. That exemption is a');
            $this->warn('  separate flag, held by one account at a time, granted through');
            $this->warn('  the control plane and recorded when it changes hands.');
            $this->warn('');

            if (! $this->confirm('Continue?', false)) {
                return self::FAILURE;
            }
        }

        $staff = PlatformStaff::create([
            'name'      => $name,
            'email'     => $email,
            'password'  => $password,
            'tier'      => $tier,
            'is_active' => true,
        ]);

        $enrolment = $auth->beginEnrolment($staff);

        $this->newLine();
        $this->info("Created {$staff->email} ({$tier}).");
        $this->newLine();
        $this->warn('  TOTP enrolment is REQUIRED before this account can sign in.');
        $this->newLine();

        $this->showEnrolment($staff, $enrolment);

        return self::SUCCESS;
    }
}
