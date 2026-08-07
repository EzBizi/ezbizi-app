<?php

namespace App\Support\Auth;

/**
 * The platform's password floor (plan §2.1, §4).
 *
 * ── Why this is a constant and not a setting ─────────────────────
 *
 * §2.1 narrows the no-hardcoding rule: security invariants stay in code. A floor
 * an administrator can lower to 4 is not a floor.
 *
 * ── What `security.password_min_length` currently does: nothing ──
 *
 * It is declared in SettingRegistry and read by no code at all. An organization
 * that raises it to 20 gets a saved value, an audit row, and twelve-character
 * passwords — which is worse than the setting being absent, because the Settings
 * screen presents it as enforced.
 *
 * Stated here rather than quietly implemented, for two reasons. It is not this
 * class's decision to make: the review found roughly twenty settings in the same
 * state, all four security ones among them, and the fix agreed for that is a
 * `notYetInEffect` flag on the definition so the screen can say so — one
 * mechanism rather than twenty patches. And wiring it here alone would half-fix
 * it: signup has no organization to read a setting against, so the floor would
 * apply at reset and change and not at the front door, which is a worse kind of
 * wrong than uniform.
 *
 * An earlier version of this docblock claimed the setting could already demand
 * MORE than this constant. It could not, and no code has ever read it.
 *
 * ── Why it lives here rather than on a controller ────────────────
 *
 * It was a private const on PasswordController, which meant the reset and change
 * screens agreed on it and nothing else could. Signup needs the same number, and
 * a second literal 12 in a second file is two floors that drift the first time
 * one of them is raised.
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
    public const MIN_LENGTH = 12;

    /**
     * The validation rules for a new password.
     *
     * `confirmed` is NOT included: the reset and change screens ask twice and the
     * signup form does not, so the rule belongs at each call site rather than
     * here, where it would silently impose a second field on every future caller.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'min:'.self::MIN_LENGTH];
    }
}
