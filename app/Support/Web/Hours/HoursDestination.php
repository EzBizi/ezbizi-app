<?php

namespace App\Support\Web\Hours;

use App\Models\WebSite;

/**
 * Somewhere a website's weekly hours are published, and can be rewritten.
 *
 * The bulk editor names its destinations explicitly — the brief's "no target
 * should be assumed silently" — and each one reads its own live week, so a
 * review is always against what that destination holds right now.
 */
interface HoursDestination
{
    /** The key the request names it by. */
    public function key(): string;

    public function label(): string;

    /** Why this destination cannot be written for this site, or null when it can. */
    public function unavailable(WebSite $site): ?string;

    /**
     * The live week, Monday first, and a fingerprint that changes whenever the
     * week, or anything that would change how an edit lands, changes.
     *
     * @return array{week: ?array, fingerprint: string}
     */
    public function read(WebSite $site): array;

    /** @param  array<int, array{closed: bool, open: string, close: string}>  $week */
    public function write(WebSite $site, array $week): void;
}
