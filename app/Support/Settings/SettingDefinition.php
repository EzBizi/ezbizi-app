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
    public const TYPE_STRING   = 'string';
    public const TYPE_BOOL     = 'bool';
    public const TYPE_INT      = 'int';
    public const TYPE_DECIMAL  = 'decimal';
    public const TYPE_ENUM     = 'enum';
    public const TYPE_JSON     = 'json';
    public const TYPE_DURATION = 'duration';

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

    /**
     * Some of another setting's list, e.g. which job statuses mean "earned".
     *
     * The choices are not declared here: they are whatever {@see $choicesFrom}
     * resolves to at the scope being edited, so a status the organization adds
     * tomorrow is offered with no edit. Stored as a list of the chosen values
     * and may be empty — "none chosen" is a real answer, unlike TYPE_LIST.
     */
    public const TYPE_SUBSET     = 'subset';

    /**
     * One of this organization's companies, by id; null when none is chosen.
     *
     * The choices are the organization's companies, sent as `options` and
     * drawn as the enum dropdown. Not validated against them in cast(): a
     * company can be deleted after it is chosen, so the reader treats an id it
     * cannot find as unset.
     */
    public const TYPE_COMPANY    = 'company';

    /**
     * Codes the business chooses, each pointing at one of its places — e.g. a
     * phone line's market code to the branch that takes it. Stored as
     * `{code: franchise_id}`; the places are sent as `options`. Like
     * TYPE_COMPANY, an id is not checked here: the reader treats one it
     * cannot find as unset.
     */
    public const TYPE_FRANCHISE_MAP = 'franchise_map';

    /**
     * A place, stored as "lat, lng" exactly as TYPE_STRING was, so nothing
     * that reads one changes. Its own type so the settings screen can draw an
     * address search and a map for it (UI queue 104, design 7) — the editor
     * switches on the type, never the key.
     */
    public const TYPE_LOCATION   = 'location';

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
        /** @var array<int, string|int> permitted values when type is enum */
        public readonly array $options = [],
        /**
         * What a person reads for each option, keyed by the option as a string.
         *
         * Absent keys fall back to the value itself. The settings screen used
         * to print `en` and `phone_or_email` because that is what travelled
         * on the wire; the label is what belongs in a selector.
         *
         * @var array<string, string>
         */
        public readonly array $optionLabels = [],
        /** Redacted in the audit log, and never returned to the API in clear. */
        public readonly bool $isSensitive = false,
        /** Permission required to change it. Null = organizations.edit. */
        public readonly ?string $editPermission = null,
        public readonly ?string $help = null,

        /**
         * Nothing reads this yet, and this sentence says when something will.
         *
         * Null means the setting is in effect. Anything else is shown to the
         * administrator on the settings screen, so it is written for them and
         * not for us: "Takes effect when invoicing ships", not "no reader".
         *
         * ── Why a sentence and not a flag ────────────────────────
         *
         * Because the administrator's next question is always "then when?", and
         * a boolean cannot answer it. A screen that says only "not active"
         * leaves somebody to guess whether the feature is coming, broken, or
         * theirs to switch on somewhere else — and guessing wrong about a
         * security control is the expensive kind of wrong.
         *
         * The other reason is that a reason is auditable and a flag is not.
         * `true` says nothing about whether it is still true; a sentence naming
         * what it waits for can be checked against whether that thing shipped.
         *
         * ── What it is not ──────────────────────────────────────
         *
         * Not a licence to leave a setting unwired. {@see SettingReaders} reads
         * the codebase and the test suite fails both ways: a setting in effect
         * with no reader, and a setting saying "not yet" that something already
         * reads. Declaring this is the honest option, never the cheap one.
         */
        public readonly ?string $notYetInEffect = null,

        /**
         * The screen that actually authors this value, when it is not the
         * Settings screen.
         *
         * Null — every setting but one — means the Settings screen draws the
         * control and is where somebody changes it. A string names the screen
         * that owns the value instead, and {@see \App\Http\Controllers\Api\SettingsController::index()}
         * then leaves the row out of the Settings screen's payload altogether.
         *
         * ── Why a row can need leaving out ───────────────────────
         *
         * `organization.structure_canvas` is box coordinates and the direction
         * the chart flows in, authored by dragging boxes and pressing a
         * two-way control on the Organization tab. The Settings screen has no
         * shape to draw for a plain `json` value, so it falls back to a box of
         * raw JSON. Handing somebody five hundred `{kind,id,x,y}` objects to
         * hand-edit is a control whose every use is a mistake: the picture it
         * describes has its own screen, and that screen has a button that
         * rebuilds the whole thing correctly in one press. It would also ship
         * ~30KB of coordinates on every settings-screen load, at every scope,
         * for a value nobody reads there — the weight {@see DisplaySettings}'
         * docblock already argues about for a much smaller payload.
         *
         * ── What it does NOT do ─────────────────────────────────
         *
         * It is not a permission and it is not a hiding place. PATCH and DELETE
         * on /settings/{key} still work, still check the same permission and
         * still audit; the value is still resolved, still cascades, and still
         * appears in the setting's own tests. Only the FORM is elsewhere. A
         * definition that wanted to be unreachable would be a definition that
         * should not exist.
         *
         * Named as a place rather than as a boolean for the reason
         * `notYetInEffect` gives: a flag says "not here" and leaves the next
         * question unanswered, and the next question is always "then where?".
         */
        public readonly ?string $editedOn = null,

        /** TYPE_SUBSET only: the TYPE_LIST setting whose values are the choices. */
        public readonly ?string $choicesFrom = null,

        /**
         * Who may SEE this row on the Settings screen, when not everybody who
         * opens Settings should (2026-10-19, Leads' call routing). Null means
         * the usual rule. A write is refused the same way, on top of
         * $editPermission.
         */
        public readonly ?string $readPermission = null,
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

        if ($type === self::TYPE_SUBSET && ($choicesFrom === null || trim($choicesFrom) === '')) {
            throw new InvalidArgumentException("Subset setting '{$key}' names no setting to choose from.");
        }

        if ($type === self::TYPE_ENUM && $options === []) {
            throw new InvalidArgumentException("Enum setting '{$key}' lists no options.");
        }

        // `?string` without strict_types would turn `notYetInEffect: true` into
        // the string "1" and print it on the settings screen. Requiring more
        // than one word rejects that, and rejects "pending" and "TODO" with it.
        if ($notYetInEffect !== null && ! str_contains(trim($notYetInEffect), ' ')) {
            throw new InvalidArgumentException(
                "Setting '{$key}' says it is not yet in effect but gives no reason an "
                ."administrator could read. This text appears on the settings screen — it has "
                ."to say what the setting is waiting for, e.g. 'Takes effect when invoicing "
                ."ships.' A flag would only move the silence up one level."
            );
        }

        // No "more than one word" requirement here, unlike the guard above: a
        // screen can legitimately be called one word. The empty string is what
        // is refused, because `editedOn: ''` is a definition claiming to send
        // somebody somewhere and naming nowhere — which is the hiding place the
        // field's docblock says this must never become.
        if ($editedOn !== null && trim($editedOn) === '') {
            throw new InvalidArgumentException(
                "Setting '{$key}' says it is edited on another screen but does not name "
                .'one. The name is what makes this a redirection rather than a hiding place.'
            );
        }
    }

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->allowedScopes, strict: true);
    }

    /** Does anything act on this setting today? */
    public function isInEffect(): bool
    {
        return $this->notYetInEffect === null;
    }

    /**
     * Does the Settings screen draw this one?
     *
     * Asked by index() and by nothing else. Deliberately phrased as a question
     * about the SCREEN rather than as `isHidden()`: what the answer decides is
     * which form somebody edits the value on, not whether they may.
     */
    public function isEditedOnSettingsScreen(): bool
    {
        return $this->editedOn === null;
    }

    public function permissionRequired(): string
    {
        return $this->editPermission ?? 'organizations.edit';
    }

    /** The words a selector prints for one permitted value. */
    public function labelFor(mixed $option): string
    {
        return $this->optionLabels[(string) $option] ?? (string) $option;
    }

    /** Coerce and validate. Throws rather than silently storing nonsense. */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            self::TYPE_BOOL    => (bool) $value,
            self::TYPE_INT     => $this->castInt($value),
            self::TYPE_DURATION => $this->castInt($value),
            self::TYPE_DECIMAL => $this->castDecimal($value),
            self::TYPE_ENUM    => $this->castEnum($value),
            self::TYPE_STRING, self::TYPE_LOCATION => (string) $value,
            self::TYPE_JSON    => $this->castJson($value),
            self::TYPE_HOURS   => $this->castHours($value),
            self::TYPE_TIME_RANGE => $this->castTimeRange($value),
            self::TYPE_LIST    => $this->castList($value),
            self::TYPE_SUBSET  => $this->castSubset($value),
            self::TYPE_FRANCHISE_MAP => $this->castFranchiseMap($value),
            self::TYPE_COMPANY => ($value === null || trim((string) $value) === '') ? null : trim((string) $value),
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
     * An ordered list of distinct, non-empty entries.
     *
     * Order is content, not presentation — job statuses are a pipeline, and the
     * sequence is what a board reads to lay out its columns. Duplicates are
     * refused because two identical entries make every later reference
     * ambiguous.
     *
     * A string is still a string: older clients and tests send `["scheduled"]`.
     * An object `{value, label}` is a named status — the value is what jobs
     * store, the label is what people see. `was` is a write-only hint for
     * renaming existing jobs and is not stored.
     */
    private function castList(mixed $value): array
    {
        $value = $this->castJson($value);

        if (! array_is_list($value)) {
            throw new InvalidArgumentException("Setting '{$this->key}' expects a list.");
        }

        $out = [];
        $seen = [];

        foreach ($value as $item) {
            [$entryValue, $entryLabel, $shaped] = $this->listEntry($item);

            if (isset($seen[$entryValue])) {
                throw new InvalidArgumentException("Setting '{$this->key}' repeats '{$entryValue}'.");
            }

            $seen[$entryValue] = true;
            $out[] = $shaped ? ['value' => $entryValue, 'label' => $entryLabel] : $entryValue;
        }

        if ($out === []) {
            throw new InvalidArgumentException("Setting '{$this->key}' cannot be empty.");
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string, 2: bool} value, label, stored-as-object
     */
    private function listEntry(mixed $item): array
    {
        if (is_array($item)) {
            if (array_is_list($item)) {
                throw new InvalidArgumentException("Setting '{$this->key}' expects a list of text.");
            }

            $value = trim((string) ($item['value'] ?? ''));
            $label = trim((string) ($item['label'] ?? $value));

            if ($value === '' && $label !== '') {
                $value = $label;
            }

            if ($value === '' || $label === '') {
                throw new InvalidArgumentException("Setting '{$this->key}' has a blank entry.");
            }

            return [$value, $label, true];
        }

        if (! is_string($item) && ! is_numeric($item)) {
            throw new InvalidArgumentException("Setting '{$this->key}' expects a list of text.");
        }

        $value = trim((string) $item);

        if ($value === '') {
            throw new InvalidArgumentException("Setting '{$this->key}' has a blank entry.");
        }

        return [$value, $value, false];
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

    /**
     * A list of chosen values, each once.
     *
     * Not checked against the choices: those belong to another setting and can
     * change after this is saved, so the READER intersects (EarnedStatuses
     * does). Refusing here would only make a renamed status un-saveable.
     *
     * @return list<string>
     */
    private function castSubset(mixed $value): array
    {
        $out = [];

        foreach ($this->castJson($value) as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("Setting '{$this->key}' expects a list of text.");
            }

            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, string> */
    private function castFranchiseMap(mixed $value): array
    {
        $map = $this->castJson($value);

        // A non-empty list has positions, not codes.
        if ($map !== [] && array_is_list($map)) {
            throw new InvalidArgumentException("Setting '{$this->key}' expects codes, not a list.");
        }

        $out = [];

        foreach ($map as $code => $place) {
            $code = trim((string) $code);

            if ($code === '' || ! is_string($place) || trim($place) === '') {
                throw new InvalidArgumentException("Setting '{$this->key}' expects each code to name a place.");
            }

            $out[$code] = trim($place);
        }

        return $out;
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
        foreach ($this->options as $option) {
            if ($option == $value) {
                // The option as declared, so an int month stays an int when
                // the client sends "1" from a dropdown.
                return $option;
            }
        }

        throw new InvalidArgumentException(sprintf(
            "Setting '%s' must be one of: %s.",
            $this->key,
            implode(', ', array_map(fn ($o) => var_export($o, true), $this->options))
        ));
    }
}
