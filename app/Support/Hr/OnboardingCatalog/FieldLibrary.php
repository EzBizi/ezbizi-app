<?php

namespace App\Support\Hr\OnboardingCatalog;

use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use RuntimeException;

/**
 * The questions these adapted templates may ask, keyed the way FieldSet stores
 * them.
 *
 * Nothing here is a platform field. Each template publishes only the keys its
 * compiled body actually names, plus the two signatures DocumentRenderer draws
 * from the field list. A key that is not in this library is a mapping we missed.
 */
final class FieldLibrary
{
    /**
     * @return array<string, mixed>
     */
    public static function definition(string $key, ?string $signer = null): array
    {
        $row = self::all($signer)[$key] ?? null;

        if ($row === null) {
            throw new RuntimeException(sprintf(
                "Onboarding catalog named a field '%s' that is not in the library. "
                .'Map it or drop the tag — do not invent a seventh type to make A-1 fit.',
                $key,
            ));
        }

        return $row;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(?string $signer = null): array
    {
        $employee = $signer === 'contractor' ? 'Contractor signature' : 'Employee signature';
        $employer = $signer === 'contractor' ? 'Company representative signature' : 'Employer signature';

        return [
            'date_of_hire' => self::field(
                'date_of_hire', 'Date of hire', FieldType::DATE, FilledBy::EMPLOYER,
            ),
            'job_title' => self::field(
                'job_title', 'Job title', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'employer_address' => self::field(
                'employer_address', 'Business address', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'address' => self::field(
                'address', 'Home address', FieldType::TEXT, FilledBy::EITHER,
            ),
            'hourly_rate' => self::field(
                'hourly_rate', 'Hourly rate', FieldType::NUMBER, FilledBy::EMPLOYER,
            ),
            'comp_method' => self::field(
                'comp_method', 'Compensation method', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'commission_pct' => self::field(
                'commission_pct', 'Commission percent', FieldType::NUMBER, FilledBy::EMPLOYER,
            ),
            'commission_stages' => self::field(
                'commission_stages', 'Commission stages', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'salary_amount' => self::field(
                'salary_amount', 'Salary amount', FieldType::NUMBER, FilledBy::EMPLOYER,
            ),
            'salary_period' => self::field(
                'salary_period', 'Salary period', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'daily_deduction' => self::field(
                'daily_deduction', 'Daily salary deduction', FieldType::NUMBER, FilledBy::EMPLOYER,
            ),
            'raise_amount' => self::field(
                'raise_amount', 'Scheduled increase amount', FieldType::NUMBER, FilledBy::EMPLOYER,
            ),
            'raise_period' => self::field(
                'raise_period', 'Scheduled increase interval', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'work_days' => self::field(
                'work_days', 'Work days', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'work_hours' => self::field(
                'work_hours', 'Work hours', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'lunch_break' => self::field(
                'lunch_break', 'Lunch break', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'county' => self::field(
                'county', 'County for disputes', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'state' => self::field(
                'state', 'Governing state', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'employer_signature' => self::field(
                'employer_signature', $employer, FieldType::SIGNATURE, FilledBy::EMPLOYER,
            ),
            'employee_signature' => self::field(
                'employee_signature', $employee, FieldType::SIGNATURE, FilledBy::EMPLOYEE,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(string $key, string $label, string $type, string $filledBy): array
    {
        return [
            'key'       => $key,
            'label'     => $label,
            'type'      => $type,
            'options'   => [],
            'filled_by' => $filledBy,
            'required'  => true,
            'sensitive' => false,
            'retired'   => false,
            'is_expiry' => false,
        ];
    }
}
