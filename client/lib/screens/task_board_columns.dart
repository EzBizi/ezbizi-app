import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/timestamps.dart';
import '../widgets/calendar_day_field.dart';
import '../widgets/colour_field.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

/// A board's own columns (UI queue 84; Jose: "i should be able to make custom
/// columns ... with options wether they are text based, numerical, status,
/// etc."). Seven types: text, number, status, date, person, dropdown and
/// checkbox. A task's values are `{column_id: value}`; a status or dropdown
/// value is the option's id.
/// [retired]: kept on the tasks that have it, out of every picker (monday's
/// deactivated label, Jose 2026-11-09).
typedef ColumnOption = ({String id, String label, Color? colour, bool retired});

typedef BoardColumn = ({
  String id,
  String name,
  String type,
  String? unit,
  List<ColumnOption> options,

  /// Number and formula (design 178): plain, currency or percent.
  String format,

  /// Number and formula: what a group's footer shows, sum, average or none.
  String footer,

  /// A formula's expression with each [Column] as {column id}, formulas
  /// it names written out in place; null on any other type.
  String? formula,
});

/// [expr] with each [Name] as {id}: a number column by its id, a formula by
/// its own expression in brackets, at most five deep; anything else 0.
String _compile(String expr, Map<String, Map> byName, [int depth = 0]) =>
    expr.replaceAllMapped(RegExp(r'\[([^\[\]]+)\]'), (m) {
      final c = byName[m[1]!.trim().toLowerCase()];
      if (c == null || depth > 4) return '0';
      if (c['type'] == 'formula') {
        final inner = '${(c['settings'] as Map?)?['expression'] ?? '0'}';
        return '(${_compile(inner, byName, depth + 1)})';
      }
      return c['type'] == 'number' ? '{${c['id']}}' : '0';
    });

/// The columns a board shows, in its order. Hidden ones are left out.
List<BoardColumn> boardColumns(Map<String, dynamic>? board) {
  final rows =
      (board?['columns'] as List? ?? const [])
          .whereType<Map>()
          .where((c) => c['hidden'] != true)
          .toList()
        ..sort(
          (a, b) => ((a['position'] as num?) ?? 0).compareTo(
            (b['position'] as num?) ?? 0,
          ),
        );
  final byName = {
    for (final c in (board?['columns'] as List? ?? const []).whereType<Map>())
      '${c['name'] ?? ''}'.trim().toLowerCase(): c,
  };
  return [
    for (final c in rows)
      (
        id: '${c['id']}',
        name: '${c['name'] ?? ''}',
        type: '${c['type'] ?? 'text'}',
        unit: (c['settings'] as Map?)?['unit'] as String?,
        format: c['type'] == 'currency'
            ? 'currency'
            : '${(c['settings'] as Map?)?['format'] ?? 'plain'}',
        footer: '${(c['settings'] as Map?)?['footer'] ?? 'none'}',
        formula: c['type'] == 'formula'
            ? _compile(
                '${(c['settings'] as Map?)?['expression'] ?? ''}',
                byName,
              )
            : null,
        options: [
          for (final o
              in ((c['settings'] as Map?)?['options'] as List? ?? const [])
                  .whereType<Map>())
            (
              id: '${o['id']}',
              label: '${o['label'] ?? ''}',
              colour: o['colour'] is String
                  ? ColourField.parse(o['colour'] as String)
                  : null,
              retired: o['retired'] == true,
            ),
        ],
      ),
  ];
}

/// A task's value for one column; a formula's is worked out here.
Object? columnValue(Map<String, dynamic> task, BoardColumn c) =>
    c.formula != null
    ? evalFormula(c.formula!, (task['values'] as Map?) ?? const {})
    : (task['values'] as Map?)?[c.id];

/// Works out a compiled formula over a task's values: + - * / and brackets,
/// an empty value counts 0. Null when it does not parse or divides by 0.
num? evalFormula(String compiled, Map values) {
  final src = compiled.replaceAllMapped(RegExp(r'\{([^}]+)\}'), (m) {
    final v = values[m[1]];
    final n = v is num ? v : num.tryParse('${v ?? ''}');
    return '${n ?? 0}';
  });
  final tokens = RegExp(
    r'\d+(?:\.\d+)?|[-+*/()]',
  ).allMatches(src).map((m) => m[0]!).toList();
  if (tokens.isEmpty || tokens.join() != src.replaceAll(RegExp(r'\s+'), '')) {
    return null;
  }
  var i = 0;
  late num? Function() expr;
  num? factor() {
    if (i >= tokens.length) return null;
    final t = tokens[i++];
    if (t == '-') {
      final f = factor();
      return f == null ? null : -f;
    }
    if (t == '(') {
      final e = expr();
      if (i >= tokens.length || tokens[i++] != ')') return null;
      return e;
    }
    return num.tryParse(t);
  }

  num? term() {
    var left = factor();
    while (left != null &&
        i < tokens.length &&
        (tokens[i] == '*' || tokens[i] == '/')) {
      final op = tokens[i++];
      final right = factor();
      if (right == null || (op == '/' && right == 0)) return null;
      left = op == '*' ? left * right : left / right;
    }
    return left;
  }

  expr = () {
    var left = term();
    while (left != null &&
        i < tokens.length &&
        (tokens[i] == '+' || tokens[i] == '-')) {
      final op = tokens[i++];
      final right = term();
      if (right == null) return null;
      left = op == '+' ? left + right : left - right;
    }
    return left;
  };

  final out = expr();
  return i == tokens.length ? out : null;
}

/// A number as its column shows it: currency with two decimals, a
/// percentage, or plain with the column's unit.
String formatColumnNumber(Object? v, BoardColumn c) {
  final n = v is num ? v : num.tryParse('${v ?? ''}');
  if (n == null) return v == null ? '' : '$v';
  return switch (c.format) {
    'currency' => () {
      final fixed = n.abs().toStringAsFixed(2).split('.');
      return '${n < 0 ? '-' : ''}\$${formatCellNumber(int.parse(fixed[0]), null)}.${fixed[1]}';
    }(),
    'percent' =>
      '${formatCellNumber(n == n.roundToDouble() ? n.toInt() : double.parse(n.toStringAsFixed(1)), null)}%',
    _ => formatCellNumber(n, c.unit),
  };
}

/// "Nov 9 – Nov 17" for a Timeline value; null when it is not one.
String? timelineLabel(Object? v, DisplayFormats formats) {
  if (v is! Map) return null;
  final from = DateTime.tryParse('${v['from']}');
  final to = DateTime.tryParse('${v['to']}');
  if (from == null || to == null) return null;
  return from == to
      ? formats.formatDate(from)
      : '${formats.formatDate(from)} – ${formats.formatDate(to)}';
}

