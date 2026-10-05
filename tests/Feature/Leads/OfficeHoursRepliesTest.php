<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The closed-office auto replies (Jose, 2026-10-05): a first reply that says
 * when we open, one "we got your message" per closed period, and the photos
 * email address when a customer mentions pictures.
 */
class OfficeHoursRepliesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const C = LeadConversation::FROM_CONSUMER;

    private const A = LeadConversation::FROM_ADVERTISER;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        // Automations run inside the tenant; so do these reads.
        app(TenantContext::class)->set($this->t['org']);
        app(SettingsResolver::class)->set('timezone', $this->t['org'], 'America/New_York');
    }

    /** @param  array<int, array{0: string, 1: string, 2?: string}>  $said  [participant, at, text] */
    private function lead(array $said): Lead
    {
        return $this->inTenant($this->t['org'], function () use ($said) {
            $lead = Lead::create([
                'organization_id' => $this->t['org']->id,
                'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => uniqid(),
                'contact_name' => 'Dana Whitfield',
                'lead_type' => 'MESSAGE',
                'status' => Lead::STATUS_NEW,
                'received_at' => Carbon::parse($said[0][1]),
            ]);
            foreach ($said as $i => $s) {
                LeadConversation::create([
                    'organization_id' => $this->t['org']->id,
                    'lead_id' => $lead->id,
                    'remote_id' => uniqid("m{$i}"),
                    'channel' => LeadConversation::CHANNEL_MESSAGE,
                    'participant' => $s[0],
                    'occurred_at' => Carbon::parse($s[1]),
                    'is_automatic' => $s[0] === self::A,
                    'text' => $s[2] ?? 'Can someone look at my fireplace?',
                ]);
            }

            return $lead->fresh();
        });
    }

    public function test_open_and_next_opening_follow_the_office_hours(): void
    {
        $lead = $this->lead([[self::C, '2026-10-05T14:00:00Z']]);

        Carbon::setTestNow('2026-10-05T14:00:00Z'); // Monday 10:00 NY
        $this->assertTrue($lead->office_open);
        $this->assertSame('', $lead->office_note);

        Carbon::setTestNow('2026-10-05T11:00:00Z'); // Monday 7:00 NY
        $this->assertFalse($lead->office_open);
        $this->assertSame('today at 8:00 AM', $lead->office_opens);

        Carbon::setTestNow('2026-10-05T22:00:00Z'); // Monday 18:00 NY
        $this->assertSame('tomorrow at 8:00 AM', $lead->office_opens);

        Carbon::setTestNow('2026-10-03T16:00:00Z'); // Saturday noon NY
        $this->assertFalse($lead->office_open);
        $this->assertSame('on Monday at 8:00 AM', $lead->office_opens);
        $this->assertStringContainsString('closed right now', $lead->office_note);
    }

    public function test_a_branch_sets_its_own_hours(): void
    {
        $lead = $this->lead([[self::C, '2026-10-03T16:00:00Z']]);
        $week = (array) app(SettingsResolver::class)->get('leads.office_hours', $this->t['org']);
        app(SettingsResolver::class)->set('leads.office_hours', $this->t['org'], [
            ...$week, 'saturday' => ['open' => '09:00', 'close' => '13:00'],
        ]);

        Carbon::setTestNow('2026-10-03T16:00:00Z'); // Saturday noon NY
        $this->assertTrue($lead->fresh()->office_open);
    }

    public function test_one_closed_reply_per_closed_period(): void
    {
        Carbon::setTestNow('2026-10-04T15:00:00Z'); // Sunday 11:00 NY, closed since Friday 21:00Z

        // Answered (automatically) on Thursday, writes on Sunday: due.
        $due = $this->lead([[self::C, '2026-10-01T14:00:00Z'], [self::A, '2026-10-01T14:00:30Z'], [self::C, '2026-10-04T14:55:00Z']]);
        $this->assertTrue($due->closed_reply_due);

        // Already told on Saturday: not again.
        $told = $this->lead([[self::C, '2026-10-03T14:00:00Z'], [self::A, '2026-10-03T14:00:30Z'], [self::C, '2026-10-04T14:55:00Z']]);
        $this->assertFalse($told->closed_reply_due);

        // A message imported late is not answered.
        $stale = $this->lead([[self::C, '2026-10-01T14:00:00Z'], [self::A, '2026-10-01T14:00:30Z'], [self::C, '2026-10-04T12:00:00Z']]);
        $this->assertFalse($stale->closed_reply_due);

        // Open office: never.
        Carbon::setTestNow('2026-10-05T14:00:00Z');
        $open = $this->lead([[self::C, '2026-10-01T14:00:00Z'], [self::A, '2026-10-01T14:00:30Z'], [self::C, '2026-10-05T13:58:00Z']]);
        $this->assertFalse($open->closed_reply_due);
    }

    public function test_photos_email_is_given_once_when_pictures_are_mentioned(): void
    {
        Carbon::setTestNow('2026-10-05T14:00:00Z');

        $photos = $this->lead([[self::C, '2026-10-05T13:50:00Z', 'I can send you some pictures of the damage']]);
        $this->assertFalse($photos->photos_email_due, 'no address set for the branch');

        app(SettingsResolver::class)->set('leads.photos_email', $this->t['org'], 'office@ny.example.com');
        $this->assertTrue($photos->fresh()->photos_email_due);
        $this->assertSame('office@ny.example.com', $photos->photos_email);

        $plain = $this->lead([[self::C, '2026-10-05T13:50:00Z', 'Can you come Tuesday?']]);
        $this->assertFalse($plain->photos_email_due);

        $given = $this->lead([
            [self::C, '2026-10-05T13:40:00Z', 'I have photos'],
            [self::A, '2026-10-05T13:41:00Z', 'Please email them to office@ny.example.com'],
            [self::C, '2026-10-05T13:50:00Z', 'Here is the report file'],
        ]);
        $this->assertFalse($given->photos_email_due);
    }

    public function test_a_photos_message_at_night_gets_the_photos_reply_not_both(): void
    {
        Carbon::setTestNow('2026-10-04T15:00:00Z');
        app(SettingsResolver::class)->set('leads.photos_email', $this->t['org'], 'office@ny.example.com');

        $lead = $this->lead([[self::C, '2026-10-01T14:00:00Z'], [self::A, '2026-10-01T14:00:30Z'], [self::C, '2026-10-04T14:55:00Z', 'want pictures?']]);

        $this->assertTrue($lead->photos_email_due);
        $this->assertFalse($lead->closed_reply_due);
        $this->assertStringContainsString('tomorrow at 8:00 AM', $lead->office_note);
    }
}
