/// The phone's bottom bar, drawn once for the real phone (HomeShell) and for
/// the Roles editor's live preview, so the two cannot drift (Jose
/// 2026-11-24: "this is hard to view, you can't tell stuff apart").
///
/// Every item gets the same width and a label of at least 11 px. A label of
/// several words may wrap to a second line; a single word stays on one line
/// and ends in an ellipsis rather than breaking ("Communicati / on",
/// 2026-11-16).
library;

import 'package:flutter/material.dart';

class PhoneBarItem {
  const PhoneBarItem({
    required this.icon,
    required this.label,
    this.tooltip,
    this.key,
    this.badge = false,
    this.isDefault = false,
  });

  final IconData icon;
  final String label;
  final String? tooltip;
  final Key? key;

  /// A tab slot with no icon of its own: the module's icon plus a dot.
  final bool badge;

  /// Filled by the default, not chosen. Shown in italics with "(default)".
  final bool isDefault;
}

/// A tab's own icon where one is known, so a tab slot never looks like its
/// module. Null when unknown: the caller badges the module icon instead.
IconData? tabIcon(String tab) {
  final t = tab.toLowerCase();
  for (final (word, icon) in const [
    ('qr', Icons.qr_code),
    ('estimate', Icons.request_quote_outlined),
    ('invoice', Icons.receipt_long_outlined),
    ('payment', Icons.payments_outlined),
    ('review', Icons.star_outline),
    ('tag', Icons.sell_outlined),
    ('map', Icons.map_outlined),
    ('calendar', Icons.calendar_month_outlined),
    ('photo', Icons.photo_library_outlined),
    ('note', Icons.notes_outlined),
    ('report', Icons.bar_chart_outlined),
    ('setting', Icons.settings_outlined),
  ]) {
    if (t.contains(word)) return icon;
  }
  return null;
}

/// The icon and badge for a bar slot: a module, or one of its tabs.
(IconData, bool) slotIcon(IconData moduleIcon, String? tab) {
  if (tab == null) return (moduleIcon, false);
  final own = tabIcon(tab);
  return own == null ? (moduleIcon, true) : (own, false);
}

class PhoneBottomBar extends StatelessWidget {
  const PhoneBottomBar({
    super.key,
    required this.items,
    this.selected = -1,
    this.onTap,
  });

  final List<PhoneBarItem> items;
  final int selected;
  final ValueChanged<int>? onTap;

  static const height = 72.0;

  /// A phone on its side: the label beside the icon, as iOS draws a compact
  /// tab bar, so the bar gives the ~250 px page 24 px back.
  static const sidewaysHeight = 48.0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final base = theme.textTheme.labelSmall ?? const TextStyle();
    final size = (base.fontSize ?? 11) < 11 ? 11.0 : base.fontSize!;
    final sideways = MediaQuery.sizeOf(context).height < 500;

    Widget item(int i, PhoneBarItem it) {
      final on = i == selected;
      final ink = on ? scheme.onSecondaryContainer : scheme.onSurface;
      final label = it.isDefault ? '${it.label} (default)' : it.label;
      Widget icon = Icon(it.icon, color: ink, size: 22);
      if (it.badge) {
        icon = Badge(
          smallSize: 7,
          backgroundColor: scheme.primary,
          alignment: AlignmentDirectional.topEnd,
          child: icon,
        );
      }
      return Expanded(
        child: Tooltip(
          message: it.tooltip ?? label,
          child: InkWell(
            key: it.key,
            onTap: onTap == null ? null : () => onTap!(i),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(2, 6, 2, 4),
              child: Flex(
                direction: sideways ? Axis.horizontal : Axis.vertical,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(
                    // Sideways the pill narrows so a 118 px slot keeps
                    // "Scheduling" whole (it read "Schedu…" on the Duo).
                    width: sideways ? 36 : 52,
                    height: 28,
                    decoration: BoxDecoration(
                      color: on ? scheme.secondaryContainer : null,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: icon,
                  ),
                  if (sideways)
                    const SizedBox(width: 4)
                  else
                    const SizedBox(height: 3),
                  Flexible(
                    fit: sideways ? FlexFit.loose : FlexFit.tight,
                    child: Text(
                      label,
                      textAlign: TextAlign.center,
                      // Words may wrap to a second line; one word never
                      // breaks in the middle.
                      maxLines: !sideways && label.contains(' ') ? 2 : 1,
                      softWrap: !sideways && label.contains(' '),
                      overflow: TextOverflow.ellipsis,
                      style: base.copyWith(
                        fontSize: size,
                        height: 1.15,
                        color: ink,
                        fontWeight: on ? FontWeight.w700 : FontWeight.w500,
                        fontStyle: it.isDefault ? FontStyle.italic : null,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    return Material(
      color: scheme.surfaceContainer,
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: sideways ? sidewaysHeight : height,
          // Text scale is capped so two lines always fit the bar's height.
          child: MediaQuery.withClampedTextScaling(
            maxScaleFactor: 1.15,
            child: Row(
              children: [for (final (i, it) in items.indexed) item(i, it)],
            ),
          ),
        ),
      ),
    );
  }
}
