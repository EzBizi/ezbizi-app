import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';

import '../core/theme.dart';

/// The title block at the top of every module.
///
/// Approved as `docs/previews/module-page-header-layouts.html` — desktop
/// layout 1 with mobile layout 5. It is drawn ONCE, by the shell, above
/// whatever screen the module rail is showing, so a module cannot be built
/// without one and a new module gets it without doing anything.
///
/// ── What it is fixing ────────────────────────────────────────────
///
/// Sixteen screens rendered the shared tab row and exactly one of them printed
/// the module's name, with a `fontSize: 25` typed into the screen. The
/// other fifteen printed nothing, so the page never said which module you were
/// in — and on a phone, where the sidebar is a Drawer, nothing on screen said
/// so at all until you opened it.
///
/// ── Why the module's name and not the pane's ─────────────────────
///
/// Two names competed: the module (Human Resources) and the pane ("At a
/// glance"). The tab row already says which pane you are in and highlights
/// itself while doing it, so a heading repeating it is the same fact twice.
/// Which is what makes this close to free: it does not add a row, it replaces
/// the pane heading that was already there.
///
/// ── Why the title is never written in a screen ───────────────────
///
/// It is the server's `label` for the module, handed down from the rail.
/// An organization may rename any module (§8), and a name hardcoded in a
/// screen would go stale the moment they did — silently, because it would
/// still look like a perfectly good title.
class ModulePageHeader extends StatelessWidget {
  const ModulePageHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.actions = const [],
    this.beside,
    this.toolbar,
  });

  /// The module's name, as the SERVER spells it. Never a literal.
  final String title;

  /// One line saying what the screen is for. Null is fine and common.
  final String? subtitle;

  final List<Widget> actions;

  /// Drawn right after the title rather than at the far end: the tab's (?)
  /// and ▶ (UI queue 81), which belong to the title, not to the page.
  final Widget? beside;

  /// Design 161 T1: the module's own search and filters, on the right of
  /// the title row. Null (most modules) leaves the side empty.
  final Widget? toolbar;

  /// Below this the phone layout applies: smaller title, and no subtitle.
  ///
  /// The same number the call desk and Your account already split on, so the
  /// app has one idea of where a phone begins rather than three.
  static const double phoneBelow = 600;

  /// What a phone header leaves its actions once the title has its width.
  // ponytail: the (i) and the help buttons are estimated, not measured: 24
  // and 86 (the (?) and the video button, 40 each + 6; the video one comes
  // from a future). One button budgets 40 too many, which only sends the
  // action to its own line sooner. Mobile review 11-24: 46 cut "Customers".
  double _actionRoom(
    BuildContext context,
    double width,
    TextStyle? style,
    bool hasSubtitle,
  ) {
    final painter = TextPainter(
      text: TextSpan(text: title, style: style),
      maxLines: 1,
      textDirection: Directionality.of(context),
      textScaler: MediaQuery.textScalerOf(context),
    )..layout();
    final titleWidth =
        painter.width + (hasSubtitle ? 24 : 0) + (beside == null ? 0 : 86);
    painter.dispose();
    // Gutter.phone on the left, 12 on the right, 12 between, 2 to spare.
    return width - Gutter.phone - 26 - titleWidth;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < phoneBelow;

        // Design 161 T1: the subtitle is the (i) beside the title, at every
        // width, so the row is one line and the list starts a line higher.
        final hasSubtitle = subtitle != null && subtitle!.trim().isNotEmpty;

        // headlineMedium is the theme's own, and the only heading token it
        // defined before this work. On a phone the same token at 22 rather
        // than a fifth hardcoded size.
        final titleStyle = phone
            ? theme.textTheme.headlineMedium?.copyWith(
                fontSize: 22,
                letterSpacing: -0.5,
              )
            : theme.textTheme.headlineMedium;

        final titleRow = Row(
          mainAxisSize: toolbar == null ? MainAxisSize.max : MainAxisSize.min,
          children: [
            Flexible(
              child: Text(
                title,
                // One line on a phone: beside a primary action (D3) a long
                // name wrapped and pushed the screen below the fold.
                maxLines: phone ? 1 : null,
                overflow: phone ? TextOverflow.ellipsis : null,
                style: titleStyle,
              ),
            ),
            if (hasSubtitle) ...[
              const SizedBox(width: 6),
              Tooltip(
                key: const ValueKey('module-subtitle-info'),
                message: subtitle!,
                child: Icon(
                  Icons.info_outline,
                  size: 18,
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
            if (beside != null) ...[const SizedBox(width: 6), beside!],
          ],
        );

        final padding = EdgeInsets.fromLTRB(
          phone ? Gutter.phone : Gutter.wide,
          phone ? 14 : 18,
          12,
          0,
        );
        final room = phone && actions.isNotEmpty
            ? _actionRoom(
                context,
                constraints.maxWidth,
                titleStyle,
                hasSubtitle,
              )
            : 0.0;

        // UI pass 11-16: beside a long title ("Automations", "Fleet
        // Management") the action would shrink past reading, so a phone
        // gives it its own line under the title instead. Decided by the
        // action's own width (mobile review 11-24, finding 15): beside the
        // title only if it fits there at 90% or more; "Add purchase order"
        // was drawn at ~75% beside "Inventory".
        if (phone && actions.isNotEmpty && toolbar == null) {
          return Padding(
            padding: padding,
            child: _TitleThenActions(
              room: room,
              children: [
                titleRow,
                FittedBox(
                  key: const ValueKey('module-header-actions'),
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerEnd,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      for (var i = 0; i < actions.length; i++) ...[
                        if (i > 0) const SizedBox(width: 8),
                        actions[i],
                      ],
                    ],
                  ),
                ),
              ],
            ),
          );
        }

        return Padding(
          padding: padding,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              if (toolbar == null)
                Expanded(child: titleRow)
              else ...[
                Flexible(child: titleRow),
                const SizedBox(width: 16),
                Expanded(
                  flex: 3,
                  child: Align(
                    key: const ValueKey('module-toolbar'),
                    alignment: AlignmentDirectional.centerEnd,
                    child: toolbar,
                  ),
                ),
              ],
              if (actions.isNotEmpty && phone) ...[
                const SizedBox(width: 12),
                // UI pass 11-16: "Add customer" took the row and cut the
                // title to "Custo…". The title keeps the width it needs; the
                // action gets the rest and scales down a little to fit.
                ConstrainedBox(
                  key: const ValueKey('module-header-actions'),
                  constraints: BoxConstraints(
                    maxWidth: room < 140 ? 140 : room,
                  ),
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerEnd,
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        for (var i = 0; i < actions.length; i++) ...[
                          if (i > 0) const SizedBox(width: 8),
                          actions[i],
                        ],
                      ],
                    ),
                  ),
                ),
              ] else if (actions.isNotEmpty) ...[
                const SizedBox(width: 12),
                // Wrapped rather than a Row: a long title and three actions do
                // not both fit at 375, and the actions dropping to a second
                // line is better than either of them being clipped.
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: actions,
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}

