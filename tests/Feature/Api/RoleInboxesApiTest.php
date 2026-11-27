<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Organization;
use App\Models\PlatformModuleFlag;
use App\Models\Role;
use App\Support\Access\InboxDirectory;
use App\Support\Modules\ModuleAvailability;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The organization's own inboxes, nested under the Email module (plan §4).
 *
 * The owner: *"the organization can grant access to the email module to a role,
 * but not necessary to all the emails connected to the module."*
 *
 * ── What is actually under test here ─────────────────────────────
 *
 * One menu on screen, two kinds of thing underneath it, and the whole point is
 * that they stay two kinds of thing. `email.view` is a PERMISSION: declared in
 * code, identical on every installation, stored in a platform table every tenant
 * reads. `info@a-1chimney.com` is a TENANT RECORD, and a permission row per
 * mailbox would have put one organization's addresses into that shared
 * catalogue.
 *
 * So the tests below are mostly about a boundary rather than about a feature:
 * that the addresses reach the right tenant's editor and no other, that the tab
 * and the inbox are separately grantable in both directions, and that every rule
 * §4 already stated about a permission is true of a grant as well.
 */
class RoleInboxesApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the tree ─────────────────────────────────────────────────

    public function test_the_catalogue_carries_this_organizations_inboxes_and_not_another_ones(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');

        $this->connectInbox($alpha, 'info@a-1chimney.com', 'Info');
        $this->connectInbox($bravo, 'billing@everdoors.com');

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $email = collect($modules)->firstWhere('key', 'email');

        $this->assertSame(
            ['info@a-1chimney.com'],
            array_column($email['inboxes'], 'address'),
        );

        // The name travels with it, because "Info" and the address are two
        // different facts and the screen shows both.
        $this->assertSame('Info', $email['inboxes'][0]['label']);

        // The other tenant's editor, from the same code path. Without this the
        // assertion above passes just as well against a directory that returns
        // every mailbox on the box — Alpha has one inbox either way.
        $theirs = $this->signedInAs($bravo['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $this->assertSame(
            ['billing@everdoors.com'],
            array_column(collect($theirs)->firstWhere('key', 'email')['inboxes'], 'address'),
        );
    }

    public function test_a_module_that_has_nothing_to_do_with_mail_carries_no_inbox_key(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        // Present and empty on the module that owns mail — this organization has
        // connected none — and ABSENT everywhere else. The two are different
        // statements: the first gets a sentence on screen saying inboxes will
        // appear here, the second gets nothing at all, and an empty nested list
        // under Customers would read as a screen that failed to load.
        $email = collect($modules)->firstWhere('key', 'email');

        $this->assertSame([], $email['inboxes']);

        foreach ($modules as $module) {
            if ($module['key'] === 'email') {
                continue;
            }

            $this->assertArrayNotHasKey('inboxes', $module, sprintf(
                'Module %s was given an inbox list it has no use for.',
                $module['key'],
            ));
        }
    }

    // ── the tab and the inbox are separate grants ────────────────

    public function test_a_role_can_hold_the_tab_with_no_inboxes_and_with_some(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $info  = $this->connectInbox($alpha, 'info@a-1chimney.com');
        $this->connectInbox($alpha, 'billing@a-1chimney.com');

        // The tab, and no mail with it. This is the state the owner asked to be
        // reachable, and it is the one a permission-key-per-inbox design would
        // have made hard to express.
        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Front desk', 'permissions' => ['email.view'],
        ])->assertCreated()->json('data');

        $this->assertSame(['email.view'], $role['permissions']);
        $this->assertSame([], $role['inboxes']);

        // And then one inbox, not both.
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'permissions' => ['email.view'],
            'inboxes'     => [$info->id],
        ])->assertOk()->assertJsonPath('data.inboxes', [$info->id]);

        $listed = collect($this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles')->assertOk()->json('data'))
            ->firstWhere('name', 'Front desk');

        $this->assertSame([$info->id], $listed['inboxes']);
    }

    public function test_granting_the_tab_grants_no_inbox(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $this->connectInbox($alpha, 'info@a-1chimney.com');
        $this->connectInbox($alpha, 'billing@a-1chimney.com');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Front desk', 'permissions' => ['email.view'],
        ])->assertCreated()->json('data');

        // The whole owner requirement in one assertion: somebody who ticks Email
        // and expects that to include the mail has not been given the mail.
        $this->assertSame([], $role['inboxes']);

        // The control. Two inboxes exist and are reachable by this actor, so an
        // empty answer here is a decision rather than an empty organization.
        $this->assertCount(2, $this->inboxesIn($alpha));
    }

    public function test_an_inbox_granted_to_a_role_that_cannot_open_the_tab_is_refused(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $info  = $this->connectInbox($alpha, 'info@a-1chimney.com');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Blind', 'permissions' => ['customers.view'], 'inboxes' => [$info->id],
        ])->assertStatus(422)->assertJsonPath(
            'errors.inboxes.0',
            fn (string $m) => str_contains($m, 'would open nothing')
                && str_contains($m, 'Email'),
        );

        $this->inTenant($alpha['org'], function () {
            $this->assertNull(Role::where('name', 'Blind')->first());
            $this->assertSame(0, MailboxGrant::count());
        });
    }

    public function test_the_tab_and_an_inbox_can_be_granted_in_the_same_request(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $info  = $this->connectInbox($alpha, 'info@a-1chimney.com');

        // The ordinary case, and the reason the tab check runs after the
        // permissions are saved rather than against the role as it arrived.
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Front desk', 'permissions' => ['email.view'], 'inboxes' => [$info->id],
        ])->assertCreated()->assertJsonPath('data.inboxes', [$info->id]);
    }

    public function test_revoking_is_sending_the_list_without_it(): void
    {
        $this->declareEmailModule();

        $alpha   = $this->provisionTenant('Alpha');
        $info    = $this->connectInbox($alpha, 'info@a-1chimney.com');
        $billing = $this->connectInbox($alpha, 'billing@a-1chimney.com');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name'        => 'Front desk',
            'permissions' => ['email.view'],
            'inboxes'     => [$info->id, $billing->id],
        ])->assertCreated()->json('data');

        $this->assertCount(2, $role['inboxes']);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'inboxes' => [$info->id],
        ])->assertOk()->assertJsonPath('data.inboxes', [$info->id]);

        // The row is gone rather than tombstoned — access taken away is not a
        // fact worth keeping, and the audit log is where the taking is recorded.
        $this->inTenant($alpha['org'], fn () => $this->assertSame(
            0,
            MailboxGrant::where('role_id', $role['id'])->where('mailbox_id', $billing->id)->count(),
        ));
    }

    public function test_omitting_the_field_leaves_the_grants_alone(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $info  = $this->connectInbox($alpha, 'info@a-1chimney.com');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Front desk', 'permissions' => ['email.view'], 'inboxes' => [$info->id],
        ])->assertCreated()->json('data');

        // A rename says nothing about mail. Absent means "leave these alone" —
        // the same contract `permissions` has, and the difference between a
        // client that has an opinion and one that is not talking about it.
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'name' => 'Reception',
        ])->assertOk()->assertJsonPath('data.inboxes', [$info->id]);
    }

    // ── the escalation bound, one level down ─────────────────────

    public function test_nobody_can_grant_an_inbox_they_cannot_open(): void
    {
        $this->declareEmailModule();

        $alpha   = $this->provisionTenant('Alpha');
        $info    = $this->connectInbox($alpha, 'info@a-1chimney.com');
        $payroll = $this->connectInbox($alpha, 'payroll@a-1chimney.com');

        // A supervisor who answers info@ and has never seen payroll@.
        $supervisor = $this->userWithRole($alpha, 'Supervisor', [
            'roles.view', 'roles.create', 'roles.edit', 'email.view',
        ]);

        $this->grant($alpha, 'Supervisor', $info);

        $this->signedInAs($supervisor)->postJson('/api/v1/roles', [
            'name' => 'Overreach', 'permissions' => ['email.view'], 'inboxes' => [$payroll->id],
        ])->assertStatus(422)->assertJsonPath(
            'errors.inboxes.0',
            fn (string $m) => str_contains($m, 'payroll@a-1chimney.com')
                && str_contains($m, 'cannot grant'),
        );

        $this->inTenant($alpha['org'], fn () => $this->assertNull(
            Role::where('name', 'Overreach')->first()
        ));

        // The positive control, and it is the reason the test above is worth
        // anything: inside their own reach the same request goes through, so
        // this is not simply an endpoint that refuses everybody.
        $this->signedInAs($supervisor)->postJson('/api/v1/roles', [
            'name' => 'Intake', 'permissions' => ['email.view'], 'inboxes' => [$info->id],
        ])->assertCreated()->assertJsonPath('data.inboxes', [$info->id]);
    }

    public function test_an_inbox_the_caller_cannot_open_is_neither_removed_nor_kept_by_accident(): void
    {
        $this->declareEmailModule();

        $alpha   = $this->provisionTenant('Alpha');
        $info    = $this->connectInbox($alpha, 'info@a-1chimney.com');
        $payroll = $this->connectInbox($alpha, 'payroll@a-1chimney.com');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name'        => 'Team',
            'permissions' => ['email.view'],
            'inboxes'     => [$info->id, $payroll->id],
        ])->assertCreated()->json('data');

        $supervisor = $this->userWithRole($alpha, 'Supervisor', [
            'roles.view', 'roles.edit', 'email.view',
        ]);

        $this->grant($alpha, 'Supervisor', $info);

        // They send an empty list. Their editor never drew payroll@ as anything
        // they could move, so the omission is not a decision to revoke it —
        // treating it as one lets a smaller role quietly cut a larger one off
        // from mail it cannot see.
        $this->signedInAs($supervisor)->patchJson("/api/v1/roles/{$role['id']}", [
            'inboxes' => [],
        ])->assertOk()->assertJsonPath('data.inboxes', [$payroll->id]);
    }

    public function test_an_inbox_from_another_organization_cannot_be_granted(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');

        $theirs = $this->connectInbox($bravo, 'billing@everdoors.com');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Reach', 'permissions' => ['email.view'], 'inboxes' => [$theirs->id],
        ])->assertStatus(422)->assertJsonPath(
            'errors.inboxes.0',
            // Never "that inbox belongs to Everdoors". Alpha is not told the row
            // exists at all, which is the same answer tenant scoping gives
            // everywhere else in this API.
            fn (string $m) => str_contains($m, 'no longer connected')
                && ! str_contains($m, 'everdoors'),
        );

        $this->inTenant($bravo['org'], fn () => $this->assertSame(0, MailboxGrant::count()));
    }

    // ── Administrator, and the platform switch ───────────────────

    public function test_administrator_reports_every_inbox_without_holding_a_row(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $this->connectInbox($alpha, 'info@a-1chimney.com');
        $this->connectInbox($alpha, 'billing@a-1chimney.com');

        $administrator = collect($this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles')->assertOk()->json('data'))
            ->firstWhere('is_administrator', true);

        // It owns no grants at all — nothing writes them for it — so reading its
        // rows would report an administrator who can open no mail, which is the
        // same trap `permissions` avoids by expanding to the full catalogue.
        $this->assertCount(2, $administrator['inboxes']);

        $this->inTenant($alpha['org'], fn () => $this->assertSame(0, MailboxGrant::count()));
    }

    public function test_a_platform_disabled_mail_module_stops_new_grants_and_keeps_the_old(): void
    {
        $this->declareEmailModule();

        $alpha   = $this->provisionTenant('Alpha');
        $info    = $this->connectInbox($alpha, 'info@a-1chimney.com');
        $billing = $this->connectInbox($alpha, 'billing@a-1chimney.com');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Front desk', 'permissions' => ['email.view'], 'inboxes' => [$info->id],
        ])->assertCreated()->json('data');

        $this->assertSame([$info->id], $role['inboxes']);

        app(ModuleAvailability::class)->setState(
            'email', PlatformModuleFlag::DISABLED, reason: 'Rotating the OAuth client'
        );

        // Read openly. The roles list reports what the organization decided,
        // exactly as it reports permission keys on a switched-off module — the
        // editor greys the MODULE from the catalogue's own `available` flag, and
        // grants that vanished mid-incident would read as EzBizi having deleted
        // them.
        $listed = collect($this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles')->assertOk()->json('data'))
            ->firstWhere('name', 'Front desk');

        $this->assertSame([$info->id], $listed['inboxes']);

        $email = collect($this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules'))
            ->firstWhere('key', 'email');

        $this->assertFalse($email['available']);

        // Write closed. Nothing new may be granted while the module is off, for
        // anybody — the same rule §13 applies to the permission behind the tab.
        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'inboxes' => [$info->id, $billing->id],
        ])->assertStatus(422);

        $this->inTenant($alpha['org'], fn () => $this->assertSame(1, MailboxGrant::count()));
    }

    // ── audit (§2.2) ─────────────────────────────────────────────

    public function test_a_grant_is_recorded_as_an_address_rather_than_an_id(): void
    {
        $this->declareEmailModule();

        $alpha   = $this->provisionTenant('Alpha');
        $info    = $this->connectInbox($alpha, 'info@a-1chimney.com');
        $billing = $this->connectInbox($alpha, 'billing@a-1chimney.com');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Front desk', 'permissions' => ['email.view'], 'inboxes' => [$info->id],
        ])->assertCreated()->json('data');

        $this->assertSame(
            ['info@a-1chimney.com'],
            AuditEvent::where('action', 'role.created')->value('metadata')['inboxes'],
        );

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'inboxes' => [$billing->id],
        ])->assertOk();

        $changed = AuditEvent::where('action', 'role.inboxes_changed')->value('changes');

        // Both directions, in words. "granted 0f8c-…" answers nothing six months
        // later, and it stops resolving to anything the day the inbox is
        // disconnected.
        $this->assertSame(['billing@a-1chimney.com'], $changed['added']);
        $this->assertSame(['info@a-1chimney.com'], $changed['removed']);
    }

    public function test_deleting_a_role_records_the_mail_it_could_read(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $info  = $this->connectInbox($alpha, 'info@a-1chimney.com');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Temporary', 'permissions' => ['email.view'], 'inboxes' => [$info->id],
        ])->assertCreated()->json('data');

        $this->signedInAs($alpha['admin'])
            ->deleteJson("/api/v1/roles/{$role['id']}")->assertOk();

        // `mailbox_grants.role_id` cascades, so the rows are gone. This entry is
        // the only place left that says this role could read that mail.
        $this->assertSame(
            ['info@a-1chimney.com'],
            AuditEvent::where('action', 'role.deleted')->value('metadata')['inboxes'],
        );

        $this->inTenant($alpha['org'], fn () => $this->assertSame(0, MailboxGrant::count()));
    }

    // ── what it refused before, it still refuses ─────────────────

    public function test_the_catalogue_is_still_the_module_registry(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');
        $this->connectInbox($alpha, 'info@a-1chimney.com');

        $body = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $registry = app(ModuleRegistry::class);

        // Composing the inboxes in must not have changed what the tree IS.
        // Order follows the sidebar, and modules that are not a grantable
        // surface (Users) stay out of the editor.
        $shown = array_values(array_filter(
            $registry->all(),
            fn (ModuleDefinition $module) => $module->rolesGroup !== ModuleDefinition::ROLES_OMIT,
        ));

        $this->assertEqualsCanonicalizing(
            array_map(fn (ModuleDefinition $m) => $m->key, $shown),
            array_column($body, 'key'),
        );

        foreach ($body as $module) {
            $definition = $registry->get($module['key']);

            $this->assertSame($definition->label, $module['label']);
            $this->assertSame($definition->permissionKeys(), array_column($module['actions'], 'key'));
        }
    }

    public function test_the_escalation_bound_on_permissions_is_untouched(): void
    {
        $this->declareEmailModule();

        $alpha = $this->provisionTenant('Alpha');

        $supervisor = $this->userWithRole($alpha, 'Supervisor', [
            'roles.view', 'roles.create', 'customers.view',
        ]);

        $this->signedInAs($supervisor)->postJson('/api/v1/roles', [
            'name' => 'Overreach', 'permissions' => ['customers.delete'],
        ])->assertStatus(422)->assertJsonPath(
            'errors.permissions.0',
            fn (string $m) => str_contains($m, 'customers.delete'),
        );
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * Declare the Email module for the duration of this test.
     *
     * It belongs in ModuleServiceProvider and is the Email module's own change to
     * make — this file does not own that provider, and two changes registering
     * the same key would fail at boot with "Module 'email' is already
     * registered". So this registers it only when nothing else has, which means
     * these tests pass both before that declaration lands and after it.
     */
    private function declareEmailModule(): void
    {
        $registry = app(ModuleRegistry::class);

        if ($registry->get(InboxDirectory::MODULE) !== null) {
            return;
        }

        $registry->register(new ModuleDefinition(
            key:     InboxDirectory::MODULE,
            label:   'Email',
            actions: ['view', 'send'],
            automationExemptBecause:
                'Declared by this test so the roles editor has a module to nest inboxes '
                .'under. The real declaration, with its triggers, belongs to the Email '
                .'module.',
        ));
    }

    /** @param array{org: Organization} $tenant */
    private function connectInbox(array $tenant, string $address, ?string $name = null): Mailbox
    {
        return $this->inTenant($tenant['org'], fn () => Mailbox::create([
            'organization_id' => $tenant['org']->id,
            'scope_type'      => Mailbox::SCOPE_ORGANIZATION,
            'scope_id'        => $tenant['org']->id,
            'address'         => $address,
            'display_name'    => $name ?? $address,
            'provider'        => 'imap',
        ]));
    }

    /** Give a role an inbox directly, for setting up an actor's own reach. */
    private function grant(array $tenant, string $roleName, Mailbox $mailbox): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $roleName, $mailbox) {
            MailboxGrant::create([
                'organization_id' => $tenant['org']->id,
                'role_id'         => Role::where('name', $roleName)->firstOrFail()->id,
                'mailbox_id'      => $mailbox->id,
            ]);
        });
    }

    /** @return Mailbox[] */
    private function inboxesIn(array $tenant): array
    {
        return $this->inTenant($tenant['org'], fn () => Mailbox::all()->all());
    }
}
