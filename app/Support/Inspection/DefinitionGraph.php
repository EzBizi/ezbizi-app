<?php

namespace App\Support\Inspection;

/**
 * An inspection drawn as the shape a technician walks through.
 *
 * ── Why this is here and not in a controller ────────────────────
 *
 * Adapted from `TwimlRenderer::graph()`, which the Phone module put in the same
 * class as `TwimlRenderer::inbound()` — the method that actually answers the
 * phone — so a picture of a call flow cannot disagree with what a caller hears.
 * The first version of theirs was going to live in a controller, and that would
 * have been a second opinion about the call flow.
 *
 * The equivalent here is exact. `RequiredFields::firstMissing` walks screens,
 * then fields, then `OptionSet::revealedBy`, asking `Condition::passes` at each
 * step. THIS FILE WALKS THE SAME THREE LEVELS AND CALLS THE SAME PREDICATES. It
 * derives no visibility rule of its own, which is the only reason the drawing
 * can be trusted to match the run.
 *
 * Not in Dart either: a chart assembled in the client is a third opinion, and it
 * drifts silently because nothing fails when it does.
 *
 * ── What the picture is FOR ─────────────────────────────────────
 *
 * The builder edits a form as a list, and a list cannot show you the branch you
 * forgot. A conditional screen that can never appear looks exactly like one that
 * works — same row, same fields, same everything — and the only difference is a
 * condition naming a field somebody renamed three edits ago. That is invisible
 * in a form editor and obvious in a picture, which is the whole reason for this.
 *
 * ── The static shape, with a hook for one run's path ────────────
 *
 * A call flow has one shape whoever rings. An inspection's shape depends on the
 * ANSWERS, so there are two different pictures available and this ships the
 * first:
 *
 *   1. the STRUCTURE — every branch, whether or not anybody took it. What an
 *      author is editing, so it is what the builder needs.
 *   2. one REPORT's actual path — "why did this inspection never ask about the
 *      chimney". A different question, for a different screen.
 *
 * `$answers` is the seam between them. Empty, every node is drawn and judged on
 * structure alone. Populated, each node also carries `on_path`, computed by the
 * same `Condition::passes` the run used — so (2) is an overlay on this rather
 * than a second graph that can disagree with it.
 */
final class DefinitionGraph
{
    /**
     * @param  list<array<string, mixed>>  $screens
     * @param  array<string, mixed>  $answers
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function of(array $screens, array $answers = []): array
    {
        $known = self::fieldKeys($screens);
        $overlay = $answers !== [];

        $nodes = [];
        $edges = [];
        $previousScreen = null;

        foreach ($screens as $index => $screen) {
            if (! is_array($screen)) {
                continue;
            }

            $screenId = 'screen:'.$index;
            $screenCondition = $screen['visible_if'] ?? null;

            $nodes[] = self::node(
                id: $screenId,
                kind: 'screen',
                title: (string) ($screen['name'] ?? 'Screen '.($index + 1)),
                detail: self::conditionSentence($screenCondition),
                state: self::stateOf($screenCondition, $known),
                ref: (string) $index,
                edit: ['pane' => 'screen', 'id' => (string) $index],
                onPath: $overlay ? Condition::passes($screenCondition, $answers) : null,
            );

            /*
             * Sequential, because that is how a run moves: screen to screen in
             * order, with the conditions deciding whether each is SHOWN rather
             * than where the walk goes next. An inspection has no jumps, which
             * is the structural difference from a phone menu and the reason
             * there is no branch node here.
             */
            if ($previousScreen !== null) {
                $edges[] = ['from' => $previousScreen, 'to' => $screenId, 'label' => null];
            }
            $previousScreen = $screenId;

            $fields = is_array($screen['fields'] ?? null) ? $screen['fields'] : [];

