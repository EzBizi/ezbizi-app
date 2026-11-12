<?php

namespace Tests\Feature\Workiz;

use App\Models\AutomationEvent;
use App\Models\Notification;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Models\WorkizTagChange;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-10: every account's tags side by side, and a warning when anyone changes one. */
class WorkizTagsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private SocialConnection $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->c = $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $this->t['franchise']->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $this->t['franchise']->id, 'display_name' => 'NY']);
            $c->connect(['api_token' => 'k', 'refresh_token' => 'k', 'api_version' => 'v2']);
            $c->save();

            return $c;
        });
    }

    private function read(array $tags): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.workiz.com/crm/api/v2/tags*' => Http::response(['pageSize' => 300, 'page' => 1, 'hasMore' => false, 'data' => $tags])]);
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['tag']])->assertSuccessful();
    }

    public function test_each_change_is_logged_told_raised_and_a_rename_reaches_the_jobs(): void
    {
        $this->read([['id' => 'T1', 'name' => 'DEPOSIT RECIEVED', 'color' => '#111111'], ['id' => 'T2', 'name' => 'VIP', 'color' => '#222222'],
            ['id' => 'T3', 'name' => 'OLD', 'color' => '#333333']]);
        $this->assertSame(0, $this->inTenant($this->t['org'], fn () => WorkizTagChange::query()->count()), 'a first read is no change');
        $job = $this->inTenant($this->t['org'], fn () => WorkizJob::query()->create(['franchise_id' => $this->t['franchise']->id, 'connection_id' => $this->c->id,
            'remote_id' => 'J1', 'status' => 'Done', 'payload' => ['Tags' => ['DEPOSIT RECIEVED']], 'synced_at' => now()]));

        $this->read([['id' => 'T1', 'name' => 'DEPOSIT RECEIVED', 'color' => '#111111'], ['id' => 'T2', 'name' => 'VIP', 'color' => '#999999'],
            ['id' => 'T4', 'name' => 'NEW', 'color' => '#444444']]);

        $this->inTenant($this->t['org'], function () use ($job) {
            $this->assertEqualsCanonicalizing(['renamed', 'recoloured', 'added', 'deleted'], WorkizTagChange::query()->pluck('change')->all());
            $this->assertSame(['DEPOSIT RECEIVED'], $job->fresh()->payload['Tags']);
            $this->assertNull(WorkizRecord::query()->where('type', 'tag')->where('remote_id', 'T3')->first(), 'a deleted tag leaves the list');
            $this->assertSame(4, AutomationEvent::query()->where('event_key', 'franchise.workiz_tag_changed')->count());
            $told = Notification::query()->where('source', 'workiz_tag_changed')->get();
            $this->assertNotEmpty($told);
            $this->assertTrue($told->contains('user_id', $this->t['admin']->id));
            $this->assertTrue($told->contains(fn ($n) => str_contains((string) $n->body, '"DEPOSIT RECIEVED" -> "DEPOSIT RECEIVED"')));
        });
    }

    public function test_the_tab_flags_what_differs_from_the_approved_standard(): void
    {
        $this->read([['id' => 'T1', 'name' => 'VIP', 'color' => '#222222']]);
        $this->signedInAs($this->t['admin']);

        $this->getJson('/api/v1/workiz/tags')->assertOk()->assertJsonPath('data.baseline_set', false)->assertJsonPath('data.rows.0.differs', true);

        $this->postJson('/api/v1/workiz/tags/approve')->assertOk()->assertJsonPath('data.approved', 1);
        // A branch on a v1 key holds no tag list: it is not a column, so it cannot make every tag look missing.
        $branch = $this->extraFranchise($this->t, 'Everdoors');
        $this->inTenant($this->t['org'], function () use ($branch) {
            $v1 = new SocialConnection;
            $v1->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $branch->id, 'display_name' => 'Everdoors']);
            $v1->connect(['api_token' => 'a', 'refresh_token' => 'a', 'auth_secret' => 's']);
            $v1->save();
        });
        $this->getJson('/api/v1/workiz/tags')->assertJsonPath('data.rows.0.differs', false)
            ->assertJsonPath('data.rows.0.standard', ['name' => 'VIP', 'color' => '#222222']);

        $this->read([['id' => 'T1', 'name' => 'VIP', 'color' => '#ff0000']]);
        $this->getJson('/api/v1/workiz/tags')->assertJsonPath('data.rows.0.differs', true)
            ->assertJsonPath('data.changes.0.change', 'recoloured');
    }

    public function test_someone_without_workiz_access_cannot_see_or_approve(): void
    {
        $this->signedInAs($this->userWithRole($this->t, 'Technician'));

        $this->getJson('/api/v1/workiz/tags')->assertForbidden();
        $this->postJson('/api/v1/workiz/tags/approve')->assertForbidden();
    }

    /** Jobs, 11-10: the PM settings manage tag definitions; each call reaches the account, the mirror, the jobs and the standard, and the next read sees no drift. */
    public function test_create_rename_and_delete_reach_workiz_the_mirror_and_the_jobs_without_flagging_drift(): void
    {
        $this->read([['id' => 'T1', 'name' => 'CONTRACTOR RUBEN', 'color' => '#0059a0']]);
        [$mirror, $job] = $this->inTenant($this->t['org'], function () {
            app(\App\Support\Settings\SettingsResolver::class)->set('workiz.tag_baseline', $this->t['org'], [['name' => 'CONTRACTOR RUBEN', 'color' => '#0059a0']]);
            $customer = \App\Models\Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id]);
            $job = \App\Models\Job::factory()->create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id, 'tags' => ['CONTRACTOR RUBEN', 'VIP']]);

            return [WorkizJob::query()->create(['franchise_id' => $this->t['franchise']->id, 'connection_id' => $this->c->id, 'remote_id' => 'J1',
                'status' => 'Done', 'job_id' => $job->id, 'payload' => ['Tags' => ['CONTRACTOR RUBEN']], 'synced_at' => now()]), $job];
        });

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'api.workiz.com/crm/api/v2/tags/T1' => Http::response(['id' => 'T1', 'name' => 'CONTRACTOR: RUBEN', 'color' => '#0059a0'], 200),
            'api.workiz.com/crm/api/v2/tags' => Http::sequence()
                ->push(['data' => [['id' => 'T1', 'name' => 'CONTRACTOR RUBEN', 'color' => '#0059a0']]])
                ->push(['data' => [['id' => 'T1', 'name' => 'CONTRACTOR: RUBEN', 'color' => '#0059a0']]])
                ->push(['id' => 'T2', 'name' => 'READY TO SCHEDULE', 'color' => '#0059a0', 'type' => 'job'], 201),
        ]);

        $this->inTenant($this->t['org'], function () use ($mirror, $job) {
            $this->assertSame(['renamed'], array_values(\App\Support\Workiz\WorkizTags::rename('CONTRACTOR RUBEN', 'CONTRACTOR: RUBEN')));
            $this->assertSame(['CONTRACTOR: RUBEN'], $mirror->fresh()->payload['Tags']);
            $this->assertSame(['CONTRACTOR: RUBEN', 'VIP'], $job->fresh()->tags);
            $this->assertSame(['created'], array_values(\App\Support\Workiz\WorkizTags::create('READY TO SCHEDULE', '#0059A0')));
            $this->assertSame(['CONTRACTOR: RUBEN', 'READY TO SCHEDULE'], collect(app(\App\Support\Settings\SettingsResolver::class)->get('workiz.tag_baseline', $this->t['org']))->pluck('name')->sort()->values()->all());
        });
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['name'] === 'CONTRACTOR: RUBEN');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['color'] === '#0059a0');

        // The next read finds Workiz as EzBizi left it: no drift.
        $this->read([['id' => 'T1', 'name' => 'CONTRACTOR: RUBEN', 'color' => '#0059a0'], ['id' => 'T2', 'name' => 'READY TO SCHEDULE', 'color' => '#0059a0']]);
        $this->assertSame(0, $this->inTenant($this->t['org'], fn () => WorkizTagChange::query()->count()));

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'api.workiz.com/crm/api/v2/tags/T1' => Http::response(null, 204),
            'api.workiz.com/crm/api/v2/tags' => Http::response(['data' => [['id' => 'T1', 'name' => 'CONTRACTOR: RUBEN', 'color' => '#0059a0']]]),
        ]);
        $this->inTenant($this->t['org'], function () use ($mirror, $job) {
            $this->assertSame(['deleted'], array_values(\App\Support\Workiz\WorkizTags::delete('contractor: ruben')));
            $this->assertSame([], $mirror->fresh()->payload['Tags']);
            $this->assertSame(['VIP'], $job->fresh()->tags);
            $this->assertNull(WorkizRecord::query()->where('remote_id', 'T1')->first());
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->inTenant($this->t['org'], fn () => \App\Support\Workiz\WorkizTags::create('X', '#123456'));
    }
}
