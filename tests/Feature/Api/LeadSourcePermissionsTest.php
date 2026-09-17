<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A source a role may not see is not in the list either (Jose, 2026-09-17).
 *
 * His words: *"i should be able to grant access by sub tab as well, so for
 * example i want them to see their work hours, but not the onboarding
 * information, etc - so this applies to every tab and it's sub tabs"*.
 *
 * ── Why this suite is about the LIST and not the tabs ────────────
 *
 * Human Resources' tabs are panes behind their own routes, so route middleware
 * is the whole enforcement and its suite asserts 403s. Leads has ONE list
 * behind ONE endpoint, and the four sources are a filter on it. Hiding a chip
 * while `All` still returns the rows would be a decoration rather than a
 * permission - the pane somebody is refused would be visible inside the pane
 * they are allowed, which is worse than not gating it at all, because it looks
 * gated.
 *
 * So the assertions below are about ROWS: what comes back with no `source`
 * parameter, what comes back when the parameter names a source that was not
 * granted, and what `show()` answers for an id somebody was handed.
 */
class LeadSourcePermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/leads';

    /**
     * Google enquiries need a connected account or they are hidden anyway.
     *
     * Not decoration: since 2026-09-16 a Google or Thumbtack lead with no
     * `connection_id` means the account was disconnected and is hidden from the
     * list. A fixture without this would be invisible for the WRONG reason, and
     * every assertion below would pass on a bug.
     */
    private function googleAccount(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4051729356',
                'display_name' => 'A1 Chimney',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function lead(array $t, string $source, string $name): Lead
    {
        // Website enquiries never carry a connection - they arrive through the
        // WordPress plugin - so the column is null for them by design.
        $connection = in_array($source, Lead::SOURCES_VIA_CONNECTION, true)
            ? $this->googleAccount($t)->getKey()
            : null;

        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection,
            'source' => $source,
            'remote_id' => 'lead-'.bin2hex(random_bytes(6)),
            'contact_name' => $name,
            'phone' => '+1 (555) 123-4567',
            'phone_digits' => Lead::digits('+1 (555) 123-4567'),
            'category' => 'chimney_sweep',
            'status' => Lead::STATUS_NEW,
            'received_at' => now()->subHour(),
        ]));
    }

    /**
     * THE POSITIVE CONTROL, and it is first deliberately.
     *
     * Everything else here asserts an absence, and an absence is also what a
     * broken route, an empty fixture, a role with no reach or a filter that
     * refuses everybody would produce. The HR side of this change was refused
     * by a platform setting rather than a permission and read as working
     * perfectly for exactly that reason.
     */
    public function test_a_role_granted_one_source_reads_that_source(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->lead($t, Lead::SOURCE_WEBSITE_CHAT, 'Dana Whitfield');

        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['leads.website_chat.view']);

        $response = $this->signedInAs($dispatcher)->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame('Dana Whitfield', $response->json('data.0.contact_name'));
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * The point of the change: `All` is the request that must not leak.
     *
     * The default request the screen makes carries no `source` parameter, so a
     * gate applied only where the parameter is present would be a gate on the
     * one request nobody makes.
     */
    public function test_all_leaves_out_the_sources_that_were_not_granted(): void
    {
        $t = $this->provisionTenant('Bravo');
        $this->lead($t, Lead::SOURCE_WEBSITE_CHAT, 'Dana Whitfield');
        $this->lead($t, Lead::SOURCE_LOCAL_SERVICES, 'Marcus Reed');

        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['leads.website_chat.view']);

        $response = $this->signedInAs($dispatcher)->getJson(self::ENDPOINT)->assertOk();

        $this->assertCount(1, $response->json('data'), 'All must not carry the ungranted source');
        $this->assertSame('Dana Whitfield', $response->json('data.0.contact_name'));
    }

    /** And naming it in the URL does not get round it. */
    public function test_asking_for_an_ungranted_source_returns_nothing(): void
    {
        $t = $this->provisionTenant('Charlie');
        $this->lead($t, Lead::SOURCE_LOCAL_SERVICES, 'Marcus Reed');

        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['leads.website_chat.view']);

        $this->signedInAs($dispatcher)
            ->getJson(self::ENDPOINT.'?source=local_services')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A single lead by id, which is the paste the list filter cannot stop.
     *
     * 404 rather than 403: somebody who may not see Google enquiries should not
     * learn that this id is one. The trait already refuses another
     * organization's rows the same way.
     */
    public function test_a_lead_of_an_ungranted_source_is_not_found_by_id(): void
    {
        $t = $this->provisionTenant('Delta');
        $hidden = $this->lead($t, Lead::SOURCE_LOCAL_SERVICES, 'Marcus Reed');
        $shown = $this->lead($t, Lead::SOURCE_WEBSITE_CHAT, 'Dana Whitfield');

        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['leads.website_chat.view']);

        // The control on the same route with the same person: show() answers.
        $this->signedInAs($dispatcher)
            ->getJson(self::ENDPOINT.'/'.$shown->getKey())
            ->assertOk();

        $this->signedInAs($dispatcher)
            ->getJson(self::ENDPOINT.'/'.$hidden->getKey())
            ->assertNotFound();
    }

    /**
     * Holding every source is holding the list as it was.
     *
     * The negative control for the filter itself: without this, a `whereIn`
     * that matched nothing would pass every assertion above while having taken
     * the module away from everybody. It is also the state the migration puts
     * every existing role into.
     */
    public function test_a_role_holding_all_four_sources_sees_all_of_them(): void
    {
        $t = $this->provisionTenant('Echo');
        $this->lead($t, Lead::SOURCE_WEBSITE_CHAT, 'Dana Whitfield');
        $this->lead($t, Lead::SOURCE_WEBSITE_FORM, 'Priya Raman');
        $this->lead($t, Lead::SOURCE_LOCAL_SERVICES, 'Marcus Reed');

        $everything = $this->userWithRole($t, 'Manager', [
            'leads.local_services.view',
            'leads.thumbtack.view',
            'leads.website_chat.view',
            'leads.website_form.view',
        ]);

        $this->signedInAs($everything)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /**
     * `leads.view` alone is now somebody who may open an empty module.
     *
     * Recorded as a test rather than left implicit, because it is the state an
     * administrator can create by hand from the roles editor and the screen
     * gives no reason for it - the list is simply empty, which is also what a
     * quiet morning looks like. If that is ever judged wrong, this is the test
     * that says what changed and where.
     */
    public function test_the_module_view_alone_carries_no_sources(): void
    {
        $t = $this->provisionTenant('Foxtrot');
        $this->lead($t, Lead::SOURCE_WEBSITE_CHAT, 'Dana Whitfield');

        $bare = $this->userWithRole($t, 'Bare', ['leads.view']);

        $this->signedInAs($bare)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
