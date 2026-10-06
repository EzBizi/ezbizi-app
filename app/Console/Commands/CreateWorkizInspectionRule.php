<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Switch on "a finished inspection goes up to its Workiz job" for an
 * organization: one active rule on `inspection.submitted` whose action is
 * `inspection_report.workiz`, running as the user named. Jose asked for it for
 * A1-Group (via Inspection, 2026-10-06). An organization that already has the
 * rule gets nothing new.
 */
class CreateWorkizInspectionRule extends Command
{
    protected $signature = 'ezbizi:workiz-inspection-rule
        {organization : The organization\'s id}
        {by : The id of the user the rule runs as, in that organization}';

    protected $description = 'Create the rule that puts each finished inspection report on its Workiz job';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($this->argument('organization'));
        $by = User::query()->withoutGlobalScopes()->find($this->argument('by'));

        if ($organization === null || $by === null || $by->organization_id !== $organization->getKey()) {
            $this->error('No such organization, or that user is not in it.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($by, $audit, $organization) {
            $has = Automation::query()->where('trigger_key', 'inspection.submitted')->with('activeVersion')->get()
                ->contains(fn (Automation $rule) => collect($rule->activeVersion?->actions ?? [])->contains('type', 'inspection_report.workiz'));

            if ($has) {
                $this->info($organization->name.' already has the rule.');

                return self::SUCCESS;
            }

            $rule = Automation::create([
                'name' => 'Inspection report to Workiz',
                'description' => 'Puts a link to each finished inspection report on its Workiz job.',
                'trigger_key' => 'inspection.submitted',
                'is_active' => true,
                'created_by' => $by->getKey(),
            ]);
            $rule->addVersion(null, [['type' => 'inspection_report.workiz']], $by);
            $audit->log('automation.created', $rule, metadata: ['by_command' => $this->getName()]);

            $this->info($organization->name.': rule created and switched on.');

            return self::SUCCESS;
        });
    }
}