ColumnOption? _option(BoardColumn c, Object? value) {
  for (final o in c.options) {
    if (o.id == '$value') return o;
  }
  return null;
}

String _number(Object? v, String? unit) {
  final n = v is num ? v : num.tryParse('$v');
  if (n == null) return '';
  final text = n == n.roundToDouble() ? '${n.toInt()}' : '$n';
  return unit == null || unit.isEmpty ? text : '$text $unit';
}

/// Units written before the amount ("$12,450"); every other unit follows it
/// ("12 ft").
const _prefixUnits = {r'$', '€', '£', '¥', '₹', r'US$', r'C$', r'A$'};

/// A number column's value as the table shows it (UI queue 152, 1):
/// thousands separators, its decimals as stored, and the column's unit.
/// Anything that is not a number comes back as it arrived.
String formatCellNumber(Object? v, String? unit) {
  final n = v is num ? v : num.tryParse('${v ?? ''}'.trim());
  if (n == null) return v == null ? '' : '$v';
  final negative = n < 0;
  final raw = (n == n.roundToDouble() ? '${n.abs().toInt()}' : '${n.abs()}');
  final dot = raw.indexOf('.');
  final whole = dot < 0 ? raw : raw.substring(0, dot);
  final fraction = dot < 0 ? '' : raw.substring(dot);
  final grouped = StringBuffer();
  for (var i = 0; i < whole.length; i++) {
    if (i > 0 && (whole.length - i) % 3 == 0) grouped.write(',');
    grouped.write(whole[i]);
  }
  final amount = '$grouped$fraction';
  final u = unit?.trim() ?? '';
  final sign = negative ? '-' : '';
  if (u.isEmpty) return '$sign$amount';
  return _prefixUnits.contains(u) ? '$sign$u$amount' : '$sign$amount $u';
}

/// A phone number in a cell, for eyes only: the stored E.164 value stays
/// what is edited and sent (UI queue 152, 1). +1 numbers read
/// "(404) 555-0142"; another country's read "+" and grouped digits
/// ("+44 20 7946 0958"). Anything else comes back as it arrived.
String formatCellPhone(String raw) {
  final v = raw.trim();
  if (!RegExp(r'^\+[1-9]\d{7,14}$').hasMatch(v)) return raw;
  final digits = v.substring(1);
  if (digits.startsWith('1')) {
    if (digits.length != 11) return raw;
    final d = digits.substring(1);
    return '(${d.substring(0, 3)}) ${d.substring(3, 6)}-${d.substring(6)}';
  }
  // The country code is taken as two digits; the rest in fours from the
  // right, so "+442079460958" reads "+44 20 7946 0958".
  final country = digits.substring(0, 2);
  final rest = digits.substring(2);
  final groups = <String>[];
  for (var end = rest.length; end > 0; end -= 4) {
    groups.insert(0, rest.substring(end - 4 < 0 ? 0 : end - 4, end));
  }
  return '+$country ${groups.join(' ')}';
}

/// One cell of the table, in its column's own shape. A checkbox, a status
/// and a dropdown change in place; the rest are changed in the task's panel.

/// The inline "+ Add tag" row of a label or tags menu: typing a new one
/// adds it to the column and picks it (Jose, 2026-11-09).
class _AddOption extends StatefulWidget {
  const _AddOption({required this.columnId, required this.onAdd});

  final String columnId;
  final Future<void> Function(String label) onAdd;

  @override
  State<_AddOption> createState() => _AddOptionState();
}

class _AddOptionState extends State<_AddOption> {
  final _text = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final label = _text.text.trim();
    if (label.isEmpty || _busy) return;
    setState(() => _busy = true);
    await widget.onAdd(label);
    if (!mounted) return;
    _text.clear();
    setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(12, 4, 12, 4),
    child: SizedBox(
      width: 200,
      child: TextField(
        key: ValueKey('column-option-new-${widget.columnId}'),
        controller: _text,
        enabled: !_busy,
        decoration: const InputDecoration(
          isDense: true,
          hintText: '+ Add tag',
          border: OutlineInputBorder(),
        ),
        onSubmitted: (_) => _submit(),
      ),
    ),
  );
}

/// Several options as chips, for a tags column.
class _TagChips extends StatelessWidget {
  const _TagChips({required this.column, required this.ids});

