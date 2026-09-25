<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Jobs\JobServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which service area a job belongs to (Jose, raised 2026-09-25).
 *
 * A service area is a sub-division WITHIN a franchise -- Scheduling's
 * `service_areas`, defined by its zips. The rule, agreed with Scheduling and
 * resting on Jose's own 2026-08-31 ruling ("chosen beats suggested"):
 *
 *   1. The area the job was ASSIGNED -- Workiz's name -- if it is an area of
 *      the job's own franchise.
 *   2. Otherwise the area the job's ZIP falls in, if that area is in the job's
 *      own franchise.
 *   3. Otherwise none. Never another franchise's area: an area is a part of
 *      its franchise, and a job cannot be in a part of somebody else's.
 *
 * The resolver is a pure read. It keeps no log of disagreements -- a write path
 * that also keeps a diary is two jobs, and the diary is the one that rots
 * (Scheduling's rule).
 */
class JobServiceAreaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_assigned_area_name_in_the_franchise_is_used(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);

        $this->assertSame($connecticut->id, $this->resolve($alpha, $ct, 'Connecticut', null));
    }

    public function test_the_name_is_matched_ignoring_case_and_spaces(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);

        $this->assertSame($connecticut->id, $this->resolve($alpha, $ct, '  connecticut ', null));
    }

    /** Jose's ruling: the chosen area beats the one the zip suggests. */
    public function test_the_assigned_name_wins_over_a_zip_in_a_different_area(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);
        $this->area($alpha, $ct, 'Hartford', ['06101']);

        $this->assertSame($connecticut->id, $this->resolve($alpha, $ct, 'Connecticut', '06101'));
    }

    /**
     * A name that is only another franchise's area is not used, and the zip
     * decides instead. This is the Nationwide account tagging jobs "New York":
     * that area belongs to the New York franchise, so it cannot be theirs.
     */
    public function test_a_name_from_another_franchise_is_ignored_and_the_zip_decides(): void
    {
        [$alpha, $ct, $ny] = $this->world();
        $this->area($alpha, $ny, 'New York', ['10001']);
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);

        $this->assertSame($connecticut->id, $this->resolve($alpha, $ct, 'New York', '06457'));
    }

    /** Empty rather than wrong: the zip's area is another franchise's. */
    public function test_a_zip_whose_area_is_in_another_franchise_gives_no_area(): void
    {
        [$alpha, $ct, $ny] = $this->world();
        $this->area($alpha, $ny, 'New York', ['10001']);

        $this->assertNull($this->resolve($alpha, $ct, null, '10001'));
    }

    /**
     * Scheduling's fix, and the case that goes wrong SILENTLY: New England zips
     * arrive without their leading zero. Taking the first five digits of "6457"
     * gives "6457", which matches no row -- no area, and no error to say so.
     */
    public function test_a_four_digit_zip_is_padded_to_five(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);

        $this->assertSame($connecticut->id, $this->resolve($alpha, $ct, null, '6457'));
    }

    public function test_a_zip_plus_four_is_read_as_its_first_five(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);

        $this->assertSame($connecticut->id, $this->resolve($alpha, $ct, null, '06457-1234'));
    }

    public function test_nothing_to_go_on_gives_no_area(): void
    {
        [$alpha, $ct] = $this->world();
        $this->area($alpha, $ct, 'Connecticut', ['06457']);

        $this->assertNull($this->resolve($alpha, $ct, null, null));
        $this->assertNull($this->resolve($alpha, $ct, '', ''));
        // A retired name with no successor and no zip stays unplaced.
        $this->assertNull($this->resolve($alpha, $ct, 'Seattle', null));
        // Fewer than four digits is not a zip.
        $this->assertNull($this->resolve($alpha, $ct, null, '123'));
    }

    /**
     * Scheduling's hardening: the zip is resolved through its AREA's franchise,
     * not through the zip row's denormalised copy of it. A data fix that moves
     * an area to another franchise and leaves its zips' copies behind -- which
     * A1 Tools hit -- must not place a job by the stale copy.
     */
    public function test_the_zip_is_resolved_through_its_areas_franchise_not_the_rows_copy(): void
    {
        [$alpha, $ct, $ny] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', []);

        // The zip row says New York; its area is Connecticut's.
        $this->inTenant($alpha['org'], fn () => ServiceAreaZip::create([
            'organization_id' => $alpha['org']->id,
            'franchise_id'    => $ny->id,
            'service_area_id' => $connecticut->id,
            'zip'             => '06457',
        ]));

        $this->assertSame($connecticut->id, $this->resolve($alpha, $ct, null, '06457'),
            'Read through the area: it is Connecticut\'s.');
        $this->assertNull($this->resolve($alpha, $ny, null, '06457'),
            'The stale copy says New York, and it must not be believed.');
    }

    // ── The job API: shown, chosen by a person, filtered on ─────────

    public function test_a_job_shows_its_area_and_its_franchises_choices(): void
    {
        [$alpha, $ct, $ny] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);
        $this->area($alpha, $ct, 'Bridgeport', []);
        $this->area($alpha, $ny, 'New York', ['10001']);
        $job = $this->aJob($alpha, $ct, $connecticut->id);

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")
            ->assertOk()
            ->assertJsonPath('data.service_area', ['id' => $connecticut->id, 'name' => 'Connecticut'])
            ->assertJsonPath('data.allowed_service_areas.*.name', ['Bridgeport', 'Connecticut']);
    }

    public function test_a_person_can_choose_the_area_and_clear_it(): void
    {
        [$alpha, $ct] = $this->world();
        $this->area($alpha, $ct, 'Connecticut', ['06457']);
        $hartford = $this->area($alpha, $ct, 'Hartford', ['06101']);
        $job = $this->aJob($alpha, $ct);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$job}", ['service_area_id' => $hartford->id])
            ->assertOk()->assertJsonPath('data.service_area.name', 'Hartford');
        $this->assertSame($hartford->id, $this->areaOf($alpha, $job));

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$job}", ['service_area_id' => null])
            ->assertOk()->assertJsonPath('data.service_area', null);
        $this->assertNull($this->areaOf($alpha, $job));
    }

    /** The resolver's rule, applied to a person's choice, on edit and on create. */
    public function test_an_area_of_another_franchise_is_refused(): void
    {
        [$alpha, $ct, $ny] = $this->world();
        $newYork = $this->area($alpha, $ny, 'New York', ['10001']);
        $job = $this->aJob($alpha, $ct);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$job}", ['service_area_id' => $newYork->id])
            ->assertStatus(422)->assertJsonValidationErrors(['service_area_id' => 'not one of this franchise']);
        $this->assertNull($this->areaOf($alpha, $job));

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $ct->id, 'customer_id' => $this->aCustomer($alpha, $ct),
            'title' => 'Sweep', 'status' => 'scheduled', 'service_area_id' => $newYork->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['service_area_id' => 'not one of this franchise']);
    }

    /** The same sentence as a wrong franchise's, so it cannot tell a caller the id is real elsewhere. */
    public function test_another_organizations_area_gets_the_same_answer(): void
    {
        [$alpha, $ct] = $this->world();
        $beta = $this->provisionTenant('Beta');
        $theirs = $this->area($beta, $beta['franchise'], 'Connecticut', ['06457']);
        $job = $this->aJob($alpha, $ct);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$job}", ['service_area_id' => $theirs->id])
            ->assertStatus(422)->assertJsonValidationErrors(['service_area_id' => 'not one of this franchise']);
    }

    public function test_the_list_filters_by_area(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);
        $hartford = $this->area($alpha, $ct, 'Hartford', ['06101']);
        $inConnecticut = $this->aJob($alpha, $ct, $connecticut->id);
        $this->aJob($alpha, $ct, $hartford->id);
        $this->aJob($alpha, $ct);

        $rows = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs?service_area_id={$connecticut->id}")->assertOk()->json('data');

        $this->assertSame([$inConnecticut], array_column($rows, 'id'));
        $this->assertSame('Connecticut', $rows[0]['service_area']['name']);
        $this->assertCount(3, $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs')->json('data'),
            'The control: unfiltered, all three are listed.');
    }

    /**
     * DEVELOPMENT-RULES: the Workiz sync must tell "a person decided" from
     * "nobody has". A person's change marks it, clearing included; a save that
     * only carries the area along does not, because the panel sends fields
     * nobody changed, as it does the status.
     */
    public function test_a_persons_change_marks_the_area_as_chosen(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);
        $hartford = $this->area($alpha, $ct, 'Hartford', ['06101']);
        $job = $this->aJob($alpha, $ct, $connecticut->id);   // placed by the machine

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job}", ['title' => 'Renamed', 'service_area_id' => $connecticut->id])->assertOk();
        $this->assertNull($this->chosenAt($alpha, $job), 'Carried along unchanged: nobody decided.');

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$job}", ['service_area_id' => $hartford->id])->assertOk();
        $this->assertNotNull($this->chosenAt($alpha, $job));

        $other = $this->aJob($alpha, $ct, $connecticut->id);
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/jobs/{$other}", ['service_area_id' => null])->assertOk();
        $this->assertNotNull($this->chosenAt($alpha, $other), 'Clearing a wrong area is a decision too.');
    }

    public function test_a_job_created_with_an_area_was_chosen_and_one_without_was_not(): void
    {
        [$alpha, $ct] = $this->world();
        $connecticut = $this->area($alpha, $ct, 'Connecticut', ['06457']);
        $post = fn (array $extra) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', $extra + [
            'franchise_id' => $ct->id, 'customer_id' => $this->aCustomer($alpha, $ct),
            'title' => 'Sweep', 'status' => 'scheduled',
        ])->assertCreated()->json('data.id');

        $this->assertNotNull($this->chosenAt($alpha, $post(['service_area_id' => $connecticut->id])));
        $this->assertNull($this->chosenAt($alpha, $post([])));
    }

    /** @return array{0: array, 1: Franchise, 2: Franchise} */
    private function world(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $ct = $alpha['franchise'];

        $ny = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'New York',
            'is_active'       => true,
        ]));

        return [$alpha, $ct, $ny];
    }

    /** @param  list<string>  $zips */
    private function area(array $tenant, Franchise $franchise, string $name, array $zips): ServiceArea
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $franchise, $name, $zips) {
            $area = ServiceArea::create([
                'organization_id' => $tenant['org']->id,
                'franchise_id'    => $franchise->id,
                'name'            => $name,
            ]);

            foreach ($zips as $zip) {
                ServiceAreaZip::create([
                    'organization_id' => $tenant['org']->id,
                    'franchise_id'    => $franchise->id,
                    'service_area_id' => $area->id,
                    'zip'             => $zip,
                ]);
            }

            return $area;
        });
    }

    private function resolve(array $tenant, Franchise $franchise, ?string $name, ?string $zip): ?string
    {
        return $this->inTenant($tenant['org'],
            fn () => JobServiceArea::resolve($franchise, $name, $zip));
    }

    private function aCustomer(array $tenant, Franchise $franchise): string
    {
        return $this->inTenant($tenant['org'], fn () => Customer::create([
            'owning_franchise_id' => $franchise->id,
            'number'              => 'C-'.bin2hex(random_bytes(3)),
            'name'                => 'A Customer',
        ])->id);
    }

    private function aJob(array $tenant, Franchise $franchise, ?string $areaId = null): string
    {
        $customer = $this->aCustomer($tenant, $franchise);

        return $this->inTenant($tenant['org'], fn () => Job::create([
            'franchise_id'    => $franchise->id,
            'customer_id'     => $customer,
            'service_area_id' => $areaId,
            'number'          => 'J-'.bin2hex(random_bytes(3)),
            'title'           => 'A job',
            'status'          => 'scheduled',
        ])->id);
    }

    private function areaOf(array $tenant, string $jobId): ?string
    {
        return $this->inTenant($tenant['org'], fn () => Job::query()->findOrFail($jobId)->service_area_id);
    }

    private function chosenAt(array $tenant, string $jobId): mixed
    {
        return $this->inTenant($tenant['org'], fn () => Job::query()->findOrFail($jobId)->service_area_chosen_at);
    }
}
