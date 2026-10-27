<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** One booking from the shared Book panel (Jose, 2026-10-27). */
class JobBookingStoreTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_new_customer_and_a_full_job_without_workiz(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $f = $alpha['franchise'];
        $area = $this->inTenant($alpha['org'], fn () => ServiceArea::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'name' => 'North']));

        $res = $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs/book', [
            'franchise_id' => $f->id,
            'customer' => ['name' => 'Ann Client', 'company' => 'Acme', 'phone' => '+13055550123', 'phone_ext' => '12', 'more_phones' => ['+13055550999']],
            'location' => ['address_line1' => '1 Main St', 'city' => 'Columbus', 'state' => 'oh', 'postal_code' => '43004'],
            'service_area_id' => $area->id,
            'title' => 'Chimney Sweep', 'job_type' => 'Chimney Sweep', 'job_source' => 'Yelp', 'description' => '<p>Two flues</p>',
            'external_company' => 'Acme Masonry', 'custom_fields' => ['Real Wood Y/N' => 'Y', 'Empty' => ''],
            'scheduled' => true, 'start' => '2026-11-03 08:00', 'end' => '2026-11-03 11:00',
            'recurrence' => ['every' => 1, 'unit' => 'year'],
        ])->assertCreated()->json('data');

        $this->assertSame('no_workiz', $res['workiz']['status'], 'a branch without Workiz keeps an EzBizi-only job');
        $this->assertContains('custom_fields', $res['ezbizi_only']);

        $this->inTenant($alpha['org'], function () use ($res, $area) {
            $job = Job::query()->findOrFail($res['job']['id']);
            $this->assertSame(['2026-11-03 08:00:00', 180, $area->id, 'Yelp', 'Acme Masonry', ['Real Wood Y/N' => 'Y'], ['unit' => 'year', 'every' => 1]],
                [$job->getRawOriginal('scheduled_for'), $job->duration_minutes, $job->service_area_id, $job->job_source, $job->external_company, $job->custom_fields, collect($job->recurrence)->sortKeysDesc()->all()]);
            $this->assertNotNull($job->service_area_chosen_at, 'a person chose the area');
            $c = Customer::query()->findOrFail($job->customer_id);
            $this->assertSame(['Ann Client', 'OH', "Company: Acme\nPhone extension: 12\nOther phones: +13055550999"], [$c->name, $c->state, $c->notes]);
        });
    }

    public function test_unscheduled_for_an_existing_customer_and_the_area_must_be_the_branchs(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $f = $alpha['franchise'];
        [$area, $customer] = $this->inTenant($alpha['org'], fn () => [
            ServiceArea::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'name' => 'North']),
            Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'Bob']),
        ]);
        $post = fn (array $x) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs/book', $x + [
            'franchise_id' => $f->id, 'customer_id' => $customer->id, 'title' => 'Look', 'scheduled' => false]);

        $id = $post(['service_area_id' => $area->id])->assertCreated()->json('data.job.id');
        $this->assertNull($this->inTenant($alpha['org'], fn () => Job::query()->find($id)->scheduled_for));
        $post(['service_area_id' => '00000000-0000-0000-0000-000000000000'])->assertNotFound();
        $post([])->assertUnprocessable()->assertJsonValidationErrors('service_area_id');
    }

    public function test_booking_from_a_lead_converts_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $f = $alpha['franchise'];
        [$area, $lead] = $this->inTenant($alpha['org'], fn () => [
            ServiceArea::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'name' => 'North']),
            \App\Models\Lead::create(['organization_id' => $alpha['org']->id, 'company_id' => $alpha['company']->id, 'franchise_id' => $f->id,
                'source' => 'website_form', 'remote_id' => 'book-1', 'status' => 'NEW', 'received_at' => now()]),
        ]);

        $id = $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs/book', ['franchise_id' => $f->id, 'lead_id' => $lead->id,
            'customer' => ['name' => 'Ann'], 'service_area_id' => $area->id, 'title' => 'Sweep', 'scheduled' => false])->assertCreated()->json('data.job.id');

        $this->inTenant($alpha['org'], function () use ($lead, $id) {
            $this->assertSame([\App\Models\Lead::STATUS_BOOKED, $id], [$lead->fresh()->status, $lead->fresh()->job_id]);
            $this->assertSame(1, \App\Models\AutomationEvent::query()->where('event_key', 'lead.converted')->where('subject_id', $lead->id)->count());
            $this->assertSame(1, \App\Models\AuditEvent::query()->where('action', 'lead.converted')->count());
        });
    }
}