  final BoardColumn column;
  final List<String> ids;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final picked = [for (final id in ids) ?_option(column, id)];
    if (picked.isEmpty) {
      return Text(
        '—',
        style: TextStyle(color: theme.colorScheme.onSurfaceVariant),
      );
    }
    return ClipRect(
      child: Wrap(
        spacing: 4,
        runSpacing: 4,
        clipBehavior: Clip.hardEdge,
        children: [
          for (final o in picked)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
              decoration: BoxDecoration(
                color: (o.colour ?? theme.colorScheme.outline).withValues(
                  alpha: 0.22,
                ),
                borderRadius: BorderRadius.circular(6),
              ),
              child: Text(
                o.label,
                maxLines: 1,
                style: theme.textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// "Nov 9, 2:30 PM" for a date-and-time value.
String? dateTimeLabel(Object? v, DisplayFormats formats) {
  final at = v is String ? DateTime.tryParse(v.replaceFirst(' ', 'T')) : null;
  if (at == null) return null;
  final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
  final m = at.minute.toString().padLeft(2, '0');
  return '${formats.formatDate(at)}, $h:$m ${at.hour < 12 ? 'AM' : 'PM'}';
}

/// tel:, mailto: or the page itself, for a phone, email or url value.
Uri? contactUri(String type, String value) => switch (type) {
  'phone' => Uri(scheme: 'tel', path: value.replaceAll(RegExp(r'[^\d+]'), '')),
  'email' => Uri(scheme: 'mailto', path: value),
  'url' => Uri.tryParse(value),
  _ => null,
};

/// What a phone, email or url field must look like before it is sent.
bool validColumnText(String type, String v) => switch (type) {
  'phone' =>
    RegExp(r'^[\d\s()+.\-]+$').hasMatch(v) &&
        v.replaceAll(RegExp(r'\D'), '').length >= 7 &&
        v.replaceAll(RegExp(r'\D'), '').length <= 15,
  'email' => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(v),
  'url' => RegExp(r'^https?://\S+\.\S+', caseSensitive: false).hasMatch(v),
  'datetime' => RegExp(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$').hasMatch(v),
  _ => true,
};

class ColumnCell extends StatelessWidget {
  const ColumnCell({
    super.key,
    required this.column,
    required this.value,
    required this.people,
    required this.formats,
    this.onChanged,
    this.onAddOption,
    this.onEditOptions,
    this.onMenu,
  });

  /// Told when a status column's menu opens and closes, to dim the board.
  final ValueChanged<bool>? onMenu;

  /// Adds a label or tag to the column and returns its id (null: refused).
  final Future<String?> Function(String label)? onAddOption;

  /// Opens the column's panel to rename, recolour or delete its options.
  final VoidCallback? onEditOptions;

  final BoardColumn column;
  final Object? value;
  final Map<String, String> people;
  final DisplayFormats formats;

  /// Null where the board is view-only.
  final ValueChanged<Object?>? onChanged;

  /// Types with their own control in the cell (a tick, a label menu), and
  /// those a cell cannot edit (a link searches; a formula is worked out).
  static const _ownControl = {
    'checkbox',
    'status',
    'dropdown',
    'tags',
    'link',
    'formula',
  };

  /// A click edits the value where it is (Jose, 2026-11-12: the sidebar is
  /// for Updates and the task, not for typing one field).
  @override
  Widget build(BuildContext context) {
    final shown = _display(context);
    if (onChanged == null || _ownControl.contains(column.type)) return shown;
    return InlineCellEditor(
      column: column,
      value: value,
      people: people,
      formats: formats,
      onChanged: onChanged!,
      child: shown,
    );
  }

  Widget _display(BuildContext context) {
    final theme = Theme.of(context);
    final muted = TextStyle(color: theme.colorScheme.onSurfaceVariant);
    final v = value;

    switch (column.type) {
      case 'checkbox':
        return Checkbox(
          value: v == true,
          visualDensity: VisualDensity.compact,
          onChanged: onChanged == null ? null : (b) => onChanged!(b ?? false),
        );
      case 'status':
      case 'dropdown':
        final picked = _option(column, v);
        final fill = picked?.colour;
        final label = Text(
          picked?.label ?? '—',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontWeight: FontWeight.w700,
            fontSize: 12,
            color: fill == null
                ? theme.colorScheme.onSurfaceVariant
                : (ThemeData.estimateBrightnessForColor(fill) == Brightness.dark
                      ? theme.colorScheme.surface
                      : theme.colorScheme.onSurface),
          ),
        );
        return MenuAnchor(
          menuChildren: [
            for (final o in column.options)
              if (!o.retired || o.id == v)
                MenuItemButton(
                  key: ValueKey('column-option-${column.id}-${o.id}'),
                  leadingIcon: _dot(o.colour, theme),
                  onPressed: () => onChanged?.call(o.id),
                  child: Text(o.label),
                ),
            if (v != null)
              MenuItemButton(
                onPressed: () => onChanged?.call(null),
                child: const Text('Clear'),
              ),
            ..._optionTools(theme, (id) => onChanged?.call(id)),
          ],
          onOpen: column.type == 'status' ? () => onMenu?.call(true) : null,
          onClose: column.type == 'status' ? () => onMenu?.call(false) : null,
          builder: (context, menu, _) => Material(
            color: column.type == 'status'
                ? (fill ?? theme.colorScheme.surfaceContainerHighest)
                : Colors.transparent,
            child: InkWell(
              key: ValueKey('column-cell-${column.id}'),
              onTap: onChanged == null
                  ? null
                  : () => menu.isOpen ? menu.close() : menu.open(),
              child: Center(
                child: column.type == 'status'
                    ? label
                    : Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 2,
                        ),
                        decoration: BoxDecoration(
                          color: (fill ?? theme.colorScheme.outline).withValues(
                            alpha: 0.18,
                          ),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          picked?.label ?? '—',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
              ),
            ),
          ),
        );
      case 'tags':
        final ids = [for (final id in (v as List?) ?? const []) '$id'];
        return MenuAnchor(
          menuChildren: [
            for (final o in column.options)
              if (!o.retired || ids.contains(o.id))
                CheckboxMenuButton(
                  key: ValueKey('column-option-${column.id}-${o.id}'),
                  value: ids.contains(o.id),
                  closeOnActivate: false,
                  onChanged: (on) {
                    final next = [
                      for (final id in ids)
                        if (id != o.id) id,
                      if (on == true) o.id,
                    ];
                    onChanged?.call(next.isEmpty ? null : next);
                  },
                  child: Text(o.label),
                ),
            ..._optionTools(theme, (id) => onChanged?.call([...ids, ?id])),
          ],
          builder: (context, menu, _) => InkWell(
            key: ValueKey('column-cell-${column.id}'),
            onTap: onChanged == null
                ? null
                : () => menu.isOpen ? menu.close() : menu.open(),
            child: Align(
              alignment: Alignment.centerLeft,
              child: _TagChips(column: column, ids: ids),
            ),
          ),
        );
      case 'phone':
      case 'email':
      case 'url':
        if (v is! String || v.isEmpty) return Text('—', style: muted);
        final uri = contactUri(column.type, v);
        return InkWell(
          onTap: uri == null ? null : () => launchUrl(uri),
          child: Text(
            column.type == 'phone' ? formatCellPhone(v) : v,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: theme.colorScheme.primary,
              decoration: TextDecoration.underline,
            ),
          ),
        );
      case 'datetime':
        final label = dateTimeLabel(v, formats);
        return Text(
          label ?? '—',
          maxLines: 1,
          style: label == null ? muted : null,
        );
      case 'link':
        final label = (v as Map?)?['label'] as String?;
        return Text(
          label ?? '—',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: label == null ? muted : null,
        );
      case 'long_text':
        final text = v is String ? v.split('\n').first : null;
        return Text(
          text == null || text.isEmpty ? '—' : text,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: text == null || text.isEmpty ? muted : null,
        );
      case 'timeline':
        final label = timelineLabel(v, formats);
        return Text(
          label ?? '—',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: label == null ? muted : null,
        );
      case 'number':
      case 'currency':
      case 'formula':
        return Align(
          alignment: Alignment.centerRight,
          child: Text(
            v == null ? '—' : formatColumnNumber(v, column),
            textAlign: TextAlign.right,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: (v == null ? muted : const TextStyle()).copyWith(
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        );
      case 'date':
        final day = v is String ? DateTime.tryParse(v) : null;
        return Text(
          day == null ? '—' : formats.formatDate(day),
          style: day == null ? muted : null,
        );
      case 'person':
        final name = people['$v'];
        return Text(
          name == null ? '—' : name.split(' ').first,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: name == null ? muted : null,
        );
      default:
        return Text(
          v == null || '$v'.isEmpty
              ? '—'
              : v is String
              ? formatCellPhone(v)
              : '$v',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: v == null || '$v'.isEmpty ? muted : null,
        );
    }
  }

  Widget _dot(Color? colour, ThemeData theme) => Container(
    width: 12,
    height: 12,
    decoration: BoxDecoration(
      color: colour ?? theme.colorScheme.outline,
      borderRadius: BorderRadius.circular(3),
    ),
  );

  /// "+ Add tag" and "Edit labels…", under a label or tags menu.
  List<Widget> _optionTools(ThemeData theme, ValueChanged<String?> picked) => [
    if (onAddOption != null) ...[
      const Divider(height: 8),
      _AddOption(
        columnId: column.id,
        onAdd: (label) async {
          final id = await onAddOption!(label);
          if (id != null) picked(id);
        },
      ),
    ],
    if (onEditOptions != null)
      MenuItemButton(
        key: ValueKey('column-options-edit-${column.id}'),
        leadingIcon: const Icon(Icons.edit_outlined, size: 18),
        onPressed: onEditOptions,
        child: Text(column.type == 'tags' ? 'Edit tags…' : 'Edit labels…'),
      ),
  ];
}

/// The same value, as a field in the task's panel, for every type.
class ColumnEditor extends StatefulWidget {
  const ColumnEditor({
    super.key,
    required this.column,
    required this.value,
    required this.people,
    required this.formats,
    this.onChanged,
    this.api,
    this.onDone,
    this.autofocus = false,
  });

  /// For a customer / job column's search; without it the field is read-only.
  final ApiClient? api;

  /// A typed value was accepted (Enter or leaving the box), changed or not.
  final VoidCallback? onDone;
  final bool autofocus;

  final BoardColumn column;
  final Object? value;
  final Map<String, String> people;
  final DisplayFormats formats;
  final ValueChanged<Object?>? onChanged;

  @override
  State<ColumnEditor> createState() => _ColumnEditorState();
}

class _ColumnEditorState extends State<ColumnEditor> {
  late final TextEditingController _text = TextEditingController(
    text: switch (widget.column.type) {
      'number' ||
      'currency' => widget.value == null ? '' : _number(widget.value, null),
      _ => widget.value == null ? '' : '${widget.value}',
    },
  );

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  String? _invalid;

  void _commitText() {
    final typed = _text.text.trim();
    final bad = typed.isNotEmpty && !validColumnText(widget.column.type, typed);
    if (bad != (_invalid != null)) {
      setState(
        () => _invalid = bad
            ? switch (widget.column.type) {
                'phone' => 'A phone number, 7 to 15 digits.',
                'email' => 'An email address.',
                'url' => 'Starts with http:// or https://',
                _ => 'YYYY-MM-DD HH:MM',
              }
            : null,
      );
    }
    if (bad) return;
    if (widget.column.type == 'number' || widget.column.type == 'currency') {
      final n = typed.isEmpty ? null : num.tryParse(typed);
      if (typed.isNotEmpty && n == null) return;
      if (n != widget.value) widget.onChanged?.call(n);
      widget.onDone?.call();
      return;
    }
    final next = typed.isEmpty ? null : typed;
    if (next != widget.value) widget.onChanged?.call(next);
    widget.onDone?.call();
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.column;
    final editable = widget.onChanged != null;
    final key = ValueKey('column-editor-${c.id}');

    switch (c.type) {
      case 'checkbox':
        return Align(
          alignment: Alignment.centerLeft,
          child: Switch(
            key: key,
            value: widget.value == true,
            onChanged: editable ? (b) => widget.onChanged!(b) : null,
          ),
        );
      case 'status':
      case 'dropdown':
      case 'person':
        final options = c.type == 'person'
            ? [
                for (final p in widget.people.entries)
                  SearchOption<String>(value: p.key, label: p.value),
              ]
            : [
                for (final o in c.options)
                  if (!o.retired || o.id == widget.value)
                    SearchOption<String>(value: o.id, label: o.label),
              ];
        return SearchPickerField<String>(
          key: key,
          value: widget.value?.toString(),
          isDense: true,
          hint: 'Not set',
          allowClear: true,
          options: options,
          onChanged: editable ? (v) => widget.onChanged!(v) : null,
        );
      case 'tags':
        final ids = [
          for (final id in (widget.value as List?) ?? const []) '$id',
        ];
        return Wrap(
          key: key,
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final o in c.options)
              if (!o.retired || ids.contains(o.id))
                FilterChip(
                  key: ValueKey('column-editor-${c.id}-${o.id}'),
                  label: Text(o.label),
                  selected: ids.contains(o.id),
                  onSelected: editable
                      ? (on) {
                          final next = [
                            for (final id in ids)
                              if (id != o.id) id,
                            if (on) o.id,
                          ];
                          widget.onChanged!(next.isEmpty ? null : next);
                        }
                      : null,
                ),
          ],
        );
      case 'link':
        final api = widget.api;
        final label = (widget.value as Map?)?['label'] as String?;
        if (api == null || !editable) return Text(label ?? '—', key: key);
        return Autocomplete<(String, String)>(
          key: key,
          initialValue: TextEditingValue(text: label ?? ''),
          displayStringForOption: (o) => o.$2,
          optionsBuilder: (typed) async {
            final term = typed.text.trim();
            if (term.length < 2) return const [];
            final (customers, jobs) = await (
              api.get('/customers', {'search': term, 'per_page': '8'}),
              api.get('/jobs', {'search': term, 'per_page': '8'}),
            ).wait;
            List rows(ApiResponse r) =>
                r.ok && r.data is List ? r.data as List : const [];
            return [
              for (final c in rows(customers))
                ('customer:${c['id']}', 'Customer · ${c['name'] ?? ''}'),
              for (final j in rows(jobs))
                (
                  'job:${j['id']}',
                  'Job ${j['number'] ?? ''} · ${(j['customer'] as Map?)?['name'] ?? ''}',
                ),
            ];
          },
          onSelected: (o) => widget.onChanged!(o.$1),
          fieldViewBuilder: (context, controller, focus, submit) => TextField(
            controller: controller,
            focusNode: focus,
            decoration: InputDecoration(
              isDense: true,
              border: const OutlineInputBorder(),
              hintText: 'Search customers and jobs',
              suffixIcon: label == null
                  ? null
                  : IconButton(
                      tooltip: 'Clear',
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () {
                        controller.clear();
                        widget.onChanged!(null);
                      },
                    ),
            ),
          ),
        );
      case 'date':
        return _DateEditor(
          key: key,
          value: widget.value as String?,
          formats: widget.formats,
          onChanged: widget.onChanged,
        );
      case 'formula':
        return Text(
          widget.value == null ? '—' : formatColumnNumber(widget.value, c),
          key: key,
        );
      case 'timeline':
        final range = widget.value is Map ? widget.value as Map : const {};
        void set(String end, Object? day) {
          final next = {'from': range['from'], 'to': range['to'], end: day};
          if (next['from'] == null && next['to'] == null) {
            widget.onChanged?.call(null);
            return;
          }
          // One end alone is a single day.
          next['from'] ??= next['to'];
          next['to'] ??= next['from'];
          if ('${next['from']}'.compareTo('${next['to']}') > 0) {
            end == 'from'
                ? next['to'] = next['from']
                : next['from'] = next['to'];
          }
          widget.onChanged?.call(next);
        }

        return Row(
          key: key,
          children: [
            Expanded(
              child: _DateEditor(
                key: ValueKey('column-editor-${c.id}-from'),
                value: range['from'] as String?,
                formats: widget.formats,
                onChanged: editable ? (d) => set('from', d) : null,
              ),
            ),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 6),
              child: Text('–'),
            ),
            Expanded(
              child: _DateEditor(
                key: ValueKey('column-editor-${c.id}-to'),
                value: range['to'] as String?,
                formats: widget.formats,
                onChanged: editable ? (d) => set('to', d) : null,
              ),
            ),
          ],
        );
      default:
        return TextField(
          key: key,
          controller: _text,
          enabled: editable,
          autofocus: widget.autofocus,
          maxLines: c.type == 'long_text' ? 6 : 1,
          minLines: 1,
          keyboardType: switch (c.type) {
            'number' ||
            'currency' => const TextInputType.numberWithOptions(decimal: true),
            'phone' => TextInputType.phone,
            'email' => TextInputType.emailAddress,
            'url' => TextInputType.url,
            'long_text' => TextInputType.multiline,
            _ => TextInputType.text,
          },
          decoration: InputDecoration(
            isDense: true,
            border: const OutlineInputBorder(),
            suffixText: c.type == 'number' ? c.unit : null,
            prefixText: c.type == 'currency' ? '\$ ' : null,
            hintText: c.type == 'datetime' ? 'YYYY-MM-DD HH:MM' : null,
            errorText: _invalid,
          ),
          onSubmitted: (_) => _commitText(),
          onTapOutside: (_) => _commitText(),
        );
    }
  }
}

class _DateEditor extends StatefulWidget {
  const _DateEditor({
    super.key,
    required this.value,
    required this.formats,
    this.onChanged,
  });

  final String? value;
  final DisplayFormats formats;
  final ValueChanged<Object?>? onChanged;

  @override
  State<_DateEditor> createState() => _DateEditorState();
}

class _DateEditorState extends State<_DateEditor> {
  late final _controller = TextEditingController(text: widget.value ?? '');

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => CalendarDayField(
    controller: _controller,
    formats: widget.formats,
    dense: true,
    enabled: widget.onChanged != null,
    onChanged: (v) {
      final day = v.isEmpty ? null : v;
      if (day != widget.value &&
          (day == null || RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(day))) {
        widget.onChanged?.call(day);
      }
    },
  );
}

// ── Setting a board up (UI queue 84) ─────────────────────────────

/// Where a board's columns and statuses are written.
// ponytail: paths as the Task Board session names them; one place to change.
String columnsPath(String board, [String? id]) =>
    '/task-boards/$board/columns${id == null ? '' : '/$id'}';
String statusesPath(String board, [String? id]) =>
    '/task-boards/$board/statuses${id == null ? '' : '/$id'}';

/// The colours a status or an option can be, as Monday offers them.
const swatches = [
  '#579BFC', '#00708F', '#A25DDC', '#7A4FB5', '#00C875', '#2F9A4C', //
  '#FDAB3D', '#D9930D', '#E2445C', '#C53B3B', '#FF5AC4', '#8E9BA3',
];

/// The column types, in the order the + menu offers them.
/// Jose, 2026-11-09: Monday's types. A Label is one coloured option, Tags
/// several; the server's names stay (dropdown is the Label).
const columnTypes = [
  ('text', 'Text', Icons.short_text),
  ('long_text', 'Long text', Icons.notes),
  ('number', 'Number', Icons.tag),
  ('currency', 'Currency', Icons.attach_money),
  ('phone', 'Phone', Icons.phone_outlined),
  ('email', 'Email', Icons.mail_outline),
  ('url', 'Link (URL)', Icons.link),
  ('date', 'Date', Icons.event_outlined),
  ('datetime', 'Date + time', Icons.schedule),
  ('checkbox', 'Checkbox', Icons.check_box_outlined),
  ('person', 'Person', Icons.person_outline),
  ('status', 'Status', Icons.label_outline),
  ('dropdown', 'Label', Icons.arrow_drop_down_circle_outlined),
  ('tags', 'Tags', Icons.sell_outlined),
  ('link', 'Customer / job', Icons.handshake_outlined),
  ('timeline', 'Timeline', Icons.view_timeline_outlined),
  ('formula', 'Formula', Icons.functions),
];

String columnTypeLabel(String type) =>
    [
      for (final t in columnTypes)
        if (t.$1 == type) t.$2,
    ].firstOrNull ??
    type;

/// A colour swatch that picks from [swatches].
class SwatchButton extends StatelessWidget {
  const SwatchButton({super.key, required this.colour, this.onPicked});

  final String colour;
  final ValueChanged<String>? onPicked;

  @override
  Widget build(BuildContext context) {
    Widget chip(String hex, {double size = 22}) => Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: ColourField.parse(hex),
        borderRadius: BorderRadius.circular(6),
      ),
    );
    return MenuAnchor(
      menuChildren: [
        Padding(
          padding: const EdgeInsets.all(8),
          child: SizedBox(
            width: 6 * 30,
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final hex in swatches)
                  InkWell(
                    key: ValueKey('swatch-$hex'),
                    onTap: () => onPicked?.call(hex),
                    child: chip(hex),
                  ),
              ],
            ),
          ),
        ),
      ],
      builder: (context, menu, _) => InkWell(
        key: key == null ? null : ValueKey('$key-open'),
        onTap: onPicked == null
            ? null
            : () => menu.isOpen ? menu.close() : menu.open(),
        child: chip(colour),
      ),
    );
  }
}

