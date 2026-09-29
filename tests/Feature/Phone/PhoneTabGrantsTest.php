<?php

namespace Tests\Feature\Phone;

use App\Models\Permission;
use App\Models\PhoneConnection;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Communication's tabs are granted one at a time, and hiding a tab closes its
 * API. Jose, 2026-09-29: "the communication right now is only view and edit,
 * which means everyone can view the settings".
 */
class PhoneTabGrantsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FILE = 'database/migrations/2026_09_29_450000_grant_existing_roles_the_communication_tabs.php';

    private const TABS = [
        'phone.phone.view',
        'phone.records.view',
        'phone.reports.view',
        'phone.settings.view',
        'phone.users.view',
    ];

    public function test_settings_endpoints_need_the_settings_tab(): void
    {
        [$t] = $this->company();
        $without = $this->userWithRole($t, 'Dispatcher', ['phone.view', 'phone.edit']);
        $with = $this->userWithRole($t, 'Office manager', ['phone.view', 'phone.edit', 'phone.settings.view']);

        $this->signedInAs($without)->getJson('/api/v1/phone/flows')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->signedInAs($with)->getJson('/api/v1/phone/flows')->assertOk();
    }

    public function test_reports_need_the_reports_tab(): void
    {
        [$t] = $this->company();
        $without = $this->userWithRole($t, 'Dispatcher', ['phone.view', 'phone.settings.view']);
        $with = $this->userWithRole($t, 'Manager', ['phone.view', 'phone.reports.view']);

        $this->signedInAs($without)->getJson('/api/v1/phone/reports')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->signedInAs($with)->getJson('/api/v1/phone/reports')->assertOk();
    }

    /**
     * Everybody's extension opens from Users OR Settings, and from neither
     * without one of them.
     */
    public function test_extensions_open_from_users_or_settings_only(): void
    {
        [$t] = $this->company();
        $neither = $this->userWithRole($t, 'Dispatcher', ['phone.view', 'phone.records.view']);
        $users = $this->userWithRole($t, 'Lead', ['phone.view', 'phone.users.view']);

        $this->signedInAs($neither)->getJson('/api/v1/phone/extensions')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->signedInAs($users)->getJson('/api/v1/phone/extensions')->assertOk();
    }

    /**
     * THE CONTROL: the Phone tab's own endpoints stay open on `phone.view`
     * alone. Over-gating them would leave a dispatcher who holds no Settings
     * unable to Page a team or see who is calling.
     */
    public function test_the_dial_pad_endpoints_stay_open_without_settings(): void
    {
        [$t] = $this->company();
        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['phone.view']);

        $this->signedInAs($dispatcher)->getJson('/api/v1/phone/ring-groups')->assertOk();
        $this->signedInAs($dispatcher)->getJson('/api/v1/phone/caller?e164=%2B13055550100')->assertOk();
    }

    /**
     * The migration, run as the FILE: a role that could open Communication
     * keeps every tab, and a role that could not gains none.
     */
    public function test_existing_roles_keep_every_tab(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        [$holder, $stranger] = $this->inTenant($t['org'], function () use ($t) {
            $holder = Role::create(['organization_id' => $t['org']->id, 'name' => 'Dispatcher']);
            $stranger = Role::create(['organization_id' => $t['org']->id, 'name' => 'Driver']);

            DB::table('permission_role')->insert([
                'role_id' => $holder->getKey(),
                'permission_id' => Permission::query()->where('key', 'phone.view')->value('id'),
            ]);

            return [$holder, $stranger];
        });

        $this->assertSame([], $this->tabKeysOf($holder), 'fixture already had the tabs');

        (require base_path(self::FILE))->up();
        (require base_path(self::FILE))->up();

        $this->assertSame(self::TABS, $this->tabKeysOf($holder));
        $this->assertSame([], $this->tabKeysOf($stranger));
    }

    /** @return array{0: array<string, mixed>} */
    private function company(): array
    {
        $t = $this->provisionTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $c = new PhoneConnection([
                'organization_id' => $t['org']->id,
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'ACtabstabstabstabstabstabstabsta',
                'api_key_sid' => 'SKtabstabstabstabstabstabstabsta',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $c->writeSecrets('secret', 'token');
            $c->save();
        });

        return [$t];
    }

    /** @return list<string> */
    private function tabKeysOf(Role $role): array
    {
        return app(TenantContext::class)->run($role->organization, function () use ($role) {
            $keys = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permission_role.role_id', $role->getKey())
                ->whereIn('permissions.key', self::TABS)
                ->pluck('permissions.key')
                ->all();

            sort($keys);

            return $keys;
        });
    }
}
