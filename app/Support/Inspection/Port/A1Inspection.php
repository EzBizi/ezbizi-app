<?php

namespace App\Support\Inspection\Port;

/**
 * A1 Chimney's Level 1 / Level 2 inspection, as EzBizi screens.
 *
 * ── Ported from generators, not from their output ────────────────
 *
 * The original is 2,818 lines of TypeScript, and most of it is expansion: a
 * helper called `createOkNotOk` writes Pass / Fail / Not-Applicable with the
 * fail branch carrying a required photograph, an explanation and priced work,
 * and the file calls it thirty-five times. Transcribing the expansion would
 * produce three thousand lines of array literal that nobody could read and
 * nobody would maintain.
 *
 * So the GENERATORS are ported. `finding()` below is `createOkNotOk`, and
 * everything else falls out of it. The result is the same inspection expressed
 * in about a tenth of the space, and a change to how every finding works is one
 * edit rather than thirty-five.
 *
 * ── This is a starting point, not a frozen artefact ──────────────
 *
 * The output is ordinary tenant data. Once seeded, A1 edits it in the Builder
 * like any other inspection — which is the entire objective, and the thing the
 * original could never do. Nothing here is privileged: no code path reads this
 * class at runtime, and deleting it after the seed would change nothing.
 *
 * ── Fidelity, and where it stops ─────────────────────────────────
 *
 * The four steps, the five-way branch on system type, the exterior findings and
 * the signature ceremony are all here. Individual measurement fields inside the
 * furnace and boiler branches are the long tail — the same three helpers, more
 * of them — and are left for A1 to add in the Builder against the real form,
 * because guessing at a measurement nobody asked for is worse than an obvious
 * gap somebody fills in.
 */
final class A1Inspection
{
    /** Building codes cited, keyed by the reference the fields use. */
    public const CODES = [
        'R1003.11' => [
            'code' => 'R1003.11',
            'title' => 'R1003.11 FLUE LINING (MATERIAL)',
            'body' => 'All chimneys shall be lined. The lining material shall be appropriate '
                .'for the type of appliance connected, in accordance with the terms of the '
                .'appliance listing and manufacturer\'s instructions.',
        ],
        'R1003.9' => [
            'code' => 'R1003.9',
            'title' => 'R1003.9 TERMINATION',
            'body' => 'Chimneys shall extend not less than 2 feet (610 mm) higher than any '
                .'portion of a building within 10 feet (3048 mm), but shall be not less than '
                .'3 feet (914 mm) above the highest point where the chimney passes through '
                .'the roof.',
        ],
        'R1003.20' => [
            'code' => 'R1003.20',
            'title' => 'R1003.20 CHIMNEY CRICKETS',
            'body' => 'Chimneys shall be provided with crickets where the dimension parallel '
                .'to the ridgeline is greater than 30 inches (762 mm) and does not intersect '
                .'the ridgeline. The intersection of the cricket and the chimney shall be '
                .'flashed and counter flashed in the same manner as normal roof-chimney '
                .'intersections.',
        ],
        'R903.2' => [
            'code' => 'R903.2',
            'title' => 'R903.2 FLASHING',
            'body' => 'Flashings shall be used to seal roofing systems, where the system is '
                .'interrupted or terminated, and shall be installed in a manner that prevents '
                .'moisture from entering the wall and roof through joints in copings, through '
                .'moisture permeable materials and at intersections with parapet walls and '
                .'other penetrations through the roof plane.',
        ],
        '1926.706(b)' => [
            'code' => '1926.706(b)',
            'title' => '1926.706(b) MASONRY WALLS',
            'body' => 'All masonry walls over eight feet in height shall be adequately braced '
                .'to prevent overturning and to prevent collapse unless the wall is adequately '
                .'supported so that it will not overturn or collapse. The bracing shall remain '
                .'in place until permanent supporting elements of the structure are in place.',
        ],
        'NFPA211' => [
            'code' => 'NFPA 211',
            'title' => 'NFPA 211 INSPECTION AND MAINTENANCE',
            'body' => 'Chimneys, fireplaces, and vents shall be inspected at least once a year '
                .'for soundness, freedom from deposits, and correct clearances. Cleaning, '
                .'maintenance, and repairs shall be done if necessary.',
        ],
        'R1001.6' => [
            'code' => 'R1001.6',
            'title' => 'R1001.6 FIREBOX DIMENSIONS',
            'body' => 'The firebox of a fireplace shall have a minimum depth of 20 inches '
                .'(508 mm). The throat shall not be less than 8 inches (203 mm) above the '
                .'fireplace opening, and the throat opening shall not be less than 4 inches '
                .'(102 mm) deep.',
        ],
    ];

