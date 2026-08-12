import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter/services.dart';

import 'dashboard_card.dart';

/// One panel handed to a [DashboardGrid].
///
/// The key is the widget key the server uses — `hr.headcount`, `jobs.revenue`.
/// It is what a saved order names, so it has to be the panel's identity here
/// too rather than its position or its label.
class DashboardPanel {
  const DashboardPanel({
    required this.key,
    required this.title,
    required this.icon,
    required this.body,
    this.badge,
  });

  final String key;
  final String title;
  final IconData icon;

  /// What the panel says, below the standard header.
  final Widget body;

  /// A state label after the title.
  final Widget? badge;
}

/// Every dashboard's layout: equal-height rows of [DashboardCard], rearrangeable
/// by drag, by keyboard and by touch.
///
/// ── Equal-height rows, decided rather than discovered ────────────
///
/// Both dashboards used a `Wrap` of fixed-width, content-height children. A
/// short panel beside a tall one left a ragged hole under it and the row below
/// started at a different place on each side of the screen, so nothing lined up
/// anywhere. The owner's word for it was "unaligned".
///
/// The answer here is a grid of rows, each row as tall as the tallest card in
/// it. Deliberately NOT masonry: masonry has no holes but it also has no rows,
/// so the eye has nothing to read along and a card that grows shuffles every
/// card beneath it into a new column. Some cards will have space at the bottom;
/// that is the cost of a grid that lines up, and it was accepted.
///
/// Two columns when there is room and one when there is not — a phone gets a
/// single column, and a card that later wants to be double-width has a row
/// structure to sit in. That vocabulary is not built, because nothing declares
/// it yet.
///
/// ── Rearranging it without a mouse ───────────────────────────────
///
/// Three ways in, because a dashboard that can only be rearranged by dragging
/// is unusable to a keyboard user and unreliable on a phone:
///
///   - **Drag.** A handle in each card's header, dragged onto the card that
///     should give up its place. A mouse picks the card up on the press
///     itself, with no travel and no wait; a finger and a stylus have to hold
///     the grip for a moment first. That difference is not a preference — see
///     [_GripDrag]: the grip is a full-size touch target sitting inside a page
///     that scrolls, and a drag that started immediately under a finger would
///     win the gesture arena against the scroll view and leave a band on every
///     card that refused to scroll.
///   - **Keyboard.** Two buttons per card, reachable by Tab, labelled with the
///     panel they move and disabled at the ends of the list. Focus follows the
///     card, so a panel can be walked from last to first without hunting for
///     the button again after each press.
///   - **Announced.** Every move says where the card landed, because the two
///     buttons are otherwise silent to a screen reader — the card moved, and
///     nothing said so.
///
/// [onReorder] is handed the keys in their new order. Null means this dashboard
/// cannot be rearranged, and then no controls are drawn at all — a disabled
/// control invites somebody to work out why it does nothing.
class DashboardGrid extends StatefulWidget {
  const DashboardGrid({
    super.key,
    required this.panels,
    this.onReorder,
    this.spacing = 16,
    this.twoColumnsFrom = 900,
  });

  final List<DashboardPanel> panels;

  final void Function(List<String> keys)? onReorder;

  /// Between cards, both ways. The same figure the screens pad with.
  final double spacing;

  /// The width at which a second column fits. Not a breakpoint table — cards
  /// are self-contained, and there is only ever one question to ask.
  final double twoColumnsFrom;

  @override
  State<DashboardGrid> createState() => _DashboardGridState();
}

class _DashboardGridState extends State<DashboardGrid> {
  /// The move buttons' focus nodes, kept per panel key rather than per position
  /// so that focus survives the card changing places — which is the entire
  /// point of holding them at all.
  final Map<String, FocusNode> _earlier = {};
  final Map<String, FocusNode> _later = {};

  @override
  void didUpdateWidget(covariant DashboardGrid old) {
    super.didUpdateWidget(old);

    // A panel can leave: a role loses a tab, or the panel picker turns one off.
    // Its nodes would otherwise sit in these maps until the screen closed.
    final present = {for (final panel in widget.panels) panel.key};

    for (final map in [_earlier, _later]) {
      for (final key in map.keys.toList()) {
        if (!present.contains(key)) map.remove(key)!.dispose();
      }
    }
  }

  @override
  void dispose() {
    for (final node in [..._earlier.values, ..._later.values]) {
      node.dispose();
    }

    super.dispose();
  }

