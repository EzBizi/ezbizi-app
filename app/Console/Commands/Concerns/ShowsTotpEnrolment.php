<?php

namespace App\Console\Commands\Concerns;

use App\Models\PlatformStaff;
use App\Support\Totp\EnrolmentQr;

/**
 * The enrolment hand-off, shown identically by create and reset.
 *
 * Shared because the two commands are the same moment from different
 * directions, and two copies of a security instruction drift — the one nobody
 * updated is the one someone follows.
 */
trait ShowsTotpEnrolment
{
    /** @param array{secret: string, uri: string} $enrolment */
    protected function showEnrolment(PlatformStaff $staff, array $enrolment): void
    {
        $this->line('  Scan this with your authenticator app:');
        $this->newLine();
        $this->line(EnrolmentQr::terminal($enrolment['uri']));
        $this->newLine();
        $this->line('  Or enter it by hand — in Google Authenticator, "Enter a setup key":');
        $this->newLine();
        $this->line("    Account name:  {$staff->email}");
        $this->line("    Key:           {$enrolment['secret']}");
        $this->line('    Type of key:   Time based');
        $this->newLine();
        $this->line('  Then confirm with the six-digit code it shows:');
        $this->line("    php artisan ezbizi:staff-enrol {$staff->email} <code>");
        $this->newLine();
    }
}
