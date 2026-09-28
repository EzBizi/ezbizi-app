<?php

namespace App\Support\Web\Hours;

use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Web\HoursWeek;
use App\Support\Web\ValueResolver;

/**
 * The hours a website publishes: its own `hours` value, or the primary site's
 * when it has none. Written as its own value, in the format the SEO grid
 * writes, so the grid, `[ezbizi_hours]` and `[ezbizi_hours_table]` all read it.
 */
final class WebsiteHours implements HoursDestination
{
    public const KEY = 'website';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Website';
    }

    public function unavailable(WebSite $site): ?string
    {
        return null;
    }

    public function read(WebSite $site): array
    {
        $text = $this->effective($site, 'hours');
        $week = $text === '' ? null : HoursWeek::parse($text);

        return [
            // Without the day names parse adds, so it compares with a planned week.
            'week' => $week === null ? null : array_map(fn (array $d) => ['closed' => $d['closed'], 'open' => $d['open'], 'close' => $d['close']], $week),
            // The zone is in it: a review converted for one zone must not land in another.
            'fingerprint' => sha1($text."\n".$this->effective($site, 'timezone')),
        ];
    }

    public function write(WebSite $site, array $week): void
    {
        WebValue::query()->updateOrCreate(
            ['site_id' => $site->getKey(), 'path' => '', 'key' => 'hours'],
            ['value' => HoursWeek::format($week)],
        );
    }

    /** This site's own value for a key, or the primary site's. */
    public function effective(WebSite $site, string $key): string
    {
        $own = WebValue::query()->where('site_id', $site->getKey())->where('path', '')->where('key', $key)->value('value');

        if (is_string($own) && trim($own) !== '') {
            return trim($own);
        }

        $inherited = ValueResolver::inheritedRowsFor($site)->firstWhere('key', $key);

        return trim((string) ($inherited?->value ?? ''));
    }
}
