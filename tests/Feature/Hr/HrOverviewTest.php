<?php

namespace Tests\Feature\Hr;

use App\Models\EmploymentEvent;
use App\Models\Invitation;
use App\Models\TimeClockShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The redesigned HR Dashboard's one read (Jose approved the layouts, 2026-10-01). */
class HrOverviewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_overview_counts_by_start_date_and_hides_what_the_viewer_cannot_open(): void
    {
        // Pinned, and dated in the organization's day as the app records one:
        // a UTC date written between 00:00 and 04:00 UTC is tomorrow in New
        // York, outside a window that ends today (desk, 2026-10-05: red every
        // night at the gate).
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-15 02:30:00', 'UTC'));
        $t = $this->provisionTenant('Alpha');
        $today = $this->inTenant($t['org'], fn () => \Illuminate\Support\Carbon::now(
            (string) app(\App\Support\Settings\SettingsResolver::class)->get('timezone', $t['org']),
        )->toDateString());
        $veteran = $this->userWithRole($t, 'Technician', ['customers.view']);
        $starter = $this->userWithRole($t, 'Dispatcher', ['customers.view']);
        $leaver = $this->userWithRole($t, 'Helper', ['customers.view']);
        $reader = $this->userWithRole($t, 'HR reader', ['hr.view', 'hr.dashboard.view']);

        $this->inTenant($t['org'], function () use ($t, $veteran, $starter, $leaver, $today) {
            $event = fn ($u, $kind, $on) => EmploymentEvent::create([
                'organization_id' => $t['org']->id, 'user_id' => $u->id, 'kind' => $kind, 'occurred_on' => $on,
            ]);
            $event($veteran, EmploymentEvent::STARTED, '2019-04-01');
            $event($starter, EmploymentEvent::STARTED, $today);
            $event($leaver, EmploymentEvent::STARTED, '2024-01-01');
            $event($leaver, EmploymentEvent::LEFT, $today);
            $leaver->forceFill(['is_active' => false])->save();

            TimeClockShift::create(['organization_id' => $t['org']->id, 'user_id' => $starter->id,
                'clocked_in_at' => now()->subHour()]);
        });

        $this->signedInAs($t['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => 'later@example.test', 'role_id' => $starter->role_id,
            'scope_type' => 'franchise', 'scope_id' => $t['franchise']->id,
        ])->assertCreated();
        $this->inTenant($t['org'], fn () => Invitation::where('email', 'later@example.test')
            ->update(['expires_at' => now()->subDay()]));

        $data = $this->signedInAs($t['admin'])->getJson('/api/v1/hr/dashboard/overview?period=month')
            ->assertOk()->json('data');

        $this->assertSame('month', $data['period']);
        $this->assertSame(1, $data['joined']['count'], 'Joined counted account rows, not start dates.');
        $this->assertSame($starter->id, $data['joined']['people'][0]['user_id']);
        $this->assertSame('Dispatcher', $data['joined']['people'][0]['role']);
        $this->assertSame(1, $data['left']['count']);
        $this->assertSame($leaver->id, $data['left']['people'][0]['user_id']);
        $this->assertSame(1, $data['invitations_stalled']['expired']);
        $this->assertCount(12, $data['by_month']);
        $this->assertSame(1, end($data['by_month'])['joined']);
        $this->assertSame(1, $data['on_the_books']['inactive']);
        $this->assertSame(1, $data['right_now']['on_the_clock']['count']);

        // Somebody who cannot open Work Hours or Training is not told about them.
        $limited = $this->signedInAs($reader)->getJson('/api/v1/hr/dashboard/overview')->assertOk()->json('data.right_now');
        $this->assertNull($limited['on_the_clock']);
        $this->assertNull($limited['due_for_retaking']);
    }
}
