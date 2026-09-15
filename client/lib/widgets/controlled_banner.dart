import 'package:flutter/material.dart';

/// The strip the app shows across the top while somebody is remotely driving
/// this machine.
///
/// ── Why this exists when a tray notice already did ────────────────
///
/// The native notice is a Shell_NotifyIcon tooltip plus a one-off balloon.
/// Both are courtesies the OS may decline — Focus Assist, a full-screen
/// window, or a notification policy set years ago will swallow a balloon, and
/// the call reports success either way. That is tolerable for "your download
/// finished" and wrong for "somebody is watching you work": the one case where
/// a notice must not fail silently is the one case it cannot report.
///
/// This surface is drawn by the app, so no OS setting removes it.
///
/// ── What it deliberately does NOT do ──────────────────────────────
///
/// It cannot be dismissed. A consent notice with a close button is a notice
/// that is absent thirty seconds later, and the fact it states is still true.
/// It also carries no "stop" action: the person being controlled cannot end
/// the session from here, and offering a button that did nothing would be
/// worse than offering none. Closing the desktop app ends it, because the
/// agent's poll loop is what applies the input.
///
/// It says WHO in company terms rather than naming the manager. The agent is
/// told that control is wanted, not who wanted it, and inventing a name here
/// would be a claim this widget has no evidence for.
class ControlledBanner extends StatelessWidget {
  const ControlledBanner({super.key});

  static const String message =
      'Your screen is being controlled by your company.';

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Semantics(
      liveRegion: true,
      container: true,
      child: Container(
        width: double.infinity,
        color: scheme.errorContainer,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        child: Row(
          children: [
            // Beside the words, never instead of them — the sentence is the
            // notice and the icon is emphasis.
            Icon(Icons.mouse_outlined, size: 18, color: scheme.onErrorContainer),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                message,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: scheme.onErrorContainer,
                      fontWeight: FontWeight.w600,
                    ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
