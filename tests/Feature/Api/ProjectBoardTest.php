<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Project Management (Jose, 2026-11-06): six sub-tabs of jobs. Invented people only. */
class ProjectBoardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_sub_tab_lists_its_statuses_with_the_columns(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $this->inTenant($beta['org'], fn () => $this->job($beta['franchise'], 'B-1', 'Pending'));
        // Workiz tag colours from the tag mirror (Jose, 2026-11-10); a tag without one is left out.
        $this->inTenant($alpha['org'], fn () => WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'tag',
            'remote_id' => 'TAG-1', 'payload' => ['name' => 'Waiting Parts', 'color' => '#fbab33'], 'synced_at' => now()]));
        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('project_board.tabs', $alpha['org'], [
            'unscheduled' => ['statuses' => ['Pending'], 'scheduled' => false],
            'ready_to_schedule' => ['statuses' => ['Submitted']],
            'scheduled' => ['statuses' => ['Pending'], 'scheduled' => true],
            'complete' => ['statuses' => ['Done']],
        ]));
        $ids = $this->inTenant($alpha['org'], fn () => [
            'unscheduled' => $this->job($alpha['franchise'], 'J-1', 'Pending'),
            'scheduled' => $this->job($alpha['franchise'], 'J-2', 'pending', '2026-11-10 09:00:00'),
            'ready_to_schedule' => $this->job($alpha['franchise'], 'J-3', 'Submitted', null, ['WAITING PARTS', 'DEPOSIT'],
                ['LineItems' => [['Name' => 'Crown Repair', 'Quantity' => 1], ['Name' => 'Damper Cap', 'Quantity' => 2]]]),
            'complete' => $this->job($alpha['franchise'], 'J-4', 'Done'),
        ]);

        foreach ($ids as $tab => $id) {
            $rows = $this->signedInAs($alpha['admin'])->getJson("/api/v1/project-board/jobs?tab={$tab}")->assertOk()->json('data');
            $this->assertSame([$id], array_column($rows, 'id'), $tab);
        }

        $row = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?tab=ready_to_schedule')
            ->assertOk()->assertJsonPath('meta.counts.unscheduled', 1)->json('data.0');
        $this->assertSame('WAITING PARTS', $row['part_status']);
        $this->assertSame(['WAITING PARTS', 'DEPOSIT'], $row['tags']);
        $this->assertSame(['WAITING PARTS' => '#FBAB33'], $row['tag_colours']);
        // Line by line (Shoval's review, 2026-11-10).
        $this->assertSame("Crown Repair\nDamper Cap x2", $row['required']);
        $this->assertSame('7003', $row['workiz_id']);
        $this->assertSame('Alpha F1', $row['franchise']);
        $this->assertArrayHasKey('total', $row);

        // The Settings pickers offer the company's real statuses and tags.
        $options = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/options')->assertOk()->json('data');
        $this->assertSame('Pending', $options['statuses'][0]);
        $this->assertEqualsCanonicalizing(['WAITING PARTS', 'DEPOSIT'], $options['tags']);

        // The dashboard glance counts what the board counts, and nothing of Beta's.
        $data = app(\App\Support\Dashboard\Glances\ProjectBoardGlance::class)
            ->resolve($alpha['admin'], \App\Support\Dashboard\DashboardPeriod::for($alpha['admin']), [])->data;
        $this->assertSame('3 open jobs.', $data['sentence']);
        $this->assertSame([1, 1, 1, 0, 0, 1], array_column($data['figures'], 'value'));
    }

    public function test_the_franchise_filter_narrows_and_refuses_one_out_of_view(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']));

        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?franchise_id='.$alpha['franchise']->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.franchises.0.id', $alpha['franchise']->id)->assertJsonPath('meta.franchises.0.company', 'Alpha Co');
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?franchise_id='.$beta['franchise']->id)->assertForbidden();
        // The filter in hierarchy order: the company, then its franchises.
        $company = $alpha['franchise']->company_id;
        $places = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs')->json('meta.places');
        $this->assertSame([['company:'.$company, 0], [$alpha['franchise']->id, 1]], array_map(fn ($p) => [$p['value'], $p['depth']], $places));
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?company_id='.$company)->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?company_id='.$beta['franchise']->company_id)->assertForbidden();
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?q=ann')->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?q=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_statuses_are_the_organizations_and_money_needs_financials(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('project_board.tabs', $alpha['org'], ['in_progress' => ['statuses' => ['On Site']]]);

            return $this->job($alpha['franchise'], 'J-1', 'on site');
        });

        $row = $this->signedInAs($this->userWithRole($alpha, 'Viewer', ['jobs.view', 'project_board.view']))
            ->getJson('/api/v1/project-board/jobs?tab=in_progress')->assertOk()->json('data.0');
        $this->assertSame($id, $row['id']);
        $this->assertArrayNotHasKey('total', $row);

        $this->signedInAs($this->userWithRole($alpha, 'Jobs only', ['jobs.view']))
            ->getJson('/api/v1/project-board/jobs')->assertForbidden();
    }

    public function test_unscheduled_by_default_is_submitted_with_deposit_and_no_date_and_tags_can_be_any(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $ids = $this->inTenant($alpha['org'], fn () => [
            'hit' => $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED', 'DONT CHANGE TECH']),
            'no_tag' => $this->job($alpha['franchise'], 'J-2', 'Submitted'),
            'dated' => $this->job($alpha['franchise'], 'J-3', 'Submitted', '2026-11-10 09:00:00', ['DEPOSIT RECEIVED']),
            'vip' => $this->job($alpha['franchise'], 'J-4', 'Done', null, ['VIP']),
            // Any case and spacing (Jose, 2026-11-10); a misspelling is a different tag.
            'cased' => $this->job($alpha['franchise'], 'J-5', 'submitted', null, ['Deposit  received']),
            'misspelt' => $this->job($alpha['franchise'], 'J-6', 'Submitted', null, ['DEPOSIT RECIEVED']),
        ]);

        $page = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?tab=unscheduled')->assertOk();
        $this->assertEqualsCanonicalizing([$ids['hit'], $ids['cased']], array_column($page->json('data'), 'id'));
        // Every other sub-tab starts empty, and the counts follow the rules.
        $this->assertSame(['unscheduled' => 2, 'ready_to_schedule' => 0, 'scheduled' => 0, 'in_progress' => 0, 'done_waiting_payment' => 0, 'complete' => 0], $page->json('meta.counts'));

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('project_board.tabs', $alpha['org'], [
            'complete' => ['statuses' => ['Done', 'Submitted'], 'tags' => ['VIP', 'DEPOSIT RECEIVED'], 'tags_mode' => 'any'],
        ]));
        $rows = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?tab=complete')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$ids['hit'], $ids['dated'], $ids['vip'], $ids['cased']], array_column($rows, 'id'));
    }

    public function test_the_unscheduled_columns_and_ready_to_schedule(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], function () use ($alpha) {
            WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'tag', 'remote_id' => 'TAG-K',
                'payload' => ['name' => 'KENNI REPAIR TEAM', 'color' => '#00C875'], 'synced_at' => now()]);
            WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'tag', 'remote_id' => 'TAG-C',
                'payload' => ['name' => 'CONTRACTOR: OMER', 'color' => '#579BFC'], 'synced_at' => now()]);
            $id = $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED'],
                ['Team' => [['id' => 'USR-1', 'Name' => 'Jonathon Chance']], 'JobNotes' => '', 'Attachments' => [],
                    // The panel's "From Workiz" file (Jose, 2026-11-10): the column counts it too.
                    'CustomFields' => [['Name' => 'Inspection Report', 'Value' => [['name' => 'report.pdf', 'url' => 'https://app.workiz.com/r.pdf']]]]]);
            WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'payment', 'remote_id' => 'PAY-1',
                'job_id' => $id, 'amount' => 2163.36, 'starts_at' => now()->subDays(5)->setTime(11, 48), 'payload' => [], 'synced_at' => now()]);

            return $id;
        });
        $api = $this->signedInAs($alpha['admin']);

        $row = $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->assertOk()
            ->json('data.0');
        // The Assign menu: the job's own account's repair-team tags, each in its colour.
        $this->assertSame([['name' => 'CONTRACTOR: OMER', 'colour' => '#579BFC'], ['name' => 'KENNI REPAIR TEAM', 'colour' => '#00C875']],
            $row['repair_options']);
        // Workiz's renames (2026-11-10) still read as repair teams; other tags do not.
        foreach (['REPAIR TEAM: KENNI', 'CONTRACTOR MIGUEL', 'GAS CONTRACTOR', 'PROGRESS BY SUB CONTRACTOR'] as $tag) {
            $this->assertTrue(\App\Http\Controllers\Api\ProjectBoardController::isRepairTeam($tag), $tag);
        }
        $this->assertFalse(\App\Http\Controllers\Api\ProjectBoardController::isRepairTeam('DEPOSIT RECEIVED'));
        $this->assertSame('Jonathon Chance', $row['technician']);
        // Search finds a job by its technician (Jose, 2026-11-10).
        $this->assertSame([$id], array_column($api->getJson('/api/v1/project-board/jobs?tab=unscheduled&q=jonathon')->json('data'), 'id'));
        $this->assertSame([], $api->getJson('/api/v1/project-board/jobs?tab=unscheduled&q=nobody-here')->json('data'));
        $this->assertSame(now()->subDays(5)->format('Y-m-d').' 11:48', $row['payment_date']);
        $this->assertContains($row['status_days'], [4, 5]);
        $this->assertSame(1, $row['attachments']);
        $this->assertSame(['Service Area', 'Repair Team / Contractor', 'a note'], $row['ready_missing']);

        // Not ready: refused, and nothing tagged.
        $api->postJson("/api/v1/project-board/jobs/{$id}/ready")->assertStatus(422)->assertJsonPath('message', 'Still needs: Service Area, Repair Team / Contractor, a note.');

        $this->inTenant($alpha['org'], function () use ($alpha, $id) {
            $area = \App\Models\ServiceArea::query()->create(['franchise_id' => $alpha['franchise']->id, 'name' => 'Columbus North']);
            Job::query()->whereKey($id)->update(['service_area_id' => $area->id]);
            \App\Models\JobNote::create(['job_id' => $id, 'body' => 'Customer wants mornings.']);
            \Illuminate\Support\Facades\DB::table('attachments')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
                'organization_id' => $alpha['org']->id, 'attachable_type' => 'jobs', 'attachable_id' => $id,
                'disk' => 'local', 'path' => 'x.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1, 'checksum' => str_repeat('0', 64),
                'created_at' => now(), 'updated_at' => now()]);
        });
        // From the Workiz tags (Jose, 2026-11-10): picking one adds the tag; anything else is refused.
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => 'VIP'])->assertStatus(422);
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => 'KENNI REPAIR TEAM'])->assertOk()
            ->assertJsonPath('data.tags', ['DEPOSIT RECEIVED', 'KENNI REPAIR TEAM']);

        $row = $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('data.0');
        $this->assertSame([], $row['ready_missing']);
        $this->assertSame('Columbus North', $row['service_area']);
        $this->assertSame('KENNI REPAIR TEAM', $row['repair_team']);
        $this->assertSame('Customer wants mornings.', $row['notes']);

        $api->postJson("/api/v1/project-board/jobs/{$id}/ready")->assertOk()
            ->assertJsonPath('data.tags', ['DEPOSIT RECEIVED', 'KENNI REPAIR TEAM', 'READY TO SCHEDULE']);
        // Out of Unscheduled, into Ready to Schedule; still Submitted.
        $page = $api->getJson('/api/v1/project-board/jobs?tab=ready_to_schedule')->assertOk();
        $this->assertSame([$id], array_column($page->json('data'), 'id'));
        $this->assertSame(0, $page->json('meta.counts.unscheduled'));
        $this->assertSame('Submitted', $page->json('data.0.status'));
    }

    public function test_another_companys_tags_never_reach_this_companys_jobs_or_view(): void
    {
        // Jose, 2026-11-10: "why are everdoors stuff showing up when im on a-1chimney view".
        $alpha = $this->provisionTenant('Alpha');
        [$a1, $door] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $other = \App\Models\Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Everdoors']);
            $doors = Franchise::create(['company_id' => $other->id, 'name' => 'Everdoors CT']);
            WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'tag', 'remote_id' => 'TAG-A',
                'payload' => ['name' => 'REPAIR TEAM: KENNI', 'color' => '#198218'], 'synced_at' => now()]);
            WorkizRecord::create(['franchise_id' => $doors->id, 'type' => 'tag', 'remote_id' => 'TAG-D',
                'payload' => ['name' => 'CONTRACTOR DOORMAN', 'color' => '#be2c2c'], 'synced_at' => now()]);
            WorkizRecord::create(['franchise_id' => $doors->id, 'type' => 'tag', 'remote_id' => 'TAG-E',
                'payload' => ['name' => 'DEPOSIT RECEIVED', 'color' => '#be2c2c'], 'synced_at' => now()]);

            return [
                $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']),
                $this->job($doors, 'D-1', 'Submitted', null, ['DEPOSIT RECEIVED', '🔴 Manual Follow-Up 🔴']),
            ];
        });
        $view = $this->signedInAs($alpha['admin'])->withHeader('X-EzBizi-View', 'company:'.$alpha['company']->id);

        $page = $view->getJson('/api/v1/project-board/jobs?tab=unscheduled')->assertOk();
        $this->assertSame([$a1], array_column($page->json('data'), 'id'));
        $this->assertSame(1, $page->json('meta.counts.unscheduled'));
        $this->assertNotContains('Everdoors CT', array_column($page->json('meta.places'), 'label'));

        // The picker: only what is inside the view, switched-off places out,
        // and a sub-company's view starts at that sub-company (Jose, 2026-11-10).
        $sub = $this->inTenant($alpha['org'], function () use ($alpha) {
            Franchise::create(['company_id' => $alpha['company']->id, 'name' => 'Closed branch', 'is_active' => false]);
            $sub = \App\Models\Company::create(['organization_id' => $alpha['org']->id, 'name' => 'AngiSweep', 'parent_company_id' => $alpha['company']->id]);
            Franchise::create(['company_id' => $sub->id, 'name' => 'Sweep NJ']);

            return $sub;
        });
        $labels = array_column($view->getJson('/api/v1/project-board/jobs')->json('meta.places'), 'label');
        $this->assertContains('AngiSweep · Sweep NJ', $labels);
        $this->assertNotContains('Alpha Co · Closed branch', $labels);
        $subLabels = array_column($this->signedInAs($alpha['admin'])->withHeader('X-EzBizi-View', 'company:'.$sub->id)
            ->getJson('/api/v1/project-board/jobs')->json('meta.places'), 'label');
        $this->assertSame(['AngiSweep', 'AngiSweep · Sweep NJ'], $subLabels);
        $row = $page->json('data.0');
        $this->assertSame(['REPAIR TEAM: KENNI'], array_column($row['repair_options'], 'name'));
        // Everdoors' colour for the same name does not paint A-1's tag.
        $this->assertSame([], (array) $row['tag_colours']);

        $options = $view->getJson('/api/v1/project-board/options')->assertOk()->json('data');
        $this->assertNotContains('🔴 Manual Follow-Up 🔴', $options['tags']);

        $this->assertSame(['REPAIR TEAM: KENNI'],
            array_column($view->getJson("/api/v1/jobs/{$a1}")->assertOk()->json('data.workiz_tags'), 'name'));
        $this->assertNotContains($door, array_column($view->getJson('/api/v1/project-board/jobs?tab=unscheduled&q=D-1')->json('data'), 'id'));
    }

    private function job(Franchise $franchise, string $number, string $status, ?string $at = null, array $tags = [], array $payload = []): string
    {
        $customer = Customer::create(['owning_franchise_id' => $franchise->id, 'number' => "C-{$number}", 'name' => 'Ann Smith']);
        $job = Job::create(['franchise_id' => $franchise->id, 'customer_id' => $customer->id, 'number' => $number, 'title' => 'A job',
            'status' => $status, 'currency' => 'USD', 'tags' => $tags, 'scheduled_for' => $at]);
        $mirror = WorkizJob::create(['franchise_id' => $franchise->id, 'remote_id' => "JOB-{$number}", 'serial_id' => '700'.preg_replace('/\D/', '', $number),
            'status' => 'x', 'payload' => $payload, 'synced_at' => now()]);
        $mirror->job_id = $job->id;
        $mirror->save();

        return $job->id;
    }
}
