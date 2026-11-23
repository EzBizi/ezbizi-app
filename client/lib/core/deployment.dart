/// Which face of the product this browser asked for (plan §4.2).
///
/// One bundle serves both hosts. The customer app answers at app.ezbizi.com and
/// the control plane at dev.ezbizi.com, and the host decides — so the staff
/// sign-in is not something a customer can stumble into, and staff do not have
/// to remember a link buried on someone else's login page.
///
/// This is presentation only. It is NOT a security boundary and must never be
/// treated as one: the bundle is the same file either way, so anyone can ask for
/// the staff form. What actually protects the control plane is server-side —
/// mandatory TOTP, the class check in the StaffOnly middleware, and nginx
/// refusing /api/v1/staff on the customer host.
enum AppFace { customer, staff }

class Deployment {
  /// Host labels that mean the control plane, comma-separated.
  ///
  /// A build flag rather than a constant, following API_BASE: the hostname a
  /// deployment answers to is deployment config, and baking one into the binary
  /// is what made moving A1 Tools to a new domain a migration instead of an
  /// edit.
  ///
  ///     flutter build web --dart-define=STAFF_HOSTS=dev,console
  static const String staffHosts =
      String.fromEnvironment('STAFF_HOSTS', defaultValue: 'dev');

  /// Forces a face regardless of host. For desktop and mobile builds, where
  /// there is no meaningful hostname to read.
  ///
  ///     flutter build windows --dart-define=APP_FACE=staff
  static const String forcedFace = String.fromEnvironment('APP_FACE');

  static AppFace face() {
    if (forcedFace == 'staff') return AppFace.staff;
    if (forcedFace == 'customer') return AppFace.customer;

    return isStaffHost(Uri.base.host) ? AppFace.staff : AppFace.customer;
  }

  /// Matches on the FIRST LABEL only.
  ///
  /// Not `contains`, and not a suffix test: 'dev' must match dev.ezbizi.com
  /// without also matching devious.example.com or app.ezbizi.com.dev.example —
  /// a hostname an attacker controls should never be able to select which UI a
  /// victim is looking at.
  ///
  /// Off-web, Uri.base is a file path with no host, so this is false and the
  /// customer app is the default.
  static bool isStaffHost(String host) {
    if (host.isEmpty) return false;

    final label = host.split('.').first.toLowerCase();

    return staffHosts
        .split(',')
        .map((h) => h.trim().toLowerCase())
        .where((h) => h.isNotEmpty)
        .contains(label);
  }

  /// The reset token in the current URL, if this is a reset link.
  ///
  /// The app decided what to render from the HOST alone, so `/reset?token=…` —
  /// the link every password-reset email contains — rendered the sign-in screen
  /// and dropped the token. The flow was built end to end on the server and had
  /// no ending in the client.
  static String? resetToken() => tokenAt(Uri.base, '/reset');

  /// The invitation token in the current URL, if this is an invitation link.
  ///
  /// `/invite?token=…` is what `POST /hr/invitations` puts in `accept_url`, so
  /// it is the exact string an inviter copies out of the app and sends to
  /// somebody. If nothing here read it, every invitation ever sent would land on
  /// the sign-in screen of an account that does not exist yet — which is the
  /// reset bug above, repeated, in the flow whose whole point is that the person
  /// following the link has no other way in.
  static String? inviteToken() => tokenAt(Uri.base, '/invite');

  /// The invite-link token in `/join?link=…` (Jose, 2026-11-23): one link many
  /// people use to make their own accounts. See JoinLinkScreen.
  static String? joinLink() {
    final uri = Uri.base;
    if (!uri.path.startsWith('/join')) return null;
    final link = uri.queryParameters['link'];
    return (link == null || link.isEmpty) ? null : link;
  }

  /// The `token` query parameter, when [uri] points at [path].
  ///
  /// Takes the URI rather than reading `Uri.base` itself so a test can hand it
  /// one: `Uri.base` is the process's own address and there is no way to set it.
  /// Public for the same reason [isStaffHost] is.
  static String? tokenAt(Uri uri, String path) {
    if (!uri.path.startsWith(path)) return null;

    final token = uri.queryParameters['token'];

    return (token == null || token.isEmpty) ? null : token;
  }
}
