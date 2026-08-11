<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\Notification;
use App\Support\Privacy\DeclaringModels;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Retention beyond the audit log (§10).
 *
 * Before this, one table in the system had enforced retention and every other
 * policy was a sentence in a docblock — including two that said out loud there
 * was no policy at all. The tests here are about the difference between a stated
 * number and an enforced one, which is the whole point: the last test in the
 * file is the one that fails when somebody adds a table nobody has decided about.
 */
class RetentionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── 1. every table has an answer, and it is a real one ───────

    public function test_every_table_that_states_retention_states_something_readable(): void
    {
        $models = app(DeclaringModels::class)->statingRetention();

        $this->assertNotEmpty($models);

        foreach ($models as $class) {
            $policy = $class::retention();

            $this->assertInstanceOf(RetentionPolicy::class, $policy);

            // The reason is what makes it a policy rather than a number. It is
            // published in the inventory, so it is written for the person asking.
            $this->assertNotEmpty($policy->because, "{$class} states a window with no reason.");

            $sentence = $policy->sentence();

            $this->assertStringContainsString($policy->because, $sentence);

            if ($policy->isSwept()) {
                // The measured-from column has to exist, or the sweep silently
                // matches nothing — the worst failure a cleanup job can have,
                // because it looks exactly like a clean table.
                $this->assertTrue(
                    Schema::hasColumn((new $class)->getTable(), $policy->column),
                    "{$class} measures retention from {$policy->column}, which does not exist.",
                );

                $this->assertStringContainsString((string) $policy->months, $sentence);
            }
        }
    }

    /**
     * Holding personal data implies stating retention, structurally.
     *
     * `HoldsPersonalData extends StatesRetention`, so this cannot be forgotten —
     * but the assertion is worth keeping, because the inheritance is the design
     * decision and a future edit could quietly split them again.
     */
    public function test_every_table_holding_personal_data_states_its_retention(): void
    {
        foreach (app(DeclaringModels::class)->holdingPersonalData() as $class) {
            $this->assertTrue(is_subclass_of($class, StatesRetention::class), $class);
        }
    }

    // ── 2. the sweep actually removes things ─────────────────────

    public function test_notifications_past_their_window_are_removed_and_recent_ones_are_not(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            Notification::create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $alpha['admin']->getKey(),
                'title' => 'Old news', 'created_at' => now()->subMonths(9),
            ]);

            Notification::create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $alpha['admin']->getKey(),
                'title' => 'Recent', 'created_at' => now()->subMonth(),
            ]);
        });

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $left = $this->inTenant($alpha['org'], fn () => Notification::pluck('title')->all());

        $this->assertSame(['Recent'], $left);
    }

    /**
     * The sweep crosses tenants, deliberately.
     *
     * It runs on a schedule with no organization in context. With the tenant
     * global scope in place it would match nothing at all in every organization
     * and report success — a cleanup job that silently does nothing is worse
     * than one that crashes.
     */
    public function test_the_sweep_reaches_every_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        foreach ([$alpha, $beta] as $tenant) {
            $this->inTenant($tenant['org'], fn () => Notification::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $tenant['admin']->getKey(),
                'title' => 'Old', 'created_at' => now()->subMonths(9),
            ]));
        }

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $this->assertSame(0, Notification::withoutGlobalScopes()->count());
    }

    public function test_invitations_past_two_years_are_removed(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            foreach ([['old', 30], ['new', 3]] as [$label, $months]) {
                // `token_hash` is deliberately not fillable — it is the
                // credential, and only App\Services\Hr\Invitations ever sets one.
                // forceFill is how a test writes one without opening that door.
                (new Invitation)->forceFill([
                    'organization_id' => $alpha['org']->id,
                    'email' => "{$label}@example.test",
                    'token_hash' => hash('sha256', $label),
                    'role_id' => $alpha['admin']->role_id,
                    'invited_by_user_id' => $alpha['admin']->getKey(),
                    'assigned_franchise_id' => $alpha['franchise']->id,
                    'sent_at' => now()->subMonths($months),
                    'expires_at' => now()->subMonths($months)->addWeek(),
                    'created_at' => now()->subMonths($months),
                    'updated_at' => now()->subMonths($months),
                ])->save();
            }
        });

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $left = $this->inTenant($alpha['org'], fn () => Invitation::pluck('email')->all());

        $this->assertSame(['new@example.test'], $left);
    }

    // ── 3. what the sweep must NOT do ────────────────────────────

    /**
     * Two commands deleting from one table on different rules is a bug waiting
     * for the night they disagree.
     *
     * `audit_events` declares its retention as enforced elsewhere, because it is
     * four windows keyed on who acted and a one-column sweep cannot express that.
     */
    public function test_the_sweep_leaves_the_audit_log_to_its_own_command(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], fn () => AuditEvent::create([
            'organization_id' => $alpha['org']->id,
            'actor_type' => AuditEvent::ACTOR_SYSTEM, 'actor_label' => 'system',
            'action' => 'something.happened', 'occurred_at' => now()->subYears(5),
        ]));

        $before = AuditEvent::withoutGlobalScopes()->count();

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $this->assertSame($before, AuditEvent::withoutGlobalScopes()->count(),
            'ezbizi:expire-data deleted audit rows. That table states that '
            .'ezbizi:expire-audit is what enforces it, and two sweeps on one table with '
            .'different rules is the shape of a bug.');

        $this->assertSame(RetentionPolicy::ELSEWHERE, AuditEvent::retention()->kind);
    }

    /**
     * An undelivered event is work still owed, not history.
     *
     * The automation outbox is written inside the business transaction, so a row
     * that has never been delivered is a business change the dispatcher still
     * has to act on. Expiring it by age would drop that change on the floor.
     */
    public function test_an_undelivered_automation_event_survives_its_window(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$pending, $delivered] = $this->inTenant($alpha['org'], fn () => [
            $this->anEvent($alpha, 'never.delivered', deliveredAt: null),
            $this->anEvent($alpha, 'long.done', deliveredAt: now()->subYear()),
        ]);

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $left = AutomationEvent::withoutGlobalScopes()->pluck('event_key')->all();

        $this->assertContains('never.delivered', $left,
            'An event that was never delivered is work still owed, and expiring it drops a '
            .'business change silently.');

        $this->assertNotContains('long.done', $left);
    }

    /**
     * A run cannot outlive the event it explains.
     *
     * AutomationRun declares `withParent` rather than a window of its own so
     * there is one number governing one history. The cascade is what enforces it.
     */
    public function test_expiring_an_event_takes_its_runs_with_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $event = $this->anEvent($alpha, 'old.thing', deliveredAt: now()->subYear());

            $automation = Automation::create([
                'organization_id' => $alpha['org']->id,
                'name' => 'A rule', 'trigger_key' => 'old.thing', 'is_active' => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $alpha['org']->id,
                'automation_id' => $automation->getKey(), 'version' => 1,
                'trigger_key' => 'old.thing', 'conditions' => [], 'actions' => [],
            ]);

            AutomationRun::create([
                'organization_id' => $alpha['org']->id,
                'automation_id' => $automation->getKey(),
                'automation_version_id' => $version->getKey(),
                'event_id' => $event->getKey(),
                'status' => AutomationRun::SUCCEEDED,
                'action_count' => 0,
                'started_at' => now()->subYear(),
            ]);
        });

        $this->assertSame(1, AutomationRun::withoutGlobalScopes()->count());

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $this->assertSame(0, AutomationRun::withoutGlobalScopes()->count(),
            'The run outlived the event it explains, which leaves "the rule fired" with no '
            .'record of what it fired about.');
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], fn () => Notification::create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->getKey(),
            'title' => 'Old', 'created_at' => now()->subYears(2),
        ]));

        $this->artisan('ezbizi:expire-data --dry-run')->assertSuccessful();

        $this->assertSame(1, Notification::withoutGlobalScopes()->count());
    }

    // ── 4. the test that earns its place ─────────────────────────

    /**
     * A table that grows without limit and says nothing about it.
     *
     * This is the §10 equivalent of the inventory's own last test, and it exists
     * for the same reason: the failure is invisible. A table missing from a
     * cleanup mechanism does not error — it simply grows, and nobody finds out
     * until the disk does.
     *
     * The tell is derived rather than listed: any tenant-owned table whose rows
     * are EVENTS — it has a created_at or an occurred_at and nothing ever updates
     * it in place — accumulates one row per thing that happens. Those must
     * declare a policy, even if the policy is `forever()` with a reason.
     */
    public function test_every_model_that_accumulates_rows_has_decided_about_retention(): void
    {
        $undeclared = [];

        foreach (glob(app_path('Models').'/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (is_subclass_of($class, StatesRetention::class)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            /*
             * `UPDATED_AT === null` is the tell, and it is a good one: a model
             * that switches off its updated_at is a model whose rows are written
             * and never revised. That is what an accumulating table looks like,
             * and it is a decision somebody made deliberately on the class rather
             * than something guessed from a name.
             */
            if ($class::UPDATED_AT === null) {
                $undeclared[] = $class;
            }
        }

        $this->assertSame([], $undeclared, sprintf(
            "These tables accumulate a row per event and say nothing about retention:\n  %s\n"
            .'Implement App\Support\Privacy\StatesRetention on each. `forever()` is a valid '
            .'answer and requires a reason — what is not valid is a table nobody has decided '
            .'about, because it does not fail, it just grows.',
            implode("\n  ", $undeclared),
        ));
    }

    /**
     * `delivered_at` is NOT fillable — the dispatcher stamps it, nothing else —
     * so it takes a forceFill. Getting that wrong is what made the first run of
     * this test pass for the wrong reason: the event looked undelivered, the
     * sweep correctly left it alone, and the assertion about expiry failed.
     */
    private function anEvent(array $tenant, string $key, ?\Illuminate\Support\Carbon $deliveredAt): AutomationEvent
    {
        $event = new AutomationEvent;

        $event->forceFill([
            'organization_id' => $tenant['org']->id,
            'event_key'   => $key,
            'payload'     => [],
            'occurred_at' => now()->subYear(),
            'available_at' => now()->subYear(),
            'delivered_at' => $deliveredAt,
        ])->save();

        return $event;
    }
}
