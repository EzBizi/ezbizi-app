<?php

namespace App\Console\Commands;

use App\Models\PlatformStaff;
use App\Services\Staff\StaffAuthenticator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create an EzBizi staff account (plan §4.2).
 *
 * CONSOLE ONLY, deliberately. There is no HTTP endpoint that creates staff:
 * an internet-reachable "create an account that can see every customer" route
 * is a liability no amount of authorization makes comfortable. Making one
 * requires shell access to the box.
 *
 * The account cannot be used until TOTP enrolment is completed — this command
 * prints the enrolment secret and the account stays unusable until a code
 * proves the authenticator works.
 */
class CreateStaffAccount extends Command
{
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
            $this->warn('    · is exempt from the ordinary business audit trail (§4.2)');
            $this->warn('  Impersonations are still always recorded.');
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
        $this->line('  Add this to your authenticator app:');
        $this->newLine();
        $this->line("    Secret:  {$enrolment['secret']}");
        $this->line("    Account: EzBizi ({$staff->email})");
        $this->newLine();
        $this->line('  Then confirm with the six-digit code:');
        $this->line("    php artisan ezbizi:staff-enrol {$staff->email} <code>");
        $this->newLine();

        return self::SUCCESS;
    }
}
