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
            default            => $value,
        };
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
