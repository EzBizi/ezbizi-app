<?php

namespace Tests\Feature\Ads;

use App\Models\AuditEvent;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Pausing and enabling a campaign from EzBizi - Google's M.110.
 *
 * Jose asked for it on 2026-09-24. It is the first write this product makes to
 * a campaign, so the assertions are about the edges that cost money or trust:
 * the request can only ever carry `status`, a person who can only READ spend
 * cannot switch advertising off, one refused campaign does not hide the others,
 * and every change Google accepts leaves an audit row naming who did it.
 *
 * The request shape below is not invented. It was validated against Google on
 * 2026-09-24 with `validateOnly: true` - a no-op - on a Local Services campaign
 * (A1 Chimney - Atlanta) and a Search campaign (A1 Chimney - Cleveland), and
 * both were accepted.
 */
class CampaignStatusTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/ads/campaigns/status';

    private function connected(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function fakeGoogle(array $mutateResponses): void
    {
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        $sequence = Http::sequence();
        foreach ($mutateResponses as [$body, $status]) {
            $sequence->push($body, $status);
        }

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => $sequence,
        ]);
        Http::preventStrayRequests();
    }

    private function ok(): array
    {
        return [['results' => [['resourceName' => 'customers/4941850746/campaigns/1']]], 200];
    }

    /**
     * THE POSITIVE CONTROL, and the shape of the one write.
     *
     * `updateMask` is exactly `status`. That is the whole guarantee that this
     * cannot touch a budget or a bid: Google applies only the masked fields.
     */
    public function test_a_manager_pauses_a_campaign_and_only_its_status_is_sent(): void
    {
        $t = $this->provisionTenant('Alpha');
        $row = $this->connected($t);
        $this->fakeGoogle([$this->ok()]);

        $response = $this->signedInAs($this->userWithRole($t, 'Marketing', ['ads_performance.view', 'ads_manager.manage']))
            ->postJson(self::ENDPOINT, [
                'connection_id' => $row->getKey(),
                'campaign_ids' => ['22334455'],
                'status' => 'PAUSED',
            ])
            ->assertOk();

        $this->assertSame([['campaign_id' => '22334455', 'ok' => true, 'because' => null]], $response->json('data'));

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'campaigns:mutate')) {
                return false;
            }

            $op = $request['operations'][0];

            return str_ends_with($request->url(), '/customers/4941850746/campaigns:mutate')
                && $op['updateMask'] === 'status'
                && $op['update'] === [
                    'resourceName' => 'customers/4941850746/campaigns/22334455',
                    'status' => 'PAUSED',
                ]
                && $request->header('login-customer-id')[0] === '4941850746';
        });
    }

    /**
     * Reading spend does not let you switch it off.
     *
     * The two grants are split on purpose: a Dispatcher-shaped role that can
     * see the Google Ads tab must not be one press from pausing a branch.
     * Asserted as "nothing reached Google", not merely as a 403 - a refusal
     * produced after the mutate had been sent would pass a status check.
     */
    public function test_view_alone_cannot_pause_and_nothing_reaches_google(): void
    {
        $t = $this->provisionTenant('Bravo');
        $row = $this->connected($t);
        $this->fakeGoogle([$this->ok()]);

        $this->signedInAs($this->userWithRole($t, 'Reader', ['ads_performance.view']))
            ->postJson(self::ENDPOINT, [
                'connection_id' => $row->getKey(),
                'campaign_ids' => ['22334455'],
                'status' => 'PAUSED',
            ])
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'Pausing or enabling campaigns needs the Ads › Manage permission.']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'campaigns:mutate'));
    }

    /**
     * REMOVED is not offered: it is permanent in Google Ads.
     *
     * Refused by validation before any request is built, which is why the
     * assertion is that Google heard nothing rather than that it said no.
     */
    public function test_removing_a_campaign_is_not_possible(): void
    {
        $t = $this->provisionTenant('Charlie');
        $row = $this->connected($t);
        $this->fakeGoogle([$this->ok()]);

        $this->signedInAs($t['admin']->fresh())
            ->postJson(self::ENDPOINT, [
                'connection_id' => $row->getKey(),
                'campaign_ids' => ['22334455'],
                'status' => 'REMOVED',
            ])
            ->assertUnprocessable();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'campaigns:mutate'));
    }

    /**
     * One refused campaign does not hide the others, and Google's own words
     * come back.
     */
    public function test_each_campaign_gets_its_own_answer(): void
    {
        $t = $this->provisionTenant('Delta');
        $row = $this->connected($t);
        $this->fakeGoogle([
            $this->ok(),
            [['error' => ['message' => 'Request contains an invalid argument.', 'details' => [[
                'errors' => [['message' => 'The operation is not allowed for the given context.']],
            ]]]], 400],
        ]);

        $data = $this->signedInAs($t['admin']->fresh())
            ->postJson(self::ENDPOINT, [
                'connection_id' => $row->getKey(),
                'campaign_ids' => ['111', '222'],
                'status' => 'ENABLED',
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($data[0]['ok']);
        $this->assertFalse($data[1]['ok']);
        $this->assertSame('The operation is not allowed for the given context.', $data[1]['because']);
    }

    /**
     * Every accepted change is audited; a refused one is not.
     *
     * "Who paused Atlanta on Tuesday" must have an answer, and the log must not
     * claim a change Google turned down.
     */
    public function test_an_accepted_change_is_audited_and_a_refused_one_is_not(): void
    {
        $t = $this->provisionTenant('Echo');
        $row = $this->connected($t);
        $this->fakeGoogle([
            $this->ok(),
            [['error' => ['message' => 'no']], 403],
        ]);

        $actor = $t['admin']->fresh();

        $this->signedInAs($actor)
            ->postJson(self::ENDPOINT, [
                'connection_id' => $row->getKey(),
                'campaign_ids' => ['111', '222'],
                'status' => 'PAUSED',
            ])
            ->assertOk();

        $events = $this->inTenant($t['org'], fn () => AuditEvent::query()
            ->where('action', 'ads_performance.campaign_status_changed')->get());

        $this->assertCount(1, $events, 'one accepted, one refused');
        $this->assertSame($actor->getKey(), $events[0]->actor_id);
        $this->assertSame('111', $events[0]->metadata['campaign_id']);
        $this->assertSame(['status' => 'PAUSED'], $events[0]->changes);
    }

    /** An account outside the person's view cannot be touched, or confirmed to exist. */
    public function test_an_account_outside_the_view_is_refused_without_a_request(): void
    {
        $t = $this->provisionTenant('Foxtrot');
        $other = $this->provisionTenant('Golf');
        $theirs = $this->connected($other);
        $this->fakeGoogle([$this->ok()]);

        $this->signedInAs($t['admin']->fresh())
            ->postJson(self::ENDPOINT, [
                'connection_id' => $theirs->getKey(),
                'campaign_ids' => ['111'],
                'status' => 'PAUSED',
            ])
            ->assertStatus(409);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'campaigns:mutate'));
    }
}
