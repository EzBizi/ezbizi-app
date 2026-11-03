<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PhoneLineAnswerTestTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_rings_only_our_lines_and_mails_the_named_people_on_a_failure(): void
    {
        // Mail::raw is not a mailable, so Mail::fake cannot count it: use the array transport.
        config(['mail.default' => 'array']);
        $twilio = new FakeTwilioGateway;
        $this->app->instance(TwilioGateway::class, $twilio);
        $t = $this->provisionTenant();
        config(['services.twilio.audit_alert_user_ids' => [$t['admin']->id]]);

        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            foreach (['+12035550001', '+12035550002', '+18559553322'] as $e164) {
                PhoneNumber::factory()->create([
                    'connection_id' => $connection->id,
                    'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                    'scope_id' => $t['org']->id,
                    'e164' => $e164,
                ]);
            }
        });
        // Off its hour (a Tuesday noon), nothing is dialled.
        \Illuminate\Support\Carbon::setTestNow('2026-11-03 12:00:00');
        $this->artisan('ezbizi:phone-line-answer-test', ['--wait' => 0])->assertSuccessful();
        $this->assertSame([], collect($twilio->calls)->where('method', 'platformCall')->all());
        \Illuminate\Support\Carbon::setTestNow();

        $twilio->platformCallResults['+12035550002'] = ['status' => 'no-answer', 'duration' => 0];

        $this->artisan('ezbizi:phone-line-answer-test', ['--wait' => 0, '--all' => true])
            ->expectsOutput('+12035550002: did not answer (no-answer)')
            ->assertSuccessful();

        $dialled = collect($twilio->calls)->where('method', 'platformCall')->map(fn ($c) => $c['args'][2])->sort()->values()->all();
        $this->assertSame(['+12035550001', '+12035550002'], $dialled);
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('+12035550002: did not answer', $sent[0]->getOriginalMessage()->getTextBody());
    }
}