            foreach ($fields as $position => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = (string) ($field['key'] ?? '');
                $fieldId = $screenId.':field:'.$position;
                $condition = $field['visible_if'] ?? null;

                $nodes[] = self::node(
                    id: $fieldId,
                    kind: 'field',
                    title: (string) ($field['label'] ?? $key ?: 'Question'),
                    detail: self::conditionSentence($condition),
                    state: self::stateOf($condition, $known),
                    ref: $key,
                    /*
                     * The pane is NOT derived from `kind`. Phone paid for that:
                     * a voicemail BOX and a voicemail OPTION are both
                     * `kind: voicemail` and are edited in two different places,
                     * so a kind-to-pane map opened the wrong editor and looked
                     * like it had worked. The analogue here is a field, which is
                     * edited on its screen, versus the same field nested under
                     * an option, which is edited inside the field that reveals
                     * it. The id carries the whole path so the two cannot
                     * collapse.
                     */
                    edit: ['pane' => 'field', 'id' => $screenId.':'.$position],
                    onPath: $overlay ? Condition::passes($condition, $answers) : null,
                );

                $edges[] = ['from' => $screenId, 'to' => $fieldId, 'label' => null];

                foreach (self::revealed($field, $answers, $overlay) as $option => $inner) {
                    foreach ($inner as $nested) {
                        if (! is_array($nested)) {
                            continue;
                        }

                        $nestedKey = (string) ($nested['key'] ?? '');
                        $nestedId = $fieldId.':option:'.$option.':'.$nestedKey;
                        $nestedCondition = $nested['visible_if'] ?? null;

                        $nodes[] = self::node(
                            id: $nestedId,
                            kind: 'option_field',
                            title: (string) ($nested['label'] ?? $nestedKey ?: 'Question'),
                            detail: self::conditionSentence($nestedCondition),
                            state: self::stateOf($nestedCondition, $known),
                            ref: $nestedKey,
                            edit: ['pane' => 'option_field', 'id' => $fieldId.':'.$option],
                            onPath: $overlay ? Condition::passes($nestedCondition, $answers) : null,
                        );

                        $edges[] = ['from' => $fieldId, 'to' => $nestedId, 'label' => (string) $option];
                    }
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Every field key the definition declares, at all three levels.
     *
     * A condition naming anything absent from this set can never be true, which
     * is the one judgement this file makes that the run does not — the run
     * simply gets `false` and shows nothing, which is exactly why nobody
     * notices.
     *
     * @param  list<array<string, mixed>>  $screens
     * @return array<string, true>
     */
    private static function fieldKeys(array $screens): array
    {
        $keys = [];

        foreach ($screens as $screen) {
            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                if (($key = (string) ($field['key'] ?? '')) !== '') {
                    $keys[$key] = true;
                }

                // Nested fields answer questions too, so a condition may name
                // one. Read through OptionSet rather than reaching into
                // `options` directly, because it is the file that knows a bare
                // string option from a configured one.
                foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $option) {
                    $shape = OptionSet::normalise($option);

                    if ($shape === null) {
                        continue;
                    }

                    foreach ((is_array($shape['fields'] ?? null) ? $shape['fields'] : []) as $nested) {
                        if (is_array($nested) && ($k = (string) ($nested['key'] ?? '')) !== '') {
                            $keys[$k] = true;
                        }
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * The nested fields an option reveals.
     *
     * With answers, `OptionSet::revealedBy` gives what THIS run revealed. With
     * none, the builder wants every branch, so the option sets are read whole —
     * the one place the two pictures genuinely need different inputs.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $answers
     * @return array<string, list<array<string, mixed>>>
     */
    private static function revealed(array $field, array $answers, bool $overlay): array
    {
        if ($overlay) {
            $live = OptionSet::revealedBy($field, $answers);

            return $live === [] ? [] : ['' => $live];
        }

        $out = [];

        foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $option) {
            $shape = OptionSet::normalise($option);

            if ($shape === null) {
                continue;
            }

            $nested = is_array($shape['fields'] ?? null) ? $shape['fields'] : [];

            if ($nested !== []) {
                $out[(string) ($shape['value'] ?? $shape['label'] ?? '')] = $nested;
            }
        }

        return $out;
    }

    /**
     * `ok`, `dead` or `skipped` — the three Phone settled on, read across.
     *
     * `dead` is somebody arrives and nothing useful happens: here, a condition
     * naming a field that no longer exists, so the branch can never open. That
     * is the renamed-field case, and it is the one this whole picture exists to
     * surface.
     *
     * `skipped` is passing THROUGH something configured that does nothing. For
     * an inspection that judgement needs answers — a condition is skipped for
     * THIS run, not in general — so it is only reachable in overlay mode, and
     * the static picture never claims it.
     *
     * @param  array<string, true>  $known
     */
    private static function stateOf(mixed $condition, array $known): string
    {
        if ($condition === null) {
            return 'ok';
        }

        foreach (Condition::fieldsNamed($condition) as $named) {
            if (! isset($known[$named])) {
                return 'dead';
            }
        }

        return 'ok';
    }

    /** A condition as one line, or null when a node is always shown. */
    private static function conditionSentence(mixed $condition): ?string
    {
        if ($condition === null) {
            return null;
        }

        $named = Condition::fieldsNamed($condition);

        return $named === [] ? 'Shown conditionally' : 'Shown when: '.implode(', ', $named);
    }

    /**
     * @param  array{pane: string, id: string}  $edit
     * @return array<string, mixed>
     */
    private static function node(
        string $id,
        string $kind,
        string $title,
        ?string $detail,
        string $state,
        string $ref,
        array $edit,
        ?bool $onPath,
    ): array {
        $node = [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'state' => $state,
            'ref' => $ref,
            'edit' => $edit,
        ];

        // Absent rather than null when there are no answers, so a client cannot
        // read "not on the path" out of the static picture, which says nothing
        // about any path at all.
        if ($onPath !== null) {
            $node['on_path'] = $onPath;
        }

        return $node;
    }
}
