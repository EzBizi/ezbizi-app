<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A customer left waiting 10 minutes: the list marks the lead, and
 * `lead.reply_overdue` is raised once per unanswered message (Jose,
 * 2026-09-28). A lead that is booked, or that nobody can reply to, never is.
 */
class ReplyOverdueTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private SocialConnection $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28T15:00:00Z');
        $this->t = $this->provisionTenant('A1-Group');
        $this->account = $this->inTenant($this->t['org'], function () {
            $row = SocialConnection::create([
                'organization_id' => $this->t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function lead(string $id, string $type, string $lastAt, string $from = 'CONSUMER', string $status = 'new'): Lead
    {
        return $this->inTenant($this->t['org'], fn () => Lead::create([
            'organization_id' => $this->t['org']->id,
            'connection_id' => $this->account->id,
            'source' => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id' => $id,
            'contact_name' => "Customer {$id}",
            'lead_type' => $type,
            'status' => $status,
            'received_at' => Carbon::parse($lastAt)->subHour(),
            'last_contact_at' => Carbon::parse($lastAt),
            'last_contact_from' => $from,
            'last_contact_preview' => 'Can you come Saturday?',
        ]));
    }

    private function raised(): array
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.reply_overdue')->get()->all();
    }

    public function test_a_waiting_customer_is_raised_once_and_marked_on_the_list(): void
    {
        $waiting = $this->lead('a', 'MESSAGE', '2026-09-28T14:45:00Z');
        $this->lead('fresh', 'MESSAGE', '2026-09-28T14:55:00Z');                 // 5 minutes: not yet
        $this->lead('answered', 'MESSAGE', '2026-09-28T14:40:00Z', LeadConversation::FROM_ADVERTISER);
        $this->lead('booked', 'MESSAGE', '2026-09-28T14:40:00Z', 'CONSUMER', Lead::STATUS_BOOKED);
        $this->lead('call', 'PHONE_CALL', '2026-09-28T14:40:00Z');              // nothing to reply on

        $this->artisan('ezbizi:leads-reply-overdue')->assertSuccessful();

        $events = $this->raised();
        $this->assertCount(1, $events);
        $this->assertSame($waiting->id, $events[0]->subject_id);
        $this->assertSame(15, $events[0]->payload['after']['minutes_waiting'] ?? $events[0]->payload['minutes_waiting'] ?? null);
        $this->assertSame('ls_text', $events[0]->payload['after']['kind'] ?? $events[0]->payload['kind'] ?? null);

        $rows = collect($this->signedInAs($this->t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data'))->keyBy('contact_name');
        $this->assertTrue($rows['Customer a']['reply_overdue']);
        $this->assertFalse($rows['Customer fresh']['reply_overdue']);
        $this->assertFalse($rows['Customer booked']['reply_overdue']);
        $this->assertFalse($rows['Customer call']['reply_overdue']);

        // The next minute is not news.
        $this->artisan('ezbizi:leads-reply-overdue')->assertSuccessful();
        $this->assertCount(1, $this->raised());

        // A new message from them starts the clock again.
        Carbon::setTestNow('2026-09-28T15:20:00Z');
        $this->inTenant($this->t['org'], fn () => $waiting->forceFill(['last_contact_at' => Carbon::parse('2026-09-28T15:08:00Z')])->save());
        $this->artisan('ezbizi:leads-reply-overdue')->assertSuccessful();
        $this->assertCount(2, array_filter($this->raised(), fn ($e) => $e->subject_id === $waiting->id));
    }

    public function test_google_splits_into_ls_call_and_ls_text(): void
    {
        $this->lead('t', 'MESSAGE', '2026-09-28T14:00:00Z');
        $this->lead('c', 'PHONE_CALL', '2026-09-28T14:00:00Z');
        $this->signedInAs($this->t['admin']);

        $this->assertSame(['Customer c'], array_column($this->getJson('/api/v1/leads?source=ls_call')->assertOk()->json('data'), 'contact_name'));
        $this->assertSame(['Customer t'], array_column($this->getJson('/api/v1/leads?source=ls_text')->assertOk()->json('data'), 'contact_name'));
        $this->assertCount(2, $this->getJson('/api/v1/leads?source=local_services')->assertOk()->json('data'));

        // A booking made through Google is its own tab, not a text (Jose, 2026-10-01).
        $this->lead('b', 'BOOKING', '2026-09-28T14:00:00Z');
        $this->assertSame(['Customer b'], array_column($this->getJson('/api/v1/leads?source=ls_booking')->assertOk()->json('data'), 'contact_name'));
        $this->assertSame(['Customer t'], array_column($this->getJson('/api/v1/leads?source=ls_text')->assertOk()->json('data'), 'contact_name'));

        // All, with LS Call left out - the default (Jose, 2026-09-29).
        $this->assertSame(['Customer t'], array_column($this->getJson('/api/v1/leads?sources=ls_text,thumbtack')->assertOk()->json('data'), 'contact_name'));
        $this->assertCount(2, $this->getJson('/api/v1/leads?sources=ls_text,ls_call')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/leads?sources=-')->assertOk()->json('data'), 'nothing ticked, nothing shown');

        // Unread per source for the rail, whatever the list is narrowed to.
        $this->postJson('/api/v1/leads/'.Lead::withoutGlobalScopes()->where('remote_id', 'c')->value('id').'/read')->assertSuccessful();
        $meta = $this->getJson('/api/v1/leads?source=thumbtack')->assertOk()->json('meta.unread_by_source');
        $this->assertSame(['ls_booking' => 1, 'ls_text' => 1], collect($meta)->sortKeys()->all(), 'the read call is not counted, and the thumbtack filter does not narrow it');
    }

    /**
     * Completed: the customer's last word was "Thank you" and nobody owes them
     * an answer (Jose, 2026-10-01). Off the overdue mark and the Unreplied
     * list; writing again reopens it.
     */
    public function test_completed_clears_the_warning_until_the_customer_writes_again(): void
    {
        $lead = $this->lead('thanks', 'MESSAGE', '2026-09-28T14:40:00Z');
        $this->signedInAs($this->t['admin']);

        $this->assertTrue($this->getJson('/api/v1/leads')->json('data.0.reply_overdue'));
        $this->patchJson("/api/v1/leads/{$lead->id}", ['status' => Lead::STATUS_COMPLETED])->assertOk();

        $this->assertFalse($this->getJson('/api/v1/leads')->json('data.0.reply_overdue'));
        $this->assertSame([], $this->getJson('/api/v1/leads?unreplied=1')->json('data'));
        $this->artisan('ezbizi:leads-reply-overdue')->assertSuccessful();
        $this->assertSame([], $this->raised());

        $this->inTenant($this->t['org'], fn () => app(\App\Support\Leads\LeadThread::class)->store($lead->fresh(), 'again-1', [
            'channel' => LeadConversation::CHANNEL_MESSAGE, 'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => now(), 'text' => 'One more question',
        ]));
        $this->assertSame(Lead::STATUS_NEW, $lead->fresh()->status, 'writing again reopens it');
    }
}