/// Add a column of [type], or change [column]: its name, a number's unit, a
/// status's or dropdown's options; move it, hide it, delete it. Pops true
/// when the board changed.
class ColumnPanel extends StatefulWidget {
  const ColumnPanel({
    super.key,
    required this.api,
    required this.boardId,
    required this.type,
    this.column,
    this.raw,
    this.others = const [],
    this.newType,
  });

  final ApiClient api;
  final String boardId;
  final String type;

  /// Opened from the header's Type menu: the type to change to.
  final String? newType;

  /// The board's other columns, for a formula's building blocks.
  final List<BoardColumn> others;

  /// The column being changed; null adds one.
  final BoardColumn? column;

  /// Its row as the server sent it, for position.
  final Map<String, dynamic>? raw;

  @override
  State<ColumnPanel> createState() => _ColumnPanelState();
}

class _ColumnPanelState extends State<ColumnPanel> {
  late final _name = TextEditingController(text: widget.column?.name ?? '');
  late final _unit = TextEditingController(text: widget.column?.unit ?? '');
  late String _type = widget.newType ?? widget.type;

  /// The server's "N values would be cleared", shown before a type change
  /// goes ahead anyway.
  String? _loss;
  late String _format = widget.column?.format ?? 'plain';
  late String _footer = widget.column?.footer ?? 'none';
  late final _expression = TextEditingController(
    text: '${widget.raw?['settings']?['expression'] ?? ''}',
  );

