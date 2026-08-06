<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Organization;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The audit log (plan §10, review R-11).
 *
 * The two behaviours that carry the weight: it is written inside the business
 * transaction, and it cannot be edited afterwards.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private AuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = app(AuditLogger::class);
    }

    private function provision(string $name = 'Alpha'): array
    {
        return app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => strtolower($name).'@example.com', 'password' => 'pw-here'],
            companyName:   'Co',
            franchiseName: 'F1',
        );
    }

    // ── append-only ──────────────────────────────────────────────

    public function test_an_audit_event_cannot_be_updated(): void
    {
        ['organization' => $org] = $this->provision();
        $event = app(TenantContext::class)->run($org, fn () => $this->audit->log('company.created'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        // Correcting history means writing a new event, never editing an old one.
        $event->update(['action' => 'something.else']);
    }

    public function test_an_audit_event_cannot_be_deleted(): void
    {
        ['organization' => $org] = $this->provision();
        $event = app(TenantContext::class)->run($org, fn () => $this->audit->log('company.created'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $event->delete();
    }

    public function test_there_is_no_updated_at_column(): void
    {
        // A row that records when it was last modified is not an audit record.
        $this->assertFalse(
            \Schema::hasColumn('audit_events', 'updated_at'),
            'audit_events must have no updated_at — it would imply rows can change.'
        );
    }

    // ── transactional (R-11) ─────────────────────────────────────

    public function test_the_audit_row_rolls_back_with_the_business_change(): void
    {
        ['organization' => $org] = $this->provision();

        try {
            DB::transaction(function () use ($org) {
                app(TenantContext::class)->run($org, function () use ($org) {
                    Company::create(['organization_id' => $org->id, 'name' => 'Doomed']);
                    $this->audit->log('company.created');

                    throw new RuntimeException('business failure');
                });
            });
        } catch (RuntimeException) {
            // expected
        }

        // Had the audit write been queued after commit, this would have logged a
        // creation that never happened.
        $this->assertSame(0, AuditEvent::where('action', 'company.created')->count());
        $this->assertNull(Company::withoutGlobalScopes()->where('name', 'Doomed')->first());
    }

    public function test_the_audit_row_commits_with_the_business_change(): void
    {
        ['organization' => $org] = $this->provision();

        DB::transaction(function () use ($org) {
            app(TenantContext::class)->run($org, function () use ($org) {
                $company = Company::create(['organization_id' => $org->id, 'name' => 'Kept']);
                $this->audit->log('company.created', $company);
            });
        });

        $event = AuditEvent::where('action', 'company.created')->first();
        $this->assertNotNull($event);
        $this->assertSame('Kept', $event->target_label);
        $this->assertSame($org->id, $event->organization_id);
    }

    // ── redaction ────────────────────────────────────────────────

    public function test_secrets_are_redacted(): void
    {
        ['organization' => $org] = $this->provision();

        $event = app(TenantContext::class)->run($org, fn () => $this->audit->log(
            'user.updated',
            metadata: [
                'email'                 => 'keep@example.com',
                'password'              => 'hunter2',
                'password_confirmation' => 'hunter2',
                'stripe_api_key'        => 'sk_live_xxx',
                'remember_token'        => 'abc',
            ]
        ));

        $this->assertSame('keep@example.com', $event->metadata['email']);
        foreach (['password', 'password_confirmation', 'stripe_api_key', 'remember_token'] as $key) {
            $this->assertSame('[redacted]', $event->metadata[$key], "{$key} leaked into the audit log");
        }
    }

    public function test_redaction_reaches_nested_values(): void
    {
        ['organization' => $org] = $this->provision();

        $event = app(TenantContext::class)->run($org, fn () => $this->audit->log(
            'user.updated',
            changes: ['before' => ['password' => 'old'], 'after' => ['password' => 'new']]
        ));

        // Secrets nest inside before/after — a shallow scan would miss them.
        $this->assertSame('[redacted]', $event->changes['before']['password']);
        $this->assertSame('[redacted]', $event->changes['after']['password']);
    }

    // ── who did it ───────────────────────────────────────────────

    public function test_the_actor_label_survives_the_actor_being_deleted(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->provision();

        $event = app(TenantContext::class)->run($org,
            fn () => $this->audit->log('company.created', actor: $admin)
        );

        app(TenantContext::class)->run($org, fn () => $admin->delete());

        // "Who did this?" is asked most often about people who have since left.
        $this->assertStringContainsString('alpha@example.com', $event->fresh()->actor_label);
    }

    public function test_events_without_an_actor_are_recorded_as_system(): void
    {
        ['organization' => $org] = $this->provision();

        $event = app(TenantContext::class)->run($org, fn () => $this->audit->log('workflow.ran'));

        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $event->actor_type);
        $this->assertSame('system', $event->actor_label);
    }

    public function test_related_events_share_a_correlation_id(): void
    {
        ['organization' => $org] = $this->provision();

        [$a, $b] = app(TenantContext::class)->run($org, fn () => [
            $this->audit->log('a.happened'),
            $this->audit->log('b.happened'),
        ]);

        // A workflow firing five actions must be reconstructable as one chain.
        $this->assertSame($a->correlation_id, $b->correlation_id);
    }

    // ── the Lead Developer exception (owner's decision) ──────────

    public function test_lead_developer_activity_is_not_audited(): void
    {
        ['organization' => $org] = $this->provision();

        app(TenantContext::class)->run($org, fn () => $this->audit->suppressForLeadDeveloper(
            fn () => $this->audit->log('company.created')
        ));

        $this->assertSame(0, AuditEvent::where('action', 'company.created')->count());
    }

    public function test_errors_are_recorded_even_for_the_lead_developer(): void
    {
        ['organization' => $org] = $this->provision();

        app(TenantContext::class)->run($org, fn () => $this->audit->suppressForLeadDeveloper(
            fn () => $this->audit->logError('crash.unhandled', ['message' => 'boom'])
        ));

        // The explicit carve-out — a crash nobody logged is a crash nobody fixes.
        $this->assertSame(1, AuditEvent::where('action', 'crash.unhandled')->count());
    }

    public function test_suppression_does_not_leak_past_its_callback(): void
    {
        ['organization' => $org] = $this->provision();

        app(TenantContext::class)->run($org, function () {
            $this->audit->suppressForLeadDeveloper(fn () => $this->audit->log('hidden.action'));
            $this->audit->log('visible.action');
        });

        $this->assertSame(0, AuditEvent::where('action', 'hidden.action')->count());
        $this->assertSame(1, AuditEvent::where('action', 'visible.action')->count());
    }

    public function test_staff_activity_is_always_recorded(): void
    {
        ['organization' => $org] = $this->provision();

        $event = app(TenantContext::class)->run($org, fn () => $this->audit->logStaff(
            'staff.impersonation_started', 'staff-1', 'Dev <dev@ezbizi.com>'
        ));

        $this->assertSame(AuditEvent::ACTOR_STAFF, $event->actor_type);
        $this->assertSame('Dev <dev@ezbizi.com>', $event->actor_label);
    }

    // ── retention (§10) ──────────────────────────────────────────

    public function test_retention_keeps_staff_events_far_longer_than_business_events(): void
    {
        ['organization' => $org] = $this->provision();

        app(TenantContext::class)->run($org, function () {
            $this->audit->log('company.created');
            $this->audit->logStaff('staff.viewed_records', 's1', 'Dev');
        });

        // Age both by two months: past the 1-month business window, well inside
        // the 12-month staff window.
        DB::table('audit_events')->update(['occurred_at' => now()->subMonths(2)]);

        $this->artisan('ezbizi:expire-audit')->assertSuccessful();

        $this->assertSame(0, AuditEvent::where('action', 'company.created')->count());
        $this->assertSame(1, AuditEvent::where('action', 'staff.viewed_records')->count());
    }

    public function test_retention_leaves_recent_events_alone(): void
    {
        ['organization' => $org] = $this->provision();
        app(TenantContext::class)->run($org, fn () => $this->audit->log('company.created'));

        $this->artisan('ezbizi:expire-audit')->assertSuccessful();

        $this->assertSame(1, AuditEvent::where('action', 'company.created')->count());
    }

    public function test_dry_run_removes_nothing(): void
    {
        ['organization' => $org] = $this->provision();
        app(TenantContext::class)->run($org, fn () => $this->audit->log('company.created'));
        DB::table('audit_events')->update(['occurred_at' => now()->subMonths(6)]);

        $this->artisan('ezbizi:expire-audit --dry-run')->assertSuccessful();

        $this->assertSame(1, AuditEvent::count());
    }
}
