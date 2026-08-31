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
 */
final class LearnerView
{
    /** @return array<int, array<string, mixed>> */
    public static function stripAnswerKey(array $questions): array
    {
        return array_map(static fn (array $question): array => [
            'prompt' => $question['prompt'] ?? '',
            'choices' => array_map(
                static fn (array $choice): array => ['text' => $choice['text'] ?? ''],
                $question['choices'] ?? [],
            ),
        ], $questions);
    }
}
