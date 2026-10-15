<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-15: "if a company is switched off, it should not appear on the
 * company picker". /me says which companies are off (the client hides them),
 * and a view standing in one, stored or sent, comes back as the organization.
 */
class SwitchedOffCompanyViewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @param  array<string, mixed>  $t */
    private function switchOff(array $t): void
    {
        app(TenantContext::class)->run($t['org'], fn () => Company::find($t['company']->id)
            ->forceFill(['is_active' => false])->save());
    }

    /** @param  array<string, mixed>  $t */
    private function standIn(array $t, string $scope): void
    {
        app(TenantContext::class)->run($t['org'], fn () => User::find($t['admin']->id)
            ->forceFill(['preferences' => ['navigation.scope' => $scope]])->save());
    }

    public function test_me_says_a_company_is_switched_off(): void
    {
        $t = $this->provisionTenant();
        $this->switchOff($t);

        $this->signedInAs($t['admin'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.companies.0.id', $t['company']->id)
            ->assertJsonPath('data.companies.0.is_active', false);
    }

    public function test_a_stored_view_on_a_switched_off_company_falls_back_to_the_organization(): void
    {
        $t = $this->provisionTenant();
        $this->standIn($t, 'company:'.$t['company']->id);

        // The control: standing there works while the company is on.
        $this->signedInAs($t['admin'])->getJson('/api/v1/me')
            ->assertJsonPath('data.scope.type', 'company');

        $this->switchOff($t);

        $this->signedInAs($t['admin'])->getJson('/api/v1/me')
            ->assertJsonPath('data.scope.type', 'organization');
    }

    public function test_a_view_header_on_a_place_in_a_switched_off_company_is_the_organization(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->withHeader('X-EzBizi-View', 'franchise:'.$t['franchise']->id)
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.scope.type', 'franchise');

        $this->switchOff($t);

        $this->signedInAs($t['admin'])
            ->withHeader('X-EzBizi-View', 'franchise:'.$t['franchise']->id)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'organization');
    }
}
