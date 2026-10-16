<?php

namespace App\Support\Web\Hours;

use Illuminate\Database\Eloquent\Model;

/**
 * Somewhere weekly hours are published, and can be rewritten.
 *
 * The bulk editor names its destinations explicitly — "no target should be
 * assumed silently" — and each one reads its own live week, so a review is
 * always against what that destination holds right now.
 *
 * A destination owns one kind of place: a website for the website's hours, a
 * Business Profile location for Google's (Jose, 2026-10-16: locations are
 * chosen as locations, not by the website they happen to name).
 */
interface HoursDestination
{
    /** The key the request names it by. */
    public function key(): string;

    public function label(): string;

    /** The model class of the places it writes. */
    public function placeClass(): string;

    /** A short name for a place, as the review lists it. */
    public function placeName(Model $place): string;

    /** Why this place cannot be written, or null when it can. */
    public function unavailable(Model $place): ?string;

    /** The IANA zone the place's hours are kept in, or '' when it has none. */
    public function zone(Model $place): string;

    /**
     * The live week, Monday first, and a fingerprint that changes whenever the
     * week, or anything that would change how an edit lands, changes.
     *
     * @return array{week: ?array, fingerprint: string}
     */
    public function read(Model $place): array;

    /**
     * @param  array<int, array{closed: bool, open: string, close: string}>  $week
     *
     * @throws HoursRefused when the destination answered no: nothing was written
     */
    public function write(Model $place, array $week): void;
}
