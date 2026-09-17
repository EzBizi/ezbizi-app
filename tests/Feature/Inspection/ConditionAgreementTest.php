<?php

namespace Tests\Feature\Inspection;

use App\Support\Inspection\Condition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP evaluator, against the table the Dart one is also held to.
 *
 * ── Why two evaluators exist, and why that is not a mistake ─────
 *
 * `Condition::passes` decides what a SUBMITTED report contains — it is what
 * `RequiredFields` asks before refusing a submission, and what the frozen
 * report is built from. `conditionPasses` in
 * `client/lib/screens/inspection/condition.dart` decides what a technician is
 * SHOWN while filling the form in, with no round trip, because a form that
 * posted to hide a field would be unusable on a roof.
 *
 * Neither can be deleted. What can be deleted is the silence: today nothing
 * fails when they disagree, and the disagreement is invisible from both sides.
 * A field the app hides and the server believes was answerable is a required
 * question nobody was asked, which surfaces as a submission refused for a
 * question the technician never saw.
 *
 * ── The fixture is shared and the expectations are hand-written ──
 *
 * `tests/Fixtures/condition_cases.json` is read by this test and by
 * `client/test/condition_agreement_test.dart`. The `expected` values come from
 * the documented rules, NOT from running either implementation — recording one
 * side's behaviour would enshrine its bugs and make this test agree with
 * whatever it found.
 *
 * Noticed while reviewing the Builder flow chart, which needed a THIRD reading
 * of the same rules and made the pair visible.
 */
class ConditionAgreementTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_the_php_evaluator_matches_the_shared_table(
        string $name,
        mixed $condition,
        array $answers,
        bool $expected,
    ): void {
        $this->assertSame($expected, Condition::passes($condition, $answers), $name);
    }

    public static function cases(): array
    {
        $path = __DIR__.'/../../Fixtures/condition_cases.json';

        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $out = [];
        foreach ($raw['cases'] as $case) {
            $out[$case['name']] = [$case['name'], $case['condition'], $case['answers'], $case['expected']];
        }

        // A guard on the guard: an empty or unreadable fixture would make every
        // assertion above vanish and the suite would still be green.
        if (count($out) < 15) {
            throw new \RuntimeException('The shared condition table is too small to be the real one.');
        }

        return $out;
    }
}
