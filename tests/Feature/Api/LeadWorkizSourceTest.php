<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Leads\LeadReplies;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Workiz is a book of past work, not an inbox, and the queue has to know it.
 *
 * ── The failure these exist to prevent ───────────────────────────
 *
 * A-1's Workiz book is about 31,000 leads across nine branches. They are CRM
 * records somebody typed, not messages a member of the public sent: no thread,
 * no recording, and read-only v1 tokens by Jose's ruling, so no channel to
 * answer on either.
 *
 * The Unreplied filter is `last_contact_from = CONSUMER OR IS NULL`, and that
 * null clause is right for a Google enquiry whose conversation has not been
 * fetched yet. For Workiz it is permanently true, so without a rule every one
 * of those 31,000 rows would land in the dispatcher's morning queue — the one
 * list whose entire value is that everything in it needs answering.
 *
 * Nothing about that failure is visible: no error, no slow query, just a queue
 * that stops meaning anything. So it is asserted from both sides.
 */
class LeadWorkizSourceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/leads';

    private function connection(array $t, SocialProvider $provider): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $t['franchise']->id,
                'provider' => $provider->value,
                'remote_id' => 'acct-'.$provider->value,
                'display_name' => 'A1 Chimney',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function lead(array $t, string $source, string $name, ?SocialConnection $connection = null): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'franchise_id' => $t['franchise']->id,
            'connection_id' => $connection?->getKey(),
            'source' => $source,
            'remote_id' => $source.'-'.bin2hex(random_bytes(5)),
            'contact_name' => $name,
            'phone' => '+1 (555) 123-4567',
            'phone_digits' => Lead::digits('+1 (555) 123-4567'),
            'category' => 'chimney_sweep',
            'status' => Lead::STATUS_NEW,
            'received_at' => now()->subHour(),
        ]));
    }

    /** @return array<string, string[]> */
    private function everyKey(): array
    {
        return ['keys' => array_map(fn ($s) => "leads.{$s}.view", Lead::SOURCES)];
    }

    /**
     * THE POSITIVE CONTROL FIRST: a Workiz lead is listed like any other.
     *
     * Every other assertion here is about it being left OUT of something, and
     * an absence is also what a lead that never imported, or a source nobody
     * can see, would produce.
     */
    public function test_a_workiz_lead_is_listed(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->lead($t, Lead::SOURCE_WORKIZ, 'Dana Whitfield', $this->connection($t, SocialProvider::Workiz));

        $response = $this->signedInAs($this->userWithRole($t, 'Desk', $this->everyKey()['keys']))
            ->getJson(self::ENDPOINT.'?source=workiz')
            ->assertOk();

        $this->assertSame('Dana Whitfield', $response->json('data.0.contact_name'));
    }

    /**
     * AND IT IS NOT IN THE UNREPLIED QUEUE, while a Google lead in the same
     * state is.
     *
     * Both rows have `last_contact_from` null — the identical database state.
     * The only thing separating them is what the row IS, which is what the
     * filter now asks. A test with only the Workiz row would pass against a
     * filter that had simply stopped working.
     */
    public function test_unreplied_holds_the_google_lead_and_not_the_workiz_one(): void
    {
        $t = $this->provisionTenant('Bravo');
        $this->lead($t, Lead::SOURCE_LOCAL_SERVICES, 'Marcus Reed', $this->connection($t, SocialProvider::GoogleAds));
        $this->lead($t, Lead::SOURCE_WORKIZ, 'Dana Whitfield', $this->connection($t, SocialProvider::Workiz));

        $response = $this->signedInAs($this->userWithRole($t, 'Desk', $this->everyKey()['keys']))
            ->getJson(self::ENDPOINT.'?unreplied=1')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('contact_name')->all();

        $this->assertContains('Marcus Reed', $names, 'a Google enquiry with no thread yet is still waiting');
        $this->assertNotContains(
            'Dana Whitfield',
            $names,
            'a book of copied records is not a queue of people awaiting an answer',
        );
    }

    /**
     * A Workiz lead that somehow HAS a consumer message is still out.
     *
     * The discriminating case: had the rule been "exclude rows with null
     * last_contact_from" rather than "exclude sources that are not an inbox",
     * this row would come back — and it is still a copied record rather than
     * somebody waiting.
     */
    public function test_even_a_workiz_lead_with_a_message_stays_out_of_unreplied(): void
    {
        $t = $this->provisionTenant('Charlie');
        $lead = $this->lead($t, Lead::SOURCE_WORKIZ, 'Dana Whitfield', $this->connection($t, SocialProvider::Workiz));

        $this->inTenant($t['org'], fn () => $lead->forceFill([
            'last_contact_from' => LeadConversation::FROM_CONSUMER,
            'last_contact_at' => now(),
        ])->save());

        $response = $this->signedInAs($this->userWithRole($t, 'Desk', $this->everyKey()['keys']))
            ->getJson(self::ENDPOINT.'?unreplied=1')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    /**
     * There is no reply channel, and the screen is told why rather than shown a
     * dead button.
     */
    public function test_a_workiz_lead_cannot_be_replied_to(): void
    {
        $t = $this->provisionTenant('Delta');
        $lead = $this->lead($t, Lead::SOURCE_WORKIZ, 'Dana Whitfield', $this->connection($t, SocialProvider::Workiz));

        $replies = app(LeadReplies::class);

        $this->inTenant($t['org'], function () use ($replies, $lead) {
            $this->assertFalse($replies->canReply($lead));
            $this->assertFalse(
                $replies->allowsUnattendedReply($lead),
                'no channel means no rule may send on it either',
            );
        });
    }

    /**
     * Workiz is the only source outside the inbox, and that is stated here so
     * the next one is a decision rather than a copy.
     *
     * THE RULE IS NOT "CAN WE REPLY TO IT", and the first version of this file
     * asserted that it was. `website_form` has no reply channel either - a form
     * is rung back, not answered in EzBizi - and a form is exactly what the
     * dispatcher's queue is for. Had that rule shipped, every website enquiry
     * would have silently left the morning list.
     *
     * So the discriminator is what the row IS: an enquiry somebody sent us and
     * nobody has answered, or a record copied out of another system. The
     * assertion is deliberately an exact list, because adding a source to
     * INBOX_SOURCES is a claim that somebody owes its sender an answer.
     */
    public function test_workiz_is_the_only_source_outside_the_inbox(): void
    {
        $this->assertSame(
            [Lead::SOURCE_WORKIZ],
            array_values(array_diff(Lead::SOURCES, Lead::INBOX_SOURCES)),
            'a source outside the inbox is excused the dispatcher queue - say why in Lead::INBOX_SOURCES',
        );

        // And the inbox is not empty, so the line above is a rule rather than
        // an accident of two identical lists.
        $this->assertNotEmpty(Lead::INBOX_SOURCES);
    }

    /** Disconnecting the branch's account hides its book, like the others. */
    public function test_a_workiz_lead_hides_when_its_account_is_disconnected(): void
    {
        $t = $this->provisionTenant('Foxtrot');
        $connection = $this->connection($t, SocialProvider::Workiz);
        $lead = $this->lead($t, Lead::SOURCE_WORKIZ, 'Dana Whitfield', $connection);

        $reader = $this->userWithRole($t, 'Desk', $this->everyKey()['keys']);

        $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(1, 'data');

        $this->inTenant($t['org'], fn () => $lead->forceFill(['connection_id' => null])->save());

        $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(0, 'data');
    }
}
