<?php

namespace App\Support\Auth;

/**
 * Which keyed changes can be put back, and how (Jose, 2026-10-07: "so changes
 * can be reverted if needed" → "Sure" to a Put it back button).
 *
 * An ALLOWLIST, one entry per endpoint whose GET answer can be turned back into
 * the body its PUT/PATCH takes. A generic "send the GET back" would re-send a
 * read's shape to a write, and where the two differ it half-applies — worse
 * than not offering the button. Every entry here has a test that changes a
 * record through a key and puts it back.
 *
 * Only the fields the change SENT are restored, so a revert never touches
 * fields the key did not.
 */
final class ApiKeyRevert
{
    /** path pattern (after api/v1/) => how to read and rebuild it */
    private const ROUTES = [
        '#^web/sites/[^/]+/values$#' => 'webValues',
        '#^customers/[^/]+$#' => 'fields',
    ];

    public static function strategyFor(string $method, string $path): ?string
    {
        if (! in_array($method, ['PUT', 'PATCH'], true)) {
            return null;
        }

        $path = preg_replace('#^api/v1/#', '', $path);

        foreach (self::ROUTES as $pattern => $strategy) {
            if (preg_match($pattern, $path)) {
                return $strategy;
            }
        }

        return null;
    }

    /** The values that matter for this change, read out of a GET answer. */
    public static function extract(string $strategy, array $snapshot, array $sent): array
    {
        $data = $snapshot['data'] ?? $snapshot;

        if ($strategy === 'webValues') {
            $path = (string) ($sent['path'] ?? '');
            $values = $path === '' ? ($data['values'] ?? []) : ($data['pages'][$path] ?? []);

            return collect(array_keys((array) ($sent['values'] ?? [])))
                ->mapWithKeys(fn ($key) => [$key => $values[$key] ?? null])->all();
        }

        return collect(array_keys($sent))->mapWithKeys(fn ($key) => [$key => $data[$key] ?? null])->all();
    }

    /** The body that writes those values back. */
    public static function body(string $strategy, array $before, array $sent): array
    {
        $values = self::extract($strategy, $before, $sent);

        return $strategy === 'webValues'
            ? array_filter(['path' => $sent['path'] ?? null, 'values' => $values], fn ($v) => $v !== null)
            : $values;
    }
}
