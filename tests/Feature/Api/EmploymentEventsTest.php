<?php

namespace Tests\Feature\Api;

use App\Models\EmploymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class EmploymentEventsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_deactivating_writes_a_left_event(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Leaver', ['customers.view']);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => false])
            ->assertOk();

        $this->assertTrue(
            $this->inTenant($alpha['org'], fn () => EmploymentEvent::query()
                ->where('user_id', $person->id)
                ->where('kind', EmploymentEvent::LEFT)
                ->exists())
        );
    }

    public function test_people_inactive_before_the_table_have_no_left_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Already gone', ['customers.view']);

        $this->inTenant($alpha['org'], function () use ($person) {
            $person->forceFill(['is_active' => false])->save();
        });

        $this->assertFalse(
            $this->inTenant($alpha['org'], fn () => EmploymentEvent::query()
                ->where('user_id', $person->id)
                ->exists())
        );
    }

    public function test_a_leave_in_march_still_counts_after_a_rehire_in_april(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Pat', ['customers.view']);

        $this->travelTo('2026-03-15 12:00:00');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => false])
            ->assertOk();

        $this->travelTo('2026-04-10 12:00:00');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/hr/people/{$person->id}", ['is_active' => true])
            ->assertOk();

        $this->assertSame(1, $this->inTenant($alpha['org'], fn () => EmploymentEvent::query()
            ->where('user_id', $person->id)
            ->where('kind', EmploymentEvent::LEFT)
            ->whereDate('occurred_on', '2026-03-15')
            ->count()));

        $this->assertTrue(
            $this->inTenant($alpha['org'], fn () => EmploymentEvent::query()
                ->where('user_id', $person->id)
                ->where('kind', EmploymentEvent::STARTED)
                ->whereDate('occurred_on', '2026-04-10')
                ->exists())
        );

        $this->travelBack();
    }
}
