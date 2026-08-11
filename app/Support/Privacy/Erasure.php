<?php

namespace App\Support\Privacy;

use InvalidArgumentException;

/**
 * What a purge does to one declared field of personal data (§9).
 *
 * ── Why this hangs off the inventory entry ───────────────────────
 *
 * §9 puts "purge personal data — anonymise in place" in the build-now list and
 * says it "must touch every table holding personal data". The obvious way to
 * build that is a list of tables and columns inside the purge. That list is
 * exactly what the data inventory exists to make unnecessary: it is a second
 * enumeration of the same fact, it is invisible when it goes stale, and the way
 * it goes stale is somebody adding a personal-data column and not thinking about
 * erasure — which is the same person, in the same file, who did remember to add
 * an inventory entry because the interface made them.
 *
 * So erasure is not a list. It is one more thing each entry says about itself,
 * beside what the field is and why it is held. The purge is then derived: walk
 * the declarations, do what each one says. Adding a personal-data column without
 * deciding what erasure does to it is impossible, because the entry does not
 * construct without this.
 *
 * ── Three dispositions, because there are three real answers ─────
 *
 * `erase()`   the data goes and the row stays. §9's own resolution — "keep the
 *             invoice and its amounts, blank the name, email, phone and address".
 *
 * `keep()`    the data survives a purge, and the reason is published. Required
 *             to be stated, because this is the one that has to be defended: a
 *             signature's `signer_label` is captured verbatim precisely so it
 *             outlives the account, and you cannot erase your way out of having
 *             signed something.
 *
 * `drop()`    the row itself goes. For rows that are nothing but the person — a
 *             session, a notification addressed to them — where blanking the
 *             columns would leave a meaningless row rather than an anonymous one.
 *
 * ── There is only one overwrite, and it has two modes ────────────
 *
 * An earlier shape of this had `blank()` and `replace('Removed')` as separate
 * dispositions, and every model then had to know which of its own columns were
 * nullable in order to pick. That is a copy of the schema written in PHP, kept by
 * hand, and wrong the first time a migration makes a column nullable.
 *
 * `erase()` with NO argument means "empty it": NULL wherever the column permits
 * it, because absent is the better erasure — there is nothing left to mistake for
 * a real value — and a short stand-in only where the schema forbids NULL and
 * something has to be written.
 *
 * `erase('...')` with an argument means "make it say this", and it wins over the
 * schema. That is not a redundant second mode, and `audit_events.actor_label` is
 * why: the column IS nullable, and a null there is silently ambiguous — the log
 * already writes 'system' for an action nobody took, so an empty label reads as a
 * rendering fault rather than as a deliberate removal (R8). Where a column is
 * displayed, what it says after an erasure is a product decision, and the model
 * is where product decisions about a column belong.
 *
 * `{ref}` inside a stand-in is replaced with the ROW's own primary key. It exists
 * for `users.email`, which is uniquely indexed per organization: two people
 * erased on the same day would collide on a constant, and the purge would fail on
 * the second one — part-way through, with earlier tables already written. The
 * reference identifies the ROW, never the person; it is the same uuid that was
 * already sitting in the id column.
 */
final class Erasure
{
    public const ERASE = 'erase';
    public const KEEP  = 'keep';
    public const DROP  = 'drop';

    /** What a NOT NULL column gets when the model asked for nothing in particular. */
    public const DEFAULT_STAND_IN = 'Removed';

    private function __construct(
        public readonly string $disposition,
        /**
         * What to write, or NULL for "empty it".
         *
         * NULL here is the model declining to choose, not a value: the column is
         * emptied where the schema allows and gets DEFAULT_STAND_IN where it does
         * not. A non-null value is written regardless of nullability.
         */
        public readonly ?string $standIn = null,
        /** Why this survives, or why the row cannot survive without it. Published. */
        public readonly ?string $because = null,
    ) {}

    /**
     * The data goes; the row stays and keeps referring to what it referred to.
     *
     * Pass nothing to empty the columns. Pass a string only where what the column
     * SAYS afterwards matters — because it is displayed, or because it is NOT
     * NULL and uniquely indexed and needs `{ref}`.
     */
    public static function erase(?string $standIn = null): self
    {
        if ($standIn !== null && trim($standIn) === '') {
            throw new InvalidArgumentException(
                'An empty string is not a stand-in, it is a blank where a name used to be — '
                .'which reads on screen as data that failed to load rather than data that '
                .'was deliberately removed. Pass nothing at all to empty the column.'
            );
        }

        return new self(self::ERASE, standIn: $standIn);
    }

    /** True where the model chose the text, so the schema does not get a vote. */
    public function hasStatedStandIn(): bool
    {
        return $this->standIn !== null;
    }

    /**
     * This survives a purge, and here is why.
     *
     * The reason is mandatory and is published in the inventory, because a `keep`
     * is the entry somebody will one day be asked to justify. "It was easier" is
     * not available; the sentence has to be written at the moment the decision is
     * made, by the person who has the reason in their head.
     */
    public static function keep(string $because): self
    {
        if (trim($because) === '') {
            throw new InvalidArgumentException(
                'Personal data that survives erasure has to say why. An unexplained keep is '
                .'indistinguishable from an oversight, and it is the one entry in the '
                .'inventory that will be challenged.'
            );
        }

        return new self(self::KEEP, because: $because);
    }

    /**
     * The row goes entirely.
     *
     * Declared on the entry rather than on the model because it is a statement
     * about THIS data: it cannot be separated from the row that holds it. A model
     * with any `drop` entry has its matching rows deleted and its other entries
     * never applied — the Anonymiser checks for it first — so a model must not
     * mix `drop` with `erase`, and
     * PersonalDataPurgeTest::test_no_model_both_drops_its_rows_and_blanks_their_columns
     * fails the build if one does.
     */
    public static function drop(string $because): self
    {
        if (trim($because) === '') {
            throw new InvalidArgumentException(
                'Removing a row rather than anonymising it is the more destructive choice '
                .'and needs the more careful sentence: §9 resolves the soft-delete tension '
                .'in favour of keeping records and blanking them, so departing from that has '
                .'to be argued.'
            );
        }

        return new self(self::DROP, because: $because);
    }

    /**
     * The text to write, with `{ref}` resolved against this row.
     *
     * THE WHOLE KEY, not a readable slice of it.
     *
     * This shortened the uuid to twelve characters, because a full one in an
     * address is ugly. Primary keys here are UUIDv7, whose leading 48 bits are
     * the millisecond the row was created — so twelve characters is eleven hex
     * digits of a TIMESTAMP, and any two accounts created within the same
     * sixteen-millisecond window produce the same reference. Two people erased in
     * one test collided on `users_organization_id_email_unique`, and they
     * collided AFTER earlier tables had already been written, which is the worst
     * place to discover it.
     *
     * Uniqueness is the only reason this placeholder exists. Trading it for a
     * shorter string was trading away the entire feature.
     */
    public function standInFor(string $rowKey): string
    {
        return str_replace('{ref}', $rowKey, $this->standIn ?? self::DEFAULT_STAND_IN);
    }

    public function toArray(): array
    {
        return array_filter([
            'disposition' => $this->disposition,
            'stand_in'    => $this->standIn,
            'because'     => $this->because,
        ], fn ($v) => $v !== null);
    }
}
