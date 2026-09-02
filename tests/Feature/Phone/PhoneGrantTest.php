<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Phone number grants on the Roles editor (Task 6).
 *
 * Nest under Phone like inspections under Inspection. Save accepts
 * phone_numbers: [uuid]. Administrator holds every number without rows.
 */
class PhoneGrantTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_catalogue_nests_this_organizations_numbers_and_not_another_ones(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');
        $this->number($alpha, '+12035551001', 'Alpha main');
        $this->number($bravo, '+12035551009', 'Bravo main');

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')
            ->assertOk()
            ->json('modules');

        $phone = collect($modules)->firstWhere('key', 'phone');
        $this->assertNotNull($phone);
        $this->assertSame(['+12035551001'], array_column($phone['phone_numbers'], 'e164'));
        $this->assertSame(['Alpha main'], array_column($phone['phone_numbers'], 'label'));

        foreach ($modules as $module) {
            if ($module['key'] === 'phone') {
                continue;
            }
            $this->assertArrayNotHasKey('phone_numbers', $module);
        }

        $theirs = $this->signedInAs($bravo['admin'])
            ->getJson('/api/v1/roles/catalogue')
            ->assertOk()
            ->json('modules');

        $this->assertSame(
            ['+12035551009'],
            array_column(collect($theirs)->firstWhere('key', 'phone')['phone_numbers'], 'e164'),
        );
    }

    /**
     * The tab IS the grant, as of 2 Sep.
     *
     * This asserted the opposite until then — granting Communication granted
     * no line, and each one had to be ticked on afterwards. Jose ruled that
     * out: a tenant with a hundred numbers cannot tick a hundred boxes per
     * role, and forgetting one shows an empty Dial screen with no reason on
     * it. The test is inverted rather than deleted so the change is visible
     * to whoever reads this file next.
     */
    public function test_granting_the_tab_grants_every_number(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $main = $this->number($alpha, '+12035551011', 'Main');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Desk',
            'permissions' => ['phone.view', 'phone.edit'],
        ])->assertCreated()->json('data');

        $this->assertSame([$main->id], $role['phone_numbers']);
    }

    /**
     * Sending a subset, or none at all, does not narrow what the role holds.
     *
     * The roles screen still draws a number picker. It no longer decides
     * anything, and this pins that: a role saved with one line still reports
     * both, and saving with none does not take them away. Better to have the
     * inertness asserted than to leave somebody wondering why their tick did
     * nothing.
     */
    public function test_a_role_holds_every_line_whatever_the_picker_sent(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $main = $this->number($alpha, '+12035551021', 'Main');
        $spare = $this->number($alpha, '+12035551022', 'Spare');

        $both = [$main->id, $spare->id];
        sort($both);

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Desk',
            'permissions' => ['phone.view'],
            'phone_numbers' => [$main->id],
        ])->assertCreated()->json('data');

        $this->assertSame($both, $role['phone_numbers']);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'permissions' => ['phone.view'],
            'phone_numbers' => [],
        ])->assertOk()->assertJsonPath('data.phone_numbers', $both);
    }

    public function test_a_number_on_a_role_without_the_tab_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $main = $this->number($alpha, '+12035551031', 'Main');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Blind',
            'permissions' => ['customers.view'],
            'phone_numbers' => [$main->id],
        ])->assertStatus(422)->assertJsonPath(
            'errors.phone_numbers.0',
            // Names the module the way the person reading it sees it in the
            // sidebar, which is Communication now. The KEY is still 'phone' —
            // it is what every permission and route is named after — so this
            // is the one place the rename is visible, and that is the point of
            // the refusal: it tells somebody which tab to grant.
            fn (string $m) => str_contains($m, 'would open nothing')
                && str_contains($m, 'Communication'),
        );
    }

    public function test_the_roles_list_reports_phone_numbers_and_administrator_holds_all(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $main = $this->number($alpha, '+12035551041', 'Main');
        $spare = $this->number($alpha, '+12035551042', 'Spare');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Desk',
            'permissions' => ['phone.view'],
            'phone_numbers' => [$main->id],
        ])->assertCreated();

        $listed = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->json('data');

        $desk = collect($listed)->firstWhere('name', 'Desk');
        $administrator = collect($listed)->firstWhere('is_administrator', true);

        // Desk and Administrator hold the same set now: every line in the
        // organisation. Administrator used to be the only role that did.
        $this->assertEqualsCanonicalizing([$main->id, $spare->id], $desk['phone_numbers']);
        $this->assertEqualsCanonicalizing([$main->id, $spare->id], $administrator['phone_numbers']);
    }

    /** @param  array{org: \App\Models\Organization, admin: \App\Models\User}  $tenant */
    private function number(array $tenant, string $e164, string $label): PhoneNumber
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $e164, $label) {
            $connection = PhoneConnection::query()
                ->where('scope_type', PhoneConnection::SCOPE_ORGANIZATION)
                ->where('scope_id', $tenant['org']->id)
                ->first()
                ?? PhoneConnection::factory()->create([
                    'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                    'scope_id' => $tenant['org']->id,
                ]);

            return PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $tenant['org']->id,
                'e164' => $e164,
                'label' => $label,
            ]);
        });
    }
}
