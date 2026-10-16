<?php

namespace Tests\Feature\Leads;

use App\Models\CallReportTranscript;
use App\Models\CallTypeCorrection;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Support\Leads\CallTypeCorrections;
use App\Support\Leads\RingCentralLeadCalls;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-16: wrong IVR key presses corrected from the database and the transcript. */
class CallTypeCorrectionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const NEW_Q = 'Main - New';

    private const OLD_Q = 'Main - Returning';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Everdoor');
        config()->set('ezbizi.leads_ai_grok', true);
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        $this->inTenant($this->t['org'], function () {
            // The business's own menu, from Settings: no A-1 queue names in code.
            app(SettingsResolver::class)->set('leads.new_client_queue', $this->t['org'], self::NEW_Q);
            app(SettingsResolver::class)->set('leads.existing_client_queue', $this->t['org'], self::OLD_Q);
        });
    }

    private static function rc(string $id, string $from, ?string $queue, array $extra = []): array
    {
        return $extra + ['id' => $id, 'direction' => 'Inbound', 'started_at' => now()->subMinutes(30)->toIso8601String(),
            'from' => $from, 'to_number' => '+17320000001', 'queue' => $queue, 'result' => 'Accepted',
            'rang' => ['Ana Diaz'], 'answered_by' => 'Ana Diaz', 'talk_seconds' => 95, 'recording_id' => null];
    }

    public function test_a_prior_job_outranks_the_key_and_the_transcript_decides_when_the_database_is_silent(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['caller' => 'existing', 'cue' => 'you came out last winter']]))]);

        $this->inTenant($this->t['org'], function () {
            // Two customer rows for one person, in different spellings; the job is on the other one.
            $c = Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id, 'name' => 'Pat', 'phone' => '(314) 409-1888']);
            Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id, 'name' => 'Pat S', 'phone' => '314.409.1888']);
            Job::factory()->create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $c->id, 'scheduled_for' => now()->subMonths(3)]);
            CallReportTranscript::query()->create(['organization_id' => $this->t['org']->id, 'call_id' => 'B', 'transcript' => 'Hi, you came out last winter...', 'source' => 'whisper']);

            app(RingCentralLeadCalls::class)->file([self::rc('A', '+13144091888', self::NEW_Q)], [], $this->t['company']->id);
            $n = app(CallTypeCorrections::class)->decide([
                self::rc('A', '+13144091888', self::NEW_Q),   // pressed New, has a job: existing
                self::rc('B', '+15550000002', self::NEW_Q),   // no job; the words say existing
                self::rc('C', '+15550000003', self::OLD_Q),   // no job, no words: the key stands, no row
            ]);
            $this->assertSame(2, $n);

            $rows = CallTypeCorrections::forCalls(['A', 'B', 'C']);
            $this->assertSame(['A', 'B'], array_keys($rows));
            $this->assertSame(['existing', 'job_match', self::NEW_Q], [$rows['A']['corrected_type'], $rows['A']['evidence'], $rows['A']['pressed_queue']]);
            $this->assertSame(['existing', 'transcript'], [$rows['B']['corrected_type'], $rows['B']['evidence']]);
            $this->assertStringContainsString('last winter', $rows['B']['reason']);
            $this->assertSame(RingCentralLeadCalls::EXISTING_CLIENT, Lead::query()->where('phone', '+13144091888')->value('source_status'), 'the RC tag follows');

            // Decided once: a later run asks nothing again.
            $this->assertSame(0, app(CallTypeCorrections::class)->decide([self::rc('A', '+13144091888', self::NEW_Q), self::rc('B', '+15550000002', self::NEW_Q)]));
        });
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_a_person_overrides_on_the_thread_and_it_is_final(): void
    {
        $lead = $this->inTenant($this->t['org'], function () {
            app(RingCentralLeadCalls::class)->file([self::rc('D', '+15550000004', self::OLD_Q)], [], $this->t['company']->id);

            return Lead::query()->firstOrFail();
        });
        $conversation = $this->inTenant($this->t['org'], fn () => $lead->conversations()->value('id'));
        $editor = $this->userWithRole($this->t, 'Desk', ['leads.view', 'leads.edit', 'leads.ringcentral.view']);

        $this->signedInAs($editor)->patchJson("/api/v1/leads/{$lead->id}/conversations/{$conversation}/caller-type", ['type' => 'new'])->assertForbidden();
        $this->signedInAs($this->t['admin'])->patchJson("/api/v1/leads/{$lead->id}/conversations/{$conversation}/caller-type", ['type' => 'new'])
            ->assertOk()->assertJsonPath('data.corrected_type', 'new');

        $this->inTenant($this->t['org'], function () use ($lead) {
            $row = CallTypeCorrection::query()->where('call_id', 'D')->firstOrFail();
            $this->assertSame(['person', $this->t['admin']->id], [$row->evidence, $row->decided_by]);
            $this->assertNull($lead->refresh()->source_status, 'pressed Existing, a person says new: the tag goes');
            $this->assertSame(0, app(CallTypeCorrections::class)->decide([self::rc('D', '+15550000004', self::OLD_Q)]), 'a person\'s word is final');
        });
    }
}
