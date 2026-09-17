<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\ContractVersion;
use App\Models\Franchise;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\Onboarding;
use App\Models\Role;
use App\Models\User;
use App\Support\Automations\AutomationEngine;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Human Resources, in the automations system (plan §5.5).
 *
 * The owner's standing rule: *"every new thing that gets added or removed from
 * the app needs to work with the automations system"*. HR shipped five tabs and
 * declared an exemption, so an organization could not build a rule on an
 * invitation lapsing, a contract being signed or a template going live.
 *
 * What is proved here, in the order it matters:
 *
 *   1. every event the module DECLARES as raised is actually written to the
 *      outbox by the flow that claims to raise it — read off the registry, so a
 *      trigger declared next year and never wired fails this test rather than
 *      sitting on somebody's screen promising to fire
 *   2. those events carry the SCOPE they happened in, so a franchise-level rule
 *      does not see another franchise's new starter
 *   3. the gating is not weakened: the catalogue does not describe HR to
 *      somebody whose role cannot reach the HR tab
 *   4. the actions the module declares ahead of the engine are marked as such
 *
 * `ModuleRegistryTest` already checks the `isRaised` claim against the source,
 * and it cannot be enough on its own here: it looks for the key as a STRING in
 * app/, and every one of these keys is also an audit action with the same name.
 * So that test would pass on the audit entry alone. This one watches the outbox.
 */
class HrAutomationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The document the signature is taken over is written through FileStore,
        // which reads `filesystems.default` — `local` under test.
        Storage::fake('local');
    }

    // ── 1. the events are real ───────────────────────────────────

    /**
     * Every HR trigger that says it fires, fires.
     *
     * Driven off the REGISTRY rather than off a list written here, which is the
     * whole point: adding a trigger to the module and forgetting to emit it
     * fails this test, and that is the failure the §5.5 standing rule exists to
     * catch. The one declared `isRaised: false` is skipped and separately
     * asserted to be absent below, so the exclusion cannot quietly grow.
     */
    public function test_every_hr_event_declared_as_raised_reaches_the_outbox(): void
    {
        $alpha = $this->wholeHrLifecycle();

        $emitted = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->pluck('event_key')->unique()->values()->all());

        $declared = $this->hrTriggers();

        $this->assertNotEmpty($declared, 'Human Resources declares no triggers at all.');

        foreach ($declared as $trigger) {
            if (! $trigger->isRaised) {
                continue;
            }

            $this->assertContains(
                $trigger->key,
                $emitted,
                "Human Resources declares “{$trigger->label}” as something that happens, and a "
                .'complete run through invite → accept → onboard → sign → amend, plus '
                .'publishing a contract version and withdrawing an invitation, never wrote it '
                .'to the outbox. A rule built on it would validate, save, switch on and wait '
                .'forever.',
            );
        }
    }

    /** The exclusion above, held to what it says. */
    public function test_the_trigger_declared_as_not_yet_raised_is_the_only_one_missing(): void
    {
        $notRaised = array_values(array_filter(
            $this->hrTriggers(),
            fn (TriggerDefinition $t) => ! $t->isRaised,
        ));

        $this->assertSame(
            ['invitation.expired'],
            array_map(fn (TriggerDefinition $t) => $t->key, $notRaised),
            'The set of HR triggers nothing raises has changed. Either something now raises '
            .'one of them — in which case drop the flag, because the tenant is still being told '
            .'the rule will not run — or a new one was declared without a producer.',
        );

        // And the sentence a person reads says so, rather than the trigger being
        // hidden. A hidden trigger is one nobody can build a rule on; a listed
        // one with no warning is one they build and never hear from again.
        $this->assertStringContainsString(
            TriggerDefinition::NOT_RAISED_YET,
            $notRaised[0]->description,
        );
    }

    /**
     * The event carries the RECORD, not just the fact.
     *
     * Which record it is decides everything downstream — the scope a rule
     * matches on, what a condition can read, what a webhook is told — so the
     * subject is asserted per event rather than trusting that "an event exists".
     */
    public function test_each_hr_event_carries_the_record_it_is_about(): void
    {
        $alpha = $this->wholeHrLifecycle();

        $subjects = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->get()
            ->groupBy('event_key')
            ->map(fn ($events) => $events->first()->subject_type)
            ->all());

        $this->assertSame(Invitation::class, $subjects['invitation.sent'] ?? null);
        $this->assertSame(Invitation::class, $subjects['invitation.accepted'] ?? null);
        $this->assertSame(Invitation::class, $subjects['invitation.revoked'] ?? null);
        $this->assertSame(Onboarding::class, $subjects['onboarding.started'] ?? null);
        $this->assertSame(Onboarding::class, $subjects['onboarding.signed'] ?? null);
        $this->assertSame(Onboarding::class, $subjects['onboarding.completed'] ?? null);
        $this->assertSame(Onboarding::class, $subjects['onboarding.amended'] ?? null);
        $this->assertSame(Onboarding::class, $subjects['onboarding.cancelled'] ?? null);

        // The VERSION, never the contract. A contract row is a name and a
        // pointer at whatever is current, so an event whose subject were the
        // contract would resolve months later to a document nobody published
        // when this fired.
        $this->assertSame(
            ContractVersion::class,
            $subjects['contract.version_published'] ?? null,
        );
    }

    /**
     * "When the new starter signs" is a condition on the party, not an event.
     *
     * The module says so in the trigger's own description, so the payload has to
     * carry the word a rule would test — otherwise the sentence on screen is
     * advice nobody can follow.
     */
    public function test_a_signature_event_says_which_side_signed(): void
    {
        $alpha = $this->wholeHrLifecycle();

        $parties = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'onboarding.signed')
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (AutomationEvent $e) => $e->payload['after']['party'] ?? null)
            ->all());

        $this->assertSame(['employer', 'employee'], $parties);

        $last = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'onboarding.signed')
            ->orderByDesc('occurred_at')
            ->first());

        // The last signature says the ceremony is over, so a rule does not have
        // to wait for the separate completion event to know.
        $this->assertTrue($last->payload['after']['fully_signed']);
    }

    // ── 2. scope ─────────────────────────────────────────────────

    /**
     * A franchise-level rule does not fire on another franchise's onboarding.
     *
     * The failure this is written against is not theoretical. AutomationEngine
     * finds an event's scope by reading `franchise_id` off the subject, and an
     * onboarding has no such column — its placement comes from the invitation.
     * Without CarriesAutomationScope every HR event arrives claiming no scope,
     * which reads to `coversScope` as "organization-wide only", so BOTH rules
     * below would skip and the assertion that F1's rule ran is what catches it.
     */
    public function test_an_onboarding_event_reaches_only_the_franchise_it_happened_in(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $second = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));

        $mine     = $this->rule($alpha, 'onboarding.completed', 'franchise', $alpha['franchise']->id);
        $theirs   = $this->rule($alpha, 'onboarding.completed', 'franchise', $second->id);
        $anywhere = $this->rule($alpha, 'onboarding.completed', null, null);

        $this->wholeHrLifecycle($alpha);
        $this->drain($alpha);

        $this->assertSame(1, $this->runsOf($alpha, $mine),
            'The rule scoped to the franchise the new starter was invited into never ran.');

        $this->assertSame(0, $this->runsOf($alpha, $theirs),
            'A franchise-level rule fired on another franchise’s new starter.');

        $this->assertSame(1, $this->runsOf($alpha, $anywhere));
    }

    /**
     * And a company-scoped rule covers an invitation issued at that company.
     *
     * `coversScope` reaches a company through the chain a franchise sits in, so
     * before HR every scope that arrived here had a franchise underneath it. An
     * invitation can be issued at COMPANY level and has no franchise at all —
     * the chain has to be built from the company itself or the rule is saved,
     * switched on and never matches anything.
     */
    public function test_a_company_scoped_rule_covers_an_invitation_issued_at_that_company(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $atCompany = $this->rule($alpha, 'invitation.sent', 'company', $alpha['company']->id);

        $this->invite($alpha, 'company', $alpha['company']->id, 'atco@example.test');
        $this->drain($alpha);

        $this->assertSame(1, $this->runsOf($alpha, $atCompany));
    }

    /**
     * Telling somebody about a record they cannot open is a leak.
     *
     * ActionRunner says the audience for a notification is "whoever could have
     * seen the record anyway", and NotificationDispatcher worked that out by
     * looking for a franchise column — which an onboarding does not have, so it
     * fell through to the branch that tells EVERY ACTIVE USER. With HR raising
     * events that branch is reachable from a tenant-authored rule, and what it
     * would announce to the whole business is a named person's contract.
     */
    public function test_a_notification_about_an_onboarding_reaches_only_people_who_can_open_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));

        // Somebody who works at the other branch and holds every HR permission
        // there is. Reach, not permission, is what must exclude them.
        $outsider = $this->userWithRole(
            $alpha, 'Other branch', ['hr.view', 'hr.create', 'hr.edit'],
            'other@alpha.test', onlyAt: $elsewhere,
        );

        $this->rule($alpha, 'onboarding.completed', null, null, actions: [
            ['type' => 'notify', 'message' => 'The paperwork is finished.'],
        ]);

        $this->wholeHrLifecycle($alpha);
        $this->drain($alpha);

        $told = $this->inTenant($alpha['org'], fn () => Notification::query()
            ->where('source', 'automation')->pluck('user_id')->all());

        $this->assertNotEmpty($told, 'Nobody was told at all, so this proves nothing.');

        $this->assertNotContains(
            $outsider->getKey(),
            $told,
            'Somebody at another branch was told a named person’s contract had been signed.',
        );
    }

    // ── 3. gating ────────────────────────────────────────────────

    /**
     * The catalogue does not describe HR to somebody who cannot reach the tab.
     *
     * The tab IS the view permission (§4), and this endpoint returns the wording
     * modules write about themselves — that contracts are signed here, that
     * invitations lapse after a week, that amendments carry a reason. That is
     * real information about the business, and `automations.view` is not the
     * permission that governs it.
     */
    public function test_human_resources_is_absent_from_the_catalogue_without_the_hr_tab(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $builder = $this->userWithRole($alpha, 'Rule builder', [
            'automations.view', 'automations.create', 'automations.build.view', 'jobs.view',
        ]);

        $modules = $this->modulesInCatalogue($builder);

        $this->assertNotContains('hr', $modules,
            'Somebody who cannot open Human Resources was shown what happens inside it.');

        // And the rest of the catalogue is unaffected, so this is a filter and
        // not an outage.
        $this->assertContains('jobs', $modules);
    }

    /**
     * Hiding a key from a picker is not gating.
     *
     * The catalogue filter above shipped first and gated nothing: the SAVE path
     * checked only that the registry knew the key, and the keys live in the
     * module declaration that any other client can read. So somebody refused
     * `GET /hr/invitations` with a 403 could still build a rule that watched
     * invitations and posted the invitee's address to themselves.
     *
     * `notify` with `{{email}}` is the cheapest demonstration, because
     * `ActionRunner::interpolate` reads attributes straight off the subject. A
     * `webhook` action is the same hole with the whole payload and a URL of
     * their choosing.
     */
    public function test_a_rule_cannot_watch_a_module_the_builder_cannot_open(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $builder = $this->userWithRole($alpha, 'Rule builder', [
            'automations.view', 'automations.create', 'jobs.view',
        ]);

        // The premise: HR is genuinely closed to them.
        $this->as($builder)->getJson('/api/v1/hr/invitations')->assertForbidden();

        $refused = $this->as($builder)->postJson('/api/v1/automations', [
            'name'        => 'Tell me about new starters',
            'trigger_key' => 'invitation.sent',
            'actions'     => [['type' => 'notify', 'message' => 'New starter: {{email}}']],
        ]);

        $refused->assertStatus(422);

        // R8: it says what to do, and never which permission key is missing.
        $message = json_encode($refused->json('errors'));

        $this->assertStringNotContainsString('hr.view', (string) $message,
            'The refusal named a permission key at somebody who cannot act on one.');

        // Counted through the model rather than assertDatabaseCount, whose
        // third argument is the CONNECTION name and would swallow this sentence.
        $saved = $this->inTenant($alpha['org'], fn () => Automation::query()->count());

        $this->assertSame(0, $saved,
            'The rule was saved. Whatever the catalogue shows, the save path is the gate — '
            .'a rule that watches HR can send HR data anywhere its actions reach.');
    }

    /**
     * The run log carries `condition_trace`, and the trace carries real values.
     *
     * `maySeeSubjectOf` named Job and only Job, so every HR subject took an
     * early `return true`. Two different people could read what they must not:
     * somebody with no HR permission at all, and — the subtler one — a branch
     * manager who legitimately holds `hr.view` reading another branch's starter
     * out of an organization-wide rule.
     *
     * This asserts the second, because the first is now refused at save time
     * and the second is the one permission alone cannot answer.
     */
    public function test_a_run_log_does_not_show_another_branch_its_starters(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name'            => 'Alpha F2',
        ]));

        $manager = $this->userWithRole(
            $alpha, 'Branch manager', ['automations.view', 'hr.view'],
            'f2@alpha.test', onlyAt: $elsewhere,
        );

        // An organization-wide rule, and an invitation into the FIRST branch.
        $automation = $this->rule($alpha, 'invitation.sent', null, null,
            actions: [['type' => 'notify', 'message' => 'Someone was invited.']],
            // The condition is the point: its trace records the value it read.
            conditions: ['all' => [['field' => 'email', 'op' => 'is_not_empty']]],
        );

        $this->as($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => 'private.person@alpha.test',
            'role_id'    => $alpha['admin']->role_id,
            'scope_type' => 'franchise',
            'scope_id'   => $alpha['franchise']->id,
        ])->assertCreated();

        $this->drain($alpha);

        // THE CONTROL, and it comes first. Without it this test passes on an
        // empty run list — no runs, no leak, nothing proved. The administrator
        // must be able to read the address here, or there is nothing for the
        // manager to be denied and the assertion below is theatre.
        $asAdmin = $this->as($alpha['admin'])
            ->getJson("/api/v1/automations/{$automation->id}/runs")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($asAdmin,
            'The rule produced no runs at all, so this test cannot say anything about who '
            .'can read them.');

        $this->assertStringContainsString('private.person@alpha.test', json_encode($asAdmin),
            'The run log does not carry the address even for somebody entitled to it, so the '
            .'assertion below would pass whatever the guard did.');

        $runs = $this->as($manager)
            ->getJson("/api/v1/automations/{$automation->id}/runs")
            ->assertOk()
            ->json('data');

        $this->assertStringNotContainsString('private.person@alpha.test', json_encode($runs),
            'A manager at one branch read another branch’s new starter out of the run log. '
            .'The condition trace carries the real value, so this is the record itself and '
            .'not a summary of it.');
    }

    public function test_human_resources_is_present_once_the_role_reaches_the_tab(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $hr = $this->userWithRole($alpha, 'People manager', [
            'automations.view', 'automations.create', 'automations.build.view', 'hr.view',
        ]);

        $catalogue = $this->catalogue($hr);
        $module    = collect($catalogue['modules'])->firstWhere('key', 'hr');

        $this->assertNotNull($module, 'Human Resources is missing for somebody who can reach it.');
        $this->assertSame('Human Resources', $module['label']);

        $keys = collect($module['triggers'])->pluck('key')->all();

        foreach (['invitation.sent', 'invitation.accepted', 'onboarding.completed'] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    // ── 4. the declared actions ──────────────────────────────────

    /**
     * Declared ahead of the engine, and marked as such on every row.
     *
     * The alternative was an HR category offering triggers with nothing to do
     * with them. What is NOT allowed is advertising an action the save-time
     * check then rejects with no warning, which is what `implemented` is for.
     */
    public function test_each_hr_action_reports_whether_it_is_actually_built(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $hr    = $this->userWithRole($alpha, 'People manager', ['automations.view', 'automations.build.view', 'hr.view']);

        $module = collect($this->catalogue($hr)['modules'])->firstWhere('key', 'hr');

        $this->assertNotEmpty($module['declared_actions'],
            'Human Resources declares no actions. Declaring nothing is the state this replaced.');

        foreach ($module['declared_actions'] as $action) {
            /*
             * Was a blanket assertFalse until 2026-09-16, and the module is no
             * longer uniform: Automations built resend and revoke against HR's
             * InvitationActions, while onboarding.start is deliberately still
             * unbuilt because it needs a contract named by the rule.
             *
             * So this asserts the ACTUAL state per action rather than one
             * answer for the module. A blanket assertTrue would have been the
             * same mistake in the other direction, and would have gone red on
             * onboarding.start - which is how this list was checked rather
             * than assumed.
             */
            $this->assertSame(
                in_array($action['key'], ['invitation.resend', 'invitation.revoke'], strict: true),
                $action['implemented'],
                $action['key'],
            );

            // The sentence has to describe the ACTION and never its absence, so
            // it still reads correctly on the day the flag flips.
            $this->assertNotSame('', trim($action['label']), $action['key']);
            $this->assertNotSame('', trim($action['description']), $action['key']);
            $this->assertStringNotContainsString($action['key'], $action['description']);
        }
    }

    /**
     * A rule may react to an HR event and may not rewrite the record.
     *
     * The refusal is the thing being tested, not the rejection: HR declares no
     * subject — its events are about three kinds of record and a module holds
     * one — so this is the first trigger to reach the fallback branch, and R8
     * says what comes back is a sentence rather than the wire key of the trigger
     * somebody picked.
     */
    public function test_a_rule_on_an_hr_event_cannot_be_saved_to_set_a_field(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $hr    = $this->userWithRole($alpha, 'People manager', [
            'automations.view', 'automations.create', 'hr.view',
        ]);

        $response = $this->signedInAs($hr)->postJson('/api/v1/automations', [
            'name'        => 'Rewrite the contract',
            'trigger_key' => 'onboarding.completed',
            'actions'     => [['type' => 'update_field', 'field' => 'status', 'value' => 'x']],
        ])->assertStatus(422);

        // Not `json('errors.actions.0.field')` — the error KEY is literally the
        // string "actions.0.field", so dot traversal would look for a nested
        // array that was never there.
        $message = (string) ($response->json('errors')['actions.0.field'][0] ?? '');

        $this->assertNotSame('', $message, 'The rule was refused without saying anything.');

        $this->assertStringNotContainsString('onboarding.completed', $message,
            'The refusal read the trigger key back at the person (R8).');
        $this->assertStringContainsString('cannot set any field', $message);
    }

    // ── the lifecycle every event test runs through ──────────────

    /**
     * Invite, accept, onboard, sign both sides, amend — plus a published
     * contract version and a second invitation that gets withdrawn.
     *
     * One helper rather than a test per event, because these ARE one sequence:
     * an onboarding cannot be signed without an invitation being accepted first,
     * and an amendment cannot happen without both signatures. Splitting it would
     * mean rebuilding most of it six times.
     */
    private function wholeHrLifecycle(?array $alpha = null): array
    {
        $alpha ??= $this->provisionTenant('Alpha');

        $contractId = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name'   => 'Driver',
            'fields' => $this->fields(),
        ])->assertCreated()->json('data.id');

        /*
         * A SECOND version, which is the contract event.
         *
         * Creating the template already published version 1 — an onboarding
         * cannot start against a template with no version — and publishing an
         * identical field list is refused, deliberately: a version that changes
         * nothing splits the records that follow it away from the ones before.
         * So this adds a field, which is what publishing is for.
         */
        $this->as($alpha['admin'])->postJson("/api/v1/hr/contracts/{$contractId}/versions", [
            'fields' => [...$this->fields(), [
                'key' => 'shirt_size', 'label' => 'Shirt size', 'type' => 'text',
                'filled_by' => 'employee', 'required' => false,
            ]],
            'notes' => 'Added the uniform question.',
        ])->assertCreated();

        $invitation = $this->invite($alpha, 'franchise', $alpha['franchise']->id);

        $this->accept($this->tokenFrom($invitation->json('data.accept_url')));

        $employee = $this->inTenant($alpha['org'], fn () => User::query()
            ->where('email', 'newstarter@example.test')->firstOrFail());

        $onboardingId = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $invitation->json('data.id'),
            'contract_id'   => $contractId,
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->patchJson("/api/v1/hr/onboardings/{$onboardingId}", [
            'answers' => ['wage' => '18.50'],
        ])->assertOk();

        $this->as($employee)->patchJson("/api/v1/hr/onboardings/{$onboardingId}", [
            'answers' => ['emergency_contact' => 'Ana, 600 111 222'],
        ])->assertOk();

        $this->sign($alpha['admin'], $onboardingId, 'employer_sign', 'Alpha Admin');
        $this->sign($employee, $onboardingId, 'employee_sign', 'Marta Ruiz');

        $this->as($alpha['admin'])->postJson("/api/v1/hr/onboardings/{$onboardingId}/amendments", [
            'answers' => ['wage' => '19.00'],
            'reason'  => 'The wage agreed verbally was higher than the one typed in.',
        ])->assertCreated();

        // A second invitation, started and cancelled — changing your mind about
        // the paperwork, not about the person. A third invitation is withdrawn
        // below, which is the other kind of change of mind.
        $stoppedInvite = $this->invite($alpha, 'franchise', $alpha['franchise']->id, 'stopped@example.test');
        $stoppedId = $this->as($alpha['admin'])->postJson('/api/v1/hr/onboardings', [
            'invitation_id' => $stoppedInvite->json('data.id'),
            'contract_id'   => $contractId,
        ])->assertCreated()->json('data.id');
        $this->as($alpha['admin'])->deleteJson("/api/v1/hr/onboardings/{$stoppedId}")
            ->assertOk();

        // A second invitation, withdrawn — the one event above that is about
        // changing your mind rather than about somebody arriving.
        $withdrawn = $this->invite($alpha, 'franchise', $alpha['franchise']->id, 'changed@example.test');

        $this->as($alpha['admin'])
            ->deleteJson('/api/v1/hr/invitations/'.$withdrawn->json('data.id'))
            ->assertOk();

        return $alpha;
    }

    private function invite(
        array $alpha,
        string $scopeType,
        string $scopeId,
        string $email = 'newstarter@example.test',
    ): TestResponse {
        return $this->as($alpha['admin'])->postJson('/api/v1/hr/invitations', [
            'email'      => $email,
            'first_name' => 'Marta',
            'last_name'  => 'Ruiz',
            'role_id'    => $this->roleFor($alpha)->id,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
        ])->assertCreated();
    }

    private function sign(User $actor, string $id, string $field, string $name): void
    {
        $hash = $this->as($actor)
            ->getJson("/api/v1/hr/onboardings/{$id}/document")
            ->assertOk()->json('data.content_hash');

        $this->as($actor)->postJson("/api/v1/hr/onboardings/{$id}/sign", [
            'field_key'     => $field,
            'affirmation'   => $name,
            'consent'       => true,
            'document_hash' => $hash,
        ])->assertCreated();
    }

    private function fields(): array
    {
        return [
            ['key' => 'wage', 'label' => 'Hourly wage', 'type' => 'number',
                'filled_by' => 'employer', 'required' => true],
            ['key' => 'emergency_contact', 'label' => 'Emergency contact', 'type' => 'text',
                'filled_by' => 'employee', 'required' => true],
            ['key' => 'employer_sign', 'label' => 'For the employer', 'type' => 'signature',
                'filled_by' => 'employer', 'required' => true],
            ['key' => 'employee_sign', 'label' => 'Your signature', 'type' => 'signature',
                'filled_by' => 'employee', 'required' => true],
        ];
    }

    /** A role for the new starter that holds nothing from HR. */
    private function roleFor(array $alpha): Role
    {
        return $this->inTenant($alpha['org'], function () use ($alpha) {
            $existing = Role::query()->where('name', 'Technician')->first();

            if ($existing !== null) {
                return $existing;
            }

            $role = Role::create([
                'organization_id' => $alpha['org']->id,
                'name'            => 'Technician',
                'is_system'       => false,
            ]);

            $role->syncPermissionKeys(['customers.view']);

            return $role;
        });
    }

    // ── automations, and running them ────────────────────────────

    private function rule(
        array $alpha,
        string $trigger,
        ?string $scopeType,
        ?string $scopeId,
        // `notify` rather than a webhook: it performs a real action with a real
        // recorded result and never leaves the process, so a scope test is about
        // scope rather than about a faked HTTP client.
        array $actions = [['type' => 'notify', 'message' => 'Something happened in HR.']],
        // A rule with no conditions records no condition_trace, so a test about
        // what the TRACE discloses has to give it something to trace.
        array $conditions = [],
    ): Automation {
        return $this->inTenant($alpha['org'], function () use ($alpha, $trigger, $scopeType, $scopeId, $actions, $conditions) {
            $automation = Automation::create([
                'organization_id' => $alpha['org']->id,
                'name'            => "When {$trigger} at ".($scopeId ?? 'anywhere'),
                'trigger_key'     => $trigger,
                'scope_type'      => $scopeType,
                'scope_id'        => $scopeId,
                'is_active'       => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $alpha['org']->id,
                'automation_id'   => $automation->id,
                'version'         => 1,
                'conditions'      => $conditions,
                'actions'         => $actions,
                'created_at'      => now(),
            ]);

            $automation->forceFill(['active_version_id' => $version->id])->save();

            return $automation->fresh();
        });
    }

    /** Deliver everything sitting in the outbox, the way the scheduled sweep does. */
    private function drain(array $alpha): void
    {
        $engine = app(AutomationEngine::class);

        $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->whereNull('delivered_at')
            ->orderBy('occurred_at')
            ->get()
            ->each(fn (AutomationEvent $e) => $engine->deliver($e)));
    }

    /**
     * How many times a rule actually ACTED.
     *
     * Skips are recorded as runs too — "the rule did not match" and "the rule
     * never saw it" being different answers — so a plain count of rows would
     * report a franchise rule that correctly declined as though it had fired.
     */
    private function runsOf(array $alpha, Automation $automation): int
    {
        return $this->inTenant($alpha['org'], fn () => AutomationRun::query()
            ->where('automation_id', $automation->getKey())
            ->whereIn('status', [
                AutomationRun::MATCHED, AutomationRun::SUCCEEDED, AutomationRun::FAILED,
            ])
            ->count());
    }

    // ── reading the registry and the catalogue ───────────────────

    /** @return TriggerDefinition[] */
    private function hrTriggers(): array
    {
        $module = app(ModuleRegistry::class)->get('hr');

        $this->assertNotNull($module, 'There is no Human Resources module in the registry.');

        return $module->triggers;
    }

    private function catalogue(User $actor): array
    {
        return $this->signedInAs($actor)
            ->getJson('/api/v1/automations/catalogue')
            ->assertOk()
            ->json('data');
    }

    /** @return string[] */
    private function modulesInCatalogue(User $actor): array
    {
        return collect($this->catalogue($actor)['modules'])->pluck('key')->all();
    }

    // ── request plumbing ─────────────────────────────────────────

    /**
     * Sign in, having first dropped the scoped container.
     *
     * ScopeReach memoises reach per actor for the container's lifetime, and
     * these tests switch between the employer and the employee inside one test.
     * Without this they ask two questions of one cached answer.
     */
    private function as(User $actor): static
    {
        $this->app->forgetScopedInstances();

        return $this->signedInAs($actor);
    }

    private function accept(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        $this->postJson('/api/v1/invitations/accept', [
            'token'         => $token,
            'password'      => 'a-real-password',
            'phone'         => '+34 600 000 000',
            'address'       => '1 Test Street',
            'date_of_birth' => '1990-04-03',
        ])->assertCreated();
    }

    private function tokenFrom(?string $url): string
    {
        $this->assertIsString($url, 'The invitation response carried no accept_url.');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['token'];
    }
}
