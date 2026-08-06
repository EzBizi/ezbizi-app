<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ShowsTotpEnrolment;
use App\Models\PlatformStaff;
use App\Services\Staff\StaffAuthenticator;
use Illuminate\Console\Command;

/**
 * Confirm a staff account's TOTP enrolment (plan §4.2).
 *
 * Console only, and it has to be: the account cannot sign in until enrolment is
 * confirmed, so an HTTP endpoint for this would need its own way in — which is
 * exactly the "authenticate without the second factor" hole the mandatory TOTP
 * rule exists to close.
 */
class EnrolStaffAccount extends Command
{
    use ShowsTotpEnrolment;

    protected $signature = 'ezbizi:staff-enrol
                            {email : The staff email address}
                            {code : The current six-digit code from the authenticator}
                            {--reset : Discard the existing secret and start enrolment again}';

    protected $description = 'Confirm (or reset) TOTP enrolment for a staff account';

    public function handle(StaffAuthenticator $auth): int
    {
        $staff = PlatformStaff::where('email', strtolower(trim($this->argument('email'))))->first();

        if ($staff === null) {
            $this->error('No staff account with that address.');

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $enrolment = $auth->beginEnrolment($staff);

            $this->newLine();
            $this->warn('  Previous authenticator entry is now invalid, and the account');
            $this->warn('  cannot sign in until the new one is confirmed.');
            $this->newLine();

            $this->showEnrolment($staff, $enrolment);

            return self::SUCCESS;
        }

        $recovery = $auth->completeEnrolment($staff, $this->argument('code'));

        if ($recovery === null) {
            $this->error('That code did not verify. Check the clock on the device, then try again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("TOTP confirmed. {$staff->email} can now sign in.");
        $this->newLine();
        $this->warn('  RECOVERY CODES — shown once, never again. Store them somewhere');
        $this->warn('  that is not the phone holding the authenticator.');
        $this->newLine();

        foreach ($recovery as $code) {
            $this->line("    {$code}");
        }

        $this->newLine();
        $this->line('  Each works once, in place of a six-digit code.');
        $this->newLine();

        return self::SUCCESS;
    }
}
