<?php

namespace App\Support\Structure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Yesterday's private pictures, and what becomes of them.
 *
 * The structure arrangement used to live in
 * `users.preferences['organization.canvas']`, one per person. There are real
 * ones in production. This runs once, at the migration that retires that key,
 * and it is a class rather than a body of code inside the migration so the
 * behaviour can be tested without pumping migrations up and down.
 *
 * ── Why "exactly one, or none" ───────────────────────────────────
 *
 * Because there is no truthful way to elect one colleague's private picture as
 * everybody's. Every heuristic considered — the most boxes, the most recently
 * updated row, the oldest account, the administrator — answers a question
 * nobody asked and produces a chart that one person recognises and the rest of
 * the office does not, with nothing on screen saying whose it was.
 *
 * Where only ONE person in an organization ever arranged anything, that
 * election does not happen: their picture is the organization's only picture,
 * so promoting it invents nothing and loses nothing. That is the overwhelmingly
 * common case, and it is the owner's own.
 *
 * Where two or more arranged, nothing is promoted and the tab opens on the
 * automatic layout. That is not data loss dressed up: the remedy is already on
 * screen and already explained — the note about boxes drawn where they belong
 * in the shape rather than where somebody would have put them, and one button
 * that tidies the whole picture correctly. And note the automatic layout is a
 * DIFFERENT layout as of this release, because the level rule was wrong: a
 * picture made by pressing "Arrange for me" yesterday encodes a franchise
 * sitting beside a sub company, which is the bug this release fixes. Preserving
 * it would preserve the thing being corrected.
 *
 * ── Idempotent ───────────────────────────────────────────────────
 *
 * An organization that already holds a shared arrangement is skipped, so a
 * re-run cannot overwrite an arrangement somebody has saved since. The key is
 * stripped from every user either way, including the ones whose arrangement was
 * promoted — it is dead the moment this returns, nothing reads it, and leaving
 * it would mean the out-clean has to be kept alive to keep it safe.
 *
 * ── Why every rule below is written out again ────────────────────
 *
 * The keys are consts here and the cleaning rules are reimplemented rather than
 * called out of OrganizationCanvasController, following
 * 2026_08_12_120000_grant_export_to_roles_that_already_view: a migration has to
 * keep doing what it did the day it ran, and application code moves. If the cap
 * is raised next year, or a fourth kind of box is invented, this file must
 * still do to yesterday's blobs exactly what it did the afternoon it was
 * deployed — otherwise a fresh install replaying the migration history produces
 * a different database from the one it is meant to reproduce.
 *
 * ── Why raw queries rather than the models ───────────────────────
 *
 * `User` and `Setting` both carry BelongsToTenant, which filters every read to
 * the current organization and refuses a write outside it. This walks EVERY
 * organization on the box and there is no tenant in context during a migration,
 * so going through the models would mean either opening and closing a tenant
 * context per organization or being refused. The query builder is also what
 * keeps this stable while the models move, which is the same argument as above.
 */
final class PromoteCanvasArrangements
{
    /** Where one person's arrangement used to live. */
    private const OLD_KEY = 'organization.canvas';

    /** The organization-wide setting it becomes. */
    private const NEW_KEY = 'organization.structure_canvas';

    /** §3's fixed depth, as it stood the day this ran. A sub company is a `company`. */
    private const KINDS = ['organization', 'company', 'franchise'];

    private const MAX_BOXES = 500;

    private const MAX_COORDINATE = 20000;

    /**
     * Run it, and say what it did.
     *
     * The counts are not decoration and not "in case somebody wants them": the
     * migration writes them to the log. This runs once, silently, against real
     * customer data during a deploy, and the difference between "promoted 12,
     * stripped 40" and "promoted 0, stripped 0" is the difference between a
     * migration that worked and one that quietly matched nothing — which is
     * exactly the failure a one-shot data migration has no other way of
     * reporting.
     *
     * @return array{promoted: int, stripped: int}
     */
    public function __invoke(): array
    {
        $promoted = 0;

        foreach ($this->organizationsWithUsers() as $organizationId) {
            $arrangers = $this->arrangersIn($organizationId);

            if ($this->promote($organizationId, $arrangers)) {
                $promoted++;
            }
        }

        $stripped = $this->stripTheOldKey();

        return ['promoted' => $promoted, 'stripped' => $stripped];
    }

