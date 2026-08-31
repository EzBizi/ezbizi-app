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
                ['key' => 'first_name', 'label' => 'First name', 'type' => 'text',
                 'bind' => 'customer.name', 'print' => 'text'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'print' => 'text'],
                ['key' => 'primary_phone', 'label' => 'Phone', 'type' => 'phone', 'print' => 'text'],

                ['type' => 'divider', 'label' => 'Address'],
                ['key' => 'address_line_1', 'label' => 'Address', 'type' => 'text', 'print' => 'text'],
                ['key' => 'address_line_2', 'label' => 'Address line 2', 'type' => 'text',
                 'print' => 'text'],
                ['key' => 'city', 'label' => 'City', 'type' => 'text', 'print' => 'text'],
                ['key' => 'state', 'label' => 'State', 'type' => 'text', 'print' => 'text'],
                ['key' => 'zip_code', 'label' => 'ZIP code', 'type' => 'text', 'print' => 'text'],

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

    /** @param array<string, string> $codes */
    private static function systemScreen(array $codes): array
    {
        return [
            'name' => 'The system',
            'fields' => [
                [
                    'key' => 'system_type',
                    'label' => 'System type',
                    'type' => 'choice',
                    'required' => true,
                    // Answered to steer eleven branches; printing "masonry"
                    // eleven times tells the customer nothing.
                    'print' => 'ignore',
                    'options' => [
                        ['value' => 'masonry', 'label' => 'Masonry fireplace'],
                        ['value' => 'built-in', 'label' => 'Built-in / gas fireplace'],
                        ['value' => 'wood', 'label' => 'Wood stove or insert'],
                        ['value' => 'furnace', 'label' => 'Furnace or boiler'],
                        ['value' => 'electric', 'label' => 'Electric fireplace'],
                    ],
                ],
                ['key' => 'system_image', 'label' => 'Photograph of the system', 'type' => 'photo',
                 'max_count' => 4],

                ['type' => 'divider', 'label' => 'Dimensions',
                 'visible_if' => self::forSystems('masonry', 'built-in')],
                ['key' => 'dimensions_width', 'label' => 'Width (inches)', 'type' => 'number',
                 'print' => 'text', 'visible_if' => self::forSystems('masonry', 'built-in')],
                ['key' => 'dimensions_height', 'label' => 'Height (inches)', 'type' => 'number',
                 'print' => 'text', 'visible_if' => self::forSystems('masonry', 'built-in')],
                ['key' => 'dimensions_depth', 'label' => 'Depth (inches)', 'type' => 'number',
                 'print' => 'text', 'visible_if' => self::forSystems('masonry', 'built-in'),
                 'code_id' => $codes['R1001.6'] ?? null],

                ['type' => 'divider', 'label' => 'Model',
                 'visible_if' => self::forSystems('built-in', 'wood', 'furnace', 'electric')],
                ['key' => 'model_brand', 'label' => 'Brand', 'type' => 'text', 'print' => 'text',
                 'visible_if' => self::forSystems('built-in', 'wood', 'furnace', 'electric')],
                ['key' => 'model_number', 'label' => 'Model number', 'type' => 'text',
                 'print' => 'text',
                 'visible_if' => self::forSystems('built-in', 'wood', 'furnace', 'electric')],
                ['key' => 'model_image', 'label' => 'Photograph of the plate', 'type' => 'photo',
                 'visible_if' => self::forSystems('built-in', 'wood', 'furnace', 'electric')],

                // ── masonry ──
                ['type' => 'divider', 'label' => 'Masonry fireplace',
                 'visible_if' => self::forSystems('masonry')],
                self::finding('masonry_clearance_to_combustible', 'Clearance to combustibles',
                    extra: ['visible_if' => self::forSystems('masonry')]),
                self::finding('masonry_firebox_condition', 'Firebox condition',
                    extra: ['visible_if' => self::forSystems('masonry')]),
                self::finding('masonry_damper', 'Damper',
                    naLabel: 'No damper fitted',
                    extra: ['visible_if' => self::forSystems('masonry')]),
                self::finding('masonry_smoke_chamber', 'Smoke chamber',
                    extra: ['visible_if' => self::forSystems('masonry')]),
                self::finding('masonry_soot_condition', 'Soot condition',
                    codeId: $codes['NFPA211'] ?? null,
                    okLabel: 'Clean', failLabel: 'Needs sweeping',
                    extra: ['visible_if' => self::forSystems('masonry')]),
                self::finding('masonry_chimney_liner', 'Chimney liner',
                    codeId: $codes['R1003.11'] ?? null,
                    extra: ['visible_if' => self::forSystems('masonry')]),

                // ── built-in / gas ──
                ['type' => 'divider', 'label' => 'Built-in fireplace',
                 'visible_if' => self::forSystems('built-in')],
                self::finding('built_in_clearance_to_combustibles', 'Clearance to combustibles',
                    extra: ['visible_if' => self::forSystems('built-in')]),
                self::finding('built_in_refractory_panels_condition', 'Refractory panels',
                    extra: ['visible_if' => self::forSystems('built-in')]),
                self::finding('built_in_glass_door_condition', 'Glass door',
                    naLabel: 'No glass door',
                    extra: ['visible_if' => self::forSystems('built-in')]),
                [
                    'key' => 'built_in_gas_connection',
                    'label' => 'Gas connection',
                    'type' => 'choice',
                    'print' => 'ignore',
                    'visible_if' => self::forSystems('built-in'),
                    'options' => [
                        ['value' => 'yes', 'label' => 'Connected to gas'],
                        ['value' => 'no', 'label' => 'No gas connection'],
                    ],
                ],
                self::finding('built_in_gas_valve_condition', 'Gas valve',
                    extra: ['visible_if' => ['field' => 'built_in_gas_connection',
                                             'op' => 'eq', 'value' => 'yes']]),
                self::finding('built_in_gas_pilot_light_condition', 'Pilot light',
                    extra: ['visible_if' => ['field' => 'built_in_gas_connection',
                                             'op' => 'eq', 'value' => 'yes']]),
                self::finding('built_in_chimney_liner_condition', 'Chimney liner',
                    codeId: $codes['R1003.11'] ?? null,
                    extra: ['visible_if' => self::forSystems('built-in')]),

                // ── wood stove ──
                ['type' => 'divider', 'label' => 'Wood stove',
                 'visible_if' => self::forSystems('wood')],
                [
                    'key' => 'wood_stove_type',
                    'label' => 'Stove type',
                    'type' => 'choice',
                    'print' => 'text',
                    'visible_if' => self::forSystems('wood'),
                    'options' => [
                        ['value' => 'insert', 'label' => 'Insert'],
                        ['value' => 'freestanding', 'label' => 'Freestanding'],
                    ],
                ],
                self::finding('wood_clearance_to_combustible', 'Clearance to combustibles',
                    extra: ['visible_if' => self::forSystems('wood')]),
                self::finding('wood_stove_condition', 'Stove condition',
                    extra: ['visible_if' => self::forSystems('wood')]),
                self::finding('wood_pipe_connection', 'Pipe connection',
                    extra: ['visible_if' => self::forSystems('wood')]),
                self::finding('wood_soot_condition', 'Soot condition',
                    codeId: $codes['NFPA211'] ?? null,
                    okLabel: 'Clean', failLabel: 'Needs sweeping',
                    extra: ['visible_if' => self::forSystems('wood')]),
                self::finding('wood_chimney_liner', 'Chimney liner',
                    codeId: $codes['R1003.11'] ?? null,
                    extra: ['visible_if' => self::forSystems('wood')]),

                // ── furnace / boiler ──
                ['type' => 'divider', 'label' => 'Furnace or boiler',
                 'visible_if' => self::forSystems('furnace')],
                [
                    'key' => 'furnace_burning_type',
                    'label' => 'Fuel',
                    'type' => 'choice',
                    'print' => 'text',
                    'visible_if' => self::forSystems('furnace'),
                    'options' => [
                        ['value' => 'gas', 'label' => 'Gas'],
                        ['value' => 'oil', 'label' => 'Oil'],
                    ],
                ],
                self::finding('furnace_clearance_to_combustibles', 'Clearance to combustibles',
                    extra: ['visible_if' => self::forSystems('furnace')]),
                [
                    'key' => 'furnace_venting_piping_type',
                    'label' => 'Vent pipe type',
                    'type' => 'choice',
                    'print' => 'text',
                    'visible_if' => self::forSystems('furnace'),
                    'options' => [
                        ['value' => 'b-vent', 'label' => 'Type B vent'],
                        ['value' => 'l-vent', 'label' => 'Type L vent'],
                        ['value' => 'direct-vent', 'label' => 'Direct vent'],
                        ['value' => 'single-wall', 'label' => 'Single wall'],
                    ],
                ],
                [
                    'type' => 'alert',
                    'title' => 'M1803.3.4 CLEARANCE',
                    'body' => 'Type B vents shall be installed with a minimum clearance of '
                        .'1 inch (25 mm) from combustible material, in accordance with the '
                        .'manufacturer\'s installation instructions.',
                    'visible_if' => ['field' => 'furnace_venting_piping_type',
                                     'op' => 'eq', 'value' => 'b-vent'],
                ],
                self::finding('furnace_venting_pipes_condition', 'Vent pipe condition',
                    extra: ['visible_if' => self::forSystems('furnace')]),
                self::finding('furnace_venting_chimney_liner', 'Chimney liner',
                    codeId: $codes['R1003.11'] ?? null,
                    extra: ['visible_if' => self::forSystems('furnace')]),

                // ── electric ──
                ['type' => 'divider', 'label' => 'Electric fireplace',
                 'visible_if' => self::forSystems('electric')],
                [
                    'key' => 'electric_is_fireplace_working',
                    'label' => 'Is it working?',
                    'type' => 'choice',
                    'print' => 'text',
                    'visible_if' => self::forSystems('electric'),
                    'options' => [
                        ['value' => 'yes', 'label' => 'Working'],
                        ['value' => 'no', 'label' => 'Not working'],
                    ],
                ],
                self::finding('electric_fireplace_condition', 'Fireplace condition',
                    extra: ['visible_if' => self::forSystems('electric')]),
            ],
        ];
    }

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

                self::finding('chimney_height_from_roof_line', 'Height above the roof line',
                    codeId: $codes['R1003.9'] ?? null),
                self::finding('chimney_cricket', 'Chimney cricket',
                    codeId: $codes['R1003.20'] ?? null,
                    naLabel: 'Does not need a cricket'),
                self::finding('flashing_condition', 'Flashing',
                    codeId: $codes['R903.2'] ?? null),
                self::finding('chimney_siding_cover_condition', 'Siding or cover',
                    naLabel: 'Not applicable'),
                self::finding('chase_covering_condition', 'Chase cover',
                    naLabel: 'No chase'),
                self::finding('exterior_pipe_condition', 'Exterior pipe',
                    okLabel: 'Sealed and connected',
                    failLabel: 'Not sealed or not connected',
                    naLabel: 'No exterior pipe'),
                self::finding('masonry_work_condition', 'Masonry condition',
                    codeId: $codes['1926.706(b)'] ?? null),
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
                self::finding('chimney_crown_condition', 'Chimney crown',
                    codeId: $codes['R1003.9'] ?? null,
                    naLabel: 'No crown'),
                self::finding('chimney_rain_cap', 'Rain cap',
                    naLabel: 'No rain cap fitted'),
                self::finding('chimney_spark_arrestor', 'Spark arrestor',
                    naLabel: 'No spark arrestor fitted'),
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
