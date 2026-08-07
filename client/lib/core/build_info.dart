/// Which build you are actually looking at.
///
/// ── Why a version number is not enough on its own ────────────────
///
/// The point of showing this is to settle "am I on the newest one?" — a
/// question that came up because a stale service worker served a months-old
/// build while every header said otherwise, and nothing on screen could
/// contradict it.
///
/// A hand-maintained version cannot settle it. The first time I ship without
/// bumping the file, the number on screen and the code behind it disagree, and
/// it becomes one more thing that looks right and is not.
///
/// So there are two values, and the second is the one that cannot lie:
///
///   version   0.0.0001 — a human number, bumped deliberately for releases
///   build     the git commit the bundle was compiled from, stamped at build
///             time, different on every deploy whether or not anybody
///             remembered anything
///
/// Comparing the build against `git log -1` on the box answers the question
/// exactly, with no judgement required.
class BuildInfo {
  /// From client/VERSION, passed at build time. "dev" in an unconfigured build,
  /// which is itself worth seeing — it means somebody built by hand.
  static const String version =
      String.fromEnvironment('APP_VERSION', defaultValue: 'dev');

  /// The short commit hash, with a trailing `+` when the working tree had
  /// uncommitted changes. That marker matters: a build from a dirty tree is not
  /// reproducible from the hash it claims.
  static const String build =
      String.fromEnvironment('BUILD_ID', defaultValue: 'local');

  /// When it was compiled, UTC, to the minute.
  static const String builtAt =
      String.fromEnvironment('BUILT_AT', defaultValue: '');

  /// `v0.0.0001 · a1b2c3d` — short enough for a sidebar footer.
  static String get short => 'v$version · $build';

  /// The whole thing, for a tooltip. Someone reporting a problem can read this
  /// out and it identifies the bundle precisely.
  static String get full => builtAt.isEmpty
      ? short
      : '$short\nBuilt $builtAt UTC';
}
