<?php

namespace App\Support\Settings;

use InvalidArgumentException;

/**
 * What a setting IS, as opposed to what it's currently set to (plan §6, review R-08).
 *
 * Definitions live in code — they're platform invariants (§2.1 layer 1). Values
 * live in the database and are tenant-editable (§2.1 layer 5). Conflating the two
 * is how a config system turns into an injection surface: if the *schema* is
 * editable data, nothing validates anything.
 *
 * `allowedScopes` is the load-bearing field. The review caught that "all three
 * levels expose the same options" contradicts the rest of the plan — reporting
 * currency is organization-only, currency is franchise-level. Worse, without it
 * a franchise administrator could weaken a security policy the organization set.
 * A setting simply isn't offered at a scope that shouldn't control it.
 */
final class SettingDefinition
{
    public const TYPE_STRING  = 'string';
    public const TYPE_BOOL    = 'bool';
    public const TYPE_INT     = 'int';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_ENUM    = 'enum';
    public const TYPE_JSON    = 'json';

    /**
     * ── Shaped types ─────────────────────────────────────────────────
     *
     * These are all JSON on the wire. They exist because `json` says only "some
     * structure", and that is not enough for either end to do its job.
     *
     * The server could not validate one: castJson accepted any array at all, so
     * `{"foo": 1}` was a perfectly acceptable set of business hours right up
     * until the code that reads them tried to open on Monday.
     *
     * And the client could not draw one. It fell back to a box of raw JSON —
     * asking somebody to hand-write `{"monday": {"open": "08:00"}}` to say the
     * shop opens at eight, and to get every brace right or lose the lot.
     *
     * Naming the shape fixes both ends at once: cast() below knows what to
     * check, and the client picks the editor from the type rather than from the
     * key. A new set of opening hours anywhere in the registry is one more
     * TYPE_HOURS and no client change at all.
     */
    public const TYPE_HOURS      = 'hours';
    public const TYPE_TIME_RANGE = 'time_range';
    public const TYPE_LIST       = 'list';

