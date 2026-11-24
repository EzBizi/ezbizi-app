<?php

namespace Tests\Feature\Access;

use App\Models\Automation;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The authorization sweep (2026-11-17) found a franchise-scoped user editing and
 * deleting organization records: roles and organization-wide automations.
 * AGENTS.md: a company view cannot modify the organization.
 */
class OrganizationWritesNeedTheOrganizationViewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ALL = [
        'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
        'automations.view', 'automations.edit', 'automations.delete',
    ];

    public function test_a_branch_user_cannot_change_or_delete_a_role(): void
    {
        $t = $this->provisionTenant('Alpha');
        $branch = $this->userWithRole($t, 'Branch', self::ALL, 'branch@alpha.test', onlyAt: $t['franchise']);
        $target = $this->inTenant($t['org'], fn () => Role::create(['organization_id' => $t['org']->id, 'name' => 'Dispatch']));

        $this->signedInAs($branch)->patchJson("/api/v1/roles/{$target->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->signedInAs($branch)->deleteJson("/api/v1/roles/{$target->id}")->assertForbidden();

        $this->assertSame('Dispatch', $this->inTenant($t['org'], fn () => Role::find($target->id)?->name));

        // Control: the organization's own administrator still can.
        $this->signedInAs($t['admin'])->patchJson("/api/v1/roles/{$target->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_an_administrator_standing_in_a_company_view_still_changes_a_role(): void
    {
        // Jose 2026-11-20: refused in the A-1 Chimney view, allowed in the
        // organization view, for the same Administrator. A role is the same
        // record from every view; authority, not the switcher, decides.
        $t = $this->provisionTenant('Alpha');
        $target = $this->inTenant($t['org'], fn () => Role::create(['organization_id' => $t['org']->id, 'name' => 'Dispatch']));

        $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'company:'.$t['company']->id)
            ->patchJson("/api/v1/roles/{$target->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $this->inTenant($t['org'], fn () => Role::find($target->id)?->name));
    }

    public function test_a_branch_user_reaches_only_rules_for_their_branch(): void
    {
        $t = $this->provisionTenant('Alpha');
        $sibling = $this->extraFranchise($t, 'Alpha F2');
        $branch = $this->userWithRole($t, 'Branch', self::ALL, 'branch@alpha.test', onlyAt: $t['franchise']);

        $rule = fn (?string $type, ?string $id, string $name) => $this->inTenant($t['org'], fn () => Automation::create([
            'organization_id' => $t['org']->id, 'scope_type' => $type, 'scope_id' => $id,
            'name' => $name, 'trigger_key' => 'customer.created', 'is_active' => false,
        ]));
        $orgWide = $rule(null, null, 'Org wide');
        $theirs = $rule('franchise', $sibling->id, 'Sibling');
        $mine = $rule('franchise', $t['franchise']->id, 'Mine');

        // An organization rule acts on their branch, so they see it, but cannot change it.
        $this->signedInAs($branch)->getJson("/api/v1/automations/{$orgWide->id}")->assertOk();
        $this->signedInAs($branch)->deleteJson("/api/v1/automations/{$orgWide->id}")->assertForbidden();
        $this->assertNotNull($this->inTenant($t['org'], fn () => Automation::find($orgWide->id)));

        // A sibling branch's rule is not theirs to read or delete.
        $this->signedInAs($branch)->getJson("/api/v1/automations/{$theirs->id}")->assertNotFound();
        $this->signedInAs($branch)->deleteJson("/api/v1/automations/{$theirs->id}")->assertNotFound();

        // Control: their own branch's rule is.
        $this->signedInAs($branch)->deleteJson("/api/v1/automations/{$mine->id}")->assertSuccessful();
    }
}
