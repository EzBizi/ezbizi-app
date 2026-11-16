import 'package:flutter/material.dart';

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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < phoneBelow;

        // Design 161 T1: the subtitle is the (i) beside the title, at every
        // width, so the row is one line and the list starts a line higher.
        final hasSubtitle = subtitle != null && subtitle!.trim().isNotEmpty;

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
                // headlineMedium is the theme's own, and the only heading
                // token it defined before this work. On a phone the same
                // token at 22 rather than a fifth hardcoded size.
                style: phone
                    ? theme.textTheme.headlineMedium?.copyWith(
                        fontSize: 22,
                        letterSpacing: -0.5,
                      )
                    : theme.textTheme.headlineMedium,
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

        return Padding(
          padding: EdgeInsets.fromLTRB(
            phone ? Gutter.phone : Gutter.wide,
            phone ? 14 : 18,
            12,
            0,
          ),
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
              if (actions.isNotEmpty) ...[
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
