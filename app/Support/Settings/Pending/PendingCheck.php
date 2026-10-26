<?php

namespace App\Support\Settings\Pending;

use Closure;

/**
 * One thing a place is expected to have and may not (Settings › Pending,
 * Jose 2026-10-26: every piece of missing information, filled from one place).
 *
 * `missing` answers for ONE place. `fill` says how the screen completes it:
 *   ['kind' => 'setting', 'key' => ...]                         PATCH /settings/{key}
 *   ['kind' => 'field', 'path' => '/companies/{id}', 'field' => 'phone']
 *   ['kind' => 'open', 'destination' => 'settings', 'hint' => '...']
 * Inline fills go through endpoints that already authorize and audit.
 */
final class PendingCheck
{
    /**
     * @param  list<string>  $scopes  organization | company | franchise
     * @param  list<string>  $permissions  all required to see and fill it
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly array $scopes,
        public readonly array $permissions,
        public readonly Closure $missing,
        public readonly Closure $fill,
    ) {}
}
