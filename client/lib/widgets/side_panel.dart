import 'package:flutter/material.dart';

/// A panel that slides in from the right — the app's answer to a modal dialog.
///
/// ── Why there are no dialogs in this app ─────────────────────────
///
/// A centred modal covers the thing it is about. Adding a company to the
/// organization screen put a box over the tree you were adding to, so the one
/// piece of context that would tell you whether you were doing it right was
/// hidden behind the form asking you to do it.
///
/// A side panel keeps that context visible beside the form. That is the whole
/// argument, and it holds for editing a customer beside a list, or a job beside
/// a calendar, in exactly the same way.
///
/// ── What this is not ─────────────────────────────────────────────
///
/// Not a bottom sheet, which on a wide screen wastes the axis there is most of.
/// Not a new route with its own back button, for anything small — losing your
/// place on the screen behind is the cost a panel exists to avoid. Something
/// substantial enough to want a URL should be a screen instead.
class SidePanel extends StatelessWidget {
  const SidePanel({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.actions = const [],
    this.width = 440,
    this.scrollable = true,
  });

  final String title;
  final String? subtitle;
  final Widget child;

  /// The panel's footer. Kept out of the body so the primary action stays
  /// visible no matter how far the form scrolls.
  final List<Widget> actions;

  final double width;

  /// Whether the panel scrolls the body for you.
  ///
  /// True suits a form, which is most of them. Set it false when the body wants
  /// the height for itself — a long list that should stay lazy, or a surface
  /// that handles its own drags.
  ///
  /// A scroll view is not free to impose. It hands the body unbounded height, so
  /// a `ListView` inside one builds every row; and once it has anything to
  /// scroll it puts a vertical-drag recognizer above everything in the body,
  /// which accepts at a smaller slop than a pan or scale recognizer and takes
  /// the gesture. That is how the logo cropper lost its drag.
  ///
  /// False gives the body a bounded height instead, and it decides what to do
  /// with it.
  final bool scrollable;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Align(
      alignment: Alignment.centerRight,
      child: Material(
        color: scheme.surface,
        // Full height, flush to the right edge, and only the left corners are
        // rounded — a floating rounded rectangle would read as the modal this
        // replaces.
        borderRadius: const BorderRadius.horizontal(left: Radius.circular(16)),
        child: ConstrainedBox(
          constraints: BoxConstraints(
            // Never wider than the window on a small screen, where it becomes
            // a full-width sheet rather than a panel with nothing beside it.
            maxWidth: width,
            minWidth: width > MediaQuery.sizeOf(context).width
                ? MediaQuery.sizeOf(context).width
                : width,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 20, 12, 16),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            title,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                          if (subtitle != null) ...[
                            const SizedBox(height: 6),
                            Text(
                              subtitle!,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                        ],
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close),
                      tooltip: 'Close',
                      onPressed: () => Navigator.of(context).maybePop(),
                    ),
                  ],
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: scrollable
                    ? SingleChildScrollView(
                        padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
                        child: child,
                      )
                    : Padding(
                        padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
                        child: child,
                      ),
              ),
              if (actions.isNotEmpty) ...[
                const Divider(height: 1),
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 14, 24, 18),
                  // Wraps rather than overflows. Three buttons is about 344px,
                  // which fits the panel until the window is narrow enough that
                  // the panel becomes the window — and that is exactly where a
                  // Row would have thrown.
                  child: Wrap(
                    alignment: WrapAlignment.end,
                    spacing: 10,
                    runSpacing: 8,
                    children: actions,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Open a side panel and wait for whatever it returns.
///
/// Escape closes it and the scrim is tappable, because both are what everybody
/// already expects of something laid over the page — the objection was to a
/// modal COVERING its own subject, not to the idea of a temporary layer.
Future<T?> showSidePanel<T>(
  BuildContext context, {
  required Widget Function(BuildContext) builder,
}) {
  return showGeneralDialog<T>(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'Dismiss',
    barrierColor: Colors.black.withValues(alpha: 0.35),
    transitionDuration: const Duration(milliseconds: 220),
    pageBuilder: (context, _, _) => builder(context),
    transitionBuilder: (context, animation, _, child) {
      // From the right, decelerating — the direction it lives in, so it reads
      // as a drawer rather than as something that appeared on top.
      return SlideTransition(
        position: Tween(begin: const Offset(1, 0), end: Offset.zero).animate(
          CurvedAnimation(parent: animation, curve: Curves.easeOutCubic),
        ),
        child: child,
      );
    },
  );
}
