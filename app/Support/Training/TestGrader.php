<?php

namespace App\Support\Training;

use App\Models\TrainingTest;

/**
 * Marks an attempt against the STORED test, never against anything the client
 * sent back. The learner's copy has had the answer key removed
 * (LearnerView::stripAnswerKey), so there is nothing on the wire to mark
 * against even if somebody tried — `correct` never left the server.
 */
final class TestGrader
{
    /**
     * @param  array<int, int|null>  $answers  chosen choice index per question
     * @return array{score:int, passed:bool}
     */
    public static function grade(TrainingTest $test, array $answers): array
    {
        $questions = $test->questions ?? [];

        if ($questions === []) {
            // No questions is not a 100% pass, whatever the test's own pass
            // mark says — decision 3: an empty test is unpassable rather than
            // trivially passed, which is the safer of the two.
            return ['score' => 0, 'passed' => false];
        }

        $right = 0;

        foreach ($questions as $i => $question) {
            $chosen = $answers[$i] ?? null;

            if ($chosen !== null && ($question['choices'][$chosen]['correct'] ?? false) === true) {
                $right++;
            }
        }

        $score = (int) round($right / count($questions) * 100);

        return ['score' => $score, 'passed' => $score >= $test->pass_mark];
    }
}
