<?php

namespace App\Support\Contracts;

/**
 * Who answers a field (EZBIZI-HR-PLAN.md, `filled_by`).
 *
 * `either` exists for a reason the owner named outright: the employer may
 * pre-fill a date of birth and get it wrong, and the employee correcting it on
 * receipt should be ordinary rather than a support request.
 *
 * ── The consequence this constant does NOT itself deliver ────────
 *
 * With `either`, two people can write the same field, so each ANSWER needs
 * provenance — who last set it and when. That belongs to the table answers are
 * stored in, which onboarding builds; nothing here can supply it. Said out loud
 * because "we chose `either`" and "we handled `either`" are different claims,
 * and only the first is true on this side of the work.
 */
final class FilledBy
{
    public const EMPLOYER = 'employer';
    public const EMPLOYEE = 'employee';
    public const EITHER   = 'either';

    /** key => the wording a person choosing reads. */
    public const ALL = [
        self::EMPLOYER => 'The employer',
        self::EMPLOYEE => 'The employee',
        self::EITHER   => 'Either, and the other can correct it',
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
