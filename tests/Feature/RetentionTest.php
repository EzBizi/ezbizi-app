<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\Onboarding;
use App\Support\Privacy\DeclaringModels;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
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
     * `RetentionPolicy::window()` names the tables it governs. It named three of
     * four.
     *
     * R15, in the one place it had already gone wrong. That docblock is where a
     * reader goes to find out what the nightly sweep deletes from, and the change
     * that added `App\Models\PasswordReset` — the entire point of which was that
     * nothing had been sweeping that table — left the sentence beneath saying
     * there were three. A list of examples inside a comment is a second copy of a
     * fact the code already holds, and it drifts in the way nothing notices.
     *
     * Reading a docblock in a test is not a habit worth spreading, and this is
     * the only one that does it. What earns it here is that the comment
     * enumerates a set the code can produce EXACTLY, and enumerates it in order
     * to be relied on. Prose that merely explains is not testable and should not
     * be made to look as though it is.
     *
     * ── And it was reading the wrong thing ───────────────────────
     *
     * The check was `assertStringContainsString($table, $doc)` against the WHOLE
     * docblock, which is prose that discusses tables as well as a list that
     * enumerates them: `automation_events` is named up top as the reason the
     * column is not assumed to be `created_at`, and `password_resets` in the
     * paragraph explaining that the list once said three. Two of the four could
     * therefore be deleted from the enumeration with the test still green —
     * confirmed by deleting "`password_resets` (created_at)" from it, leaving the
     * surrounding prose, and watching this file pass.
     *
     * So the sentence is cut out of the docblock first and every assertion is
     * made against that alone, and each table is required in the form only the
     * list produces: the name, then the column it is measured from, in brackets.
     */
    public function test_this_constructor_names_every_table_it_governs(): void
    {
        $swept = [];

        foreach (app(DeclaringModels::class)->statingRetention() as $class) {
            $policy = $class::retention();

            if ($policy->isSwept()) {
                $swept[(new $class)->getTable()] = $policy->column;
            }
        }

        ksort($swept);

        $this->assertNotEmpty($swept, 'Nothing is swept at all, so this test is comparing '
            .'an empty list against a docblock and would pass whatever it said.');

        $doc = (string) (new ReflectionMethod(RetentionPolicy::class, 'window'))->getDocComment();

        // Flattened to one line, because the docblock is wrapped and a pair split
        // across a line break says exactly what an unsplit one says. The ` * `
        // that opens each line goes with the newline it follows.
        $flat = trim((string) preg_replace(['/\s*\R\s*\*\s?/', '/\s+/'], ' ', $doc));

        $counted = [1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];

        $opening = sprintf('The %s tables this governs today are',
            $counted[count($swept)] ?? count($swept));

        $this->assertStringContainsString($opening, $flat, sprintf(
            'There are %d swept tables (%s) and the docblock on RetentionPolicy::window() '
            .'counts a different number. Update the sentence — or, if the list has grown '
            .'past what a sentence should carry, replace it with a pointer to this test.',
            count($swept), implode(', ', array_keys($swept)),
        ));

        $this->assertSame(1, preg_match('/'.preg_quote($opening, '/').'(.+?)\./', $flat, $found),
            'The sentence enumerating the swept tables has gone from '
            .'RetentionPolicy::window(). This test reads that one sentence and nothing else, '
            .'so mentioning the tables in the prose around it will not do — put the list '
            .'back, or change this test deliberately and say what now keeps it true.');

        $enumerated = $found[1];

        foreach ($swept as $table => $column) {
            $this->assertStringContainsString(sprintf('`%s` (%s)', $table, $column), $enumerated, sprintf(
                'RetentionPolicy::window() deletes from `%s` every night, measured from %s, '
                .'and the sentence enumerating what it governs does not say so. Naming the '
                .'table elsewhere in that docblock does not count: somebody reading the list '
                .'to find out what the sweep touches would not learn that this table is one '
                .'of them.',
                $table, $column,
            ));
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
        $beta = $this->provisionTenant('Beta');

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

    /**
     * The window on `invitations` reaches a table that is declared `forever`.
     *
     * `onboardings.invitation_id` is `nullOnDelete`, so at twenty-four months the
     * sweep does not only remove the invitation — it edits an onboarding whose
     * own retention says it is kept for as long as the agreement it produced
     * matters. That is deliberate (the alternatives are a cascade that lets
     * expiry delete a signed agreement, or a restrict that makes the window
     * unenforceable the moment anybody is hired), and both retention sentences
     * now say so.
     *
     * What this pins is the part that would be a disaster rather than a
     * surprise: the onboarding SURVIVES. A cascade added here by somebody
     * tidying up foreign keys would silently start deleting signed contracts on
     * a nightly schedule, and nothing else in the suite would notice.
     */
    public function test_expiring_an_invitation_keeps_the_onboarding_it_produced(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $onboardingId = $this->inTenant($alpha['org'], function () use ($alpha) {
            $invitation = (new Invitation)->forceFill([
                'organization_id' => $alpha['org']->id,
                'email' => 'hired@example.test',
                'token_hash' => hash('sha256', 'hired'),
                'role_id' => $alpha['admin']->role_id,
                'invited_by_user_id' => $alpha['admin']->getKey(),
                'assigned_franchise_id' => $alpha['franchise']->id,
                'sent_at' => now()->subMonths(30),
                'expires_at' => now()->subMonths(30)->addWeek(),
                'accepted_at' => now()->subMonths(30)->addDay(),
                'created_at' => now()->subMonths(30),
                'updated_at' => now()->subMonths(30),
            ]);

            $invitation->save();

            $contract = Contract::create([
                'organization_id' => $alpha['org']->id,
                'name' => 'Driver',
                'created_by' => $alpha['admin']->getKey(),
            ]);

            $version = ContractVersion::create([
                'organization_id' => $alpha['org']->id,
                'contract_id' => $contract->getKey(),
                'version' => 1,
                'fields' => [],
                'created_by' => $alpha['admin']->getKey(),
            ]);

            return Onboarding::create([
                'organization_id' => $alpha['org']->id,
                'invitation_id' => $invitation->getKey(),
                'contract_version_id' => $version->getKey(),
                'status' => Onboarding::COMPLETED,
                'started_at' => now()->subMonths(30),
                'started_by_user_id' => $alpha['admin']->getKey(),
            ])->getKey();
        });

        $this->artisan('ezbizi:expire-data')->assertSuccessful();

        $this->inTenant($alpha['org'], function () use ($onboardingId) {
            $onboarding = Onboarding::find($onboardingId);

            $this->assertNotNull($onboarding,
                'The invitation sweep deleted a signed agreement. `onboardings.invitation_id` '
                .'must stay nullOnDelete — a cascade turns a retention window on an '
                .'administrative row into nightly deletion of contracts.');

            $this->assertNull($onboarding->invitation_id,
                'The invitation was expected to be gone and its link nulled. If the '
                .'invitation survived, the window on that table is no longer being applied.');

            $this->assertSame(0, Invitation::where('email', 'hired@example.test')->count());
        });
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
     * The test above cannot see a table that has no model, and that is where the
     * hole was.
     *
     * Everything §9 and §10 build is DERIVED by walking `app/Models`: the
     * inventory, the purge, the sweep, and the test above. All four share one
     * blind spot — a table written by a bare `DB::table()` with no Eloquent class
     * is not exempt from any of them, it is invisible to all of them at once, and
     * invisibility does not fail. `password_resets` was exactly that: a row per
     * reset request, naming the person and the address they asked from, marked
     * used rather than deleted, absent from the inventory, untouched by a purge
     * of that person and swept by nothing.
     *
     * So this walks the SCHEMA instead, which is the only list that cannot be
     * short. Every table either belongs to a model that has stated a policy, or
     * is named below with the reason it needs none. The polarity is the point: a
     * table added tomorrow fails this test until somebody decides about it,
     * rather than passing silently because nobody wrote it down.
     *
     * ── That polarity was not real, and this is what it was ──────
     *
     * `$declared` was seeded from `statingRetention()` and then TOPPED UP with
     * every class in app/Models, on the reasoning — written in a comment here —
     * that "a table whose model exists and does not state retention is caught by
     * the test above". It is not. The sibling test only fires on a table with an
     * `organization_id` whose model sets `UPDATED_AT = null`, and fifteen tables
     * had a model, no policy, and failed one or both of those conditions: they
     * were caught by nothing at all. What this test actually asserted was "every
     * table has a MODEL", which is a much weaker sentence than the one above it
     * and reads identically when it passes.
     *
     * `$declared` is now the declaring models and nothing else, so a table with
     * a model and no policy lands in the exception list or fails — and the
     * exception list is checked for staleness, which means the day somebody
     * declares a policy on one of these the entry excusing it starts failing.
     *
     * ── The second list is gone, because the decision arrived ────
     *
     * `staff_impersonations` had a list to itself here, excused as awaiting the
     * owner while "retained 12 months (§10)" sat in the model's docblock enforced
     * by nothing. The owner decided, and the answer is that it is kept: it is the
     * trail of platform staff reaching into a client's records, so
     * StaffImpersonation states `forever()` and the table is now covered by
     * `$declared` like any other. `assignments` left the exception list the same
     * way — see Assignment::retention(), which also records that the reason it
     * used to be excused with, rows being "deleted when the access is taken
     * away", matched no code in this repository. The staleness check below is
     * what would have failed had either entry been left behind.
     */
    public function test_every_table_in_the_schema_has_been_decided_about(): void
    {
        /*
         * Tables that need no retention policy, listed with why.
         *
         * A short list of EXCEPTIONS is a different artefact from a list of
         * what is covered: this one defaults to failing, so forgetting to edit
         * it is the safe direction.
         */
        $needsNoPolicy = [
            // Laravel's own plumbing. Each row is transient by construction —
            // written, consumed and removed by the framework — and none of them
            // is a record of anybody's dealings with an organization.
            'migrations' => 'the ledger of which migrations have run',
            'cache' => 'framework cache, evicted by its own TTL',
            'cache_locks' => 'framework cache locks, released on use',
            'queue_jobs' => 'pending queue payloads, deleted as they are processed. Named '
                .'`queue_jobs` rather than `jobs` because `jobs` is the business table — see '
                .'the rename migration, which exists so the two can never be confused.',
            'job_batches' => 'queue batch bookkeeping, pruned by queue:prune-batches',
            'failed_jobs' => 'failed queue payloads, pruned by queue:prune-failed',
            'workiz_job_flags' => 'which Workiz jobs were already flagged, keyed to the mirror row and '
                .'deleted with it (cascade); no person in it',

            'password_reset_tokens' => 'Laravel\'s email-keyed reset table, which this '
                .'application does not use: an address is not an account here (§4.3), so '
                .'App\Services\Auth\PasswordResets writes `password_resets` instead — a '
                .'table that names a subject. Nothing reaches the framework password broker, '
                .'so no row is ever written to this one.',

            'sessions' => 'Laravel\'s database session table. Sign-in is by bearer token '
                .'(`personal_access_tokens`, declared by ApiToken); the `web` guard is '
                .'configured because Laravel ships it, and nothing ever authenticates '
                .'through it — there is no Auth::login and no route behind it. So '
                .'`sessions.user_id` is null on every row and what is here is anonymous '
                .'visitors to the public page: nobody to list it under and nobody to erase '
                .'it for, which is why it is named in DataInventory::caveats rather than in '
                .'the inventory.',

            // A join table: no row about a person, and each goes with the role
            // whose grant it records.
            'permission_role' => 'a join table; rows go with the role',

            // One counter per organization per record kind, updated in place.
            // It does not accumulate and it names nobody.
            'record_sequences' => 'the next number for each kind of record, one row per '
                .'counter, incremented rather than appended to',

            /*
             * ── THE TENANT'S OWN STRUCTURE AND CONFIGURATION ─────
             *
             * Each of these has a model and states no policy, and until this
             * test was narrowed that alone was enough to pass. What they have
             * in common is the shape of the answer rather than the answer
             * itself: one row per THING the organization has, edited in place
             * for as long as that thing exists, and removed by the cascade when
             * the organization is. There is no age at which a live one stops
             * being current, so there is nothing for a sweep to do — which is a
             * different statement from "nobody has looked".
             */
            'organizations' => 'the tenant itself. Every other retention window is measured '
                .'inside one of these rows, and removing it is what removes them — a sweep '
                .'here would not be retention, it would be deleting clients on a timer',
            'companies' => 'a level of the organization\'s own structure (§3): one row per '
                .'company, edited in place, soft-deleted when the client closes one and gone '
                .'with the tenant',
            'franchises' => 'the operating units of that same structure (§5), on exactly the '
                .'terms above. Whatever the organization has renamed them to (§8)',
            'roles' => 'the permission sets an organization composed for itself (§4). '
                .'One row per role, edited in place; it names nobody, and it grows only when '
                .'somebody creates a role',
            'settings' => 'one override per setting per scope (§6), overwritten when the '
                .'value changes rather than appended to. Absence means inherit, so a stale '
                .'row is a wrong answer rather than clutter, and it is corrected rather than '
                .'expired',
            'automations' => 'the rule, not its history: a name, a trigger and a pointer at '
                .'the version that is live (§5.5). The three tables that accumulate beneath '
                .'it — automation_versions, automation_runs, automation_events — each state a '
                .'policy of their own',
            'contracts' => 'the template, never the signed thing. `contract_versions` and '
                .'`contract_signatures` are the records that matter and both state a policy; '
                .'this row is a name and a pointer at the current version',

            // `jobs` used to be argued here as a business record that holds no
            // person. It no longer does: `performed_by_user_id` (spec §5, §7,
            // §8.1) put an employee's identity directly on the row, so the
            // premise this entry rested on is false and the table decides about
            // itself now — see Job::retention(). Left OUT of this list on
            // purpose, not edited in place: the staleness check below is what
            // catches an exception that excuses a table which no longer needs
            // excusing, and leaving a rewritten sentence here would defeat it
            // the same way the stale `staff_impersonations` entry once did.

            /*
             * ── EZBIZI'S OWN TABLES, NOT ANY TENANT'S ───────────
             *
             * No organization_id, not reachable from a tenant, and none of them
             * a log: each is a current-state row that is overwritten or removed
             * rather than appended to.
             */
            'permissions' => 'the list of what the software can do (§2.1). It is code kept in '
                .'a table and synced by ezbizi:sync-permissions; a client composes these into '
                .'roles and cannot invent one',
            'platform_help_videos' => 'one row per module, holding the how-to video behind '
                .'its tab (UI queue 81), changed in place. `module_key` is unique, so it is '
                .'bounded by the number of modules. `changed_by` is a PlatformStaff label '
                .'overwritten by the next change; the audit log carries the history',
            'platform_module_flags' => 'one row per module, holding EzBizi\'s own '
                .'availability switch (§2.1 layer 3), changed in place',
            'platform_minimum_client_versions' => 'one row per platform, holding the oldest '
                .'installed client EzBizi will answer, changed in place. `platform` is unique, '
                .'so it is bounded by the number of platforms that exist rather than by time. '
                .'`changed_by` is a PlatformStaff label overwritten by the next change, not a '
                .'history — the audit log carries that, and is swept on its own schedule',
            'platform_subscription_defaults' => 'one row, holding the platform\'s default '
                .'trial and subscription numbers (§13), changed in place. The expiry that '
                .'binds a client is `organizations.trial_ends_at`, not this',
            'platform_staff_links' => 'one row per staff account that is also a user inside '
                .'an organization (§4.2). It states that two identities are one person while '
                .'that is true, and is deleted when it stops being true',
            /*
             * `platform_staff` does hold personal data — a name, an address, a
             * last-login IP — and it is named here on RETENTION grounds only:
             * it is a live account record, one row per person, edited in place
             * and soft-deleted when somebody leaves, so there is nothing
             * accumulating for a sweep to reach. Whether EzBizi's own staff
             * records belong in the §9 inventory is a separate question from
             * this test's, and it is not answered by this entry.
             */
            'platform_staff' => 'EzBizi\'s own staff accounts (§4.2): one row per person, '
                .'edited in place and soft-deleted when they leave. An account record rather '
                .'than a log, so it does not accumulate',
        ];

        $undecided = [];

        $declared = [];

        foreach (app(DeclaringModels::class)->statingRetention() as $class) {
            $declared[(new $class)->getTable()] = $class;
        }

        $database = DB::connection()->getDatabaseName();

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (($table['schema'] ?? $database) !== $database) {
                continue;   // another sandbox on the same server
            }

            if (isset($declared[$name]) || isset($needsNoPolicy[$name])) {
                continue;
            }

            $undecided[] = $name;
        }

        sort($undecided);

        /*
         * The list has to stay true as well.
         *
         * An entry naming a table that has since stated a policy, or one that no
         * longer exists, is a sentence asserting something about this schema
         * that is not so — and the first draft of this list had one: it excused
         * `jobs` as the queue table, which it is not. A stale exception is also
         * how a list of exceptions quietly turns into a list of everything.
         *
         * The staleness check is what makes narrowing `$declared` safe rather
         * than merely stricter. Put the app/Models top-up back and every entry
         * added for a table that HAS a model — thirteen of them today — is
         * immediately excusing a table this test already counts as covered, and
         * this assertion says so by name.
         */
        $stale = [];

        foreach (array_keys($needsNoPolicy) as $name) {
            if (isset($declared[$name])) {
                $stale[] = "{$name} (states retention now: {$declared[$name]})";
            } elseif (! Schema::hasTable($name)) {
                $stale[] = "{$name} (no such table)";
            }
        }

        $this->assertSame([], $stale, sprintf(
            "The exception list in this test excuses tables that do not need excusing:\n  %s",
            implode("\n  ", $stale),
        ));

        $this->assertSame([], $undecided, sprintf(
            "These tables exist and nothing has decided anything about them:\n  %s\n"
            .'A table with no policy is invisible to ezbizi:expire-data, and one with no '
            .'model at all is invisible to the §9 inventory and to the purge as well — not '
            .'exempt from them. Implement App\Support\Privacy\StatesRetention on its model '
            .'(and HoldsPersonalData if a person is in it), or add it to the exception list '
            .'in this test WITH the reason it needs no policy.',
            implode("\n  ", $undecided),
        ));
    }

    /**
     * `delivered_at` is NOT fillable — the dispatcher stamps it, nothing else —
     * so it takes a forceFill. Getting that wrong is what made the first run of
     * this test pass for the wrong reason: the event looked undelivered, the
     * sweep correctly left it alone, and the assertion about expiry failed.
     */
    private function anEvent(array $tenant, string $key, ?Carbon $deliveredAt): AutomationEvent
    {
        $event = new AutomationEvent;

        $event->forceFill([
            'organization_id' => $tenant['org']->id,
            'event_key' => $key,
            'payload' => [],
            'occurred_at' => now()->subYear(),
            'available_at' => now()->subYear(),
            'delivered_at' => $deliveredAt,
        ])->save();

        return $event;
    }
}
