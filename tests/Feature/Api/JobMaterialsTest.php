<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What was used on a job, and what a job never learns (spec §9).
 *
 * Materials are read from `inventory_issues.job_id` rather than stored on the
 * job: the issue already happened in Inventory and copying it here would give
 * one fact two homes.
 *
 * **The absence is the point of the whole feature.** No cost, price or amount
 * reaches a job from Inventory, for ANYBODY — including somebody holding
 * `inventory.financials`, who may see costs everywhere else in the product.
 * A job's money is what the customer is charged, which is the service lines;
 * what the parts cost the business is Inventory's, and a surface carrying both
 * invites a margin nobody agreed to publish. So this is modelling, not a
 * permission strip, and the test that proves it has to be the one whose
 * subject CAN see costs — a refusal test on somebody who cannot see them
 * anywhere would pass against the wrong mechanism.
 */
class JobMaterialsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Write the positive control first, per the tab-permissions brief. */
    public function test_a_job_lists_what_inventory_issued_against_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        // 2.5 rather than 1: `qty` is decimal:3 and a whole number would hide
        // both failures worth catching here — a cast that truncates to int, and
        // JSON emitting 1.0 as `1`, which is what the first draft of this
        // assertion tripped over. A fraction cannot be produced by truncation
        // or by a default.
        $this->issueAgainst($alpha, $job, 'Stainless cap 8in', '2.500');

        $reader = $this->userWithRole($alpha, 'Tech', ['jobs.view', 'jobs.materials.view']);

        $materials = $this->signedInAs($reader)
            ->getJson("/api/v1/jobs/{$job->id}")->assertOk()->json('data.materials');

        $this->assertCount(1, $materials);
        $this->assertSame('Stainless cap 8in', $materials[0]['item']);
        $this->assertSame(2.5, $materials[0]['qty']);
        $this->assertSame('2026-09-23', $materials[0]['issued_on']);
    }

    /**
     * The §9 assertion, and its subject holds `inventory.financials` on purpose.
     *
     * If this ever goes red the fix is to stop sending the field, never to gate
     * it: a cost that reaches a job for the right person is still a cost on a
     * job.
     */
    public function test_materials_carry_no_money_even_for_somebody_who_may_see_costs(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $this->issueAgainst($alpha, $job, 'Stainless cap 8in', '1.000');

        $reader = $this->userWithRole($alpha, 'Accountant', [
            'jobs.view', 'jobs.materials.view', 'inventory.view', 'inventory.financials',
        ]);

        $materials = $this->signedInAs($reader)
            ->getJson("/api/v1/jobs/{$job->id}")->assertOk()->json('data.materials');

        // THE CONTROL, and the first version of it was broken in the way this
        // memo keeps warning about: `assertNotSame([], $materials)` passes on
        // NULL, so it passed against a payload with no `materials` key at all —
        // the whole test was green before the feature existed, proving only
        // that a missing field contains no money. assertCount forces the rows
        // to be there before the absence below means anything.
        $this->assertCount(1, $materials, 'The control: this person can read the list at all.');

        $encoded = json_encode($materials);
        foreach (['cost', 'unit_cost', 'price', 'amount_minor', 'value'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded,
                "A job must never carry `{$forbidden}` from Inventory (§9).");
        }
    }

    /**
     * The tab gate, which the 2026-08-28 plan predates.
     *
     * `GET /jobs/{job}` feeds every pane of the detail panel, so the gate
     * cannot be route middleware — that would refuse the whole job to somebody
     * who may read it. It belongs beside the `$detailed` split already inside
     * `present()`, which is the shape inbox 0051 prescribes for exactly this
     * case. A hidden pane whose payload still carries the rows is a decoration,
     * and the URL is one paste away from anybody who has seen it.
     */
    public function test_a_role_without_the_materials_tab_gets_no_materials_at_all(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $this->issueAgainst($alpha, $job, 'Stainless cap 8in', '1.000');

        $reader = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);

        $data = $this->signedInAs($reader)
            ->getJson("/api/v1/jobs/{$job->id}")->assertOk()->json('data');

        // The job itself still opens. Only the pane's rows are absent — an
        // absent key rather than an empty list, so a client cannot read "no
        // materials were used" from "you may not see them".
        $this->assertSame($job->id, $data['id']);
        $this->assertArrayNotHasKey('materials', $data);
    }

    private function aJob(array $tenant): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name'   => 'Materials Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'   => 'J-'.bin2hex(random_bytes(3)),
                'title'    => 'Reline',
                'status'   => 'scheduled',
                'currency' => 'USD',
            ]);
        });
    }

    private function issueAgainst(array $tenant, Job $job, string $itemName, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($job, $itemName, $qty) {
            $item = InventoryItem::factory()->create(['name' => $itemName]);
            $location = InventoryLocation::factory()->create();

            $issue = InventoryIssue::create([
                'location_id' => $location->id,
                'job_id'      => $job->id,
                'note'        => 'fitted on site',
                'number'      => 'IS-'.bin2hex(random_bytes(4)),
                'issued_on'   => '2026-09-23',
            ]);

            InventoryIssueLine::create([
                'issue_id' => $issue->id,
                'item_id'  => $item->id,
                'qty'      => $qty,
            ]);
        });
    }
}
