<?php

namespace Tests\Feature;

use App\Support\Audit\AuditLogger;
use App\Support\Mail\MailStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SchedulerFailedTest extends TestCase
{
    public function test_a_failed_slot_is_mailed_once_an_hour(): void
    {
        config(['ezbizi.backups.alert_to' => 'ops@example.test']);
        $this->mock(MailStatus::class)->shouldReceive('delivers')->andReturnTrue();
        Mail::shouldReceive('raw')->once();

        $this->artisan('ezbizi:scheduler-failed', ['unit' => 'ezbizi-scheduler@0.service'])->assertSuccessful();
        $this->artisan('ezbizi:scheduler-failed', ['unit' => 'ezbizi-scheduler@0.service'])->assertSuccessful();
    }

    public function test_the_mail_still_goes_when_the_audit_and_cache_cannot_be_written(): void
    {
        config(['ezbizi.backups.alert_to' => 'ops@example.test']);
        $this->mock(MailStatus::class)->shouldReceive('delivers')->andReturnTrue();
        $this->mock(AuditLogger::class)->shouldReceive('logError')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('no db'));
        Mail::shouldReceive('raw')->once();

        $this->artisan('ezbizi:scheduler-failed', ['unit' => 'ezbizi-scheduler-slow'])->assertSuccessful();
    }
}
