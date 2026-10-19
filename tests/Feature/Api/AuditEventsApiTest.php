<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Settings › Activity (2026-10-16). */
class AuditEventsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function event(array $tenant, string $action, ?string $scopeType, ?string $scopeId, array $extra = []): void
    {
        AuditEvent::create([
            'organization_id' => $tenant['org']->id,
            'actor_type'      => 'user',
            'actor_label'     => 'Dana',
            'action'          => $action,
            'target_label'    => 'Something',
            'scope_type'      => $scopeType,
            'scope_id'        => $scopeId,
            'changes'         => ['big' => str_repeat('x', 1000)],
            'occurred_at'     => now()->subHour(),
            ...$extra,
        ]);
    }

    private function actions(): array
    {
        return collect($this->getJson('/api/v1/audit-events')->assertOk()->json('data'))->pluck('action')->sort()->values()->all();
    }

    public function test_it_needs_audit_view(): void
    {
        $tenant = $this->provisionTenant();

        $this->signedInAs($this->userWithRole($tenant, 'Plain', ['organizations.view']))
            ->getJson('/api/v1/audit-events')->assertForbidden();
    }

    public function test_it_lists_this_organization_only_and_never_the_detail_columns(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');
        $this->event($alpha, 'alpha.done', 'organization', $alpha['org']->id);
        $this->event($beta, 'beta.done', 'organization', $beta['org']->id);

        $this->signedInAs($this->userWithRole($alpha, 'Auditor', ['audit.view']));

        $this->assertSame(['alpha.done'], $this->actions());

        $row = $this->getJson('/api/v1/audit-events')->json('data.0');
        $this->assertSame(['id', 'occurred_at', 'source', 'actor', 'action', 'target'], array_keys($row));
    }

    public function test_a_branch_person_sees_their_branch_not_the_organization_or_a_sibling(): void
    {
        $tenant  = $this->provisionTenant();
        $sibling = $this->extraFranchise($tenant, 'Sibling');
        $this->event($tenant, 'org.done', 'organization', $tenant['org']->id);
        $this->event($tenant, 'mine.done', 'franchise', $tenant['franchise']->id);
        $this->event($tenant, 'sibling.done', 'franchise', $sibling->id);

        $this->signedInAs($this->userWithRole($tenant, 'Branch', ['audit.view'], onlyAt: $tenant['franchise']));

        $this->assertSame(['mine.done'], $this->actions());
    }

    /** Jose, 2026-10-19: A-1 Chimney's Activity said "Nothing in this window" for 90 days. */
    public function test_a_company_view_shows_its_events_and_the_unscoped_ones_to_an_organization_admin(): void
    {
        $tenant = $this->provisionTenant();
        $lead = $this->inTenant($tenant['org'], fn () => \App\Models\Lead::create([
            'organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'source' => 'website_form', 'remote_id' => 'audit-1', 'status' => 'NEW', 'received_at' => now(),
        ]));
        $logged = $this->inTenant($tenant['org'], fn () => app(\App\Support\Audit\AuditLogger::class)->log('lead.updated', $lead));
        $this->assertSame(['franchise', (string) $tenant['franchise']->id], [$logged->scope_type, $logged->scope_id]);

        $this->event($tenant, 'old.unscoped', null, null);
        $this->event($tenant, 'org.done', 'organization', $tenant['org']->id);
        $this->signedInAs($tenant['admin'])->withHeader('X-EzBizi-View', 'company:'.$tenant['company']->id);

        $this->assertSame(['lead.updated', 'old.unscoped', 'org.done'], $this->actions());
    }

    public function test_one_event_opens_with_its_changes_and_not_outside_the_view(): void
    {
        $tenant  = $this->provisionTenant();
        $sibling = $this->extraFranchise($tenant, 'Sibling');
        $this->event($tenant, 'mine.done', 'franchise', $tenant['franchise']->id, ['changes' => ['status' => ['before' => 'a', 'after' => 'b']]]);
        $this->event($tenant, 'sibling.done', 'franchise', $sibling->id);
        $mine  = AuditEvent::query()->where('action', 'mine.done')->value('id');
        $other = AuditEvent::query()->where('action', 'sibling.done')->value('id');

        $this->signedInAs($this->userWithRole($tenant, 'Branch', ['audit.view'], onlyAt: $tenant['franchise']));

        $this->getJson("/api/v1/audit-events/{$mine}")->assertOk()
            ->assertJsonPath('data.action', 'mine.done')
            ->assertJsonPath('data.changes.status.after', 'b');
        $this->getJson("/api/v1/audit-events/{$other}")->assertNotFound();
    }

    public function test_one_event_needs_audit_view(): void
    {
        $tenant = $this->provisionTenant();
        $this->event($tenant, 'x.done', 'organization', $tenant['org']->id);
        $id = AuditEvent::query()->value('id');

        $this->signedInAs($this->userWithRole($tenant, 'Plain', ['organizations.view']))
            ->getJson("/api/v1/audit-events/{$id}")->assertForbidden();
    }

    public function test_the_window_and_source_filter(): void
    {
        $tenant = $this->provisionTenant();
        $this->event($tenant, 'recent.done', 'organization', $tenant['org']->id);
        $this->event($tenant, 'old.done', 'organization', $tenant['org']->id, ['occurred_at' => now()->subDays(10)]);
        $this->event($tenant, 'robot.done', 'organization', $tenant['org']->id, ['actor_type' => 'system']);

        $this->signedInAs($this->userWithRole($tenant, 'Auditor', ['audit.view']));

        $this->assertSame(['recent.done', 'robot.done'], $this->actions());
        $this->assertSame(['old.done', 'recent.done', 'robot.done'], collect($this->getJson('/api/v1/audit-events?window=30d')->json('data'))->pluck('action')->sort()->values()->all());
        $this->assertSame(['robot.done'], collect($this->getJson('/api/v1/audit-events?source=system')->json('data'))->pluck('action')->all());
    }
}