    /**
     * The whole inspection.
     *
     * @param  array<string, string>  $codeIds  reference => inspection_codes.id
     * @return list<array<string, mixed>>
     */
    public static function screens(array $codeIds): array
    {
        return [
            self::customerScreen(),
            self::systemScreen($codeIds),
            self::exteriorScreen($codeIds),
            self::signatureScreen(),
        ];
    }

    // ── the generators, ported ───────────────────────────────────

    /**
     * A FINDING: pass, fail, and optionally not-applicable.
     *
     * This is `createOkNotOk`. The failing branch carries a required photograph,
     * an explanation and priced work, so a technician cannot record a failure
     * and move on — the single most valuable idea in the tool being ported.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function finding(
        string $key,
        string $label,
        ?string $codeId = null,
        string $okLabel = 'Meets industry standards',
        string $failLabel = 'Does not meet industry standards',
        ?string $naLabel = null,
        array $extra = [],
        array $moreFails = [],
    ): array {
        $fail = [
            'value' => 'not-ok',
            'label' => $failLabel,
            'variant' => 'fail',
            'fields' => [
                [
                    'key' => $key.'_photo',
                    'label' => 'Photograph',
                    'type' => 'photo',
                    'max_count' => 4,
                    'required' => true,
                ],
                [
                    'key' => $key.'_explanation',
                    'label' => 'What is wrong',
                    'type' => 'textarea',
                ],
                [
                    'key' => $key.'_work',
                    'label' => 'Recommended work',
                    'type' => 'services',
                ],
            ],
        ];

        return [
            'key' => $key,
            'label' => $label,
            'type' => 'choice',
            'required' => true,
            // A finding is what the report is FOR, so it prints as one: its own
            // heading, with the photograph, the code and the work under it.
            'print' => 'root',
            'options' => array_values(array_filter([
                $naLabel === null ? null : [
                    'value' => 'not-applicable',
                    'label' => $naLabel,
                    'variant' => 'not_applicable',
                ],
                ['value' => 'ok', 'label' => $okLabel, 'variant' => 'pass'],
                $fail,
                // A second way to fail ("Crown Does Not Exist", "Soot - Level 2")
                // demands the same evidence as the first.
                ...array_map(static fn (string $label, int $i) => [
                    'value' => 'not-ok-'.($i + 2),
                    'label' => $label,
                    'variant' => 'fail',
                    'fields' => array_map(
                        static fn (array $f) => ['key' => $f['key'].'_'.($i + 2)] + $f,
                        $fail['fields'],
                    ),
                ], $moreFails, array_keys($moreFails)),
            ])),
            ...($codeId === null ? [] : ['code_id' => $codeId]),
            ...$extra,
        ];
    }

    /** Shown only for these system types. */
    private static function forSystems(string ...$types): array
    {
        return ['field' => 'system_type', 'op' => 'in', 'values' => array_values($types)];
    }

