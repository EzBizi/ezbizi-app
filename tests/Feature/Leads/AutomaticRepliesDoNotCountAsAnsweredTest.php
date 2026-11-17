<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\LeadThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A robot saying hello is not somebody answering.
 *
 * ── What this unblocks ──────────────────────────────────────────
 *
 * "Unreplied" means the newest row is from the CONSUMER, so ANY advertiser row
 * took an enquiry out of that list the instant it landed — the list a business
 * uses to find the people nobody has got back to.
 *
 * Two modules were stuck on it. Web Management defaulted their website greeting
 * ON, three Leads tests went red, and it shipped defaulted OFF to avoid this;
 * `WidgetDefaults` still carries the comment saying so. Automations would not
 * build Jose's "reply to the lead" rule because every rule anybody wrote would
 * have switched off their own unreplied list as a side effect.
 *
 * ── The split ───────────────────────────────────────────────────
 *
 * The preview still comes from the newest row — the greeting IS the most recent
 * thing said, and hiding it would be a different lie. `last_contact_from` comes
 * from the newest row a person was involved in, because its only two readers
 * (the filter, and the Replied / Awaiting reply badge) are both asking whether
 * anybody has answered.
 */
class AutomaticRepliesDoNotCountAsAnsweredTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function lead(array $t): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'company_id' => $t['company']->getKey(),
            'source' => Lead::SOURCE_WEBSITE_CHAT,
            'remote_id' => 'chat-'.bin2hex(random_bytes(4)),
            'contact_name' => 'Dana Whitfield',
            'status' => Lead::STATUS_NEW,
            'received_at' => now()->subMinutes(5),
        ]));
    }

    private function say(Lead $lead, string $from, string $text, bool $automatic = false, string $channel = 'LIVECHAT'): void
    {
        static $n = 0;
        $n++;

        $this->inTenant($lead->organization, function () use ($lead, $from, $text, $automatic, $n, $channel) {
            $thread = app(LeadThread::class);

            $thread->store($lead, 'row-'.$n, [
                'channel' => $channel,
                'participant' => $from,
                'occurred_at' => now()->subMinutes(5)->addSeconds($n),
                'text' => $text,
                'is_automatic' => $automatic,
            ]);

            $thread->refresh($lead);
        });
    }

    /** @return string[] contact names the unreplied filter returned */
    private function unreplied(array $t): array
    {
        return array_map(
            fn (array $row) => (string) $row['contact_name'],
            $this->signedInAs($t['admin']->fresh())
                ->getJson('/api/v1/leads?unreplied=1')
                ->assertOk()
                ->json('data'),
        );
    }

    /**
     * THE DEFECT. A greeting at t=0 made a brand new enquiry read as answered.
     */
    public function test_an_automatic_greeting_leaves_the_enquiry_awaiting_a_reply(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $lead = $this->lead($t);

        $this->say($lead, LeadConversation::FROM_CONSUMER, 'Is Saturday ok?');
        $this->assertContains('Dana Whitfield', $this->unreplied($t));

        // The website answers by itself, one second later.
        $this->say(
            $lead,
            LeadConversation::FROM_ADVERTISER,
            'Thanks for getting in touch, somebody will be with you shortly.',
            automatic: true,
        );

        $this->assertContains(
            'Dana Whitfield',
            $this->unreplied($t),
            'nobody has answered this person, and the list exists to find them',
        );
    }

    /**
     * THE CONTROL, and the half that makes the test above readable: a reply a
     * person actually sent must still take the enquiry off the list. Without
     * this, "ignore automatic rows" passes on a filter that ignores every
     * reply, which would be the same bug pointing the other way.
     */
    public function test_a_human_reply_still_answers_it(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $lead = $this->lead($t);

        $this->say($lead, LeadConversation::FROM_CONSUMER, 'Is Saturday ok?');
        $this->say($lead, LeadConversation::FROM_ADVERTISER, 'Saturday works, see you at 10.');

        $this->assertNotContains(
            'Dana Whitfield',
            $this->unreplied($t),
            'a real reply must still count as one',
        );
    }

    /**
     * And a person answering AFTER the greeting counts, which is the ordinary
     * case: the automatic row is newest by nothing, the human one is what the
     * question is about.
     */
    public function test_a_human_reply_after_the_greeting_counts(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $lead = $this->lead($t);

        $this->say($lead, LeadConversation::FROM_CONSUMER, 'Is Saturday ok?');
        $this->say($lead, LeadConversation::FROM_ADVERTISER, 'Thanks for getting in touch.', automatic: true);
        $this->say($lead, LeadConversation::FROM_ADVERTISER, 'Saturday works, see you at 10.');

        $this->assertNotContains('Dana Whitfield', $this->unreplied($t));
    }

    /**
     * THE PREVIEW IS NOT THE FILTER.
     *
     * The greeting is the most recent thing said and the list should show it.
     * A fix that made the enquiry look unanswered by HIDING the greeting would
     * pass every assertion above and be a different lie.
     */
    public function test_the_greeting_still_shows_as_the_latest_thing_said(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $lead = $this->lead($t);

        $this->say($lead, LeadConversation::FROM_CONSUMER, 'Is Saturday ok?');
        $this->say($lead, LeadConversation::FROM_ADVERTISER, 'Thanks for getting in touch.', automatic: true);

        $fresh = $this->inTenant($t['org'], fn () => Lead::query()->find($lead->getKey()));

        $this->assertStringContainsString(
            'Thanks for getting in touch',
            (string) $fresh->last_contact_preview,
            'the preview shows what was last said, greeting included',
        );

        $this->assertSame(
            LeadConversation::FROM_CONSUMER,
            $fresh->last_contact_from,
            'and the answered-or-not column still says the enquirer spoke last',
        );
    }

    /**
     * A call is not a message waiting on an answer (Jose, 2026-11-17: an
     * answered call, then our automatic "Not booked" text, read "Awaiting
     * reply"). Our text after the call is the last word until they write.
     */
    public function test_our_automatic_text_after_a_call_answers_it(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $lead = $this->lead($t);

        $this->say($lead, LeadConversation::FROM_CONSUMER, '', channel: 'PHONE_CALL');
        $this->assertContains('Dana Whitfield', $this->unreplied($t));

        $this->say($lead, LeadConversation::FROM_ADVERTISER, 'Book your visit here.', automatic: true, channel: 'SMS');
        $this->assertNotContains('Dana Whitfield', $this->unreplied($t));

        // And when they text back, they are owed a reply again.
        $this->say($lead, LeadConversation::FROM_CONSUMER, 'What does it cost?', channel: 'SMS');
        $this->assertContains('Dana Whitfield', $this->unreplied($t));
    }
}
