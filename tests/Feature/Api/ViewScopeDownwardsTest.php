<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\SavedReport;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-16: "People assigned to a company are also seeing the
 * organization ... they shouldn't, access is only downwards, not upwards."
 */
class ViewScopeDownwardsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @param  array<string, mixed>  $t */
    private function atCompany(array $t): User
    {
        $user = $this->userWithRole($t, 'Dispatcher');

        return app(TenantContext::class)->run($t['org'], function () use ($user, $t) {
            Assignment::query()->where('user_id', $user->id)->delete();
            Assignment::create([
                'organization_id' => $t['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $t['company']->id,
            ]);

            return $user->fresh();
        });
    }

    public function test_a_company_person_is_not_offered_the_organization_and_lands_on_their_company(): void
    {
        $t = $this->provisionTenant();
        $person = $this->atCompany($t);

        $this->signedInAs($person)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.organization.can_view', false)
            ->assertJsonPath('data.scope.type', 'company')
            ->assertJsonPath('data.scope.id', $t['company']->id);

        // The control: somebody assigned at organization level still has it.
        $this->signedInAs($t['admin'])->getJson('/api/v1/me')
            ->assertJsonPath('data.organization.can_view', true)
            ->assertJsonPath('data.scope.type', 'organization');
    }

    public function test_the_organization_header_is_refused_to_a_company_person(): void
    {
        $t = $this->provisionTenant();
        $person = $this->atCompany($t);

        $this->signedInAs($person)
            ->withHeader('X-EzBizi-View', 'organization')
            ->getJson('/api/v1/me')
            ->assertForbidden();

        $this->signedInAs($t['admin'])
            ->withHeader('X-EzBizi-View', 'organization')
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_a_stored_organization_view_falls_back_to_the_company_not_upwards(): void
    {
        $t = $this->provisionTenant();
        $person = $this->atCompany($t);
        app(TenantContext::class)->run($t['org'], fn () => $person
            ->forceFill(['preferences' => ['navigation.scope' => 'organization']])->save());

        $this->signedInAs($person)->getJson('/api/v1/me')
            ->assertJsonPath('data.scope.type', 'company')
            ->assertJsonPath('data.scope.id', $t['company']->id);
    }

    public function test_switching_their_company_off_does_not_lift_them_to_the_organization(): void
    {
        $t = $this->provisionTenant();
        $person = $this->atCompany($t);
        app(TenantContext::class)->run($t['org'], fn () => Company::find($t['company']->id)
            ->forceFill(['is_active' => false])->save());

        $this->signedInAs($person)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'company')
            ->assertJsonPath('data.scope.id', $t['company']->id);
    }

    /** The audit's leak 1: a place view listed every company's saved reports. */
    public function test_a_place_view_lists_only_its_own_companys_reports(): void
    {
        $t = $this->provisionTenant();
        $person = $this->userWithRole($t, 'Dispatcher', ['reports.view', 'reports.library.view'], onlyAt: $t['franchise']);
        [$ours, $theirs] = app(TenantContext::class)->run($t['org'], function () use ($t) {
            $other = Company::create(['organization_id' => $t['org']->id, 'name' => 'Elsewhere Co', 'is_active' => true]);

            return [
                SavedReport::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Ours', 'definition' => ['rows' => 'jobs']]),
                SavedReport::create(['organization_id' => $t['org']->id, 'company_id' => $other->id, 'name' => 'Theirs', 'definition' => ['rows' => 'jobs']]),
            ];
        });

        $names = collect($this->signedInAs($person)->getJson('/api/v1/reports')->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Ours', $names);
        $this->assertNotContains('Theirs', $names);
    }
}
