<?php

namespace App\Support\Tasks;

use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnOption;
use App\Models\TaskPriorityOption;
use App\Models\TaskStatusOption;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

final class TaskViewCodec
{
    private const CORE_TYPES = [
        'task' => 'text',
        'owner' => 'people',
        'status' => 'label',
        'priority' => 'label',
        'start_on' => 'date',
        'due_on' => 'date',
    ];

    private const OPERATORS = [
        'text' => ['equals', 'in', 'not_in', 'empty', 'not_empty', 'contains'],
        'number' => ['equals', 'in', 'not_in', 'empty', 'not_empty', 'between'],
        'checkbox' => ['equals', 'empty', 'not_empty'],
        'date' => ['before', 'on', 'after', 'between', 'empty', 'not_empty'],
        'people' => ['contains_any', 'contains_all', 'empty', 'not_empty'],
        'label' => ['contains_any', 'contains_all', 'in', 'not_in', 'empty', 'not_empty'],
        'link' => ['equals', 'in', 'not_in', 'empty', 'not_empty'],
        'file' => ['empty', 'not_empty'],
    ];

    private const SORTABLE_TYPES = ['text', 'number', 'checkbox', 'date', 'link'];

    public function __construct(private readonly TaskParticipantEligibility $participants) {}

    public function defaults(TaskBoard $board): array
    {
        return [
            'schema' => 1,
            'presentation' => 'table',
            'filters' => [],
            'sorts' => [],
            'hidden_columns' => [],
            // Task is the permanent pinned identity column. Saved layout state
            // only owns the scrollable operational columns beside it.
            'column_order' => array_merge(array_values(array_filter(
                array_keys(self::CORE_TYPES), fn (string $reference) => $reference !== 'task'
            )), $board->columnDefinitions()
                ->active()->orderBy('position')->pluck('id')->map(fn (string $id) => 'custom:'.$id)->all()),
            'column_widths' => (object) [],
            'group_by' => null,
            'search' => '',
        ];
    }

