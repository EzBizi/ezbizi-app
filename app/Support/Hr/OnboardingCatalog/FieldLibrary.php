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
                shownWhen: ['key' => 'pay_basis', 'is' => 'hourly'],
            ),
            'hourly_rate_steps' => self::field(
                'hourly_rate_steps', 'Hourly rate stages', FieldType::RATE_STEPS, FilledBy::EMPLOYER,
                required: false,
                shownWhen: ['key' => 'pay_basis', 'is' => 'hourly'],
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
                shownWhen: ['key' => 'pay_basis', 'is' => 'salary'],
            ),
            'salary_period' => self::field(
                'salary_period', 'Salary period', FieldType::TEXT, FilledBy::EMPLOYER,
                shownWhen: ['key' => 'pay_basis', 'is' => 'salary'],
            ),
            'daily_deduction' => self::field(
                'daily_deduction', 'Daily salary deduction', FieldType::NUMBER, FilledBy::EMPLOYER,
                shownWhen: ['key' => 'pay_basis', 'is' => 'salary'],
            ),
            'raise_amount' => self::field(
                'raise_amount', 'Scheduled increase amount', FieldType::NUMBER, FilledBy::EMPLOYER,
                required: false,
            ),
            'raise_period' => self::field(
                'raise_period', 'Scheduled increase interval', FieldType::TEXT, FilledBy::EMPLOYER,
                required: false,
            ),
            'pay_basis' => [
                'key'       => 'pay_basis',
                'label'     => 'Pay basis',
                'type'      => FieldType::CHOICE,
                'options'   => ['Hourly', 'Salary'],
                'filled_by' => FilledBy::EMPLOYER,
                'required'  => true,
                'sensitive' => false,
                'retired'   => false,
                'is_expiry' => false,
            ],
            // The technician ticks what they received, as in A1 Tools (Jose,
            // 2026-10-05: "that's something to be filled on the technician
            // side"). Only the field contracts ask them.
            'tools' => self::checklist(
                'tools', 'Tools issued', self::TOOLS, FilledBy::EMPLOYEE,
            ),
            'equipment' => self::checklist(
                'equipment', 'Equipment issued', self::EQUIPMENT, FilledBy::EMPLOYEE,
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
            'state' => self::field(
                'state', 'Governing state', FieldType::TEXT, FilledBy::EMPLOYER,
            ),
            'county' => self::field(
                'county', 'County for disputes', FieldType::TEXT, FilledBy::EMPLOYER,
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
     * What a new hire gives besides the agreement: tax and bank details and
     * the documents A1 Tools collected at onboarding (Jose, 2026-09-30: "yes
     * of course, those are the most important things"). Field roles also give
     * a passport photo and their car, which A1 let them skip ("no vehicle
     * yet"), so the car is optional here too. The keys are the A1 import's.
     *
     * None of these is named in a contract body, so none is printed on the
     * signed document; the numbers are encrypted and masked, the files stay
     * on the record.
     *
     * @return list<array<string, mixed>>
     */
    public static function newHire(bool $fieldRole): array
    {
        $taxId = ['key' => 'tax_id_type', 'label' => 'Tax ID', 'type' => FieldType::CHOICE,
            'options' => ['SSN', 'EIN'], 'filled_by' => FilledBy::EMPLOYEE, 'required' => true,
            'sensitive' => false, 'retired' => false, 'is_expiry' => false];
        $secret = fn (array $row) => ['sensitive' => true] + $row;
        $mine = FilledBy::EMPLOYEE;

        $rows = [
            $taxId,
            $secret(self::field('ssn', 'Social Security number', FieldType::TEXT, $mine,
                shownWhen: ['key' => 'tax_id_type', 'is' => 'ssn'])),
            $secret(self::field('ein', 'EIN', FieldType::TEXT, $mine,
                shownWhen: ['key' => 'tax_id_type', 'is' => 'ein'])),
            self::field('ein_company_name', 'Company name on the EIN', FieldType::TEXT, $mine,
                shownWhen: ['key' => 'tax_id_type', 'is' => 'ein']),
            self::field('bank_name', 'Bank name', FieldType::TEXT, $mine),
            self::field('bank_account_name', 'Name on the bank account', FieldType::TEXT, $mine),
            $secret(self::field('bank_account_number', 'Bank account number', FieldType::TEXT, $mine)),
            $secret(self::field('bank_routing_number', 'Bank routing number', FieldType::TEXT, $mine)),
            $secret(self::field('tax_form', 'W-4 (employee) or W-9 (contractor), signed', FieldType::FILE, $mine)),
            // Filled from the bank and identity answers and signed in the app
            // (Jose, 2026-10-06: "so it's all filled on ezbizi").
            $secret(self::field('direct_deposit_form', 'Direct deposit authorization, signed', FieldType::FILE, $mine)),
            $secret(self::field('i9_form', 'Form I-9, signed', FieldType::FILE, $mine)),
            $secret(self::field('drivers_license_front', 'Driver licence (front)', FieldType::FILE, $mine)),
            $secret(self::field('drivers_license_back', 'Driver licence (back)', FieldType::FILE, $mine)),
            self::field('uniform_photo', 'Photo in uniform', FieldType::FILE, $mine),
        ];

        if ($fieldRole) {
            $rows[] = $secret(self::field('id_document', 'Passport photo', FieldType::FILE, $mine));
            foreach (['front' => 'front', 'back' => 'back', 'left' => 'left side', 'right' => 'right side',
                'interior' => 'interior', 'mileage' => 'mileage'] as $part => $words) {
                $rows[] = self::field("car_{$part}", "Your car ({$words})", FieldType::FILE, $mine, required: false);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private const TOOLS = [
        'Tool Bag', 'Tool Box', 'Plastic Crate', 'Extension cord', 'Grinder',
        'Circular Saw', 'Impact Drill', 'Drill', "2x Drill's Batteries",
        "Drill Battery's Charger", 'Drill & Impact Bits', 'Concrete Drill Bit',
        'Steel Cutter', 'Wrench', '2x Pipe Wrenches', 'Combined Wrench Set',
        'Hammer', 'Crimping tool', 'Locking Plier', 'Alligator Plier',
        'Tape Measure', 'Flashlight', 'Headlight', 'Lighter', 'Knife',
        'Gas Flange Key', 'Caulking Gun', 'Multimeter', 'Gas Leak Detector',
        'Hand brush', 'Wire hand brush', 'Trowel', 'Concrete Mixer Rod',
        'Bricks Jointer', 'Chisel',
    ];

    private const EQUIPMENT = [
        'Vacuum', 'Rods Holder Bag', 'Sweeping Brush', 'Sweeping Rod Handle',
        'Big Ladder', 'Small Ladder', 'Drone Set', 'Scope camera', 'Drop Cloth',
        'Gloves', 'Helmet', 'Belt', 'Goggles', 'Dust Pan',
    ];

    /**
     * @param  string[]  $items
     * @return array<string, mixed>
     */
    private static function checklist(string $key, string $label, array $items, string $filledBy): array
    {
        $row = self::field($key, $label, FieldType::CHECKLIST, $filledBy, required: false);
        $row['options'] = $items;

        return $row;
    }

    private static function field(
        string $key,
        string $label,
        string $type,
        string $filledBy,
        bool $required = true,
        ?array $shownWhen = null,
    ): array {
        $row = [
            'key'       => $key,
            'label'     => $label,
            'type'      => $type,
            'options'   => [],
            'filled_by' => $filledBy,
            'required'  => $required,
            'sensitive' => false,
            'retired'   => false,
            'is_expiry' => false,
        ];
        if ($shownWhen !== null) {
            $row['shown_when'] = $shownWhen;
        }

        return $row;
    }
}
