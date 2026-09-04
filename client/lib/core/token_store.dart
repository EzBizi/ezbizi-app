import 'token_store_io.dart' if (dart.library.js_interop) 'token_store_web.dart' as store;

/// Where a session survives a refresh.
///
/// ── The trade, stated rather than assumed ────────────────────────
///
/// A bearer token in browser storage is readable by any script running on the
/// page. The stricter alternative is an httpOnly cookie, which script cannot
/// read at all.
///
/// I did not take that route, and the reason is specific to this app rather than
/// a general preference. The client renders to a canvas: there is no HTML built
/// from data, no innerHTML, no template interpolation — the usual DOM injection
/// routes do not exist here. The realistic script-execution risk is a
/// compromised dependency, and a compromised dependency defeats httpOnly
/// cookies just as thoroughly by simply making requests with them.
///
/// What httpOnly would genuinely buy is worth naming: it would survive an XSS
/// that this architecture makes unlikely rather than impossible. It would also
/// need Sanctum's SPA mode, a CSRF token on every write, and a second mechanism
/// for the staff token. That is a real change and a reasonable one to make
/// later — this comment is here so it is a decision rather than an oversight.
///
/// What IS done: sessionStorage rather than localStorage, so it dies with the
/// tab; the token is verified against /me before it is trusted; and it is
/// cleared the moment a session turns out to be invalid or somebody signs out.
class TokenStore {
  /// Load any persisted session into memory.
  ///
  /// Must be awaited before the first [tenantToken] read, because off the web
  /// the backing store is a keychain and the getters below are synchronous.
  /// Skip it and the desktop app reads an empty cache and shows the sign-in
  /// screen to somebody who is already signed in.
  static Future<void> init() => store.init();

  static const _tenantKey = 'ezbizi.token';
  static const _staffKey = 'ezbizi.staff_token';

  static String? get tenantToken => store.readItem(_tenantKey);
  static String? get staffToken => store.readItem(_staffKey);

  /// Writes only what it is given, so saving a staff token does not wipe the
  /// tenant one — the linked-account login hands them over together, but the
  /// control-plane host only ever has the second.
  static void save({String? tenant, String? staff, bool persist = false}) {
    // `persist` is the "keep me signed in" tick. Off, the token lives in
    // sessionStorage and dies with the tab — right for a shared machine, and
    // the default. On, it moves to localStorage and survives the browser
    // closing. The person makes that trade for themselves; it is not made
    // quietly on their behalf.
    if (tenant != null) store.writeItem(_tenantKey, tenant, persist: persist);
    if (staff != null) store.writeItem(_staffKey, staff, persist: persist);
  }

  /// Both, always. Signing out of one while the other lingers is how somebody
  /// ends up believing they have left.
  static void dropStaff() {
    store.writeItem(_staffKey, null);
  }

  static void clear() {
    store.writeItem(_tenantKey, null);
    store.writeItem(_staffKey, null);
  }
}
