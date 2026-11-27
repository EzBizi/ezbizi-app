<?php

namespace Tests\Feature\Hr;

use App\Models\EmploymentEvent;
use App\Support\Dashboard\Widgets\Hr\StartersAndLeavers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Joined" is the start date, not the day an account row was made: on
 * 2026-10-01 the dashboard said 68 people joined this week because 64 A1
 * Tools staff had been imported on 2026-09-29 (UI/UX found it).
 */
class StartersAndLeaversTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_imported_veteran_has_not_joined_and_a_new_starter_has(): void
    {
        $t = $this->provisionTenant('Alpha');
        $veteran = $this->userWithRole($t, 'Technician', ['customers.view']);
        $starter = $this->userWithRole($t, 'Dispatcher', ['customers.view']);

        $result = $this->inTenant($t['org'], function () use ($t, $veteran, $starter) {
            foreach ([[$veteran, '2019-04-01'], [$starter, now()->toDateString()]] as [$person, $on]) {
                EmploymentEvent::create([
                    'organization_id' => $t['org']->id, 'user_id' => $person->id,
                    'kind' => EmploymentEvent::STARTED, 'occurred_on' => $on,
                ]);
            }

            return app(StartersAndLeavers::class)->resolve($t['admin'], ['days' => 30]);
        });

        $this->assertSame(1, $result['total'], 'An account made this week counted as joining.');
        $this->assertSame([$starter->id], array_column($result['people'], 'id'));
    }

    /** An erased account is not a starter (audit 2026-11-27); a live one still is. */
    public function test_an_erased_account_is_not_counted(): void
    {
        $t = $this->provisionTenant('Alpha');
        $starter = $this->userWithRole($t, 'Dispatcher', ['customers.view']);
        $erased = $this->userWithRole($t, 'Technician', ['customers.view']);

        $result = $this->inTenant($t['org'], function () use ($t, $starter, $erased) {
            $erased->forceFill(['email' => 'removed-9@invalid', 'is_active' => false])->save();
            foreach ([$starter, $erased] as $person) {
                EmploymentEvent::create([
                    'organization_id' => $t['org']->id, 'user_id' => $person->id,
                    'kind' => EmploymentEvent::STARTED, 'occurred_on' => now()->toDateString(),
                ]);
            }

            return app(StartersAndLeavers::class)->resolve($t['admin'], ['days' => 30]);
        });

        $this->assertSame([$starter->id], array_column($result['people'], 'id'));
    }
}
