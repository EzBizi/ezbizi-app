<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\ProjectBoardController;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\Notification;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\ServiceArea;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\ProjectBoardGlance;
use App\Support\Jobs\ScheduleTexts;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        $data = app(ProjectBoardGlance::class)
            ->resolve($alpha['admin'], DashboardPeriod::for($alpha['admin']), [])->data;
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
        // The totals strip needs Financials too (Jose, 2026-11-10).
        $this->assertNull($this->signedInAs($this->userWithRole($alpha, 'Viewer 2', ['jobs.view', 'project_board.view']))
            ->getJson('/api/v1/project-board/jobs?tab=in_progress')->json('meta.totals'));
        // Desk, 2026-11-12: paid beyond a job's total is shown, so paid - over + due = total.
        $this->inTenant($alpha['org'], function () use ($alpha, $id) {
            Job::query()->whereKey($id)->update(['amount_minor' => 10000]);
            $other = $this->job($alpha['franchise'], 'J-2', 'on site');
            Job::query()->whereKey($other)->update(['amount_minor' => 5000]);
            foreach ([[$id, 30.00], [$other, 80.00]] as $i => [$job, $amount]) {
                WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'payment', 'remote_id' => "PAY-{$i}",
                    'job_id' => $job, 'amount' => $amount, 'status' => 'succeeded', 'starts_at' => now(), 'payload' => [], 'synced_at' => now()]);
            }
        });
        $this->assertSame(['paid' => 11000, 'due' => 7000, 'total' => 15000, 'over' => 3000, 'currency' => 'USD'], $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/project-board/jobs?tab=in_progress&per_page=1')->json('meta.totals'));

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
            // Submitted AND scheduled, not OR (Jose, 2026-11-12): status Scheduled is not on it.
            'status_scheduled' => $this->job($alpha['franchise'], 'J-7', 'Scheduled', '2026-11-10 09:00:00', ['DEPOSIT RECEIVED']),
        ]);

        $page = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?tab=unscheduled')->assertOk();
        $this->assertEqualsCanonicalizing([$ids['hit'], $ids['cased']], array_column($page->json('data'), 'id'));
        // Every other sub-tab starts empty, and the counts follow the rules.
        // The dated deposit job is on Scheduled (Jose, 2026-11-10).
        $this->assertSame(['unscheduled' => 2, 'ready_to_schedule' => 0, 'scheduled' => 1, 'in_progress' => 0, 'done_waiting_payment' => 0, 'complete' => 0], $page->json('meta.counts'));

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
            $this->assertTrue(ProjectBoardController::isRepairTeam($tag), $tag);
        }
        $this->assertFalse(ProjectBoardController::isRepairTeam('DEPOSIT RECEIVED'));
        $this->assertSame('Jonathon Chance', $row['technician']);
        // Search finds a job by its technician (Jose, 2026-11-10).
        $this->assertSame([$id], array_column($api->getJson('/api/v1/project-board/jobs?tab=unscheduled&q=jonathon')->json('data'), 'id'));
        $this->assertSame([], $api->getJson('/api/v1/project-board/jobs?tab=unscheduled&q=nobody-here')->json('data'));
        $this->assertSame(now()->subDays(5)->format('Y-m-d').' 11:48', $row['payment_date']);
        $this->assertContains($row['status_days'], [4, 5]);
        $this->assertSame(1, $row['attachments']);
        $this->assertSame(['Service Area', 'Repair Team / Contractor', 'a note'], $row['ready_missing']);

        // Not ready: refused, and nothing tagged.
        // The four questions first (Jose, 2026-11-10: date, time slot, and an alternative of each).
        $times = ['date' => '2026-11-20', 'slot' => '8 - 11 AM', 'alt_date' => '2026-11-21', 'alt_slot' => '11 AM - 2 PM'];
        $api->postJson("/api/v1/project-board/jobs/{$id}/ready", ['date' => '2026-11-20'])->assertStatus(422);
        $api->postJson("/api/v1/project-board/jobs/{$id}/ready", $times)->assertStatus(422)->assertJsonPath('message', 'Still needs: Service Area, Repair Team / Contractor, a note.');

        $this->inTenant($alpha['org'], function () use ($alpha, $id) {
            $area = ServiceArea::query()->create(['franchise_id' => $alpha['franchise']->id, 'name' => 'Columbus North']);
            Job::query()->whereKey($id)->update(['service_area_id' => $area->id]);
            JobNote::create(['job_id' => $id, 'body' => 'Customer wants mornings.']);
            DB::table('attachments')->insert(['id' => (string) Str::uuid(),
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

        // A different pick replaces it; Unassign takes it off, and Ready needs it again (Jose, 2026-11-10).
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => 'CONTRACTOR: OMER'])->assertOk()
            ->assertJsonPath('data.tags', ['DEPOSIT RECEIVED', 'CONTRACTOR: OMER']);
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => null])->assertOk()
            ->assertJsonPath('data.tags', ['DEPOSIT RECEIVED']);
        $this->assertSame(['Repair Team / Contractor'], $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('data.0.ready_missing'));
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => 'KENNI REPAIR TEAM'])->assertOk();

        $api->postJson("/api/v1/project-board/jobs/{$id}/ready", $times)->assertOk()
            ->assertJsonPath('data.tags', ['DEPOSIT RECEIVED', 'KENNI REPAIR TEAM', 'READY TO SCHEDULE']);
        // Out of Unscheduled, into Ready to Schedule; still Submitted.
        $page = $api->getJson('/api/v1/project-board/jobs?tab=ready_to_schedule')->assertOk();
        $this->assertSame([$id], array_column($page->json('data'), 'id'));
        $this->assertSame(0, $page->json('meta.counts.unscheduled'));
        $this->assertSame('Submitted', $page->json('data.0.status'));
        $this->assertSame($times, $page->json('data.0.schedule_proposal'));
        $api->patchJson("/api/v1/project-board/jobs/{$id}/proposal", ['slot' => 'x'] + $times)->assertOk();
    }

    public function test_another_companys_tags_never_reach_this_companys_jobs_or_view(): void
    {
        // Jose, 2026-11-10: "why are everdoors stuff showing up when im on a-1chimney view".
        $alpha = $this->provisionTenant('Alpha');
        [$a1, $door] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $other = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Everdoors']);
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
            $sub = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'AngiSweep', 'parent_company_id' => $alpha['company']->id]);
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

    public function test_sort_and_search_cover_the_whole_tab_not_the_loaded_page(): void
    {
        // Jose, 2026-11-10: "this should take into account all the list, not just the loaded ones".
        $alpha = $this->provisionTenant('Alpha');
        $ids = $this->inTenant($alpha['org'], function () use ($alpha) {
            $ids = [];
            foreach (range(1, 7) as $n) {
                $ids[$n] = $this->job($alpha['franchise'], "J-{$n}", 'Submitted', null, ['DEPOSIT RECEIVED']);
                // J-1 is the oldest: newest-first puts it on the last page.
                Job::query()->whereKey($ids[$n])->update(['created_at' => now()->subDays(20 - $n)]);
            }
            Customer::query()->where('number', 'C-J-1')->update(['name' => 'Zelda Quarry']);

            return $ids;
        });
        $api = $this->signedInAs($alpha['admin']);
        $first = fn (string $query) => array_column($api->getJson("/api/v1/project-board/jobs?tab=unscheduled&per_page=3{$query}")
            ->assertOk()->json('data'), 'id');

        $this->assertNotContains($ids[1], $first(''), 'J-1 is past page 1 unsorted');
        // Status Time, most days first: the oldest job tops page 1.
        $this->assertSame($ids[1], $first('&sort=status_days&dir=desc')[0]);
        $this->assertSame($ids[7], $first('&sort=status_days&dir=asc')[0]);
        $this->assertSame($ids[1], $first('&sort=customer&dir=desc')[0]);
        // Load more continues the same order, with no row twice.
        $page2 = array_column($api->getJson('/api/v1/project-board/jobs?tab=unscheduled&per_page=3&page=2&sort=status_days&dir=desc')->json('data'), 'id');
        $this->assertSame([$ids[4], $ids[5], $ids[6]], $page2);
        // Search reaches a row beyond page 1.
        $this->assertSame([$ids[1]], $first('&q=Zelda'));
        $api->getJson('/api/v1/project-board/jobs?sort=nonsense')->assertStatus(422);
        $this->assertContains('status_days', $api->getJson('/api/v1/project-board/jobs')->json('meta.sortable'));
    }

    public function test_one_repair_team_per_job_and_the_settings_that_steer_it(): void
    {
        // Jose, 2026-11-10: "it should only be one type of repair team" and
        // "the settings need to be updated to manage all of these new columns".
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Submitted', null,
            ['DEPOSIT RECEIVED', 'CONTRACTOR', 'CONTRACTOR: ITAMAR']));
        $api = $this->signedInAs($alpha['admin']);

        $row = $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->assertOk()
            ->assertJsonPath('meta.status_days', ['warn' => 3, 'bad' => 7])->json('data.0');
        $this->assertSame(['DEPOSIT RECEIVED'], $row['tags'], 'the repair tag has its own column');
        $this->assertSame('CONTRACTOR', $row['repair_team']);

        // A pick leaves exactly one.
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => 'CONTRACTOR: ITAMAR'])->assertOk()
            ->assertJsonPath('data.tags', ['DEPOSIT RECEIVED', 'CONTRACTOR: ITAMAR']);

        // The company's own list replaces the name rule; Ready needs only what is ticked.
        $this->inTenant($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('project_board.repair_tags', $alpha['org'], ['KENNI CREW']);
            app(SettingsResolver::class)->set('project_board.ready_requires', $alpha['org'], ['technician']);
            app(SettingsResolver::class)->set('project_board.status_days', $alpha['org'], ['warn' => 2, 'bad' => 5]);
        });
        $page = $api->getJson('/api/v1/project-board/jobs?tab=unscheduled');
        $this->assertEquals(['warn' => 2, 'bad' => 5], $page->json('meta.status_days'));
        $row = $page->json('data.0');
        $this->assertNull($row['repair_team'], 'CONTRACTOR: ITAMAR is no longer a repair tag');
        $this->assertSame(['Technician'], $row['ready_missing']);
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => 'CONTRACTOR: OMER'])->assertStatus(422);
        $api->patchJson("/api/v1/project-board/jobs/{$id}/repair-team", ['repair_team' => 'KENNI CREW'])->assertOk();
    }

    public function test_a_jobs_parts_need_to_order_or_in_stock(): void
    {
        // Jose, 2026-11-10: "Part Status @ Need to Order or @ In Stock".
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']));
        $api = $this->signedInAs($alpha['admin']);
        $url = "/api/v1/project-board/jobs/{$id}/parts";

        $api->putJson($url, ['parts' => [['kind' => 'order', 'item' => 'Cap', 'quantity' => 0]]])->assertStatus(422);
        $api->putJson($url, ['parts' => [
            ['kind' => 'order', 'item' => 'Stainless cap 13x13', 'quantity' => 2, 'price_minor' => 8900, 'delivery_on' => '2026-11-14'],
            ['kind' => 'stock', 'item' => 'Mortar', 'quantity' => 1, 'price_minor' => 1200, 'delivery_on' => '2026-11-30'],
        ]])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.delivery_on', null);

        $parts = $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('data.0.parts');
        $this->assertSame(['order', 'stock'], array_column($parts, 'kind'));
        $this->assertSame('2026-11-14', $parts[0]['delivery_on']);

        $api->putJson($url, ['parts' => []])->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Jose, 2026-11-17, the parts selector: in stock, ordered from a supplier
     * or a same-day pickup store, where it goes, and red three days after it
     * was ordered and still not received.
     */
    public function test_a_part_names_its_supplier_and_turns_late_three_days_after_it_was_ordered(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']));
        $this->inTenant($alpha['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)->set('project_board.suppliers', $alpha['org'], [
            ['id' => 's-1', 'name' => 'Chimney Supply Co', 'address' => '12 Brick Rd, Austin', 'phone' => '555-0100', 'kind' => 'order'],
            ['id' => 's-2', 'name' => 'Home Depot Round Rock', 'kind' => 'pickup'],
            ['name' => 'No id, dropped'],
        ]));
        $api = $this->signedInAs($alpha['admin']);
        $url = "/api/v1/project-board/jobs/{$id}/parts";

        $this->assertSame(['s-1', 's-2'], array_column(
            $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('meta.suppliers'), 'id'));

        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(9, 0));
        $parts = $api->putJson($url, ['parts' => [
            ['kind' => 'order', 'item' => 'Cap', 'quantity' => 1, 'supplier_id' => 's-1', 'destination' => 'supplier'],
            ['kind' => 'pickup', 'item' => 'Mortar', 'quantity' => 2, 'supplier_id' => 's-2', 'destination' => 'customer'],
            ['kind' => 'stock', 'item' => 'Flashing', 'quantity' => 1, 'supplier_id' => 's-1', 'destination' => 'warehouse'],
        ]])->assertOk()->json('data');
        $this->assertSame('12 Brick Rd, Austin', $parts[0]['supplier']['address']);
        $this->assertSame('supplier', $parts[0]['destination']);
        $this->assertNull($parts[2]['supplier'], 'In stock comes from no supplier.');
        $this->assertNull($parts[2]['ordered_at']);
        $this->assertFalse($parts[0]['late']);

        // Saved again two days on (the whole list, by id): the clock keeps
        // its start, and the received one stops it.
        $this->travel(2)->days();
        $again = $api->putJson($url, ['parts' => [
            ['id' => $parts[0]['id'], 'kind' => 'order', 'item' => 'Cap', 'quantity' => 1, 'supplier_id' => 's-1', 'destination' => 'supplier'],
            ['id' => $parts[1]['id'], 'kind' => 'pickup', 'item' => 'Mortar', 'quantity' => 2, 'supplier_id' => 's-2', 'received' => true],
        ]])->assertOk()->json('data');
        $this->assertSame($parts[0]['ordered_at'], $again[0]['ordered_at']);
        $this->assertNotNull($again[1]['received_at']);

        $this->travel(1)->days();
        $late = $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('data.0.parts');
        $this->assertTrue($late[0]['late'], 'Three days ordered and not received.');
        $this->assertFalse($late[1]['late'], 'Received: never late.');
    }

    /** Jose, 2026-11-17: a job's work order for the crew, as a PDF. */
    public function test_the_work_order_is_a_pdf_with_the_crews_facts_and_the_payment_rule(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $id = $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']));
        $api = $this->signedInAs($alpha['admin']);
        $api->putJson("/api/v1/project-board/jobs/{$id}/parts", ['parts' => [
            ['kind' => 'stock', 'item' => 'Flashing', 'quantity' => 2],
        ]])->assertOk();

        $response = $api->get("/api/v1/project-board/jobs/{$id}/work-order")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertContains('work_order', array_column($api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('meta.columns'), 'key'));

        // Another organization's job is not there at all.
        $this->signedInAs($beta['admin'])->get("/api/v1/project-board/jobs/{$id}/work-order")->assertNotFound();
    }

    /** The job's Workiz photos print on the work order, fetched from Workiz's file host only. */
    public function test_a_workiz_photo_is_fetched_from_workiz_only_and_shrunk(): void
    {
        $img = imagecreatetruecolor(2400, 1600);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        \Illuminate\Support\Facades\Http::fake([
            'st.sendajob.com/*' => \Illuminate\Support\Facades\Http::response($png, 200, ['Content-Type' => 'image/png']),
            '*' => \Illuminate\Support\Facades\Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $data = \App\Support\ProjectBoard\WorkOrderPdf::fetchPhoto('https://st.sendajob.com/a/b.jpg');
        $this->assertNotNull($data);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $data);
        $this->assertSame(1200, imagesx(imagecreatefromstring(base64_decode(substr($data, 23)))));

        $this->assertNull(\App\Support\ProjectBoard\WorkOrderPdf::fetchPhoto('https://evil.example/b.jpg'), 'Not Workiz: never fetched.');
        $this->assertNull(\App\Support\ProjectBoard\WorkOrderPdf::fetchPhoto('http://st.sendajob.com/b.jpg'), 'https only.');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_the_work_order_page_names_where_each_part_is_picked_up(): void
    {
        $html = \App\Support\ProjectBoard\WorkOrderPdf::html([
            'company' => 'A-1 Chimney', 'number' => 'J-1', 'workiz' => '7001', 'client' => 'Lena Park',
            'address' => '1 Main St, Austin', 'phone' => '512-555-0100', 'phone_ext' => '12',
            'technician' => 'Guy', 'repair_team' => 'KENNI REPAIR TEAM',
            'parts' => [
                ['item' => 'Cap', 'quantity' => 1, 'kind' => 'order', 'destination' => 'warehouse'],
                ['item' => 'Liner', 'quantity' => 1, 'kind' => 'order', 'destination' => 'supplier',
                    'supplier' => ['name' => 'Chimney Supply Co', 'address' => '12 Brick Rd']],
            ],
            'photos' => [], 'files' => ['roof.heic'], 'inspections' => ['Level 2 11/14/2026'],
            'payment_date' => '11/02/2026 11:48 AM', 'money' => null,
        ]);

        $this->assertStringContainsString('512-555-0100 ext. 12', $html);
        $this->assertStringContainsString('Warehouse pickup', $html);
        $this->assertStringContainsString('Pick up at Chimney Supply Co, 12 Brick Rd', $html);
        $this->assertStringContainsString('Inspection report: Level 2 11/14/2026', $html);
        $this->assertStringContainsString(htmlspecialchars(\App\Support\ProjectBoard\WorkOrderPdf::PAYMENT_RULE, ENT_QUOTES), $html);
        $this->assertStringNotContainsString('Remaining balance', $html, 'No Financials, no amounts.');
    }

    /**
     * Jose, 2026-11-17: the Dashboard counts what waits for parts, a part 3
     * days late notifies once, a reminder is sent when due, and a muted kind
     * is not sent.
     */
    public function test_the_dashboard_counts_and_late_parts_and_reminders_notify_once(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']));
        $pm = $this->userWithRole($alpha, 'Project Manager', ['project_board.view', 'jobs.view', 'jobs.edit']);
        $muted = $this->userWithRole($alpha, 'Muted PM', ['project_board.view', 'jobs.view']);
        $this->inTenant($alpha['org'], fn () => $muted->forceFill(['preferences' => ['notifications.muted_kinds' => ['project_board.part_late']]])->save());
        $api = $this->signedInAs($pm);

        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
        $parts = $api->putJson("/api/v1/project-board/jobs/{$id}/parts", ['parts' => [
            ['kind' => 'order', 'item' => 'Cap', 'quantity' => 1],
            ['kind' => 'pickup', 'item' => 'Mortar', 'quantity' => 1, 'remind_at' => '2026-11-11T15:00:00Z', 'remind_note' => 'Call the store'],
            ['kind' => 'stock', 'item' => 'Flashing', 'quantity' => 1],
        ]])->assertOk()->json('data');

        $board = $api->getJson('/api/v1/project-board/dashboard')->assertOk();
        $board->assertJsonPath('data.waiting_items', 2)->assertJsonPath('data.waiting_jobs', 1)
            ->assertJsonCount(0, 'data.late')->assertJsonPath('data.reminders.0.remind_note', 'Call the store');

        $alerts = fn (string $who) => \App\Models\Notification::query()->withoutGlobalScopes()->where('user_id', $who)->count();
        $this->travelTo(now()->setDate(2026, 11, 13)->setTime(10, 0));
        $this->artisan('ezbizi:project-board-part-alerts')->assertSuccessful();
        $this->assertSame(3, $alerts($pm->id), 'Two late parts and one reminder due.');
        $this->assertSame(1, $alerts($muted->id), 'Late is muted: only the reminder.');
        $this->artisan('ezbizi:project-board-part-alerts')->assertSuccessful();
        $this->assertSame(3, $alerts($pm->id), 'Once, not every quarter hour.');
        $api->getJson('/api/v1/project-board/dashboard')->assertJsonCount(2, 'data.late');

        // Rescheduled from the Dashboard: sent again when it comes due.
        $api->patchJson("/api/v1/project-board/parts/{$parts[1]['id']}/reminder", ['remind_at' => '2026-11-14T09:00:00Z'])->assertOk();
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(10, 0));
        $this->artisan('ezbizi:project-board-part-alerts')->assertSuccessful();
        $this->assertSame(4, $alerts($pm->id));

        // A save of the list keeps "told it was late".
        $api->putJson("/api/v1/project-board/jobs/{$id}/parts", ['parts' => [
            ['id' => $parts[0]['id'], 'kind' => 'order', 'item' => 'Cap', 'quantity' => 1],
        ]])->assertOk();
        $this->artisan('ezbizi:project-board-part-alerts')->assertSuccessful();
        $this->assertSame(4, $alerts($pm->id));
    }

    /**
     * Jose, 2026-11-17: "Inbox separated like a Mini Leads inside Project
     * Management that only shows these messages per client". A board job's
     * client texts are one thread; a client of no board job is not here.
     */
    public function test_the_inbox_threads_a_board_jobs_client_texts_and_no_one_elses(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$board, $other] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $board = $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']);
            $other = $this->job($alpha['franchise'], 'J-2', 'Submitted');
            $connection = \App\Models\PhoneConnection::factory()->create(['scope_type' => \App\Models\PhoneConnection::SCOPE_COMPANY, 'scope_id' => $alpha['franchise']->company_id]);
            $line = \App\Models\PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => \App\Models\PhoneNumber::SCOPE_FRANCHISE,
                'scope_id' => $alpha['franchise']->id, 'e164' => '+13055550199']);
            foreach ([[$board, '+15125550101', 'outbound', 'Can we come Tuesday 8-11?'], [$board, '+15125550101', 'inbound', 'NO'], [$other, '+15125550102', 'inbound', 'Hello?']] as [$jobId, $phone, $dir, $body]) {
                $job = Job::query()->find($jobId);
                $job->customer->forceFill(['phone' => $phone])->save();
                \App\Models\PhoneMessage::query()->create(['connection_id' => $connection->id, 'number_id' => $line->id,
                    'twilio_sid' => 'SM'.bin2hex(random_bytes(16)), 'direction' => $dir, 'customer_id' => $job->customer_id,
                    'from_e164' => $dir === 'inbound' ? $phone : $line->e164, 'to_e164' => $dir === 'inbound' ? $line->e164 : $phone, 'body' => $body]);
                $this->travel(1)->minutes();
            }

            return [$board, $other];
        });
        $api = $this->signedInAs($alpha['admin']);

        $threads = $api->getJson('/api/v1/project-board/inbox')->assertOk()->json('data');
        $this->assertSame([$board], array_column($threads, 'job_id'), 'Only the board job; J-2 is not on the board.');
        $this->assertSame('NO', $threads[0]['last_body']);
        $this->assertTrue($threads[0]['waiting'], 'The client spoke last.');

        $api->getJson("/api/v1/project-board/inbox/{$board}")->assertOk()
            ->assertJsonPath('data.0.body', 'Can we come Tuesday 8-11?')->assertJsonPath('data.1.direction', 'inbound');

        $viewer = $this->userWithRole($alpha, 'Viewer', ['project_board.view', 'jobs.view']);
        $this->signedInAs($viewer)->postJson("/api/v1/project-board/inbox/{$board}", ['body' => 'Hi'])->assertForbidden();
    }

    /** Jose, 2026-11-17: "Just let me drag them" - one column order for everyone on the board. */
    public function test_a_dragged_column_order_is_the_boards_for_everyone(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Viewer', ['project_board.view', 'jobs.view']);
        $api = $this->signedInAs($alpha['admin']);

        $api->putJson('/api/v1/project-board/column-order', ['order' => ['pending', 'paid', 'total', 'nonsense']])->assertOk();

        $keys = array_column($this->signedInAs($viewer)->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('meta.columns'), 'key');
        $this->assertSame(['pending', 'paid', 'total'], array_slice($keys, 0, 3));
        $this->assertContains('notes', $keys, 'A column not named keeps its place after the rest.');
        $this->assertNotContains('nonsense', $keys);

        $this->signedInAs($viewer)->putJson('/api/v1/project-board/column-order', ['order' => ['notes']])->assertForbidden();
    }

    /** Trey, 2026-11-11: search a job by the client's phone in any format, or its address. */
    public function test_jobs_and_project_management_search_by_phone_and_address(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->inTenant($alpha['org'], function () use ($alpha) {
            $id = $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED']);
            Job::query()->findOrFail($id)->customer->update(['phone' => '+15553334444', 'address_line1' => '12 Elm Street']);
            $this->job($alpha['franchise'], 'J-2', 'Submitted', null, ['DEPOSIT RECEIVED']);

            return $id;
        });
        $api = $this->signedInAs($alpha['admin']);
        foreach (['(555) 333-4444', '555.333.4444', '+1 555 333 4444', '3334444', 'Elm Street'] as $term) {
            $this->assertSame([$id], array_column($api->getJson('/api/v1/jobs?search='.urlencode($term))->json('data'), 'id'), $term);
            $this->assertSame([$id], array_column($api->getJson('/api/v1/project-board/jobs?tab=unscheduled&q='.urlencode($term))->json('data'), 'id'), $term);
        }
        $this->assertCount(2, $api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('data'));
    }

    /** Jose, 2026-11-10: the repair team list is managed here, each with a phone or user. */
    public function test_the_repair_team_list_is_added_renamed_and_deleted_here(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['DEPOSIT RECEIVED', 'KENNI REPAIR TEAM']));
        $api = $this->signedInAs($alpha['admin']);
        $post = fn (array $body) => $api->postJson('/api/v1/project-board/repair-teams', $body);

        $post(['op' => 'create', 'name' => 'OMER', 'color' => '#123456'])->assertStatus(422);
        // The first change keeps the tags already working as repair teams.
        $post(['op' => 'create', 'name' => 'CONTRACTOR: OMER', 'color' => '#0059a0', 'phone' => '+15550001111'])->assertOk()
            ->assertJsonPath('data.list', [
                ['name' => 'CONTRACTOR: OMER', 'phone' => '+15550001111', 'user_id' => null],
                ['name' => 'KENNI REPAIR TEAM', 'phone' => null, 'user_id' => null],
            ]);
        $post(['op' => 'update', 'name' => 'contractor: omer', 'to' => 'OMER CONTRACTOR', 'user_id' => $alpha['admin']->id])->assertOk()
            ->assertJsonPath('data.list.1', ['name' => 'OMER CONTRACTOR', 'phone' => null, 'user_id' => $alpha['admin']->id]);
        $post(['op' => 'delete', 'name' => 'KENNI REPAIR TEAM'])->assertOk()->assertJsonCount(1, 'data.list');
        // Only the list counts now: the job's KENNI tag is no longer its repair team.
        $this->assertNull($api->getJson('/api/v1/project-board/jobs?tab=unscheduled')->json('data.0.repair_team'));
    }

    /**
     * Jose, 2026-11-16: "it should already show the exisiting repair team -
     * contractors". Before any change the card lists the repair tags on the
     * Workiz tag lists and on jobs, never an ordinary tag or another
     * tenant's, and reading it saves nothing and calls no Workiz account.
     */
    public function test_the_settings_card_lists_the_teams_already_there_without_saving_or_calling_workiz(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $this->workizV2($alpha);
        $this->inTenant($alpha['org'], function () use ($alpha) {
            foreach (['CONTRACTOR: MIGUEL' => '#0059A0', 'REPAIR TEAM: ISRAEL' => '#BE2C2C', 'PAID - ESTIMATED' => '#37D34D'] as $name => $colour) {
                WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'tag', 'remote_id' => 'TAG-'.Str::random(6),
                    'payload' => ['name' => $name, 'color' => $colour], 'synced_at' => now()]);
            }
            $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['PAID - ESTIMATED', 'REPAIR TEAM: KENNI', 'repair team: israel']);
        });
        $this->inTenant($beta['org'], fn () => $this->job($beta['franchise'], 'J-9', 'Submitted', null, ['CONTRACTOR: SOMEONE ELSE']));
        Http::fake();

        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/repair-teams')->assertOk()
            ->assertJsonPath('data.list', [
                ['name' => 'CONTRACTOR: MIGUEL', 'phone' => null, 'user_id' => null],
                ['name' => 'REPAIR TEAM: ISRAEL', 'phone' => null, 'user_id' => null],
                ['name' => 'REPAIR TEAM: KENNI', 'phone' => null, 'user_id' => null],
            ])
            ->assertJsonPath('data.tag_colours', ['CONTRACTOR: MIGUEL' => '#0059A0', 'REPAIR TEAM: ISRAEL' => '#BE2C2C']);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('settings')->where('key', 'project_board.repair_tags')->count(), 'reading saves nothing');

        // Once the company saved its list, that list is what the card shows.
        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('project_board.repair_tags', $alpha['org'], [['name' => 'GAS CONTRACTOR']]));
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/repair-teams')->assertOk()
            ->assertJsonPath('data.list', [['name' => 'GAS CONTRACTOR', 'phone' => null, 'user_id' => null]]);
    }

    /** Rename and Delete on a team that was only found, not yet saved, go through Workiz's tag calls like Add. */
    public function test_renaming_and_deleting_an_existing_team_reach_every_workiz_account(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->workizV2($alpha);
        $this->inTenant($alpha['org'], function () use ($alpha) {
            WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'tag', 'remote_id' => 'T1',
                'payload' => ['id' => 'T1', 'name' => 'REPAIR TEAM: ISRAEL', 'color' => '#be2c2c'], 'synced_at' => now()]);
            $this->job($alpha['franchise'], 'J-1', 'Submitted', null, ['CONTRACTOR: OMER']);
        });
        $api = $this->signedInAs($alpha['admin']);
        Http::fake([
            'api.workiz.com/crm/api/v2/tags/*' => Http::response(['id' => 'T1', 'name' => 'REPAIR TEAM: ISRAEL R'], 200),
            'api.workiz.com/crm/api/v2/tags' => Http::response(['data' => [['id' => 'T1', 'name' => 'REPAIR TEAM: ISRAEL', 'color' => '#be2c2c']]]),
        ]);

        $api->postJson('/api/v1/project-board/repair-teams', ['op' => 'update', 'name' => 'REPAIR TEAM: ISRAEL', 'to' => 'REPAIR TEAM: ISRAEL R'])->assertOk()
            ->assertJsonPath('data.outcomes', ['Alpha F1' => 'renamed'])
            ->assertJsonPath('data.list', [
                ['name' => 'CONTRACTOR: OMER', 'phone' => null, 'user_id' => null],
                ['name' => 'REPAIR TEAM: ISRAEL R', 'phone' => null, 'user_id' => null],
            ]);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/tags/T1') && $r['name'] === 'REPAIR TEAM: ISRAEL R');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'api.workiz.com/crm/api/v2/tags/*' => Http::response(null, 204),
            'api.workiz.com/crm/api/v2/tags' => Http::response(['data' => [['id' => 'T1', 'name' => 'REPAIR TEAM: ISRAEL R', 'color' => '#be2c2c']]]),
        ]);
        $api->postJson('/api/v1/project-board/repair-teams', ['op' => 'delete', 'name' => 'REPAIR TEAM: ISRAEL R'])->assertOk()
            ->assertJsonPath('data.outcomes', ['Alpha F1' => 'deleted'])
            ->assertJsonPath('data.list', [['name' => 'CONTRACTOR: OMER', 'phone' => null, 'user_id' => null]]);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/tags/T1'));
    }

    /** A Workiz v2 account on the tenant's franchise, so tag changes fan out to it. */
    private function workizV2(array $tenant): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $tenant['franchise']->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $tenant['franchise']->id, 'display_name' => 'Workiz']);
            $c->connect(['api_token' => 'k', 'refresh_token' => 'k', 'api_version' => 'v2']);
            $c->save();
        });
    }

    /** Jose, 2026-11-10: YES schedules and confirms; NO offers the alternative; NO again, the PM calls. */
    public function test_the_client_answers_the_scheduling_text_yes_or_no(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$no, $yes] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $connection = PhoneConnection::factory()->create(['scope_type' => 'organization', 'scope_id' => $alpha['org']->id]);
            PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $alpha['org']->id, 'e164' => '+12035551004', 'sms_outbound_allowed' => true]);
            $times = ['date' => '2026-11-20', 'slot' => '8 - 11 AM', 'alt_date' => '2026-11-21', 'alt_slot' => '11 AM - 2 PM', 'by' => $alpha['admin']->id];
            $ids = [];
            foreach (['+15553334444', '+15553335555'] as $i => $phone) {
                $ids[$i] = $this->job($alpha['franchise'], "J-{$i}", 'Submitted', null, ['DEPOSIT RECEIVED', 'READY TO SCHEDULE']);
                $job = Job::query()->findOrFail($ids[$i]);
                $job->customer->update(['phone' => $phone]);
                $job->update(['schedule_proposal' => $times]);
                $this->assertNull(app(ScheduleTexts::class)->offer($job, $alpha['admin']));
            }

            return $ids;
        });
        $texts = new class($alpha['org'], fn ($org, $f) => $this->inTenant($org, $f))
        {
            public function __construct(private $org, private $in) {}

            public function absorb(...$a): bool
            {
                return ($this->in)($this->org, fn () => app(ScheduleTexts::class)->absorb(...$a));
            }
        };
        $stage = fn (string $id) => $this->inTenant($alpha['org'], fn () => Job::query()->findOrFail($id)->schedule_proposal['stage']);

        $this->assertSame('offered', $stage($no));
        $this->assertFalse($texts->absorb('+15553334444', 'What time again?', 'SM1'), 'not an answer');
        $this->assertTrue($texts->absorb('+15553334444', 'No sorry', 'SM2'));
        $this->assertSame('alt_offered', $stage($no));
        $this->assertTrue($texts->absorb('+15553334444', 'no', 'SM3'));
        $this->assertSame('pm_call', $stage($no));
        $this->assertFalse($texts->absorb('+15553334444', 'no', 'SM4'), 'nothing pending any more');

        $this->assertTrue($texts->absorb('+15553335555', 'Yes!', 'SM5'));
        $this->inTenant($alpha['org'], function () use ($no, $yes, $alpha) {
            $job = Job::query()->findOrFail($yes);
            $this->assertSame('confirmed', $job->schedule_proposal['stage']);
            $this->assertSame('2026-11-20 08:00', $job->scheduled_for->format('Y-m-d H:i'));
            $this->assertStringContainsString('Friday, Nov 20 between 8 - 11 AM', PhoneMessage::query()->where('to_e164', '+15553335555')->latest('id')->value('body'));
            // Every text both ways is on the job, and the PM heard about the double no.
            $this->assertSame(5, JobNote::query()->where('job_id', $no)->count());
            $this->assertTrue(Notification::query()->where('user_id', $alpha['admin']->id)->where('title', 'like', '%J-0%')->exists());
        });
        // The Scheduled sub-tab: DEPOSIT RECEIVED with a date.
        $this->assertSame([$yes], array_column($this->signedInAs($alpha['admin'])->getJson('/api/v1/project-board/jobs?tab=scheduled')->json('data'), 'id'));
        $this->assertSame(['11:00', '14:00'], ScheduleTexts::window('11 AM - 2 PM'));
        $this->assertSame(['14:30', '17:00'], ScheduleTexts::window('2:30-5pm'));
    }

    /** "Booked by" on the Jobs list (Trey Brannan, 2026-11-12). */
    public function test_jobs_say_who_booked_them(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $ids = $this->inTenant($alpha['org'], function () use ($alpha) {
            $workiz = $this->job($alpha['franchise'], 'J-1', 'Submitted', null, [], ['CreatedBy' => 'Crizzel Ann']);
            $online = $this->job($alpha['franchise'], 'J-2', 'Submitted');
            Job::query()->whereKey($online)->update(['job_source' => 'Reserve with Google']);
            $phone = $this->job($alpha['franchise'], 'J-3', 'Submitted');
            Job::query()->whereKey($phone)->update(['job_source' => 'LS Ads']);

            return compact('workiz', 'online', 'phone');
        });

        $rows = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs?per_page=50')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('Crizzel Ann', $rows[$ids['workiz']]['booked_by']);
        $this->assertSame('Online booking', $rows[$ids['online']]['booked_by']);
        $this->assertNull($rows[$ids['phone']]['booked_by']);
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