    // ── the four screens ─────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function customerScreen(): array
    {
        return [
            'name' => 'Client and site',
            'fields' => [
                ['key' => 'first_name', 'label' => 'Name', 'type' => 'text',
                 'bind' => 'customer.name', 'print' => 'text'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'bind' => 'customer.email', 'print' => 'text'],
                ['key' => 'primary_phone', 'label' => 'Phone', 'type' => 'phone', 'bind' => 'customer.phone', 'print' => 'text'],

                ['type' => 'divider', 'label' => 'Address'],
                ['key' => 'address_line_1', 'label' => 'Address', 'type' => 'text', 'bind' => 'customer.address_line1', 'print' => 'text'],
                ['key' => 'address_line_2', 'label' => 'Address line 2', 'type' => 'text', 'bind' => 'customer.address_line2',
                 'print' => 'text'],
                ['key' => 'city', 'label' => 'City', 'type' => 'text', 'bind' => 'customer.city', 'print' => 'text'],
                ['key' => 'state', 'label' => 'State', 'type' => 'text', 'bind' => 'customer.state', 'print' => 'text'],
                ['key' => 'zip_code', 'label' => 'ZIP code', 'type' => 'text', 'bind' => 'customer.postal_code', 'print' => 'text'],

                ['type' => 'divider', 'label' => 'The visit'],
                ['key' => 'exterior_home_image', 'label' => 'Photograph of the property',
                 'type' => 'photo', 'max_count' => 2],
                ['key' => 'inspection_date', 'label' => 'Date of inspection', 'type' => 'date',
                 'bind' => 'job.scheduled_for', 'print' => 'text'],
                [
                    'key' => 'inspection_level',
                    'label' => 'Level',
                    'type' => 'choice',
                    'required' => true,
                    'print' => 'text',
                    'options' => [
                        ['value' => '1', 'label' => 'Level 1 — readily accessible'],
                        ['value' => '2', 'label' => 'Level 2 — includes the exterior and flue'],
                    ],
                ],
                ['key' => 'inspection_reason', 'label' => 'Why this inspection', 'type' => 'textarea',
                 'print' => 'text'],
            ],
        ];
    }

    /**
     * The system screen, re-cut 2026-10-26 to the form A1's technicians file
     * today: system types, items and option wording read off 140 of their
     * reports uploaded to Workiz in October 2026, not invented here.
     *
     * Each system type is a row in SYSTEMS: its measurements, its yes/no
     * questions, and its findings. A finding's key is "<type>_<item>", so the
     * same item on two types never shares an answer.
     *
     * @param  array<string, string>  $codes
     */
    private static function systemScreen(array $codes): array
    {
        $fields = [
            [
                'key' => 'system_type',
                'label' => 'System type',
                'type' => 'choice',
                'required' => true,
                'print' => 'text',
                'options' => array_map(
                    static fn (string $value, array $system) => ['value' => $value, 'label' => $system['label']],
                    array_keys(self::SYSTEMS),
                    self::SYSTEMS,
                ),
            ],
            ['key' => 'system_image', 'label' => 'System image', 'type' => 'photo', 'max_count' => 4],
        ];

        foreach (self::SYSTEMS as $type => $system) {
            $only = self::forSystems($type);
            $fields[] = ['type' => 'divider', 'label' => $system['label'], 'visible_if' => $only];

            foreach ($system['measure'] ?? [] as $key => $label) {
                $fields[] = ['key' => "{$type}_{$key}", 'label' => $label.' (inches)', 'type' => 'number',
                    'print' => 'text', 'visible_if' => $only];
            }

            foreach ($system['ask'] ?? [] as $key => [$label, $options, $when]) {
                $fields[] = [
                    'key' => "{$type}_{$key}", 'label' => $label, 'type' => 'choice', 'print' => 'text',
                    'visible_if' => $when === null ? $only
                        : ['field' => "{$type}_{$when[0]}", 'op' => 'eq', 'value' => $when[1]],
                    'options' => array_map(static fn ($o) => ['value' => $o, 'label' => $o], $options),
                ];
            }

            foreach ($system['find'] as $key => $spec) {
                [$label, $ok, $fails, $na] = $spec + [1 => null, 2 => null, 3 => null];
                $when = $spec['when'] ?? null;
                $fields[] = self::finding(
                    "{$type}_{$key}",
                    $label,
                    codeId: isset($spec['code']) ? ($codes[$spec['code']] ?? null) : null,
                    okLabel: $ok ?? 'Meets Industry Standards',
                    failLabel: ($fails ?? ['Does Not Meet Industry Standards'])[0],
                    naLabel: $na,
                    extra: ['visible_if' => $when === null ? $only
                        : ['field' => "{$type}_{$when[0]}", 'op' => 'eq', 'value' => $when[1]]],
                    moreFails: array_slice($fails ?? [], 1),
                );
            }
        }

        return ['name' => 'The system', 'fields' => $fields];
    }

