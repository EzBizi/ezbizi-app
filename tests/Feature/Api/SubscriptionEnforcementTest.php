<?php

namespace Tests\Feature\Api;

use App\Exceptions\SubscriptionLapsedException;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Notification;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowVersion;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use App\Support\Workflows\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subscription enforcement below the edge (plan §13).
 *
 * §13 is explicit that middleware is not sufficient: "A workflow that keeps
 * firing after expiry, or an import that still runs, means the product works
 * fine without paying. Enforce at the service layer, where every path
 * converges."
 *
 * So these deliberately go around HTTP — a queue worker and a direct model write
 * are exactly the paths middleware never sees.
 */
class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        SubscriptionGuard::flush();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = [
            'organization' => $r['organization'],
            'admin'        => $r['administrator'],
            'franchise'    => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->org['organization'], $fn);
    }

    private function lapse(): void
    {
        $this->org['organization']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->org['organization']->refresh();

        SubscriptionGuard::flush();
    }

    private function customer(): Customer
    {
        return $this->inTenant(fn () => Customer::create([
            'owning_franchise_id' => $this->org['franchise']->id,
            'number' => 'C-1', 'name' => 'Person',
        ]));
    }

    // ── the floor ────────────────────────────────────────────────

    public function test_a_lapsed_organization_cannot_write_business_data_at_all(): void
    {
        $this->lapse();

        $this->expectException(SubscriptionLapsedException::class);

        // No request, no middleware. This is the path an import or a console
        // command takes, and it used to be wide open.
        $this->inTenant(fn () => Customer::create([
            'owning_franchise_id' => $this->org['franchise']->id,
            'number' => 'C-1', 'name' => 'Person',
        ]));
    }

    public function test_reading_still_works(): void
    {
        $customer = $this->customer();
        $this->lapse();

        // §13: the owner may still sign in and view everything. Locking somebody
        // out of their own records turns a billing lapse into a support incident.
        $this->assertNotNull($this->inTenant(fn () => Customer::find($customer->id)));
    }

    public function test_a_workflow_cannot_keep_acting_after_expiry(): void
    {
        $customer = $this->customer();

        $job = $this->inTenant(fn () => Job::create([
            'franchise_id' => $this->org['franchise']->id,
            'customer_id'  => $customer->id,
            'number' => 'JOB-1', 'title' => 'A job', 'status' => 'scheduled', 'currency' => 'USD',
        ]));

        $this->inTenant(function () use ($job) {
            $w = Workflow::create([
                'organization_id' => $this->org['organization']->id,
                'name' => 'Touch it', 'trigger_key' => 'job.created', 'is_active' => true,
            ]);

            $v = WorkflowVersion::create([
                'organization_id' => $this->org['organization']->id,
                'workflow_id' => $w->id, 'version' => 1,
                'actions' => [['type' => 'update_field', 'field' => 'title', 'value' => 'Touched']],
                'created_at' => now(),
            ]);

            $w->forceFill(['active_version_id' => $v->id])->save();

            app(\App\Support\Workflows\EventEmitter::class)
                ->emit('job.created', $job, ['after' => ['status' => 'scheduled']]);
        });

        $this->lapse();

        $event = $this->inTenant(fn () => WorkflowEvent::whereNull('delivered_at')->first());

        app(WorkflowEngine::class)->deliver($event);

        // The rule matched and its action was refused. "The product works fine
        // without paying" is precisely what §13 says this must not be.
        $this->assertSame('A job', $this->inTenant(fn () => Job::find($job->id)->title));

        // And the event is finished with, rather than left for the retry sweep
        // to pick up forever — an outbox that never drains is its own outage.
        $this->assertNotNull($event->fresh()->delivered_at);
    }

    // ── what must keep working (§13) ─────────────────────────────

    public function test_a_password_change_still_works(): void
    {
        $this->lapse();

        // Otherwise expiry locks people out permanently — including out of the
        // ability to update billing, which is the one action that fixes this.
        $this->inTenant(function () {
            $user = User::find($this->org['admin']->id);
            $user->forceFill(['password' => 'a-brand-new-password'])->save();
        });

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check(
                'a-brand-new-password',
                User::withoutGlobalScopes()->find($this->org['admin']->id)->password,
            )
        );
    }

    public function test_enrolling_a_second_factor_still_works(): void
    {
        $this->lapse();

        $this->inTenant(function () {
            $user = User::find($this->org['admin']->id);
            app(\App\Support\Auth\TotpEnrolment::class)->begin($user, $user->email);
        });

        $this->assertTrue(
            User::withoutGlobalScopes()->find($this->org['admin']->id)->hasPendingTotp()
        );
    }

    public function test_a_business_field_cannot_ride_along_with_a_password_change(): void
    {
        $this->lapse();

        $this->expectException(SubscriptionLapsedException::class);

        // A single business field mixed into the same save is still a business
        // write. Letting it through because it travelled with a password change
        // is exactly the gap that gets found later.
        $this->inTenant(function () {
            $user = User::find($this->org['admin']->id);
            $user->forceFill(['password' => 'a-brand-new-password', 'name' => 'Renamed'])->save();
        });
    }

    public function test_notifications_still_write(): void
    {
        $this->lapse();

        // Telling somebody their trial has lapsed is how they find out. A system
        // that goes silent at the moment it starts refusing things is one nobody
        // can diagnose.
        $this->inTenant(fn () => Notification::create([
            'organization_id' => $this->org['organization']->id,
            'user_id' => $this->org['admin']->id,
            'title' => 'Your trial has ended', 'created_at' => now(),
        ]));

        $this->assertSame(1, $this->inTenant(fn () => Notification::count()));
    }

    public function test_audit_keeps_recording(): void
    {
        $this->lapse();

        // Satisfied by construction rather than by an exception: AuditEvent
        // deliberately does not use BelongsToTenant, so it never reaches the
        // guard at all.
        app(\App\Support\Audit\AuditLogger::class)->logError('something.happened', ['x' => 1]);

        $this->assertDatabaseHas('audit_events', ['action' => 'something.happened']);
    }

    public function test_staff_can_still_repair_a_lapsed_customers_data(): void
    {
        $customer = $this->customer();
        $this->lapse();

        // Platform scope is exempt (§4.2). Refusing it would make an expired
        // account unsupportable — and staff access is audited regardless.
        app(TenantContext::class)->runWithoutTenant(function () use ($customer) {
            $c = Customer::withoutGlobalScopes()->find($customer->id);
            $c->forceFill(['name' => 'Corrected'])->save();
        });

        $this->assertSame(
            'Corrected',
            Customer::withoutGlobalScopes()->find($customer->id)->name,
        );
    }

    public function test_an_active_organization_is_untouched(): void
    {
        // The guard must be invisible when nothing has lapsed — every other test
        // in this suite depends on that being true.
        $this->assertNotNull($this->customer());
    }

    public function test_password_recovery_survives_the_http_edge_too(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->lapse();

        // §13: "Password & MFA recovery — otherwise expiry locks people out
        // permanently." The model-layer guard permitted this from the first
        // day; the middleware refused it two layers earlier, so the guarantee
        // existed in exactly one of the two places that had to agree.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/me/password', [
                'current_password' => 'a-real-password',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertOk();
    }

    public function test_renaming_yourself_is_still_refused_when_lapsed(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->lapse();

        // The /me/* exemption stops at the sub-resources. A display name is what
        // the rest of the organization sees against somebody's work, which makes
        // it business data rather than account recovery — and an exemption that
        // quietly widened to cover it would be the kind of gap nobody notices.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/me', ['first_name' => 'Renamed', 'last_name' => 'Person'])
            ->assertStatus(402);
    }

    public function test_the_http_edge_still_answers_402(): void
    {
        $this->lapse();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        // The middleware stays: it answers with something a client can render,
        // rather than letting the model layer throw on the way past.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers', [
                'owning_franchise_id' => $this->org['franchise']->id, 'name' => 'Person',
            ])->assertStatus(402);
    }
}
