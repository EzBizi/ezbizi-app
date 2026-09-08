<?php

namespace App\Support\Training;

/**
 * Whether a guide filed at one place reaches at least as far as a test filed
 * at another — the ONE definition of "is this guide reachable from that
 * test's place" this module keeps.
 *
 * `TestsController::assertGuideCompatible` asks this when a test is
 * authored or moved: is the guide it names reachable from the test's own
 * place? `GuidesController::update` asks the identical question the other
 * way around when a GUIDE moves: would its new place still reach every
 * test that already points at it? Both are the same three-way comparison —
 * organization reaches anything; a company's General reaches that company
 * and every franchise under it; a franchise reaches only itself — and a
 * second, independently-written copy of that comparison is exactly the
 * drift this module has spent sixteen tasks avoiding. This class is the one
 * place it is written.
 */
final class GuidePlaceReach
{
    /**
     * Does a guide filed at ($guideCompanyId, $guideFranchiseId) reach a
     * place filed at ($companyId, $franchiseId)? "Reach" here means every
     * learner who could ever be shown the second place could also be shown
     * the first — the guide is at or above it.
     */
    public static function covers(
        ?string $guideCompanyId,
        ?string $guideFranchiseId,
        ?string $companyId,
        ?string $franchiseId,
    ): bool {
        return $guideCompanyId === null
            || ($guideCompanyId === $companyId && $guideFranchiseId === null)
            || ($guideCompanyId === $companyId && $guideFranchiseId === $franchiseId);
    }
}
