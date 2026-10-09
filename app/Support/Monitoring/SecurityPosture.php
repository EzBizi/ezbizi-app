<?php

namespace App\Support\Monitoring;

/**
 * What Windows' own Security Center says about a machine's defences, and the
 * one place the server decides whether that is worth telling somebody about.
 *
 * The agent reports `specs.security` as three words from a fixed vocabulary —
 * see windows/runner/monitoring_specs.cpp, which reads them through WSC rather
 * than by inspecting Defender, so a machine correctly protected by a THIRD
 * PARTY antivirus reads as healthy instead of as unprotected.
 *
 * ── The states, and why two of the four do not count ──────────────
 *
 * `poor` and `snoozed` mean Windows is complaining: not protecting the
 * machine, or protecting it but deliberately paused. Those are worth an
 * automation.
 *
 * `not_monitored` means Windows is not WATCHING that area, which is normal
 * under domain policy. Raising an automation for it would fire across a whole
 * office at once, and then the one machine that matters arrives looking like
 * the forty-one that do not.
 *
 * An ABSENT block is not a state at all: an older agent, a browser session, or
 * a machine that has not beaten yet. Silence is not a report of health and it
 * is not a report of trouble — it is the absence of a report, and nothing
 * should fire on it.
 *
 * ── This has a twin in the client and they must agree ─────────────
 *
 * `securityNeedsAttention` in client/lib/screens/monitoring/machine_detail.dart
 * decides the roster BADGE from the same three keys and the same two states.
 * This decides the automation TRIGGER. A roster that badges a machine while no
 * automation fires for it — or the reverse — would be a difference nobody
 * could explain from either screen, so a change to the states below belongs in
 * both files in the same cut. SecurityPostureTest pins the vocabulary on this
 * side; machine_detail_test.dart pins it on the other.
 */
final class SecurityPosture
{
    /** The areas Windows reports on, in the order a person would read them. */
    public const AREAS = ['antivirus', 'firewall', 'updates'];

    /** The states that mean somebody should look at this machine. */
    public const UNHEALTHY = ['poor', 'snoozed'];

    /**
     * The areas of this machine that Windows is unhappy about, keyed by area.
     *
     * Empty for a healthy machine AND for one that has reported nothing, which
     * is why callers ask [reported] first: "no unhealthy areas" is true of a
     * machine nobody has heard from, and that is not the same fact.
     *
     * @param  array<string, mixed>|null  $specs
     * @return array<string, string>
     */
    public static function unhealthyAreas(?array $specs): array
    {
        $block = $specs['security'] ?? null;

        if (! is_array($block)) {
            return [];
        }

        $found = [];

        foreach (self::AREAS as $area) {
            $state = $block[$area] ?? null;

            if (is_string($state) && in_array($state, self::UNHEALTHY, true)) {
                $found[$area] = $state;
            }
        }

        return $found;
    }

    /**
     * Whether a beat carries a security report at all.
     *
     * The load-bearing distinction for the trigger: a beat with no security
     * block must NOT be read as "the machine recovered", because the next beat
     * that does carry one would then look like a fresh transition and raise
     * the automation a second time for a fault nobody fixed.
     *
     * @param  array<string, mixed>|null  $specs
     */
    public static function reported(?array $specs): bool
    {
        return is_array($specs['security'] ?? null);
    }
}
