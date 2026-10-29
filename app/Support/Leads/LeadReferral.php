<?php

namespace App\Support\Leads;

/** Where a lead came from, read from what the provider hands us (Jose, 2026-10-29). */
final class LeadReferral
{
    /**
     * A website visitor's page: its UTM tags, and the ad network a click id names.
     * Null when the page says nothing beyond itself.
     */
    public static function fromUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $tag = fn (string $k) => is_string($q[$k] ?? null) && trim($q[$k]) !== '' ? mb_substr(trim($q[$k]), 0, 120) : null;

        $source = $tag('utm_source') ?? match (true) {
            isset($q['gclid']) || isset($q['gbraid']) || isset($q['wbraid']) => 'Google Ads',
            isset($q['fbclid']) => 'Facebook',
            isset($q['msclkid']) => 'Microsoft Ads',
            default => null,
        };

        return self::clean([
            'source' => $source,
            'campaign_name' => $tag('utm_campaign'),
            'ad_name' => $tag('utm_content'),
            'keyword' => $tag('utm_term'),
            'page_url' => mb_substr($url, 0, 500),
        ], keep: $source !== null || $tag('utm_campaign') !== null);
    }

    /** Drops empty keys; null when nothing worth showing is left. */
    public static function clean(array $referral, bool $keep = true): ?array
    {
        $referral = array_filter($referral, fn ($v) => $v !== null && $v !== '');

        return $keep && $referral !== [] ? $referral : null;
    }
}
