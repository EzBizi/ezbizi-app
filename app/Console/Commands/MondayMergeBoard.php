<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Tasks\Monday\MondayBoardMerge;
use App\Support\Tasks\TaskBoards;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Fold an imported Monday branch board into its EzBizi branch board (Jose,
 * 2026-11-16: "like both should just be one"). See MondayBoardMerge.
 * --board is the Monday board id; the branch board is the franchise board of
 * the franchise the imported board sits on. --dry-run rolls back.
 */
class MondayMergeBoard extends Command
{
    protected $signature = 'ezbizi:monday-merge-board {--board=* : Monday board ids} {--org=} {--franchise= : "Company/Branch" for a board on no branch} {--as= : EzBizi email (rule versions, when the rule\'s maker is gone)} {--dry-run}';

    protected $description = 'Merge imported Monday branch boards into their EzBizi branch boards';

    public function handle(TenantContext $tenant, TaskBoards $boards, MondayBoardMerge $merge): int
    {
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($this->option('org'));
        $failed = false;
        $tenant->run($org, function () use ($boards, $merge, &$failed) {
            $by = User::query()->where('email', (string) $this->option('as'))->firstOrFail();
            foreach ((array) $this->option('board') as $mondayId) {
                $from = TaskBoard::query()->where('external_ref', 'monday:'.$mondayId)->first();
                // A board restored from the 11-03 run sits on no branch (NW): --franchise names it, "Company/Branch".
                $franchise = $from?->franchise_id !== null ? Franchise::query()->find($from->franchise_id) : $this->named();
                if ($from === null || $from->kind !== TaskBoard::KIND_CUSTOM || $franchise === null) {
                    $this->error("monday board {$mondayId}: no imported custom board on a branch answers for it".($from ? " ({$from->name} is {$from->kind}; name its branch with --franchise)" : ''));
                    $failed = true;

                    continue;
                }
                $into = $boards->ensureFor($franchise);
                $before = [$into->tasks()->withTrashed()->count(), $from->tasks()->withTrashed()->count()];
                try {
                    $r = $merge->merge($from, $into, $by, (bool) $this->option('dry-run'));
                } catch (\Throwable $e) {
                    $this->error("{$from->name}: ".$e->getMessage());
                    $failed = true;

                    continue;
                }
                $after = $this->option('dry-run') ? '(dry run)' : $into->tasks()->withTrashed()->count();
                $this->line("== {$from->name} -> {$into->name}: branch tasks {$before[0]} + monday {$before[1]} -> {$after}");
                $this->line('   '.json_encode(array_filter($r['counts'])));
                foreach ($r['conflicts'] as $c) {
                    $this->line('   conflict: '.$c);
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** The branch --franchise names ("A-1 Chimney/Nationwide"), or null. */
    private function named(): ?Franchise
    {
        $spec = (string) $this->option('franchise');
        if (! str_contains($spec, '/')) {
            return null;
        }
        [$company, $name] = array_map('trim', explode('/', $spec, 2));
        $companyId = \App\Models\Company::query()->get()->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower($company))?->id;
        $rows = Franchise::query()->where('company_id', $companyId)->get()->filter(fn ($f) => mb_strtolower($f->name) === mb_strtolower($name));

        return $rows->count() === 1 ? $rows->first() : null;
    }
}
