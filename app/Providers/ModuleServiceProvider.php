<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\User;
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
             * each is now reached through the permission of the thing it acts
             * on — `customers.view` exports customers, and always did. The
             * routes did not move and were never gated on `data.view`; see the
             * import/export block in routes/api.php.
             *
             * `view` only. The dashboard has no action beyond looking — it
             * composes what other modules already permit. It is declared FIRST
             * because the registry's order is the sidebar's order, and it is the
             * screen somebody opens the app to look at.
             */
            new ModuleDefinition(
                key:     'dashboard',
                label:   'Dashboard',
                actions: ['view'],
                automationExemptBecause:
                    'The dashboard only reads what other modules already record; '
                    .'there is no event here that another module does not raise first.',
            ),

            new ModuleDefinition(
                key:   'organizations',
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
                        key:   'organization.settings_changed',
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
                        key:   'organization.notify_administrators',
                        label: 'Notify the administrators',
                        description: 'Sends an in-app notification to the people who '
                            .'administer this organization.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key:   'companies',
                label: 'Companies',
                subject: new AutomationSubject(Company::class, 'Company'),
                triggers: [
                    new TriggerDefinition(
                        key:   'company.created',
                        label: 'Created',
                        description: 'A new company was added to the organization.',
                    ),
                    new TriggerDefinition(
                        key:   'company.updated',
                        label: 'Updated',
                        description: 'Something on a company changed — its name, its address, '
                            .'or it was switched back on. Switching one OFF raises '
                            .'"Deactivated" instead of this.',
                    ),
                    new TriggerDefinition(
                        key:   'company.deactivated',
                        label: 'Deactivated',
                        description: 'Somebody switched a company off. Nothing is deleted; the '
                            .'record and everything filed under it stay as they are.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key:   'company.deactivate',
                        label: 'Deactivate the company',
                        description: 'Switches a company off, leaving its records where '
                            .'they are.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key:   'franchises',
                label: 'Franchises',
                subject: new AutomationSubject(Franchise::class, 'Franchise'),
                triggers: [
                    new TriggerDefinition(
                        key:   'franchise.created',
                        label: 'Created',
                        description: 'A new franchise was added. Creating a company opens its '
                            .'first franchise at the same time, so this fires then too.',
                    ),
                    new TriggerDefinition(
                        key:   'franchise.updated',
                        label: 'Updated',
                        description: 'A franchise was renamed, moved to a different time zone, '
                            .'or switched back on. Switching one OFF raises "Deactivated" '
                            .'instead of this.',
                    ),
                    new TriggerDefinition(
                        key:   'franchise.deactivated',
                        label: 'Deactivated',
                        description: 'Somebody switched a franchise off. The jobs and history '
                            .'recorded against it are left untouched.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key:   'franchise.deactivate',
                        label: 'Deactivate the franchise',
                        description: 'Switches a franchise off without touching the work '
                            .'already recorded against it.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key:   'users',
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
                        key:   'user.invited',
                        label: 'Invited',
                        description: 'Somebody was invited to join, before they have accepted. '
                            .'There is no account yet, so this one arrives with the invitation’s '
                            .'details rather than a person.',
                    ),
                    new TriggerDefinition(
                        key:   'user.activated',
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
                        key:   'user.deactivated',
                        label: 'Deactivated',
                        description: 'Someone’s access was switched off. The account stays, and '
                            .'so does everything it did.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key:   'user.deactivate',
                        label: 'Deactivate the user',
                        description: 'Takes away a person’s access without removing them or '
                            .'anything they did.',
                    ),
                    new ActionDefinition(
                        key:   'user.assign_to_scope',
                        label: 'Move a user to a company or franchise',
                        description: 'Changes which company or franchise somebody works under, '
                            .'and with it the records they can reach.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key:     'roles',
                label:   'Roles & permissions',
                // Deliberately outside automation: a rule that could rewrite
                // permissions would be a privilege-escalation path — the
                // automation engine is configured by the same admins it would
                // grant rights to. Role changes stay a human act, and every one
                // is audited (§2.2).
                automationExemptBecause:
                    'Automating permission changes would let a rule escalate privileges.',
            ),

            new ModuleDefinition(
                key:     'audit',
                label:   'Audit log',
                actions: ['view'],
                // Read-only by construction: the log is append-only (§10). No edit
                // or delete action exists to grant, so tampering isn't a permission
                // question — there is simply no code path.
                automationExemptBecause:
                    'The audit log is append-only; nothing may act on it programmatically.',
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
             * holds Job and Customer and nothing else, deliberately: an
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
                key:     'hr',
                label:   'Human Resources',
                actions: ['view', 'create', 'edit'],
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
                        key:   'invitation.sent',
                        label: 'Invitation sent',
                        description: 'Somebody was invited to join. It carries the address, the '
                            .'role they were offered, the place they were offered it in and when '
                            .'the link runs out.',
                    ),
                    new TriggerDefinition(
                        key:   'invitation.accepted',
                        label: 'Invitation accepted',
                        description: 'The person followed the link and created their account. '
                            .'It carries the role and the place, and by this point there is a '
                            .'real user behind it.',
                    ),
                    new TriggerDefinition(
                        key:   'invitation.revoked',
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
                        key:   'invitation.expired',
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
                        key:   'onboarding.started',
                        label: 'Onboarding started',
                        description: 'An employer picked a contract template for somebody who '
                            .'has been invited, and the form is now open. It carries the '
                            .'template’s name and the version it was pinned to.',
                    ),
                    new TriggerDefinition(
                        key:   'onboarding.signed',
                        label: 'Somebody signed',
                        description: 'One party signed one line of the contract. It carries '
                            .'whether that was the employer or the employee, which line, and '
                            .'whether every signature has now been given — so a rule about the '
                            .'new starter specifically is a condition on the party. The first '
                            .'signature is also what freezes the answers.',
                    ),
                    new TriggerDefinition(
                        key:   'onboarding.completed',
                        label: 'Onboarding fully signed',
                        description: 'Every signature the contract asks for has been given and '
                            .'the paperwork is finished. This is the one a payroll or IT handover '
                            .'should hang off, rather than the account being created.',
                    ),
                    new TriggerDefinition(
                        key:   'onboarding.amended',
                        label: 'Signed contract amended',
                        description: 'A correction was made after signing. The original document '
                            .'stays and a new revision sits beside it; this carries the revision '
                            .'number, which fields changed and the reason given.',
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
                        key:   'contract.version_published',
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
                        key:   'invitation.resend',
                        label: 'Send the invitation again',
                        description: 'Issues a fresh link with a fresh week on it, replacing the '
                            .'old one. Chasing somebody who has not accepted is the obvious rule '
                            .'to build, and the cascade guards are what stop it chasing forever.',
                    ),
                    new ActionDefinition(
                        key:   'invitation.revoke',
                        label: 'Withdraw the invitation',
                        description: 'Takes an invitation back. The row stays and reports itself '
                            .'as withdrawn, so the record still shows that somebody was asked.',
                    ),
                    new ActionDefinition(
                        key:   'onboarding.start',
                        label: 'Start the onboarding',
                        description: 'Opens the form for somebody who has been invited, against '
                            .'a contract template the rule names. It is the step an employer '
                            .'takes by hand today, and the one most worth not forgetting.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key:   'customers',
                label: 'Customers',
                subject: new AutomationSubject(Customer::class, 'Customer'),
                triggers: [
                    new TriggerDefinition(
                        key:   'customer.created',
                        label: 'Created',
                        description: 'A new customer was added to the books.',
                    ),
                    new TriggerDefinition(
                        key:   'customer.updated',
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
                        key:   'customer.deleted',
                        label: 'Deleted',
                        description: 'Destructive. A customer was deleted — only possible when '
                            .'they have no jobs — and they leave every list.',
                    ),
                    // Its own trigger rather than a field-change on updated:
                    // moving accountability between franchises is the kind of
                    // thing a manager wants to be told about, and burying it in
                    // "a field changed" means nobody ever builds that rule.
                    new TriggerDefinition(
                        key:   'customer.owner_reassigned',
                        label: 'Owner reassigned',
                        description: 'Accountability for a customer moved to another franchise. '
                            .'It is a deliberate, separately authorised act rather than part of '
                            .'an ordinary edit, which is why "Updated" does not fire for it.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key:   'customer.update_field',
                        label: 'Update a field',
                        description: 'Sets one field on the customer that triggered the rule: '
                            .'name, contact details, notes, address, or whether they are active.',
                    ),
                    new ActionDefinition(
                        key:   'customer.reassign_owner',
                        label: 'Reassign the owner',
                        description: 'Moves accountability for the customer to a different '
                            .'franchise.',
                    ),
                    new ActionDefinition(
                        key:   'customer.deactivate',
                        label: 'Deactivate the customer',
                        description: 'Marks a customer inactive without deleting anything. '
                            .'"Update a field" can already do this by setting Active to false.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key:   'jobs',
                label: 'Jobs',
                subject: new AutomationSubject(Job::class, 'Job'),
                triggers: [
                    new TriggerDefinition(
                        key:   'job.created',
                        label: 'Created',
                        description: 'A job was booked. If it was given a date at the same time, '
                            .'"Date set or changed" fires as well.',
                    ),
                    new TriggerDefinition(
                        key:   'job.updated',
                        label: 'Updated',
                        description: 'Any saved change to a job. A change of status or of date '
                            .'raises this AND its own more specific event, so a rule listening '
                            .'for both runs twice.',
                    ),
                    new TriggerDefinition(
                        key:   'job.deleted',
                        label: 'Deleted',
                        description: 'Destructive. A job was deleted, and it drops out of every '
                            .'list and every total.',
                    ),
                    // The one most automation will actually hang off — "when a
                    // job is completed, email the customer" is the canonical
                    // rule, and it needs the transition, not the end state.
                    new TriggerDefinition(
                        key:   'job.status_changed',
                        label: 'Status changed',
                        description: 'A job moved from one status to another. It carries the old '
                            .'status as well as the new one, which is what a rule like "when a '
                            .'job is completed" needs — the status on its own cannot say that it '
                            .'just changed.',
                    ),
                    new TriggerDefinition(
                        key:   'job.scheduled',
                        label: 'Date set or changed',
                        description: 'A job’s date was set, moved, or cleared. Clearing it counts '
                            .'— the event fires with an empty new date.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key:   'job.update_field',
                        label: 'Update a field',
                        description: 'Sets one field on the job that triggered the rule: title, '
                            .'description, status, date, duration or amount.',
                    ),
                    new ActionDefinition(
                        key:   'job.change_status',
                        label: 'Change the status',
                        description: 'Moves a job to a different status. "Update a field" can '
                            .'already do this by setting the status field.',
                    ),
                    new ActionDefinition(
                        key:   'job.assign_to_user',
                        label: 'Assign it to somebody',
                        description: 'Names the person responsible for the job.',
                    ),
                    new ActionDefinition(
                        key:   'job.create',
                        label: 'Create a job',
                        description: 'Books a follow-up job from the record that triggered '
                            .'the rule.',
                    ),
                ],
            ),
            new ModuleDefinition(
                // Renamed from 'workflows' on 2026-08-10, permission keys and
                // all — see the rename migration of that date. The product says
                // "automation", so the module key does too.
                key:   'automations',
                label: 'Automations',
                // Deliberately outside automation itself. An automation that
                // could create or edit automations is a self-modifying system:
                // the loop guards count actions, not rules, so a rule that
                // writes rules escapes them entirely. It stays a human act.
                automationExemptBecause:
                    'An automation that could write automations would escape the loop guards.',
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
