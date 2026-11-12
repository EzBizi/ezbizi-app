<?php

namespace Tests\Feature;

use App\Support\Mail\MailStatus;
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
}
