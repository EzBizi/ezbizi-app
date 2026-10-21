<?php

namespace Tests\Feature;

use App\Support\Settings\SettingRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "Takes effect when invoicing ships" — a claim about the source, checked.
 *
 * ── Why these rot, and why nothing else catches it ───────────────
 *
 * `notYetInEffect` is a sentence the settings screen SHOWS an administrator:
 * this control is real, your value is stored and audited, and nothing reads it
 * yet. Fourteen settings carry one today. Every one is a promise about code
 * somewhere else, made by a file that has no reason to be touched on the day
 * that code lands — so the failure is somebody wiring the feature, shipping it,
 * and leaving the screen saying it does nothing.
 *
 * That failure is invisible from every direction. No test breaks, because the
 * setting works. Nothing looks wrong on screen, because the sentence is
 * plausible. The administrator simply never sets the value — they were told it
 * would not do anything — and the feature ships configured by its default
 * forever.
 *
 * The same shape as `TriggerDefinition::$isRaised`, which
 * `ModuleRegistryTest::test_a_trigger_that_nothing_raises_says_so_and_one_that_fires_does_not`
 * already guards for exactly this reason. This is that check, for the other
 * field that makes a promise about the source. It was written after
 * `organization.settings_changed` was found declared-and-dead in the registry
 * next door, and after the SES sandbox lifted on 2026-09-14 put two of these
 * sentences in question — both measured still true, because the blocker was
 * never delivery: nothing in `app/` reads `notifications.email_enabled` at all.
 *
 * ── One direction only, deliberately ─────────────────────────────
 *
 * A key nothing reads is certainly not in effect, so this fails a claim that
 * has been overtaken. The other direction — a setting that IS dormant but
 * carries no sentence — is not checkable from here: "nothing reads it" is also
 * true of a setting read only by the client, or one whose reader is a queue
 * worker in another repo. Half a check that cannot be wrong beats a whole one
 * that cries wolf.
 */
class SettingNotYetInEffectTest extends TestCase
{
    public function test_a_setting_that_says_it_does_nothing_is_read_by_nothing(): void
    {
        $claims = [];

        foreach (app(SettingRegistry::class)->all() as $key => $definition) {
            if ($definition->notYetInEffect !== null) {
                $claims[] = $key;
            }
        }

        // A floor, because the scope is derived. An empty list passes every
        // assertion below while checking nothing, and a renamed field would
        // produce exactly that with nothing to say the scan had stopped
        // working (StartUp.md §8).
        // Lowered from 8 to 5 when invoicing put the invoice prefix and the
        // payment terms into effect (2026-10-21): the floor catches a broken
        // scan, not settings being switched on.
        $this->assertGreaterThan(
            5,
            count($claims),
            'Almost no setting carries a not-yet-in-effect sentence — the scan has stopped matching, so this test is green without checking anything.',
        );

        $readers = $this->readersOf($claims);

        $overtaken = [];

        foreach ($claims as $key) {
            if ($readers[$key] !== null) {
                $overtaken[] = sprintf('%s — read by %s', $key, $readers[$key]);
            }
        }

        sort($overtaken);

        $this->assertSame([], $overtaken, sprintf(
            "These settings tell the administrator they do nothing yet, and something reads them:\n  %s\n\n".
            "If the feature has shipped, drop `notYetInEffect` — the screen is telling somebody not to bother\n".
            "setting a value that now decides something. If the reader is a dead end rather than the feature,\n".
            'say so in the sentence instead of leaving it flatly untrue.',
            implode("\n  ", $overtaken),
        ));
    }

    /**
     * The first file that reads each key, or null for the ones nothing reads.
     *
     * QUOTED FORMS ONLY, and that is the whole precision of this check. A
     * reader writes `$settings->get('money.tax_rate', $scope)`; prose about a
     * setting writes it in backticks. Searching for the bare key matches the
     * prose — `DisplaySettings`'s own docblock explains why `measurement_units`
     * is deliberately absent from `/me`, and a bare-substring scan reads that
     * paragraph as a reader and fails the one setting whose absence is
     * documented most carefully.
     *
     * Verified against a control before it was trusted: `timezone` answers with
     * 23 files and `jobs.statuses` with 2, so a zero here is a measurement
     * rather than a scan that matches nothing.
     *
     * @param  string[]  $keys
     * @return array<string, string|null>
     */
    private function readersOf(array $keys): array
    {
        $found = array_fill_keys($keys, null);

        $trees = [
            [app_path(), 'php', app_path('Support'.DIRECTORY_SEPARATOR.'Settings'.DIRECTORY_SEPARATOR.'SettingRegistry.php')],
            [base_path('client'.DIRECTORY_SEPARATOR.'lib'), 'dart', null],
        ];

        foreach ($trees as [$root, $extension, $declaration]) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== $extension || $file->getPathname() === $declaration) {
                    continue;
                }

                $contents = $file->getContents();

                foreach ($keys as $key) {
                    if ($found[$key] !== null) {
                        continue;
                    }

                    if (str_contains($contents, "'{$key}'") || str_contains($contents, "\"{$key}\"")) {
                        $found[$key] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                    }
                }
            }
        }

        return $found;
    }
}