  bool get _numeric => _type == 'number' || _type == 'formula';

  /// Puts [text] where the cursor is in the formula.
  void _insert(String text) {
    final sel = _expression.selection;
    final at = sel.isValid ? sel.start : _expression.text.length;
    final end = sel.isValid ? sel.end : at;
    final pad = text.startsWith('[') ? text : ' $text ';
    _expression.value = TextEditingValue(
      text: _expression.text.replaceRange(at, end, pad),
      selection: TextSelection.collapsed(offset: at + pad.length),
    );
    setState(() {});
  }

  late final List<
    ({TextEditingController label, String colour, String? id, bool retired})
  >
  _options = [
    for (final o in widget.column?.options ?? const <ColumnOption>[])
      (
        label: TextEditingController(text: o.label),
        colour: o.colour == null ? swatches.first : ColourField.hex(o.colour!),
        id: o.id,
        retired: o.retired,
      ),
  ];
  bool _saving = false;
  bool _confirmDelete = false;
  String? _error;

  bool get _hasOptions =>
      _type == 'status' || _type == 'dropdown' || _type == 'tags';

  @override
  void dispose() {
    _name.dispose();
    _unit.dispose();
    _expression.dispose();
    for (final o in _options) {
      o.label.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _settings => {
    if (_type == 'number' && _unit.text.trim().isNotEmpty)
      'unit': _unit.text.trim(),
    if (_numeric && _format != 'plain') 'format': _format,
    if (_numeric && _footer != 'none') 'footer': _footer,
    if (_type == 'formula') 'expression': _expression.text.trim(),
    if (_hasOptions)
      'options': [
        for (final o in _options)
          if (o.label.text.trim().isNotEmpty)
            {
              'id': ?o.id,
              'label': o.label.text.trim(),
              'colour': o.colour,
              if (o.retired) 'retired': true,
            },
      ],
  };

  Future<void> _send(Future<ApiResponse> Function() call) async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final res = await call();
    if (!mounted) return;
    if (res.ok) {
      Navigator.of(context).pop(true);
    } else {
      final lost = res.reason;
      setState(() {
        _saving = false;
        _loss = lost.contains('would be cleared') ? lost : null;
        _error = _loss == null ? res.reason : null;
      });
    }
  }

  void _save({bool acceptLoss = false}) {
    if (_name.text.trim().isEmpty) {
      setState(() => _error = 'Give the column a name.');
      return;
    }
    final id = widget.column?.id;
    final body = {
      'name': _name.text.trim(),
      // A changed type carries the values over; the server says first how
      // many it cannot, and acceptLoss goes ahead.
      if (id == null || _type != widget.type) 'type': _type,
      if (acceptLoss) 'accept_loss': true,
      'settings': _settings,
    };
    _send(
      () => id == null
          ? widget.api.post(columnsPath(widget.boardId), body)
          : widget.api.patch(columnsPath(widget.boardId, id), body),
    );
  }

  void _move(int by) {
    final at = (widget.raw?['position'] as num?)?.toInt() ?? 0;
    _send(
      () => widget.api.patch(columnsPath(widget.boardId, widget.column!.id), {
        'position': (at + by).clamp(0, 1000),
      }),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final editing = widget.column != null;

    return SidePanel(
      title: editing
          ? widget.column!.name
          : 'Add a ${columnTypeLabel(_type).toLowerCase()} column',
      subtitle: columnTypeLabel(_type),
      actions: [
        if (editing)
          if (_confirmDelete)
            FilledButton(
              key: const Key('column-delete-confirm'),
              style: FilledButton.styleFrom(
                backgroundColor: theme.colorScheme.error,
                foregroundColor: theme.colorScheme.onError,
              ),
              onPressed: _saving
                  ? null
                  : () => _send(
                      () => widget.api.delete(
                        columnsPath(widget.boardId, widget.column!.id),
                      ),
                    ),
              child: const Text('Delete column and its values'),
            )
          else
            TextButton(
              key: const Key('column-delete'),
              onPressed: () => setState(() => _confirmDelete = true),
              child: const Text('Delete…'),
            ),
        if (_loss != null)
          FilledButton(
            key: const Key('column-type-anyway'),
            style: FilledButton.styleFrom(
              backgroundColor: theme.colorScheme.error,
              foregroundColor: theme.colorScheme.onError,
            ),
            onPressed: _saving ? null : () => _save(acceptLoss: true),
            child: const Text('Change type and clear them'),
          )
        else
          FilledButton(
            key: const Key('column-save'),
            onPressed: _saving ? null : _save,
            child: Text(editing ? 'Save' : 'Add column'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const Key('column-name'),
            controller: _name,
            autofocus: !editing,
            decoration: const InputDecoration(
              labelText: 'Name',
              border: OutlineInputBorder(),
            ),
          ),
          if (editing) ...[
            const SizedBox(height: 12),
            DropdownMenu<String>(
              key: const Key('column-type'),
              enableFilter: true,
              requestFocusOnTap: true,
              expandedInsets: EdgeInsets.zero,
              label: const Text('Type'),
              initialSelection: _type,
              onSelected: (v) => setState(() {
                _type = v ?? _type;
                _loss = null;
              }),
              dropdownMenuEntries: [
                for (final (type, label, icon) in columnTypes)
                  DropdownMenuEntry(
                    value: type,
                    label: label,
                    leadingIcon: Icon(icon, size: 18),
                  ),
              ],
            ),
            if (_type != widget.type)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  'Values are carried over where they fit. You are told first if any would be cleared.',
                  style: theme.textTheme.bodySmall,
                ),
              ),
          ],
          if (_type == 'formula') ...[
            const SizedBox(height: 12),
            TextField(
              key: const Key('column-formula'),
              controller: _expression,
              decoration: const InputDecoration(
                labelText: 'Formula',
                hintText: '[Job Cost] + [Materials]',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final c in widget.others)
                  if ((c.type == 'number' || c.type == 'formula') &&
                      c.id != widget.column?.id)
                    ActionChip(
                      key: ValueKey('formula-column-${c.id}'),
                      label: Text(c.name),
                      onPressed: () => _insert('[${c.name}]'),
                    ),
                for (final (op, label) in const [
                  ('+', '+'),
                  ('-', '−'),
                  ('*', '×'),
                  ('/', '÷'),
                  ('(', '('),
                  (')', ')'),
                ])
                  ActionChip(
                    key: ValueKey('formula-op-$op'),
                    label: Text(label),
                    onPressed: () => _insert(op),
                  ),
              ],
            ),
          ],
          if (_numeric) ...[
            const SizedBox(height: 12),
            Text('Show as', style: theme.textTheme.labelLarge),
            const SizedBox(height: 6),
            SegmentedButton<String>(
              key: const Key('column-format'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: 'plain', label: Text('Plain')),
                ButtonSegment(value: 'currency', label: Text('Currency')),
                ButtonSegment(value: 'percent', label: Text('%')),
              ],
              selected: {_format},
              onSelectionChanged: (v) => setState(() => _format = v.first),
            ),
            const SizedBox(height: 12),
            Text('Group footer', style: theme.textTheme.labelLarge),
            const SizedBox(height: 6),
            SegmentedButton<String>(
              key: const Key('column-footer'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: 'sum', label: Text('Sum')),
                ButtonSegment(value: 'average', label: Text('Average')),
                ButtonSegment(value: 'none', label: Text('None')),
              ],
              selected: {_footer},
              onSelectionChanged: (v) => setState(() => _footer = v.first),
            ),
          ],
          if (_type == 'number' && _format == 'plain') ...[
            const SizedBox(height: 12),
            TextField(
              key: const Key('column-unit'),
              controller: _unit,
              decoration: const InputDecoration(
                labelText: 'Unit (optional)',
                hintText: 'h, mi, \$',
                border: OutlineInputBorder(),
              ),
            ),
          ],
          if (_hasOptions) ...[
            const SizedBox(height: 16),
            Text(
              switch (_type) {
                'status' || 'dropdown' => 'Labels',
                _ => 'Tags',
              },
              style: theme.textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 8),
            for (final (i, o) in _options.indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(
                  children: [
                    SwatchButton(
                      key: ValueKey('column-option-colour-$i'),
                      colour: o.colour,
                      onPicked: (hex) => setState(
                        () => _options[i] = (
                          label: o.label,
                          colour: hex,
                          id: o.id,
                          retired: o.retired,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        key: ValueKey('column-option-$i'),
                        controller: o.label,
                        decoration: const InputDecoration(
                          isDense: true,
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    IconButton(
                      key: ValueKey('column-option-retire-$i'),
                      tooltip: o.retired
                          ? 'Retired: kept on old tasks, not offered. Bring back'
                          : 'Retire: keep it on old tasks, stop offering it',
                      isSelected: o.retired,
                      icon: const Icon(Icons.visibility_outlined, size: 18),
                      selectedIcon: const Icon(
                        Icons.visibility_off_outlined,
                        size: 18,
                      ),
                      onPressed: () => setState(
                        () => _options[i] = (
                          label: o.label,
                          colour: o.colour,
                          id: o.id,
                          retired: !o.retired,
                        ),
                      ),
                    ),
                    IconButton(
                      tooltip: 'Delete (clears it from every task)',
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () => setState(() => _options.removeAt(i)),
                    ),
                  ],
                ),
              ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const Key('column-option-add'),
                onPressed: () => setState(
                  () => _options.add((
                    label: TextEditingController(),
                    colour: swatches[_options.length % swatches.length],
                    id: null,
                    retired: false,
                  )),
                ),
                icon: const Icon(Icons.add, size: 18),
                label: Text(_type == 'tags' ? 'Add a tag' : 'Add a label'),
              ),
            ),
          ],
          if (editing) ...[
            const Divider(height: 28),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  key: const Key('column-left'),
                  onPressed: _saving ? null : () => _move(-1),
                  icon: const Icon(Icons.arrow_back, size: 18),
                  label: const Text('Move left'),
                ),
                OutlinedButton.icon(
                  key: const Key('column-right'),
                  onPressed: _saving ? null : () => _move(1),
                  icon: const Icon(Icons.arrow_forward, size: 18),
                  label: const Text('Move right'),
                ),
                OutlinedButton.icon(
                  key: const Key('column-hide'),
                  onPressed: _saving
                      ? null
                      : () => _send(
                          () => widget.api.patch(
                            columnsPath(widget.boardId, widget.column!.id),
                            {'hidden': true},
                          ),
                        ),
                  icon: const Icon(Icons.visibility_off_outlined, size: 18),
                  label: const Text('Hide'),
                ),
              ],
            ),
          ],
          if (_loss != null) ...[
            const SizedBox(height: 12),
            Text(
              _loss!,
              key: const Key('column-type-loss'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              _error!,
              key: const Key('column-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

/// A board's statuses: their names, colours and which count as done, each
/// saved as it changes. The board reloads when the panel closes.
class StatusesPanel extends StatefulWidget {
  const StatusesPanel({super.key, required this.api, required this.board});

  final ApiClient api;
  final Map<String, dynamic> board;

  @override
  State<StatusesPanel> createState() => _StatusesPanelState();
}

class _StatusesPanelState extends State<StatusesPanel> {
  late List<Map<String, dynamic>> _rows =
      (widget.board['statuses'] as List? ?? const [])
          .whereType<Map>()
          .map((s) => s.cast<String, dynamic>())
          .toList();
  final _names = <String, TextEditingController>{};
  String? _deleting;
  String? _moveTo;
  String? _error;

  String get _board => '${widget.board['id']}';

  @override
  void dispose() {
    for (final c in _names.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _name(Map<String, dynamic> s) => _names.putIfAbsent(
    '${s['id']}',
    () => TextEditingController(text: '${s['name'] ?? ''}'),
  );

  Future<bool> _write(Future<ApiResponse> call) async {
    final res = await call;
    if (!mounted) return false;
    setState(() => _error = res.ok ? null : res.reason);
    return res.ok;
  }

  Future<void> _patch(Map<String, dynamic> s, Map<String, dynamic> body) async {
    if (await _write(
      widget.api.patch(statusesPath(_board, '${s['id']}'), body),
    )) {
      setState(() => s.addAll(body));
    }
  }

  Future<void> _add() async {
    final res = await widget.api.post(statusesPath(_board), {
      'name': 'New status',
      'colour': swatches[_rows.length % swatches.length],
      'is_done': false,
    });
    if (!mounted) return;
    if (res.ok && res.body['data'] is Map) {
      setState(() {
        _error = null;
        _rows = [..._rows, (res.body['data'] as Map).cast<String, dynamic>()];
      });
    } else {
      setState(() => _error = res.reason);
    }
  }

  Future<void> _delete(String id) async {
    final ok = await _write(
      widget.api.delete(statusesPath(_board, id), {'move_to': ?_moveTo}),
    );
    if (ok) {
      setState(() {
        _rows = [
          for (final s in _rows)
            if ('${s['id']}' != id) s,
        ];
        _deleting = null;
        _moveTo = null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return SidePanel(
      title: 'Statuses · ${widget.board['name']}',
      subtitle: 'Each saves as you change it.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final s in _rows) ...[
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  SwatchButton(
                    key: ValueKey('status-colour-${s['id']}'),
                    colour: '${s['colour'] ?? swatches.last}',
                    onPicked: (hex) => _patch(s, {'colour': hex}),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: TextField(
                      key: ValueKey('status-name-${s['id']}'),
                      controller: _name(s),
                      decoration: const InputDecoration(
                        isDense: true,
                        border: OutlineInputBorder(),
                      ),
                      onSubmitted: (v) {
                        if (v.trim().isNotEmpty && v.trim() != s['name']) {
                          _patch(s, {'name': v.trim()});
                        }
                      },
                    ),
                  ),
                  const SizedBox(width: 6),
                  Tooltip(
                    message: 'Counts as done',
                    child: FilterChip(
                      key: ValueKey('status-done-${s['id']}'),
                      showCheckmark: false,
                      label: const Text('Done'),
                      selected: s['is_done'] == true,
                      onSelected: (v) => _patch(s, {'is_done': v}),
                    ),
                  ),
                  IconButton(
                    key: ValueKey('status-delete-${s['id']}'),
                    tooltip: 'Delete',
                    icon: const Icon(Icons.delete_outline, size: 18),
                    onPressed: _rows.length <= 1
                        ? null
                        : () => setState(() {
                            _deleting = '${s['id']}';
                            _moveTo = null;
                          }),
                  ),
                ],
              ),
            ),
            if (_deleting == '${s['id']}')
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text('Tasks in "${s['name']}" move to:', style: muted),
                    const SizedBox(height: 6),
                    SearchPickerField<String>(
                      key: const Key('status-move-to'),
                      value: _moveTo,
                      isDense: true,
                      hint: 'Choose a status',
                      options: [
                        for (final o in _rows)
                          if (o['id'] != s['id'])
                            SearchOption(
                              value: '${o['id']}',
                              label: '${o['name']}',
                            ),
                      ],
                      onChanged: (v) => setState(() => _moveTo = v),
                    ),
                    const SizedBox(height: 6),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        TextButton(
                          onPressed: () => setState(() => _deleting = null),
                          child: const Text('Keep it'),
                        ),
                        FilledButton(
                          key: const Key('status-delete-confirm'),
                          onPressed: _moveTo == null
                              ? null
                              : () => _delete('${s['id']}'),
                          child: const Text('Delete'),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
          ],
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const Key('status-add'),
              onPressed: _add,
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add a status'),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              key: const Key('status-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

/// A table cell that turns into its editor when clicked: typed types in the
/// cell itself, a day, a timeline or a person in a small card under it.
/// Enter or clicking away saves; Esc puts it back as it was.
class InlineCellEditor extends StatefulWidget {
  const InlineCellEditor({
    super.key,
    required this.column,
    required this.value,
    required this.people,
    required this.formats,
    required this.onChanged,
    required this.child,
  });

  final BoardColumn column;
  final Object? value;
  final Map<String, String> people;
  final DisplayFormats formats;
  final ValueChanged<Object?> onChanged;
  final Widget child;

  @override
  State<InlineCellEditor> createState() => _InlineCellEditorState();
}

class _InlineCellEditorState extends State<InlineCellEditor> {
  bool _editing = false;
  final _link = LayerLink();
  final _card = OverlayPortalController();

  /// Typed in the cell; everything else in a card, which has the room for a
  /// calendar, a picker or a validation message.
  bool get _inPlace =>
      const {'text', 'number', 'currency'}.contains(widget.column.type);

  void _start() {
    setState(() => _editing = true);
    if (!_inPlace) _card.show();
  }

  void _stop() {
    if (!mounted || !_editing) return;
    if (_card.isShowing) _card.hide();
    setState(() => _editing = false);
  }

  Widget _editor() => CallbackShortcuts(
    bindings: {const SingleActivator(LogicalKeyboardKey.escape): _stop},
    child: TapRegion(
      groupId: this,
      onTapOutside: (_) => Future.microtask(_stop),
      child: ColumnEditor(
        column: widget.column,
        value: widget.value,
        people: widget.people,
        formats: widget.formats,
        autofocus: true,
        onDone: () => Future.microtask(_stop),
        onChanged: (v) {
          widget.onChanged(v);
          // A timeline is two days: stay open for the second.
          if (widget.column.type != 'timeline' &&
              widget.column.type != 'datetime' &&
              !_inPlace) {
            _stop();
          }
        },
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final key = ValueKey('column-cell-edit-${widget.column.id}');
    if (_editing && _inPlace) {
      return KeyedSubtree(key: key, child: _editor());
    }
    return CompositedTransformTarget(
      link: _link,
      child: OverlayPortal(
        controller: _card,
        overlayChildBuilder: (context) => CompositedTransformFollower(
          link: _link,
          targetAnchor: Alignment.bottomLeft,
          child: Align(
            alignment: Alignment.topLeft,
            child: Material(
              elevation: 4,
              borderRadius: BorderRadius.circular(8),
              color: Theme.of(context).colorScheme.surface,
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: SizedBox(width: 300, child: _editor()),
              ),
            ),
          ),
        ),
        child: InkWell(
          key: key,
          onTap: _editing ? null : _start,
          child: SizedBox.expand(
            child: Align(alignment: Alignment.centerLeft, child: widget.child),
          ),
        ),
      ),
    );
  }
}
