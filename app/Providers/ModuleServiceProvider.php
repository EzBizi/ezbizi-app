<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\BusinessLocation;
use App\Models\InventoryItem;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Newsletter;
use App\Models\MonitoredDevice;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\AutomationSubject;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Support\ServiceProvider;

/**
 * Where modules declare themselves (plan §5.5).
 *
 * Adding a module means adding it here — and the §5.5 standing rule means
 * declaring its automation triggers and actions at the same time, or stating why
 * it has none. `ModuleRegistryTest` fails the build otherwise.
 *
 * Each trigger and action arrives with a LABEL and a DESCRIPTION, and those are
 * part of the declaration rather than something the client is expected to know.
 * The automation builder was rendering `organization.settings_changed` at the
 * person writing the rule; the module that raises the event is the only place
 * that can say what it means, and it says it here, once, for every client that
 * will ever ask.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, function () {
            $registry = new ModuleRegistry;

            foreach ([...$this->foundationModules(), ...$this->businessModules()] as $module) {
                $registry->register($module);
            }

            return $registry;
        });
    }

    /**
     * The foundation set (§14). Business modules — customers, jobs, invoices —
     * arrive with the first vertical slice and register the same way.
     *
     * @return ModuleDefinition[]
     */
    private function foundationModules(): array
    {
        return [
            /*
             * A module that is a screen and nothing else.
             *
             * The dashboard was one of the last destinations compiled into the
             * client. The sidebar is now built from what this registry declares,
             * so a screen that is not a module simply does not exist to it — and
             * the dashboard disappeared, for everybody including the
             * Administrator, the first time that list was drawn from the server.
             *
             * Declaring it is the right answer rather than a workaround. The
             * owner's rule is that EVERY module is reached through a tab
             * permission the organization grants; a screen exempt from that
             * would be a second way in.
             *
             * ── There were two of these, and the other one is gone ──
             *
             * `data` — "Import & export" — was declared here beside it, for the
             * same reason and with the same shape. It was removed on 2026-08-12:
             * the owner's call was that per-resource transfer belongs in the tab
             * that owns the records, so exporting customers is now a control in
             * Customers and exporting jobs one in Jobs. The two §9 answers that
             * are about the whole organization rather than one resource live in
             * Settings. None of the three needs a module of its own, because
             * each is now reached through the permissions of the thing it acts
             * on. The routes did not move and were never gated on `data.view`;
             * see the import/export block in routes/api.php.
             *
             * WHICH permissions changed with the tab, and that is the part this
             * paragraph used to get wrong. It said `customers.view` exports
             * customers "and always did" — true of the endpoint, and it stopped
             * being true here. Removing the tab removed the only way an
             * organization could withhold bulk export, so exporting is its own
             * action now: `customers.view` reads a customer, `customers.export`
             * downloads all of them. Reading one record and carrying the whole
             * list out in a file are different acts, and only the second is the
             * one that leaves the building on a laptop.
             *
             * `view` only. The dashboard has no action beyond looking — it
             * composes what other modules already permit. It is declared FIRST
             * because the registry's order is the sidebar's order, and it is the
             * screen somebody opens the app to look at.
             */
            new ModuleDefinition(
                key: 'dashboard',
                label: 'Dashboard',
                actions: ['view'],
                automationExemptBecause: 'The dashboard only reads what other modules already record; '
                    .'there is no event here that another module does not raise first.',
            ),

            new ModuleDefinition(
                key: 'organizations',
                label: 'Organization settings',
                // What its events are about, and therefore what a rule built on
                // one could change. Nothing on an organization is writable by
                // automation, and saying which record it is anyway is what lets
                // the builder explain that rather than offer an empty picker.
                subject: new AutomationSubject(Organization::class, 'Organization'),
                // No create/delete: an organization isn't created from inside
                // itself. Self-serve signup and staff creation both live in the
                // control plane (§4.2), outside tenant permissions.
                actions: ['view', 'edit'],
                triggers: [
                    new TriggerDefinition(
                        key: 'organization.settings_changed',
                        label: 'Setting changed',
                        description: 'Somebody saved a different value for a setting, at '
                            .'whichever level they set it.',
                        // Settings are saved without going through the outbox.
                        // Shown anyway: a tenant who can build a rule on it and
                        // watch it never fire is worse off than one who is told.
                        isRaised: false,
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'organization.notify_administrators',
                        label: 'Notify the administrators',
                        description: 'Sends an in-app notification to the people who '
                            .'administer this organization.',
                    ),
                ],
                rolesGroup: ModuleDefinition::ROLES_SETTINGS,
            ),

            new ModuleDefinition(
                key: 'companies',
                label: 'Companies',
                subject: new AutomationSubject(Company::class, 'Company'),
                triggers: [
                    new TriggerDefinition(
                        key: 'company.created',
                        label: 'Created',
                        description: 'A new company was added to the organization.',
                    ),
                    new TriggerDefinition(
                        key: 'company.updated',
                        label: 'Updated',
                        description: 'Something on a company changed — its name, its address, '
                            .'or it was switched back on. Switching one OFF raises '
                            .'"Deactivated" instead of this.',
                    ),
                    new TriggerDefinition(
                        key: 'company.deactivated',
                        label: 'Deactivated',
                        description: 'Somebody switched a company off. Nothing is deleted; the '
                            .'record and everything filed under it stay as they are.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'company.deactivate',
                        label: 'Deactivate the company',
                        description: 'Switches a company off, leaving its records where '
                            .'they are.',
                    ),
                ],
                rolesGroup: ModuleDefinition::ROLES_ORGANIZATION,
            ),

            new ModuleDefinition(
                key: 'franchises',
                label: 'Franchises',
                subject: new AutomationSubject(Franchise::class, 'Franchise'),
                triggers: [
                    new TriggerDefinition(
                        key: 'franchise.created',
                        label: 'Created',
                        description: 'A new franchise was added. Creating a company opens its '
                            .'first franchise at the same time, so this fires then too.',
                    ),
                    new TriggerDefinition(
                        key: 'franchise.updated',
                        label: 'Updated',
                        description: 'A franchise was renamed, moved to a different time zone, '
                            .'or switched back on. Switching one OFF raises "Deactivated" '
                            .'instead of this.',
                    ),
                    new TriggerDefinition(
                        key: 'franchise.deactivated',
                        label: 'Deactivated',
                        description: 'Somebody switched a franchise off. The jobs and history '
                            .'recorded against it are left untouched.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'franchise.deactivate',
                        label: 'Deactivate the franchise',
                        description: 'Switches a franchise off without touching the work '
                            .'already recorded against it.',
                    ),
                ],
                rolesGroup: ModuleDefinition::ROLES_ORGANIZATION,
            ),

            new ModuleDefinition(
                key: 'users',
                label: 'Users',
                subject: new AutomationSubject(User::class, 'User'),
                /*
                 * All three were declared `isRaised: false` — "nothing in user
                 * management reaches the outbox yet" — because there was no way
                 * to invite anybody, no way to switch an account off, and in
                 * practice only ever one user per organization.
                 *
                 * Human Resources is what raises them. The events stay declared
                 * HERE rather than moving with the module that emits them,
                 * because a trigger belongs to the record it is about: these are
                 * about a User, this is the module whose subject is User, and the
                 * field picker reads the subject off the trigger's module. Two
                 * modules declaring one key would give the registry two answers
                 * for it.
                 *
                 * `user.invited` is the one that carries no subject when it
                 * fires, and it cannot: at the moment of inviting there is no
                 * user yet. Its payload names the address, the role and the
                 * place instead. A rule can act on it — send a notification, call
                 * a webhook — but "update a field" has nothing to write to, which
                 * is the honest shape of an event about somebody who does not
                 * exist yet.
                 */
                triggers: [
                    new TriggerDefinition(
                        key: 'user.invited',
                        label: 'Invited',
                        description: 'Somebody was invited to join, before they have accepted. '
                            .'There is no account yet, so this one arrives with the invitation’s '
                            .'details rather than a person.',
                    ),
                    new TriggerDefinition(
                        key: 'user.activated',
                        label: 'Activated',
                        // Widened from "an invited person accepted", because
                        // switching a deactivated account back on reaches the
                        // same state and there is no second event for it. A
                        // description that named only one of the two ways in
                        // would be wrong about the other.
                        description: 'An account became usable — either an invited person '
                            .'accepted, or somebody who had been switched off was switched '
                            .'back on.',
                    ),
                    new TriggerDefinition(
                        key: 'user.deactivated',
                        label: 'Deactivated',
                        description: 'Someone’s access was switched off. The account stays, and '
                            .'so does everything it did.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'user.deactivate',
                        label: 'Deactivate the user',
                        description: 'Takes away a person’s access without removing them or '
                            .'anything they did.',
                    ),
                    new ActionDefinition(
                        key: 'user.assign_to_scope',
                        label: 'Move a user to a company or franchise',
                        description: 'Changes which company or franchise somebody works under, '
                            .'and with it the records they can reach.',
                    ),
                ],
                // Not a tab. Human Resources is the people screen; these keys
                // exist so automations can talk about a User. Granting them
                // in Roles would look like a Users tab that does not exist.
                rolesGroup: ModuleDefinition::ROLES_OMIT,
            ),

            new ModuleDefinition(
                key: 'roles',
                label: 'Roles & permissions',
                // Deliberately outside automation: a rule that could rewrite
                // permissions would be a privilege-escalation path — the
                // automation engine is configured by the same admins it would
                // grant rights to. Role changes stay a human act, and every one
                // is audited (§2.2).
                automationExemptBecause: 'Automating permission changes would let a rule escalate privileges.',
                rolesGroup: ModuleDefinition::ROLES_SETTINGS,
            ),

            new ModuleDefinition(
                key: 'audit',
                label: 'Audit log',
                actions: ['view'],
                // Read-only by construction: the log is append-only (§10). No edit
                // or delete action exists to grant, so tampering isn't a permission
                // question — there is simply no code path.
                automationExemptBecause: 'The audit log is append-only; nothing may act on it programmatically.',
                rolesGroup: ModuleDefinition::ROLES_SETTINGS,
            ),
        ];
    }

    /**
     * The first vertical slice (§18 Phase 2).
     *
     * Their triggers and actions are declared here alongside the permissions,
     * per the §5.5 standing rule — and they are the ones the automation engine
     * will be built against. The plan is explicit that the engine should be
     * proved with REAL Customer and Job events rather than imagined ones, which
     * is only possible if these exist first.
     *
     * @return ModuleDefinition[]
     */
    private function businessModules(): array
    {
        return [
            /*
             * Human Resources — EZBIZI-HR-PLAN.md.
             *
             * First in this list, and therefore first in the sidebar after the
             * foundation, because it is the module that lets an organization
             * have employees at all: until it existed, no endpoint wrote
             * `users.role_id`, so every tenant was permanently one Administrator
             * and every role an organization composed had nobody to give it to.
             *
             * Three actions, not four.
             *
             *   view    the tab — people, the invitations in flight, and the
             *           contract templates onboarding is built from
             *   create  inviting somebody, and the whole life of that invitation:
             *           resending it and withdrawing it. Issuing and un-issuing
             *           are one authority, because the person who should be able
             *           to take an invitation back is the person who sent it.
             *           Also adding a contract template.
             *   edit    changing what somebody's account is — their role, and
             *           whether it works at all — and publishing a new version
             *           of a contract template
             *
             * The two contract clauses were added when the Contracts tab landed.
             * One module means one set of actions across all five tabs (§4: the
             * tab IS the view permission), so `hr.create` genuinely does cover
             * both inviting a technician and authoring the schema every
             * onboarding uses. Those are not the same size of authority, and
             * ContractPolicy narrows the second one on top: writing a template
             * additionally requires standing at the ORGANIZATION, because a
             * template reaches every franchise. The permission alone would have
             * handed a branch manager the whole business's onboarding form.
             *
             * There is no `delete`, and its absence is a decision rather than an
             * omission. §5: deactivate is not delete, and an employment record
             * survives deactivation — a person who leaves is switched off, not
             * erased, because everything they did stays attributed to them.
             * Revoking an invitation does not delete it either; it becomes
             * abandoned, and the row is how "we invited them and changed our
             * mind" stays answerable.
             */
            /*
             * ── Its automation surface, and why it stopped being an
             *    exemption ─────────────────────────────────────────
             *
             * This module used to state `automationExemptBecause`, and the
             * reasoning was that everything HR does happens to a User —
             * `user.invited`, `user.activated`, `user.deactivated`, all declared
             * by the Users module, all raised from here. Those three are still
             * declared there and still raised from here, and none of what
             * follows replaces them.
             *
             * What the exemption missed is that HR shipped five tabs and four of
             * them are about records that are not a user. An invitation is a
             * thing with a week on it that is accepted or lapses or is
             * withdrawn; an onboarding is a form two people fill in and sign;
             * a contract version is what they signed. `user.activated` says a
             * person got an account. It cannot say the paperwork is finished,
             * which is the moment a payroll webhook is waiting for.
             *
             * ── This module declares NO SUBJECT, and that is a
             *    limitation being stated rather than a decision ────
             *
             * A ModuleDefinition holds one AutomationSubject and refuses any
             * declared key whose prefix disagrees with it, because the field
             * picker resolves a trigger's subject through the module that
             * declares it. HR's events are about THREE kinds of record — an
             * invitation, an onboarding, a contract version — so there is no one
             * answer to give, and naming any of the three would make the picker
             * wrong about the other two.
             *
             * Null is the honest answer and it costs exactly one thing: the
             * builder cannot grey out "Update a field" under an HR trigger the
             * way it does under an organization trigger, so somebody can pick it
             * and be refused at save. The refusal is a sentence and it says why.
             * Nothing is lost beyond that, because no field on any of the three
             * is writable by a rule in the first place — ActionRunner::WRITABLE
             * holds Job, Customer and Vehicle and nothing else, deliberately: an
             * invitation's role, an onboarding's answers and a published
             * version's fields are each governed by an endpoint that checks
             * something a bare field write would skip.
             *
             * The proper fix is a subject per TRIGGER rather than per module.
             * That is a change to the registry AND to the wire shape the
             * catalogue publishes — `subject` sits beside `label` on the module,
             * and the client reads it from there — so it is a change to three
             * files this task does not own. Recorded here rather than done
             * quietly and badly.
             */
            new ModuleDefinition(
                key: 'hr',
                label: 'Human Resources',
                auditActionPrefixes: ['onboarding', 'invitation', 'contract', 'person'],
                // `security.reset` is deliberately separate from `edit`: clearing
                // somebody's second factor and handing out a password link is a
                // bigger authority than maintaining their row, and folding it into
                // `edit` would have granted it silently, on deploy, to everyone who
                // already keeps the people list — see UserPolicy::resetSecurity.
                // Same reasoning that keeps `monitoring.control` off
                // `monitoring.screenshots.view`.
                actions: ['view', 'create', 'edit', 'security.reset'],
                triggers: [
                    /*
                     * ── Invitations ─────────────────────────────
                     *
                     * `invitation.sent` and `user.invited` fire on the same act,
                     * and both are wanted. The difference is what they carry:
                     * `user.invited` is about a person who does not exist yet, so
                     * it has no record at all and no scope, and a franchise-level
                     * rule can never match it. This one carries the invitation,
                     * which knows the franchise or company it was issued into —
                     * so "when somebody is invited into MY branch" is a rule that
                     * can be written here and could not be written there.
                     *
                     * There is no "resent". Resending is an administrative
                     * gesture rather than something that happened to the
                     * business, and a rule on it would fire every time an
                     * administrator chased somebody.
                     *
                     * There is no "opened" either. The row records it and the
                     * status list shows it, but the useful rule about opening is
                     * about NOT opening — "nobody has looked at this in three
                     * days" — and an event cannot express an absence.
                     */
                    new TriggerDefinition(
                        key: 'invitation.sent',
                        label: 'Invitation sent',
                        description: 'Somebody was invited to join. It carries the address, the '
                            .'role they were offered, the place they were offered it in and when '
                            .'the link runs out.',
                    ),
                    new TriggerDefinition(
                        key: 'invitation.accepted',
                        label: 'Invitation accepted',
                        description: 'The person followed the link and created their account. '
                            .'It carries the role and the place, and by this point there is a '
                            .'real user behind it.',
                    ),
                    new TriggerDefinition(
                        key: 'invitation.revoked',
                        label: 'Invitation withdrawn',
                        description: 'The organization changed its mind and took an invitation '
                            .'back before it was accepted. The link stops working immediately, '
                            .'and this is a different fact from one that simply ran out.',
                    ),
                    /*
                     * Real, and nothing observes it.
                     *
                     * Expiry is a fact about the CLOCK: `status()` derives
                     * `abandoned` by comparing `expires_at` to now, and the
                     * model argues at length against a scheduled job that writes
                     * a column to agree with the clock. An outbox event is not
                     * that column — it is a fact published once, which is a
                     * different thing and would be legitimate.
                     *
                     * It is not built because the sweep that would raise it has
                     * a first-run problem worth deciding deliberately rather
                     * than shipping by accident: every invitation that has
                     * already lapsed would fire the day it is deployed, so
                     * whatever look-back window it uses is a decision about how
                     * much history gets replayed into live rules. Marked, and
                     * visible, which is the whole reason `isRaised` exists —
                     * hiding it would let somebody build this rule, switch it on
                     * and wait forever.
                     */
                    new TriggerDefinition(
                        key: 'invitation.expired',
                        label: 'Invitation ran out',
                        description: 'A week passed and nobody accepted, so the link stopped '
                            .'working on its own.',
                        isRaised: false,
                    ),

                    /*
                     * ── Onboarding ──────────────────────────────
                     *
                     * "Signed" and "Fully signed" are two events because they
                     * are two moments, and the gap between them is where the
                     * document has to hold still — see the amendment guard in
                     * HrOnboardingsController. A rule that wants only the new
                     * starter's signature puts a condition on the party rather
                     * than getting an event of its own, because the party is a
                     * property of the signature and not a different kind of act.
                     */
                    new TriggerDefinition(
                        key: 'onboarding.started',
                        label: 'Onboarding started',
                        description: 'An employer picked a contract template for somebody who '
                            .'has been invited, and the form is now open. It carries the '
                            .'template’s name and the version it was pinned to.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.signed',
                        label: 'Somebody signed',
                        description: 'One party signed one line of the contract. It carries '
                            .'whether that was the employer or the employee, which line, and '
                            .'whether every signature has now been given — so a rule about the '
                            .'new starter specifically is a condition on the party. The first '
                            .'signature is also what freezes the answers.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.completed',
                        label: 'Onboarding fully signed',
                        description: 'Every signature the contract asks for has been given and '
                            .'the paperwork is finished. This is the one a payroll or IT handover '
                            .'should hang off, rather than the account being created.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.amended',
                        label: 'Signed contract amended',
                        description: 'A correction was made after signing. The original document '
                            .'stays and a new revision sits beside it; this carries the revision '
                            .'number, which fields changed and the reason given.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.cancelled',
                        label: 'Onboarding cancelled',
                        description: 'An onboarding was stopped before it was fully signed. The '
                            .'row stays. It carries the contract name and the status it left, so '
                            .'a rule can tell a form nobody had signed from one that was voided '
                            .'mid-ceremony.',
                    ),

                    /*
                     * ── Contracts ───────────────────────────────
                     *
                     * Publishing is a change to the SCHEMA rather than to
                     * anybody's record, and the old exemption used that to argue
                     * it should raise nothing. Half of that argument was right
                     * and it is kept below: no rule may PUBLISH a version,
                     * because a rule that rewrites what every future employee is
                     * asked would bypass the readable diff publishing exists to
                     * put in front of a person.
                     *
                     * Reacting to one is a different thing entirely, and it is
                     * the case the owner's rule is about: the terms of
                     * employment changed, and somebody wants to be told.
                     */
                    new TriggerDefinition(
                        key: 'contract.version_published',
                        label: 'Contract version published',
                        description: 'A new version of a contract template went live, so every '
                            .'onboarding started from now on asks these questions. It carries the '
                            .'template’s name, the version number and a count of what changed. '
                            .'Forms already in flight keep the version they were pinned to.',
                    ),
                ],
                /*
                 * None of these run yet, and the catalogue says so on each row.
                 *
                 * They are declared because the alternative is an HR category
                 * offering triggers and nothing to do with them, and because a
                 * module is where the intent belongs — ActionRunner decides what
                 * executes and the catalogue marks the gap, so this list stating
                 * a plan is exactly what it is for.
                 *
                 * What is deliberately NOT here: anything that writes a contract
                 * template, anything that signs, and anything that accepts an
                 * invitation on somebody's behalf. The first bypasses the diff;
                 * the second and third are the two acts in this module whose
                 * entire value is that a person did them.
                 */
                automationActions: [
                    new ActionDefinition(
                        key: 'invitation.resend',
                        label: 'Send the invitation again',
                        description: 'Issues a fresh link with a fresh week on it, replacing the '
                            .'old one. Chasing somebody who has not accepted is the obvious rule '
                            .'to build, and the cascade guards are what stop it chasing forever.',
                    ),
                    new ActionDefinition(
                        key: 'invitation.revoke',
                        label: 'Withdraw the invitation',
                        description: 'Takes an invitation back. The row stays and reports itself '
                            .'as withdrawn, so the record still shows that somebody was asked.',
                    ),
                    new ActionDefinition(
                        key: 'onboarding.start',
                        label: 'Start the onboarding',
                        description: 'Opens the form for somebody who has been invited, against '
                            .'a contract template the rule names. It is the step an employer '
                            .'takes by hand today, and the one most worth not forgetting.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key: 'time_clock',
                label: 'Clock in',
                // `lock_screen`, not `lock`: the roles label is derived from
                // the key (PermissionCatalogue::actionLabel), so this reads
                // "Lock screen" beside the checkbox with no override. Any
                // action implies `view`, which is right — a locked person
                // must be able to punch.
                actions: ['view', 'lock_screen'],
                sidebar: false,
                triggers: [
                    new TriggerDefinition(
                        key: 'time_clock.clocked_in',
                        label: 'Person clocked in',
                        description: 'Somebody started a shift. It carries who, and when they punched in.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'time_clock.clocked_out',
                        label: 'Person clocked out',
                        description: 'Somebody ended a shift. It carries who, when they punched in, and when they punched out.',
                        isRaised: true,
                    ),
                ],
                /*
                 * ── Why a rule may punch the clock ──────────────────
                 *
                 * This module declared triggers and no actions until 2026-08-28,
                 * with nothing written down about why. The obvious argument
                 * against is real and worth stating so nobody re-litigates it
                 * from scratch: a shift opened or closed by a rule is a timesheet
                 * entry no person punched, and payroll is downstream of it.
                 *
                 * The owner's ruling is that this is the organization's call to
                 * make, not ours to withhold — automations are a thing a business
                 * builds for itself, and what it does with them is its own
                 * affair. A company that wants every open shift closed at
                 * midnight is not doing something we should have to approve.
                 *
                 * What keeps that honest rather than merely permissive: a punch
                 * made by a rule goes through exactly the path a person's punch
                 * does, so it is audited as `time_clock.clocked_in/out`, it
                 * raises the same trigger, and the shift is an ordinary row on
                 * the Human Resources day list. It is visible as a punch. It is
                 * not visible as one a human made, which is why the event it
                 * raises carries the automation's causation chain.
                 */
                automationActions: [
                    /*
                     * `executionType` is spelled out rather than derived, the
                     * way `training.assign` spells its out. Left to itself
                     * ActionDefinition keeps everything after the FIRST dot, so
                     * these would execute as bare `clock_in` / `clock_out` in a
                     * namespace every module shares — and "clock in" is a phrase
                     * a second module could plausibly want. Phone can derive
                     * safely because `phone.voicemail.mark_dealt` still leaves a
                     * dotted `voicemail.mark_dealt` behind; a two-segment key
                     * does not.
                     */
                    new ActionDefinition(
                        key: 'time_clock.clock_in',
                        label: 'Start a shift',
                        description: 'Opens a shift for the person this rule is about, the same way '
                            .'the button does. Refused if the organization has the clock switched '
                            .'off, if they may not use it, or if they are already on a shift.',
                        executionType: 'time_clock.clock_in',
                    ),
                    new ActionDefinition(
                        key: 'time_clock.clock_out',
                        label: 'End a shift',
                        description: 'Closes the open shift of the person this rule is about. Needs '
                            .'only an open shift, so a shift left running by somebody whose access '
                            .'has since changed can still be closed.',
                        executionType: 'time_clock.clock_out',
                    ),
                ],
            ),

            /*
             * ── Why there is a fifth action here ────────────────
             *
             * `export` is the ordinary CRUD set plus one, and it is the only
             * module action that is not a way of changing a record. It exists
             * because taking EVERY customer's name, email, phone and address out
             * of the system as one file is a different act from reading one
             * customer, and until now it was the same permission.
             *
             * It was survivable while the "Import & export" tab existed: that
             * tab was gated on `data.view`, so an organization could say "this
             * role does not reach bulk transfer". Removing the tab on 2026-08-12
             * moved the Export control into this tab and took that control away
             * with it — the owner's rule is that modules are gated by the tab
             * permissions the organization sets, and there was suddenly no grant
             * to withhold.
             *
             * Declared here rather than anywhere else because this is where
             * permissions come from: the roles editor, `/me`, the escalation
             * bound and the sync command all read the registry, so a key added
             * here is grantable everywhere with nothing else edited.
             * ResourceMap names it as the resource's `export.permission` and
             * DataController enforces it; nothing invents a second mechanism.
             */
            new ModuleDefinition(
                key: 'customers',
                label: 'Customers',
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'export'],
                subject: new AutomationSubject(Customer::class, 'Customer'),
                triggers: [
                    new TriggerDefinition(
                        key: 'customer.created',
                        label: 'Created',
                        description: 'A new customer was added to the books.',
                    ),
                    new TriggerDefinition(
                        key: 'customer.updated',
                        label: 'Updated',
                        // The distinction somebody will get wrong. Reassignment
                        // is its own endpoint and emits its own event only, so a
                        // rule watching "Updated" for a change of owner never
                        // sees one.
                        description: 'A field on a customer changed and was saved. Moving a '
                            .'customer to a different owning franchise does NOT raise this — '
                            .'that has its own event, "Owner reassigned".',
                    ),
                    new TriggerDefinition(
                        key: 'customer.deleted',
                        label: 'Deleted',
                        description: 'Destructive. A customer was deleted — only possible when '
                            .'they have no jobs — and they leave every list.',
                    ),
                    // Its own trigger rather than a field-change on updated:
                    // moving accountability between franchises is the kind of
                    // thing a manager wants to be told about, and burying it in
                    // "a field changed" means nobody ever builds that rule.
                    new TriggerDefinition(
                        key: 'customer.owner_reassigned',
                        label: 'Owner reassigned',
                        description: 'Accountability for a customer moved to another franchise. '
                            .'It is a deliberate, separately authorised act rather than part of '
                            .'an ordinary edit, which is why "Updated" does not fire for it.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'customer.update_field',
                        label: 'Update a field',
                        description: 'Sets one field on the customer that triggered the rule: '
                            .'name, contact details, notes, address, or whether they are active.',
                    ),
                    new ActionDefinition(
                        key: 'customer.reassign_owner',
                        label: 'Reassign the owner',
                        description: 'Moves accountability for the customer to a different '
                            .'franchise.',
                    ),
                    new ActionDefinition(
                        key: 'customer.deactivate',
                        label: 'Deactivate the customer',
                        description: 'Marks a customer inactive without deleting anything. '
                            .'"Update a field" can already do this by setting Active to false.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key: 'jobs',
                label: 'Jobs',
                // The same fifth action, for the same reason — see Customers
                // above. Jobs cannot be imported by anybody (ResourceMap says
                // why) and can be exported by everybody who reaches the tab, so
                // this is the only transfer grant this module has to offer.
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'export'],
                subject: new AutomationSubject(Job::class, 'Job'),
                triggers: [
                    new TriggerDefinition(
                        key: 'job.created',
                        label: 'Created',
                        description: 'A job was booked. If it was given a date at the same time, '
                            .'"Date set or changed" fires as well.',
                    ),
                    new TriggerDefinition(
                        key: 'job.updated',
                        label: 'Updated',
                        description: 'Any saved change to a job. A change of status or of date '
                            .'raises this AND its own more specific event, so a rule listening '
                            .'for both runs twice.',
                    ),
                    new TriggerDefinition(
                        key: 'job.deleted',
                        label: 'Deleted',
                        description: 'Destructive. A job was deleted, and it drops out of every '
                            .'list and every total.',
                    ),
                    // The one most automation will actually hang off — "when a
                    // job is completed, email the customer" is the canonical
                    // rule, and it needs the transition, not the end state.
                    new TriggerDefinition(
                        key: 'job.status_changed',
                        label: 'Status changed',
                        description: 'A job moved from one status to another. It carries the old '
                            .'status as well as the new one, which is what a rule like "when a '
                            .'job is completed" needs — the status on its own cannot say that it '
                            .'just changed.',
                    ),
                    new TriggerDefinition(
                        key: 'job.scheduled',
                        label: 'Date set or changed',
                        description: 'A job’s date was set, moved, or cleared. Clearing it counts '
                            .'— the event fires with an empty new date.',
                    ),
                    new TriggerDefinition(
                        key: 'job.assigned',
                        label: 'Assigned to a role',
                        description: 'A job’s role was set, changed or cleared. It carries the '
                            .'old role as well as the new one. Naming a role says whose trade '
                            .'the work is — it does not decide who may open the job.',
                    ),
                    new TriggerDefinition(
                        key: 'job.completed',
                        label: 'Completed',
                        description: 'A job reached one of the statuses your organization counts '
                            .'as earned. Set that list in Settings. This fires only on the way '
                            .'IN, so a job corrected back and forth does not fire twice.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'job.update_field',
                        label: 'Update a field',
                        description: 'Sets one field on the job that triggered the rule: title, '
                            .'description, status, date, duration or amount.',
                    ),
                    new ActionDefinition(
                        key: 'job.change_status',
                        label: 'Change the status',
                        description: 'Moves a job to a different status. "Update a field" can '
                            .'already do this by setting the status field.',
                    ),
                    new ActionDefinition(
                        key: 'job.assign_to_user',
                        label: 'Assign it to somebody',
                        description: 'Names the person responsible for the job.',
                    ),
                    new ActionDefinition(
                        key: 'job.create',
                        label: 'Create a job',
                        description: 'Books a follow-up job from the record that triggered '
                            .'the rule.',
                    ),
                ],
            ),

            /*
             * Scheduling — a shell, and this comment is the honest label on it.
             *
             * Three tabs stand (Availability, Schedule, Settings) and all three
             * are placeholders. There is no table, no endpoint and no model
             * behind any of them yet, so the only thing this declaration buys
             * today is the rail slot and the four permissions — which is the
             * point: the permissions exist before the screens do, so nobody
             * ships a screen and grants it to everybody by accident.
             *
             * It sits next to Jobs because that is the work it will eventually
             * be about. Whether Availability turns out to mean staff, equipment
             * or customer-facing slots is undecided, and this file is not the
             * place that decides it.
             */
            new ModuleDefinition(
                key: 'scheduling',
                label: 'Scheduling',
                auditExemptBecause: 'NOT AUDITED. A create, edit or delete here leaves no '
                    .'audit_events row, so there is no record of who changed what or when. '
                    .'This states a defect, not a decision, and it is not permission to stay '
                    .'this way — see TODO #35 for how to close it and delete this line.',
                actions: ModuleDefinition::DEFAULT_ACTIONS,
                automationExemptBecause: 'Nothing is scheduled yet — this module is a shell. '
                    .'Replace this with real triggers when Availability becomes real; do not '
                    .'read it as a ruling that scheduling has nothing to automate.',
            ),

            /*
             * Inspection — named templates a role fills in on a visit, then a
             * frozen report. The tab is one destination with three faces (New
             * Inspection, Generated Reports, Builder). Extra work after submit
             * is automations on `inspection.submitted`, not a second filing
             * place on the form.
             *
             * No automation actions. A rule that wrote answers, archived a
             * report, or published a template would skip the screens that make
             * those acts mean something. Triggers are enough for
             * satisfiesAutomationRule; the empty array is the decision, not an
             * omission.
             *
             * No subject: events are about a run or a report, and naming either
             * would make the field picker wrong about the other. Same cost as
             * HR — `update_field` is refused at save, in a sentence.
             *
             * All four triggers are raised: archived from Generated Reports,
             * started / submitted / discarded from New Inspection.
             * ModuleRegistryTest holds that claim to the source.
             */
            new ModuleDefinition(
                key: 'inspection',
                label: 'Inspection',
                actions: ['view', 'create', 'edit', 'delete', 'archive'],
                /*
                 * Both record types this module broadcasts, not just the one
                 * that matters most.
                 *
                 * The RUN is the sharper of the two and the reason to be
                 * careful here: it is personal, and InspectionRunPolicy answers
                 * "no such run" to anybody but the person who started it,
                 * because a draft's existence is itself private. A `notify` on
                 * `inspection.started` reaching every active user would tell
                 * them a named technician started a named inspection for a
                 * named customer — the exact fact the policy refuses to a
                 * direct request.
                 *
                 * Both now implement CarriesAutomationScope, answering with the
                 * job's franchise and falling back to the customer's.
                 */
                emits: [
                    \App\Models\InspectionRun::class,
                    \App\Models\InspectionReport::class,
                ],
                triggers: [
                    new TriggerDefinition(
                        key: 'inspection.started',
                        label: 'Inspection started',
                        description: 'Somebody started filling in a named inspection for a '
                            .'client or a job. The draft is personal to them until they submit it.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'inspection.submitted',
                        label: 'Inspection submitted',
                        description: 'A named inspection was finished and a report was written. '
                            .'The answers, photographs and signatures are frozen as they were '
                            .'on the day.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'inspection.archived',
                        label: 'Report archived',
                        description: 'A submitted inspection was hidden from the default list. '
                            .'Archive does not delete the report.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'inspection.discarded',
                        label: 'In-progress discarded',
                        description: 'Somebody threw away their own unfinished inspection and '
                            .'started again, or discarded it without submitting.',
                        isRaised: true,
                    ),
                ],
                automationActions: [],
            ),

            /*
             * Marketing — declared before it is built, and NOT a stub.
             *
             * The owner asked for the foundation of tenant-authored mail: *"there
             * will be situations where they will be using Modules in the app that
             * send emails for their own companies … a company would prefer that
             * this newsletter came from their own SMTP's like info@company.com"*.
             * The sending identity is that foundation; this is the module that
             * will be its first customer, and Newsletter is its first sub-tab.
             *
             * ── Why declare it now rather than when it works ────
             *
             * Because of the rule the owner set after Human Resources shipped
             * silent: *"every new thing that gets added or removed from the app
             * needs to work with the automations system"*. HR was written, shipped
             * and then retrofitted, and the retrofit is the expensive way. A
             * newsletter having been sent is an event a business builds a rule on,
             * so the surface is declared in the same breath as the module — which
             * is exactly what `isRaised` exists to make honest.
             *
             * The tab is NOT exempt from anything for being new. It is reached
             * through `marketing.view` like every other module, and an
             * organization that grants it to nobody has no Marketing tab.
             *
             * ── `view` and `edit` ────────────────────────────────
             *
             * Reaching the tab is `view`. Connecting a page is `edit`, the same
             * split Email uses for looking at inboxes versus signing one in.
             * Publishing will use `edit` as well. A `send` for Newsletter is
             * still not declared: drafting something and putting it in front of
             * every customer on the books are different sizes of authority, and
             * only the second is irreversible.
             *
             * ── No subject, and the reason is not HR's ──────────
             *
             * HR declares none because its events are about three kinds of record
             * at once. This one declares none because there is no record at all:
             * AutomationSubject takes a class-string of an Eloquent model and
             * refuses anything else, so a subject cannot be named before the table
             * exists. When a Newsletter model lands it is named here, and
             * `assertKeysMatchSubject` will hold these keys to it.
             *
             * It costs the same thing it costs HR: the builder cannot grey out
             * "Update a field" under a Newsletter trigger in advance, so somebody
             * can pick it and be refused at save, in a sentence.
             */
            new ModuleDefinition(
                key: 'marketing',
                label: 'Marketing',
                auditActionPrefixes: ['social'],
                actions: ['view', 'edit'],
                triggers: [
                    /*
                     * Both are declared as never raised, because nothing sends a
                     * newsletter yet — there is no audience to send to and nothing
                     * written to send. `ModuleRegistryTest` checks that claim
                     * against the source on every run, so the day either one is
                     * emitted the build fails until the flag is dropped, rather
                     * than the builder going on telling tenants a working rule
                     * will never run.
                     *
                     * ── What is deliberately NOT declared ───────
                     *
                     * "Opened" and "Clicked". Both need a tracking pixel or a
                     * rewritten link in somebody's inbox, and whether this product
                     * watches recipients that way is a decision nobody has made.
                     * Declaring the event would make it for them: a trigger in the
                     * builder is a promise the data will exist.
                     *
                     * "Bounced" and "Failed to send". Real, wanted, and dependent
                     * on the transport reporting a bounce back to us — which is a
                     * property of the transport chosen for tenant mail, not of
                     * this module. It is declared where that path is built.
                     */
                    /*
                     * `PublishSocialTarget` emits one or the other every time
                     * it runs.
                     *
                     * One event per ACCOUNT rather than one per post, because
                     * that is the grain a rule can act on. A post to three
                     * networks where TikTok refused is not a failure and not a
                     * success, so a per-post event would have to pick one and be
                     * wrong about the others — and "tell me when a post fails"
                     * is a rule somebody writes precisely to hear about the one
                     * account that did.
                     */
                    new TriggerDefinition(
                        key: 'social.published',
                        label: 'A post went out',
                        description: 'A post reached one connected social account. It carries '
                            .'which account and which network, and the id the network gave '
                            .'back — one of these per account a post was sent to, as each '
                            .'one lands.',
                    ),
                    new TriggerDefinition(
                        key: 'social.publish_failed',
                        label: 'A post could not go out',
                        description: 'One account refused a post, or could not be reached. It '
                            .'carries which account and the reason in the words shown on the '
                            .'screen. The other accounts the same post went to are unaffected '
                            .'and raise their own events.',
                    ),
                ],
                /*
                 * No actions of its own. Marketing's only automation surface is
                 * now the social pair above; everything a rule could DO to a
                 * newsletter moved to the `newsletter` module below, beside the
                 * events it would react to.
                 */
            ),

            /*
             * Newsletter — write a mailing, choose who gets it, send it through
             * the account the company brought.
             *
             * ── Why this is its own module and not two more marketing keys ──
             *
             * `send` has to be grantable on its own. Writing a mailing and
             * putting it in front of twelve hundred customers are different
             * acts, and plenty of organizations want somebody who can draft but
             * not send — the same split that keeps `inventory.financials` off
             * `inventory.view`. That is not expressible as `marketing.edit`.
             *
             * `parent: 'marketing'` says where it is DRAWN and nothing else, the
             * same as Leads. These are new keys, held by nobody until an
             * organization grants them (§4).
             *
             * ── Why there is no subject ─────────────────────────────
             *
             * Events here are about a mailing, an audience or an address, and
             * naming any one of the three would make the field picker wrong
             * about the other two. Same cost HR pays: `update_field` is refused
             * at save, in a sentence.
             */
            new ModuleDefinition(
                key: 'newsletter',
                label: 'Newsletter',
                parent: 'marketing',
                auditActionPrefixes: ['newsletter'],
                actions: ['view', 'create', 'edit', 'delete', 'send'],
                /*
                 * One record type, and it really is one: both triggers pass a
                 * Newsletter. `newsletter.unsubscribed` fires from the public
                 * unsubscribe page with the MAILING as its subject rather than
                 * the address, because the address is the payload and a subject
                 * is a record a rule can be scoped by.
                 *
                 * Declaring it is what lets `AutomationSubjectScopeTest` ask
                 * whether that record can say where it sits — and it could not
                 * until now, so a `notify` on either trigger reached every
                 * active user in the organization. That matters most for the
                 * unsubscribe: its payload carries an email address, so one
                 * branch's departure was broadcast organization-wide.
                 */
                emits: [Newsletter::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'newsletter.sent',
                        label: 'Newsletter sent',
                        description: 'A newsletter went out to its audience. It carries which '
                            .'one, how many addresses it went to and the account it was sent '
                            .'through — not whether anybody read it.',
                    ),
                    /*
                     * The one that had to exist on day one rather than day two.
                     *
                     * Somebody asking to stop receiving marketing mail is the
                     * only event here the business is obliged to honour, and
                     * honouring it usually means touching a record this module
                     * does not own: flag the customer, stop the other campaign,
                     * tell the branch. That is a rule, and this is the trigger it
                     * hangs off.
                     */
                    new TriggerDefinition(
                        key: 'newsletter.unsubscribed',
                        label: 'Somebody unsubscribed',
                        description: 'A recipient asked to stop receiving marketing mail. It '
                            .'carries the address and which audience it was asked from, and it '
                            .'is a decision the person made rather than a list being tidied.',
                    ),
                ],
                /*
                 * `add_to_audience` and nothing that WRITES a mailing, for the
                 * reason HR has no action that publishes a contract version: the
                 * entire value of a message going to twelve hundred people is
                 * that a person read it first, and a rule that composes one
                 * bypasses the only review there is.
                 *
                 * Sending is absent for the same reason and a sharper one — a
                 * rule that mails a real audience with nobody looking is the one
                 * mistake in this module that cannot be taken back.
                 */
                automationActions: [
                    new ActionDefinition(
                        key: 'newsletter.add_to_audience',
                        label: 'Add somebody to a mailing list',
                        description: 'Puts the person a rule fired about onto a named mailing '
                            .'list, so "a new customer joins the list" is a rule somebody can '
                            .'write. It does not stand in for their agreement to be mailed, '
                            .'which the list records separately.',
                    ),
                ],
            ),

            /*
             * Web Management — the business facts a company's websites publish,
             * and the WordPress plugin that reads them.
             *
             * It stopped being a placeholder on 2026-08-28. What it is NOT is
             * the website editor the old comment here was waiting for: EzBizi
             * holds the facts, the company's own WordPress renders them. A
             * company registers each host it owns — a-1chimney.com and
             * nj.a-1chimney.com are two sites with two tokens — sets name,
             * phone, address, licence and hours per site, and the plugin turns
             * each into a shortcode. The New Jersey site answering a different
             * number is the entire point.
             *
             * Reaching the tab is `view`. Registering a site, rotating its
             * token and changing a value is `edit`, the same split Phone uses
             * for looking at numbers versus connecting Twilio. There is no
             * `delete`: removing a site IS an edit to the set of sites, and a
             * separate switch would let an organization grant somebody the
             * right to add a host but not to take it back — which is the wrong
             * half to withhold.
             */
            new ModuleDefinition(
                key: 'web_management',
                label: 'Web Management',
                /*
                 * Drawn inside Marketing as of 2026-09-03, swapping places with
                 * Leads. It belongs there by subject: what a customer's site
                 * says about the business is the same job as what its social
                 * accounts and its listing say, and it was only ever a sidebar
                 * row of its own because it landed before Marketing had a tab
                 * strip to put it in.
                 *
                 * As with Leads, this moves nothing but the drawing.
                 * `web_management.view` and `.edit` are the same keys held by
                 * the same roles, and the WordPress plugin's token-authenticated
                 * endpoints do not go through module nesting at all.
                 */
                parent: 'marketing',
                auditExemptBecause: 'NOT AUDITED. A create, edit or delete here leaves no '
                    .'audit_events row, so there is no record of who changed what or when. '
                    .'This states a defect, not a decision, and it is not permission to stay '
                    .'this way — see TODO #35 for how to close it and delete this line.',
                actions: ['view', 'edit'],
                /*
                 * This module was automation-exempt on the grounds that
                 * "nothing here happens on its own". That stopped being true
                 * when the chat and form widgets shipped: a visitor starting a
                 * conversation on a customer's website is exactly the kind of
                 * thing that happens without anybody in EzBizi doing anything,
                 * at an hour when nobody is looking at the screen.
                 *
                 * The subject is the SITE rather than the lead. Leads already
                 * raises `lead.contact_received` for every source it holds, and
                 * a second trigger meaning the same thing would be a trap. What
                 * this adds is the one thing Leads cannot express: WHICH
                 * website, so a rule can send nj.a-1chimney.com's enquiries to
                 * the New Jersey branch and leave the rest alone.
                 */
                subject: new AutomationSubject(\App\Models\WebSite::class, 'Website'),
                triggers: [
                    new TriggerDefinition(
                        key: 'website.chat_started',
                        label: 'Live chat started',
                        description: 'Somebody on one of your websites opened the chat widget '
                            .'and sent a first message. It arrives in Leads at the same moment.',
                    ),
                    new TriggerDefinition(
                        key: 'website.form_submitted',
                        label: 'Contact form submitted',
                        description: 'Somebody filled in the contact form on one of your '
                            .'websites. It arrives in Leads at the same moment.',
                    ),
                ],
            ),

            /*
             * Phone — Twilio softphone, numbers, SMS, voicemail, and records.
             *
             * Reaching the tab is `view`. Connecting Twilio, buying numbers,
             * and changing flows/hours/ring groups is `edit`, the same split
             * Email uses for looking at inboxes versus signing one in.
             *
             * No subject: events are about a call, an SMS, a voicemail, or a
             * number, and naming any one would make the field picker wrong
             * about the others. Same cost as HR — `update_field` is refused
             * at save, in a sentence.
             *
             * Call started/ended/missed raise from the Voice and status
             * webhooks (Task 10). Voicemail raises from the recording
             * callback (Task 11). SMS received/sent raise from the SMS
             * webhook and MessagesController (Task 12). number.broken raises
             * from PhoneLineHealth on a gateway/webhook failure transition
             * (Task 14). ModuleRegistryTest holds that claim to the source.
             *
             * Actions change EzBizi only — mark a voicemail dealt with, or
             * open a job from a missed call. Nothing here places a call or
             * sends a text.
             */
            new ModuleDefinition(
                key: 'phone',

                // The KEY stays 'phone'. It is what every permission
                // (phone.view, phone.edit), every route and every automation
                // trigger is named after, and renaming it would revoke access
                // for everybody holding those permissions while looking like a
                // relabel.
                label: 'Communication',
                auditExemptBecause: 'NOT AUDITED. A create, edit or delete here leaves no '
                    .'audit_events row, so there is no record of who changed what or when. '
                    .'This states a defect, not a decision, and it is not permission to stay '
                    .'this way — see TODO #35 for how to close it and delete this line.',
                actions: ['view', 'edit'],
                triggers: [
                    new TriggerDefinition(
                        key: 'phone.call.started',
                        label: 'A call started',
                        description: 'A call began on one of the phone lines — inbound or '
                            .'outbound — and is ringing or connected.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.call.ended',
                        label: 'A call ended',
                        description: 'A call finished. It carries how long it lasted and which '
                            .'line it used.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.call.missed',
                        label: 'A call was missed',
                        description: 'Nobody answered, and the caller either hung up or reached '
                            .'voicemail.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.call.tagged',
                        label: 'A call was tagged',
                        description: 'Somebody said what a call was about. It carries the tag '
                            .'name, so a rule can act on complaints without acting on '
                            .'bookings. Raised when a tag is put on, not when one is removed.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.voicemail.left',
                        label: 'A voicemail was left',
                        description: 'A caller left a voicemail on one of the phone lines. The '
                            .'recording stays on Twilio until it is erased.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.sms.received',
                        label: 'A text arrived',
                        description: 'A text message reached one of the phone lines that can '
                            .'receive SMS.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.sms.sent',
                        label: 'A text was sent',
                        description: 'Somebody sent a text from a line that is allowed to send '
                            .'SMS.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.number.broken',
                        label: 'A phone line stopped working',
                        description: 'EzBizi could no longer use one of the phone lines — the '
                            .'Twilio account refused it, the number was invalid, or the '
                            .'subaccount was suspended. It fires once per break, not every '
                            .'retry afterwards.',
                        isRaised: true,
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'phone.voicemail.mark_dealt',
                        label: 'Mark a voicemail dealt with',
                        description: 'Records that a voicemail has been picked up so it stops '
                            .'showing as waiting. It changes what the team sees in EzBizi and '
                            .'does not call anyone.',
                    ),
                    new ActionDefinition(
                        key: 'phone.missed.create_job',
                        label: 'Create a job from a missed call',
                        description: 'Opens a job in reach titled from the caller number so '
                            .'somebody can follow up. It does not place a call or send a text.',
                    ),

                    /*
                     * NOT subject-bound, and that is the point of it.
                     *
                     * Every other Phone action works on the record the event
                     * was about — a call, a voicemail. This one is asked for
                     * precisely so a rule on something that is NOT a phone
                     * record can still send a text: an email arriving, a job
                     * changing status. The subject is whatever set the rule
                     * off and this action does not read it.
                     *
                     * So the line to send from and the number to send to are
                     * both stated in the rule. There is nothing to infer them
                     * from, and inferring is how you send a customer's text to
                     * whoever happened to be in the payload.
                     */
                    new ActionDefinition(
                        key: 'phone.sms.send',
                        label: 'Send a message',
                        description: 'Sends a text from one of the company phone lines to '
                            .'a number you give. Works on any trigger, including ones that have '
                            .'nothing to do with the phone. The line has to be approved for '
                            .'messaging first, and the message is recorded under Records like '
                            .'any other.',
                        subjectBound: false,
                    ),
                ],
            ),

            /*
             * Fleet Management — vehicles (owned or rented), keys, insurance,
             * service, and money. The tab is one list with filters; ledgers hang
             * off the vehicle rather than becoming destinations of their own.
             *
             * Extra actions beyond CRUD:
             *   assign     — hand keys over / take them back
             *   financials — see and change amounts and payments
             *
             * Service visits travel with `edit`. Amounts never travel with it.
             *
             * insurance_expiring / service_due stay unraised — no sweep in this
             * plan. ModuleRegistryTest holds that claim to the source.
             */
            new ModuleDefinition(
                key: 'fleet_management',
                label: 'Fleet Management',
                auditExemptBecause: 'NOT AUDITED. A create, edit or delete here leaves no '
                    .'audit_events row, so there is no record of who changed what or when. '
                    .'This states a defect, not a decision, and it is not permission to stay '
                    .'this way — see TODO #35 for how to close it and delete this line.',
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'assign', 'financials'],
                subject: new AutomationSubject(Vehicle::class, 'Vehicle'),
                triggers: [
                    new TriggerDefinition(
                        key: 'vehicle.created',
                        label: 'Vehicle added',
                        description: 'A vehicle was added to the fleet.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.updated',
                        label: 'Vehicle updated',
                        description: 'Identity, franchise, odometer or next-due was saved. A '
                            .'change of status, assignment, or a ledger does NOT raise this — '
                            .'those have their own events.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.status_changed',
                        label: 'Status changed',
                        description: 'A vehicle moved from one status to another. It carries '
                            .'the old status as well as the new one.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.assigned',
                        label: 'Keys handed over',
                        description: 'Somebody was given the keys to a vehicle.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.unassigned',
                        label: 'Keys taken back',
                        description: 'The open assignment ended and nobody holds the keys.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.ownership_started',
                        label: 'Ownership period started',
                        description: 'A new owned or rented period opened on the vehicle.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.insurance_started',
                        label: 'Insurance policy started',
                        description: 'A new insurance policy period opened on the vehicle.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.service_logged',
                        label: 'Service logged',
                        description: 'A service visit was saved against the vehicle.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.payment_recorded',
                        label: 'Payment recorded',
                        description: 'A payment was recorded against the vehicle.',
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.insurance_expiring',
                        label: 'Insurance expiring',
                        description: 'An open policy expires inside thirty days. No sweep '
                            .'raises this yet.',
                        isRaised: false,
                    ),
                    new TriggerDefinition(
                        key: 'vehicle.service_due',
                        label: 'Service due',
                        description: 'Next-due date or mileage was crossed. No sweep raises '
                            .'this yet.',
                        isRaised: false,
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'vehicle.assign_to_user',
                        label: 'Assign it to somebody',
                        description: 'Hands the keys to a person who works in the vehicle’s '
                            .'company.',
                    ),
                    new ActionDefinition(
                        key: 'vehicle.unassign',
                        label: 'Take the keys back',
                        description: 'Ends the open assignment so nobody holds the keys.',
                    ),
                    new ActionDefinition(
                        key: 'vehicle.change_status',
                        label: 'Change the status',
                        description: 'Moves a vehicle to a different status. Sold and returned '
                            .'close the open ownership period, the same way a person saving would.',
                    ),
                    new ActionDefinition(
                        key: 'vehicle.update_field',
                        label: 'Update a field',
                        description: 'Sets one non-money field on the vehicle that triggered '
                            .'the rule: plate, colour, odometer, or next-due.',
                    ),
                ],
            ),

            /*
             * Inventory — the register, buying, and issuing. Quantity lives on
             * (item, location); movements post balances. The tab is one list
             * with chips; money never travels with edit.
             *
             * Extra action beyond CRUD:
             *   financials — see and change costs
             *
             * Receive, transfer, issue, and adjust travel with `edit`.
             *
             * Every trigger is raised from the write path except item.low,
             * which stays unraised — no sweep in this plan. ModuleRegistryTest
             * holds those claims to the source.
             *
             * No automation action moves stock: a rule that could issue or
             * receive without a person is the same objection Email records
             * against sending.
             *
             * Subject prefix is declared `item` — class_basename(InventoryItem)
             * is inventoryitem, and boot would refuse every item.* trigger.
             */
            new ModuleDefinition(
                key: 'inventory',
                label: 'Inventory',
                auditExemptBecause: 'NOT AUDITED. A create, edit or delete here leaves no '
                    .'audit_events row, so there is no record of who changed what or when. '
                    .'This states a defect, not a decision, and it is not permission to stay '
                    .'this way — see TODO #35 for how to close it and delete this line.',
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'financials'],
                subject: new AutomationSubject(InventoryItem::class, 'Item', key: 'item'),
                triggers: [
                    new TriggerDefinition(
                        key: 'item.created',
                        label: 'Item added',
                        description: 'A catalogue item was saved for the first time.',
                    ),
                    new TriggerDefinition(
                        key: 'item.updated',
                        label: 'Item updated',
                        description: 'Identity, status, or reorder point was saved. A movement '
                            .'does not raise this — those have their own events.',
                    ),
                    new TriggerDefinition(
                        key: 'item.received',
                        label: 'Stock received',
                        description: 'Stock arrived against a purchase order and the balance '
                            .'went up.',
                    ),
                    new TriggerDefinition(
                        key: 'item.issued',
                        label: 'Stock issued',
                        description: 'Stock left a location, for a job or with a note.',
                    ),
                    new TriggerDefinition(
                        key: 'item.transferred',
                        label: 'Stock transferred',
                        description: 'Stock moved from one location to another in a single save.',
                    ),
                    new TriggerDefinition(
                        key: 'item.adjusted',
                        label: 'Count adjusted',
                        description: 'On-hand at a location was counted as a new quantity.',
                    ),
                    new TriggerDefinition(
                        key: 'item.ordered',
                        label: 'Purchase order opened',
                        description: 'A purchase order left draft and is now open to receive.',
                    ),
                    new TriggerDefinition(
                        key: 'item.purchase_received',
                        label: 'Purchase order fully received',
                        description: 'Every line on a purchase order has been fully received.',
                    ),
                    new TriggerDefinition(
                        key: 'item.sale_opened',
                        label: 'Sale opened',
                        description: 'A sales order left draft and can be fulfilled.',
                    ),
                    new TriggerDefinition(
                        key: 'item.sold',
                        label: 'Stock sold',
                        description: 'Stock left a location against a sales order.',
                    ),
                    new TriggerDefinition(
                        key: 'item.sale_fulfilled',
                        label: 'Sale fully fulfilled',
                        description: 'Every line on a sales order has been fully fulfilled.',
                    ),
                    new TriggerDefinition(
                        key: 'item.low',
                        label: 'Stock is low',
                        description: 'On-hand at a visible location is at or below the reorder '
                            .'point. No sweep raises this yet.',
                        isRaised: false,
                    ),
                ],
            ),

            new ModuleDefinition(
                key: 'services',
                label: 'Services',
                auditExemptBecause: 'NOT AUDITED. A create, edit or delete here leaves no '
                    .'audit_events row, so there is no record of who changed what or when. '
                    .'This states a defect, not a decision, and it is not permission to stay '
                    .'this way — see TODO #35 for how to close it and delete this line.',
                actions: ModuleDefinition::DEFAULT_ACTIONS,
                subject: new AutomationSubject(Service::class, 'Service'),
                /*
                 * No `automationActions`, and that is a ruling rather than an
                 * oversight: spec decision 8 in
                 * docs/superpowers/specs/2026-08-25-services-design.md reads
                 * "No automation action writes the catalogue."
                 *
                 * The spec records the decision and NOT the reason for it, so
                 * neither does this comment. Anyone who wants to know why, or
                 * who wants to add an action here, is re-opening a human
                 * decision and should go and ask rather than infer a rationale
                 * from a plausible-sounding sentence in a provider. Triggers
                 * are the other direction — listening, not writing — and are
                 * declared below.
                 */
                triggers: [
                    new TriggerDefinition(
                        key: 'service.created',
                        label: 'Service added',
                        description: 'A service was saved for the first time.',
                    ),
                    new TriggerDefinition(
                        key: 'service.updated',
                        label: 'Service updated',
                        description: 'Name, price, description, group, or label was saved.',
                    ),
                    new TriggerDefinition(
                        key: 'service.deleted',
                        label: 'Service deleted',
                        description: 'A service was removed from the catalogue.',
                    ),
                ],
            ),

            /*
             * ── Leads: enquiries from connected advertising ──────
             *
             * The first module whose data belongs to somebody outside this
             * building. A Local Services enquiry is a member of the public who
             * left a phone number expecting a call back about work, and every
             * decision below follows from that rather than from what the API
             * makes possible.
             *
             * It reads the tenant's OWN Google Ads account, connected by the
             * tenant through consent they can withdraw. EzBizi holds one OAuth
             * registration for the installation and never a credential per
             * customer; see `config/services.php`.
             *
             * ── Why this is not one module with Reviews ──────────
             *
             * A ModuleDefinition holds ONE AutomationSubject and
             * assertKeysMatchSubject refuses a trigger key whose prefix
             * disagrees with it. A lead and a review are different records, so
             * one module could only carry both by declaring no subject at all —
             * the HR compromise — and losing the field picker for both. Three
             * subjects genuinely exist here, so there are three modules.
             *
             * It also sells better apart: a company with Maps listings and no
             * advertising wants Reviews and has no use for this.
             *
             * ── What is deliberately NOT declared ────────────────
             *
             * Anything that REPLIES to an enquirer. The engine could hold the
             * phone number and the message; what it cannot hold is the judgement
             * about whether this particular stranger should receive an automatic
             * text in the customer's name. Marketing refused to let a rule write
             * a newsletter for the same reason, and the reason survives the
             * change of audience: the value of the message is that a person read
             * it first.
             *
             * `lead.converted` IS declared and marked not raised. Nothing turns
             * a lead into a customer and a job yet. Naming it now shows the
             * builder where this goes; the catalogue marks it per row, so
             * nobody builds a rule on an event that cannot arrive.
            */
            new ModuleDefinition(
                key: 'leads',
                label: 'Leads',
                /*
                 * A row of its own, as of 2026-09-03. It was drawn inside
                 * Marketing until Thumbtack became a second lead source: one
                 * source is a tab, two are a module, and burying "where does
                 * work come from" under Marketing stopped matching how anybody
                 * uses it. Web Management moved the other way in the same
                 * change and took the slot.
                 *
                 * Its permissions did not move with it, and could not: `parent`
                 * says where a module is DRAWN and nothing else. `leads.view`
                 * is the same key it was, held by the same roles, and the
                 * automations keyed on `lead.*` are untouched.
                 */
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'export'],
                subject: new AutomationSubject(Lead::class, 'Lead'),
                triggers: [
                    new TriggerDefinition(
                        key: 'lead.received',
                        label: 'Lead received',
                        // "A connected account", not "a connected ADVERTISING
                        // account". Thumbtack is a marketplace rather than
                        // advertising, and it started raising this on
                        // 2026-09-08 — somebody building a rule off the old
                        // wording would reasonably conclude it did not cover
                        // their Thumbtack enquiries, and quietly not build it.
                        description: 'An enquiry arrived from a connected account — Google Ads or '
                            .'Thumbtack. It carries the contact details as the enquirer gave them, '
                            .'which source and location it came through, and when it arrived — not '
                            .'yet what it is about.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.contact_received',
                        label: 'Enquirer called or messaged',
                        description: 'The person behind a lead reached out again — a call, or a '
                            .'message — and Google recorded it. It carries the channel, when, and '
                            .'the first line of what they wrote. The business\'s own replies do '
                            .'not raise it.',
                    ),
                    /*
                     * Separate from "received" because the gap between them is
                     * real and sometimes minutes long: a voicemail has to be
                     * transcribed before anything can be said about it. A rule
                     * that routes by job type has to wait for this one; a rule
                     * that just alerts somebody must not.
                     */
                    new TriggerDefinition(
                        key: 'lead.classified',
                        label: 'Lead classified',
                        description: 'A lead was read and categorised — what kind of work it is '
                            .'about, and whether it looks like a real enquiry at all. It fires '
                            .'after "Lead received", once any recording has been transcribed.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.status_changed',
                        label: 'Status changed',
                        description: 'Somebody moved the lead — booked, archived, or marked not a '
                            .'real enquiry. It carries who moved it and what it was before. The '
                            .'advertising source\'s own status is a separate field and does not '
                            .'raise this.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.converted',
                        label: 'Lead became a job',
                        description: 'A lead was turned into a customer and a booked job, carrying '
                            .'its source through so the work can be attributed back to the '
                            .'advertising that produced it.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'lead.update_field',
                        label: 'Update a field',
                        description: 'Sets one field on the lead that triggered the rule: its '
                            .'status, or the summary kept against it. It cannot change the contact '
                            .'details the enquirer gave, which are a record of what they said.',
                    ),
                ],
            ),

            /*
             * ── Reviews: what the public said, and the answer ────
             *
             * Sold separately from Leads on purpose. A business with three Maps
             * listings and no advertising at all wants this and has no use for
             * Local Services enquiries; the reverse is just as common.
             *
             * ── Five actions, and the fifth is the whole point ───
             *
             * DEFAULT_ACTIONS plus `publish`. `edit` writes a draft nobody
             * outside the building can see. `publish` puts words on Google
             * under the organization's name, in public, permanently, where a
             * stranger reads them next to the review they wrote. Those are
             * routinely different people — whoever composes the answer to an
             * angry review is very often not whoever should decide it goes out
             * — and one grant covering both would make that impossible to say
             * on the Roles screen.
             *
             * Same split as `inventory.financials`: a second permission for the
             * half of a module that carries the consequence.
             *
             * `create` is granted and nothing calls it. Nobody writes a review
             * here; every row is fetched from Google. It stays because
             * DEFAULT_ACTIONS carries it and a gap in the set reads as an
             * oversight rather than a decision.
             *
             * ── What is deliberately NOT declared ────────────────
             *
             * `review.publish_reply` as an ACTION. A rule that could publish
             * would be a rule that speaks for the business to the public with
             * nobody reading it first, and the failure is not hypothetical: the
             * reply most worth automating is the one to a furious customer, and
             * that is exactly the one a person must see. Drafting is offered
             * instead — it removes the blank page, not the judgement.
             *
             * Marketing refused to let a rule compose a newsletter for the same
             * reason, and the reason survives the change of audience.
             */
            new ModuleDefinition(
                key:     'reviews',
                label:   'Reviews',
                // Drawn as a tab inside Marketing rather than as a row of its own
                // in a sidebar that already holds fifteen. Its permissions are
                // untouched by that: this says where it appears, not who reaches it.
                parent:  'marketing',
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'publish'],
                subject: new AutomationSubject(Review::class, 'Review'),
                triggers: [
                    new TriggerDefinition(
                        key:   'review.received',
                        label: 'Review received',
                        description: 'A new review arrived on a connected Business Profile. It '
                            .'carries the rating, the text as the reviewer wrote it, and which '
                            .'location it is about.',
                    ),
                    new TriggerDefinition(
                        key:   'review.reply_published',
                        label: 'Reply published',
                        // The distinction somebody will get wrong. Drafting
                        // produces text; publishing is the moment it becomes
                        // public under the customer's name. Only the second
                        // raises this.
                        description: 'An approved reply went live on Google. Drafting a reply does '
                            .'not raise this — only publication does.',
                    ),
                    new TriggerDefinition(
                        key:   'review.reply_failed',
                        label: 'Reply failed to publish',
                        description: 'Google refused a reply somebody tried to publish. It carries '
                            .'the reason, and the wording is kept rather than retried silently, so '
                            .'a person decides what happens next.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key:   'review.draft_reply',
                        label: 'Draft a reply',
                        description: 'Writes a suggested reply to the review that triggered the '
                            .'rule and leaves it waiting for a person. It does not publish, and '
                            .'nothing in this module can.',
                    ),
                ],
            ),

            /*
             * ── Business Profile: the listing the public sees ────
             *
             * The third of the EZ Leads modules, and the one whose subject is
             * neither an enquiry nor an opinion but the shop front itself:
             * what Google shows a stranger who looks this business up.
             *
             * ── Why it is not Reviews, and not Franchises ────────
             *
             * Not Reviews, because assertKeysMatchSubject would refuse the
             * keys: a review and a location are different records and a module
             * holds one subject. Not Franchises either, and that one is worth
             * saying out loud because it is the tempting shortcut — a company
             * may hold thirty listings and no franchise records at all, and a
             * franchise may cover several listings. The link between them is
             * optional, set by a person, and nothing derives one from the
             * other.
             *
             * ── One trigger earns this module its place ──────────
             *
             * `changed_on_google`. Hours or a phone number changing without
             * anybody here doing it is a Google edit, an accepted public
             * suggestion, or somebody with access nobody remembers granting.
             * All three are worth a rule, and none of them is visible today
             * until a customer rings to say nobody answered.
             *
             * ── No actions, and that is a decision ───────────────
             *
             * The API can write hours, descriptions, categories and phone
             * numbers. None is offered to a rule. Changing what the public is
             * told about a real business is not something that should happen
             * because a condition matched at three in the morning, and
             * satisfiesAutomationRule() passes on triggers alone — so the
             * absence costs nothing but has to be recorded as intent, which
             * is what this paragraph is.
             */
            new ModuleDefinition(
                key:     'business_profile',
                label:   'Business Profile',
                auditActionPrefixes: ['business_location'],
                // Drawn as a tab inside Marketing rather than as a row of its own
                // in a sidebar that already holds fifteen. Its permissions are
                // untouched by that: this says where it appears, not who reaches it.
                parent:  'marketing',
                actions: ModuleDefinition::DEFAULT_ACTIONS,
                // `key:` declared for the same reason InventoryItem declares
                // `item`: the default is strtolower(class_basename()), which
                // would make this `businesslocation` and read as one word
                // nobody says.
                subject: new AutomationSubject(
                    BusinessLocation::class,
                    'Business location',
                    key: 'business_location',
                ),
                triggers: [
                    new TriggerDefinition(
                        key:   'business_location.changed_on_google',
                        label: 'Changed on Google',
                        description: 'A connected listing changed on Google without the change '
                            .'being made here — its name, phone, address, category, or whether '
                            .'Google is showing it at all. It carries which fields moved, and '
                            .'what they were before.',
                    ),
                    new TriggerDefinition(
                        key:   'business_location.franchise_set',
                        label: 'Location assigned to a branch',
                        description: 'Somebody said which franchise answers for a listing. It is '
                            .'a deliberate act rather than part of a sync, and the sync never '
                            .'changes it — so this fires when a person decides, and never when '
                            .'Google is read.',
                    ),
                ],
            ),

            /*
             * ── Email: shared team inboxes ───────────────────────
             *
             * The owner: *"In this module the client will be able to add his
             * emails to this ezbizi module [and it] will work as a Mailbox."*
             * And what it is FOR, which rules a great deal out: *"the capacity
             * to let people answer emails they don't regularly have access to —
             * like company wide emails that receive customer emails or forms"*.
             *
             * So it is an access-controlled WINDOW onto addresses like info@,
             * and not a CRM mail integration: mail is not linked to customers or
             * jobs, nothing is mirrored into the database, and messages are read
             * from the provider while somebody is looking at them.
             *
             * ── Four actions, and each has code behind it ────────
             *
             * A permission with nothing behind it enforces nothing and shows up
             * on the roles screen as a switch granting a capability the software
             * does not have (see ModuleRegistry, and Marketing's note). All four
             * are real here, and the split between them is the one that matters
             * in this module:
             *
             *   view    — READ an inbox. Also requires a grant for that
             *             particular inbox, which is not a permission and
             *             cannot be one; see App\Models\MailboxGrant for why
             *             per-inbox access is a tenant record rather than a row
             *             in the platform-wide `permissions` table.
             *   create  — connect a new address.
             *   edit    — rename or reconnect one, and hand a role access to it.
             *   delete  — disconnect one.
             *
             * Connecting an inbox and READING it are deliberately different
             * authorities: whoever sets a mailbox up is usually not whoever
             * answers it, and reading is the invisible one. App\Support\Email\
             * MailboxAccess holds that split.
             *
             * ── No subject, and the reason is this module's own ──
             *
             * HR declares none because its events are about three kinds of
             * record; Marketing because its record does not exist yet. This one
             * declares none because the record CANNOT exist: what these events
             * are ABOUT is a MESSAGE, and there is deliberately no Message model
             * — the owner's decision was *"we don't want to store thousands and
             * thousands of emails on our database"*, so a message is fetched
             * live and never written down. AutomationSubject takes a class-
             * string of an Eloquent model, and there is no honest one to name.
             *
             * The MAILBOX is what each event carries as its subject, which is a
             * different thing and worth not confusing with this: it is what
             * gives the engine a scope, so a branch manager's rule fires on
             * their branch's inbox and nobody else's (App\Models\Mailbox::
             * automationScope). It does not make a mailbox something a
             * condition can read fields off, which is what declaring a subject
             * here would offer.
             *
             * It costs what it costs HR: the builder cannot grey out "Update a
             * field" under one of these triggers in advance, so somebody can
             * pick it and be refused at save, in a sentence.
             */
            new ModuleDefinition(
                key: 'email',
                label: 'Email',
                auditActionPrefixes: ['mailbox', 'sending_identity'],
                triggers: [
                    /*
                     * THE ONE THIS MODULE EXISTS FOR, AND IT FIRES.
                     *
                     * "An email arrived at a shared inbox" is the most valuable
                     * trigger in this application — it is the front door of a
                     * service business, and every rule somebody would want here
                     * hangs off it: tell the branch, raise a task, chase it if
                     * nobody has opened it by the afternoon.
                     *
                     * ── What this said before, and why it was true ──
                     *
                     * It was declared `isRaised: false`, and the reason ran to a
                     * paragraph: reading was live and pull-based, mail was
                     * fetched when somebody opened the screen and at no other
                     * time, NOTHING POLLED — so no code anywhere learned that a
                     * message existed until a human being looked. It also said
                     * what would have to exist first: a Gmail push subscription
                     * on a public webhook, or an IMAP IDLE connection held open
                     * by a worker.
                     *
                     * It turned out the third option was the right one and the
                     * paragraph had not considered it. Both readers work now, so
                     * there is something to ask — and ASKING, on a timer, needs
                     * no public endpoint and no subscription to renew.
                     * App\Console\Commands\WatchMailboxes polls each connected
                     * inbox every few minutes and inserts an outbox row per new
                     * message, which is the same write path every other event in
                     * the application uses.
                     *
                     * ── The two things the wording below must not hide ──
                     *
                     * IT IS MINUTES LATE, NOT INSTANT. Said plainly, because a
                     * rule that pages somebody the second a message lands is one
                     * this cannot honour, and finding that out afterwards is
                     * worse than knowing.
                     *
                     * A NEWLY CONNECTED INBOX ANNOUNCES NOTHING. The first look
                     * takes a position and stays silent, deliberately: an inbox
                     * with four thousand messages already in it must not raise
                     * four thousand events, none of which describes anything
                     * that happened. Somebody who connects an address and sees
                     * no rule fire for the mail already sitting in it has met a
                     * decision, not a bug, and this is where they can read it.
                     */
                    new TriggerDefinition(
                        key: 'email.received',
                        label: 'An email arrived',
                        description: 'A new message reached one of the shared inboxes. It '
                            .'carries which inbox it arrived at, who it is from, the subject, '
                            .'when it was sent and whether anything is attached — never the '
                            .'contents, which are not stored anywhere. EzBizi checks each '
                            .'connected inbox every few minutes rather than the instant mail '
                            .'lands, so a rule on this runs within about five minutes rather '
                            .'than immediately. Connecting an inbox for the first time raises '
                            .'nothing for the mail already in it: EzBizi notes where the '
                            .'mailbox has got to and announces what arrives after that. The '
                            .'same applies after a long gap — if nothing has looked at an inbox '
                            .'for over a day, EzBizi starts again from the present rather than '
                            .'announcing a day of mail at once.',
                    ),

                    /*
                     * The other half, and this one IS raised — by
                     * MailboxMessagesController after a reply actually leaves.
                     *
                     * Worth having on day one rather than day two: "somebody
                     * answered from the shared inbox" is the event a manager
                     * builds a rule on, and it is the only thing that happens in
                     * this module that this application itself causes.
                     */
                    new TriggerDefinition(
                        key: 'email.replied',
                        label: 'A reply was sent',
                        description: 'Somebody answered a message from a shared inbox. It '
                            .'carries which inbox it went out from and who typed it — the '
                            .'reply goes to the customer as the inbox itself, never as the '
                            .'individual, so this is the only place that records which of them '
                            .'it was.',
                    ),

                    /*
                     * Starting a conversation, as against answering one.
                     *
                     * Separate from `email.replied` rather than folded into it
                     * with a flag, because the two mean different things to
                     * somebody writing a rule: "we answered a customer" is
                     * follow-up, "we contacted a customer first" is outreach,
                     * and a rule that fired on both would be useless for either.
                     *
                     * The payload carries a recipient COUNT and not the
                     * addresses. Rules are tenant-authored and this table is
                     * read by them, so putting customer addresses here would
                     * make every rule author a reader of the mailing list —
                     * same reasoning as keeping the words out of the audit log.
                     */
                    new TriggerDefinition(
                        key: 'email.sent',
                        label: 'A new message was sent',
                        description: 'Somebody started a new conversation from a shared inbox, '
                            .'rather than answering one. It carries which inbox it went out '
                            .'from, how many recipients it went to, and who typed it — the '
                            .'message goes out as the inbox itself, never as the individual.',
                    ),

                    /*
                     * AN INBOX STOPPING IS A BUSINESS EVENT, not a technical
                     * one.
                     *
                     * A shared inbox that quietly stops working is the worst
                     * failure this module has: customer mail goes on arriving at
                     * an address nobody can see, and the first anybody hears of
                     * it is a customer asking why they were ignored. So it is
                     * worth a rule — tell the branch manager, raise a task —
                     * and App\Support\Email\RecordingMailboxReader emits it the
                     * moment a read fails for a reason that means the connection
                     * is broken.
                     *
                     * ── WHEN IT FIRES, AND WHAT CHANGED ─────────
                     *
                     * This used to say that it fired only when somebody OPENED
                     * a broken inbox, not at the moment it broke, because
                     * nothing watched the inboxes — so an inbox nobody looked at
                     * for a fortnight raised nothing for a fortnight. That was
                     * the honest description of the module as it stood, and it
                     * was the worst kind of alert: the inboxes least likely to
                     * be opened are precisely the ones whose silence nobody
                     * notices.
                     *
                     * `ezbizi:watch-mailboxes` polls every connected inbox now,
                     * and it goes through the same reader wrapper every screen
                     * does — so a mailbox that stops working is found within
                     * minutes by the timer rather than whenever somebody
                     * happens to click. Nothing about the emission changed to
                     * make that true; the polling simply arrives at the same
                     * funnel.
                     *
                     * It still fires ONCE per break, not once per attempt, and
                     * that rule matters far more now than it did. A mailbox
                     * already recorded as broken is refused on every attempt,
                     * and a timer attempting it every five minutes would have
                     * sent somebody a message every five minutes until they
                     * switched the rule off. See RecordingMailboxReader, which
                     * raises this only on the TRANSITION.
                     */
                    new TriggerDefinition(
                        key: 'email.mailbox_broken',
                        label: 'A shared inbox stopped working',
                        description: 'EzBizi could no longer open one of the shared inboxes — '
                            .'the sign-in was refused, the permission was narrowed, or the mail '
                            .'service stopped answering. It carries which inbox, which of those '
                            .'causes it was, and the same sentence the Email settings screen '
                            .'shows. Mail keeps arriving at the address; what has stopped is '
                            .'EzBizi being able to show it. EzBizi checks each connected inbox '
                            .'every few minutes, so this fires shortly after an inbox stops '
                            .'working rather than waiting for somebody to open it — and only '
                            .'once per break, not every time it is tried again afterwards. '
                            .'Reconnecting the inbox clears it.',
                    ),
                ],
                /*
                 * Deliberately no action that SENDS. A rule that could compose
                 * and send mail from a business's public address, to whoever
                 * wrote in, with nobody reading it first, is the same objection
                 * Marketing records against a rule that writes a newsletter —
                 * and it is worse here, because the recipient is a specific
                 * customer who asked a specific question.
                 *
                 * The one action offered is the one that is safe by
                 * construction: it changes something inside EzBizi and sends
                 * nothing to anybody outside it.
                 */
                automationActions: [
                    new ActionDefinition(
                        key: 'email.mark_seen',
                        label: 'Mark a message as dealt with',
                        description: 'Records that a message in a shared inbox has been picked '
                            .'up, so it stops showing as waiting for somebody. It changes what '
                            .'the team sees in EzBizi and sends nothing to anyone.',
                    ),
                ],
            ),

            /*
             * Training — guides people read and tests they take, and the record
             * of who has done which.
             *
             * Actions beyond `view`: `monitor` sees other people's results,
             * `manage` writes the guides and the tests, `assign` gives one to
             * somebody. Deliberately not the CRUD set: reading your own training
             * and looking at everybody else's are different acts, and only the
             * second is the one an organization withholds.
             *
             * ── No subject, and the reason is Human Resources' ──
             *
             * `AutomationSubject` binds a module to ONE Eloquent class, because
             * `ActionRunner::updateField` writes to the subject of the EVENT and
             * the trigger is what decides the field list. That works for a
             * module whose events are all about one kind of record. These are
             * not:
             *
             *   assigned  — an assignment: what was given, to a person OR a role
             *   completed — a guide's progress: somebody reached the last screen
             *   passed    — an attempt: a score against a pass mark
             *   failed    — an attempt
             *   expired   — a pass that has run out of the period it was good for
             *
             * Three records, not one — and `assertKeysMatchSubject` cannot tell
             * which of the three is right, because it compares the declared key
             * PREFIX and never the class.
             *
             * Left to default a subject keys as `strtolower(class_basename())`,
             * so naming any of these three unqualified would key as
             * `trainingassignment`, `trainingprogress` or `trainingattempt`,
             * none of which is the `training.` these keys begin with, and boot
             * would refuse it. That is not the check catching a wrong subject
             * though — it is the check catching a compound class name, which is
             * what the `key:` override exists for. Inventory passes
             * `key: 'item'` for exactly that reason. Pass `key: 'training'` here
             * and any one of the three sails through identically.
             *
             * So the constructor cannot enforce this for us; it is a claim that
             * has to be true. Naming the attempt would
             * make the builder tell somebody writing a rule on "Training
             * assigned" that it reacts to something about an attempt, and the
             * field picker would offer an attempt's fields for an assignment's
             * event. Wrong about four triggers to be right about two.
             *
             * Marketing declares none because there is no model at all; this
             * declares none for HR's reason, which is the opposite problem.
             *
             * What null costs is one affordance and nothing else: the builder
             * cannot grey out "Update a field" under a Training trigger in
             * advance, so somebody can pick it and be refused at save, in a
             * sentence. Nothing more is lost, because `ActionRunner::WRITABLE`
             * holds Job, Customer and Vehicle and nothing else — no field on any
             * Training record is writable by a rule in the first place, so there
             * was never a field list here for a subject to unlock.
             *
             * The proper fix is a subject per TRIGGER rather than per module,
             * which is a change to the wire shape the catalogue publishes as
             * well as to ModuleDefinition. Recorded where HR records it, and not
             * done quietly here.
             *
             * ── `isRaised` is declared per trigger, and checked against reality ──
             *
             * This task declared the module with every trigger `isRaised: false`:
             * nothing emitted any of them yet, and `isRaised: false` is what puts
             * "nothing raises this yet" on the row a tenant reads. `training.completed`
             * has since been flipped to `isRaised: true` (task 7's ProgressController
             * raises it on a guide's final screen), `training.passed` /
             * `training.failed` have since been flipped to `isRaised: true` as well
             * (task 8's LearnController::submit emits both), `training.assigned`
             * has since been flipped to `isRaised: true` too (task 9's
             * AssignmentWriter::create emits it), and `training.expired` has since
             * been flipped to `isRaised: true` as well (task 10's
             * ExpireTrainingPasses nightly sweep emits it) — all five of this
             * module's triggers are now raised; none remain declared
             * `isRaised: false`.
             * ModuleRegistryTest holds the claim to the source for every one of the
             * five independently: the day any of them is emitted, the build fails
             * until its own flag is dropped, rather than the builder going on
             * promising a working rule will never run.
             */
            new ModuleDefinition(
                key: 'training',
                label: 'Training',
                /*
                 * The first module to declare this, and the worked example for
                 * the other nineteen.
                 *
                 * NOT `subject:` — that names the one model whose fields an
                 * `update_field` action may set, and Training emits three record
                 * types (progress now; attempts and assignments in Tasks 8-10).
                 * Naming one would make the field picker silently wrong about the
                 * other two, which is why this module correctly declares no
                 * subject at all.
                 *
                 * `emits` is the audience question instead: every record type
                 * passed to `events->emit()`, so a test can ask of each whether it
                 * can say where it sits. TrainingProgress implements
                 * CarriesAutomationScope, declared in the same commit that flipped
                 * `training.completed` to raised.
                 */
                /*
                 * Three, because Training's five triggers carry three different
                 * records: a guide finished is progress, a test passed or failed
                 * is an attempt, and training given out is an assignment. That is
                 * the same multiplicity that makes `subject` null here — but where
                 * `subject` had to pick one and so picks none, `emits` can name all
                 * three, which is the whole reason it exists.
                 */
                emits: [
                    \App\Models\TrainingProgress::class,
                    \App\Models\TrainingAttempt::class,
                    \App\Models\TrainingAssignment::class,
                ],
                actions: ['view', 'monitor', 'manage', 'assign'],
                triggers: [
                    new TriggerDefinition(
                        key: 'training.assigned',
                        /*
                         * "Somebody" was wrong for half the feature and this is
                         * a PUBLISHED sentence, so it is worth the words.
                         * `assignee_type` is `user` or `role`, and a role
                         * assignment is deliberately not expanded into one row
                         * per person — so one row raises one event, and what it
                         * carries may be a role rather than anybody in
                         * particular. A rule that assumed a person would be
                         * written against a description that promised one.
                         */
                        label: 'Training assigned',
                        description: 'A guide or a test was given out, to one person or to a '
                            .'whole role at once. It carries what was given, who or which role '
                            .'it went to, who gave it, and the date it is due if there is one.',
                        // Raised by AssignmentWriter::create (task 9), inside
                        // the same transaction as the assignment row it
                        // fires from — see its own docblock.
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'training.completed',
                        label: 'Guide finished',
                        description: 'Somebody reached the end of a guide. This is about reading '
                            .'it, not about passing anything — a guide with no test raises only '
                            .'this.',
                        // Raised by ProgressController::seen (task 7), on the
                        // outbox and inside the same transaction as the
                        // progress row — see its docblock. `training.passed` /
                        // `training.failed` are raised too (task 8),
                        // `training.assigned` is raised too (task 9), and
                        // `training.expired` is raised too (task 10) — see the
                        // block comment above.
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'training.passed',
                        label: 'Test passed',
                        description: 'Somebody took a test and met its pass mark. It carries the '
                            .'score and, when the test expires, the date the pass runs out.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'training.failed',
                        label: 'Test failed',
                        description: 'Somebody took a test and did not meet the pass mark. '
                            .'Whether they may try again is the test\'s own retake setting.',
                        isRaised: true,
                    ),
                    /*
                     * Expiry is a fact about the CLOCK, and this is the event
                     * that makes the whole "a pass runs out" option worth
                     * having: "re-assign the ladder guide when the pass lapses"
                     * is the rule an organization writes once.
                     *
                     * The nightly sweep INSERTS this into the outbox. It must
                     * never dispatch: one rule failing while re-assigning one
                     * lapsed pass would otherwise take down the sweep for
                     * everybody else's.
                     */
                    new TriggerDefinition(
                        key: 'training.expired',
                        label: 'Pass ran out',
                        description: 'A test pass reached the end of the period it was good for. '
                            .'The training reappears in that person\'s list as due for retaking.',
                        // Raised by ExpireTrainingPasses (task 10), the nightly
                        // sweep — an INSERT into the outbox, never a dispatch;
                        // see that command's own docblock and StartUp.md §10.
                        isRaised: true,
                    ),
                ],
                automationActions: [
                    /*
                     * `executionType` is spelled out, and it is load-bearing.
                     *
                     * The catalogue marks a row implemented by looking up
                     * `ActionDefinition::type()` — everything after the FIRST
                     * dot — in AutomationsController::IMPLEMENTED_ACTIONS. Left
                     * to default this would reduce to the bare word `assign`, in
                     * a namespace every module shares, and the entry in that
                     * const would never match: the action would publish as "not
                     * built yet" forever with the const looking correct in the
                     * diff. time_clock spells its two out for the same reason.
                     */
                    new ActionDefinition(
                        key: 'training.assign',
                        label: 'Assign training',
                        description: 'Give somebody a guide or a test, optionally with a date it '
                            .'is due by. Paired with "Account activated" this is how an induction '
                            .'arrives on its own.',
                        executionType: 'training.assign',
                    ),
                ],
            ),

            new ModuleDefinition(
                // Renamed from 'workflows' on 2026-08-10, permission keys and
                // all — see the rename migration of that date. The product says
                // "automation", so the module key does too.
                key: 'automations',
                label: 'Automations',
                // Deliberately outside automation itself. An automation that
                // could create or edit automations is a self-modifying system:
                // the loop guards count actions, not rules, so a rule that
                // writes rules escapes them entirely. It stays a human act.
                automationExemptBecause: 'An automation that could write automations would escape the loop guards.',
            ),

            /*
             * Monitoring — 2026-08-28-monitoring-pc-specs.
             *
             * Not CRUD. A device is reported by the machine that runs the
             * client, never created or edited by an admin, so the only
             * standing grant is `view`. `specs.view` is a second, narrower
             * grant so an organization can let a manager see who is online
             * without also handing them the hardware inventory underneath.
             *
             * The subject names 'monitoring' explicitly rather than letting
             * it derive from the class: MonitoredDevice would default to
             * `monitoreddevice`, and the triggers below are keyed
             * `monitoring.*` — the same compound-name case InventoryItem
             * documents itself (it keys as `item`, not `inventoryitem`).
             *
             * Both triggers started `isRaised: false` — this module was
             * registered before anything under app/ emitted either event, and
             * ModuleRegistryTest's standing check refuses `isRaised: true`
             * until something does (its own failure message: "declared as
             * raised, but nothing in app/ mentions it... declare isRaised:
             * false until something emits it"). `new_device_seen` flipped to
             * true (the default — the parameter is omitted below) once
             * HeartbeatController::store() started calling
             * EventEmitter::emit('monitoring.new_device_seen', ...) on a
             * newly-created device (2026-08-28-monitoring-pc-specs, task 4).
             * `device_offline` flipped the same way once DeviceOfflineWorker
             * started calling EventEmitter::emit('monitoring.device_offline',
             * ...) from its scheduled sweep (task 6).
             *
             * `cameras.view` / `cameras.manage` (2026-09-01-monitoring-cameras,
             * generic-protocol v1) added the same add-only way `screenshots.view`
             * was: a camera is CRUD (unlike a device, which is only ever
             * reported), so it gets its own write grant rather than reusing
             * `view` — see MonitoringCameraPolicy.
             */
            new ModuleDefinition(
                key: 'monitoring',
                label: 'Monitoring',
                // `control` is deliberately separate from `screenshots.view`:
                // driving another machine's mouse and keyboard is a bigger
                // authority than watching its screen, and an organization
                // must be able to grant one without the other — see
                // RemoteControlController's docblock (2026-09-02-monitoring-remote-control).
                actions: ['view', 'specs.view', 'screenshots.view', 'cameras.view', 'cameras.manage', 'control'],
                triggers: [
                    new TriggerDefinition(
                        key: 'monitoring.new_device_seen',
                        label: 'New device seen',
                        description: 'A person signed in from a device not seen before.',
                    ),
                    new TriggerDefinition(
                        key: 'monitoring.device_offline',
                        label: 'Device went offline',
                        description: 'A device stopped reporting for longer than the allowed gap.',
                    ),
                ],
                subject: new AutomationSubject(MonitoredDevice::class, 'Device', key: 'monitoring'),
                sidebar: true,
            ),

            /*
             * `data` — "Import & export" — used to be declared here, last in the
             * list. It was removed on 2026-08-12 and the reasoning is left
             * behind, because "why is there no import module" is a question
             * somebody will ask by proposing to add one back.
             *
             * The owner: "get rid of the import & export tab as well, erase it
             * entirely, as the import exports will go inside each of the tabs,
             * not a separate tab to do all of them, it's more intuitive that
             * way." Exporting customers is a control in the Customers tab now,
             * and exporting jobs one in Jobs.
             *
             * Nothing was lost with it. Its exemption said import and export act
             * THROUGH other modules, and that is why removing it costs no
             * automation: it declared no trigger and no action, so no rule could
             * ever have been built on one, and none needed rehoming. It carried
             * exactly one permission, `data.view`, and that permission never
             * gated any of the routes — export has always required the view
             * permission of the resource being exported, which is what makes the
             * per-tab controls correct without a new grant. The stranded rows are
             * dropped by the migration of the same date.
             *
             * The two §9 answers that are about the ORGANIZATION rather than a
             * resource — export everything, and the record of what is held about
             * people — had no tab to move into, so they are in Settings under the
             * organization's own data. They are still gated as they always were:
             * on holding the view permission of every exportable resource.
             */
        ];
    }
}