    private const SOOT = ['Soot Condition', 'Cleaned / Swept',
        ['Soot - Level 1', 'Creosote Buildup - Level 2', 'Glazed Creosote - Level 3'], null, 'code' => 'NFPA211'];

    private const LINER = ['Chimney Liner', null, null, null, 'code' => 'R1003.11'];

    private const DIRECT_VENT = 'Not Applicable (Direct Vent / B vent)';

    private const FLUE_TYPES = ['Masonry / Bricks', 'Single Wall Metal Pipe', 'Roof Metal Pipe',
        'Side Wall Metal Pipe', 'Siding Covered'];

    private const GAS_VENTING = [
        'burning_type' => ['Burning Type', ['Gas / Oil Burner', 'Other'], null],
        'venting' => ['Venting', ['Vented', 'Not Vented', 'Direct Vent', 'Single Wall Vent'], null],
        'flue_type' => ['Flue Ventilation Type', self::FLUE_TYPES, null],
    ];

    /**
     * Every system type: label, measurements, questions, findings.
     *
     * A finding is [label, ok wording, fail wordings, not-applicable wording],
     * nulls meaning the house default, plus optional 'code' and 'when'
     * ([question, answer] that must hold for it to be asked).
     */
    private const SYSTEMS = [
        'masonry' => [
            'label' => 'Masonry Fireplace',
            'measure' => ['width' => 'Width', 'height' => 'Height', 'depth' => 'Depth',
                'hearth_side' => 'Hearth Extension Side'],
            'find' => [
                'clearance' => ['Clearance To Combustible'],
                'firebox' => ['Firebox Condition', null, null, null, 'code' => 'R1001.6'],
                'damper' => ['Damper', null, null, self::DIRECT_VENT],
                'smoke_chamber' => ['Smoke Chamber', null, ['Does Not Parged Smooth', 'Does Not Meet Industry Standards']],
                'soot' => self::SOOT,
                'liner' => self::LINER,
                'ash_dump' => ['Ash Dump', null, null, 'Ash Dump does not exist'],
                'gas_line' => ['Gas Line', null, null, 'Gas Line Does Not Exist'],
                'glass_door' => ['Glass Door Condition', null, null, 'No glass door'],
                'hearth' => ['Hearth Extension Dimensions'],
            ],
        ],
        'prefab' => [
            'label' => 'Pre-Fabricated Fireplace',
            'measure' => ['width' => 'Width', 'height' => 'Height', 'depth' => 'Depth'],
            'ask' => [
                'gas' => ['System has a gas connection?', ['Yes', 'No'], null],
                'gas_starter' => ['Gas Starter Type', ['Remote / Pilot Light', 'Key Starter'], ['gas', 'Yes']],
                'vented' => ['Is the system vented?', ['Yes', 'No'], null],
            ],
            'find' => [
                'clearance' => ['Clearance to Combustibles'],
                'refractory' => ['Refractory Panels Condition', null, ['Cracked / Missing', 'Does Not Meet Industry Standards']],
                'glass_door' => ['Glass Door Condition', null, null, 'No glass door'],
                'hearth' => ['Hearth Extensions'],
                'gas_burner' => ['Gas Line Burner Condition', 'when' => ['gas', 'Yes']],
                'gas_valve' => ['Gas Valve Condition', 'when' => ['gas', 'Yes']],
                'pilot' => ['Pilot Light Condition', 'when' => ['gas', 'Yes']],
                'thermocouple' => ['Thermocouple Condition', 'when' => ['gas', 'Yes']],
                'remote' => ['Remote System Condition', 'Working', ['Needs Attention'], null, 'when' => ['gas_starter', 'Remote / Pilot Light']],
                'damper' => ['Damper', null, null, self::DIRECT_VENT],
                'soot' => self::SOOT,
                'liner' => self::LINER,
            ],
        ],
        'gas' => [
            'label' => 'Gas Fireplace',
            'measure' => ['pipe' => 'Pipe Circumference'],
            'ask' => self::GAS_VENTING,
            'find' => [
                'burner' => ['Burner Condition'],
                'logs' => ['Logs Condition'],
                'pilot' => ['Pilot Assembly Condition'],
                'gas_valve' => ['Gas Valve Condition'],
                'visual' => ['Visual Inspection'],
                'clearance' => ['Clearance to Combustibles'],
                'pipe_clearance' => ['Pipe Clearance to Combustibles'],
                'pipes' => ['Pipes Condition', 'Sealed / Connected', ['Unsealed / Disconnected']],
                'pipes_soot' => ['Furnace Pipes Soot Condition', 'Cleaned / Swept', ['Soot - Level 1', 'Creosote Buildup - Level 2']],
                'clean_out' => ['Clean-Out Door', 'Tight-Fitting / Noncombustible Cover', ['Loose-Fitting / Combustible Cover'], 'No clean-out door'],
                'liner' => self::LINER,
            ],
        ],
        'masonry_ventless' => [
            'label' => 'Masonry Ventless Fireplace',
            'find' => [
                'burner' => ['Burner Condition'],
                'logs' => ['Logs Condition'],
                'pilot' => ['Pilot Assembly Condition'],
                'gas_valve' => ['Gas Valve Condition'],
                'gas_line' => ['Gas Line'],
                'clearance' => ['Clearance To Combustible'],
                'firebox' => ['Firebox Condition', null, null, null, 'code' => 'R1001.6'],
                'hearth' => ['Hearth Extensions'],
            ],
        ],
        'wood' => [
            'label' => 'Wood Stove',
            'measure' => ['pipe' => 'Pipe Circumference'],
            'ask' => [
                'stove_type' => ['Wood Stove Type', ['Free Standing', 'Insert'], null],
                'flue_type' => ['Flue Ventilation Type', self::FLUE_TYPES, null],
            ],
            'find' => [
                'clearance' => ['Clearance To Combustible'],
                'stove' => ['Stove Condition'],
                'pipe_connection' => ['Pipe Connection', 'Sealed / Connected', ['Unsealed / Not Connected']],
                'soot' => self::SOOT,
                'liner' => self::LINER,
            ],
        ],
        'furnace' => [
            'label' => 'Furnace',
            'measure' => ['pipe' => 'Pipe Circumference'],
            'ask' => self::GAS_VENTING,
            'find' => [
                'visual' => ['Visual Inspection'],
                'clearance' => ['Clearance to Combustibles'],
                'pipe_clearance' => ['Pipe Clearance to Combustibles'],
                'pipes' => ['Pipes Condition', 'Sealed / Connected', ['Unsealed / Disconnected']],
                'pipes_soot' => ['Furnace Pipes Soot Condition', 'Cleaned / Swept', ['Soot - Level 1', 'Creosote Buildup - Level 2']],
                'boiler_pipes' => ['Boiler Pipes Condition'],
                'boiler_connection' => ['Boiler Pipes Connection', 'Sealed / Connected', ['Unsealed / Disconnected'], 'No boiler'],
                'clean_out' => ['Clean-Out Door', 'Tight-Fitting / Noncombustible Cover', ['Loose-Fitting / Combustible Cover'], 'No clean-out door'],
                'liner' => self::LINER,
            ],
        ],
        'electric' => [
            'label' => 'Electric',
            'measure' => ['width' => 'Width', 'height' => 'Height'],
            'ask' => [
                'working' => ['Is the electric fireplace working?', ['Yes', 'No'], null],
            ],
            'find' => [
                'condition' => ['Fireplace Condition'],
            ],
        ],
    ];


