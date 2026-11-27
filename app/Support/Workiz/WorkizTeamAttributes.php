<?php

namespace App\Support\Workiz;

/**
 * One Workiz team member, reduced to one row's attributes.
 *
 * Same contract as `WorkizJobAttributes`: pure, and everything that comes from
 * the connection rather than from Workiz is the caller's. `user_id` and
 * `auto_matched` are EzBizi's own and are never set here — a sweep must not be
 * able to revert a link somebody confirmed.
 *
 * Workiz's absent value is the empty string, not null, exactly as on jobs.
 */
final class WorkizTeamAttributes
{
    /**
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    public static function fromPayload(array $member): array
    {
        return [
            'remote_id' => WorkizValue::text($member['id'] ?? null) ?? '',
            'name' => WorkizValue::text($member['name'] ?? null),
            'email' => self::email($member['email'] ?? null),
            'role' => WorkizValue::text($member['role'] ?? null),
            'is_field_tech' => self::bool($member['fieldTech'] ?? null),

            // Defaults to ACTIVE when absent. Workiz keeps leavers for ever
            // and `is_active` is what every picker filters on, so the safe
            // default is the one that shows a real person rather than hiding
            // them — a missing flag must not silently retire somebody.
            'is_active' => self::bool($member['active'] ?? null) ?? true,

            'service_areas' => self::list($member['serviceAreas'] ?? null),
            'skills' => self::list($member['skills'] ?? null),

            'payload' => $member,
        ];
    }

    /**
     * Lower-cased, because it is the only thing that can propose a link to an
     * EzBizi user and a match that is case-sensitive fails on the difference
     * between how somebody typed their address in two systems.
     */
    private static function email(mixed $value): ?string
    {
        $text = WorkizValue::text($value);

        return $text === null ? null : mb_strtolower($text);
    }

    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * `serviceAreas` and `skills` are documented as objects and never seen in a
     * captured payload, so this accepts either a list or a single value rather
     * than assuming which arrives.
     *
     * @return list<string>|null
     */
    private static function list(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $items = is_array($value) ? $value : [$value];
        $out = [];

        foreach ($items as $item) {
            $text = WorkizValue::text($item);

            if ($text !== null) {
                $out[] = $text;
            }
        }

        return $out === [] ? null : $out;
    }
}
