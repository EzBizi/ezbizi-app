<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Bring the permissions table in line with the module registry.
 *
 * Runs on every deploy. Idempotent — new modules add rows, existing ones are
 * left alone.
 *
 * It does NOT delete permissions that vanish from the registry, and that's
 * deliberate: a role somewhere may reference one, and silently dropping it would
 * quietly widen or narrow access with no trace. Removal is a migration written by
 * a human who has thought about the roles pointing at it.
 */
class SyncPermissions extends Command
{
    protected $signature = 'ezbizi:sync-permissions {--prune : report registry-orphaned permissions without deleting}';

    protected $description = 'Sync the permission catalogue from the module registry';

    public function handle(ModuleRegistry $registry): int
    {
        // Guard the §5.5 standing rule at the point of use, not just in tests —
        // a module reaching production without automation support is the exact
        // drift the rule exists to prevent.
        if ($violations = $registry->violatingAutomationRule()) {
            $this->error('These modules declare no automation triggers/actions and no exemption:');
            foreach ($violations as $key => $_) {
                $this->line("  - {$key}");
            }
            $this->line('See plan §5.5. Declare triggers/actions, or state automationExemptBecause.');

            return self::FAILURE;
        }

        $created = 0;

        foreach ($registry->all() as $module) {
            foreach ($module->actions as $action) {
                $key = Permission::key($module->key, $action);

                $permission = Permission::firstOrCreate(
                    ['key' => $key],
                    [
                        'module' => $module->key,
                        'action' => $action,
                        'label'  => $this->label($module, $action),
                    ]
                );

                if ($permission->wasRecentlyCreated) {
                    $created++;
                    $this->line("  + {$key}");
                }
            }
        }

        $total = Permission::count();
        $this->info("Permissions synced: {$created} new, {$total} total.");

        if ($this->option('prune')) {
            $known   = $registry->allPermissionKeys();
            $orphans = Permission::whereNotIn('key', $known)->pluck('key');

            if ($orphans->isNotEmpty()) {
                $this->warn('Not in the registry (NOT deleted — roles may reference these):');
                $orphans->each(fn ($k) => $this->line("  ? {$k}"));
            }
        }

        return self::SUCCESS;
    }

    private function label(ModuleDefinition $module, string $action): string
    {
        return ucfirst($action).' '.lcfirst($module->label);
    }
}
