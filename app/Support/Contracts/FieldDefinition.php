<?php

namespace App\Support\Contracts;

/**
 * One question on a contract template (EZBIZI-HR-PLAN.md, "A field definition
 * carries").
 *
 * A plain value object over the JSON that actually lives in
 * `contract_versions.fields`. It exists so that the four places that read a
 * field — the validator, the diff, the API presenter and the version's own
 * helpers — read it the same way, rather than each one doing
 * `$field['sensitive'] ?? false` with its own idea of the default.
 *
 * ── Every property is the TENANT's, except the type ──────────────
 *
 * The app must not know what any of these mean. It knows there is a key, that
 * something is stored against it, that somebody fills it, and that the tenant
 * has told us whether it is sensitive — never that "bank_account" is a bank
 * account. See FieldType for the one thing that is not tenant-defined and why.
 */
final class FieldDefinition
{
    /**
     * @param  string[]  $options  choice only; empty for every other type
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly array $options,
        public readonly string $filledBy,
        public readonly bool $required,
        public readonly bool $sensitive,
        public readonly bool $retired,
        public readonly bool $isExpiry,
        public readonly ?array $shownWhen,
    ) {}

    /**
     * Read one out of a request or out of stored JSON.
     *
     * Deliberately total rather than validating: it coerces what it is given and
     * says nothing about whether the result is legal. FieldSet is what refuses,
     * because refusing needs the WHOLE list — uniqueness and key stability are
     * properties of a set and of its history, not of one field.
     */
    public static function from(mixed $raw): self
    {
        $raw = is_array($raw) ? $raw : [];

        return new self(
            key:      is_string($raw['key'] ?? null) ? trim($raw['key']) : '',
            label:    is_string($raw['label'] ?? null) ? trim($raw['label']) : '',
            type:     is_string($raw['type'] ?? null) ? $raw['type'] : '',
            options:  self::normaliseOptions($raw['options'] ?? null),
            filledBy: is_string($raw['filled_by'] ?? null) ? $raw['filled_by'] : '',
            // The three booleans default to FALSE, and each default is the safe
            // direction: a field nobody said was required is optional, a field
            // nobody called sensitive is treated as ordinary rather than
            // silently encrypted and hidden, and nothing is retired by accident.
            required:  (bool) ($raw['required'] ?? false),
            sensitive: (bool) ($raw['sensitive'] ?? false),
            retired:   (bool) ($raw['retired'] ?? false),
            isExpiry:  (bool) ($raw['is_expiry'] ?? false),
            shownWhen: self::normaliseShownWhen($raw['shown_when'] ?? null),
        );
    }

    /** @return string[] */
    private static function normaliseOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $o) => is_string($o) ? trim($o) : '', $options),
            fn (string $o) => $o !== '',
        ));
    }

    /**
     * What gets stored, and what the API returns.
     *
     * `options` is present on every field, empty for the types that have none,
     * for the reason AutomationsController states about `writable_fields`: empty
     * is an answer, a missing key is indistinguishable from an older server, and
     * the client would have to guess which.
     */
    public function toArray(): array
    {
        $out = [
            'key'       => $this->key,
            'label'     => $this->label,
            'type'      => $this->type,
            'options'   => $this->options,
            'filled_by' => $this->filledBy,
            'required'  => $this->required,
            'sensitive' => $this->sensitive,
            'retired'   => $this->retired,
            'is_expiry' => $this->isExpiry,
        ];

        // Absent means always asked. Sending null on every field would make
        // every published version look like it changed the day this landed.
        if ($this->shownWhen !== null) {
            $out['shown_when'] = $this->shownWhen;
        }

        return $out;
    }

    /**
     * What a person is shown of this field, for the diff.
     *
     * The key is left out because it is the IDENTITY of the field rather than a
     * property of it — it cannot change (FieldSet refuses a rename), so a diff
     * line saying "key: bank_account → bank_account" would be noise on every
     * single row.
     *
     * `retired` is left out for a different reason: retiring is its own line in
     * the diff, and listing it here as well would report one act twice.
     */
    public function comparableAttributes(): array
    {
        return [
            'label'     => $this->label,
            'type'      => $this->type,
            'options'   => $this->options,
            'filled_by' => $this->filledBy,
            'required'  => $this->required,
            'sensitive' => $this->sensitive,
            'is_expiry' => $this->isExpiry,
            'shown_when' => $this->shownWhen,
        ];
    }

    public function isShownGiven(array $answers): bool
    {
        if ($this->shownWhen === null) {
            return true;
        }

        $raw = $answers[$this->shownWhen['key']] ?? null;

        if ($raw === null || $raw === '') {
            return false;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $raw) ?? '');

        return trim($slug, '_') === $this->shownWhen['is'];
    }

    private static function normaliseShownWhen(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $key = is_string($raw['key'] ?? null) ? trim($raw['key']) : '';
        $is  = is_string($raw['is'] ?? null) ? trim($raw['is']) : '';

        if ($key === '' || $is === '') {
            return null;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $is) ?? '');
        $slug = trim($slug, '_');

        return $slug === '' ? null : ['key' => $key, 'is' => $slug];
    }
}
