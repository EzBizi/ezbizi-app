<?php

namespace App\Support\Inspection;

use App\Models\Customer;
use App\Models\Job;

/** Prefill and freeze bindable keys as already-rendered text. */
final class BoundAnswers
{
    /**
     * @param  list<array<string, mixed>>  $screens
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public static function freeze(?Customer $customer, ?Job $job, array $screens, array $answers): array
    {
        foreach ($screens as $screen) {
            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $key = (string) ($field['key'] ?? '');
                $bind = $field['bind'] ?? null;
                if ($key === '' || ! is_string($bind) || $bind === '') {
                    continue;
                }

                $locked = ($field['locked'] ?? false) === true;
                $existing = $answers[$key] ?? null;
                $hasEdit = is_string($existing) && trim($existing) !== '';

                if ($hasEdit && ! $locked) {
                    continue;
                }

                $rendered = self::render($customer, $job, $bind);
                if ($rendered !== null) {
                    $answers[$key] = $rendered;
                }
            }
        }

        return self::withoutHidden($screens, $answers);
    }

    /**
     * Drop answers to questions this version will not ask.
     *
     * A technician answers the wood-stove branch, then changes the system type
     * to masonry. Without this the report freezes an answer it never renders —
     * evidence of a question that was not asked, sitting in `answers` forever
     * and surfacing in an export or a §9 purge report as something the visit
     * apparently found.
     *
     * Resolved against the FINAL answers, once, at submit. Doing it as the
     * technician types would delete work the moment they tapped the wrong
     * option and tapped back.
     *
     * ── It is not a whitelist, deliberately ─────────────────────
     *
     * Only keys that BELONG to a field this version declares are considered. An
     * answer key with no field behind it is left exactly where it is: it
     * belongs to something else — a future field type this build predates, or a
     * list this method has never heard of — and dropping it would be freeze
     * deleting data it does not understand.
     *
     * @param  list<array<string, mixed>>  $screens
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private static function withoutHidden(array $screens, array $answers): array
    {
        foreach ($screens as $screen) {
            if (! is_array($screen)) {
                continue;
            }

            $screenShown = Condition::passes($screen['visible_if'] ?? null, $answers);

            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $shown = $screenShown
                    && Condition::passes($field['visible_if'] ?? null, $answers);

                $key = (string) ($field['key'] ?? '');
                if ($key !== '' && array_key_exists($key, $answers) && ! $shown) {
                    unset($answers[$key]);
                }

                /*
                 * Nested answers go when their option is not the chosen one.
                 *
                 * A technician marks a crown failed, photographs it, then
                 * changes their mind and marks it passing. The report must not
                 * carry a photograph of a fault it says does not exist.
                 *
                 * `revealedBy` is asked against the answers as they now stand,
                 * so a field whose own option was just dropped reveals nothing
                 * and its children go with it.
                 */
                $revealed = $shown ? OptionSet::revealedBy($field, $answers) : [];
                $live = [];
                foreach ($revealed as $inner) {
                    if (Condition::passes($inner['visible_if'] ?? null, $answers)) {
                        $live[] = (string) ($inner['key'] ?? '');
                    }
                }

                foreach (self::everyNestedKey($field) as $nestedKey) {
                    if ($nestedKey !== ''
                        && array_key_exists($nestedKey, $answers)
                        && ! in_array($nestedKey, $live, true)) {
                        unset($answers[$nestedKey]);
                    }
                }
            }
        }

        return $answers;
    }

    /**
     * Every key that lives inside any of this field's options.
     *
     * The set to consider dropping. Only keys this version DECLARES are looked
     * at, so an answer with no field behind it is still left alone — freeze
     * must not become a whitelist.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private static function everyNestedKey(array $field): array
    {
        $keys = [];

        foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $option) {
            $option = OptionSet::normalise($option);

            foreach (($option['fields'] ?? []) as $nested) {
                if (is_array($nested)) {
                    $keys[] = (string) ($nested['key'] ?? '');
                }
            }
        }

        return $keys;
    }

    public static function render(?Customer $customer, ?Job $job, string $bind): ?string
    {
        [$source, $column] = array_pad(explode('.', $bind, 2), 2, '');

        if ($source === 'customer') {
            if ($customer === null || ! in_array($column, BindableFields::CUSTOMER, true)) {
                return null;
            }
            $value = $customer->getAttribute($column);

            return self::stringify($value);
        }

        if ($source === 'job') {
            if ($job === null || ! in_array($column, BindableFields::JOB, true)) {
                return null;
            }
            if ($column === 'scheduled_for') {
                $job->loadMissing('franchise');
                $at = $job->scheduled_for;
                if ($at === null) {
                    return null;
                }

                return $at->timezone($job->franchise?->timezone ?: 'UTC')->format('D j M, g:i A');
            }

            return self::stringify($job->getAttribute($column));
        }

        return null;
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance(
                \DateTimeImmutable::createFromInterface($value)
            )->format('D j M, g:i A');
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
