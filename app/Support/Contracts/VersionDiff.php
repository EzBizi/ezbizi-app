<?php

namespace App\Support\Contracts;

/**
 * What publishing this version actually changes
 * (EZBIZI-HR-PLAN.md: "Publishing a new version must be an explicit act with a
 * diff a person can read: what was added, what was retired, what changed").
 *
 * ── Why a diff, and not just a version number ────────────────────
 *
 * A contract template is the schema for onboarding AND the wording of the
 * agreement. Publishing decides what the next new starter is asked, who is asked
 * it, what merges into the document, and what that document actually says.
 * "Version 4" tells the person clicking publish none of that. What is below —
 * the field lists, the order, and whether the wording moved — is the difference
 * between an explicit act and a button.
 *
 * ── Computed, never stored ───────────────────────────────────────
 *
 * Both versions are immutable, so the diff between any two of them is a fixed
 * fact that can be worked out whenever it is asked for. Writing it into a column
 * as well would be a second copy of a derived answer, and the two can only ever
 * disagree in one direction: the stored one going stale. This is also what makes
 * `preview` honest — the diff shown before publishing is produced by this same
 * class from the same input, not by a separate code path that approximates it.
 */
final class VersionDiff
{
    /** What happened to the wording, when something did. */
    public const BODY_ADDED   = 'added';
    public const BODY_EDITED  = 'edited';
    public const BODY_REMOVED = 'removed';

    private function __construct(
        /** @var array<int, array<string, mixed>> fields this version introduces */
        public readonly array $added,
        /** @var array<int, array<string, mixed>> fields this version takes out of new onboardings */
        public readonly array $retired,
        /** @var array<int, array<string, mixed>> fields brought back into use */
        public readonly array $unretired,
        /** @var array<int, array<string, mixed>> per-field property changes */
        public readonly array $changed,
        /** Order is meaningful — it is the order of the form — so a reshuffle is reported. */
        public readonly bool $reordered,
        /**
         * What happened to the agreement's wording: one of the three constants
         * above, or null when it did not change.
         *
         * ── Why the change is named and the TEXT is not carried ──
         *
         * The field lists above are short and a person reads them. A body is
         * pages of prose, and a line-by-line diff of it belongs on a screen built
         * for one — meanwhile this same array goes into the audit metadata of
         * every publish, where a full before-and-after would store two copies of
         * text that is already stored, immutably, on the two versions themselves.
         * The rule VersionDiff opens with, applied to the largest thing in the
         * row: a diff is computed from the versions, never duplicated beside them.
         *
         * The three states are distinguished because they are three different
         * acts. "Removed" in particular is the one somebody needs to see loudly:
         * it does not blank the contract, it drops it back to the generated form
         * dump (DocumentRenderer), and a publisher who did that by pasting an
         * empty box has changed what every new starter signs.
         */
        public readonly ?string $body,
    ) {}

    /**
     * @param  FieldSet|null  $before  null for the first version of a contract,
     *                                 where everything is an addition
     * @param  string|null  $bodyBefore  already normalised — '' and null are one
     *         state (MergeTags::normalise), or this would report an "edit" for
     *         somebody clearing a box that was already empty
     */
    public static function between(
        ?FieldSet $before,
        FieldSet $after,
        ?string $bodyBefore = null,
        ?string $bodyAfter = null,
    ): self {
        $old = $before?->keyed() ?? [];
        $new = $after->keyed();

        $added = $retired = $unretired = $changed = [];

        foreach ($new as $key => $field) {
            $was = $old[$key] ?? null;

            if ($was === null) {
                $added[] = [
                    'key'   => $key,
                    'label' => $field->label,
                    'type'  => $field->type,
                    // Named on the line itself: "added a field" and "added a
                    // field the employee must answer before they can finish"
                    // are different amounts of news.
                    'filled_by' => $field->filledBy,
                    'required'  => $field->required,
                    'sensitive' => $field->sensitive,
                ];

                continue;
            }

            if (! $was->retired && $field->retired) {
                $retired[] = ['key' => $key, 'label' => $field->label];
            } elseif ($was->retired && ! $field->retired) {
                $unretired[] = ['key' => $key, 'label' => $field->label];
            }

            $properties = self::changedProperties($was, $field);

            if ($properties !== []) {
                $changed[] = ['key' => $key, 'label' => $field->label, 'properties' => $properties];
            }
        }

        foreach ($old as $key => $field) {
            if (! isset($new[$key])) {
                $retired[] = ['key' => $key, 'label' => $field->label];
            }
        }

        return new self(
            added: $added,
            retired: $retired,
            unretired: $unretired,
            changed: $changed,
            reordered: self::orderChanged($old, $new),
            body: self::bodyChange(
                MergeTags::normalise($bodyBefore),
                MergeTags::normalise($bodyAfter),
            ),
        );
    }

    private static function bodyChange(?string $before, ?string $after): ?string
    {
        return match (true) {
            $before === $after  => null,
            $before === null    => self::BODY_ADDED,
            $after  === null    => self::BODY_REMOVED,
            default             => self::BODY_EDITED,
        };
    }

    /**
     * Asked only of the fields present in BOTH versions.
     *
     * Adding a field shifts the position of everything after it, so comparing
     * whole lists would report "the order changed" on every publish that added
     * anything — true, mechanically, and useless: it would bury the one line
     * that matters under a second one that is always there. Comparing the
     * relative order of the fields the two versions share answers the question
     * somebody actually has, which is whether existing questions were moved.
     *
     * @param  array<string, FieldDefinition>  $old
     * @param  array<string, FieldDefinition>  $new
     */
    private static function orderChanged(array $old, array $new): bool
    {
        $common = array_intersect(array_keys($old), array_keys($new));

        return array_values($common) !== array_values(array_intersect(array_keys($new), $common));
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private static function changedProperties(FieldDefinition $was, FieldDefinition $now): array
    {
        $before = $was->comparableAttributes();
        $after  = $now->comparableAttributes();
        $out    = [];

        foreach ($after as $property => $value) {
            if ($before[$property] !== $value) {
                $out[$property] = ['from' => $before[$property], 'to' => $value];
            }
        }

        return $out;
    }

    /**
     * Nothing moved. A publish with an empty diff is one somebody should
     * reconsider.
     *
     * The body counts, and it has to: an edit to the wording is as much a change
     * as an edit to a field, because it changes what somebody is agreeing to. If
     * it were left out here, rewriting the entire agreement while touching no
     * field would be refused as "the field list this contract already has" — the
     * one publish that most needs a version of its own.
     */
    public function isEmpty(): bool
    {
        return $this->added === []
            && $this->retired === []
            && $this->unretired === []
            && $this->changed === []
            && ! $this->reordered
            && $this->body === null;
    }

    public function toArray(): array
    {
        return [
            'added'     => $this->added,
            'retired'   => $this->retired,
            'unretired' => $this->unretired,
            'changed'   => $this->changed,
            'reordered' => $this->reordered,
            // Null when the wording did not change — present either way, because
            // an absent key is indistinguishable from an older server and the
            // client would have to guess which.
            'body'     => $this->body,
            'is_empty' => $this->isEmpty(),
        ];
    }
}
