<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Jobs\BookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A job EzBizi books itself, rather than a person typing it in: a member's
 * chosen visit first (Memberships, 2026-10-05). The invariants
 * JobsController::store keeps, in one place another module can call.
 */
class BookJobTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_booked_visit_is_numbered_placed_and_announced(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $f = $alpha['franchise'];

        $job = $this->inTenant($alpha['org'], function () use ($alpha, $f) {
            $area = ServiceArea::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'name' => 'North']);
            ServiceAreaZip::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'service_area_id' => $area->id, 'zip' => '10001']);
            $customer = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'Member', 'postal_code' => '10001-2345']);

            return app(BookJob::class)($customer, $f, 'Annual sweep', '2026-11-03', ['source' => 'membership']);
        });

        $this->assertNotEmpty($job->number);
        $this->assertSame('scheduled', $job->status, "The organization's first status: booked, not started.");
        $this->assertSame('2026-11-03 09:00:00', $job->getRawOriginal('scheduled_for'), "Nine on the chosen day, the franchise's wall clock.");
        $this->assertSame('USD', $job->currency);
        $this->assertNotNull($job->service_area_id, "Placed by the customer's zip.");
        $this->assertNull($job->service_area_chosen_at, 'Placed by EzBizi, not chosen by a person.');

        $this->inTenant($alpha['org'], function () use ($job) {
            $this->assertSame(['job.created', 'job.scheduled'], AutomationEvent::query()
                ->where('subject_id', $job->id)->pluck('event_key')->sort()->values()->all());
            $audit = AuditEvent::query()->where('action', 'job.created')->sole();
            $this->assertSame('membership', $audit->metadata['source']);
        });
    }

    public function test_without_a_date_it_waits_to_be_scheduled(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $job = $this->inTenant($alpha['org'], fn () => app(BookJob::class)(
            Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'Member']),
            $alpha['franchise'], 'Annual sweep', null,
        ));

        $this->assertNull($job->scheduled_for);
        $this->assertSame(['job.created'], $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('subject_id', $job->id)->pluck('event_key')->all()));
    }
}
