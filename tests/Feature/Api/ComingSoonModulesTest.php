<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Web Management and Phone — tabs that can be granted, and are not built.
 *
 * Being new exempts them from nothing (§4). They exist so an organization can
 * turn the destination on or off, and so the sidebar is not a second list
 * that has to be remembered when the work behind them lands.
 */
class ComingSoonModulesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string}> */
    public static function modules(): array
    {
        return [
            ['web_management', 'Web Management'],
            ['phone', 'Phone'],
        ];
    }

    #[DataProvider('modules')]
    public function test_the_module_is_registered_and_offers_exactly_the_tab_permission(
        string $key,
        string $label,
    ): void {
        $module = app(ModuleRegistry::class)->get($key);

        $this->assertNotNull($module, "{$label} is not in the registry, so it has no tab at all.");
        $this->assertSame($label, $module->label);
        $this->assertSame(["{$key}.view"], $module->permissionKeys());
        $this->assertNotEmpty(
            $module->automationExemptBecause,
            "{$label} declared no events and gave no reason.",
        );
    }

    #[DataProvider('modules')]
    public function test_an_organization_can_grant_the_tab_from_the_roles_editor(
        string $key,
        string $label,
    ): void {
        $alpha = $this->provisionTenant('Alpha');

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $row = collect($modules)->firstWhere('key', $key);

        $this->assertNotNull($row, "{$label} is not offered in the roles editor.");
        $this->assertSame($label, $row['label']);
        $this->assertContains("{$key}.view", collect($row['actions'])->pluck('key')->all());
    }

    #[DataProvider('modules')]
    public function test_the_tab_is_reachable_only_when_the_role_holds_it(
        string $key,
        string $label,
    ): void {
        $alpha    = $this->provisionTenant('Beta');
        $viewer   = $this->userWithRole($alpha, 'Viewer', ['customers.view']);
        $operator = $this->userWithRole($alpha, 'Operator', ["{$key}.view"]);

        $this->assertFalse($this->inMe($viewer, $key)['reachable'], "{$label} leaked to a role that was not granted it.");
        $this->assertTrue($this->inMe($operator, $key)['reachable']);
        $this->assertSame($label, $this->inMe($operator, $key)['label']);
    }

    public function test_the_new_tabs_sit_after_marketing_and_before_email(): void
    {
        $keys = array_keys(app(ModuleRegistry::class)->all());

        $this->assertLessThan(
            array_search('web_management', $keys, true),
            array_search('marketing', $keys, true),
        );
        $this->assertLessThan(
            array_search('phone', $keys, true),
            array_search('web_management', $keys, true),
        );
        $this->assertLessThan(
            array_search('email', $keys, true),
            array_search('phone', $keys, true),
        );
    }

    /** @return array{label: string, reachable: bool} */
    private function inMe(\App\Models\User $user, string $key): array
    {
        $modules = $this->signedInAs($user)->getJson('/api/v1/me')->assertOk()->json('data.modules');
        $row     = collect($modules)->firstWhere('key', $key);

        $this->assertNotNull($row, "{$key} is missing from /me entirely.");

        return $row;
    }
}
