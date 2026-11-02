<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Ai\TranscriptSpeakers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12: transcripts divided "IVR Menu: / Dispatcher: / Customer:". */
class LabelCallTranscriptsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const RAW = 'Thank you for calling A1 Chimney, press 1 for service. Thank you for calling A1 Chimney, '
        .'press 1 for service. A1 Chimney, this is Ana. Hi, I saw you are hiring technicians in Baltimore. '
        .'Yes, send your resume to the office. Great, thank you.';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        config()->set('ezbizi.leads_ai_grok', true);
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        config()->set('services.grok_cli.since', now()->subMonth()->toDateString());
    }

    private function aCall(): LeadConversation
    {
        return $this->inTenant($this->t['org'], function () {
            $lead = Lead::create([
                'organization_id' => $this->t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => uniqid(), 'lead_type' => 'PHONE_CALL', 'status' => Lead::STATUS_NEW, 'received_at' => now(),
            ]);

            return LeadConversation::create([
                'organization_id' => $lead->organization_id, 'lead_id' => $lead->id, 'remote_id' => uniqid(),
                'channel' => LeadConversation::CHANNEL_PHONE_CALL, 'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => now(), 'duration_ms' => 148000, 'transcript' => self::RAW,
            ]);
        });
    }

    private function reread(LeadConversation $row): LeadConversation
    {
        return $this->inTenant($this->t['org'], fn () => $row->fresh());
    }

    private function grokSays(array $turns): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['turns' => $turns]]))]);
    }

    public function test_a_call_is_divided_by_speaker_with_the_menu_once_and_the_raw_kept(): void
    {
        $row = $this->aCall();
        $this->grokSays([
            ['speaker' => 'IVR menu', 'text' => 'Thank you for calling A1 Chimney, press 1 for service.'],
            ['speaker' => 'IVR menu', 'text' => 'Thank you for calling A1 Chimney, press 1 for service.'],
            ['speaker' => 'Dispatcher', 'text' => 'A1 Chimney, this is Ana.'],
            ['speaker' => 'Customer', 'text' => 'Hi, I saw you are hiring technicians in Baltimore.'],
            ['speaker' => 'Dispatcher', 'text' => 'Yes, send your resume'],
            ['speaker' => 'Dispatcher', 'text' => 'to the office.'],
            ['speaker' => 'Customer', 'text' => 'Great, thank you.'],
        ]);

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $row = $this->reread($row);
        $this->assertSame(self::RAW, $row->transcript, 'the Whisper words stay');
        $this->assertSame(
            "IVR menu: Thank you for calling A1 Chimney, press 1 for service.\n\n"
            ."Dispatcher: A1 Chimney, this is Ana.\n\n"
            ."Customer: Hi, I saw you are hiring technicians in Baltimore.\n\n"
            ."Dispatcher: Yes, send your resume to the office.\n\n"
            .'Customer: Great, thank you.',
            $row->transcript_labelled,
        );

        // Labelled once: the next run asks Grok nothing (still the one call).
        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_an_answer_that_lost_the_words_falls_back_to_the_raw_transcript(): void
    {
        $row = $this->aCall();
        $this->grokSays([['speaker' => 'Customer', 'text' => 'Wants a job.']]);

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $this->assertSame(self::RAW, $this->reread($row)->transcript_labelled);
    }

    public function test_grok_failing_leaves_it_for_the_next_run(): void
    {
        $row = $this->aCall();
        Process::fake(['*' => Process::result('', 'not signed in', 1)]);

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $this->assertNull($this->reread($row)->transcript_labelled);
    }

    public function test_a_speaker_grok_was_not_asked_for_is_dropped(): void
    {
        $this->assertNull(TranscriptSpeakers::assemble([['speaker' => 'Narrator', 'text' => self::RAW]], self::RAW));
    }

    public function test_the_summary_next_step_and_signals_are_kept_and_shown(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'turns' => [
                ['speaker' => 'Dispatcher', 'text' => 'A1 Chimney, this is Ana.'],
                ['speaker' => 'Customer', 'text' => 'Hi, I saw you are hiring technicians in Baltimore. Yes, send your resume to the office. Great, thank you.'],
            ],
            'summary' => 'Asked about technician jobs in Baltimore; told to send a resume.',
            'next_step' => '',
            'complaint' => false, 'asked_price' => true, 'asked_availability' => false, 'booked' => false,
        ]]))]);
        $row = $this->aCall();

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $row = $this->reread($row);
        $this->assertSame('Asked about technician jobs in Baltimore; told to send a resume.', $row->call_summary);
        $this->assertNull($row->call_next_step);
        $this->assertEquals(['complaint' => false, 'asked_price' => true, 'asked_availability' => false, 'booked' => false], $row->call_signals);
    }

    public function test_a_complaint_call_raises_lead_call_complaint(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'turns' => [['speaker' => 'Customer', 'text' => self::RAW]],
            'summary' => 'Technician never showed up; caller wants a refund.',
            'next_step' => 'Manager to call back',
            'complaint' => true, 'asked_price' => false, 'asked_availability' => false, 'booked' => false,
        ]]))]);
        $row = $this->aCall();

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $event = \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.call_complaint')->sole();
        $this->assertSame($row->lead_id, $event->subject_id);
    }

    /** Desk, 2026-11-02: a transferred call is two transcripts on one lead, and alerted twice. */
    public function test_one_call_in_two_transcripts_alerts_once(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'turns' => [['speaker' => 'Customer', 'text' => self::RAW]],
            'summary' => 'Unhappy.', 'next_step' => '',
            'complaint' => true, 'asked_price' => false, 'asked_availability' => false, 'booked' => false,
        ]]))]);
        $first = $this->aCall();
        $this->inTenant($this->t['org'], fn () => $first->replicate()->fill(['remote_id' => uniqid()])->save());

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $this->assertSame(1, \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.call_complaint')->count());
    }

    /** AI item 4: asked side by side, and one failure leaves only its own call for later. */
    public function test_calls_are_asked_together_and_a_failure_stays_with_its_call(): void
    {
        Process::fake(['*' => Process::sequence()
            ->push(Process::result('', 'not logged in', 1))
            ->push(Process::result(json_encode(['structuredOutput' => ['turns' => [['speaker' => 'Customer', 'text' => self::RAW]]]])))]);
        $a = $this->aCall();
        $b = $this->aCall();

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $done = array_filter([$this->reread($a)->transcript_labelled, $this->reread($b)->transcript_labelled]);
        $this->assertCount(1, $done, 'one answered, one left for the next run');
    }

    /** Leads review: a call 0.0.0410 split by speaker still gets its summary. */
    public function test_a_call_already_split_by_speaker_is_still_read_for_its_summary(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'turns' => [['speaker' => 'Customer', 'text' => self::RAW]],
            'summary' => 'Asked about jobs.', 'next_step' => '',
            'complaint' => false, 'asked_price' => false, 'asked_availability' => false, 'booked' => false,
        ]]))]);
        $row = $this->aCall();
        $this->inTenant($this->t['org'], fn () => $row->forceFill(['transcript_labelled' => 'Customer: earlier'])->save());

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $this->assertSame('Asked about jobs.', $this->reread($row)->call_summary);
    }

    /** Leads review: the first run must not alert about last week's complaints. */
    public function test_an_old_complaint_call_raises_nothing(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'turns' => [['speaker' => 'Customer', 'text' => self::RAW]],
            'summary' => 'Unhappy.', 'next_step' => '',
            'complaint' => true, 'asked_price' => false, 'asked_availability' => false, 'booked' => false,
        ]]))]);
        $row = $this->aCall();
        $this->inTenant($this->t['org'], fn () => $row->forceFill(['occurred_at' => now()->subDays(3)])->save());

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        $this->assertSame(0, \App\Models\AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.call_complaint')->count());
        $this->assertTrue($this->reread($row)->call_signals['complaint'], 'still read and stored');
    }

    /** Leads, 2026-10-12: a call that is only the menu reads labelled too. */
    public function test_a_menu_only_call_is_labelled_as_the_menu(): void
    {
        $this->assertSame('IVR menu: Press 1 for service.', TranscriptSpeakers::assemble(
            [['speaker' => 'IVR menu', 'text' => 'Press 1 for service.'], ['speaker' => 'IVR menu', 'text' => 'Press 1 for service.']],
            'Press 1 for service. Press 1 for service.',
        ));
    }

    /** Jose, 2026-10-13: "just new stuff from now on" - nothing before GROK_AI_SINCE is read. */
    public function test_a_call_before_the_ai_start_date_is_never_read(): void
    {
        config()->set('services.grok_cli.since', now()->toDateString());
        Process::fake();
        $row = $this->aCall();
        $this->inTenant($this->t['org'], fn () => $row->forceFill(['occurred_at' => now()->subDays(2)])->save());

        $this->artisan('ezbizi:label-call-transcripts')->assertSuccessful();

        Process::assertNothingRan();
        $this->assertNull($this->reread($row)->call_signals);
    }
}
