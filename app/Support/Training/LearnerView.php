<?php

namespace App\Support\Training;

/**
 * What a learner is allowed to see of a test.
 *
 * The answer key lives in the same JSON column as the question, because a
 * choice and its correctness are one fact and splitting them would let the two
 * drift. The consequence is that EVERY learner-facing read must pass through
 * here — grading happens server-side against the stored row, never against
 * anything the client was told.
 *
 * Rebuilding the shape rather than unsetting `correct` is the point: a key
 * added to a choice later — by this file or by Task 5's normalizeQuestions,
 * which is the same idea on the write side — is excluded BY DEFAULT instead
 * of leaking until somebody notices. Strip by allowlist, never by denylist.
 *
 * ── A malformed stored row is skipped, not a fatal error ──────────
 *
 * `normalizeQuestions` is TestsController's guarantee, not the database's —
 * a row written any other way (a seeder, an import, a manual fix) can carry
 * a question that is not an array, or a `choices` value that is not an
 * array either. Nothing here trusts that every stored row went through the
 * write-side shape, so a malformed entry is filtered out rather than handed
 * to a typed closure that would throw. Failing with a 500 would still fail
 * CLOSED — no disclosure — but it is a dead end for a learner looking at a
 * test with one broken question in it, when the rest of the test is
 * perfectly readable.
 */
final class LearnerView
{
    /** @return array<int, array<string, mixed>> */
    public static function stripAnswerKey(array $questions): array
    {
        return array_values(array_map(
            static function (array $question): array {
                $choices = is_array($question['choices'] ?? null) ? $question['choices'] : [];

                return [
                    'prompt' => $question['prompt'] ?? '',
                    'choices' => array_values(array_map(
                        static fn (array $choice): array => ['text' => $choice['text'] ?? ''],
                        array_filter($choices, 'is_array'),
                    )),
                ];
            },
            array_filter($questions, 'is_array'),
        ));
    }
}
