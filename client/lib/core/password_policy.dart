/// What the client tells people about passwords.
///
/// ── Two copies, and why it is not one ────────────────────────────
///
/// The authority is `App\Support\Auth\PasswordPolicy::MIN_LENGTH` on the server,
/// which is what actually refuses a short password. This is the number the forms
/// *say*, and it cannot be fetched: sign-up and password-reset are public
/// screens with no session, so there is nothing to ask before the field is
/// drawn, and adding an endpoint that serves one integer to unauthenticated
/// callers is a larger thing than the problem.
///
/// So it is deliberately two sources, named to each other, rather than the four
/// it was — the same literal sat in signup_screen, password_card and
/// reset_password_screen with nothing connecting them. **Raising the server
/// floor means changing this line too**, and the server's docblock says the same
/// in the other direction.
///
/// The failure mode if they drift is mild and self-correcting: the server
/// refuses the password and returns the real requirement, which every one of
/// those screens already surfaces. A stale helper is a bad hint, not a lockout.
class PasswordPolicy {
  const PasswordPolicy._();

  /// Mirrors `PasswordPolicy::MIN_LENGTH` (PHP).
  static const int minLength = 12;

  /// "At least 12 characters" — one sentence, so the three forms agree on the
  /// wording as well as the number.
  static const String hint = 'At least $minLength characters.';
}
