<?php

namespace App\Support\Tasks;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Codec for a task's recurrence declaration (Tasks Phase 2 design, task-5
 * brief): `{mode, every, unit, until, count}`.
 *
 * Read BY KEY, never by position or order. MySQL does not preserve JSON key
 * order and this module never asserts on it (same rule TaskWindow and
 * TaskViewCodec follow for the rest of a saved view's configuration).
 *
 * This is a declaration only. Task 6 builds the worker that acts on it;
 * nothing here schedules an occurrence or emits an automation.
 */
final class TaskRecurrenceCodec
{
    private const MODES = ['after_completion', 'on_schedule'];

    private const UNITS = ['day', 'week', 'month'];

    private const KEYS = ['mode', 'every', 'unit', 'until', 'count', 'declared_on'];

    public static function parse(?array $raw): ?TaskRecurrence
    {
        if ($raw === null) {
            return null;
        }

        $unknown = array_diff(array_keys($raw), self::KEYS);
        if ($unknown !== []) {
            self::invalid('Unknown recurrence field: '.implode(', ', $unknown).'.');
        }

        $mode = $raw['mode'] ?? null;
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            self::invalid('A recurrence mode must be after_completion or on_schedule.');
        }

        $every = $raw['every'] ?? null;
        if (! is_int($every) || $every < 1) {
            self::invalid('A recurrence must repeat every 1 or more units.');
        }

        $unit = $raw['unit'] ?? null;
        if (! is_string($unit) || ! in_array($unit, self::UNITS, true)) {
            self::invalid('A recurrence unit must be day, week, or month.');
        }

        $until = self::until($raw);
        $count = self::count($raw);
        if ($until !== null && $count !== null) {
            self::invalid('A recurrence may end on a date or after a count, not both.');
        }
        $declaredOn = self::declaredOn($raw);

        return new TaskRecurrence($mode, $every, $unit, $until, $count, $declaredOn);
    }

    public static function encode(TaskRecurrence $recurrence): array
    {
        return [
            'mode' => $recurrence->mode,
            'every' => $recurrence->every,
            'unit' => $recurrence->unit,
            'until' => $recurrence->until,
            'count' => $recurrence->count,
            'declared_on' => $recurrence->declaredOn,
        ];
    }

    private static function until(array $raw): ?string
    {
        if (! array_key_exists('until', $raw) || $raw['until'] === null) {
            return null;
        }
        $value = $raw['until'];
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            self::invalid('A recurrence "until" must use a real YYYY-MM-DD date.');
        }

        return $value;
    }

    /**
     * Validates the shape only. `declared_on` is never trusted from client
     * input for what it MEANS — TaskWriter::setRecurrence always
     * overwrites the parsed value with a server-stamped date before
     * saving. This only guards the value read back out of storage.
     */
    private static function declaredOn(array $raw): ?string
    {
        if (! array_key_exists('declared_on', $raw) || $raw['declared_on'] === null) {
            return null;
        }
        $value = $raw['declared_on'];
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            self::invalid('A recurrence "declared_on" must use a real YYYY-MM-DD date.');
        }

        return $value;
    }

    private static function count(array $raw): ?int
    {
        if (! array_key_exists('count', $raw) || $raw['count'] === null) {
            return null;
        }
        $value = $raw['count'];
        if (! is_int($value) || $value < 1) {
            self::invalid('A recurrence "count" must be a positive integer.');
        }

        return $value;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['recurrence' => $message]);
    }
}
