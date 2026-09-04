<?php

namespace App\Support\Web;

/**
 * The business facts a site can publish, and the only keys the API will store.
 *
 * ── Why a fixed catalogue and not free-form keys ──
 *
 * A value is only useful once somebody can name it from a template, so every
 * key here becomes a shortcode (`[ezbizi_phone]`) and a documented widget. A
 * user-invented key would be a shortcode nobody wrote documentation for, and
 * a typo in one would render as an empty string on a live website with no way
 * to tell that apart from a value nobody has filled in yet.
 *
 * Adding a field is one line here. There is no migration behind it, because
 * `web_values` stores key/value rows rather than a column per fact — which is
 * the whole reason the table is shaped that way.
 *
 * ── Why each key says whether blank is a problem ──
 *
 * A blank `phone` and a blank `license` are OPPOSITE facts. One is a setup
 * somebody has not finished; the other is the finished answer for a trade or a
 * state that licenses nothing. Stored, they are the same thing — an absent row
 * — so without this property any empty treatment on the screen is wrong for one
 * of them: quiet-and-grey lies about the missing phone number, and
 * amber-and-prompting nags forever about a licence that is never coming, which
 * is how somebody learns to ignore the whole screen.
 *
 * The user never sets this. The CATALOGUE asserts it, which is why it sits
 * beside `label` and `hint` rather than in a column.
 *
 *   REQUIRED     blank means unfinished; say so
 *   CONDITIONAL  blank is legitimate, but common enough to offer once, quietly
 *   OPTIONAL     blank is legitimate; say nothing at all
 *
 * THE SPLIT BELOW IS REASONING, NOT USAGE DATA, and is meant to be corrected.
 * No customer has filled in more than two of these fields, so every assignment
 * here is a guess about what a trade business publishes. It is one line per key
 * precisely so being wrong costs a line rather than a migration.
 */
class SeoFields
{
    public const REQUIRED = 'required';

    public const CONDITIONAL = 'conditional';

    public const OPTIONAL = 'optional';

    /**
     * Key => [label, hint, requirement].
     *
     * @return array<string, array{label: string, hint: string, requirement: string}>
     */
    public static function all(): array
    {
        return [
            'company_name' => [
                'label' => 'Company name',
                'hint' => 'The trading name this site answers under.',
                // A site publishing no company name is not finished.
                'requirement' => self::REQUIRED,
            ],
            'phone' => [
                'label' => 'Phone number',
                'hint' => 'The number this site should show. Each subdomain can differ.',
                // The number is the reason this module exists.
                'requirement' => self::REQUIRED,
            ],
            'email' => [
                'label' => 'Email address',
                'hint' => 'The address customers should write to.',
                // Plenty of trades take calls and no email.
                'requirement' => self::CONDITIONAL,
            ],
            /*
             * The address is FIVE fields and one derived line.
             *
             * It started as one string, which was the outlier in this codebase:
             * Customers and Jobs already store address_line1, address_line2,
             * city, state and postal_code, and the marketing site's JSON-LD wants
             * exactly those parts. So the parts are the truth and `address` is
             * composed from them by ValueResolver — a site that set only the
             * old single string keeps publishing it, and one that fills the parts
             * gets the line built for it. Nothing that used `[ezbizi_address]`
             * changes.
             */
            'address_line1' => [
                'label' => 'Street address',
                'hint' => 'Number and street.',
                'requirement' => self::REQUIRED,
            ],
            'address_line2' => [
                'label' => 'Suite or unit',
                'hint' => 'Only if there is one.',
                'requirement' => self::OPTIONAL,
            ],
            'city' => [
                'label' => 'City',
                'hint' => '',
                'requirement' => self::REQUIRED,
            ],
            'state' => [
                'label' => 'State',
                'hint' => 'Two letters.',
                'requirement' => self::REQUIRED,
            ],
            'postal_code' => [
                'label' => 'ZIP',
                'hint' => '',
                'requirement' => self::REQUIRED,
            ],
            'address' => [
                'label' => 'Address, one line',
                'hint' => 'Built from the parts above. This is what the footer renders.',
                // Derived when the parts exist; a plain string on sites that
                // pre-date the parts.
                'requirement' => self::REQUIRED,
            ],
            'hours' => [
                'label' => 'Hours of operation',
                'hint' => 'Written the way it should read on the page.',
                // An appointment-only business has none worth publishing.
                'requirement' => self::CONDITIONAL,
            ],
            'license' => [
                'label' => 'License number',
                'hint' => 'The licence this site must display.',
                // Required in some trades and states, absent in others.
                'requirement' => self::CONDITIONAL,
            ],
            'service_area' => [
                'label' => 'Service area',
                'hint' => 'The towns or counties this site covers.',
                // Nice to publish, and no site is worse without it.
                'requirement' => self::OPTIONAL,
            ],
            'booking_url' => [
                'label' => 'Booking link',
                'hint' => 'Where a "Book now" button should go.',
                // Most companies take no online bookings at all.
                'requirement' => self::OPTIONAL,
            ],
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * The catalogue as the client and the install guide need it, shortcode included.
     *
     * The shortcode name is derived rather than declared so the two can never
     * disagree — the plugin registers `'ezbizi_'.$key` by the same rule.
     *
     * @return array<int, array{key: string, label: string, hint: string, shortcode: string}>
     */
    public static function catalogue(): array
    {
        return array_map(
            fn (string $key, array $field) => [
                'key' => $key,
                'label' => $field['label'],
                'hint' => $field['hint'],
                'requirement' => $field['requirement'],
                'shortcode' => '[ezbizi_'.$key.']',
            ],
            self::keys(),
            array_values(self::all()),
        );
    }
}
