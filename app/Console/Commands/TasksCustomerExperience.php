<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Support\Audit\AuditLogger;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off (Jose, 2026-10-20, A-1 Chimney's company board): the "Complaint …"
 * and "Bad review …" tasks an automation files go into a "Customer
 * Experience" group, typed by a status column, with the client's name and
 * phone in their own columns. Idempotent: a second run changes nothing, and
 * a name or phone already in a column is never overwritten.
 *
 * Name and phone come from the task's linked record (a lead, a customer, or
 * a job's customer) when it has one, else from the title. The title is
 * never changed.
 */
class TasksCustomerExperience extends Command
{
    public const GROUP = 'Customer Experience';

    public const DEFAULT_TYPE_COLUMN = 'Description';

    public const PHONE_COLUMN = 'Phone number';

    public const NAME_COLUMN = 'Client name';

    /** option id => [label, colour, title prefix]. */
    public const KINDS = [
        'complaint' => ['Complaint', '#E5484D', 'complaint'],
        'bad_review' => ['Bad Review', '#F76B15', 'bad review'],
    ];

    /** A North American number as people write it: 555-123-4567, (555) 123 4567, +1 555.123.4567, 5551234567. */
    public const PHONE = '/(?<!\d)(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\d)/';

    protected $signature = 'ezbizi:tasks-customer-experience {board_id} {--dry-run} {--column-name='.self::DEFAULT_TYPE_COLUMN.'}';

    protected $description = 'Group the Complaint and Bad review tasks on one board under Customer Experience, typed, with client name and phone columns';

    public function handle(TaskWriter $writer, AuditLogger $audit, TenantContext $tenant): int
    {
        $board = TaskBoard::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($this->argument('board_id'));
        if ($board === null) {
            $this->error('No board with that id.');

            return self::FAILURE;
        }
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($board->organization_id);
        $dry = (bool) $this->option('dry-run');
        $typeName = trim((string) $this->option('column-name')) ?: self::DEFAULT_TYPE_COLUMN;

        return $tenant->run($org, function () use ($board, $writer, $audit, $dry, $typeName) {
            $counts = ['matched' => 0, 'moved' => 0, 'typed' => 0, 'phone filled' => 0, 'name filled' => 0];
            $unparsed = [];

            $run = function () use ($board, $writer, $audit, $typeName, &$counts, &$unparsed) {
                $setup = $this->ensureBoard($board, $audit, $typeName);

                $tasks = Task::query()->where('board_id', $board->id)->whereNull('parent_task_id')
                    ->where(fn ($q) => $q->where('title', 'like', 'complaint%')->orWhere('title', 'like', 'bad review%'))
                    ->with('values')->orderBy('created_at')->get();

                foreach ($tasks as $task) {
                    $kind = self::kindOf($task->title);
                    if ($kind === null) {
                        continue;
                    }
                    $counts['matched']++;
                    $have = $task->values->keyBy('column_id');
                    $input = [];
                    $values = [];

                    if ($task->group_id !== $setup['group']->id) {
                        $input['group_id'] = $setup['group']->id;
                        $counts['moved']++;
                    }
                    if ($have->get($setup['type']->id)?->value_text !== $kind) {
                        $values[$setup['type']->id] = $kind;
                        $counts['typed']++;
                    }

                    [$name, $phone] = $this->contactOf($task);
                    $nameNow = $have->get($setup['name']->id)?->value_text;
                    $phoneNow = $have->get($setup['phone']->id)?->value_text;
                    if (blank($nameNow) && filled($name)) {
                        $values[$setup['name']->id] = $name;
                        $counts['name filled']++;
                    }
                    if (blank($phoneNow) && filled($phone)) {
                        $values[$setup['phone']->id] = $phone;
                        $counts['phone filled']++;
                    }
                    if ((blank($nameNow) && blank($name)) || (blank($phoneNow) && blank($phone))) {
                        $unparsed[] = $task->id;
                    }

                    if ($values !== []) {
                        $input['values'] = $values;
                    }
                    if ($input !== []) {
                        $writer->update($task, $input, null);
                    }
                }
            };

            DB::beginTransaction();
            try {
                $run();
                $dry ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            $this->line(($dry ? '[dry run, nothing saved] ' : '').'Board '.$board->name.' ('.$board->id.')');
            foreach ($counts as $label => $n) {
                $this->line(str_pad($label.':', 14).$n);
            }
            $this->line(str_pad('unparsed:', 14).count($unparsed).($unparsed === [] ? '' : ' '.implode(' ', $unparsed)));

            return self::SUCCESS;
        });
    }

    /** "complaint" or "bad_review" for a title that starts with either (any case), else null. */
    public static function kindOf(string $title): ?string
    {
        $t = mb_strtolower(ltrim($title));
        foreach (self::KINDS as $id => [, , $prefix]) {
            if (str_starts_with($t, $prefix)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * [name, phone] from the title: whatever follows the prefix, the phone
     * being the one number in it and the name what remains, when that is
     * plainly a name (letters, spaces, . ' - &; no digits, no @). Either is
     * null when not certain.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function parseTitle(string $title): array
    {
        $kind = self::kindOf($title);
        if ($kind === null) {
            return [null, null];
        }
        $rest = mb_substr(ltrim($title), mb_strlen(self::KINDS[$kind][2]));

        $phone = null;
        if (preg_match_all(self::PHONE, $rest, $m) === 1) {
            $phone = trim($m[0][0]);
            $rest = str_replace($m[0][0], ' ', $rest);
        }

        // A name only after a separator: "Complaint: Ann Lee", never "Complaint about the crew".
        $separated = preg_match('/^\s*[:\-–—|]/u', $rest) === 1;
        $name = trim(preg_replace('/\s+/u', ' ', $rest) ?? '', " \t:-–—|,;/()[]");
        $plain = $separated && $name !== '' && mb_strlen($name) <= 80
            && preg_match("/^[\\p{L}][\\p{L}\\p{M} .'’&-]*$/u", $name) === 1;

        return [$plain ? $name : null, $phone];
    }

    /** @return array{0: ?string, 1: ?string} name and phone, the linked record's first, then the title's */
    private function contactOf(Task $task): array
    {
        $record = $task->aboutRecord();
        if ($record instanceof Job) {
            $record = Customer::query()->find($record->customer_id);
        }
        [$name, $phone] = match (true) {
            $record instanceof Lead => [$record->contact_name, $record->phone],
            $record instanceof Customer => [$record->name, $record->phone],
            default => [null, null],
        };
        [$titleName, $titlePhone] = self::parseTitle($task->title);

        return [filled($name) ? trim($name) : $titleName, filled($phone) ? trim($phone) : $titlePhone];
    }

    /**
     * The group, the three columns, About as "Summary" and Created shown.
     *
     * @return array{group: TaskGroup, type: TaskColumn, phone: TaskColumn, name: TaskColumn}
     */
    private function ensureBoard(TaskBoard $board, AuditLogger $audit, string $typeName): array
    {
        $find = fn ($rows, string $name) => $rows->first(fn ($r) => mb_strtolower(trim($r->name)) === mb_strtolower($name));

        $group = $find($board->groups()->get(), self::GROUP);
        if ($group === null) {
            $group = TaskGroup::query()->create([
                'organization_id' => $board->organization_id,
                'board_id' => $board->id,
                'name' => self::GROUP,
                'colour' => '#E5484D',
                'position' => (int) $board->groups()->max('position') + 1,
            ]);
            $audit->log('task_board.group_created', $group);
            $this->line('Created group '.self::GROUP.'.');
        }

        $column = function (string $name, string $type, ?array $settings) use ($board, $audit, $find): TaskColumn {
            $row = $find($board->columns()->get(), $name);
            if ($row === null) {
                $row = TaskColumn::query()->create([
                    'organization_id' => $board->organization_id,
                    'board_id' => $board->id,
                    'name' => $name,
                    'type' => $type,
                    'settings' => $settings,
                    'position' => (int) $board->columns()->max('position') + 1,
                ]);
                $audit->log('task_board.column_created', $row);
                $this->line("Created column {$name}.");
            } elseif ($row->type !== $type) {
                throw new \RuntimeException("The board already has a column called {$name}, of type {$row->type}, not {$type}.");
            }

            return $row;
        };

        $type = $column($typeName, 'status', ['options' => array_map(
            fn (string $id) => ['id' => $id, 'label' => self::KINDS[$id][0], 'colour' => self::KINDS[$id][1]],
            array_keys(self::KINDS),
        )]);
        // An existing column keeps its options and gains any that are missing.
        $options = $type->options();
        $missing = array_diff(array_keys(self::KINDS), array_column($options, 'id'));
        if ($missing !== []) {
            foreach ($missing as $id) {
                $options[] = ['id' => $id, 'label' => self::KINDS[$id][0], 'colour' => self::KINDS[$id][1]];
            }
            $type->settings = ['options' => $options] + ($type->settings ?? []);
            $type->save();
            $audit->log('task_board.column_updated', $type);
        }

        $name = $column(self::NAME_COLUMN, 'text', null);
        $phone = $column(self::PHONE_COLUMN, 'text', null);

        $want = ['about' => ['label' => 'Summary'], 'created' => ['visible' => true]];
        $resolved = $board->resolvedBuiltinColumns();
        if ($resolved['about']['label'] !== 'Summary' || $resolved['created']['visible'] !== true) {
            $board->builtin_columns = \App\Http\Controllers\Api\TaskBoardsController::mergeBuiltinColumns($board, $want);
            $board->save();
            $audit->log('task_board.updated', $board);
            $this->line('About is now Summary; Created is shown.');
        }

        return ['group' => $group, 'type' => $type, 'phone' => $phone, 'name' => $name];
    }
}
