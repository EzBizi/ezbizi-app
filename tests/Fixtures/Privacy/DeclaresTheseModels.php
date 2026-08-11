<?php

namespace Tests\Fixtures\Privacy;

use App\Support\Privacy\DeclaringModels;

/**
 * The walk, with a list a test chose.
 *
 * ── Why a test needs this at all ─────────────────────────────────
 *
 * App\Support\Privacy\Anonymiser is DERIVED: it walks whatever in app/Models
 * implements HoldsPersonalData and does what each declaration says. That is the
 * design and it is the right one — but it means the Anonymiser's own behaviour
 * on a case no real model produces cannot be reached from the real models, and
 * two such cases are exactly where it was wrong:
 *
 *   - a model that refuses an ERASE. Nothing declares one today, so the catch on
 *     that path had no coverage at all and could be deleted with 30 tests still
 *     green.
 *
 *   - a write that fails because the DATABASE said no rather than because a
 *     model did. Reachable only by arranging a real constraint violation
 *     part-way through a walk.
 *
 * The alternative to a fixture is changing a real declaration to provoke the
 * case, which would mean a production model carrying a shape that exists to make
 * a test go. There is already a test whose whole job is to stop that shape
 * appearing on the table one of these fixtures uses
 * (PersonalDataPurgeTest::test_the_evidentiary_tables_keep_everything).
 *
 * `DeclaringModels` is injected into the Anonymiser rather than reached
 * statically, which is what makes this possible without any seam being added for
 * it. The subclass replaces one answer and leaves the rest of the class alone.
 */
class DeclaresTheseModels extends DeclaringModels
{
    /** @param  array<class-string>  $classes  in the order the walk should visit them */
    public function __construct(private readonly array $classes) {}

    /**
     * In the given order, NOT sorted.
     *
     * The real implementation sorts, and the order it produces is alphabetical
     * by class name. Two of the tests here turn on one table being written
     * before another raises, so the order is the fixture's to state.
     */
    public function holdingPersonalData(): array
    {
        return $this->classes;
    }
}
