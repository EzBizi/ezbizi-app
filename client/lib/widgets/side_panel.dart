import 'dart:async';

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
///
/// ── Reaching it without a mouse ──────────────────────────────────
///
/// Two separate things, both of which this class owns so that all 47 panels get
/// them at once:
///
///   - **A screen reader is told.** `scopesRoute` says a new surface has opened
///     and the page behind is no longer part of what is being read; `namesRoute`
///     with the panel's own [title] says which one. Without the pair, opening a
///     panel is silent and the reader carries on down the page underneath.
///   - **Keyboard focus moves in.** Flutter's ModalRoute focuses the route's
///     scope, which is enough for Tab to eventually find its way inside, but
///     nothing in the panel holds focus until then — so the first press of Tab
///     is spent and there is nothing highlighted to say where you are. This
///     takes focus explicitly, and gives it back on close, which the route
///     already does correctly.
///
/// Both were measured absent before they were added; see test/side_panel_test.
class SidePanel extends StatefulWidget {
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
  State<SidePanel> createState() => _SidePanelState();
}

class _SidePanelState extends State<SidePanel> {
  /// Where focus lands when nothing inside the panel asked for it.
  ///
  /// Skipped in traversal, so once it has been left by the first Tab it never
  /// comes back around — it is a starting point, not a stop.
  final _entry = FocusNode(debugLabel: 'Side panel', skipTraversal: true);

  @override
  void initState() {
    super.initState();

    // ── Why this is scheduled twice ──────────────────────────────
    //
    // Most panels are forms, and a form with `autofocus: true` on its first box
    // must keep it — landing in the Name field is the reason you opened the
    // thing. Autofocus is applied by the focus manager from a MICROTASK queued
    // during build; post-frame callbacks run at the end of the same frame, which
    // is BEFORE that microtask. So a plain post-frame `requestFocus` here would
    // win the race, and the pending autofocus would then be discarded — the
    // manager skips it once the scope already has a focused child.
    //
    // Queuing a microtask from the post-frame callback puts this behind the one
    // build queued, because microtasks run in order. By the time it runs, a
    // field that wanted focus has it, `hasFocus` is true for this node — which
    // reports its descendants as well as itself — and there is nothing to do.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      scheduleMicrotask(() {
        if (!mounted || _entry.hasFocus) return;

        _entry.requestFocus();
      });
    });
  }

  @override
  void dispose() {
    _entry.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Semantics(
      // "A surface opened, it is called this, and the page behind is not part of
      // it." The same three flags Flutter's own Drawer and AlertDialog set, for
      // the same reason.
      scopesRoute: true,
      namesRoute: true,
      explicitChildNodes: true,
      label: widget.title,
      child: Focus(
        focusNode: _entry,
        child: _panel(context, scheme),
      ),
    );
  }

  Widget _panel(BuildContext context, ColorScheme scheme) {
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
            maxWidth: widget.width,
            minWidth: widget.width > MediaQuery.sizeOf(context).width
                ? MediaQuery.sizeOf(context).width
                : widget.width,
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
                            widget.title,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                          if (widget.subtitle != null) ...[
                            const SizedBox(height: 6),
                            Text(
                              widget.subtitle!,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                        ],
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close),
                      // Named, because "Close" alone does not say close what when a
                      // panel sits over a page that also has controls.
                      tooltip: 'Close ${widget.title}',
                      onPressed: () => Navigator.of(context).maybePop(),
                    ),
                  ],
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: widget.scrollable
                    ? SingleChildScrollView(
                        padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
                        child: widget.child,
                      )
                    : Padding(
                        padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
                        child: widget.child,
                      ),
              ),
              if (widget.actions.isNotEmpty) ...[
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
                    children: widget.actions,
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