    /**
     * Every organization that has a user at all.
     *
     * Read off `users` rather than off `organizations`, because a tenant with
     * nobody in it has nobody who could have arranged anything and there is
     * nothing to decide for it.
     *
     * @return list<string>
     */
    private function organizationsWithUsers(): array
    {
        return DB::table('users')
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * The people in one organization who actually arranged something.
     *
     * A stored key holding an empty list, or junk, is not an arrangement: it
     * is somebody who dragged a box and put it back, or a blob nothing can
     * read. Counting either would let a second "arranger" appear who never
     * arranged anything and block a promotion that should have happened.
     *
     * @return list<list<array{kind: string, id: string, x: int, y: int}>>
     */
    private function arrangersIn(string $organizationId): array
    {
        $found = [];

        $rows = DB::table('users')
            ->where('organization_id', $organizationId)
            ->whereNotNull('preferences')
            ->pluck('preferences', 'id');

        foreach ($rows as $raw) {
            $preferences = $this->decode($raw);
            $stored      = $preferences[self::OLD_KEY] ?? null;

            if (! is_array($stored)) {
                continue;
            }

            $boxes = $this->cleaned($stored);

            if ($boxes !== []) {
                $found[] = $boxes;
            }
        }

        return $found;
    }

    /**
     * Seed the organization's shared arrangement, when exactly one person had
     * one and nobody has saved a shared one since.
     *
     * @param  list<list<array{kind: string, id: string, x: int, y: int}>>  $arrangers
     */
    private function promote(string $organizationId, array $arrangers): bool
    {
        if (count($arrangers) !== 1) {
            return false;
        }

        // Already arranged since — leave it entirely alone. This is what makes
        // a re-run safe: the second run must not put yesterday's private
        // picture back over the one the office has been using.
        $already = DB::table('settings')
            ->where('scope_type', 'organization')
            ->where('scope_id', $organizationId)
            ->where('key', self::NEW_KEY)
            ->exists();

        if ($already) {
            return false;
        }

        DB::table('settings')->insert([
            'id'              => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'scope_type'      => 'organization',
            'scope_id'        => $organizationId,
            'key'             => self::NEW_KEY,
            // Encoded by hand because this is the query builder rather than the
            // model, so no `json` cast runs. JSON_THROW_ON_ERROR because a
            // silent `false` here would write the string "" into a json column
            // and fail at a distance.
            'value'           => json_encode($arrangers[0], JSON_THROW_ON_ERROR),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return true;
    }

    /**
     * Take the old key off every user, promoted or not.
     *
     * Rewritten key by key rather than assigned over: `preferences` also holds
     * the theme, the sidebar width and the saved scope, and a strip that took
     * somebody's theme with it would be a bug found days later by somebody who
     * never connected the two.
     *
     * A user whose blob does not contain the key is not written at all, so this
     * touches only the rows that need it and a re-run writes nothing.
     */
    private function stripTheOldKey(): int
    {
        $stripped = 0;

        DB::table('users')
            ->whereNotNull('preferences')
            ->orderBy('id')
            ->chunk(200, function ($users) use (&$stripped) {
                foreach ($users as $user) {
                    $preferences = $this->decode($user->preferences);

                    if (! array_key_exists(self::OLD_KEY, $preferences)) {
                        continue;
                    }

                    unset($preferences[self::OLD_KEY]);

                    DB::table('users')->where('id', $user->id)->update([
                        'preferences' => json_encode($preferences, JSON_THROW_ON_ERROR),
                    ]);

                    $stripped++;
                }
            });

        return $stripped;
    }

    /**
     * A stored `preferences` value as an array, whatever the driver handed back.
     *
     * sqlite and MySQL both return the column as a string here, but a driver
     * that decoded it would otherwise arrive as an array and be json_decode'd
     * into null — losing every arrangement on that engine, silently, which is
     * the one failure mode a migration must not have.
     *
     * @return array<string, mixed>
     */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The same reduction the canvas applies, frozen as it stood today.
     *
     * A blob written by an older build, restored from a backup or edited by
     * hand must not be promoted as-is into a row every colleague reads: the
     * client hands these numbers to a transform that throws on a value it
     * cannot use, and a bad promotion would be a screen nobody in that
     * organization could open.
     *
     * @param  array<mixed>  $boxes
     * @return list<array{kind: string, id: string, x: int, y: int}>
     */
    private function cleaned(array $boxes): array
    {
        $kept = [];

        foreach ($boxes as $box) {
            if (! is_array($box)) {
                continue;
            }

            $kind = $box['kind'] ?? null;
            $id   = $box['id'] ?? null;

            if (! is_string($kind) || ! in_array($kind, self::KINDS, strict: true)) {
                continue;
            }

            if (! is_string($id) || ! Str::isUuid($id)) {
                continue;
            }

            $x = $this->coordinate($box['x'] ?? null);
            $y = $this->coordinate($box['y'] ?? null);

            if ($x === null || $y === null) {
                continue;
            }

            // Last one wins, and the dedupe happens before the cap: six hundred
            // rows that are five hundred duplicates is a small arrangement
            // written badly, not an oversized one.
            $kept["{$kind}:{$id}"] = ['kind' => $kind, 'id' => $id, 'x' => $x, 'y' => $y];
        }

        return array_slice(array_values($kept), 0, self::MAX_BOXES);
    }

    /** Clamped and rounded; NaN cannot be clamped, so it drops the entry. */
    private function coordinate(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        if (is_nan($number)) {
            return null;
        }

        return (int) max(0, min(self::MAX_COORDINATE, round($number)));
    }
}
