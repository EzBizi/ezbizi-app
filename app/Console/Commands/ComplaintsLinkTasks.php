<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\Complaint;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Support\Tasks\ComplaintLink;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Jose 11-25 (CX, one source): joins every complaint's card to the branch tasks that are the same issue, and with
 * --archive joins then archives the A1 - Operation "Customer Experience" copies the three complaint automations made
 * (archived = trashed here, archived on Monday; restorable, never deleted), and switches those automations off.
 * Dump first (--dump=path).
 */
class ComplaintsLinkTasks extends Command
{
    protected $signature = 'ezbizi:complaints-link {--org= : organization id} {--archive : also archive the automation copies and switch their automations off} {--dump= : write the copies to this JSON file first} {--dry-run}';

    protected $description = 'Join complaints to their branch-board tasks (and archive the automation copies)';

    /** The automations that made the copies (desk 11-25, measured). */
    private const AUTOMATIONS = ['01a13259-0bd4-73a7-954d-90ef1b99ac4e', '01a13764-db21-7282-b156-1048f1e023a4', '01a1eb58-1ee9-70b0-8805-b6edcbeefe9a'];

    public function handle(TenantContext $tenant): int
    {
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($this->option('org'));
        $dry = (bool) $this->option('dry-run');

        return $tenant->run($org, function () use ($dry) {
            $linked = 0;
            $cards = 0;
            foreach (Complaint::query()->cursor() as $complaint) {
                $card = Task::query()->where('external_ref', 'complaint:'.$complaint->id)->first();
                if ($card === null) {
                    continue;
                }
                $cards++;
                $linked += $dry ? 0 : ComplaintLink::link($complaint, $card);
            }
            $this->line("complaint cards: $cards, branch tasks joined: $linked");
            if (! $this->option('archive')) {
                return self::SUCCESS;
            }

            $copies = Task::query()->whereIn('created_by_automation_id', self::AUTOMATIONS)
                ->whereIn('group_id', TaskGroup::query()->where('name', 'like', 'Customer Experience%')
                    ->whereIn('board_id', TaskBoard::query()->where('name', 'A1 - Operation')->select('id'))->select('id'))
                ->get();
            if ($path = $this->option('dump')) {
                file_put_contents($path, $copies->load('values')->toJson());
                $this->line("dumped {$copies->count()} to $path");
            }
            $joined = 0;
            $archived = 0;
            foreach ($copies as $copy) {
                $complaint = $copy->about_type === 'lead' ? Complaint::query()->where('source_type', 'lead')->where('source_id', $copy->about_id)->first() : null;
                $card = $complaint ? Task::query()->where('external_ref', 'complaint:'.$complaint->id)->first() : null;
                if (! $dry) {
                    if ($card !== null && ComplaintLink::join($copy, $card)) {
                        $joined++;
                    }
                    app(TaskWriter::class)->delete($copy, null);
                }
                $archived++;
            }
            $off = $dry ? 0 : Automation::query()->whereIn('id', self::AUTOMATIONS)->where('is_active', true)->update(['is_active' => false]);
            $this->line("copies: {$copies->count()}, joined to their complaint: $joined, archived: $archived, automations switched off: $off".($dry ? ' (dry run)' : ''));

            return self::SUCCESS;
        });
    }
}
