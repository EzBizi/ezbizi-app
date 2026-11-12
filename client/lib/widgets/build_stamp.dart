import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/build_info.dart';
import '../core/desktop_updater.dart';
import 'version_history_panel.dart';

/// The version, small and out of the way — and the door into what shipped.
///
/// Shown on the sign-in screen and at the foot of the sidebar — the two places
/// somebody already is when they wonder whether the app has updated. Quiet
/// enough to ignore for months and specific enough to settle the question in
/// one glance when it matters.
///
/// Tapping it opens [VersionHistoryPanel]: each version, what it did, when.
/// That list is how we remember. The tooltip still names the exact bundle.
class BuildStamp extends StatelessWidget {
  const BuildStamp({
    super.key,
    this.onDark = false,
    this.compact = false,
    this.api,
  });

  /// Signed in: the panel shows the release on its way. Null on sign-in.
  final ApiClient? api;

  /// On the sign-in panel's dark half the theme's own muted colour disappears.
  final bool onDark;

  /// The version alone, for the 80px collapsed rail (Jose, layout 5,
  /// 2026-09-14).
  ///
  /// It used to draw nothing there, and the reason was right: *"half a build id
  /// is worse than none — it invites people to compare the wrong characters and
  /// conclude they are up to date when they are not."*
  ///
  /// This does not truncate anything. `short` is `v0.0.0201 · a1b2c3d` and it
  /// is the HASH that will not fit; the version on its own is eight characters
  /// and fits with room to spare. So what is dropped is a whole field rather
  /// than the end of one — and the field that is dropped is the one you cannot
  /// compare by eye anyway. The tooltip still carries both, and tapping still
  /// opens the history, so nothing is only available here.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final colour = onDark
        ? Colors.white.withValues(alpha: 0.4)
        : Theme.of(context).colorScheme.onSurfaceVariant.withValues(alpha: 0.7);

    final stamp = Tooltip(
      message: '${BuildInfo.full}\nOpen what\'s been built',
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          onTap: () => showVersionHistory(context, api: api),
          borderRadius: BorderRadius.circular(4),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 2),
            child: Text(
              compact ? BuildInfo.version : BuildInfo.short,
              style: TextStyle(
                fontSize: 11,
                color: colour,
                // Tabular figures so the string does not jitter between builds — it
                // sits in the corner of every screen and a number that shifts width
                // draws the eye for no reason.
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ),
        ),
      ),
    );

    // Nothing to check on the web or on Android: a browser re-fetches the app
    // every load and Play does the rest. Drawing a dead button on those would
    // be offering something that cannot happen.
    if (!DesktopUpdater.instance.supported) return stamp;

    return AnimatedBuilder(
      animation: DesktopUpdater.instance,
      builder: (context, _) {
        final u = DesktopUpdater.instance;

        return Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            stamp,
            const SizedBox(width: 2),
            _UpdateButton(updater: u, colour: colour),
          ],
        );
      },
    );
  }
}

/// The update control that lives beside the version.
///
/// Three states, and the icon is the whole message: a spinner while something
/// is happening, a filled arrow when a build is waiting, and an outline arrow
/// the rest of the time meaning "look now". Deliberately the same size and
/// weight as the version beside it — this sits in the corner of every screen
/// and is not something to be drawn to when there is nothing to do.
class _UpdateButton extends StatelessWidget {
  const _UpdateButton({required this.updater, required this.colour});

  final DesktopUpdater updater;
  final Color colour;

  @override
  Widget build(BuildContext context) {
    if (updater.busy || updater.checking) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: SizedBox(
          width: 11,
          height: 11,
          child: CircularProgressIndicator(strokeWidth: 1.5, color: colour),
        ),
      );
    }

    final waiting = updater.available != null;
    final pending = updater.secondsLeft;

    final message = updater.error != null
        ? updater.error!
        : waiting
        ? pending != null
              ? 'Version ${updater.available!.version} installs in ${pending}s. '
                    'Click to install it now.'
              : 'Version ${updater.available!.version} is ready. Click to install it.'
        : 'Check for a new version';

    return Tooltip(
      message: message,
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          onTap: waiting ? updater.install : updater.checkNow,
          borderRadius: BorderRadius.circular(4),
          child: Padding(
            padding: const EdgeInsets.all(3),
            child: Icon(
              waiting
                  ? Icons.system_update_alt
                  : Icons.refresh,
              size: 13,
              // A waiting update earns full contrast; a "check now" that nobody
              // needs to press does not.
              color: updater.error != null
                  ? Theme.of(context).colorScheme.error
                  : waiting
                  ? Theme.of(context).colorScheme.primary
                  : colour,
            ),
          ),
        ),
      ),
    );
  }
}
