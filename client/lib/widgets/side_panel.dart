import 'dart:async';

import 'package:flutter/material.dart';

import '../core/theme.dart';

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
/// Two separate things, both of which this class owns so that every panel in
/// the app gets them at once rather than each one remembering:
///
///   - **A screen reader is told.** `scopesRoute` says a new surface has opened
///     and the page behind is no longer part of what is being read; `namesRoute`
///     with the panel's own [title] says which one. Without the pair, opening a
///     panel is silent and the reader carries on down the page underneath.
///   - **Keyboard focus moves in.** Flutter's ModalRoute focuses the route's
///     scope, which is enough for Tab to eventually find its way inside, but
///     nothing in the panel holds focus until then — so the first press of Tab
///     is spent and there is nothing highlighted to say where you are. This
///     takes focus explicitly on open.
///
/// ── Giving focus BACK is the framework's job, not this class's ───
///
/// Closing restores focus to the control that opened the panel, and there is
/// deliberately no code here doing it. A FocusScopeNode remembers its
/// focusedChild, the route beneath keeps its own scope while the panel is up,
/// and popping hands focus back to what that scope was last on. Adding a
/// remember-and-restore of our own would be a second implementation racing the
/// framework's, and the failure mode of the two disagreeing is worse than
/// either alone.
///
/// Both halves are asserted in test/side_panel_test — the taking here, and the
/// giving back that this class does not do.
class SidePanel extends StatefulWidget {
  const SidePanel({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.actions = const [],
    this.headerActions = const [],
    this.width = 440,
    this.scrollable = true,
    this.docked = false,
    this.onClose,
    this.onBack,
  });

  final String title;
  final String? subtitle;
  final Widget child;

  /// Drawn IN the page rather than over it: beside a list, as the record of
  /// the row that is open (Jobs and Customers, queue 24). No slide, no scrim,
  /// no rounded edge, and it does not take focus when it appears — the list
  /// it sits beside is where the keyboard was, and arrowing down that list is
  /// the whole point of keeping the list on screen.
  final bool docked;

  /// What the close button does. Null pops the route, which is right for the
  /// panel [showSidePanel] opens and wrong for a docked one, which has no
  /// route of its own to pop — popping it would close the SCREEN.
  final VoidCallback? onClose;

  /// Drawn in place of a list on a phone: a way back where the close button
  /// was, so the system Back finds it (see pressInPageBack) and the page reads
  /// as the list's next page rather than as something laid over it.
  final VoidCallback? onBack;

  /// The panel's footer. Kept out of the body so the primary action stays
  /// visible no matter how far the form scrolls.
  final List<Widget> actions;

  /// Small icon buttons drawn in the header, just before the close button —
  /// controls for the body itself (a camera's Full screen), not the form's
  /// primary action, which belongs in [actions].
  final List<Widget> headerActions;

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

    // Docked, focus stays in the list the panel sits beside.
    if (widget.docked) return;

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

    // Part of the page, so none of the three route flags: nothing opened, and
    // the list beside it is still part of what is being read.
    if (widget.docked) {
      return Material(color: scheme.surface, child: _content(context));
    }