    /** In week order, which is the order they are shown in. */
    public const WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY      = 'company';
    public const SCOPE_FRANCHISE    = 'franchise';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $default,
        /** @var string[] scopes permitted to SET this. Order is irrelevant. */
        public readonly array $allowedScopes = [
            self::SCOPE_ORGANIZATION, self::SCOPE_COMPANY, self::SCOPE_FRANCHISE,
        ],
        /** @var array<int,string|int> permitted values when type is enum */
        public readonly array $options = [],
        /** Redacted in the audit log, and never returned to the API in clear. */
        public readonly bool $isSensitive = false,
        /** Permission required to change it. Null = organizations.edit. */
        public readonly ?string $editPermission = null,
        public readonly ?string $help = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_.]*$/', $key)) {
            throw new InvalidArgumentException("Setting key '{$key}' must be lower_snake_case.");
        }

        if ($allowedScopes === []) {
            throw new InvalidArgumentException(
                "Setting '{$key}' allows no scopes — nothing could ever set it."
            );
        }

        foreach ($allowedScopes as $scope) {
            if (! in_array($scope, [self::SCOPE_ORGANIZATION, self::SCOPE_COMPANY, self::SCOPE_FRANCHISE], true)) {
                throw new InvalidArgumentException("Setting '{$key}' names unknown scope '{$scope}'.");
            }
        }

        if ($type === self::TYPE_ENUM && $options === []) {
            throw new InvalidArgumentException("Enum setting '{$key}' lists no options.");
        }
    }

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->allowedScopes, strict: true);
    }

    public function permissionRequired(): string
    {
        return $this->editPermission ?? 'organizations.edit';
    }

    /** Coerce and validate. Throws rather than silently storing nonsense. */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            self::TYPE_BOOL    => (bool) $value,
            self::TYPE_INT     => $this->castInt($value),
            self::TYPE_DECIMAL => $this->castDecimal($value),
            self::TYPE_ENUM    => $this->castEnum($value),
            self::TYPE_STRING  => (string) $value,
            self::TYPE_JSON    => $this->castJson($value),
            self::TYPE_HOURS   => $this->castHours($value),
            self::TYPE_TIME_RANGE => $this->castTimeRange($value),
            self::TYPE_LIST    => $this->castList($value),
            default            => $value,
        };
    }

    /**
     * A week of opening times.
     *
     * Every weekday must be present and is either null — closed — or an open
     * and a close. Missing days are rejected rather than assumed closed: a
     * client that dropped Saturday by accident would otherwise quietly shut the
     * business on Saturdays.
     *
     * Close before open is allowed. A bar closing at 02:00 is not a typo, and
     * refusing it would be this validator deciding what trade the tenant is in.
     */
    private function castHours(mixed $value): array
    {
        $value = $this->castJson($value);
        $out = [];

        foreach (self::WEEKDAYS as $day) {
            if (! array_key_exists($day, $value)) {
                throw new InvalidArgumentException("Setting '{$this->key}' is missing {$day}.");
            }

            if ($value[$day] === null) {
                $out[$day] = null;
                continue;
            }

            if (! is_array($value[$day])) {
                throw new InvalidArgumentException(
                    "Setting '{$this->key}': {$day} must be null or an open and close time."
                );
            }

            $out[$day] = [
                'open'  => $this->time($value[$day]['open'] ?? null, "{$day} open"),
                'close' => $this->time($value[$day]['close'] ?? null, "{$day} close"),
            ];
        }

        return $out;
    }

    /**
     * One start-and-end pair, or neither.
     *
     * Both-or-neither, because half a range is a question nobody downstream can
     * answer — a quiet period that starts at ten and never ends is either
     * always on or never on depending on which side of the comparison you write
     * first.
     */
    private function castTimeRange(mixed $value): array
    {
        $value = $this->castJson($value);

        $start = $value['start'] ?? null;
        $end   = $value['end'] ?? null;

        if ($start === null && $end === null) {
            return ['start' => null, 'end' => null];
        }

        if ($start === null || $end === null) {
            throw new InvalidArgumentException(
                "Setting '{$this->key}' needs both a start and an end, or neither."
            );
        }

        return [
            'start' => $this->time($start, 'start'),
            'end'   => $this->time($end, 'end'),
        ];
    }

    /**
     * An ordered list of distinct, non-empty strings.
     *
     * Order is content, not presentation — job statuses are a pipeline, and the
     * sequence is what a board reads to lay out its columns. Duplicates are
     * refused because two identical entries make every later reference
     * ambiguous.
     */
    private function castList(mixed $value): array
    {
        $value = $this->castJson($value);

        if (! array_is_list($value)) {
            throw new InvalidArgumentException("Setting '{$this->key}' expects a list.");
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                throw new InvalidArgumentException("Setting '{$this->key}' expects a list of text.");
            }

            $item = trim((string) $item);

            if ($item === '') {
                throw new InvalidArgumentException("Setting '{$this->key}' has a blank entry.");
            }

            if (in_array($item, $out, strict: true)) {
                throw new InvalidArgumentException("Setting '{$this->key}' repeats '{$item}'.");
            }

            $out[] = $item;
        }

        if ($out === []) {
            throw new InvalidArgumentException("Setting '{$this->key}' cannot be empty.");
        }

        return $out;
    }

    /** 24-hour HH:MM. Stored as text because that is what it is — a wall clock. */
    private function time(mixed $value, string $what): string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException(
                "Setting '{$this->key}': {$what} must be a time like 08:30."
            );
        }

        return $value;
    }

    private function castInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Setting '{$this->key}' expects a number.");
        }

        return (int) $value;
    }

    /**
     * Money-adjacent values (tax rates) as float.
     *
     * Note this is a RATE, not an amount. Amounts are stored as integer minor
     * units elsewhere (§7) — a percentage is a different thing and float is
     * correct for it.
     */
    private function castDecimal(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Setting '{$this->key}' expects a number.");
        }

        return (float) $value;
    }

    private function castJson(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException("Setting '{$this->key}' expects valid JSON.");
            }

            return $decoded;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Setting '{$this->key}' expects an array or JSON string.");
        }

        return $value;
    }

    private function castEnum(mixed $value): mixed
    {
        if (! in_array($value, $this->options, strict: false)) {
            throw new InvalidArgumentException(sprintf(
                "Setting '%s' must be one of: %s.",
                $this->key,
                implode(', ', array_map(fn ($o) => var_export($o, true), $this->options))
            ));
        }

        return $value;
    }
}
