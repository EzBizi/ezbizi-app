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
                // Nothing in user management reaches the outbox yet, so all
                // three say so rather than being quietly listed as if they work.
                triggers: [
                    new TriggerDefinition(
                        key:   'user.invited',
                        label: 'Invited',
                        description: 'Somebody was invited to join, before they have accepted.',
                        isRaised: false,
                    ),
                    new TriggerDefinition(
                        key:   'user.activated',
                        label: 'Activated',
                        description: 'An invited person accepted, and their account can now '
                            .'be used.',
                        isRaised: false,
                    ),
                    new TriggerDefinition(
                        key:   'user.deactivated',
                        label: 'Deactivated',
                        description: 'Someone’s access was switched off. The account stays, and '
                            .'so does everything it did.',
                        isRaised: false,
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
        ];
    }
}
