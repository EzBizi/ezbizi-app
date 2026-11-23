<?php

namespace App\Support\Auth;

use App\Models\Organization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/**
 * The platform's password floor (plan §2.1, §4).
 *
 * ── Why the FLOOR is a constant and not a setting ────────────────
 *
 * §2.1 narrows the no-hardcoding rule: security invariants stay in code. A floor
 * an administrator can lower to 4 is not a floor. So MIN_LENGTH below is the
 * number nothing on this platform will go under, whatever any organization has
 * configured.
 *
 * ── What `security.password_min_length` does ─────────────────────
 *
 * It RAISES that floor for one organization, and cannot lower it. An
 * administrator answering a client's security questionnaire sets 16 and gets 16;
 * one setting 6 gets 8, because the constant wins. Both directions are tested
 * in tests/Feature/Api/SecuritySettingsTest.php.
 *
 * Until this was wired the setting was read by nothing at all — a saved value, an
 * audit row and twelve-character passwords, which is worse than the setting being
 * absent because the Settings screen presents it as enforced.
 *
 * ── The one place it cannot apply: signup ────────────────────────
 *
 * `minLengthFor(null)` is the honest answer at the front door. A setting is
 * resolved against an organization, and at signup there is no organization yet —
 * the form that creates one is the form being validated. Nothing can be read, so
 * the constant applies and the first password on a new tenant is a
 * twelve-character one.
 *
 * That asymmetry is real and deliberate rather than an oversight. It is also
 * narrow: it covers exactly one password, the founding administrator's, set
 * before anybody could have configured anything. Every later password — every
 * reset, every change — goes through an organization and gets the policy.
 *
 * ── Why the container is reached into from a static ──────────────
 *
 * Every existing caller writes `PasswordPolicy::rules()` inside a `validate()`
 * array, and the value of keeping the answer in one discoverable place is higher
 * than the value of constructor injection on a class with no state. The
 * resolution is one method, and it is the only impure thing here.
 */
final class PasswordPolicy
{
    /**
     * Long enough to be worth having, short enough that people use it.
     *
     * Deliberately NOT the staff minimum of 16 — that applies to accounts which
     * reach every customer on the platform. This is the ordinary tenant policy,
     * and a length nobody complies with is a length that produces Summer2026!.
     */
    public const MIN_LENGTH = 8;

    /**
     * The longest minimum an organization can usefully ask for.
     *
     * bcrypt — the default hasher, and what `Hash::make` uses here — reads the
     * first 72 bytes of a password and ignores the rest. A minimum above that
     * demands characters the stored hash cannot tell apart from padding, so it
     * buys nothing; and because the check runs on every password change, a
     * fat-fingered 1000 would make it impossible for anyone in the organization
     * to change their password until somebody put the setting back.
     *
     * Clamped rather than refused at write time. SettingDefinition has no bounds
     * facility and giving one setting a private validator would be the beginning
     * of thirty of them; the registry's help text states the ceiling instead, so
     * the screen and the code say the same number.
     */
    public const MAX_LENGTH = 72;

    /**
     * The validation rules for a new password, at the platform floor.
     *
     * For anything belonging to a known organization use `rulesFor()` — this is
     * the signup case, and the one place the organization genuinely does not
     * exist yet.
     *
     * `confirmed` is NOT included: the reset and change screens ask twice and the
     * signup form does not, so the rule belongs at each call site rather than
     * here, where it would silently impose a second field on every future caller.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return self::rulesFor(null);
    }

    /**
     * The validation rules for a password belonging to one organization.
     *
     * @return array<int, string>
     */
    public static function rulesFor(?Organization $organization): array
    {
        return ['required', 'string', 'min:'.self::minLengthFor($organization)];
    }

    /**
     * The effective minimum: the platform floor, or the organization's if higher.
     *
     * `max()` and not the setting on its own — that is the whole security
     * property. An administrator may make their organization stricter than the
     * platform and may not make it laxer, which is the same shape as §6's allowed
     * scopes: policy moves in one direction only.
     */
    public static function minLengthFor(?Organization $organization): int
    {
        if ($organization === null) {
            return self::MIN_LENGTH;
        }

        // Setting rows are tenant-scoped, and the two callers that matter reach
        // here from opposite sides of that: `change` already has the tenant in
        // context, `reset` is a public route with none at all. Entering the
        // organization explicitly is correct in both — for the first it re-sets
        // what is already there, for the second it is the only way the read can
        // legally happen.
        $configured = app(TenantContext::class)->run(
            $organization,
            fn () => app(SettingsResolver::class)->get('security.password_min_length', $organization),
        );

        return min(
            self::MAX_LENGTH,
            max(self::MIN_LENGTH, (int) $configured),
        );
    }
}
