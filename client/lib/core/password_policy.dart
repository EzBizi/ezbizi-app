/// What the client tells people about passwords.
///
/// ── Where the real number comes from ─────────────────────────────
///
/// The authority is the server, which is what actually refuses a short
/// password. Two different numbers can apply:
///
///   the platform floor      `App\Support\Auth\PasswordPolicy::MIN_LENGTH`
///   the organization's own  `security.password_min_length` (§6), which may
///                           RAISE the floor and can never lower it
///
/// Signed in, the effective one arrives on `/me` as `password_min_length` and
/// is on `Session.me.passwordMinLength` — so the account screen states whatever
/// the administrator configured rather than a constant compiled into this app.
/// Use [hintFor] there.
///
/// ── The two screens that still cannot ask ────────────────────────
///
/// Sign-up and password-reset are public: there is no session before the field
/// is drawn. Reset additionally has no organization to resolve against until
/// the server has examined the token, which is exactly why the server does that
/// check itself in PasswordController::reset rather than trusting this file.
///
/// So those two state [minLength] below — the platform floor, the lowest number
/// that can ever be correct — and the failure mode when an organization has
/// raised it is mild and self-correcting: the server refuses the password and
/// returns the real requirement, which both screens already surface.
///
/// **Raising the server floor means changing this line too**, and the server's
/// docblock says the same in the other direction.
class PasswordPolicy {
  const PasswordPolicy._();

  /// Mirrors `PasswordPolicy::MIN_LENGTH` (PHP). The PLATFORM floor, not
  /// necessarily what the signed-in person's organization asks for.
  static const int minLength = 8;

  /// "At least 8 characters" — one sentence, so the public forms agree on the
  /// wording as well as the number.
  static const String hint = 'At least $minLength characters.';

  /// The same sentence for a known minimum, so a screen that HAS the server's
  /// answer says the server's number in the same words as one that does not.
  static String hintFor(int length) => 'At least $length characters.';
}
