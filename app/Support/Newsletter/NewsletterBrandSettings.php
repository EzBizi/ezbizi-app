<?php

namespace App\Support\Newsletter;

/**
 * A newsletter brand's settings (P1b, 2026-11-18), read the one way the
 * editor, the API and the renderer all agree on. Anything unrecognised is
 * dropped rather than stored, so the renderer only ever sees values it can
 * write safely.
 */
final class NewsletterBrandSettings
{
    /** Email-safe stacks only: every one falls back to a font every client has. */
    public const FONTS = [
        'system' => "system-ui,-apple-system,'Segoe UI',sans-serif",
        'arial' => 'Arial,Helvetica,sans-serif',
        'verdana' => 'Verdana,Geneva,sans-serif',
        'georgia' => "Georgia,'Times New Roman',serif",
        'trebuchet' => "'Trebuchet MS',Tahoma,sans-serif",
    ];

    private const COLORS = ['primary', 'text', 'background', 'link', 'button_fill', 'button_text'];

    /**
     * @return array<string, mixed>|null null when there is nothing to apply
     */
    public static function clean(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (self::COLORS as $key) {
            if (is_string($raw[$key] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $raw[$key])) {
                $out[$key] = strtoupper($raw[$key]);
            }
        }
        if (is_string($raw['font'] ?? null) && isset(self::FONTS[$raw['font']])) {
            $out['font'] = $raw['font'];
        }
        if (isset($raw['button_radius']) && is_numeric($raw['button_radius'])) {
            $out['button_radius'] = max(0, min(40, (int) $raw['button_radius']));
        }
        if (is_bool($raw['button_full_width'] ?? null)) {
            $out['button_full_width'] = $raw['button_full_width'];
        }
        foreach (['logo_asset_id' => 64, 'logo_url' => 2048, 'logo_alt' => 255, 'footer_note' => 500] as $key => $max) {
            if (is_string($raw[$key] ?? null) && trim($raw[$key]) !== '') {
                $out[$key] = mb_substr(trim($raw[$key]), 0, $max);
            }
        }
        if (isset($out['logo_url']) && strtolower((string) parse_url($out['logo_url'], PHP_URL_SCHEME)) !== 'https') {
            unset($out['logo_url']);
        }

        return $out === [] ? null : $out;
    }

    public static function fontStack(mixed $key): string
    {
        return self::FONTS[is_string($key) && isset(self::FONTS[$key]) ? $key : 'system'];
    }
}