    return Semantics(
      // "A surface opened, it is called this, and the page behind is not part of
      // it." The same three flags Flutter's own Drawer and AlertDialog set, for
      // the same reason.
      scopesRoute: true,
      namesRoute: true,
      explicitChildNodes: true,
      label: widget.title,
      child: Focus(focusNode: _entry, child: _panel(context, scheme)),
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
          // INSIDE the Material, not around it.
          //
          // `showGeneralDialog` covers the whole screen, status bar and home
          // indicator included, and nothing here inset for them. On a desktop
          // there is no status bar so it never showed; on an iPhone the header
          // row drew UNDER the notch, which put the close button beneath the
          // clock where it cannot be tapped. The panel has no other way out on
          // a phone -- Escape needs a keyboard and the scrim is off the side --
          // so the app had to be force-quit. Reported from an iPhone on
          // 0.0.0129, and it applies to all 34 screens that open a panel.
          //
          // Wrapping the Material itself would inset the SURFACE, leaving a
          // floating rounded card with a gap above and below it -- the modal
          // look this panel exists to avoid. Padding the content instead keeps
          // the surface bleeding to every edge and moves only the parts you
          // touch.
          child: SafeArea(child: _content(context)),
        ),
      ),
    );
  }

  /// A docked panel can be a thousand pixels wide, and a form stretched that
  /// far reads as one long field per line. Floating, the panel's own [width]
  /// already bounds it.
  Widget _readable(Widget child) => widget.docked
      ? Align(
          alignment: Alignment.topLeft,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: child,
          ),
        )
      : child;

  Widget _content(BuildContext context) {
    // Mobile review 2026-11-13, P6: 24 a side left a phone 327 of 375.
    final g = Gutter.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(g, 20, 12, 16),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (widget.onBack != null) ...[
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back',
                  onPressed: widget.onBack,
                ),
                const SizedBox(width: 4),
              ],
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
              ...widget.headerActions,
              if (widget.onBack == null)
                IconButton(
                  icon: const Icon(Icons.close),
                  // Named, because "Close" alone does not say close what when a
                  // panel sits over a page that also has controls.
                  tooltip: 'Close ${widget.title}',
                  onPressed:
                      widget.onClose ?? () => Navigator.of(context).maybePop(),
                ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: widget.scrollable
              ? SingleChildScrollView(
                  padding: EdgeInsets.fromLTRB(g, 20, g, 20),
                  child: _readable(widget.child),
                )
              : Padding(
                  padding: EdgeInsets.fromLTRB(g, 20, g, 20),
                  child: _readable(widget.child),
                ),
        ),
        if (widget.actions.isNotEmpty) ...[
          const Divider(height: 1),
          Padding(
            padding: EdgeInsets.fromLTRB(g, 14, g, 18),
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

/// A list pane's title row: a back arrow before the title when [showBack],
/// which is how the pane opens full screen on a phone.
Widget paneHeader(
  BuildContext context,
  String title, {
  required bool showBack,
  required VoidCallback? onBack,
  String backTooltip = 'Back to the list',
  bool ellipsis = false,
}) => Padding(
  padding: EdgeInsets.fromLTRB(showBack ? 8 : 20, 12, 20, 8),
  child: Row(
    children: [
      if (showBack)
        IconButton(
          icon: const Icon(Icons.arrow_back),
          tooltip: backTooltip,
          onPressed: onBack,
        ),
      Expanded(
        child: Text(
          title,
          style: Theme.of(context).textTheme.titleMedium,
          overflow: ellipsis ? TextOverflow.ellipsis : null,
        ),
      ),
    ],
  ),
);

/// A form pane's Cancel and save buttons, at its foot.
Widget paneFooter({
  required VoidCallback? onCancel,
  required VoidCallback? onSave,
  String saveLabel = 'Save',
}) => Padding(
  padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
  child: Wrap(
    spacing: 8,
    runSpacing: 8,
    alignment: WrapAlignment.end,
    children: [
      TextButton(onPressed: onCancel, child: const Text('Cancel')),
      FilledButton(onPressed: onSave, child: Text(saveLabel)),
    ],
  ),
);

/// A form pane: [paneHeader], its fields scrolling beneath (a spinner while
/// [loading]), and [paneFooter]. Back and Cancel both call [onCancel], and
/// neither works while [busy].
Widget formPane(
  BuildContext context,
  String title, {
  required bool showBack,
  required bool busy,
  required VoidCallback onCancel,
  required VoidCallback? onSave,
  String saveLabel = 'Save',
  String backTooltip = 'Back to the list',
  bool loading = false,
  required List<Widget> children,
}) => Column(
  crossAxisAlignment: CrossAxisAlignment.stretch,
  children: [
    paneHeader(
      context,
      title,
      showBack: showBack,
      onBack: busy ? null : onCancel,
      backTooltip: backTooltip,
    ),
    Expanded(
      child: loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: children,
              ),
            ),
    ),
    paneFooter(
      onCancel: busy ? null : onCancel,
      onSave: onSave,
      saveLabel: saveLabel,
    ),
  ],
);