  FocusNode _node(Map<String, FocusNode> map, String key, String label) =>
      map.putIfAbsent(key, () => FocusNode(debugLabel: '$label $key'));

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= widget.twoColumnsFrom ? 2 : 1;
        final rows = <Widget>[];

        for (var start = 0; start < widget.panels.length; start += columns) {
          if (rows.isNotEmpty) rows.add(SizedBox(height: widget.spacing));

          rows.add(
            // Every card in the row is given the height of the tallest. This is
            // the whole alignment fix, and it is one widget.
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (var column = 0; column < columns; column++) ...[
                    if (column > 0) SizedBox(width: widget.spacing),
                    Expanded(
                      // An empty slot on a short last row, so the column widths
                      // are the same on every row rather than the final card
                      // stretching across the screen.
                      child: start + column < widget.panels.length
                          ? _cardAt(start + column)
                          : const SizedBox.shrink(),
                    ),
                  ],
                ],
              ),
            ),
          );
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: rows,
        );
      },
    );
  }

  Widget _cardAt(int index) {
    final panel = widget.panels[index];

    final card = DashboardCard(
      title: panel.title,
      icon: panel.icon,
      badge: panel.badge,
      controls: widget.onReorder == null ? null : _controls(index, panel),
      child: panel.body,
    );

    if (widget.onReorder == null) {
      return KeyedSubtree(key: ValueKey(panel.key), child: card);
    }

    return KeyedSubtree(
      key: ValueKey(panel.key),
      child: DragTarget<int>(
        onWillAcceptWithDetails: (details) => details.data != index,
        onAcceptWithDetails: (details) => _move(details.data, index),
        builder: (context, candidate, rejected) => Stack(
          children: [
            card,
            if (candidate.isNotEmpty)
              // Says where the card would land. Drawn over the card rather than
              // by moving anything, so the page does not reshuffle under the
              // pointer while it is still deciding.
              Positioned.fill(
                child: IgnorePointer(
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius:
                          BorderRadius.circular(DashboardCard.radius),
                      border: Border.all(
                        color: Theme.of(context).colorScheme.primary,
                        width: 2,
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// The card's own controls: earlier, later, and something to drag.
  Widget _controls(int index, DashboardPanel panel) {
    final last = widget.panels.length - 1;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        _moveButton(
          node: _node(_earlier, panel.key, 'earlier'),
          icon: Icons.arrow_upward,
          // "Earlier" rather than "up": in two columns the first card of a row
          // moves LEFT, and a button that says up would be describing something
          // that does not happen.
          tooltip: 'Move “${panel.title}” earlier',
          onPressed: index == 0
              ? null
              : () => _move(index, index - 1, fromKeyboard: true),
        ),
        _moveButton(
          node: _node(_later, panel.key, 'later'),
          icon: Icons.arrow_downward,
          tooltip: 'Move “${panel.title}” later',
          onPressed: index == last
              ? null
              : () => _move(index, index + 1, fromKeyboard: true),
        ),
        _handle(index, panel),
      ],
    );
  }

  Widget _moveButton({
    required FocusNode node,
    required IconData icon,
    required String tooltip,
    required VoidCallback? onPressed,
  }) {
    return IconButton(
      focusNode: node,
      onPressed: onPressed,
      icon: Icon(icon),
      // A small glyph in a full-size button. The touch target stays at the
      // framework's interactive minimum — two of these plus the grip fit a
      // 375-point card, and a control a finger misses is a control a phone does
      // not have.
      iconSize: 18,
      // What a screen reader reads, as well as what a pointer hovering sees, so
      // these are not icon-only to anybody.
      tooltip: tooltip,
    );
  }

  Widget _handle(int index, DashboardPanel panel) {
    final grip = _grip(
      Icon(
        Icons.drag_indicator,
        size: 18,
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
    );

    // Two of them around one grip, and never both at once: each answers to a
    // different family of pointer, so whichever kind arrives, exactly one
    // enters the gesture arena. See [_GripDrag].
    Widget drag({required bool hold, required Widget child}) => _GripDrag(
      hold: hold,
      data: index,
      feedback: _preview(panel),
      childWhenDragging: Opacity(opacity: 0.3, child: grip),
      child: child,
    );

    return Tooltip(
      // Not "in front of", which is only true going backwards: dropping a card
      // on one that is LATER than it puts it after that card, the way every
      // reorderable list behaves. A sentence that is right half the time is
      // worse than one that says less.
      message: 'Drag “${panel.title}” onto the card whose place it should take',
      // Hovering and screen readers only. A Tooltip's own touch trigger is a
      // long press, which is exactly the gesture that now PICKS THE CARD UP,
      // and leaving both attached puts two 500ms timers in one gesture arena.
      //
      // ON TOUCH the drag wins that race — it is deeper in the tree, so it is
      // handed the pointer first and starts its timer first, and equal timers
      // fire in the order they were made. That is a tree order and a timer
      // queue, which is not what "the card comes up rather than a caption"
      // should rest on.
      //
      // ON A STYLUS IT DOES NOT WIN, AND THIS LINE IS WHAT SAVES IT. Tooltip's
      // trigger recognizer excludes a mouse and INCLUDES a stylus, while the
      // delayed drag listener below is registered for touch only — so a held
      // pen has a long-press tooltip and no competing drag at all. Without
      // `manual` the caption wins outright and the card is never picked up.
      // This is load-bearing, not belt-and-braces, and it was nearly written
      // off as the latter.
      //
      // A held stylus is also what tells the two apart, so the claim that "no
      // test can tell" was wrong by about ten lines — see
      // `a held stylus picks the card up and shows no caption`.
      //
      // Hovering is untouched (triggerMode does not apply to a mouse) and so
      // is the sentence a screen reader reads, which `Tooltip` puts in the
      // semantics tree however it is triggered. Both are asserted.
      triggerMode: TooltipTriggerMode.manual,
      child: MouseRegion(
        cursor: SystemMouseCursors.grab,
        child: drag(hold: false, child: drag(hold: true, child: grip)),
      ),
    );
  }

  /// The grip, boxed to the same [kMinInteractiveDimension] the move buttons
  /// beside it occupy — so the three controls sit on one line at one height,
  /// and dragging is as reachable by a finger as pressing is.
  ///
  /// The box being 48 points is not on its own enough for that second half:
  /// hit testing defers to the child by default, and the child is an 18-point
  /// glyph in the middle. [_GripDrag] claims the whole box.
  Widget _grip(Widget grip) => SizedBox(
    width: kMinInteractiveDimension,
    height: kMinInteractiveDimension,
    child: Center(child: grip),
  );

  /// What follows the pointer: the card's name, not a copy of the card.
  ///
  /// A full-size copy would need the card's width, which only the row knows, and
  /// would cover the cards it is being dropped between — the two things the
  /// person dragging is trying to look at.
  Widget _preview(DashboardPanel panel) {
    final theme = Theme.of(context);

    return Material(
      elevation: 6,
      color: theme.colorScheme.surface,
      borderRadius: BorderRadius.circular(DashboardCard.radius),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(panel.icon, size: 18, color: theme.colorScheme.onSurfaceVariant),
            const SizedBox(width: 8),
            Text(panel.title, style: theme.textTheme.titleSmall),
          ],
        ),
      ),
    );
  }

  /// Move the card at [from] so that it sits at [to].
  ///
  /// The whole order goes back to the caller, not the pair that changed. A
  /// dashboard's order is one fact; handing over a swap would make the screen
  /// and the stored preference two places that have to agree about what the
  /// list currently is.
  void _move(int from, int to, {bool fromKeyboard = false}) {
    final reorder = widget.onReorder;

    if (reorder == null || from == to) return;

    final panel = widget.panels[from];
    final keys = [for (final one in widget.panels) one.key];

    keys.insert(to, keys.removeAt(from));

    // Said out loud. Two buttons that silently rearrange the page are, to
    // somebody who cannot see it, two buttons that do nothing.
    //
    // Sent to THIS view rather than the implicit one: the app is web today and
    // desktop and mobile after it (R7), and `SemanticsService.announce` asserts
    // there is exactly one window. An announcement that works until the day the
    // app has two is not a keyboard path, it is a keyboard path with an expiry
    // date on it.
    SemanticsService.sendAnnouncement(
      View.of(context),
      '${panel.title} moved to position ${to + 1} of ${keys.length}.',
      Directionality.of(context),
    );

    if (fromKeyboard) {
      // Focus follows the card. Which button, though, is decided here rather
      // than by asking the framework afterwards: the button just pressed is
      // disabled once the card reaches the end it was heading for, and focus
      // dropped there sends Tab back to the top of the page.
      final wantLater = to == 0
          ? true
          : (to == keys.length - 1 ? false : to > from);

      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;

        (wantLater ? _later : _earlier)[panel.key]?.requestFocus();
      });
    }

    reorder(keys);
  }
}

/// Half of the grip's drag: a [Draggable] that answers to one family of
/// pointers, on one gesture. Two of them wrap the one grip.
///
/// ── Why the gesture is not the same for everybody ────────────────
///
/// The grip is a 48-point target — it has to be, or a finger misses it — and it
/// sits inside a page that scrolls. A plain [Draggable] recognises a drag on
/// the first pixel of movement, so it wins the gesture arena against the scroll
/// view before the scroll view has decided anything, and MEASURED on a
/// 375x700 window that made a swipe starting on the grip move the page 0
/// points while the same swipe on the card body moved it 300. It did not
/// rearrange anything either: the swipe ended over the card it began on. A
/// 48-point band on every card that silently refuses to scroll is worse than
/// no grip at all.
///
/// So a finger has to hold the grip before the card comes up, and everything
/// else still picks it up at once. That is the answer Flutter itself gives:
/// [ReorderableListView] wraps its drag handle in a
/// `ReorderableDragStartListener` on a desktop and the whole tile in a
/// `ReorderableDelayedDragStartListener` on a phone, and both of those are the
/// same one-method override used here — [Draggable.createRecognizer], which is
/// also what `LongPressDraggable` overrides. The delay, the hit slop that
/// cancels the hold the moment the finger travels, and the arena that hands the
/// swipe back to the scroll view are all the framework's.
///
/// It is not free, and the price is worth writing down. A swipe that starts on
/// the grip now has to win a contested arena, so the scroll view is not handed
/// the gesture until the finger has passed the touch slop, and that first
/// ~20 points of travel is dropped rather than replayed — MEASURED, the same
/// 300-point swipe moves the page 300 points from the card body and 280 from
/// the grip. That is the standard toll for any contested drag surface: every
/// tile of a `ReorderableListView` on a phone pays exactly the same one.
///
/// ── Why the pointer and not the platform ─────────────────────────
///
/// `ReorderableListView` chooses by `Theme.of(context).platform`, which answers
/// "which operating system" rather than "what is touching the screen". A
/// Windows laptop with a touchscreen answers "desktop" while somebody scrolls
/// it with a finger, and this app is web today with desktop and mobile behind
/// it (R7), so the same build has to be right for both hands at once. The
/// pointer's own kind is the fact that matters, and the arena already filters
/// on it: each recognizer is simply told which pointers are its own, so exactly
/// one of the two ever competes for any given pointer.
class _GripDrag extends Draggable<int> {
  const _GripDrag({
    required this.hold,
    required super.data,
    required super.feedback,
    required super.childWhenDragging,
    required super.child,
  }) : super(
         // All 48 points of the grip, not the 18-point glyph in the middle of
         // it. Left to defer to its child, the corners of the target are
         // decoration — MEASURED: a drag started two points inside the corner
         // moved nothing at all.
         hitTestBehavior: HitTestBehavior.opaque,
       );

  /// Whether the card has to be held before it comes up.
  final bool hold;

  /// Everything that is not a finger.
  ///
  /// Written as "the rest" rather than as a list of kinds, so that a pointer
  /// kind Flutter adds later drags a card immediately — which is the behaviour
  /// every pointer had before — rather than being unable to drag one at all.
  static final Set<PointerDeviceKind> _pointers = PointerDeviceKind.values
      .toSet()
    ..removeAll(_held);

  /// The pointers that have to HOLD the grip before the card comes up.
  ///
  /// A stylus is here with the finger, and it is not an obvious call. It is in
  /// the immediate set in every other respect — it is precise, it hovers, it
  /// has no fat-finger problem. But it is used on a tablet, on the same
  /// scrolling page a finger uses, and Flutter's own Tooltip classes it with
  /// touch rather than with the mouse for exactly that reason. A pen that
  /// picked a card up on the first pixel would leave the same dead band a
  /// finger did.
  static const Set<PointerDeviceKind> _held = {
    PointerDeviceKind.touch,
    PointerDeviceKind.stylus,
    PointerDeviceKind.invertedStylus,
  };

  @override
  MultiDragGestureRecognizer createRecognizer(
    GestureMultiDragStartCallback onStart,
  ) {
    if (!hold) {
      return ImmediateMultiDragGestureRecognizer(
        debugOwner: this,
        supportedDevices: _pointers,
      )..onStart = onStart;
    }

    return DelayedMultiDragGestureRecognizer(
      debugOwner: this,
      supportedDevices: _held,
    )..onStart = (position) {
      final drag = onStart(position);

      // The same click `LongPressDraggable` gives a held pickup, and for its
      // reason: the finger that is holding the card is also covering it, so
      // the feedback that says "it is up now" cannot only be something to look
      // at.
      if (drag != null) HapticFeedback.selectionClick();

      return drag;
    };
  }
}
