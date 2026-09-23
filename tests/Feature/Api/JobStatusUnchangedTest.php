<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Editing a job whose status the organization does not list.
 *
 * ── Why this exists, measured on production 2026-09-23 ───────────
 *
 * The Workiz mirror projected 550 jobs carrying Workiz's own status words —
 * `Submitted`, `Done`, `Pending`, `In progress`, `Canceled`, `done pending
 * approval` — and A-1's list is the platform default (`scheduled`,
 * `in_progress`, `completed`, `cancelled`). Not one matches; `In progress`
 * misses `in_progress` by a space and a capital.
 *
 * The detail panel always sends `status` on save, so **changing the DATE of one
 * of those jobs was refused, with a message about the status.** 550 real
 * records a person could open, read and not save, and a refusal naming a field
 * they had not touched.
 *
 * ── The rule this pins ───────────────────────────────────────────
 *
 * A status is validated when it CHANGES, not when it is merely present. An
 * unchanged value is already in the database: refusing it does not remove it,
 * it only blocks the edit somebody actually asked for. Validating a field the
 * request did not alter is validating history rather than the request.
 *
 * This is not a Workiz problem and does not depend on the import. Any status
 * that predates a change to the organization's list — somebody renaming a
 * status in Settings — strands every job holding the old word in exactly the
 * same way.
 */
class JobStatusUnchangedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_job_whose_status_is_not_listed_can_still_have_its_date_changed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJobWithStatus($alpha, 'Submitted');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", [
                // Exactly what the detail panel sends: the field being changed,
                // AND the untouched status it is already showing.
                'scheduled_for' => '2026-10-01 09:00:00',
                'status'        => 'Submitted',
            ])
            ->assertOk();

        $this->assertSame('Submitted', $job->fresh()->status,
            'The unlisted status is preserved, not rewritten to something listed.');
    }

    /**
     * The other half, and without it the fix above is a hole rather than a fix.
     *
     * Relaxing "present" to "changed" must not become "anything goes". Somebody
     * MOVING a job to a word the organization does not use is still refused —
     * that is the case `assertStatusAllowed` was written for.
     */
    public function test_moving_a_job_to_an_unlisted_status_is_still_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJobWithStatus($alpha, 'scheduled');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'Invented'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('scheduled', $job->fresh()->status);
    }

    /**
     * And a job on an unlisted status cannot be moved to another unlisted one.
     *
     * The narrow reading of "only check when it changes" would let `Submitted`
     * become `Nonsense`, because both are outside the list and the code only
     * asks whether the value differs. The check has to run on the NEW value
     * whenever it differs, which is what distinguishes this from the first
     * test rather than merely being a second example of it.
     */
    public function test_an_unlisted_status_cannot_be_traded_for_another_unlisted_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJobWithStatus($alpha, 'Submitted');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'Nonsense'])
            ->assertStatus(422);

        $this->assertSame('Submitted', $job->fresh()->status);
    }

    /**
     * A job carrying an unlisted status can still be moved ONTO the list.
     *
     * This is the path out: somebody tidying A-1's imported work should be able
     * to set `completed` on a job that arrived as `Done`. If this were refused
     * the jobs would be permanently stranded, which is the state the fix exists
     * to end rather than to entrench.
     */
    public function test_a_job_can_be_moved_from_an_unlisted_status_onto_the_list(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJobWithStatus($alpha, 'Done');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$job->id}", ['status' => 'completed'])
            ->assertOk();

        $this->assertSame('completed', $job->fresh()->status);
    }

    /** A job whose status bypasses the API the way the projection does. */
    private function aJobWithStatus(array $tenant, string $status): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $status) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name'   => 'Imported Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'   => 'J-'.bin2hex(random_bytes(3)),
                'title'    => 'Chimney sweep',
                'status'   => $status,
                'currency' => 'USD',
            ]);
        });
    }
}
