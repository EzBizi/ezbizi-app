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
 */
class SeoFields
{
    /**
     * Key => [label, hint].
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function all(): array
    {
        return [
            'company_name' => [
                'label' => 'Company name',
                'hint' => 'The trading name this site answers under.',
            ],
            'phone' => [
                'label' => 'Phone number',
                'hint' => 'The number this site should show. Each subdomain can differ.',
            ],
            'email' => [
                'label' => 'Email address',
                'hint' => 'The address customers should write to.',
            ],
            'address' => [
                'label' => 'Address',
                'hint' => 'The address shown in the footer and on contact pages.',
            ],
            'hours' => [
                'label' => 'Hours of operation',
                'hint' => 'Written the way it should read on the page.',
            ],
            'license' => [
                'label' => 'License number',
                'hint' => 'The licence this site must display.',
            ],
            'service_area' => [
                'label' => 'Service area',
                'hint' => 'The towns or counties this site covers.',
            ],
            'booking_url' => [
                'label' => 'Booking link',
                'hint' => 'Where a "Book now" button should go.',
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
                'shortcode' => '[ezbizi_'.$key.']',
            ],
            self::keys(),
            array_values(self::all()),
        );
    }
}
