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

  /// The kept ("keep me signed in") copy of the tenant token, read from the
  /// keychain or localStorage itself rather than this process's memory.
  /// Throws when the store cannot be read.
  static Future<String?> persistedTenant() => store.readPersisted(_tenantKey);

  /// Put [token] back in the kept store if it is no longer there.
  ///
  /// Jose, 2026-11-16: "every new update seem to log me out". The desktop's
  /// token (row 801) was valid on the server and in use until the installer
  /// closed the app; the restart found no `ezbizi.token` in the keychain file,
  /// while the same file still held this install's phone_device_id from
  /// 2026-09-10. So the key was dropped from disk WHILE the app ran on its
  /// in-memory copy, and the next restart — an update — showed the sign-in
  /// screen. Nothing compared the two, so the loss stayed silent for hours.
  ///
  /// Session calls this after each successful /me while the person chose to
  /// stay signed in. Returns true when it had to write the token back, so the
  /// caller can report it: whatever removed it is then caught in the act.
  /// [stillWanted] is asked after the read, so a sign-out that lands while
  /// the keychain is answering is never undone.
  static Future<bool> keepTenant(
    String token, {
    required bool Function() stillWanted,
  }) async {
    final String? onDisk;
    try {
      onDisk = await store.readPersisted(_tenantKey);
    } catch (_) {
      // Cannot tell, so do nothing: rewriting on every unreadable answer
      // would hide a broken keychain behind a stream of writes.
      return false;
    }
    if (onDisk == token || !stillWanted()) return false;
    store.writeItem(_tenantKey, token, persist: true);
    return true;
  }

  /// Test seam: forget the loaded copy so [init] reads the store again.
  static void debugReset() => store.debugReset();

  /// The staff token only. A staff session ending (the Development tab's
  /// token expiring, a staff restore failing) must not take the customer
  /// session's kept token with it; [clear] is for ending both.
  static void dropStaff() {
    store.writeItem(_staffKey, null);
  }

  /// Both, always. Signing out of one while the other lingers is how somebody
  /// ends up believing they have left.
  static void clear() {
    store.writeItem(_tenantKey, null);
    store.writeItem(_staffKey, null);
  }
}
