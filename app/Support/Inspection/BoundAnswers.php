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

        return $answers;
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