    public function decode(TaskBoard $board, mixed $input): array
    {
        if (! is_array($input)) {
            $this->invalid('configuration', 'A view configuration must be an object.');
        }
        $expected = ['schema', 'presentation', 'filters', 'sorts', 'hidden_columns', 'column_order', 'column_widths', 'group_by', 'search'];
        if (array_diff(array_keys($input), $expected) !== [] || array_diff($expected, array_keys($input)) !== []) {
            $this->invalid('configuration', 'A view configuration must contain exactly the versioned table fields.');
        }
        if ($input['schema'] !== 1 || $input['presentation'] !== 'table') {
            $this->invalid('configuration.schema', 'Only schema 1 table views are supported.');
        }
        if (! is_array($input['filters']) || ! array_is_list($input['filters']) || count($input['filters']) > 20) {
            $this->invalid('configuration.filters', 'Filters must be a list of at most 20 conditions.');
        }
        if (! is_array($input['sorts']) || ! array_is_list($input['sorts']) || count($input['sorts']) > 5) {
            $this->invalid('configuration.sorts', 'Sorts must be a list of at most five fields.');
        }

        $filters = [];
        foreach ($input['filters'] as $index => $filter) {
            $path = "configuration.filters.{$index}";
            if (! is_array($filter) || array_diff(array_keys($filter), ['field', 'operator', 'value']) !== []
                || ! isset($filter['field'], $filter['operator']) || ! is_string($filter['field']) || ! is_string($filter['operator'])) {
                $this->invalid($path, 'Each filter requires a field, operator, and operator-appropriate value.');
            }
            $field = $this->field($board, $filter['field'], $path.'.field');
            if (! in_array($filter['operator'], self::OPERATORS[$field['type']], true)) {
                $this->invalid($path.'.operator', 'That operator is not supported for this field type.');
            }
            $filters[] = [
                'field' => $filter['field'],
                'operator' => $filter['operator'],
                'value' => $this->normalizeValue($board, $field, $filter['operator'], $filter['value'] ?? null, $path.'.value'),
            ];
        }

        $sorts = [];
        foreach ($input['sorts'] as $index => $sort) {
            $path = "configuration.sorts.{$index}";
            if (! is_array($sort) || array_diff(array_keys($sort), ['field', 'direction']) !== []
                || ! isset($sort['field'], $sort['direction']) || ! is_string($sort['field'])
                || ! in_array($sort['direction'], ['asc', 'desc'], true)) {
                $this->invalid($path, 'Each sort requires an allowed field and asc or desc direction.');
            }
            $field = $this->field($board, $sort['field'], $path.'.field');
            if ($field['custom'] && ! in_array($field['type'], self::SORTABLE_TYPES, true)) {
                $this->invalid($path.'.field', 'People, Label, and File custom fields cannot be sorted.');
            }
            $sorts[] = ['field' => $sort['field'], 'direction' => $sort['direction']];
        }

        $hidden = array_values(array_filter(
            $this->columnList($board, $input['hidden_columns'], 'configuration.hidden_columns'),
            fn (string $reference) => $reference !== 'task',
        ));
        $order = array_values(array_filter(
            $this->columnList($board, $input['column_order'], 'configuration.column_order'),
            fn (string $reference) => $reference !== 'task',
        ));
        $widthInput = is_object($input['column_widths']) ? get_object_vars($input['column_widths']) : $input['column_widths'];
        if (! is_array($widthInput) || (array_is_list($widthInput) && $widthInput !== [])) {
            $this->invalid('configuration.column_widths', 'Column widths must be an object keyed by column.');
        }
        $widths = [];
        foreach ($widthInput as $reference => $width) {
            if (! is_string($reference)) {
                $this->invalid('configuration.column_widths', 'Each width must be keyed by a column reference.');
            }
            $this->field($board, $reference, 'configuration.column_widths.'.$reference);
            if (! is_int($width) || $width < 60 || $width > 800) {
                $this->invalid('configuration.column_widths.'.$reference, 'A column width must be between 60 and 800 pixels.');
            }
            if ($reference === 'task') {
                continue;
            }
            $widths[$reference] = $width;
        }
        if ($input['group_by'] !== null) {
            if (! is_string($input['group_by'])) {
                $this->invalid('configuration.group_by', 'Group by must be a column reference or null.');
            }
            $group = $this->field($board, $input['group_by'], 'configuration.group_by');
            if ($group['custom'] && in_array($group['type'], ['people', 'label', 'file'], true)) {
                $this->invalid('configuration.group_by', 'Multi-value and File fields cannot group a table.');
            }
        }
        $search = $input['search'] ?? '';
        if (! is_string($search) || strlen($search) > 191) {
            $this->invalid('configuration.search', 'Search must be at most 191 bytes.');
        }

        return [
            'schema' => 1,
            'presentation' => 'table',
            'filters' => $filters,
            'sorts' => $sorts,
            'hidden_columns' => $hidden,
            'column_order' => $order,
            'column_widths' => (object) $widths,
            'group_by' => $input['group_by'],
            'search' => trim($search),
        ];
    }

    /** @return array{reference:string,type:string,custom:bool,id:?string} */
    public function field(TaskBoard $board, string $reference, string $path = 'field'): array
    {
        if (isset(self::CORE_TYPES[$reference])) {
            return ['reference' => $reference, 'type' => self::CORE_TYPES[$reference], 'custom' => false, 'id' => null];
        }
        if (! preg_match('/^custom:([0-9a-f]{8}-[0-9a-f-]{27})$/i', $reference, $matches)) {
            $this->invalid($path, 'Unknown task column reference.');
        }
        $definition = TaskColumnDefinition::query()->where('board_id', $board->id)->active()->find($matches[1]);
        if ($definition === null) {
            $this->invalid($path, 'Unknown, archived, or foreign custom column reference.');
        }

        return ['reference' => $reference, 'type' => $definition->type->value, 'custom' => true, 'id' => $definition->id];
    }

    private function columnList(TaskBoard $board, mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100
            || collect($value)->contains(fn ($item) => ! is_string($item))
            || count(array_unique($value)) !== count($value)) {
            $this->invalid($path, 'Columns must be a unique list of at most 100 references.');
        }
        foreach ($value as $index => $reference) {
            $this->field($board, $reference, $path.'.'.$index);
        }

