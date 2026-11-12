<?php

namespace App\Support\Tasks;

use App\Http\Controllers\Api\TasksController;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A column's values, carried to another type (Jose, 2026-11-09: "changing a
 * column's type converts existing values where possible and warns before
 * losing any"). Each value goes through its words: the old value as a person
 * would read it, then read again as the new type through TaskWriter::encode,
 * the same rule a typed value meets. A label that is not yet an option of a
 * status, dropdown or tags column becomes one.
 */
class ColumnConverter
{
    public function __construct(private readonly TaskWriter $writer) {}

    /**
     * The new rows by value id (null: lost), and the options the column
     * needs for them. Writes nothing.
     *
     * @return array{rows: array<string, ?array<string, mixed>>, options: list<array{id: string, label: string, colour: ?string}>}
     */
    public function plan(TaskColumn $old, TaskColumn $new): array
    {
        $options = $new->options();
        $assignable = null;
        $rows = [];

        foreach ($old->values()->with(['column', 'task' => fn ($q) => $q->withTrashed()->select('id', 'sensitive')])->get() as $v) {
            $sensitive = (bool) $v->task?->sensitive;
            // A sensitive task's words never become a column option everyone reads: that value is lost.
            if ($sensitive && ! SensitiveTasks::sealsColumn($new->type)) {
                $rows[$v->id] = null;

                continue;
            }
            $words = $this->words($old, $v);
            $value = $words === null ? null : $this->read($new, $words, $options);
            $probe = $new->replicate();
            $probe->settings = ['options' => $options] + ($new->settings ?? []);
            $row = $value === null ? null : $this->writer->encode($probe, $value, $old->board, $assignable)[0];
            if ($row !== null && $sensitive) {
                $row['value_text'] = SensitiveTasks::seal($row['value_text']);
            }
            $rows[$v->id] = $row;
        }

        return ['rows' => $rows, 'options' => $options];
    }

    /** The value as a person reads it, or null when there is nothing to carry. */
    public static function words(TaskColumn $column, TaskColumnValue $v): ?string
    {
        $value = TasksController::valueOf($v);
        $label = fn (string $id) => collect($column->options())->firstWhere('id', $id)['label'] ?? null;

        $words = match ($column->type) {
            'status', 'dropdown' => is_string($value) ? $label($value) : null,
            'tags' => implode(', ', array_filter(array_map($label, (array) $value))),
            'person' => User::query()->find($value)?->name,
            'checkbox' => $value ? 'Yes' : 'No',
            'timeline' => is_array($value) ? $value['from'] : null,
            'link' => is_array($value) ? $value['label'] : null,
            'number', 'currency' => $value === null ? null : rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.'),
            default => is_scalar($value) ? (string) $value : null,
        };

        return $words === null || trim($words) === '' ? null : trim($words);
    }

    /**
     * Words read as [column]'s type: a value encode() can judge, or null.
     *
     * @param  list<array{id: string, label: string, colour: ?string}>  $options  grows with new labels
     */
    private function read(TaskColumn $column, string $words, array &$options): mixed
    {
        $option = function (string $label) use (&$options): string {
            foreach ($options as $o) {
                if (mb_strtolower($o['label']) === mb_strtolower($label)) {
                    return $o['id'];
                }
            }
            $options[] = $o = ['id' => Str::lower(Str::random(10)), 'label' => mb_substr($label, 0, 100), 'colour' => null];

            return $o['id'];
        };
        $time = strtotime($words);

        return match ($column->type) {
            'status', 'dropdown' => $option($words),
            'tags' => array_values(array_unique(array_map($option, array_filter(array_map('trim', explode(',', $words)))))),
            'number', 'currency' => is_numeric($n = preg_replace('/[$,\s]/', '', $words)) ? $n : null,
            'checkbox' => match (mb_strtolower($words)) {
                'yes', 'true', '1', 'x', 'done' => true,
                'no', 'false', '0' => false,
                default => null,
            },
            'date' => $time === false ? null : date('Y-m-d', $time),
            'datetime' => $time === false ? null : date('Y-m-d H:i', $time),
            'timeline' => $time === false ? null : ['from' => date('Y-m-d', $time), 'to' => date('Y-m-d', $time)],
            'person' => User::query()->where('name', $words)->value('id'),
            'formula', 'link' => null,
            default => $words,
        };
    }
}
