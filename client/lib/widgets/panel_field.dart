import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/timestamps.dart';
import 'range_calendar.dart';

/// One field shape for a record's side panel (Jose, 2026-11-16, on the task
/// panel: "all fields are different sizes, all pickers the same it's a
/// mess"). Every box in such a form is the full width and 44 high, the dense
/// picker height used elsewhere: a text box, a picker, a day, a range.
const double panelFieldHeight = 44;

/// The padding inside a panel box: a 24-high line and 8 above and below is
/// 40, under the 44 floor, so every box lands on the floor whatever the font.
const panelFieldPadding = EdgeInsets.symmetric(horizontal: 12, vertical: 8);

/// The floor every panel box stands on, set on the decoration and, through
/// [PanelFieldTheme], on fields that build their own (a picker).
const panelFieldConstraints = BoxConstraints(minHeight: panelFieldHeight);

/// Puts every field under it on the 44 floor: a box drawn by another widget
/// (a picker, a text field) takes it from the theme.
class PanelFieldTheme extends StatelessWidget {
  const PanelFieldTheme({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Theme(
      data: theme.copyWith(
        inputDecorationTheme: theme.inputDecorationTheme.copyWith(
          constraints: panelFieldConstraints,
        ),
      ),
      child: child,
    );
  }
}

/// The decoration every panel box shares.
InputDecoration panelFieldDecoration({
  String? hint,
  Widget? suffixIcon,
  Widget? prefixIcon,
  String? prefixText,
  String? suffixText,
  String? errorText,
  bool enabled = true,
}) => InputDecoration(
  isDense: true,
  enabled: enabled,
  hintText: hint,
  errorText: errorText,
  prefixText: prefixText,
  suffixText: suffixText,
  contentPadding: panelFieldPadding,
  constraints: panelFieldConstraints,
  prefixIcon: prefixIcon,
  prefixIconConstraints: const BoxConstraints(minWidth: 36, minHeight: 24),
  suffixIcon: suffixIcon,
  suffixIconConstraints: const BoxConstraints(minWidth: 40, minHeight: 24),
);

/// What a panel field still holds unsaved, flushed when the panel closes:
/// typing in a box is saved on Enter, on leaving it, after a pause, and when
/// the panel goes, whichever comes first.
class PanelFlush {
  final _pending = <Object, VoidCallback>{};

  void add(Object owner, VoidCallback flush) => _pending[owner] = flush;
  void remove(Object owner) => _pending.remove(owner);

  /// Saves whatever is still typed, everywhere in the panel.
  void flushAll() {
    for (final f in List.of(_pending.values)) {
      f();
    }
  }
}

/// A panel box that is not typed into: tapped, it opens what picks its
/// value. Read-only when [onTap] is null.
class PanelFieldBox extends StatelessWidget {
  const PanelFieldBox({
    super.key,
    required this.child,
    this.onTap,
    this.icon = Icons.arrow_drop_down,
    this.trailing,
    this.readOnly = false,
  });

  final Widget child;
  final VoidCallback? onTap;

  /// Drawn at the right; null draws none (a read-only value).
  final IconData? icon;

  /// Something shown before the icon, e.g. a "Late" pill.
  final Widget? trailing;

  /// A value nobody edits here (who made the task): no greyed look.
  final bool readOnly;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final enabled = onTap != null || readOnly;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: InputDecorator(
        decoration: panelFieldDecoration(
          enabled: enabled,
          suffixIcon: icon == null && trailing == null
              ? null
              : Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    ?trailing,
                    if (icon != null)
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        child: Icon(
                          icon,
                          size: 20,
                          color: onTap == null
                              ? Theme.of(context).disabledColor
                              : scheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
        ),
        child: DefaultTextStyle.merge(
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          child: child,
        ),
      ),
    );
  }
}

/// The muted words a box shows while it holds nothing.
Widget panelPlaceholder(BuildContext context, String text) => Text(
  text,
  maxLines: 1,
  overflow: TextOverflow.ellipsis,
  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
    color: Theme.of(context).colorScheme.onSurfaceVariant,
  ),
);

/// A popover under a box, the box itself drawn by [box] with a toggle.
class PanelPopover extends StatefulWidget {
  const PanelPopover({
    super.key,
    required this.box,
    required this.popover,
    this.enabled = true,
  });

  final Widget Function(BuildContext context, VoidCallback toggle) box;

  /// The contents; [close] shuts it.
  final Widget Function(BuildContext context, VoidCallback close) popover;
  final bool enabled;

  @override
  State<PanelPopover> createState() => _PanelPopoverState();
}

class _PanelPopoverState extends State<PanelPopover> {
  // An overlay, not a MenuAnchor: a menu sizes its children by their
  // intrinsic width, which a calendar's grid cannot answer.
  final _link = LayerLink();
  final _portal = OverlayPortalController();

  void _toggle() => setState(_portal.toggle);

  void _close() {
    if (_portal.isShowing && mounted) setState(_portal.hide);
  }