/// Where a module hands its toolbar to the shell's title row (design 161 T1).
///
/// The shell puts one above each module's screen; a screen that has a
/// toolbar sets [slot] and draws its controls itself when there is no scope
/// (a test, or a screen opened outside the shell).
class ModuleToolbarScope extends InheritedWidget {
  const ModuleToolbarScope({
    super.key,
    required this.slot,
    this.action,
    required super.child,
  });

  final ValueNotifier<Widget?> slot;

  /// The header's right-hand slot for the screen's primary action (desktop
  /// review 2026-11-13, D3), filled through [ModuleAction].
  final ValueNotifier<Widget?>? action;

  static ValueNotifier<Widget?>? actionOf(BuildContext context) =>
      context.getInheritedWidgetOfExactType<ModuleToolbarScope>()?.action;

  static ValueNotifier<Widget?>? maybeOf(BuildContext context) =>
      context.getInheritedWidgetOfExactType<ModuleToolbarScope>()?.slot;

  @override
  bool updateShouldNotify(ModuleToolbarScope oldWidget) =>
      oldWidget.slot != slot || oldWidget.action != action;
}

/// A screen's primary action ("Add job", "Add customer", "New report"),
/// drawn in ONE place: the module header, on the right (desktop review
/// 2026-11-13, D3: it sat in six places, from the filter row to a floating
/// button). Inside the shell it draws nothing where it is written and hands
/// [child] to the header; outside it (a test, a screen opened alone) it draws
/// [child] in place.
/// A phone header: the title, and the actions beside it when they fit [room]
/// at 90% of their own width or more, else on a line of their own, right.
class _TitleThenActions extends MultiChildRenderObjectWidget {
  const _TitleThenActions({required this.room, required super.children});

