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
             * Two modules that are screens and nothing else.
             *
             * They were the last destinations compiled into the client. The
             * sidebar is now built from what this registry declares, so a screen
             * that is not a module simply does not exist to it — and Dashboard
             * and Import & export both disappeared, for everybody including the
             * Administrator, the first time that list was drawn from the server.
             *
             * Declaring them is the right answer rather than a workaround. The
             * owner's rule is that EVERY module is reached through a tab
             * permission the organization grants; a screen exempt from that
             * would be a second way in. It also makes them grantable, which is
             * the point: a technician has no business in Import & export, and
             * until now there was no way to say so.
             *
             * `view` only. Neither has an action beyond looking — the dashboard
             * composes what other modules already permit, and an import writes
             * through the permissions of the records it creates, not its own.
             * Dashboard is declared FIRST because the registry's order is the
             * sidebar's order, and it is the screen somebody opens the app to
             * look at.
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
             *   view    the tab — people, and the invitations in flight
             *   create  inviting somebody, and the whole life of that invitation:
             *           resending it and withdrawing it. Issuing and un-issuing
             *           are one authority, because the person who should be able
             *           to take an invitation back is the person who sent it.
             *   edit    changing what somebody's account is — their role, and
             *           whether it works at all
             *
             * There is no `delete`, and its absence is a decision rather than an
             * omission. §5: deactivate is not delete, and an employment record
             * survives deactivation — a person who leaves is switched off, not
             * erased, because everything they did stays attributed to them.
             * Revoking an invitation does not delete it either; it becomes
             * abandoned, and the row is how "we invited them and changed our
             * mind" stays answerable.
             */
            new ModuleDefinition(
                key:     'hr',
                label:   'Human Resources',
                actions: ['view', 'create', 'edit'],
                /*
                 * Outside automation as a DECLARER, not as a participant — the
                 * distinction matters, so it is spelt out.
                 *
                 * Everything HR does happens to a User: somebody is invited,
                 * their account becomes usable, their access is switched off.
                 * Those are `user.invited`, `user.activated` and
                 * `user.deactivated`, and this module is what finally raises all
                 * three. They are declared by the Users module because that is
                 * the module whose subject is the User record, and the field
                 * picker resolves a trigger's subject through the module that
                 * declares it — so declaring them a second time here would give
                 * the registry two answers for one key.
                 *
                 * The invitation itself raises nothing of its own. A rule on
                 * "invitation resent" would be a rule about an administrative
                 * gesture rather than about the business, and the three events
                 * above already cover every moment that changes what somebody
                 * can do.
                 */
                automationExemptBecause:
                    'Everything HR does happens to a user — invited, activated, deactivated — '
                    .'and those three events are declared by the Users module, whose subject is '
                    .'the User record. HR is what raises them.',
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


            new ModuleDefinition(
                key:     'data',
                label:   'Import & export',
                actions: ['view'],
                automationExemptBecause:
                    'Import and export act THROUGH other modules, and those raise '
                    .'their own events — an imported customer fires customer.created.',
            ),
        ];
    }
}
