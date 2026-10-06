<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\AutomationVersion;

/**
 * What the rules of one organization are asking about one field.
 *
 * ── Why this exists, and why it is here rather than in the caller ──────
 *
 * The Email module cannot put a message body into an automation event: its own
 * payload docblock forbids message content, and `AutomationEvent` keeps payloads
 * for three months. But a rule needs to test words that only appear in a body —
 * RingCentral writes "Status: Missed" there and nothing else in the message says
 * it. So the watcher asks THIS, tests only the words somebody actually asked
 * about, and emits which of them matched. Nothing else of the message is read
 * and nothing of it is stored.
 *
 * It lives in Automations because the condition tree is ours. It nests `all` and
 * `any` to any depth with `{field, op, value}` at the leaves, and that shape will
 * change without telling Email — a walker written over there would go quietly
 * wrong the day it does. One reader, one place to fix.
 *
 * ── What it deliberately does NOT do ──────
 *
 * It returns the literals a rule NAMES. It does not know which operator they
 * belong to, and the caller must not infer one: `contains "Missed"` and
 * `eq "Missed"` both yield "Missed", and deciding what matching means is the
 * caller's job in the caller's own words.
 */
final class AutomationConditions
{
    /**
     * Every literal the active rules of one organization compare `$field` against.
     *
     * CROSS-TENANT READS ARE REFUSED BY THE GLOBAL SCOPE, and this is called from
     * a mailbox sweep that may not be inside a tenant at all. So the scope is
     * dropped and the organization is named explicitly instead — the id is an
     * argument rather than ambient state, which is the only shape where "whose
     * rules are these" has one answer rather than depending on the caller's
     * context.
     *
     * @return list<string> unique, in no meaningful order
     */
    /**
     * Does any active rule on this trigger use `{{$field}}` in one of its steps?
     *
     * The action-side twin of {@see literalsFor}: a step that writes the field
     * into a notification, a message or an email is asking for it just as much
     * as a condition that compares it. Matched with the same pattern
     * ActionRunner::interpolate fills -- `{{ field }}`, spaces allowed, any case.
     */
    public static function actionsUse(string $triggerKey, string $field, string $organizationId): bool
    {
        if ($triggerKey === '' || $field === '' || $organizationId === '') {
            return false;
        }

        $pattern = '/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/i';

        foreach (self::activeVersions($triggerKey, $organizationId)->pluck('actions') as $actions) {
            if (preg_match($pattern, (string) json_encode($actions)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The live version of every rule on this trigger that is switched on.
     *
     * A rule that is off, or that switched itself off after repeated failure,
     * is not asking about anything. Reading on its behalf would make a disabled
     * rule cost a body fetch on every message.
     */
    private static function activeVersions(string $triggerKey, string $organizationId): \Illuminate\Database\Eloquent\Builder
    {
        return AutomationVersion::query()
            ->withoutGlobalScopes()
            ->where('automation_versions.organization_id', $organizationId)
            ->whereIn('id', Automation::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('trigger_key', $triggerKey)
                ->where('is_active', true)
                ->whereNull('disabled_at')
                ->whereNotNull('active_version_id')
                ->select('active_version_id'));
    }

    public static function literalsFor(string $triggerKey, string $field, string $organizationId): array
    {
        if ($triggerKey === '' || $field === '' || $organizationId === '') {
            return [];
        }

        $trees = self::activeVersions($triggerKey, $organizationId)->pluck('conditions');

        $found = [];

        foreach ($trees as $tree) {
            self::walk(is_array($tree) ? $tree : [], $field, $found);
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $found
     */
    private static function walk(array $node, string $field, array &$found): void
    {
        foreach (['all', 'any'] as $branch) {
            if (isset($node[$branch]) && is_array($node[$branch])) {
                foreach ($node[$branch] as $child) {
                    if (is_array($child)) {
                        self::walk($child, $field, $found);
                    }
                }

                // A node is a branch or a leaf, never both. Falling through
                // would read `field` off the branch itself, which is absent —
                // harmless today and exactly the kind of thing that stops being
                // harmless when somebody adds a key to the envelope.
                return;
            }
        }

        if (($node['field'] ?? null) !== $field) {
            return;
        }

        $value = $node['value'] ?? null;

        // A LIST IS FLATTENED, because `is one of` stores one. Returning the
        // array would hand the caller something it would have to guess the shape
        // of, and every element is a literal the rule names just as much as a
        // single value is.
        foreach (is_array($value) ? $value : [$value] as $one) {
            if (is_string($one) && trim($one) !== '') {
                $found[] = $one;
            }
        }
    }
}