    /**
     * The exterior, asked only on a Level 2.
     *
     * A whole screen behind one condition — which is what screen-level
     * `visible_if` exists for, and the thing the old tool needed a bespoke
     * "invisible state" component to fake.
     *
     * @param  array<string, string>  $codes
     */
    private static function exteriorScreen(array $codes): array
    {
        return [
            'name' => 'Exterior',
            'visible_if' => ['field' => 'inspection_level', 'op' => 'eq', 'value' => '2'],
            'fields' => [
                ['key' => 'exterior_chimney_type_image', 'label' => 'Photograph of the chimney',
                 'type' => 'photo', 'max_count' => 4],

                self::finding('chimney_height_from_roof_line', 'Chimney Height From Roof Line',
                    codeId: $codes['R1003.9'] ?? null),
                self::finding('chimney_cricket', 'Chimney Cricket',
                    codeId: $codes['R1003.20'] ?? null,
                    naLabel: 'Chimney Does Not Need Cricket',
                    moreFails: ['Chimney Needs Cricket']),
                self::finding('flashing_condition', 'Flashing Condition',
                    codeId: $codes['R903.2'] ?? null),
                self::finding('masonry_work_condition', 'Masonry Work Condition',
                    codeId: $codes['1926.706(b)'] ?? null, naLabel: 'No masonry'),
                [
                    'key' => 'masonry_work_repair_needs',
                    'label' => 'What the masonry needs',
                    'type' => 'choice',
                    'multiple' => true,
                    'print' => 'text',
                    'visible_if' => ['field' => 'masonry_work_condition',
                                     'op' => 'eq', 'value' => 'not-ok'],
                    'options' => [
                        ['value' => 'cracked-bricks', 'label' => 'Cracked bricks'],
                        ['value' => 'loose-mortar', 'label' => 'Loose mortar'],
                        ['value' => 'spalling', 'label' => 'Spalling'],
                        ['value' => 'water-damage', 'label' => 'Water damage'],
                    ],
                ],
                self::finding('chimney_crown_condition', 'Chimney Crown Condition',
                    codeId: $codes['R1003.9'] ?? null,
                    naLabel: 'Not applicable',
                    moreFails: ['Crown Does Not Exist']),
                self::finding('chimney_rain_cap', 'Chimney Rain Cap',
                    naLabel: 'Not applicable',
                    moreFails: ['Rain Cap Does Not Exist']),
                self::finding('chimney_termination_cap', 'Chimney Termination Cap',
                    naLabel: 'Not applicable',
                    moreFails: ['Termination Cap Does Not Exist']),
                self::finding('chase_condition', 'Chase Condition',
                    naLabel: self::DIRECT_VENT),
                self::finding('chase_covering_condition', 'Chase Cover Condition',
                    naLabel: self::DIRECT_VENT),
                self::finding('exterior_pipe_condition', 'Exterior Pipe Condition',
                    okLabel: 'Sealed / Connected',
                    failLabel: 'Unsealed / Not Connected',
                    naLabel: 'No exterior pipe'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function signatureScreen(): array
    {
        return [
            'name' => 'Sign off',
            'fields' => [
                ['key' => 'note', 'label' => 'Anything else the customer should know',
                 'type' => 'textarea', 'print' => 'text'],
                [
                    'key' => 'is_customer_on_site',
                    'label' => 'The customer is on site',
                    'type' => 'checkbox',
                    'print' => 'text',
                ],
                [
                    'key' => 'customer_signature',
                    'label' => 'Customer signature',
                    'type' => 'signature',
                    // Only when there is a customer present to sign. Asking for
                    // a signature nobody can give is how a form becomes
                    // unfinishable on a doorstep.
                    'visible_if' => ['field' => 'is_customer_on_site', 'op' => 'eq',
                                     'value' => 'true'],
                ],
                [
                    'key' => 'customer_acceptance',
                    'label' => 'The customer accepts these findings',
                    'type' => 'checkbox',
                    'required' => true,
                    'print' => 'text',
                    'visible_if' => ['field' => 'is_customer_on_site', 'op' => 'eq',
                                     'value' => 'true'],
                ],
                [
                    'key' => 'technician_signature',
                    'label' => 'Technician signature',
                    'type' => 'signature',
                    'required' => true,
                ],
            ],
        ];
    }
}
