<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\WorkizJob;
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
        $this->assertSame('Crown Repair, Damper Cap x2', $row['required']);
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
        $this->inTenant($alpha['org'], fn () => $this->job($alpha['franchise'], 'J-1', 'Pending'));

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