  final double room;

  @override
  RenderObject createRenderObject(BuildContext context) =>
      _RenderTitleThenActions(room);

  @override
  void updateRenderObject(
    BuildContext context,
    _RenderTitleThenActions renderObject,
  ) => renderObject.room = room;
}

class _RenderTitleThenActions extends RenderBox
    with
        ContainerRenderObjectMixin<RenderBox, MultiChildLayoutParentData>,
        RenderBoxContainerDefaultsMixin<RenderBox, MultiChildLayoutParentData> {
  _RenderTitleThenActions(this._room);

  static const _gap = 12.0;
  static const _line = 6.0;

  double _room;
  set room(double value) {
    if (value == _room) return;
    _room = value;
    markNeedsLayout();
  }

  @override
  void setupParentData(RenderBox child) {
    if (child.parentData is! MultiChildLayoutParentData) {
      child.parentData = MultiChildLayoutParentData();
    }
  }

  @override
  void performLayout() {
    final title = firstChild!;
    final actions = lastChild!;
    final width = constraints.maxWidth;
    actions.layout(BoxConstraints(maxWidth: width), parentUsesSize: true);
    final beside = actions.size.width * 0.9 <= _room;
    if (beside) {
      actions.layout(
        BoxConstraints(maxWidth: math.max(0, _room)),
        parentUsesSize: true,
      );
    }
    title.layout(
      BoxConstraints.tightFor(
        width: beside ? math.max(0, width - _gap - actions.size.width) : width,
      ),
      parentUsesSize: true,
    );
    final t = title.parentData! as MultiChildLayoutParentData;
    final a = actions.parentData! as MultiChildLayoutParentData;
    final double height;
    if (beside) {
      height = math.max(title.size.height, actions.size.height);
      t.offset = Offset(0, (height - title.size.height) / 2);
      a.offset = Offset(
        width - actions.size.width,
        (height - actions.size.height) / 2,
      );
    } else {
      height = title.size.height + _line + actions.size.height;
      t.offset = Offset.zero;
      a.offset = Offset(width - actions.size.width, title.size.height + _line);
    }
    size = constraints.constrain(Size(width, height));
  }

  @override
  void paint(PaintingContext context, Offset offset) =>
      defaultPaint(context, offset);

  @override
  bool hitTestChildren(BoxHitTestResult result, {required Offset position}) =>
      defaultHitTestChildren(result, position: position);
}

class ModuleAction extends StatefulWidget {
  const ModuleAction({super.key, required this.child});

  final Widget child;

  @override
  State<ModuleAction> createState() => _ModuleActionState();
}

class _ModuleActionState extends State<ModuleAction> {
  ValueNotifier<Widget?>? _slot;
  Widget? _given;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _slot = ModuleToolbarScope.actionOf(context);
  }

  @override
  void dispose() {
    final slot = _slot;
    final given = _given;
    if (slot != null && given != null) {
      // Not during the frame that is tearing this down.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (identical(slot.value, given)) slot.value = null;
      });
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final slot = _slot;
    final active = ModuleActionGate.of(context);
    if (slot == null) return widget.child;
    if (!active) {
      // A tab kept alive offstage: hand the slot back to the tab in front.
      final given = _given;
      _given = null;
      if (given != null) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (identical(slot.value, given)) slot.value = null;
        });
      }
      return const SizedBox.shrink();
    }
    final child = widget.child;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _given = child;
      slot.value = child;
    });
    return const SizedBox.shrink();
  }
}

/// Marks a subtree (a tab kept alive offstage) whose [ModuleAction]s must
/// not claim the header: only the tab in front fills the slot.
class ModuleActionGate extends InheritedWidget {
  const ModuleActionGate({
    super.key,
    required this.active,
    required super.child,
  });

  final bool active;

  static bool of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<ModuleActionGate>()?.active ??
      true;

  @override
  bool updateShouldNotify(ModuleActionGate oldWidget) =>
      oldWidget.active != active;
}
