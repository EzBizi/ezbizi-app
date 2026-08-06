<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use Illuminate\Console\Command;

/**
 * Change a staff password (plan §4.2).
 *
 * Console only, like the rest of staff account management. Existing tokens are
 * revoked: a password change usually means the old one is suspect, and leaving
 * live sessions running would make the change cosmetic.
 */
class SetStaffPassword extends Command
{
    protected $signature = 'ezbizi:staff-password {email : The staff email address}';

    protected $description = 'Change a staff account password and revoke its tokens';

    public function handle(): int
    {
        $staff = PlatformStaff::where('email', strtolower(trim($this->argument('email'))))->first();

        if ($staff === null) {
            $this->error('No staff account with that address.');

            return self::FAILURE;
        }

        $password = $this->secret('New password (min 16 characters)');

        if (strlen((string) $password) < 16) {
            $this->error('Staff passwords must be at least 16 characters.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm new password')) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $staff->password = $password;
        $staff->save();

        $revoked = $staff->tokens()->delete();

        $this->info("Password changed for {$staff->email}. {$revoked} session(s) revoked.");
        $this->line('TOTP enrolment is unchanged — use --reset on ezbizi:staff-enrol to replace it.');

        return self::SUCCESS;
    }
}
