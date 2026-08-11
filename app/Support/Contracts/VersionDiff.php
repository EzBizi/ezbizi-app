<?php

namespace App\Support\Contracts;

/**
 * What publishing this version actually changes
 * (EZBIZI-HR-PLAN.md: "Publishing a new version must be an explicit act with a
 * diff a person can read: what was added, what was retired, what changed").
 *
 * ── Why a diff, and not just a version number ────────────────────
 *
 * A contract template is the schema for onboarding. Publishing decides what the
 * next new starter is asked, who is asked it, and what merges into a document
 * somebody signs. "Version 4" tells the person clicking publish none of that.
 * The three lists below are the difference between an explicit act and a button.
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
    ) {}

    /**
     * @param  FieldSet|null  $before  null for the first version of a contract,
     *                                 where everything is an addition
     */
    public static function between(?FieldSet $before, FieldSet $after): self
    {
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

        return new self(
            added: $added,
            retired: $retired,
            unretired: $unretired,
            changed: $changed,
            reordered: self::orderChanged($old, $new),
        );
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

    /** Nothing moved. A publish with an empty diff is one somebody should reconsider. */
    public function isEmpty(): bool
    {
        return $this->added === []
            && $this->retired === []
            && $this->unretired === []
            && $this->changed === []
            && ! $this->reordered;
    }

    public function toArray(): array
    {
        return [
            'added'     => $this->added,
            'retired'   => $this->retired,
            'unretired' => $this->unretired,
            'changed'   => $this->changed,
            'reordered' => $this->reordered,
            'is_empty'  => $this->isEmpty(),
        ];
    }
}
