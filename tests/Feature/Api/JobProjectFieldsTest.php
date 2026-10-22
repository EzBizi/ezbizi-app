<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Project Management's facts on a job (Jose, 2026-10-22): review reason, outside company, second visit. */
class JobProjectFieldsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_job_carries_and_filters_by_its_project_facts(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $customer = $this->inTenant($alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer'])->id);
        $make = fn (string $title) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer, 'title' => $title, 'status' => 'scheduled',
        ])->assertCreated()->json('data.id');
        $ours = $make('Ours');
        $theirs = $make('Theirs');

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$theirs}", [
            'review_reason' => 'order_parts', 'external_company' => 'Acme Masonry', 'needs_second_visit' => true,
        ])->assertOk()
            ->assertJsonPath('data.review_reason', 'order_parts')
            ->assertJsonPath('data.external_company', 'Acme Masonry')
            ->assertJsonPath('data.needs_second_visit', true);

        // A reason outside the organization's list is refused.
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$ours}", ['review_reason' => 'made_up'])
            ->assertUnprocessable()->assertJsonValidationErrors('review_reason');

        $titles = fn (string $q) => $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs?{$q}")->assertOk()->json('data.*.title');
        $this->assertSame(['Theirs'], $titles('external=1'));
        $this->assertSame(['Ours'], $titles('external=0'));
        $this->assertSame(['Theirs'], $titles('needs_second_visit=1'));
        $this->assertSame(['Theirs'], $titles('review_reason[]=order_parts'));
        $this->assertSame([], $titles('review_reason[]=measurements'));
    }
}
