<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A disconnected account takes its enquiries off the list with it.
 *
 * Jose, 2026-09-16, having disconnected A-1 Chimney's Google Ads account and
 * found its enquiries still sitting in the list: *"if the account where the
 * leads come from is disconnected, then it should hide said leads, only show
 * them again once the account is reconnected."*
 *
 * ── Three things have to hold at once ───────────────────────────
 *
 * Hidden. Not deleted, and reversible by reconnecting. And website enquiries,
 * which never had a `connection_id` to lose, must not be caught by a rule
 * written to catch enquiries that HAVE lost one. That third case is the one a
 * naive `whereNotNull('connection_id')` fails, and it fails silently: every
 * chat and form enquiry the business has ever received disappears, and nothing
 * on screen says why.
 */
class DisconnectedAccountsHideTheirLeadsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $t, string $customerId = '4051729356'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $customerId) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->getKey(),
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->getKey(),
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => $customerId,
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function lead(
        array $t,
        string $name,
        string $source,
        ?SocialConnection $connection,
    ): Lead {
        static $n = 0;
        $n++;

        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'company_id' => $t['company']->getKey(),
            'connection_id' => $connection?->getKey(),
            'source' => $source,
            'remote_id' => 'L-'.$n,
            'contact_name' => $name,
            'status' => Lead::STATUS_NEW,
            'received_at' => now()->subMinutes($n),
        ]));
    }

    /** @return string[] the contact names the list returned, in order */
    private function listed(array $t): array
    {
        return array_map(
            fn (array $row) => (string) $row['contact_name'],
            $this->signedInAs($t['admin']->fresh())
                ->getJson('/api/v1/leads')
                ->assertOk()
                ->json('data'),
        );
    }

    public function test_an_enquiry_from_a_disconnected_account_leaves_the_list(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connection($t);

        $this->lead($t, 'Still Connected', Lead::SOURCE_LOCAL_SERVICES, $connection);
        $orphan = $this->lead($t, 'Account Gone', Lead::SOURCE_LOCAL_SERVICES, $connection);

        $this->assertContains('Account Gone', $this->listed($t));

        // What disconnecting does to a lead: it nulls the link. The row itself
        // is untouched, which is the whole basis for this being reversible.
        $this->inTenant($t['org'], fn () => $orphan->forceFill(['connection_id' => null])->save());

        $names = $this->listed($t);

        $this->assertContains('Still Connected', $names);
        $this->assertNotContains(
            'Account Gone',
            $names,
            'an enquiry whose account was disconnected is still on the list',
        );
    }

    public function test_the_enquiries_are_hidden_rather_than_deleted(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connection($t);
        $lead = $this->lead($t, 'Account Gone', Lead::SOURCE_LOCAL_SERVICES, $connection);

        $this->inTenant($t['org'], fn () => $lead->forceFill(['connection_id' => null])->save());

        $this->assertNotContains('Account Gone', $this->listed($t));

        // The distinction Jose asked for, and the one a filter can quietly get
        // wrong: the row is still there to come back.
        $this->assertNotNull(
            $this->inTenant($t['org'], fn () => Lead::query()->find($lead->getKey())),
            'hiding an enquiry must not be a delete wearing a filter',
        );
    }

    public function test_reconnecting_the_account_brings_them_back(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connection($t);
        $lead = $this->lead($t, 'Account Gone', Lead::SOURCE_LOCAL_SERVICES, $connection);

        $this->inTenant($t['org'], fn () => $lead->forceFill(['connection_id' => null])->save());
        $this->assertNotContains('Account Gone', $this->listed($t));

        // Reconnecting re-links the row - which is what reclaim() does on the
        // real path, keyed on remote_customer_id. "Show them again once the
        // account is reconnected" needs no second mechanism.
        $this->inTenant(
            $t['org'],
            fn () => $lead->forceFill(['connection_id' => $connection->getKey()])->save(),
        );

        $this->assertContains(
            'Account Gone',
            $this->listed($t),
            'hiding has to be reversible or it is a delete with extra steps',
        );
    }

    /**
     * THE CASE A NAIVE RULE BREAKS.
     *
     * Website chat and form enquiries arrive through the WordPress plugin and
     * are CREATED with `connection_id` null - they never had one. A rule
     * written as "hide every lead with no connection" takes every one of them
     * off the list on the day it ships, for a business that has disconnected
     * nothing.
     */
    public function test_website_enquiries_never_had_a_connection_and_must_stay(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $this->lead($t, 'Chat Visitor', Lead::SOURCE_WEBSITE_CHAT, null);
        $this->lead($t, 'Form Visitor', Lead::SOURCE_WEBSITE_FORM, null);

        $names = $this->listed($t);

        $this->assertContains('Chat Visitor', $names);
        $this->assertContains('Form Visitor', $names);
    }

    /**
     * And it is not a Google rule. Jose, 2026-09-15, on the reply tags: "that
     * goes for everything, google thumbtack, etc."
     */
    public function test_thumbtack_obeys_the_same_rule(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connection($t);
        $lead = $this->lead($t, 'Thumbtack Enquiry', Lead::SOURCE_THUMBTACK, $connection);

        $this->assertContains('Thumbtack Enquiry', $this->listed($t));

        $this->inTenant($t['org'], fn () => $lead->forceFill(['connection_id' => null])->save());

        $this->assertNotContains(
            'Thumbtack Enquiry',
            $this->listed($t),
            'the rule is about enquiries arriving through an account, not about Google',
        );
    }

    /**
     * The dashboard count says the same number the list does.
     *
     * Hiding on one surface and counting on the other puts "38 enquiries" above
     * a list showing two, and the person reading has no way to tell which is
     * lying. Asserted on the payload rather than on the query, because a count
     * that reads the right rows and sends a different figure is the failure.
     */
    public function test_the_dashboard_count_agrees_with_the_list(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $connection = $this->connection($t);

        $this->lead($t, 'Still Connected', Lead::SOURCE_LOCAL_SERVICES, $connection);
        $gone = $this->lead($t, 'Account Gone', Lead::SOURCE_LOCAL_SERVICES, $connection);

        $this->inTenant($t['org'], fn () => $gone->forceFill(['connection_id' => null])->save());

        $this->assertCount(1, $this->listed($t));

        $glances = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('glances');

        $leads = collect($glances)->firstWhere('key', 'leads');

        $this->assertNotNull($leads, 'the Leads glance was not on the dashboard at all');

        // The box counts ONE, the same one the list shows. Asserted on the
        // rendered sentences rather than on the query, because a count that
        // reads the right rows and renders a different figure is the failure
        // a person would actually see.
        $rendered = (string) json_encode($leads['data']['sentences'] ?? []);

        $this->assertStringContainsString('1 ', $rendered);
        $this->assertStringNotContainsString(
            '2 ',
            $rendered,
            'the dashboard is counting an enquiry the list refuses to show',
        );
    }
}
