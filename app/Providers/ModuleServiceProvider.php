<?php

namespace App\Providers;

use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Where modules declare themselves (plan §5.5).
 *
 * Adding a module means adding it here — and the §5.5 standing rule means
 * declaring its workflow triggers and actions at the same time, or stating why
 * it has none. `ModuleRegistryTest` fails the build otherwise.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, function () {
            $registry = new ModuleRegistry;

            foreach ($this->foundationModules() as $module) {
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
                // No create/delete: an organization isn't created from inside
                // itself. Self-serve signup and staff creation both live in the
                // control plane (§4.2), outside tenant permissions.
                actions:         ['view', 'edit'],
                triggers:        ['organization.settings_changed'],
                workflowActions: ['organization.notify_administrators'],
            ),

            new ModuleDefinition(
                key:     'companies',
                label:   'Companies',
                triggers:        ['company.created', 'company.updated', 'company.deactivated'],
                workflowActions: ['company.deactivate'],
            ),

            new ModuleDefinition(
                key:     'franchises',
                label:   'Franchises',
                triggers:        ['franchise.created', 'franchise.updated', 'franchise.deactivated'],
                workflowActions: ['franchise.deactivate'],
            ),

            new ModuleDefinition(
                key:     'users',
                label:   'Users',
                triggers:        ['user.invited', 'user.activated', 'user.deactivated'],
                workflowActions: ['user.deactivate', 'user.assign_to_scope'],
            ),

            new ModuleDefinition(
                key:     'roles',
                label:   'Roles & permissions',
                // Deliberately outside automation: a workflow that could rewrite
                // permissions would be a privilege-escalation path — the automation
                // engine is configured by the same admins it would grant rights to.
                // Role changes stay a human act, and every one is audited (§2.2).
                automationExemptBecause:
                    'Automating permission changes would let a workflow escalate privileges.',
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
}
