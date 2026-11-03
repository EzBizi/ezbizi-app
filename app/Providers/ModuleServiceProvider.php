<?php

namespace App\Providers;

use App\Models\Automation;
use App\Models\BusinessLocation;
use App\Models\AdsAccountState;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Membership;
use App\Models\FieldAlert;
use App\Models\Franchise;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\InventoryItem;
use App\Models\Job;
use App\Models\Lead;
use App\Models\MonitoredDevice;
use App\Models\Newsletter;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingProgress;
use App\Models\User;
use App\Models\FinancialRecord;
use App\Models\FinancialResult;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\Vehicle;
use App\Models\WebSite;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\AutomationSubject;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\TabDefinition;
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
                /*
                 * The Settings destination's own faces (settings_shell.dart).
                 * Until 2026-09-29 two were drawn for everybody and the third
                 * borrowed `marketing.view`, so `organizations.view` was a box
                 * that controlled nothing. Roles keeps its own module gate.
                 *
                 * Integrations is still an AND with `marketing.view` on the
                 * client: the tab opens the pane, the lists inside it are
                 * Marketing's reads.
                 */
                tabs: [
                    new TabDefinition('organization', 'Organization'),
                    new TabDefinition('settings', 'Settings'),
                    new TabDefinition('integrations', 'Integrations'),
                    // Everything anybody did with an API key (UI queue 66; Jose,
                    // 2026-10-07: "an MCP-API log to records the actions").
                    // Administrators hold it; the activity endpoint widens to
                    // the organization for whoever does.
                    new TabDefinition('api_activity', 'API activity'),
                ],
                // SettingsController files every settings event against the
                // tenant root, whatever level the value was written at, because
                // that is the one of the three that can say where it sits.
                emits: [Organization::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'organization.settings_changed',
                        label: 'Setting changed',
                        description: 'Somebody saved a different value for a setting, at '
                            .'whichever level they set it — or dropped an override and let '
                            .'it inherit again. The payload carries the key, the level it '
                            .'was set at, and the value before and after.',
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
                    // Paid time off (Jose, 2026-10-21), each with the person and
                    // Total / Used / Left. On the person, so a rule is scoped by them.
                    new TriggerDefinition(
                        key: 'user.pto_requested',
                        label: 'Paid time off requested',
                        description: 'A person asked for paid days off. Carries the person, the days asked '
                            .'for, and their total, used and left.',
                    ),
                    new TriggerDefinition(
                        key: 'user.pto_approved',
                        label: 'Paid time off approved',
                        description: 'A request for paid days off was approved and the days recorded as used.',
                    ),
                    new TriggerDefinition(
                        key: 'user.pto_denied',
                        label: 'Paid time off denied',
                        description: 'A request for paid days off was denied, with the reason given.',
                    ),
                    new TriggerDefinition(
                        key: 'user.pto_used_changed',
                        label: 'Paid time off used changed',
                        description: 'Somebody changed how many paid days off a person has used. '
                            .'Carries the person, total, used (before and after), left and the reason.',
                    ),
                    new TriggerDefinition(
                        key: 'user.pto_balance_empty',
                        label: 'Paid time off balance reached zero',
                        description: 'A change to the days used left a person with no paid time off, or less.',
                    ),
                    new TriggerDefinition(
                        key: 'user.pto_available',
                        label: 'Paid time off becomes available',
                        description: 'A person’s waiting period ended today, so they can now use paid time off.',
                    ),
                    new TriggerDefinition(
                        key: 'user.pto_reset_soon',
                        label: 'Paid time off resets in 30 days',
                        description: 'A person’s paid time off resets (their work anniversary, 1 January or '
                            .'the period) 30 days from today. Carries what they still have left.',
                    ),
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
                // Read by Settings › Activity (2026-10-16), so it is a tick that
                // opens something again.
                rolesGroup: ModuleDefinition::ROLES_SETTINGS,
                sidebar: false,
            ),

            new ModuleDefinition(
                key: 'api_keys',
                label: 'API keys',
                // Which kind of key a role may make (Jose, 2026-10-07): view =
                // Viewer (read), edit = Editor (read and edit), delete = Full
                // access. Checked again on every keyed request, so taking the
                // tick away downgrades keys already made (EnforceApiKeyAccess).
                actions: ['view', 'edit', 'delete'],
                actionLabels: [
                    'view' => 'Viewer keys (read)',
                    'edit' => 'Editor keys (read and edit)',
                    'delete' => 'Full access keys (read, edit and delete)',
                ],
                automationExemptBecause: 'API keys are credentials; nothing may create or change them programmatically.',
                rolesGroup: ModuleDefinition::ROLES_SETTINGS,
                sidebar: false,
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
                // `hours.correct` and `hours.all` lived here until 2026-09-24,
                // when Work Hours left HR for a module of its own — they are
                // `work_hours.manage` and `work_hours.all` now, and the
                // migration of that date copied every grant across.
                // `pay.view` and `pay.edit`, 2026-09-29 (Jose: "Pay rates", with its
                // own permission). Who earns what is a narrower circle than who
                // keeps the people list, so neither rides on `hr.edit` — and as new
                // keys they are held by NO existing role until somebody ticks them,
                // which is the safe direction for a new sensitive grant. An
                // administrator holds them by identity, as every key.
                // `delete`, 2026-10-20 (Jose: "we are missing full deletion"):
                // erasing a person for good, apart from deactivating them. New,
                // so held by no role until ticked; administrators by identity.
                actions: ['view', 'create', 'edit', 'security.reset', 'pay.view', 'pay.edit', 'delete'],
                actionLabels: [
                    'pay.view' => 'See pay rates',
                    'pay.edit' => 'Change pay rates',
                    'delete' => 'Delete people permanently',
                ],
                /*
                 * ── The seven tabs, grantable one at a time ──
                 *
                 * Jose, 2026-09-17, naming this module as the example: "i want
                 * them to see their work hours, but not the onboarding
                 * information". Dispatchers needed one tab and the only grant
                 * that existed was `hr.view`, which is every contract, every
                 * onboarding form and the invitation list.
                 *
                 * THE LABELS ARE THE SCREEN'S OWN WORDS, taken from
                 * hr_screen.dart's `_tabs` rather than written afresh. "Work
                 * Hours" is capitalised that way on screen; matching it
                 * matters more than house style,
                 * because an administrator who ticks "Work hours" and then
                 * cannot find it has been given a checkbox for something whose
                 * name they were told wrongly.
                 *
                 * Dashboard is a tab like the rest. It is the module's landing
                 * pane rather than a privilege, but it shows headcount and
                 * whoever is clocked in right now, and "opens HR and sees
                 * nothing but a summary of everybody" is exactly the grant this
                 * change exists to make refusable.
                 *
                 * NOTHING CHANGES FOR ANY EXISTING ROLE. The migration that
                 * accompanies this grants all seven to every role already
                 * holding `hr.view`, because a permission split that silently
                 * NARROWS what somebody could do yesterday is the one direction
                 * nobody notices until they are locked out of their own work.
                 */
                tabs: [
                    new TabDefinition('dashboard', 'Dashboard'),
                    // Work Hours and Paid time off moved to the `work_hours`
                    // module on 2026-09-24 (Jose: "separate it from human
                    // resources and make a new main tab called Work Hours").
                    // Order is Mia's, 2026-10-02: Records, Contracts,
                    // Invitations, Onboarding.
                    new TabDefinition('records', 'Records'),
                    new TabDefinition('contracts', 'Contracts'),
                    new TabDefinition('invitations', 'Invitations'),
                    new TabDefinition('onboarding', 'Onboarding'),
                    // Jose, 2026-10-22: everyone with salary, W-4, dates, role
                    // and contract in one table. New, so held by no role until
                    // ticked; administrators by identity.
                    new TabDefinition('payroll', 'Payroll'),
                ],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Invitation::class],
                        label: 'Invitation sent',
                        description: 'Somebody was invited to join. It carries the address, the '
                            .'role they were offered, the place they were offered it in and when '
                            .'the link runs out.',
                    ),
                    new TriggerDefinition(
                        key: 'invitation.accepted',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Invitation::class],
                        label: 'Invitation accepted',
                        description: 'The person followed the link and created their account. '
                            .'It carries the role and the place, and by this point there is a '
                            .'real user behind it.',
                    ),
                    new TriggerDefinition(
                        key: 'invitation.revoked',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Invitation::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Invitation::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Onboarding::class],
                        label: 'Onboarding started',
                        description: 'An employer picked a contract template for somebody who '
                            .'has been invited, and the form is now open. It carries the '
                            .'template’s name and the version it was pinned to.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.signed',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Onboarding::class],
                        label: 'Somebody signed',
                        description: 'One party signed one line of the contract. It carries '
                            .'whether that was the employer or the employee, which line, and '
                            .'whether every signature has now been given — so a rule about the '
                            .'new starter specifically is a condition on the party. The first '
                            .'signature is also what freezes the answers.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.completed',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Onboarding::class],
                        label: 'Onboarding fully signed',
                        description: 'Every signature the contract asks for has been given and '
                            .'the paperwork is finished. This is the one a payroll or IT handover '
                            .'should hang off, rather than the account being created.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.amended',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Onboarding::class],
                        label: 'Signed contract amended',
                        description: 'A correction was made after signing. The original document '
                            .'stays and a new revision sits beside it; this carries the revision '
                            .'number, which fields changed and the reason given.',
                    ),
                    new TriggerDefinition(
                        key: 'onboarding.cancelled',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Onboarding::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\ContractVersion::class],
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
                        // EXPLICIT: derived this would be `start`, as generic a name
                        // as `create`, and any later `<thing>.start` would be run by
                        // the onboarding arm.
                        executionType: 'onboarding.start',
                    ),
                ],
            ),

            /*
             * ── Work Hours, out of HR (Jose, 2026-09-24) ──
             *
             * "i think it may be a good idea to separate it from human
             * resources and make a new main tab called Work Hours which will
             * have the General - Paid Time Off - Settings on it" — General
             * being the Work Hours pane as it was.
             *
             * A module of its own rather than `time_clock` given a sidebar
             * entry: `time_clock.view` means "may PUNCH", which every
             * technician holds, and promoting it would hand all of them a
             * sidebar item. The clock stays `time_clock`; this is the screen
             * where hours are read and managed.
             *
             * `all`: see everybody's hours rather than only your own (was
             * `hr.hours.all`). `manage`: edit, delete, clock out, day records
             * and people's schedules (was `hr.hours.correct`, plus schedules,
             * which only HR › Records could set). Manage without All would be
             * rewriting only your own paid hours, so the catalogue cannot say it.
             *
             * Every grant was copied from the HR keys by the migration of
             * 2026-09-24, so no role narrows on deploy.
             */
            new ModuleDefinition(
                key: 'work_hours',
                label: 'Work Hours',
                // `pto.edit` (Jose, 2026-10-21): changing somebody's used paid
                // days is its own grant, not general HR or hours editing.
                // Seeing balances is the Paid Time Off tab's own key.
                // `approve` (Daily approval, 2026-10-22): sign off a day of the
                // people one reaches. Implies seeing them.
                // `pto.request` (Jose, 2026-10-26): ask for your own paid time
                // off. Every role was given it by the migration of that day.
                actions: ['view', 'all', 'manage', 'pto.edit', 'approve', 'pto.request'],
                actionRequires: [
                    'all' => ['work_hours.general.view'],
                    'manage' => ['work_hours.all'],
                    'approve' => ['work_hours.all'],
                    'pto.edit' => ['work_hours.pto.view'],
                ],
                actionLabels: [
                    'all' => "See everyone's hours",
                    'manage' => 'Edit hours and schedules',
                    'pto.edit' => 'Edit used paid time off',
                    'approve' => 'Approve daily hours',
                    'pto.request' => 'Ask for paid time off',
                ],
                tabs: [
                    new TabDefinition('general', 'General'),
                    new TabDefinition('pto', 'Paid Time Off'),
                    // Saved weekly hours reports (2026-09-28): sent by hand here,
                    // or on a schedule by the action below.
                    new TabDefinition('reports', 'Reports'),
                    // Daily approval (design 136, 2026-10-22): a day of the
                    // people one reaches, approved or adjusted. Approving and
                    // adjusting are `work_hours.approve`; without it the tab
                    // reads only.
                    new TabDefinition('approval', 'Daily approval'),
                    // Registry order is the roles editor's order: keep it the
                    // screen's (work_hours_screen.dart).
                    new TabDefinition('settings', 'Settings'),
                ],
                auditActionPrefixes: ['time_clock', 'pto'],
                /*
                 * The weekly hours email as a rule step (Jose, 2026-09-28: every
                 * Sunday 8 PM Miami time to the dispatch manager). The report
                 * definitions and TimeClockReports::send are this module's; the
                 * clock's own triggers and clock in/out stay on `time_clock`.
                 */
                automationActions: [
                    new ActionDefinition(
                        key: 'work_hours.send_report',
                        label: 'Send the work hours report',
                        description: 'Emails the hours each person worked in the week to the '
                            .'addresses you name. Those can be outside the business, so this is HR '
                            .'data leaving it: every send is recorded with who it went to.',
                    ),
                ],
            ),

            /*
             * The staff assistant - Jose, 2026-09-24: "a screen in the app
             * where im able to change the replies from the menu myself ... a
             * new tab called AI Assistance ... 3 tabs AI - Library - Settings".
             * AI is the assistant itself, in the app, for whoever has no
             * WhatsApp; Library is its answers; Settings its name, welcome and
             * which sites Find a location searches.
             */
            new ModuleDefinition(
                key: 'ai_assistance',
                label: 'AI Assistance',
                // `command`: run app actions from the staff WhatsApp line
                // (AppAct). Each action still needs its own screen's tick.
                actions: ['view', 'manage', 'command'],
                // No actionRequires: `manage` is spent on two tabs, Library
                // (answers) and Settings (name, welcome), so requiring either
                // tab would grant a pane somebody may not have been meant to
                // see. Each tab's own grant decides where manage can be used.
                actionLabels: ['manage' => 'Edit answers and settings', 'command' => 'Do things from WhatsApp'],
                tabs: [
                    new TabDefinition('ai', 'AI'),
                    new TabDefinition('library', 'Library'),
                    // The Chimney Tech Assistant (2026-10-23, tech-assistant.html).
                    new TabDefinition('parts', 'Parts'),
                    new TabDefinition('manuals', 'Manuals'),
                    new TabDefinition('answers', 'Answers'),
                    // Where each WhatsApp report goes (docs/REPLACING-RODRIGO-BOT.md).
                    new TabDefinition('whatsapp_groups', 'WhatsApp groups'),
                    // The staff line's menu for each role (Jose, 2026-10-13: "One
                    // number, menu by role"; UI queue 88).
                    new TabDefinition('staff_bot', 'Staff bot'),
                    new TabDefinition('settings', 'Settings'),
                ],
                auditActionPrefixes: ['assistant_entry', 'assistant_part', 'assistant_manual', 'assistant_answer', 'whatsapp_group', 'staff_bot', 'telegram_link'],
                automationExemptBecause: 'The Library is reference material an administrator '
                    .'writes, like a training guide: editing an answer is not something that happened '
                    .'to the business, and a rule firing on it would act on a reworded sentence. The '
                    .'conversations it answers are WhatsApp messages, which already raise WhatsApp '
                    .'events. When the AI tab starts answering in its own words, "a question it '
                    .'could not answer" is the trigger to add here.',
            ),

            /*
             * Guidelines - Jose, 2026-10-14: "a company can create cards, or
             * situations, then users can click on those cards and get step by
             * step guides ... with a search bar ... and the settings tab to
             * create them ... colors and images as well".
             */
            new ModuleDefinition(
                key: 'guidelines',
                label: 'Guidelines',
                actions: ['view', 'manage'],
                actionLabels: ['manage' => 'Create and edit guidelines'],
                tabs: [
                    new TabDefinition('guidelines', 'Guidelines'),
                    new TabDefinition('settings', 'Settings'),
                ],
                auditActionPrefixes: ['guideline'],
                automationExemptBecause: 'Guidelines are reference material an administrator '
                    .'writes, like the AI Assistance Library: editing a card is not something that '
                    .'happened to the business, and a rule firing on it would act on a reworded step.',
            ),

            new ModuleDefinition(
                key: 'time_clock',
                label: 'Clock in',
                // `lock_screen`, not `lock`: the roles label is derived from
                // the key (PermissionCatalogue::actionLabel), so this reads
                // "Lock screen" beside the checkbox with no override. Any
                // action implies `view`, which is right — a locked person
                // must be able to punch.
                //
                // ── §2.2, stated because the gate cannot ask ────────
                //
                // `view` here means "may punch": clocking in and out CREATES
                // and CLOSES time_clock_shifts rows under an action that is
                // read-only by name, so writesRecords() is false and the
                // registry audit gate never asks about this module. The
                // punches are audited anyway — time_clock.clocked_in,
                // time_clock.clocked_out (a person, a rule, or the server at
                // the scheduled end) and time_clock.extended, each naming the
                // actor and the shift. TimeClockAuditTest is the gate for this
                // module, since the fleet-wide one is blind to it. Not renamed
                // to `punch`: time_clock.view is a live permission key in
                // every role that holds it.
                // `anywhere` (2026-10-16): clock in and out away from the
                // place's clock-in zone. A capability, not a restriction, so
                // Administrator holding every permission means exempt.
                // `lock_screen_skip` (2026-10-28, Jose: "people with
                // Administrator Status can skip the lock screen if they want,
                // like without clocking in"). A capability like `anywhere`, so
                // Administrator holding every permission is what grants it.
                // Skipping writes only an audit row (time_clock.lock_skipped).
                actions: ['view', 'lock_screen', 'anywhere', 'lock_screen_skip'],
                actionLabels: ['anywhere' => 'Clock in from anywhere', 'lock_screen_skip' => 'Skip the lock screen'],
                sidebar: false,
                triggers: [
                    new TriggerDefinition(
                        key: 'time_clock.clocked_in',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\User::class],
                        label: 'Person clocked in',
                        description: 'Somebody started a shift. It carries who, and when they punched in.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'time_clock.clocked_out',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\User::class],
                        label: 'Person clocked out',
                        description: 'Somebody ended a shift. It carries who, when they punched in, and when they punched out, '
                            .'and ended_by: person, schedule (the server closed it at the scheduled end) or automation.',
                        isRaised: true,
                    ),
                    // No separate "overdue" trigger: the server closes an overdue
                    // shift within a minute, so overdue is never a state a rule
                    // could observe. ended_by on clocked_out is the fact.
                    new TriggerDefinition(
                        key: 'time_clock.extended',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\User::class],
                        label: 'Shift extended',
                        description: 'Somebody pushed their scheduled end out. It carries who, the new scheduled end, '
                            .'and how many times this shift has been extended.',
                        isRaised: true,
                    ),
                    // Daily approval (2026-10-22).
                    new TriggerDefinition(
                        key: 'time_clock.day_awaiting_approval',
                        subjects: [\App\Models\User::class],
                        label: 'Day of hours waiting for approval',
                        description: 'A person\'s clocked day went to their approver. It carries who and the day.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'time_clock.day_approved',
                        subjects: [\App\Models\User::class],
                        label: 'Day of hours approved',
                        description: 'A manager approved a person\'s clocked day. It carries who, the day and the approver.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'time_clock.shift_adjusted',
                        subjects: [\App\Models\User::class],
                        label: 'Shift adjusted',
                        description: 'A manager changed a shift\'s times with a reason. It carries the times before and after, and the reason.',
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
                    // Memberships (Jose, 2026-10-05): the review faces, told who
                    // smiled — by the customer's own review link, or by the
                    // phone or email the faces asked for. A happy one is when
                    // to offer the membership: {{ membership_join_url }}.
                    new TriggerDefinition(
                        key: 'customer.reviewed',
                        label: 'Left a review on the website',
                        description: 'A known customer tapped a face on the review widget on the website. '
                            .'"rating" is happy or sad; a sad one may carry a "comment". '
                            .'"verified" is true only for their personal review link; a typed '
                            .'phone or email is unverified. At most once a day per customer.',
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

            /*
             * Membership program, drawn inside Customers (Jose, 2026-10-05: "a
             * sub tab in Customers, with its own sub tabs of Dashboard - Members
             * - Settings"). A yearly maintenance plan: one `memberships` row per
             * year, reminded a month before it ends. Amounts need
             * `jobs.financials`, as every other amount does. Spec:
             * docs/superpowers/specs/2026-10-05-memberships-design.md.
             */
            new ModuleDefinition(
                key: 'memberships',
                label: 'Memberships',
                parent: 'customers',
                sidebar: false,
                subject: new AutomationSubject(Membership::class, 'Membership'),
                emits: [Membership::class],
                tabs: [
                    new TabDefinition('dashboard', 'Dashboard'),
                    new TabDefinition('members', 'Members'),
                    new TabDefinition('settings', 'Settings'),
                ],
                triggers: [
                    new TriggerDefinition(
                        key: 'membership.created',
                        label: 'Member joined',
                        description: 'A customer joined a membership plan, new or renewing. '
                            .'"The customer" as a recipient is the member.',
                    ),
                    new TriggerDefinition(
                        key: 'membership.renewal_due',
                        label: 'Renewal due in a month',
                        description: 'Once, a month before a membership year ends: the time to '
                            .'remind the customer to book their next yearly visit. Put {{ confirm_visit_url }} '
                            .'in the message: it lets them pick the day, and books the job.',
                    ),
                    new TriggerDefinition(
                        key: 'membership.visit_confirmed',
                        label: 'Next visit confirmed',
                        description: 'The customer picked a date from the reminder\'s {{ confirm_visit_url }} '
                            .'and a job was booked for it ("job_number", "visit_on"). A branch on '
                            .'Workiz gets it there too, with no service area set yet.',
                    ),
                    new TriggerDefinition(
                        key: 'membership.visit_not_in_workiz',
                        label: 'Visit could not be sent to Workiz',
                        description: 'A confirmed visit was booked in EzBizi but Workiz refused it; '
                            .'"refused" is Workiz\'s own reason (often a job type the branch\'s list '
                            .'lacks). Somebody has to enter it in Workiz by hand.',
                    ),
                    new TriggerDefinition(
                        key: 'membership.visit_unconfirmed',
                        label: 'No visit date two weeks after the reminder',
                        description: 'Once, when the customer has not picked a date 14 days after the '
                            .'renewal reminder: the time for staff to call them.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key: 'jobs',
                label: 'Jobs',
                /*
                 * The detail panel's panes, in the order it draws them (0051).
                 *
                 * `work` NAMES `jobs.view` rather than minting `jobs.work.view`,
                 * because that pane has shipped under `jobs.view` since this
                 * module existed. Minting would create two names for one
                 * authority and let an administrator grant the tab without the
                 * read — a pane that opens and refuses everything inside it —
                 * and it would also take the pane away from everybody who holds
                 * `jobs.view` today, which is a regression wearing a feature's
                 * clothes. Training's case, same answer.
                 *
                 * `materials` is a real mint because the audience genuinely
                 * differs: "the technician may see the job but not what the
                 * parts were" is a sentence somebody says out loud, and the rows
                 * come from Inventory rather than from this module.
                 *
                 * Photos is NOT here yet, on purpose: its pane does not exist,
                 * and a checkbox for a pane an administrator cannot then find is
                 * step 1 of the brief failing from the other side. It arrives in
                 * the commit that builds it.
                 *
                 * Inspection is not here AND WILL NOT BE, which is a decision,
                 * not a gap. Its section on the job panel is gated by
                 * Inspection's own `inspection.view` (JobsController::
                 * inspectionsFor). A Jobs tab key would either be an alternative
                 * to that grant — a way past InspectionReportPolicy — or an AND
                 * on top of it, which only lets an administrator hide
                 * inspections-on-a-job from somebody who may read every
                 * inspection elsewhere. Nobody makes that split. This comment
                 * used to promise an Inspection tab here; it was wrong about
                 * what the pane needed, not about when it would arrive.
                 */
                tabs: [
                    new TabDefinition('work', 'Work', action: 'view'),
                    new TabDefinition('materials', 'Materials'),
                ],
                // The same fifth action, for the same reason — see Customers
                // above. Jobs cannot be imported by anybody (ResourceMap says
                // why) and can be exported by everybody who reaches the tab, so
                // this is the only transfer grant this module has to offer.
                //
                //   financials — see and change prices, and every amount on a
                //                job (Jose, 2026-09-29: "a financials toggle").
                //                Money never travels with edit, as in Fleet and
                //                Inventory.
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'export', 'financials'],
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
                        key: 'job.payment_received',
                        label: 'Payment received',
                        description: 'A payment on the job arrived from Workiz. It carries the amount, '
                            .'what has been paid in all, the job total, whether that makes it fully paid, '
                            .'and the job’s Workiz status.',
                    ),
                    new TriggerDefinition(
                        key: 'job.estimate_aging',
                        label: 'Estimate waiting',
                        description: 'Once a day, for each job in a status your organization says '
                            .'waits on an estimate (set in Settings). It says how many days since '
                            .'the latest estimate and what has been paid, so a rule can act, for '
                            .'example after 30 days with nothing paid.',
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
                        key: 'job.set',
                        label: 'Set the job status and tags',
                        description: 'Sets the status, sub-status and tags of the job (tags not listed are removed), or '
                            .'removes its scheduled time. EzBizi is written first; a job linked to Workiz is then '
                            .'brought in line there.',
                        executionType: 'job.set',
                    ),
                    // Jose's Project Management rules (2026-10-21), through Workiz's writer.
                    new ActionDefinition(
                        key: 'job.workiz_update',
                        label: 'Update the job in Workiz',
                        description: 'Sets the status of the Workiz job (and sub-status) and/or replaces its tags '
                            .'with the ones listed, in the spelling of each Workiz account. Tags not '
                            .'listed are removed. It can also remove the job’s scheduled time. A job not linked to Workiz is left alone. If Workiz '
                            .'refuses, for example no technician on the job, its own words are shown.',
                        executionType: 'workiz.job_update',
                    ),
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
                        description: 'Books a follow-up job for the same customer, at the same '
                            .'franchise — from a job or a customer the rule fired on. Leave the '
                            .'days blank to leave it for somebody to schedule. A rule on "job '
                            .'created" that books a job sets itself off, and the engine stops it '
                            .'after a few rounds rather than letting it run on.',
                        // EXPLICIT, not derived. Derived, this would be `create` —
                        // the most generic name there is — and the next module
                        // to declare `<thing>.create` would have its action run
                        // by this job arm without anybody noticing.
                        executionType: 'job.create',
                    ),
                ],
            ),

            /*
             * Estimates, drawn inside Jobs (Jose, 2026-09-29: "build it, but as a
             * part of Jobs"). A module of its own only because a ModuleDefinition
             * holds one automation subject and `estimate.accepted` is about an
             * Estimate: assertKeysMatchSubject refuses `estimate.*` on the Job.
             *
             * Every action here ALSO needs `jobs.financials` (EstimatePolicy): an
             * estimate is money, and Jose ruled that money is "a financials
             * toggle". `parent` is where it is drawn and nothing more, so the Jobs
             * screen draws it; `sidebar: false` because it is no rail destination
             * of its own, which also keeps it off the dashboard, where Jose ruled
             * no money goes.
             */
            new ModuleDefinition(
                key: 'estimates',
                label: 'Estimates',
                parent: 'jobs',
                sidebar: false,
                subject: new AutomationSubject(Estimate::class, 'Estimate'),
                emits: [Estimate::class],
                // EstimatePolicy refuses every estimates action without it, so
                // granted alone an estimates tick has nothing behind it: the
                // Jobs screen draws no Estimates tab. The roles editor now says
                // so instead of saving it (the desk's permissions audit,
                // 2026-09-29). Argued in RolesApiTest.
                actionRequires: array_fill_keys(ModuleDefinition::DEFAULT_ACTIONS, ['jobs.financials']),
                triggers: [
                    new TriggerDefinition(
                        key: 'estimate.accepted',
                        label: 'Estimate accepted',
                        description: 'A customer accepted an estimate. The usual next step is booking the work.',
                    ),
                ],
            ),

            /*
             * Project Management (Jose, 2026-10-21): every job waiting on an
             * estimate, as a timeline with its cancel clock. Its own sidebar
             * module ("This Project Management should be its own tab, not a
             * sub tab of Jobs"). It reads Jobs' records and events, and writes
             * nothing of its own.
             */
            new ModuleDefinition(
                key: 'project_management',
                // "- 1" beside its rebuild, Project Management - 2 (Jose, 2026-10-26).
                label: 'Project Management - 1',
                actions: ['view'],
                // Jose, 2026-11-02: never without Jobs. It shows every job
                // waiting on an estimate and who paid, which Jobs guards.
                actionRequires: ['view' => ['jobs.view']],
                /*
                 * Overview | Jobs | Assignments | Repair team | Rules | Logs |
                 * Settings (UI queue 127, 139, 141, 143, 144). All are the one grant the
                 * module always was: naming `view` mints no permission, so no role
                 * gains or loses a tab, and Settings' writes stay the settings
                 * API's to refuse. Overview was "Dashboard" and keeps its key; Jobs
                 * was "Pending Jobs" and keeps `pending`. Repair team (design 144)
                 * is Reports and Table merged, under Reports' key; `table` is
                 * retired, and as it minted nothing no grant moves and no
                 * migration is needed. It reads Financials' repair report, so the
                 * client also hides it without financials.bases.view. Rules
                 * (design 144) reads GET jobs/project-management/rules, the same grant.
                 */
                tabs: [
                    new TabDefinition('dashboard', 'Overview', action: 'view'),
                    new TabDefinition('pending', 'Jobs', action: 'view'),
                    // Assignments (design 143): Action 1's Task Board cards by technician, the same grant.
                    new TabDefinition('assignments', 'Assignments', action: 'view'),
                    new TabDefinition('reports', 'Repair team', action: 'view'),
                    new TabDefinition('rules', 'Rules', action: 'view'),
                    // Logs (UI queue 139): what rules and people changed, the same grant.
                    new TabDefinition('logs', 'Logs', action: 'view'),
                    new TabDefinition('settings', 'Settings', action: 'view'),
                ],
                automationExemptBecause: 'It reads jobs and their events; Jobs raises every event a rule could use.',
                auditExemptBecause: 'It changes nothing; every change it shows was audited by Jobs.',
            ),

            /*
             * Project Management - 2 (Jose, 2026-10-26): Project Management
             * rebuilt from scratch beside the first, to compare the two. For
             * now one list, the jobs done pending approval.
             */
            new ModuleDefinition(
                key: 'project_management_2',
                label: 'Project Management - 2',
                actions: ['view'],
                automationExemptBecause: 'Its one write, Submit, raises the job\'s own job.updated and job.status_changed.',
                auditExemptBecause: 'Its one write, Submit, is a job status change, audited as job.updated like any job edit.',
            ),

            /*
             * Scheduling — how much work a service area can take, per day.
             *
             * THIS COMMENT USED TO SAY "a shell ... no table, no endpoint and
             * no model behind any of them yet", and it said so while three
             * tables, six endpoints and two live screens sat behind it: it was
             * written on 2026-08-31 and never revisited when 0.0.0174 shipped
             * the module. The description claiming less than the code is the
             * one nobody checks, because nothing errors and the reader has no
             * reason to doubt it — so this replacement names what the module
             * IS, which stays true, rather than what it lacks, which does not.
             *
             * Availability turned out to mean neither staff nor equipment:
             * `service_area_slots` holds a job count per area, per weekday, per
             * time slot, and a CLOSED DAY IS THE ABSENCE OF ROWS, never a zero.
             * Schedule is the one tab still a placeholder — it needs Scheduled
             * Jobs, which is also what turns "configured capacity" into
             * "remaining".
             */
            new ModuleDefinition(
                key: 'scheduling',
                label: 'Scheduling',
                /*
                 * Labels copied from the tab bar in scheduling_screen.dart, not
                 * written fresh — an administrator who ticks a name and then
                 * cannot find a tab called that has been handed a checkbox for
                 * something whose name they were told wrongly.
                 *
                 * The split that matters is Availability from Settings. Reading
                 * what the business can take is the dispatcher's job; changing
                 * how much it can take is not, and until now the only grant
                 * available gave both.
                 *
                 * `schedule` is declared while its tab is still a placeholder.
                 * Declaring it now means the grant exists before there is
                 * anything behind it, which is the order this module has used
                 * since 9d376605 — the alternative is shipping a screen and its
                 * permission together and leaving a window where everyone can
                 * see it.
                 */
                tabs: [
                    new TabDefinition('availability', 'Availability'),
                    new TabDefinition('schedule', 'Schedule'),
                    // Jose, 2026-09-29: "a new tab on scheduling that warns me
                    // about all the overbookings call it Overbooking".
                    new TabDefinition('overbooking', 'Overbooking'),
                    // Jose, 2026-10-13: booked jobs that would sit better on
                    // another day, beside other work (App\Support\Scheduling\Reschedules).
                    new TabDefinition('rescheduling', 'Re-Scheduling'),
                    // Jose, 2026-10-14: a route per technician (capacity / 6),
                    // opened in Google Maps (App\Support\Scheduling\Routes).
                    new TabDefinition('routing', 'Routing'),
                    // A1 Leads takeover (Jose, 2026-10-20): helpers booked to
                    // technicians by day or half (SchedulingHelpersController).
                    new TabDefinition('helpers', 'Helpers'),
                    // Jovanni, 2026-10-01: company branches and the closest
                    // one to an address, for new dispatchers (queue 49).
                    new TabDefinition('locations', 'Locations'),
                    // Last, as on the screen: the roles editor lists tabs in
                    // this order.
                    new TabDefinition('settings', 'Settings'),
                ],
                /*
                 * The first booking event (Jose, 2026-09-29: a warning email).
                 * Since 2026-10-05 it carries only the areas with no spot left
                 * for Overbooking::FULL_DAYS days or more (Shoval: so their ads
                 * can be paused). The key keeps its first name, because rules
                 * already listen on it. Raised once each morning by
                 * scheduling:warn-overbooking with a Markdown `summary`, days
                 * under headings, declared composed so an email renders it as
                 * structure (UI queue 39); who is told is an automation on it.
                 */
                // The warning covers every area, so it is filed against the
                // organization, as Organization settings files its events.
                emits: [Organization::class],
                triggers: [
                    new TriggerDefinition(
                        'scheduling.overbooking_found',
                        'Fully booked for 10+ days',
                        'Each morning, when a service area has no spot left for the next 10 days or more (Shoval: time to pause its ads). Its summary lists them with their next opening.',
                        composedFields: ['summary'],
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [Organization::class],
                    ),
                    new TriggerDefinition(
                        'scheduling.area_underfilled',
                        'Tomorrow under-filled',
                        'The day before, at the hour set in Settings › Scheduling, when a service area has empty spots tomorrow. Its summary lists them per branch with totals and a link to Booked vs available.',
                        composedFields: ['summary'],
                        subjects: [Organization::class],
                    ),
                ],
                actions: ModuleDefinition::DEFAULT_ACTIONS,
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
                 * Labels copied from the tab bar in inspection_screen.dart, not
                 * written fresh. An administrator who ticks a box called one
                 * thing and then looks for a tab called another has been handed
                 * a control for something whose name they were told wrongly,
                 * and nothing anywhere errors.
                 *
                 * These are a SECOND axis, not a replacement for the actions
                 * above. The three tabs were already separated by capability —
                 * New Inspection needs `create`, Generated Reports `view`,
                 * Builder `edit` — and those still govern what the endpoints
                 * behind each tab will do. The tab permission governs whether
                 * the tab is there at all, which is the thing Jose could not
                 * grant before: somebody who files inspections all day and has
                 * no business reading the whole branch's filed reports.
                 */
                tabs: [
                    new TabDefinition('new', 'New Inspection'),
                    new TabDefinition('reports', 'Generated Reports'),
                    new TabDefinition('builder', 'Builder'),
                    /*
                     * A dry run of a form, which is authoring: the same grant
                     * as Builder rather than a fourth box (rehearse sits behind
                     * builder.view). Jose chose the word "Testing", 2026-10-26.
                     */
                    new TabDefinition('testing', 'Testing', action: 'builder.view'),
                ],
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
                    InspectionRun::class,
                    InspectionReport::class,
                ],
                triggers: [
                    new TriggerDefinition(
                        key: 'inspection.started',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\InspectionRun::class],
                        label: 'Inspection started',
                        description: 'Somebody started filling in a named inspection for a '
                            .'client or a job. The draft is personal to them until they submit it.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'inspection.submitted',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\InspectionReport::class],
                        label: 'Inspection submitted',
                        description: 'A named inspection was finished and a report was written. '
                            .'The answers, photographs and signatures are frozen as they were '
                            .'on the day.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'inspection.archived',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\InspectionReport::class],
                        label: 'Report archived',
                        description: 'A submitted inspection was hidden from the default list. '
                            .'Archive does not delete the report.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'inspection.discarded',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\InspectionRun::class],
                        label: 'In-progress discarded',
                        description: 'Somebody threw away their own unfinished inspection and '
                            .'started again, or discarded it without submitting.',
                        isRaised: true,
                    ),
                ],
                automationActions: [
                    /*
                     * Emailing a finished report to the customer, decided by a
                     * RULE rather than by a button.
                     *
                     * Jose's call, 2026-09-14: "something to be decided in
                     * automations, not hardcoded". It is the better shape for
                     * the reason his standing rule gives — every new thing has
                     * to work with the automations system — but also because
                     * the three questions a send button would force somebody to
                     * answer in code (who may send, when, and to whom) are
                     * exactly the three a rule already answers, per tenant,
                     * without a deploy.
                     *
                     * The execution type is SPELLED OUT rather than derived.
                     * ActionDefinition's own docblock asks for that whenever an
                     * action is one implementation of a specific thing rather
                     * than a generic verb applied to many subjects — this
                     * renders an inspection report and attaches it, and nothing
                     * else will ever share it.
                     *
                     * Subject-bound: it acts on the report the event is about,
                     * so it is only meaningful on `inspection.submitted`.
                     */
                    new ActionDefinition(
                        key: 'inspection.report.email',
                        label: 'Email the report to the customer',
                        description: 'SWITCHED OFF at the moment, and a rule using it will '
                            .'record why on every run. It would send the finished inspection '
                            .'to the customer it was carried out for with the PDF attached, '
                            .'but mail leaves over the EzBizi mail service whatever sending '
                            .'address a branch has configured, and that service carries '
                            .'EzBizi messages only.',
                        /*
                         * `inspection_report.email`, NOT the `report.email` this
                         * key would derive to. Reports declares `report.send`
                         * and labels it "Email a report" — so a derived
                         * `report.email` sits one rename away from being the
                         * same string as somebody else's action, in the
                         * namespace every module shares, and the collision
                         * would be a silent mis-route in ActionRunner's match
                         * rather than an error. Changed before anything could
                         * select it, which is the only free moment: no tenant
                         * rule references it yet.
                         */
                        executionType: 'inspection_report.email',
                    ),
                    /*
                     * Workiz's (Jose via Inspection, 2026-10-06): "inspection
                     * reports should also go up in workiz when done via the
                     * app". ActionRunner::putInspectionReportOnWorkiz. No
                     * Workiz API takes a file, so a link goes up instead.
                     * Subject-bound, like the email above.
                     */
                    new ActionDefinition(
                        key: 'inspection.report.workiz',
                        label: 'Put the report on its Workiz job',
                        description: 'Adds one line to the end of the Workiz job\'s description: the date '
                            .'and a link to the report that opens without signing in. Workiz takes no '
                            .'files through its API, so the link is what goes up. Written once per '
                            .'report; an inspection with no Workiz job is left alone.',
                        executionType: 'inspection_report.workiz',
                    ),
                ],
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
                auditActionPrefixes: ['social', 'qr_code'],
                actions: ['view', 'edit'],
                /*
                 * Social Media Management's own two panes, grantable one at a
                 * time (Jose, 2026-09-29: tabs should be grantable "like all the
                 * others"). Labels are the words its chip strip draws.
                 *
                 * `posts` is enforced on its routes. `accounts` is enforced on
                 * the SCREEN only, deliberately: the connection routes serve
                 * Settings > Integrations, Leads, Reports and Newsletter as well,
                 * so a route gate there would take accounts away from people
                 * who reach them another way (RequirePermission's docblock names
                 * this case). What connecting and disconnecting CHANGE is
                 * refused server-side without `connectPermission()` regardless.
                 */
                tabs: [
                    // Posts split into its three tabs (Jose, 2026-10-12, UI queue 76).
                    new TabDefinition('dashboard', 'Dashboard'),
                    new TabDefinition('compose', 'Compose'),
                    new TabDefinition('calendar', 'Calendar'),
                    new TabDefinition('accounts', 'Accounts'),
                    // Dynamic QR codes (Jose, 2026-10-05). Enforced on its routes.
                    new TabDefinition('qr_codes', 'QR Codes'),
                ],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\SocialPostTarget::class],
                        label: 'A post went out',
                        description: 'A post reached one connected social account. It carries '
                            .'which account and which network, and the id the network gave '
                            .'back — one of these per account a post was sent to, as each '
                            .'one lands.',
                    ),
                    new TriggerDefinition(
                        key: 'social.publish_failed',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\SocialPostTarget::class],
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
                 * The three panes, grantable one at a time (Jose, 2026-09-29).
                 * Labels are the words the sub-tab strip draws.
                 *
                 * `composer` is enforced on every route that writes, previews or
                 * sends. The LIST is read by Dashboard and Composer both - the
                 * calendar and the picker are the same rows - so it answers to
                 * either, inside NewslettersController::index. `settings` is the
                 * Mailchimp connection, which Settings > Integrations reaches as
                 * well, so it is enforced on the screen; connecting still needs
                 * `newsletter.send` on the server.
                 */
                tabs: [
                    new TabDefinition('dashboard', 'Dashboard'),
                    new TabDefinition('composer', 'Composer'),
                    new TabDefinition('settings', 'Settings'),
                ],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Newsletter::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Newsletter::class],
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
                        /*
                         * **RULED 2026-09-16, Jose: it needs an opt-in tickbox.**
                         * Built 2026-09-24 on that ruling. The website form and
                         * chat carry an unticked box; ticking it writes
                         * `leads.marketing_consent_at` together with the exact
                         * words beside the box. This step reads THAT and nothing
                         * else — never a parameter a rule author could set, and
                         * never the fact that somebody typed an address into a
                         * contact form, which is the shape the ruling forbids.
                         *
                         * Mailchimp lists only: a `customers` audience has no
                         * members to add, it is the customer book at send time.
                         */
                        description: 'Adds somebody who enquired on your website to one of your '
                            .'Mailchimp lists, but only if they ticked the box agreeing to marketing '
                            .'email. Anyone who did not tick it is left off, and the rule carries on.',
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
                 * ── Two tabs, and deliberately only two ──
                 *
                 * The labels are web_screen.dart's own words. The strip there
                 * is a fixed list of exactly these two, so there is nothing to
                 * copy wrongly and nothing else to declare.
                 *
                 * WHY THIS IS A REAL GRANT rather than granularity for its own
                 * sake: SEO is what the website SAYS — the phone number, the
                 * licence, the address, the copy on each page. Widgets is what
                 * a visitor can START on it, and every conversation begun there
                 * becomes a lead. "Whoever writes the site's copy may not
                 * reconfigure the thing that captures enquiries" is a sentence
                 * somebody says out loud, which is the test the brief sets.
                 *
                 * NOT DECLARED, on purpose: General, Pages, Posts, Templates,
                 * Content and Hours are panes of a rail INSIDE the SEO tab, not
                 * tabs. Six more keys would produce a roles editor nobody can
                 * read, to express a distinction nobody has asked for — the
                 * same reason Communication left their fifteen-pane Settings
                 * rail as one `phone.edit`.
                 *
                 * THE BROWSER KEY DOES NOT GET A TAB EITHER, and Settings was
                 * right to ask. Re-issuing it is already `web_management.edit`
                 * and the button is drawn only behind `canEdit`, so "may look
                 * at the site" and "may reissue the credential the plugin
                 * authenticates with" are ALREADY two grants. A tab key is a
                 * visibility toggle by design; hanging a credential rotation on
                 * one would be the weaker half of a gate that already holds.
                 */
                tabs: [
                    new TabDefinition('seo', 'SEO'),
                    new TabDefinition('widgets', 'Widgets'),
                ],
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
                subject: new AutomationSubject(WebSite::class, 'Website'),
                /*
                 * Both record types this module hands to events->emit(),
                 * not just the subject. WebSite carries the two triggers
                 * below; Lead carries the `lead.received` that
                 * WebsiteEnquiries raises when a chat or a form becomes an
                 * enquiry, which is this module's code even though the
                 * trigger is the Leads module's.
                 *
                 * Both can say where they sit -- `web_sites.franchise_id`
                 * arrived with branch routing, and `leads.franchise_id` was
                 * always there -- so a notify rule on either reaches the
                 * branch concerned rather than every active user in the
                 * organization.
                 */
                emits: [
                    WebSite::class,
                    Lead::class,
                ],
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
                    /*
                     * The one that exists because of what 1.9.0 does: a site
                     * EzBizi stops recognising takes its chat and contact form
                     * down at once, so it stops collecting enquiries and says
                     * so only in a WordPress notice nobody reads.
                     *
                     * Who should hear about that is a question about the
                     * business, not about this module, so it is a trigger
                     * rather than a notification I chose the recipient for.
                     */
                    new TriggerDefinition(
                        key: 'website.went_quiet',
                        label: 'Website stopped answering',
                        description: 'A website that had been connected has not fetched its '
                            .'details for a day. Its chat and contact form are no longer '
                            .'showing, so it is not collecting enquiries. Usually its token '
                            .'was changed or the plugin was removed.',
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
                /*
                 * Jose, 2026-09-29: "the communication right now is only view
                 * and edit, which means everyone can view the settings, we need
                 * to add the tab support like all the others". Labels are the
                 * tab bar's in phone_screen.dart, in its order.
                 *
                 * What each one closes on the server is listed in routes/api.php
                 * beside the routes. Records has no route of its own to close:
                 * its calls and texts are the same endpoints the Phone tab's
                 * history and customer panel read.
                 */
                tabs: [
                    new TabDefinition('phone', 'Phone'),
                    new TabDefinition('users', 'Users'),
                    new TabDefinition('records', 'Records'),
                    new TabDefinition('reports', 'Reports'),
                    // Jose, 2026-10-07: alerts to chosen people, a pop-up they
                    // dismiss. phone.alerts.view is also who may SEND one.
                    new TabDefinition('alerts', 'Alerts'),
                    new TabDefinition('settings', 'Settings'),
                ],
                triggers: [
                    new TriggerDefinition(
                        key: 'phone.call.started',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneCall::class],
                        label: 'A call started',
                        description: 'A call began on one of the phone lines — inbound or '
                            .'outbound — and is ringing or connected.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.call.ended',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneCall::class],
                        label: 'A call ended',
                        description: 'A call finished. It carries how long it lasted and which '
                            .'line it used.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.call.missed',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneCall::class],
                        label: 'A call was missed',
                        description: 'Nobody answered, and the caller either hung up or reached '
                            .'voicemail.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.call.tagged',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneCall::class],
                        label: 'A call was tagged',
                        description: 'Somebody said what a call was about. It carries the tag '
                            .'name, so a rule can act on complaints without acting on '
                            .'bookings. Raised when a tag is put on, not when one is removed.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.voicemail.left',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneCall::class],
                        label: 'A voicemail was left',
                        description: 'A caller left a voicemail on one of the phone lines. The '
                            .'recording stays on Twilio until it is erased.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.sms.received',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneMessage::class],
                        label: 'A text arrived',
                        description: 'A text message reached one of the phone lines that can '
                            .'receive SMS.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.sms.sent',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneMessage::class],
                        label: 'A text was sent',
                        description: 'Somebody sent a text from a line that is allowed to send '
                            .'SMS.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'phone.number.broken',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\PhoneNumber::class, \App\Models\PhoneConnection::class],
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
                // General is the module's dashboard (Mia, 2026-10-02), opened
                // first; Vehicles is the register. Granted to every role that
                // held Fleet by 2026_10_02_300000_grant_general_tabs.
                tabs: [
                    new TabDefinition('general', 'General'),
                    new TabDefinition('vehicles', 'Vehicles'),
                ],
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
             * Task Board (Jose, 2026-10-13, UI queue 80) — one board for every
             * company, franchise and person, created automatically.
             *
             * Two actions only: `view` sees the team boards in the current view,
             * `edit` writes them (create and delete travel with edit). Your own
             * board needs neither — it is always yours.
             *
             * The three automation actions are catalogued here; their runner
             * arms belong to the Automations owner and go through
             * TaskWriter (app/Support/Tasks). Until then the builder shows them
             * as not built (AutomationsController's built list).
             */
            /*
             * Financials (Jose, 2026-10-14) — the Airtable replacement: bases of
             * tables with typed columns, records and saved views (grid, kanban,
             * calendar, gallery, form). Edit covers records, columns, tables
             * and views inside a base; delete removes any of them.
             */
            new ModuleDefinition(
                key: 'financials',
                label: 'Financials',
                // approve: sign off a week of the Repair Team report.
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'approve'],
                // Bases is the work; Settings is which Airtable table is which
                // part of the books (organization configuration).
                tabs: [
                    new TabDefinition('bases', 'Home'),
                    new TabDefinition('bills', 'Bills'),
                    new TabDefinition('settings', 'Settings'),
                ],
                auditActionPrefixes: ['financials'],
                subject: new AutomationSubject(FinancialRecord::class, 'Record', key: 'financial_record'),
                emits: [FinancialRecord::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'financial_record.created',
                        label: 'Financials record added',
                        description: 'A record was added to a Financials table. It carries the table name.',
                    ),
                    new TriggerDefinition(
                        key: 'financial_record.updated',
                        label: 'Financials record changed',
                        description: 'A cell in a Financials record was changed. It carries the table name.',
                    ),
                ],
            ),

            /*
             * Results (Jose, 2026-10-27, ACE option A): an outside lead
             * source's calls, jobs, cost, sales and profit per date and area,
             * typed in or read from a photo (app or WhatsApp). A row counts in
             * reports only once approved. Drawn inside Financials (parent).
             *
             *   view     see results
             *   create   type rows in, or send a photo
             *   edit     change a pending row
             *   delete   remove a row
             *   approve  approve rows so they count; change approved rows
             */
            new ModuleDefinition(
                key: 'financial_results',
                label: 'Lead source results',
                parent: 'financials',
                sidebar: false,
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'approve'],
                actionLabels: ['approve' => 'Approve results so they count in reports'],
                subject: new AutomationSubject(FinancialResult::class, 'Lead source results', key: 'financial_result'),
                emits: [FinancialResult::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'financial_result.approved',
                        label: 'Lead source results approved',
                        description: 'A row of a lead source\'s results (ACE) was approved. It carries the source, date, area, calls, jobs, cost, sales and profit.',
                    ),
                ],
            ),

            /*
             * Invoices (Jose, 2026-10-20/21): charge sheets per period, one
             * draft invoice per branch with its PDF, approve, send from the
             * organization's own mail, payments. One invoice model for the
             * product — Jobs' customer invoices use it too. Drawn as a tab in
             * Financials (parent), so it has no sidebar row of its own.
             *
             *   view     see charge sheets and invoices
             *   create   make charge sheets and draft invoices
             *   edit     change a draft, record payments, configure charge lines
             *   delete   void an invoice (a sent one is never deleted)
             *   approve  approve a draft for sending
             *   send     email an approved invoice
             */
            new ModuleDefinition(
                key: 'invoices',
                label: 'Invoices',
                parent: 'financials',
                sidebar: false,
                // Drawn inside the Invoices tab of Financials.
                tabs: [
                    new TabDefinition('charge_sheets', 'Charge sheets'),
                    new TabDefinition('invoices', 'Invoices'),
                    new TabDefinition('settings', 'Settings', 'edit'),
                ],
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'approve', 'send'],
                actionLabels: [
                    'approve' => 'Approve invoices for sending',
                    'send' => 'Send invoices by email',
                ],
                auditActionPrefixes: ['invoice', 'financials'],
                subject: new AutomationSubject(Invoice::class, 'Invoice'),
                emits: [Invoice::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'invoice.approved',
                        label: 'Invoice approved',
                        description: 'Somebody approved a draft invoice for sending.',
                    ),
                    new TriggerDefinition(
                        key: 'invoice.sent',
                        label: 'Invoice sent',
                        description: 'An invoice was emailed to whoever it bills.',
                    ),
                    new TriggerDefinition(
                        key: 'invoice.paid',
                        label: 'Invoice paid',
                        description: 'Payments recorded against an invoice reached its total.',
                    ),
                    new TriggerDefinition(
                        key: 'invoice.overdue',
                        label: 'Invoice overdue',
                        description: 'A sent invoice passed its due date with money still owed. Raised once per invoice.',
                    ),
                ],
            ),

            new ModuleDefinition(
                key: 'tasks',
                label: 'Task Board',
                /*
                 * Jose, 2026-10-14: roles decide who sees every board, who adds
                 * people to boards, and who creates boards. Administrator holds
                 * all three (Role::hasPermission). create_boards replaced
                 * tasks.edit on POST task-boards with no backfill, on purpose.
                 */
                actions: ['view', 'edit', 'view_all', 'manage_members', 'create_boards'],
                actionLabels: [
                    'view_all' => 'See every board',
                    'manage_members' => 'Add people to any board',
                    'create_boards' => 'Create boards',
                ],
                actionDescriptions: [
                    'view_all' => "Everyone's boards in their picker, grouped by person, including personal ones.",
                    'manage_members' => "Share any board they can see with others, and change who is on it. A board's owner can always do this on their own board.",
                    'create_boards' => 'Make new boards of their own.',
                ],
                subject: new AutomationSubject(Task::class, 'Task'),
                emits: [Task::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'task.created',
                        label: 'Task added',
                        description: 'A task was added to a board, by a person or a rule.',
                    ),
                    new TriggerDefinition(
                        key: 'task.status_changed',
                        label: 'Task status changed',
                        description: 'A task moved from one status to another, for example to '
                            .'Stuck or Done. It carries the old status as well as the new one.',
                    ),
                    new TriggerDefinition(
                        key: 'task.overdue',
                        label: 'Task overdue',
                        description: 'A task that is not done passed its due date. Raised once '
                            .'per task; changing the due date lets it be raised again.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'task.create',
                        label: 'Add a task',
                        description: 'Adds a task to a board, optionally owned by somebody and '
                            .'about the record the rule fired on.',
                        // EXPLICIT: derived, this would be the bare `create`.
                        executionType: 'task.create',
                    ),
                    new ActionDefinition(
                        key: 'task.assign',
                        label: 'Give the task to somebody',
                        description: 'Makes a person the owner of the task the rule fired on.',
                        executionType: 'task.assign',
                    ),
                    new ActionDefinition(
                        key: 'task.move_group',
                        label: 'Move the task to a group',
                        description: 'Moves the task the rule fired on into another group on its own board.',
                        executionType: 'task.move_group',
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
                /*
                 * Four tabs, in the order the chip row draws them.
                 *
                 * ── Two of these four cannot be enforced at the endpoint ──
                 *
                 * Hiding a tab whose routes still answer is decoration, so this
                 * says which two are which rather than letting somebody assume
                 * all four are alike.
                 *
                 * `stock` and `orders` own their endpoints outright and carry
                 * `permission:` middleware in routes/api.php.
                 *
                 * `items` and `locations` are the module's REFERENCE DATA, not
                 * one tab's list. Measured: `/inventory/items` is read by six
                 * panes and `/inventory/locations` by seven, across all four
                 * tabs — every "new purchase order", "new sale", "new issue"
                 * and "new transfer" form has to name an item and a place. So a
                 * role that may cut a purchase order can necessarily read the
                 * catalogue, and gating those routes would refuse the Orders
                 * tab mid-form. Granting these two hides the LIST; it does not
                 * withhold the data, and an administrator should be told that.
                 * Closing it properly needs a narrow id-and-name picker
                 * endpoint for the forms — see TODO #36.
                 */
                tabs: [
                    // The module's dashboard, first and opened first (spec
                    // 2026-09-09-inventory-general-tab, built 2026-10-02).
                    new TabDefinition('general', 'General'),
                    new TabDefinition('stock', 'Stock'),
                    new TabDefinition('items', 'Items'),
                    new TabDefinition('orders', 'Orders'),
                    new TabDefinition('locations', 'Locations'),
                ],
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
                /*
                 * Labels copied from the chip bar in
                 * client/lib/screens/services_screen.dart, not written fresh — an
                 * administrator who ticks a name and then cannot find a chip called
                 * that has been handed a checkbox for the wrong thing, and nothing
                 * errors.
                 *
                 * What these two gate is deliberately NOT everything behind them.
                 * See the services block in routes/api.php: the catalogue list and
                 * the group and label lists stay on plain `services.view`, because
                 * Jobs prices a line out of the catalogue and the Catalogue chip's
                 * own pickers read the taxonomy. Only WRITES sit behind a chip.
                 */
                tabs: [
                    new TabDefinition('catalogue', 'Catalogue'),
                    new TabDefinition('settings', 'Settings'),
                ],
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
                // `manage` (Jose, 2026-10-15): the Manage pane, where lead sources
                // are connected, is its own grant instead of riding on `edit`.
                // `reclassify` and `dispatcher` (A1 Leads takeover, 2026-10-16):
                // asking Grok again, and saying who handled a call, are grants of
                // their own (Jose: "leads.* permission ticks for each").
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'export', 'manage', 'reclassify', 'dispatcher'],
                actionLabels: ['manage' => 'Manage sources (connect accounts)', 'reclassify' => 'Re-classify (AI, and new or existing caller)', 'dispatcher' => 'Change who handled a call'],
                /*
                 * THE SOURCES, GRANTED SEPARATELY - Jose, 2026-09-17: "i should
                 * be able to grant access by sub tab as well ... so this applies
                 * to every tab and it's sub tabs".
                 *
                 * The keys are the WIRE VALUES of `leads.source`, not prettier
                 * names, because the same four strings are the query parameter,
                 * the column and the client's chip list. A fifth spelling here
                 * would be a fifth thing to keep in step, and the one that drifts
                 * is always the one only a permission reads.
                 *
                 * The labels are the words the rail actually draws - "Chat",
                 * not "Website chat". Google is drawn as TWO chips, LS Call and
                 * LS Text, both reading `local_services` (`_maySee` in
                 * leads_screen.dart), so its one box names both. An
                 * administrator who ticks a name they cannot then find on screen
                 * has been told the name wrongly, and nothing errors.
                 *
                 * ── These are not tabs the way HR's are ──────────────
                 *
                 * HR's tabs are panes behind their own routes, so a route-level
                 * `permission:` gate is the whole enforcement. Leads has ONE
                 * list and one endpoint; the chips are a filter on it. Hiding a
                 * chip while `All` still returns Thumbtack rows would be a
                 * decoration rather than a permission - the pane a person is
                 * refused would be visible in the pane they are allowed.
                 *
                 * So the gate is a DATA filter in LeadsController, applied to
                 * every request including All, and `show()` refuses a single
                 * lead of a source the reader cannot see. See visibleSources().
                 */
                tabs: [
                    new TabDefinition('local_services', 'LS Call, LS Text and LS Booking'),
                    new TabDefinition('thumbtack', 'Thumbtack'),
                    new TabDefinition('website_chat', 'Chat'),
                    new TabDefinition('website_form', 'Forms'),
                    // The review link's sad faces (Jose, 2026-10-19).
                    new TabDefinition('bad_rating', 'Bad Ratings'),
                    // Appointments asked for on a website's booking widget (Jose, 2026-10-08).
                    new TabDefinition('website_booking', 'Online Booking'),
                    new TabDefinition('facebook', 'Messenger'),
                    new TabDefinition('instagram', 'Instagram'),
                    new TabDefinition('workiz', 'Workiz'),
                    new TabDefinition('whatsapp', 'WhatsApp'),
                    new TabDefinition('ringcentral', 'RC Calls'),
                    // Calls and texts to EzBizi's own lines (Jose, 2026-10-16).
                    new TabDefinition('ez_calls', 'EZ Calls'),
                    new TabDefinition('ez_texts', 'EZ Texts'),
                    // Private conversations kept from staff: sees, hides and unhides them (Jose, 2026-10-29).
                    new TabDefinition('hidden', 'Hidden'),
                ],
                subject: new AutomationSubject(Lead::class, 'Lead'),
                /*
                 * THREE record types, not one, and the other two are why this
                 * is separate from `subject` above.
                 *
                 * Leads raises `lead.*` carrying a Lead, and `customer.created`
                 * / `job.created` / `job.scheduled` when an enquiry is
                 * converted - the record handed to the event is the Customer or
                 * the Job that was just made, not the lead it came from. A
                 * reader of `subject` alone would conclude this module
                 * broadcasts one kind of record and be wrong about two.
                 *
                 * Declaring it is what lets AutomationSubjectScopeTest ask of
                 * each: can this say where it sits? Until today Leads was on
                 * that file's HAS_NOT_DECLARED_WHAT_IT_EMITS list, so nothing
                 * could ask, and a notify rule on any lead trigger reached
                 * every active user in the organization. Lead implements
                 * CarriesAutomationScope as of 8f526b14; Customer and Job carry
                 * owning_franchise_id and franchise_id.
                 *
                 * The declaration is a FLOOR rather than the fix. `leads` has a
                 * franchise_id column and would have satisfied that test for
                 * months while every website chat carried null in it - the
                 * column existing is not the column being filled. What actually
                 * narrowed the audience was implementing the interface.
                 */
                emits: [Lead::class, Customer::class, Job::class, Organization::class],
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
                        // FOUR SOURCES, AND THE SAME MISTAKE TWICE. The note
                        // above records Thumbtack being added while this
                        // sentence still said "advertising", so a rule author
                        // would conclude it did not cover them. Website chat
                        // has raised this since 2026-09-08 and the sentence
                        // still named two sources - measured on the box: 1992
                        // local_services, 4 thumbtack, 4 website_chat, all on
                        // this trigger. Forms take the same path and have had
                        // no submission yet. A description is the only thing a
                        // rule author has to go on, so it has to list what
                        // ACTUALLY raises this, and name the values a condition
                        // is written against.
                        description: 'An enquiry arrived — from Google Ads, from Thumbtack, or '
                            .'from the business\'s own website as a live chat or a form. It '
                            .'carries the contact details as the enquirer gave them, which source '
                            .'and location it came through, and when it arrived — not yet what it '
                            .'is about. Narrow it with a condition on "source": local_services, '
                            .'thumbtack, website_chat or website_form - or on "kind", which also '
                            .'splits Google into ls_call, ls_text and ls_booking.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.contact_received',
                        label: 'Enquirer called or messaged',
                        description: 'The person behind a lead reached out again — a call, or a '
                            .'message — and Google recorded it. It carries the channel, the kind '
                            .'of lead (ls_call, ls_text, thumbtack...), when, and the first line of '
                            .'what they wrote. The business\'s own replies do '
                            .'not raise it.',
                    ),
                    /*
                     * Jose, 2026-09-28: "a new lead would be that exactly, a new
                     * lead, while we need to have another sort of automation for
                     * replies, which should count only reply from the costumer
                     * not our own replies". "Enquirer called or messaged" also
                     * fires on a new lead's FIRST message, so a reply rule on it
                     * would ring for every new lead too. This one is only a
                     * customer message on a lead that already had one.
                     */
                    // Jose, 2026-09-28: a warning for dispatchers when a
                    // customer is left waiting. Raised once per unanswered
                    // customer message, by ezbizi:leads-reply-overdue.
                    new TriggerDefinition(
                        key: 'lead.reply_overdue',
                        label: 'Customer waiting for a reply',
                        description: 'A customer wrote and nobody has answered for 10 minutes, on a '
                            .'lead that is still New and can be replied to. Raised once per message '
                            .'left waiting; a booked or archived lead never raises it. It carries the '
                            .'source, the kind of lead, who it is, how many minutes they have waited '
                            .'and what they said.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.daily_digest',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Organization::class],
                        label: 'Daily leads digest',
                        description: 'Every morning, yesterday in numbers: how many leads by source, '
                            .'how many were answered and how fast, how many were booked, and each '
                            .'company\'s count. It carries a one-line summary ready for a WhatsApp or '
                            .'email step, and each figure on its own for conditions.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.follow_up_due',
                        label: 'Customer went quiet after the automatic reply',
                        description: 'Three hours after the automatic first reply, the same day, when the '
                            .'customer has not written since and no person has replied. Raised once '
                            .'per lead, ever, and never on a booked or archived lead or a complaint. '
                            .'It carries the source, the kind of lead, the name, first_name and a '
                            .'ready greeting ("Hi Mark", or "Hey" with no name) for the follow-up.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.customer_replied',
                        label: 'Customer replied',
                        description: 'The customer wrote or called again on a lead that already '
                            .'had a conversation, on any source. Their first message is "Lead '
                            .'received", not this, and replies from the business never raise it. '
                            .'It carries the source, the kind of lead (ls_call, ls_text, thumbtack...), '
                            .'the channel and the first line of what they said.',
                    ),
                    /*
                     * Separate from "received" because the gap between them is
                     * real and sometimes minutes long: a voicemail has to be
                     * transcribed before anything can be said about it. A rule
                     * that routes by job type has to wait for this one; a rule
                     * that just alerts somebody must not.
                     */
                    new TriggerDefinition(
                        key: 'lead.auto_replied',
                        label: 'Automatic reply sent',
                        description: 'A rule answered the customer automatically. It carries the '
                            .'source, the kind of lead, who it went to and the message as sent.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.classified',
                        label: 'Lead classified',
                        description: 'A lead was read and categorised — what kind of work it is '
                            .'about, and whether it looks like a real enquiry at all. It fires '
                            .'after "Lead received", once any recording has been transcribed.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.call_complaint',
                        label: 'A call sounded like a complaint',
                        description: 'AI read a call transcript and the caller sounded unhappy - '
                            .'with work done, a no-show, a charge or how they were treated. It '
                            .'carries the AI one-line summary, plus the caller name and phone.',
                        composedFields: ['summary'],
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
                        key: 'lead.missed_call',
                        label: 'Missed call',
                        description: 'Somebody rang one of the business\'s own RingCentral lines and nobody '
                            .'picked up. It carries their number, name when known, the line they rang '
                            .'and when. Answered calls raise nothing.',
                    ),
                    new TriggerDefinition(
                        key: 'lead.booking_approved',
                        label: 'Online booking approved',
                        description: 'A dispatcher approved a booking made on the website. It carries '
                            .'the day and arrival window as the customer reads them ({{booking_when}}) '
                            .'and the branch phone for rescheduling ({{office_phone}}). Raised once per booking.',
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
                    /*
                     * WEBSITE CHAT ONLY, AND THE LIMIT IS A FILING RATHER THAN
                     * A PREFERENCE. EzBizi told Google's ads-api-compliance
                     * team and OAuth Trust and Safety, in writing and in these
                     * words, that "the only write is appendLeadConversation:
                     * one reply a member of staff types and sends to a text
                     * enquiry". Both submissions are open and one has already
                     * bounced twice on discrepancies. A rule reaching a
                     * local_services lead makes a live filing false.
                     *
                     * The limit is STRUCTURAL rather than a note here:
                     * `LeadReplyChannel::allowsUnattendedReply()` is required
                     * rather than defaulted, answers false on LocalServices and
                     * Thumbtack, and those two THROW on an automatic send even
                     * if a caller forgets to ask. Thumbtack's false is a choice
                     * rather than a filing - one line and an argument if it is
                     * ever wanted.
                     */
                    new ActionDefinition(
                        key: 'lead.reply',
                        label: 'Reply to the lead',
                        description: 'Sends a reply in the conversation the enquiry arrived on — '
                            .'a Google Local Services text enquiry, website live chat, Thumbtack, '
                            .'Facebook or Instagram. A Google call has nothing to reply into, and '
                            .'neither has a website form. The reply is recorded as automatic, so it '
                            .'does not take the lead off the unreplied list.',
                    ),
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
             * ── Customer Experience: a container for Reviews and Business Profile ──
             *
             * Jose, 2026-09-25: *"inside you will move reviews and business
             * profile that are currently in marketing out of there and into the
             * new customer experience tab"*.
             *
             * Not A1Leads GBP: that is a window onto Rodrigo's system, and
             * putting EzBizi's own modules there was the mistake undone on
             * 2026-09-23. This is EzBizi's own tab and holds EzBizi's own modules.
             *
             * `view` because the registry refuses `actions: []`. Holding it and
             * neither child gets the row and a screen saying nothing inside is
             * available; holding a child and not this still gets the row
             * (main.dart `_hasDestination`). Nesting is layout, not a grant.
             */
            new ModuleDefinition(
                key: 'customer_experience',
                label: 'Customer Experience',
                // Complaints (Jose, 2026-10-20): every bad review, Bad Rating and
                // complaint call, and where handling it has got to. The tab is
                // customer_experience.complaints.view; working one is .manage.
                actions: ['view', 'complaints.manage'],
                actionLabels: ['complaints.manage' => 'Work complaints (status, owner, notes)'],
                tabs: [
                    new TabDefinition('complaints', 'Complaints'),
                ],
                auditActionPrefixes: ['complaint'],
                subject: new AutomationSubject(\App\Models\Complaint::class, 'Complaint', key: 'complaint'),
                emits: [\App\Models\Complaint::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'complaint.opened',
                        label: 'Complaint opened',
                        description: 'A review of 3 stars or fewer, a Bad Rating from the website, or a call '
                            .'heard as a complaint was added to Complaints. It carries the kind and, for a '
                            .'review, the rating.',
                    ),
                    new TriggerDefinition(
                        key: 'complaint.status_changed',
                        label: 'Complaint status changed',
                        description: 'Somebody moved a complaint to New, In progress, Solved or Cannot be '
                            .'solved. It carries the status before and after.',
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
                key: 'reviews',
                label: 'Reviews',
                // Drawn as a tab inside Customer Experience (moved out of
                // Marketing 2026-09-25, Jose). Its permissions are untouched:
                // this says where it appears, not who reaches it.
                //
                // NEVER inside A1Leads GBP - moved there on 2026-09-23 and put
                // back the same day. Jose: *"we still need our system in place
                // for when we get OUR GBP Credentials."* A1Leads GBP is a bridge
                // to somebody else's system; this is EzBizi's own module.
                parent: 'customer_experience',
                // `manage`: working a review as a case - status, notes, the
                // customer it is about, reporting it (UI queue 101, 2026-10-16).
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'publish', 'manage'],
                actionLabels: ['manage' => 'Work reviews as cases (status, notes, customer, report)'],
                subject: new AutomationSubject(Review::class, 'Review'),
                triggers: [
                    new TriggerDefinition(
                        key: 'review.received',
                        label: 'Review received',
                        description: 'A new review arrived on a connected Business Profile. It '
                            .'carries the rating, the text as the reviewer wrote it, which '
                            .'location it is about, and how many days old it is (age_days).',
                    ),
                    new TriggerDefinition(
                        key: 'review.removed',
                        label: 'Review removed',
                        description: 'Google no longer shows a review: three complete lists without it, '
                            .'each confirmed by Google answering that the review is gone, at least two '
                            .'hours apart. Raised once per review.',
                    ),
                    new TriggerDefinition(
                        key: 'review.reply_published',
                        label: 'Reply published',
                        // The distinction somebody will get wrong. Drafting
                        // produces text; publishing is the moment it becomes
                        // public under the customer's name. Only the second
                        // raises this.
                        description: 'An approved reply went live on Google. Drafting a reply does '
                            .'not raise this — only publication does.',
                    ),
                    new TriggerDefinition(
                        key: 'review.reply_failed',
                        label: 'Reply failed to publish',
                        description: 'Google refused a reply somebody tried to publish. It carries '
                            .'the reason, and the wording is kept rather than retried silently, so '
                            .'a person decides what happens next.',
                    ),
                ],
                automationActions: [
                    new ActionDefinition(
                        key: 'review.draft_reply',
                        label: 'Draft a reply',
                        description: 'Writes a suggested reply to the review that triggered the '
                            .'rule and leaves it waiting for a person. It does not publish.',
                    ),
                    /*
                     * Jose, 2026-10-16/20 (A1 Leads takeover): good reviews answered
                     * automatically. Guarded three ways - the listing's own
                     * auto-reply switch, off by default; the author's publish grant
                     * and reach; never over another reply.
                     */
                    new ActionDefinition(
                        key: 'review.publish_reply',
                        label: 'Publish a reply on Google',
                        description: 'Posts the reply under the review on Google, by itself. Only on '
                            .'listings where automatic replies are switched on under Business Profile; '
                            .'elsewhere it posts nothing. Never over a reply or draft already there.',
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
                key: 'business_profile',
                label: 'Business Profile',
                auditActionPrefixes: ['business_location'],
                // Drawn as a tab inside Customer Experience (moved out of
                // Marketing 2026-09-25, Jose). Its permissions are untouched:
                // this says where it appears, not who reaches it.
                //
                // NEVER inside A1Leads GBP - moved there on 2026-09-23 and put
                // back the same day. Jose: *"we still need our system in place
                // for when we get OUR GBP Credentials."* A1Leads GBP is a bridge
                // to somebody else's system; this is EzBizi's own module.
                parent: 'customer_experience',
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
                        key: 'business_location.changed_on_google',
                        label: 'Changed on Google',
                        description: 'A connected listing changed on Google without the change '
                            .'being made here — its name, phone, address, category, or whether '
                            .'Google is showing it at all. It carries which fields moved, and '
                            .'what they were before.',
                    ),
                    new TriggerDefinition(
                        key: 'business_location.franchise_set',
                        label: 'Location assigned to a branch',
                        description: 'Somebody said which franchise answers for a listing. It is '
                            .'a deliberate act rather than part of a sync, and the sync never '
                            .'changes it — so this fires when a person decides, and never when '
                            .'Google is read.',
                    ),
                    new TriggerDefinition(
                        key: 'business_location.daily_digest',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [],
                        label: 'Daily Business Profile summary',
                        description: 'Once a day at 7 AM Eastern, when anything happened in the last 24 hours: '
                            .'new reviews and how many had 2 stars or fewer, reviews Google removed, and '
                            .'every listing change on Google. Not raised on a quiet day. The text is in '
                            .'{{summary}}.',
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
                auditActionPrefixes: ['mailbox', 'sending_identity', 'email_filter'],
                // Jose 2026-10-28: anybody with the Inbox files their own mail; this makes filters for everyone at a place they reach.
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'filters.share'],

                /*
                 * ── TWO TABS, AND THE SECOND ONE IS THE POINT ────────
                 *
                 * Jose, 2026-09-17: "i should be able to grant access by sub
                 * tab as well ... so this applies to every tab and it's sub
                 * tabs". For Email the split somebody actually wants is
                 * obvious: the people who READ the mail are not the people who
                 * CONNECT the mailboxes. Settings holds provider sign-ins,
                 * server addresses and the per-role inbox grants themselves --
                 * the screen on which somebody could grant themselves an inbox.
                 *
                 * Labels copied from the tab bar in `email_screen.dart`, not
                 * written fresh. An administrator who ticks "Settings" and then
                 * looks for a tab with that word has to find one.
                 *
                 * ── THIS IS THE THIRD LEVEL, AND IT IS NOT A FOURTH ──
                 *
                 * Email already had a second-level grant that is NOT a
                 * permission key: the per-inbox `MailboxGrant` rows, which say
                 * WHICH inboxes a role may read once it is through the door.
                 * Tabs now sit beside them under one module heading, so the
                 * editor reads: open Email, open which tab, read which inbox.
                 *
                 * They are genuinely different questions and neither replaces
                 * the other -- `email.inbox.view` is "may you see the mail
                 * screen at all", a grant is "whose mail". A role with the tab
                 * and no grant gets the screen and the sentence explaining that
                 * no inbox has been given to it, which is the honest answer and
                 * already written.
                 *
                 * NOTHING NARROWS TODAY. The migration grants both tabs to
                 * every role already holding `email.view`, so no role loses a
                 * screen it had this morning.
                 */
                tabs: [
                    new TabDefinition('inbox', 'Inbox'),
                    new TabDefinition('settings', 'Settings'),
                    new TabDefinition('workspace', 'Google Workspace'),
                ],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Mailbox::class],
                        label: 'An email arrived',
                        description: 'A new message reached one of the shared inboxes. It '
                            .'carries which inbox it arrived at, who it is from, the subject, '
                            .'when it was sent and whether anything is attached — never the '
                            .'contents, which are not stored anywhere. For replies it also '
                            .'carries the sender domain (from_domain), whether the inbox '
                            .'office is open (office_open), when it next opens (office_opens), '
                            .'its office phone, company name and a greeting. EzBizi checks each '
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Mailbox::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Mailbox::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Mailbox::class],
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
                    /*
                     * THIS USED TO BE `email.mark_seen`, AND THE RENAME IS THE
                     * WHOLE POINT.
                     *
                     * The old key said "seen" while its own label said "dealt
                     * with", and nothing implemented it — which was lucky,
                     * because the obvious implementation was to write a
                     * `message_seen` row. That table is per person and is what
                     * "Seen by Jenny" reads. A rule writing to it would assert
                     * that somebody had READ a message nobody read, and a
                     * colleague would then skip a customer's mail on the
                     * strength of a name that was never there.
                     *
                     * A rule has no eyes, so it cannot have seen anything. It
                     * can perfectly well have HANDLED something, which is a
                     * different fact with a different grain — one per message
                     * for the whole inbox rather than one per reader. That fact
                     * now has its own table, and this is the action that writes
                     * it. Jose's decision, 2026-09-09.
                     *
                     * Three segments, not two: ActionDefinition::type() keeps
                     * everything after the FIRST dot, so `email.mark_dealt`
                     * would execute as a bare `mark_dealt` in the namespace
                     * every module shares — the trap `time_clock.clock_in`
                     * documents one block above.
                     */
                    new ActionDefinition(
                        key: 'email.message.mark_dealt',
                        label: 'Mark a message as dealt with',
                        description: 'Records that a message in a shared inbox has been handled, '
                            .'so it stops showing as waiting for somebody. It does NOT record '
                            .'that anyone read it — "Seen by" stays a statement about people. '
                            .'It changes what the team sees in EzBizi and sends nothing to '
                            .'anyone.',
                    ),
                    new ActionDefinition(
                        key: 'email.message.reply',
                        label: 'Reply to the email',
                        description: 'Answers the email that arrived, in its thread, from the same inbox '
                            .'- for example after hours or on Sundays. The email itself stays '
                            .'unread. Never answers this inbox itself or an automatic address, and '
                            .'answers each sender at most once a day.',
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
                    TrainingProgress::class,
                    TrainingAttempt::class,
                    TrainingAssignment::class,
                ],
                actions: ['view', 'monitor', 'manage', 'assign'],
                /*
                 * Three tabs, each NAMING an action this module already has
                 * rather than minting a new one (Jose, 2026-09-17: "i should be
                 * able to grant access by sub tab as well ... this applies to
                 * every tab and it's sub tabs").
                 *
                 * Training's tabs were three separate grants before that ask
                 * existed, so the only thing missing was the LABEL: the roles
                 * editor rendered them "View training" / "Monitor training" /
                 * "Manage training", and an administrator hunting for the box
                 * that opens the Dashboard had to infer which one it was.
                 * `action:` supplies the name without a second key.
                 *
                 * ── Why these must never MINT, if you are about to change it ──
                 *
                 * Dropping `action:` makes `TabDefinition` mint `{key}.view`, so
                 * a `dashboard` tab becomes `training.dashboard.view` sitting
                 * beside `training.monitor`: two names for one authority. An
                 * administrator could then grant the tab without the read and
                 * get a Dashboard that opens and then refuses every request
                 * inside it — an affordance without its gate, a state this
                 * product cannot otherwise reach, and nothing would report it.
                 *
                 * The other way round is worse. `monitor` is a DISCLOSURE
                 * boundary, not a visibility toggle: it decides who may read
                 * named people's results, and this module shipped two
                 * cross-franchise disclosures learning to hold that line
                 * (task-11 review). A tab grant is "visibility only,
                 * deliberately" by `TabDefinition`'s own docblock, which is the
                 * right weight for Work Hours and the wrong weight for this.
                 *
                 * `TrainingModuleTest::test_every_tab_names_an_existing_action_and_mints_nothing`
                 * pins it. `ModuleDefinition` also refuses a named action the
                 * module does not declare, at boot.
                 *
                 * The client needs nothing: `training_screen.dart` already omits
                 * a tab from `_tabs` when its permission is absent, and the keys
                 * it reads are unchanged by naming.
                 */
                tabs: [
                    new TabDefinition('learn', 'Learn', action: 'view'),
                    new TabDefinition('dashboard', 'Dashboard', action: 'monitor'),
                    new TabDefinition('settings', 'Settings', action: 'manage'),
                ],
                /*
                 * `assign` DELIBERATELY declares no `actionRequires`, and the
                 * history is worth keeping because the obvious reading is wrong.
                 *
                 * It carried `['assign' => ['training.monitor']]` from 0.0.0180
                 * to 0.0.0182, on the reasoning that the only assign control
                 * lives in the Dashboard pane and `monitor` is what opens it, so
                 * assign alone was a role with nothing to click.
                 *
                 * That reasoning was measured wrong. `ActionRunner::taskContext`
                 * runs an automation action as the RULE'S CREATOR, and
                 * `AssignmentWriter::create` gates on `training.assign` and
                 * nothing else. So the automations builder is a second entry
                 * point and it needs no monitor: assign alone is a working
                 * grant. Training proved it by execution, in
                 * TrainingAssignmentReachTest.
                 *
                 * The trap that hid it: the premise was made true BY the
                 * refusal. With the requirement in place no administrator can
                 * create that role, so "assign alone has no entry point" became
                 * self-confirming. The proving test builds the role directly
                 * rather than through RolesController for exactly that reason.
                 *
                 * What it refused was a coherent configuration: may build a
                 * rule that assigns training, may not read everyone's results.
                 * That is a sensible role in a product where a company grants
                 * roles to its own customers, and refusing it forced an admin
                 * who wanted rule-driven assignment to grant org-wide monitor,
                 * which is the exact widening the requirement was written to
                 * avoid.
                 */
                triggers: [
                    new TriggerDefinition(
                        key: 'training.assigned',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\TrainingAssignment::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\TrainingProgress::class],
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\TrainingAttempt::class],
                        label: 'Test passed',
                        description: 'Somebody took a test and met its pass mark. It carries the '
                            .'score and, when the test expires, the date the pass runs out.',
                        isRaised: true,
                    ),
                    new TriggerDefinition(
                        key: 'training.failed',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\TrainingAttempt::class],
                        label: 'Test failed',
                        description: 'Somebody took a test and did not meet the pass mark. It carries '
                            .'the score. Whether they may try again is the test\'s own retake setting.',
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
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\TrainingAttempt::class],
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
                // Still no ACTIONS, and the reason is unchanged: an automation
                // that could create or edit automations is a self-modifying
                // system, because the loop guards count actions rather than
                // rules, so a rule that writes rules escapes them entirely. It
                // stays a human act. The exemption is about actions only — the
                // trigger below is not a rule writing a rule, it is a clock.
                automationExemptBecause: 'An automation that could write automations would escape the loop guards.',
                /*
                 * TWO TABS, AND THEY ARE TWO DIFFERENT THINGS TO BE TRUSTED WITH.
                 *
                 * Jose, 2026-09-17, relayed with the rest: *"i should be able to
                 * grant access by sub tab as well ... so this applies to every
                 * tab and it's sub tabs"*.
                 *
                 * The split here is not cosmetic. BUILD is a construction
                 * surface — the catalogue tells somebody every trigger and
                 * action this installation can perform, which is a map of the
                 * whole system. YOUR AUTOMATIONS is a reading surface, and a run
                 * log names the records a rule touched, which is the disclosure
                 * half: a person who may read runs can learn about records they
                 * could not open directly.
                 *
                 * Labels are the tab bar's own words, copied from
                 * automations_screen.dart rather than written fresh — an
                 * administrator who ticks a name and then cannot find that tab
                 * has been handed a checkbox for something they were told the
                 * wrong name for, and nothing errors.
                 */
                tabs: [
                    new TabDefinition('rules', 'Your automations'),
                    new TabDefinition('build', 'Build'),
                ],
                emits: [Automation::class],
                triggers: [
                    /*
                     * THE CLOCK, and the one trigger that is about no record.
                     *
                     * Every other trigger in this application answers "something
                     * happened to this thing". This one answers "the time you
                     * asked for came round", which is why it lives on the
                     * Automations module rather than a business one: it belongs
                     * to no module's work, and the tab it is reachable from is
                     * the one where rules are written. Anybody building a rule
                     * already holds `automations.view`, so the catalogue's reach
                     * filter is exactly right without a new grant.
                     *
                     * ── The subject is the RULE, and that is not cosmetic ────
                     *
                     * `NotificationDispatcher::audienceFor` reads the subject to
                     * decide who hears, and falls through to EVERY ACTIVE USER
                     * when a record cannot say where it sits. A schedule has no
                     * record at all, so "email me the weekly report" written at
                     * one branch would have mailed the whole business — with
                     * nothing on screen looking wrong. The rule is a record and
                     * knows its own scope, so it is its own subject and reaches
                     * the people its author meant. See Automation::automationScope.
                     */
                    new TriggerDefinition(
                        key: 'automation.schedule_reached',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [],
                        label: 'A time you chose comes round',
                        description: 'Runs on a clock rather than on something happening — every '
                            .'day, on chosen days of the week, or once a month. The time is read '
                            .'in the timezone you pick when you build it, so it stays correct '
                            .'through daylight saving. It carries when it was due and when it is '
                            .'next expected.',
                    ),
                ],
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
                // `files.view` is split off the same way: browsing and
                // downloading a machine's files is its own authority, neither
                // watching the screen nor driving it — see FilesController
                // (2026-10-01-monitoring-files).
                actions: ['view', 'specs.view', 'screenshots.view', 'cameras.view', 'cameras.manage', 'map.manage', 'control', 'files.view', 'lighting.manage'],
                // cameras.manage and control write records / drive a machine, so
                // §2.2 requires an audit trail (writesRecords() flags them as
                // writes since 2026-09-09). CamerasController logs
                // monitoring.camera.{created,updated,deleted}; RemoteControlController
                // logs monitoring.control.started once per control session. Declared
                // here so the gate resolves the 'monitoring' prefix to those calls
                // (ModuleRegistryTest §2.2 asks the source, not this list).
                auditActionPrefixes: ['monitoring'],
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
                    new TriggerDefinition(
                        key: 'monitoring.security_needs_attention',
                        label: 'Device security needs attention',
                        description: 'Windows reported the antivirus, firewall or '
                            .'updates on a device as off or paused. Raised once when it '
                            .'happens, not again until it is fixed and happens again.',
                    ),
                ],
                /*
                 * The four chips MonitoringScreen draws, in that order.
                 *
                 * THREE OF THESE NAME AN ACTION RATHER THAN MINTING ONE, and
                 * this module is the case the mechanism was named after:
                 * `monitoring.specs.view`, `monitoring.cameras.view` and
                 * `monitoring.screenshots.view` have been separate grants since
                 * the module shipped, because "may see who is online" and "may
                 * look at their screen" were never the same permission here.
                 *
                 * Minting `monitoring.desktop.view` beside `screenshots.view`
                 * would put two names on one authority, and an administrator
                 * could then grant the tab without the read — a Desktop
                 * Monitoring pane that opens and shows nothing, which is the
                 * affordance-without-its-gate shape. What was missing was the
                 * LABEL: the catalogue renders these as "Screenshots.view", so
                 * somebody hunting for the box that opens Desktop Monitoring
                 * has to infer it.
                 *
                 * PC SPECS NAMES `view`, AND NAMING `specs.view` WOULD HAVE
                 * BEEN THE SILENT NARROWING THIS WHOLE SWEEP EXISTS TO STOP.
                 * That tab is the people roster — who is online, on what, seen
                 * when — and `monitoring.specs.view` gates only the HARDWARE
                 * DETAIL inside it: DevicesController withholds the `specs` key
                 * without that grant and the roster still lists everybody. So
                 * the tab is the module's own view, exactly as Training's Learn
                 * pane is `training.view`. Naming `specs.view` would have taken
                 * the roster away from every role holding plain
                 * `monitoring.view` — and the backfill could not have restored
                 * it, correctly, because a named action is skipped there.
                 *
                 * GENERAL AND MAP ARE TWO TABS NOW (Jose, 2026-09-22). The
                 * floor-plan map moved to `map` and General became the
                 * ownership chart — the same people and machines as a table
                 * rather than a picture. Both mint, and the migration beside
                 * this hands `map.view` to everybody who held `general.view`,
                 * because the thing they could open yesterday is the thing
                 * that moved.
                 *
                 * `general` is the one that MINTS, and it is the only one that
                 * should. The floor-plan map has ridden on the module's own
                 * `monitoring.view` since it shipped, so a tab key for it is
                 * new and its backfill genuinely preserves what roles had.
                 * Naming `view` instead would draw a General checkbox that IS
                 * the module grant — ticking a tab to gain the module.
                 */
                tabs: [
                    new TabDefinition('general', 'General'),
                    new TabDefinition('map', 'Map'),
                    new TabDefinition('specs', 'PC Specs', action: 'view'),
                    new TabDefinition('files', 'Files', action: 'files.view'),
                    new TabDefinition('cameras', 'Camera Monitoring', action: 'cameras.view'),
                    new TabDefinition('desktop', 'Desktop Monitoring', action: 'screenshots.view'),
                    new TabDefinition('lighting', 'Lighting', action: 'lighting.manage'),
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

            /*
             * Reports (docs/superpowers/specs/2026-09-10-reports-design.md).
             *
             * A builder rather than a fixed catalogue: somebody composes a
             * report across modules, saves it, and other people run it. What is
             * readable comes from `Support/Reports/ReportMap`, and a table
             * absent from that map cannot be reported on at all.
             *
             * THREE PERMISSIONS, AND `build` IS THE LARGE ONE. `view` opens the
             * tab and runs what you have been given; `build` composes, which
             * means seeing every column of every module you can already open,
             * money included; `export` downloads a run. An organization that
             * wants somebody to run reports but not write them grants `view`
             * alone, which is expected to be the common case. Both `build` and
             * `export` require `view` — the roles editor draws one word per
             * action and could not otherwise say that building without the tab
             * is a grant with nothing to click.
             *
             * NO TRIGGERS YET, so no `emits:`. Reports observes other modules'
             * records rather than owning one that anything would react to; if a
             * "report was run" trigger is ever wanted, its subject is the saved
             * report and that is the day this gains an `emits:`.
             */
            new ModuleDefinition(
                key: 'reports',
                label: 'Reports',
                auditActionPrefixes: ['report'],
                actions: ['view', 'build', 'export', 'scoring_weights', 'settings', 'correct', 'handles'],

                /*
                 * ── THE TABS, and what a tab grant MEANS here ───────
                 *
                 * Jose, 2026-09-17: "i should be able to grant access by sub
                 * tab as well ... so this applies to every tab and it's sub
                 * tabs".
                 *
                 * In most modules a tab grant reads as "which pane opens". In
                 * this one the panes are the same capability aimed at different
                 * data, so it reads as WHICH FIGURES A ROLE MAY SEE - a
                 * stronger claim, and the reason Experimental is the tab worth
                 * having. It names every person who answered a phone, how long
                 * they spent on it and how much of their work became jobs. That
                 * is personnel performance, and somebody who builds reports
                 * about jobs has no automatic business reading it.
                 *
                 * Labels copied from the chips in reports_screen.dart, not
                 * written fresh: an administrator who ticks a box and then
                 * cannot find a tab by that name has been told the name wrongly
                 * and nothing errors.
                 *
                 * ── The other doors were checked, not assumed ───────
                 *
                 * A tab that hides figures is worth nothing if the same numbers
                 * come out of another pane. Measured 2026-09-17: the report
                 * builder reads ReportMap, which holds EzBizi's own records
                 * only - no RingCentral calls, no Workiz jobs, and by written
                 * commitment to Google no ads_performance resource. Profit and
                 * loss is read live from Airtable and is its own module with
                 * its own grant. So no tab here can be reached around by
                 * building a report, and that is a fact about ReportMap rather
                 * than about these three keys: it stops being true the day
                 * anything external is stored in a resource the builder offers.
                 */
                // A RingCentral call is no EzBizi record and sits in no franchise:
                // the event is the organization's, and its rule names who hears.
                emits: [Organization::class],
                triggers: [
                    // Bar, 2026-10-07: a notification "whenever we're receiving a
                    // missed call from the report". ezbizi:watch-missed-calls.
                    new TriggerDefinition(
                        key: 'reports.call.missed',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [],
                        label: 'A call in the call report was missed',
                        description: 'A call to one of the company\'s RingCentral lines reached nobody: '
                            .'missed, or left in voicemail. Spam that sat in the phone menu is not '
                            .'one. It carries the caller\'s number, the line they rang and the company.',
                        isRaised: true,
                    ),
                    // Jose, 2026-10-13 ("Yes", via Grok AI): calls AI read as booked
                    // with no Workiz job, daily for a manager. ezbizi:ai-booked-no-job.
                    new TriggerDefinition(
                        key: 'reports.ai_booked_no_job',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [],
                        label: 'Calls AI says were booked have no job',
                        description: 'Each morning, the calls from the day before that AI read as booked but that '
                            .'have no job in Workiz, so a manager can follow up. Raised only when there are any. '
                            .'It carries the count, the day and a list: time, line, the caller\'s number, who answered.',
                        composedFields: ['summary'],
                    ),
                    // Jose, 2026-10-12: a weekly plain-English report by WhatsApp
                    // and email. ezbizi:ai-weekly-summary, Monday 7:00 New York.
                    new TriggerDefinition(
                        key: 'reports.ai_summary_ready',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [],
                        label: 'Weekly AI summary is ready',
                        description: 'Every Monday morning AI reads the figures for last week (jobs, '
                            .'sales, deposits, profit and call reasons, per branch) and writes a '
                            .'short plain-English summary with what to do this week. It carries '
                            .'a headline, the summary and the dates it covers. It CONTAINS MONEY '
                            .'FIGURES (sales, deposits, profit and loss) that the app keeps behind '
                            .'the financials permissions: send it only to people who may see them.',
                        composedFields: ['summary'],
                    ),
                ],
                tabs: [
                    new TabDefinition('library', 'Reports'),
                    // Labels renamed by Jose, 2026-10-06; the keys (and so the
                    // permissions reports.builder.view / reports.experimental.view)
                    // stay, so no role loses a grant.
                    new TabDefinition('builder', 'Report Builder'),
                    new TabDefinition('experimental', 'Performance'),
                    // Each dispatcher as a card, ranked by AI's call score
                    // (Jose, 2026-10-13). Granted to Administrator and Lead
                    // Manager only (Jose: "right now only administrators and
                    // lead manager").
                    new TabDefinition('scoring', 'Scoring'),
                    // Ad cost against jobs and profit (Jose, 2026-10-12); the
                    // server also asks for profit_loss.view, its profit being
                    // the P&L's margin.
                    new TabDefinition('marketing', 'Marketing'),
                    // Facebook ad spend by campaign, state and category.
                    new TabDefinition('meta', 'Meta'),
                    // Thumbtack lead spend by category and branch (2026-10-13).
                    new TabDefinition('thumbtack', 'Thumbtack'),
                    // Pick a report, preview it, export, approve or send it
                    // (takeover row 10, UI queue 157, Jose 2026-10-29).
                    new TabDefinition('workspace', 'Workspace'),
                ],
                actionRequires: [
                    'build' => ['reports.view'],
                    'export' => ['reports.view'],
                    // Setting what each Scoring metric counts for (Jose, 2026-10-14).
                    'scoring_weights' => ['reports.scoring.view'],
                    // The weekly/monthly report's goals (2026-10-16).
                    'settings' => ['reports.view'],
                    // Correcting a report draft before it is approved (2026-10-20).
                    'correct' => ['reports.view'],
                    // Performance's Handles and Convert to booked (Jose, 2026-11-02: a
                    // Dispatch Supervisor was refused with no tick to grant).
                    'handles' => ['reports.experimental.view'],
                ],
                actionLabels: ['scoring_weights' => "Set Scoring's weights", 'settings' => "Set the reports' goals", 'correct' => 'Correct a report before it is approved', 'handles' => 'Set who handles New or Existing clients, and mark calls booked, on Performance'],
                automationActions: [
                    new ActionDefinition(
                        key: 'report.send',
                        label: 'Email a report',
                        description: 'Runs a saved report and emails the result to the people '
                            .'you name. The report is run with the scope of the rule itself, so a rule '
                            .'belonging to one franchise cannot mail a report covering the whole '
                            .'organization.',
                        /*
                         * SPELLED OUT, AND IT IS LOAD-BEARING. ActionDefinition
                         * derives the execution type as everything after the
                         * FIRST dot, so `report.send` left alone becomes the bare
                         * word `send`, in a namespace every module shares.
                         *
                         * Nothing collides with it today — all 28 declared
                         * actions were checked and none derives to bare `send`;
                         * `phone.sms.send` has two dots and derives to
                         * `sms.send`. So this prevents a FUTURE collision, which
                         * is the only kind available here. Note also that the
                         * constructor validates the dotted shape only when the
                         * type is passed explicitly, so the careful form is the
                         * one that can fail and the derived form is never
                         * checked at all.
                         */
                        executionType: 'report.send',
                        /*
                         * The report and the recipients come from the rule's own
                         * configuration, not from whatever record the event was
                         * about: "email the weekly job report when a month
                         * closes" has no subject to read them from. Same shape as
                         * phone.sms.send.
                         *
                         * DELIBERATELY ABSENT FROM AutomationsController::
                         * IMPLEMENTED_ACTIONS until the runner arm exists. That
                         * const publishes `implemented: true`, which would let a
                         * tenant build and save a rule that throws on every run
                         * and auto-disables itself. Declared-without-const draws
                         * as "Not built yet" and cannot be selected, which is the
                         * honest state today.
                         */
                        subjectBound: false,
                    ),
                ],
                rolesGroup: ModuleDefinition::ROLES_TAB,
            ),

            /*
             * ── Google Ads: what the advertising costs ──────────
             *
             * A tab inside Reports, not Marketing. Jose's call, 2026-09-15:
             * "It is clearly not supposed to be in Marketing." Marketing is
             * what the business SAYS about itself - its listing, its reviews,
             * its posts. This is a measurement of what that cost, which is the
             * same kind of thing as every other report.
             *
             * Account and Campaign levels, and nothing lower. The scope is a
             * compliance position rather than a backlog: Google's Reporting
             * Minimum Functionality applies to each level of the hierarchy a
             * tool DISPLAYS, so adding an ad group, ad or keyword here would
             * make R.40, R.50, R.70, R.100 and R.130 apply, each with its own
             * mandatory fields, and oblige two weeks' notice to Google before
             * the change takes effect.
             *
             * `view` and `manage`, and not the usual four. The figures are
             * Google's and are read live rather than stored, so create, edit
             * and delete would offer three things that cannot exist. `manage`
             * is pausing and enabling campaigns (Jose, 2026-09-24) - Google's
             * M.110 - and is its own grant because switching a branch's
             * advertising off is a different act from reading what it cost.
             * Every change is audited as `ads_performance.campaign_status_changed`,
             * with the status it moved to.
             *
             * Its own permission rather than borrowing `reports.view`: what the
             * business spends on advertising is a different grant from what it
             * measures internally, and an organization that wants one without
             * the other should not have to choose.
             */

            new ModuleDefinition(
                key: 'ads_performance',
                label: 'Google Ads',
                parent: 'reports',
                actions: ['view', 'manage'],
                // AUTOMATED since 2026-09-28, as the exemption this replaced
                // said it would be "the day spend is stored or watched": each
                // account's Local Services certification is now watched daily
                // (`ezbizi:ads-certifications`). The subject is the account's
                // AdsAccountState, which sits where its connection sits.
                // Pausing a campaign stays a person's button, not an action.
                subject: new AutomationSubject(AdsAccountState::class, 'Google Ads account', key: 'ads'),
                emits: [AdsAccountState::class, Organization::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'ads.certification_problem',
                        label: 'Certification problem',
                        description: 'A Local Services account has a licence, insurance or background '
                            .'check problem - lapsed, failed, never submitted, or expiring within 30 '
                            .'days - and Google may stop showing its ads. Raised when the problems '
                            .'change, not every day they last.',
                    ),
                    new TriggerDefinition(
                        key: 'ads.campaign_stopped',
                        label: 'Ads stopped showing',
                        description: 'A Local Services campaign that was showing stopped - paused, '
                            .'ended or no longer eligible - checked every hour. Names who paused it: '
                            .'the person, when it was paused in EzBizi; the Google user, when '
                            .'Google change history has one; nobody, when neither does.',
                    ),
                    new TriggerDefinition(
                        key: 'ads.campaign_resumed',
                        label: 'Ads showing again',
                        description: 'A Local Services campaign that had stopped showing is showing '
                            .'again, checked every hour - the other half of "Ads stopped showing".',
                    ),
                    new TriggerDefinition(
                        key: 'ads.certification_resolved',
                        label: 'Certification problem resolved',
                        description: 'A Local Services account that had a licence, insurance or '
                            .'background check problem is clean again.',
                    ),
                    new TriggerDefinition(
                        key: 'ads.daily_review',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Organization::class],
                        label: 'Daily account review',
                        description: 'Every morning at 6:30 (New York): which Local Services accounts '
                            .'have ads not running or licence / insurance problems. Monday is the whole '
                            .'picture; other days only what changed, and nothing when nothing did. It '
                            .'carries a ready summary for a WhatsApp or email step.',
                    ),
                ],
            ),

            /*
             * Profit & Loss - a tenant's own accounting sheet, read live out
             * of Airtable.
             *
             * AFTER Google Ads, and that is a decision rather than the order
             * it was typed in. Registry order is the strip order, so placing
             * this ahead of Google Ads would move a chip that has been in the
             * same position for weeks - a silent change to where people
             * already click, with nothing on screen to explain it. A new tab
             * goes on the end.
             *
             * It was ahead of it for one commit, because the edit that added
             * it anchored on Google Ads and inserted above - which also split
             * Google Ads' comment from its own definition. Both are why this
             * says which order it wants rather than leaving it to the next
             * anchor.
             *
             * ITS OWN PERMISSION, which is the reason it is a module at all
             * rather than a report type in the builder. The sheet holds
             * payroll, owner distributions and franchise royalties, by name
             * and by person. Somebody who measures jobs and calls should not
             * acquire that with the same grant, and an organization wanting
             * one without the other should not have to choose. Same shape as
             * Google Ads beside it, for the same reason. Jose's call,
             * 2026-09-17, asked before building.
             *
             * `view` alone. The figures live in somebody else's spreadsheet
             * and are read when the screen opens - EzBizi stores none of them,
             * so create, update and delete would offer three actions that
             * cannot exist.
             */
            new ModuleDefinition(
                key: 'profit_loss',
                label: 'Profit & Loss',
                parent: 'reports',
                actions: ['view'],
                automationExemptBecause: 'Nothing here is a record. The figures are read from '
                    .'the Airtable base a business connected, when somebody opens the screen, and are '
                    .'never stored, so no row is ever created, changed or removed for a trigger '
                    .'to fire on. This stops being true the day a week is cached or watched: '
                    .'an alert on a branch going into loss is a real trigger, and it needs this '
                    .'line removed rather than reworded.',
            ),

            /*
             * Field — GPS tracking of the people a role says to track, for
             * safety and compliance, and suggested routes for their jobs
             * (docs/superpowers/specs/2026-09-25-field-module-design.md).
             *
             * `tracked` is not something a person DOES, it is something done
             * to them: holding it is what puts a role's phones on the map. It
             * is a grant rather than a setting because "which roles" is
             * exactly the question the roles editor already answers, per role,
             * at every level of the organization.
             *
             * `manage` acknowledges alerts. Settings edits ride the settings
             * registry's own editPermission, which names it too.
             */
            new ModuleDefinition(
                key: 'field',
                label: 'Field',
                // record: makes safety recordings; listen: hears other people's.
                actions: ['view', 'tracked', 'manage', 'record', 'listen'],
                actionLabels: [
                    'record' => 'Make safety recordings',
                    'listen' => "Listen to other people's recordings",
                ],
                // Listening is only spent in the Recordings tab.
                actionRequires: ['listen' => ['field.recordings.view']],
                tabs: [
                    new TabDefinition('dashboard', 'Dashboard'),
                    new TabDefinition('map', 'Map'),
                    new TabDefinition('route', 'Route'),
                    new TabDefinition('recordings', 'Recordings'),
                    new TabDefinition('settings', 'Settings'),
                ],
                auditActionPrefixes: ['field'],
                emits: [Job::class, FieldAlert::class],
                triggers: [
                    new TriggerDefinition(
                        key: 'field.arrived_at_job',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Job::class],
                        label: 'Arrived at a job',
                        description: 'A tracked person came within the arrival distance of one of '
                            .'their jobs today. It is about the job, and carries who arrived.',
                    ),
                    new TriggerDefinition(
                        key: 'field.left_job',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\Job::class],
                        label: 'Left a job',
                        description: 'A tracked person who had arrived at a job moved away from it. '
                            .'It is about the job, and carries who left.',
                    ),
                    new TriggerDefinition(
                        key: 'field.stopped_too_long',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\FieldAlert::class],
                        label: 'Stopped too long',
                        description: 'A tracked person has not moved for the minutes set in Field › '
                            .'Settings, away from any of their jobs. Fires once until they move again.',
                    ),
                    new TriggerDefinition(
                        key: 'field.signal_lost',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\FieldAlert::class],
                        label: 'Lost signal',
                        description: 'A tracked person\'s phone has not reported for the minutes set in '
                            .'Field › Settings while they should be tracked. Fires once until it reports again.',
                    ),
                    new TriggerDefinition(
                        key: 'field.sos',
                        // What the event is about (builder audit, 2026-10-22).
                        subjects: [\App\Models\FieldAlert::class],
                        label: 'SOS pressed',
                        description: 'A tracked person pressed SOS. It carries where they were. '
                            .'Managers who can see them are told straight away, rule or no rule.',
                    ),
                ],
            ),
        ];
    }
}
