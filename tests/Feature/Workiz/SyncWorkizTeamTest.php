<?php

namespace Tests\Feature\Workiz;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WorkizTeamMember;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class SyncWorkizTeamTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connect(array $tenant, Franchise $branch, string $name): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $branch, $name) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => $name,
            ]);
            $c->connect(['api_token' => 'api_'.$name, 'refresh_token' => 'api_'.$name]);
            $c->save();

            return $c;
        });
    }

    /** @param array<int, array<string, mixed>> $members */
    private function fakeTeam(array $members): void
    {
        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => $members])]);
    }

    private function member(array $overrides = []): array
    {
        return array_merge([
            'id' => '35512',
            'name' => 'James Workiz',
            'email' => 'james@example.test',
            'role' => 'tech',
            'fieldTech' => true,
            'active' => true,
            'serviceAreas' => ['metro1', 'metro2'],
            'skills' => ['Service'],
        ], $overrides);
    }

    public function test_it_mirrors_a_member_onto_the_connections_branch(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->fakeTeam([$this->member()]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizTeamMember::query()->first());

        $this->assertSame('35512', $row->remote_id);
        $this->assertSame('James Workiz', $row->name);
        $this->assertSame($tenant['franchise']->id, $row->franchise_id);
        $this->assertTrue($row->is_field_tech);
        $this->assertSame(['metro1', 'metro2'], $row->service_areas);
    }

    /**
     * WORKIZ KEEPS LEAVERS FOR EVER — 444 members against 165 active on A-1's
     * live accounts. They are mirrored, not dropped, because last year's jobs
     * still name them; `is_active` is what a picker filters on.
     */
    public function test_a_leaver_is_mirrored_and_marked_inactive(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->fakeTeam([$this->member(['active' => false])]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizTeamMember::query()->first());

        $this->assertNotNull($row);
        $this->assertFalse($row->is_active);
    }

    /**
     * A MISSING FLAG MUST NOT RETIRE SOMEBODY. `active` has no captured payload
     * behind it, so its absence has to default to the harmless direction.
     */
    public function test_a_member_with_no_active_flag_is_treated_as_active(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $member = $this->member();
        unset($member['active']);
        $this->fakeTeam([$member]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $this->assertTrue($this->inTenant($tenant['org'], fn () => WorkizTeamMember::query()->first())->is_active);
    }

    public function test_an_exact_email_match_proposes_the_ezbizi_user(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        $user = $this->userWithRole($tenant, 'Tech', [], email: 'tech@example.test');

        $this->fakeTeam([$this->member(['email' => 'TECH@Example.Test'])]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizTeamMember::query()->first());

        $this->assertSame($user->getKey(), $row->user_id, 'the match must be case-insensitive');
        $this->assertTrue($row->auto_matched);
    }

    public function test_no_match_leaves_the_link_empty_rather_than_guessing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->fakeTeam([$this->member(['email' => 'nobody@example.test'])]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizTeamMember::query()->first());

        $this->assertNull($row->user_id);
        $this->assertFalse($row->auto_matched);
    }

    /**
     * THE ONE THAT MATTERS MOST. A sweep that re-derived the link every hour
     * would revert a correction the moment somebody made it — silently, and
     * repeatedly. Jose asked for the link to be settable; this is what makes
     * setting it stick.
     */
    public function test_a_confirmed_link_survives_the_next_sweep(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        $wrong = $this->userWithRole($tenant, 'Wrong', [], email: 'auto@example.test');
        $right = $this->userWithRole($tenant, 'Right', [], email: 'confirmed@example.test');

        Http::fake(['api.workiz.com/*' => Http::sequence()
            ->push(['flag' => true, 'data' => [$this->member(['email' => 'auto@example.test'])]])
            ->push(['flag' => true, 'data' => [$this->member(['email' => 'auto@example.test', 'name' => 'Renamed'])]]),
        ]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        // A person corrects the link by hand.
        $this->inTenant($tenant['org'], function () use ($right) {
            $row = WorkizTeamMember::query()->first();
            $row->user_id = $right->getKey();
            $row->auto_matched = false;
            $row->save();
        });

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizTeamMember::query()->first());

        $this->assertSame($right->getKey(), $row->user_id, 'the sweep must not revert a confirmed link');
        $this->assertFalse($row->auto_matched);
        $this->assertNotSame($wrong->getKey(), $row->user_id);

        // Workiz's own fields DO still refresh — the mirror is still a mirror.
        $this->assertSame('Renamed', $row->name);
    }

    public function test_running_twice_refreshes_rather_than_duplicating(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->fakeTeam([$this->member()]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();
        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => WorkizTeamMember::query()->count()));
    }
}