  @override
  Widget build(BuildContext context) => CompositedTransformTarget(
    link: _link,
    child: OverlayPortal(
      controller: _portal,
      overlayChildBuilder: (context) => CompositedTransformFollower(
        link: _link,
        targetAnchor: Alignment.bottomLeft,
        offset: const Offset(0, 4),
        child: Align(
          alignment: Alignment.topLeft,
          child: TapRegion(
            groupId: this,
            onTapOutside: (_) => _close(),
            child: CallbackShortcuts(
              bindings: {
                const SingleActivator(LogicalKeyboardKey.escape): _close,
              },
              child: Focus(
                autofocus: true,
                child: Material(
                  elevation: 4,
                  borderRadius: BorderRadius.circular(10),
                  color: Theme.of(context).colorScheme.surface,
                  child: Padding(
                    padding: const EdgeInsets.all(10),
                    child: widget.popover(context, _close),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
      child: TapRegion(
        groupId: this,
        child: widget.box(context, widget.enabled ? _toggle : () {}),
      ),
    ),
  );
}

String _isoDay(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

const _monthsShort = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

/// "Nov 10 – Nov 16", with the year only when it is not this one.
String rangeLabel(String? from, String? to, {DateTime? now}) {
  final a = from == null ? null : DateTime.tryParse(from);
  if (a == null) return '';
  final b = (to == null ? null : DateTime.tryParse(to)) ?? a;
  final year = (now ?? DateTime.now()).year;
  String short(DateTime d) => '${_monthsShort[d.month - 1]} ${d.day}';
  final tail = a.year == year && b.year == year ? '' : ', ${b.year}';
  if (DateUtils.isSameDay(a, b)) return '${short(a)}$tail';
  return '${short(a)} – ${short(b)}$tail';
}

/// A day: the box shows it, a calendar opens under it when tapped.
class DayPopoverField extends StatelessWidget {
  const DayPopoverField({
    super.key,
    required this.value,
    required this.formats,
    this.onChanged,
    this.trailing,
    this.hint = 'No date',
  });

  /// yyyy-mm-dd, as stored.
  final String? value;
  final DisplayFormats formats;

  /// The day picked, or null when cleared. Null makes it read-only.
  final ValueChanged<String?>? onChanged;
  final Widget? trailing;
  final String hint;

  @override
  Widget build(BuildContext context) {
    final day = value == null ? null : DateTime.tryParse(value!);
    return PanelPopover(
      enabled: onChanged != null,
      box: (context, toggle) => PanelFieldBox(
        onTap: onChanged == null ? null : toggle,
        icon: Icons.calendar_today_outlined,
        trailing: trailing,
        child: day == null
            ? panelPlaceholder(context, hint)
            : Text(formats.formatDate(day)),
      ),
      popover: (context, close) => SizedBox(
        width: 300,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            CalendarDatePicker(
              initialDate: day ?? DateTime.now(),
              firstDate: DateTime(1900),
              lastDate: DateTime(2100, 12, 31),
              onDateChanged: (d) {
                close();
                final iso = _isoDay(d);
                if (iso != value) onChanged?.call(iso);
              },
            ),
            if (day != null)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  key: const Key('day-popover-clear'),
                  onPressed: () {
                    close();
                    onChanged?.call(null);
                  },
                  child: const Text('Clear'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// A timeline: "Nov 10 – Nov 16" in the box, the range calendar in a
/// popover only once tapped (Jose, 2026-11-16: never always open).
class RangePopoverField extends StatelessWidget {
  const RangePopoverField({
    super.key,
    required this.from,
    required this.to,
    this.onChanged,
  });

  final String? from;
  final String? to;
  final void Function(String? from, String? to)? onChanged;

  @override
  Widget build(BuildContext context) {
    final label = rangeLabel(from, to);
    return PanelPopover(
      enabled: onChanged != null,
      box: (context, toggle) => PanelFieldBox(
        key: const Key('range-field'),
        onTap: onChanged == null ? null : toggle,
        icon: Icons.date_range_outlined,
        child: label.isEmpty
            ? panelPlaceholder(context, 'No dates')
            : Text(label),
      ),
      popover: (context, close) => Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          SizedBox(
            width: 300,
            child: RangeCalendar(from: from, to: to, onChanged: onChanged),
          ),
          TextButton(
            key: const Key('range-done'),
            onPressed: close,
            child: const Text('Done'),
          ),
        ],
      ),
    );
  }
}

/// Several choices in one box (people, tags): their names, and a list with
/// a tick beside each once tapped.
class MultiPickField extends StatelessWidget {
  const MultiPickField({
    super.key,
    required this.options,
    required this.selected,
    this.onChanged,
    this.itemKey,
  });

  /// (id, label), in the order offered.
  final List<(String, String)> options;
  final List<String> selected;
  final ValueChanged<List<String>>? onChanged;

  /// A key for each choice's row, by id.
  final Key Function(String id)? itemKey;

  @override
  Widget build(BuildContext context) {
    final names = [
      for (final (id, label) in options)
        if (selected.contains(id)) label,
    ];
    return MenuAnchor(
      menuChildren: [
        for (final (id, label) in options)
          CheckboxMenuButton(
            key: itemKey?.call(id),
            value: selected.contains(id),
            closeOnActivate: false,
            onChanged: onChanged == null
                ? null
                : (on) => onChanged!([
                    for (final x in selected)
                      if (x != id) x,
                    if (on == true) id,
                  ]),
            child: Text(label),
          ),
      ],
      builder: (context, menu, _) => PanelFieldBox(
        onTap: onChanged == null
            ? null
            : () => menu.isOpen ? menu.close() : menu.open(),
        child: names.isEmpty
            ? panelPlaceholder(context, 'Not set')
            : Text(names.join(', ')),
      ),
    );
  }
}

/// How long typing in a panel box rests before it is saved.
const panelTypingPause = Duration(milliseconds: 900);
