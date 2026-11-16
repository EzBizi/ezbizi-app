<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\Organization;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Automations\AutomationSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monday's board automations as Task Board rules (Jose, 2026-11-13: "make
 * sure we aren't missing any features from Monday specially automations").
 * Reads the spec read from every board's Automate panel
 * (docs/agents/monday-automations-2026-11-13.json) and writes one ordinary
 * Automations rule per recipe, on the board the import made for that Monday
 * board: `board_id eq <board>` first, as the Task Board's ⚡ Automate panel
 * writes them, so each shows there and in Automations.
 *
 * On or off as on Monday. A recipe naming something that is not here (a
 * person with no account, a group Monday itself no longer has) is made OFF
 * with what is missing in its description. Once each: the rule's
 * description carries monday:<id>. Run after ezbizi:monday-import-boards.
 */
class MondayAutomations extends Command
{
    protected $signature = 'ezbizi:monday-automations {--spec= : the JSON spec} {--org= : organization id} {--as= : email the rules run as on a board with no owner} {--dry-run}';

    protected $description = 'Recreate Monday board automations as Task Board rules';

    /** @var array<string, string> monday email => EzBizi email */
    private array $people = [];

    public function handle(TenantContext $tenant): int
    {
        $spec = json_decode((string) file_get_contents((string) $this->option('spec')), true, flags: JSON_THROW_ON_ERROR);
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($this->option('org'));
        $counts = ['made on' => 0, 'made off' => 0, 'made off (something missing)' => 0, 'already there' => 0, 'board not imported' => 0];

        // monday email => EzBizi email, the people map the import used (on the Monday connection).
        $connection = \App\Models\SocialConnection::query()->withoutGlobalScopes()->where('organization_id', $org->id)
            ->where('provider', 'monday')->first();
        foreach ((array) ($connection?->setup['monday']['people'] ?? []) as $from => $to) {
            $this->people[mb_strtolower(trim((string) $from))] = mb_strtolower(trim((string) $to));
        }
        $tenant->run($org, function () use ($spec, &$counts) {
            DB::beginTransaction();
            try {
                foreach ($spec['automations'] as $a) {
                    $this->one($a, $counts);
                }
                $this->option('dry-run') ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        });
        foreach ($counts as $k => $v) {
            $this->line("{$k}: {$v}");
        }
        foreach ($spec['skipped'] ?? [] as $s) {
            $this->line("not recreated: monday {$s['monday_id']} on board {$s['board']} ({$s['why']})");
        }

        return self::SUCCESS;
    }

    private function one(array $a, array &$counts): void
    {
        $tag = 'monday:'.$a['monday_id'];
        if (Automation::query()->where('description', 'like', $tag.' %')->exists()) {
            $counts['already there']++;

            return;
        }
        $board = TaskBoard::query()->where('external_ref', 'monday:'.$a['board'])->first();
        if ($board === null) {
            $counts['board not imported']++;
            $this->line("board not imported: {$a['board']} ({$a['sentence']})");

            return;
        }
        $missing = [];
        $group = function (?string $name) use ($board, &$missing): ?string {
            $id = $name === null ? null : TaskGroup::query()->where('board_id', $board->id)->get()->first(fn ($g) => mb_strtolower($g->name) === mb_strtolower($name))?->id;
            if ($id === null) {
                $missing[] = 'group '.($name ?? '(gone on Monday)');
            }

            return $id;
        };
        $column = function (?string $name) use ($board, &$missing): ?string {
            if ($name === 'due') {
                return 'due';
            }
            $id = $name === null ? null : TaskColumn::query()->where('board_id', $board->id)->get()->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower($name))?->id;
            if ($id === null) {
                $missing[] = 'column '.($name ?? '(gone on Monday)');
            }

            return $id;
        };
        // The person behind a Monday email, as the import finds them: the same email, the
        // connection's people map, then a name that names one active person only (Monday's
        // "Jose Demasi" is "Jose Luis Demasi"; baro@ is Bar Ohayon at bar@ezbizi.com).
        $person = function (?string $email, ?string $name = null) use (&$missing): ?string {
            $active = User::query()->where('is_active', true);
            $key = mb_strtolower(trim((string) $email));
            $id = $key === '' ? null : ((clone $active)->where('email', $key)->value('id')
                ?? (isset($this->people[$key]) ? (clone $active)->where('email', $this->people[$key])->value('id') : null));
            if ($id === null && trim((string) $name) !== '') {
                $words = fn (string $n) => array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($n))) ?: []));
                $want = $words((string) $name);
                $same = (clone $active)->get(['id', 'name'])->filter(fn ($u) => $words((string) $u->name) === $want);
                if ($same->isEmpty() && count($want) > 1) {
                    $same = (clone $active)->get(['id', 'name'])->filter(fn ($u) => array_diff($want, $words((string) $u->name)) === []);
                }
                $id = $same->count() === 1 ? $same->first()->id : null;
            }
            if ($id === null) {
                $missing[] = 'person '.($name ? $name.' ' : '').'('.($email ?: '?').')';
            }

            return $id;
        };

        $t = $a['trigger'] ?? null;
        if (! is_array($t) || ! isset($t['kind'])) {
            $this->line("not recreated (no trigger read): {$a['monday_id']} on board {$a['board']} ({$a['sentence']})");

            return;
        }
        $all = [['field' => 'board_id', 'op' => 'eq', 'value' => $board->id]];
        $delay = null;
        $schedule = null;
        $trigger = match ($t['kind']) {
            'created' => 'task.created',
            'status_changed' => 'task.status_changed',
            'column_changed' => 'task.column_changed',
            'date_arrived' => 'task.date_arrived',
            'schedule' => 'automation.schedule_reached',
        };
        if ($t['kind'] === 'status_changed') {
            $status = TaskStatus::query()->where('board_id', $board->id)->get()->first(fn ($s) => mb_strtolower($s->name) === mb_strtolower((string) $t['to']));
            $status === null ? $missing[] = 'status '.$t['to'] : $all[] = ['field' => 'status_id', 'op' => 'eq', 'value' => $status->id];
        } elseif ($t['kind'] === 'column_changed') {
            $all[] = ['field' => 'column_id', 'op' => 'eq', 'value' => $column($t['column'])];
            // "When Area changes to X", or Monday's bare "when column changes".
            $all[] = ($t['to'] ?? null) === null
                ? ['field' => 'value', 'op' => 'changed']
                : ['field' => 'value', 'op' => 'changed_to', 'value' => $t['to']];
        } elseif ($t['kind'] === 'date_arrived') {
            $all[] = ['field' => 'column_id', 'op' => 'eq', 'value' => $column($t['column'])];
            $all[] = ['field' => 'days_until', 'op' => 'eq', 'value' => (int) ($t['days_before'] ?? 0)];
            $delay = (int) round(((float) ($t['hour'] ?? 0)) * 60); // Monday's hour of the day (ET)
        } elseif ($t['kind'] === 'schedule') {
            $all = []; // a clock has no task; the board is in the rule's name and place
            $schedule = ['kind' => $t['every'] === 'daily' ? 'daily' : 'weekly', 'time' => $t['time'], 'timezone' => 'America/New_York']
                + ($t['every'] === 'daily' ? [] : ['days' => $t['days'] ?: ['monday']]);
        }
        foreach ($a['conditions'] as $c) {
            if (array_key_exists('group', $c)) {
                $all[] = ['field' => 'group_id', 'op' => 'eq', 'value' => $group($c['group'])];
            } elseif (isset($c['status'])) {
                $all[] = ['field' => 'status', 'op' => 'eq', 'value' => $c['status']];
            } elseif (isset($c['column'])) {
                $missing[] = 'a condition on '.$c['column'].' is '.$c['is'].' (rules read the task\'s status and group, not other columns)';
            }
        }

        $actions = [];
        foreach ($a['actions'] as $x) {
            if (array_key_exists('move_group', $x)) {
                $actions[] = ['type' => 'task.move_group', 'group_id' => $group($x['move_group'])];
            } elseif (isset($x['assign'])) {
                $actions[] = $x['assign'] === 'creator'
                    ? ['type' => 'task.assign', 'user_field' => 'created_by_user_id']
                    : ['type' => 'task.assign', 'user_field' => 'user:'.$person($x['assign'], $x['name'] ?? null), 'add' => (bool) $x['add']];
            } elseif (isset($x['notify'])) {
                $actions[] = $x['notify'] === 'assignees'
                    ? ['type' => 'notify', 'message' => $x['message'], 'to_assignee' => true]
                    : ['type' => 'notify', 'message' => $x['message'], 'user_ids' => array_values(array_filter([$person($x['notify'], $x['name'] ?? null)]))];
            } elseif (array_key_exists('shift', $x)) {
                $actions[] = ['type' => 'task.shift_date', 'column_id' => $column($x['shift']), 'amount' => $x['amount'], 'unit' => $x['unit']];
            } elseif (array_key_exists('set', $x)) {
                $actions[] = ['type' => 'task.set_value', 'column_id' => $column($x['set']), 'value' => $x['value']];
            } elseif (isset($x['create_on'])) {
                $target = TaskBoard::query()->where('external_ref', 'monday:'.$x['create_on'])->first();
                $owner = $target?->owner_user_id;
                if ($owner === null) {
                    $missing[] = 'board '.$x['create_on'];
                }
                // On that person's own board, in Monday's group; the connect-boards link has no Task Board column (audit §2).
                $actions[] = ['type' => 'task.create', 'title' => '{{title}}', 'assignee' => 'user:'.$owner, 'group' => (string) $x['group']];
            }
        }

        // Runs as the board's owner (a personal board) or its maker, else whoever --as names.
        $creator = $board->owner_user_id ?? $board->created_by_user_id
            ?? User::query()->where('email', (string) $this->option('as'))->value('id')
            ?? throw new \RuntimeException("Board {$board->name} has no owner: name who the rules run as with --as=<email>.");
        [$scopeType, $scopeId] = $board->franchise_id !== null ? ['franchise', $board->franchise_id]
            : ($board->company_id !== null ? ['company', $board->company_id] : [null, null]);
        if (collect($a['actions'])->contains(fn ($x) => isset($x['create_on']))) {
            [$scopeType, $scopeId] = [null, null]; // so the new task lands on the named person's board
        }
        $active = (bool) $a['active'] && $missing === [];
        $rule = Automation::query()->create([
            'name' => mb_substr($board->name.': '.$a['sentence'], 0, 255),
            'description' => $tag.' (Monday, '.$a['monday_state'].')'.($missing ? ' — off: not here: '.implode('; ', array_unique($missing)) : ''),
            'trigger_key' => $trigger, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'is_active' => $active, 'created_by' => $creator, 'delay_minutes' => $delay ?: null,
            'schedule' => $schedule, 'next_run_at' => $schedule ? (new AutomationSchedule($schedule))->nextAfter(now()) : null,
        ]);
        $rule->addVersion($all === [] ? null : ['all' => $all], $actions, User::query()->findOrFail($creator));
        $counts[$active ? 'made on' : ($a['active'] ? 'made off (something missing)' : 'made off')]++;
        if ($missing !== [] && $a['active']) {
            $this->line("off, not here: {$board->name}: {$a['sentence']} — ".implode('; ', array_unique($missing)));
        }
    }
}
