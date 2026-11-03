<?php

namespace Tests\Feature\Hr;

use App\Models\EmploymentEvent;
use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-03: Eve fills the gaps by hand; expired invitations are withdrawn. */
class HrGapsAndExpiryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_records_filter_people_missing_a_start_date_or_a_contract(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Technician', ['jobs.view']);
        $ids = fn (string $missing) => collect($this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/hr/people?missing={$missing}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($person->id, $ids('start_date'));
        $this->assertContains($person->id, $ids('contract'));

        $this->inTenant($alpha['org'], fn () => EmploymentEvent::create(['organization_id' => $alpha['org']->id,
            'user_id' => $person->id, 'kind' => EmploymentEvent::STARTED, 'occurred_on' => '2025-04-01']));
        $this->assertNotContains($person->id, $ids('start_date'));
    }

    public function test_expired_invitations_are_withdrawn_and_held_ones_wait(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role = $this->userWithRole($alpha, 'Technician', ['jobs.view'])->role_id;
        $invite = fn (string $email) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'role_id' => $role,
            'scope_type' => 'franchise', 'scope_id' => $alpha['franchise']->id,
        ])->assertCreated()->json('data.id');
        [$old, $held, $fresh] = [$invite('old@example.test'), $invite('held@example.test'), $invite('fresh@example.test')];
        $this->inTenant($alpha['org'], function () use ($old, $held) {
            Invitation::whereKey($old)->update(['expires_at' => now()->subDay()]);
            Invitation::whereKey($held)->update(['expires_at' => now()->subDay(), 'held_at' => now()]);
        });

        $this->artisan('ezbizi:revoke-expired-invitations', ['--dry-run' => true])
            ->expectsOutputToContain('Would withdraw 1')->assertSuccessful();
        $this->artisan('ezbizi:revoke-expired-invitations')->assertSuccessful();

        $revoked = $this->inTenant($alpha['org'], fn () => Invitation::whereNotNull('revoked_at')->pluck('id')->all());
        $this->assertSame([$old], $revoked);
        $this->assertNotContains($fresh, $revoked);
    }
}