        return $value;
    }

    private function normalizeValue(TaskBoard $board, array $field, string $operator, mixed $value, string $path): mixed
    {
        if (in_array($operator, ['empty', 'not_empty'], true)) {
            if ($value !== null) {
                $this->invalid($path, 'Empty and not-empty filters require a null value.');
            }

            return null;
        }
        if (in_array($operator, ['in', 'not_in', 'contains_any', 'contains_all', 'between'], true)) {
            if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
                $this->invalid($path, 'This operator requires a non-empty value list.');
            }
            if ($operator === 'between' && count($value) !== 2) {
                $this->invalid($path, 'A between filter requires exactly two values.');
            }
            $normalized = array_map(fn ($item) => $this->normalizeScalar($field, $item, $path), $value);
            if (count(array_unique(array_map(fn ($item) => json_encode($item), $normalized))) !== count($normalized)) {
                $this->invalid($path, 'Filter values must be unique.');
            }
            $this->validateReferences($board, $field, $normalized, $path);

            return $normalized;
        }
        $normalized = $this->normalizeScalar($field, $value, $path);
        $this->validateReferences($board, $field, [$normalized], $path);

        return $normalized;
    }

    private function normalizeScalar(array $field, mixed $value, string $path): mixed
    {
        return match ($field['type']) {
            'text' => is_string($value) && strlen($value) <= 65535
                ? $value : $this->invalid($path, 'A Text filter requires a string of at most 65,535 bytes.'),
            'number' => $this->number($value, $path),
            'checkbox' => is_bool($value)
                ? $value : $this->invalid($path, 'A Checkbox filter requires true or false.'),
            'date' => $this->date($value, $path),
            'link' => $this->url($value, $path),
            'people', 'label', 'file' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$/i', $value)
                ? $value : $this->invalid($path, 'This filter requires valid record IDs.'),
        };
    }

    private function number(mixed $value, string $path): string
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            $this->invalid($path, 'A Number filter requires a finite JSON number.');
        }
        $normalized = number_format((float) $value, 6, '.', '');
        if (strlen(ltrim(strstr($normalized, '.', true), '-')) > 14) {
            $this->invalid($path, 'That Number is outside the supported range.');
        }

        return $normalized;
    }

    private function date(mixed $value, string $path): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid($path, 'A Date filter must use a real YYYY-MM-DD date.');
        }

        return $value;
    }

    private function url(mixed $value, string $path): string
    {
        $candidate = is_string($value) ? trim($value) : '';
        $parts = preg_match('/[\x00-\x20\x7f]/', $candidate) ? false : parse_url($candidate);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            $this->invalid($path, 'A Link filter must be an absolute HTTPS URL without credentials.');
        }
        $url = 'https://'.strtolower($parts['host']);
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $url .= ':'.(int) $parts['port'];
        }
        $url .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }
        if (strlen($url) > 2048) {
            $this->invalid($path, 'That Link URL is too long.');
        }

        return $url;
    }

    private function validateReferences(TaskBoard $board, array $field, array $values, string $path): void
    {
        if ($field['type'] === 'people') {
            $users = User::query()->whereIn('id', $values)->get();
            if ($users->count() !== count($values)
                || $users->contains(fn (User $user) => $this->participants->forTaskParticipation($user, $board)->denied())) {
                $this->invalid($path, 'One or more People values are unavailable for this board.');
            }

            return;
        }
        if ($field['type'] !== 'label') {
            return;
        }
        $count = match ($field['reference']) {
            'status' => TaskStatusOption::query()->where('board_id', $board->id)->active()->whereIn('id', $values)->count(),
            'priority' => TaskPriorityOption::query()->where('board_id', $board->id)->active()->whereIn('id', $values)->count(),
            default => TaskColumnOption::query()->where('board_id', $board->id)
                ->where('column_definition_id', $field['id'])->active()->whereIn('id', $values)->count(),
        };
        if ($count !== count($values)) {
            $this->invalid($path, 'One or more Label values are unknown, archived, or foreign to this field.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
