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

    public const GENERAL = 'general';

    public const SOCIAL = 'social';

    /**
     * The sections, in the order the screen shows them.
     *
     * Sent to the client rather than mirrored there, so adding a section is
     * still one line in this file and nothing else — the same bargain the
     * catalogue itself makes.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function sections(): array
    {
        return [
            ['key' => self::GENERAL, 'label' => 'General'],
            ['key' => self::SOCIAL, 'label' => 'Social links'],
        ];
    }

    /**
     * Key => [label, hint, requirement].
     *
     * @return array<string, array{label: string, hint: string, requirement: string}>
     */
    public static function all(): array
    {
        return array_merge(self::general(), self::social());
    }

    /**
     * @return array<string, array{label: string, hint: string, requirement: string, section: string}>
     */
    private static function general(): array
    {
        return self::sectioned(self::GENERAL, [
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
            'country' => [
                'label' => 'Country',
                'hint' => 'Only needed if the site serves outside the United States.',
                /*
                 * Not part of the one-line address, deliberately: an American
                 * business printing "United States" in its own footer reads
                 * like a form somebody filled in badly. It is here because
                 * JSON-LD addressCountry wants it and a site that trades across
                 * a border needs to say which one.
                 */
                'requirement' => self::OPTIONAL,
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
            /*
             * Jose, 2026-09-16. Four he asked for by name; three are published
             * facts like the rest, and the fourth is the one people paste
             * wrong, which is why it says so in the hint.
             */
            'maps_url' => [
                'label' => 'Google Maps link',
                'hint' => 'The "Share" link from your Google listing, not the address bar.',
                // A business with no Google listing yet has nothing to point at.
                'requirement' => self::OPTIONAL,
            ],
            'timezone' => [
                'label' => 'Time zone',
                'hint' => 'Where this site\'s hours are local to, e.g. America/New_York.',
                /*
                 * Optional rather than required even though every site has
                 * one: it earns its place next to the hours, and a site that
                 * publishes hours without it is not wrong, only local.
                 */
                'requirement' => self::OPTIONAL,
            ],
            'tagline' => [
                'label' => 'Tagline',
                'hint' => 'The line that sits under the name. Yours, not a description of the trade.',
                'requirement' => self::OPTIONAL,
            ],
            /*
             * Three that earn their place on a PAGE rather than on a site.
             *
             * They are in the same catalogue as everything else because the
             * plugin turns a catalogue key into a shortcode and there is no
             * second mechanism — a page-only catalogue would be a second list
             * to keep in step, and the first thing to fall out of step is the
             * one nobody renders.
             *
             * Author and category are CHOSEN from the website's own lists
             * rather than typed, and stored here. Nothing is written back to
             * WordPress: the account we hold could set a post's author and
             * deliberately does not.
             */
            'group' => [
                'label' => 'Group',
                'hint' => 'Your own label for organising pages. Usually set per page.',
                'requirement' => self::OPTIONAL,
            ],
            'author' => [
                'label' => 'Author',
                'hint' => 'Chosen from the people on this website. Usually set per page.',
                'requirement' => self::OPTIONAL,
            ],
            'category' => [
                'label' => 'Category',
                'hint' => 'Chosen from this website\'s categories. Usually set per page.',
                'requirement' => self::OPTIONAL,
            ],
        ]);
    }

    /**
     * Where the business already exists, so a site can link to it.
     *
     * ── Why these are values and not a new table ──
     *
     * A social link is a business fact with a shortcode, which is what this
     * catalogue already is. Storing them as `web_values` rows means the primary
     * site answers for a branch that has not set its own, the shortcode is
     * derived, and the editor draws them — none of which is code anybody writes
     * again. A `web_social_links` table would have bought a per-network column
     * and re-implemented all three.
     *
     * Every one is OPTIONAL. A trade business with no TikTok is not an
     * unfinished site, and a required badge on fifteen networks would make the
     * badge mean nothing on the fields where it does.
     *
     * @return array<string, array{label: string, hint: string, requirement: string, section: string}>
     */
    private static function social(): array
    {
        $networks = [
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'youtube' => 'YouTube',
            'twitter' => 'Twitter / X',
            'linkedin' => 'LinkedIn',
            'tiktok' => 'TikTok',
            'yelp' => 'Yelp',
            'google_business' => 'Google Business',
            'pinterest' => 'Pinterest',
            'bbb' => 'BBB',
            'nextdoor' => 'Nextdoor',
            'houzz' => 'Houzz',
            'angi' => 'Angi',
            'thumbtack' => 'Thumbtack',
            'homeadvisor' => 'HomeAdvisor',
        ];

        $fields = [];

        foreach ($networks as $key => $label) {
            $fields[$key] = [
                'label' => $label,
                'hint' => 'The full link to the company page on '.$label.'.',
                'requirement' => self::OPTIONAL,
            ];
        }

        return self::sectioned(self::SOCIAL, $fields);
    }

    /**
     * Stamp a section onto a block of fields.
     *
     * @param  array<string, array{label: string, hint: string, requirement: string}>  $fields
     * @return array<string, array{label: string, hint: string, requirement: string, section: string}>
     */
    private static function sectioned(string $section, array $fields): array
    {
        return array_map(
            fn (array $field) => $field + ['section' => $section],
            $fields,
        );
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
                'section' => $field['section'],
                'shortcode' => '[ezbizi_'.$key.']',
            ],
            self::keys(),
            array_values(self::all()),
        );
    }
}
