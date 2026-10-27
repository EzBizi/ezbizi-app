<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-27: EzBizi is the master; Workiz, Google Ads, Local Services,
 * Thumbtack and Business Profile are optional. With none connected, every
 * Leads job runs clean and every Leads screen answers; a disconnected account
 * leaves the leads it brought in EzBizi.
 */
class LeadsWithoutIntegrationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Every scheduled command that reads one of Leads' integrations. */
    private const COMMANDS = [
        'ezbizi:sync-leads', 'ezbizi:import-workiz-leads', 'ezbizi:match-leads-to-jobs',
        'ezbizi:thumbtack-lead-costs', 'ezbizi:thumbtack-keep-alive',
        'ezbizi:ads-certifications', 'ezbizi:ads-daily-review', 'ezbizi:ads-campaign-watch',
        'ezbizi:leads-read', 'ezbizi:leads-link-profiles',
        'ezbizi:gbp-sync', 'ezbizi:gbp-sync --reviews', 'ezbizi:gbp-performance-sync', 'ezbizi:gbp-daily-digest',
    ];

    public function test_with_nothing_connected_every_job_runs_clean_and_every_screen_answers(): void
    {
        $t = $this->provisionTenant('Alpha');
        Http::preventStrayRequests();

        foreach (self::COMMANDS as $command) {
            $this->artisan($command)->assertSuccessful();
        }

        // ads/performance and ads/rollup are one account's pages, asked for by connection_id once ads/accounts lists one.
        foreach (['leads', 'leads/dashboard', 'leads/stats', 'ads/accounts',
            'business-profile/locations', 'business-profile/performance'] as $path) {
            $this->signedInAs($t['admin'])->getJson('/api/v1/'.$path.'?from=2026-10-01&to=2026-10-27')->assertOk();
        }
    }

    public function test_a_disconnected_accounts_leads_stay_and_open(): void
    {
        $t = $this->provisionTenant('Alpha');

        $leads = $this->inTenant($t['org'], function () use ($t) {
            $out = [];
            foreach ([SocialProvider::GoogleAds, SocialProvider::GoogleBusinessProfile, SocialProvider::Thumbtack, SocialProvider::Workiz] as $provider) {
                $c = new SocialConnection;
                $c->forceFill([
                    'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                    'scope_id' => $t['org']->id, 'provider' => $provider->value, 'remote_id' => uniqid(), 'display_name' => $provider->value,
                ]);
                $c->connect(['api_token' => 'x', 'refresh_token' => 'x']);
                $c->save();

                $lead = Lead::create([
                    'organization_id' => $t['org']->id, 'connection_id' => $c->id, 'source' => Lead::SOURCE_THUMBTACK,
                    'remote_id' => uniqid(), 'contact_name' => 'Pat', 'status' => 'new', 'received_at' => now(),
                ]);
                LeadConversation::create(['lead_id' => $lead->id, 'channel' => 'MESSAGE', 'participant' => LeadConversation::FROM_CONSUMER,
                    'text' => 'Hello', 'occurred_at' => now(), 'remote_id' => uniqid()]);
                $c->delete();
                $out[] = $lead->id;
            }

            return $out;
        });

        foreach ($leads as $id) {
            $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$id}")->assertOk()
                ->assertJsonPath('data.contact_name', 'Pat')
                ->assertJsonCount(1, 'data.conversations');
        }
        // Kept, not removed. The list hides them until the account is back
        // (Jose, 2026-09-16; LeadsController::scopeToReader).
        $this->assertSame(4, $this->inTenant($t['org'], fn () => Lead::query()->whereIn('id', $leads)->count()));
    }
}
