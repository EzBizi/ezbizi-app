<?php

namespace App\Support\Settings\Lists;

use InvalidArgumentException;

/**
 * What one business list is: its name on screen, the columns each row holds,
 * who may edit it, and the rows it holds until a business edits it.
 *
 * `defaults` is where a code constant moves to when its module puts it on the
 * list store: the business keeps exactly today's behaviour until somebody
 * edits the list, and from then on its own rows are the answer.
 */
final class ListDefinition
{
    /**
     * @param  list<array{key: string, label: string, type?: string}>  $columns  `type` is text (default), number or bool
     * @param  list<array{key: string, value: array<string, mixed>}>  $defaults
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly array $columns,
        public readonly array $defaults = [],
        public readonly ?string $help = null,
        public readonly string $editPermission = 'organizations.edit',
    ) {
        if (! preg_match('/^[a-z][a-z0-9_.]*$/', $key)) {
            throw new InvalidArgumentException("List key '{$key}' must be lower_snake_case.");
        }

        if ($columns === []) {
            throw new InvalidArgumentException("List '{$key}' declares no columns, so a row could hold nothing.");
        }
    }

    /** The row value, checked against the columns. Unknown columns are refused. */
    public function cast(array $value): array
    {
        $out = [];
        $columns = collect($this->columns)->keyBy('key');

        foreach ($value as $column => $cell) {
            $declared = $columns->get($column);

            if ($declared === null) {
                throw new InvalidArgumentException("List '{$this->key}' has no column '{$column}'.");
            }

            $out[$column] = match ($declared['type'] ?? 'text') {
                'number' => is_numeric($cell) ? $cell + 0 : throw new InvalidArgumentException("'{$declared['label']}' must be a number."),
                'bool'   => (bool) $cell,
                default  => $cell === null ? null : trim((string) $cell),
            };
        }

        return $out;
    }
}
