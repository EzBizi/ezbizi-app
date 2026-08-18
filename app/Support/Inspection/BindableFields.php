<?php

namespace App\Support\Inspection;

/**
 * Customer and job columns Builder may wire a field to.
 *
 * Inspection does not invent columns. Extend this list when Customers or Jobs
 * grow — not as a one-off key inside a named inspection.
 */
final class BindableFields
{
    /** @var list<string> */
    public const CUSTOMER = [
        'name', 'email', 'phone', 'number',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code',
    ];

    /** @var list<string> */
    public const JOB = [
        'number', 'title', 'scheduled_for', 'status', 'description',
    ];

    /** @return list<string> */
    public static function customerColumns(): array
    {
        return self::CUSTOMER;
    }

    /** @return list<string> */
    public static function jobColumns(): array
    {
        return self::JOB;
    }

    /** @return list<string> customer.name, job.scheduled_for, … */
    public static function keys(): array
    {
        return [
            ...array_map(fn (string $c) => "customer.{$c}", self::CUSTOMER),
            ...array_map(fn (string $c) => "job.{$c}", self::JOB),
        ];
    }
}
