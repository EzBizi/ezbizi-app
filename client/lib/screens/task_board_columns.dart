import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import '../widgets/cell_tap.dart';
import '../widgets/lazy_menu_anchor.dart';
import '../widgets/range_calendar.dart';
import 'package:flutter/services.dart';

import 'package:url_launcher/url_launcher.dart';

import '../core/api_client.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/calendar_day_field.dart';
import '../widgets/colour_field.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/user_avatar.dart';
import 'task_board_person_card.dart';

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

  /// Rating: how many stars (settings.max, default 5).
  int? max,
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
      return '{${c['id']}}';
    });

/// The columns a board shows, in its order. Hidden ones are left out.
/// Parsed once per columns list: every cell of every row asks (freeze
/// 11-16). A board's columns are replaced, never edited in place.
final _columnMemo = Expando<List<BoardColumn>>('boardColumns');

List<BoardColumn> boardColumns(Map<String, dynamic>? board) {
  final raw = board?['columns'];
  if (raw is! List) return _parseColumns(const []);
  return _columnMemo[raw] ??= List.unmodifiable(_parseColumns(raw));
}

List<BoardColumn> _parseColumns(List columns) {
  final rows =
      columns.whereType<Map>().where((c) => c['hidden'] != true).toList()..sort(
        (a, b) => ((a['position'] as num?) ?? 0).compareTo(
          (b['position'] as num?) ?? 0,
        ),
      );
  final byName = {
    for (final c in columns.whereType<Map>())
      '${c['name'] ?? ''}'.trim().toLowerCase(): c,
  };
  return [
    for (final c in rows)
      (
        id: '${c['id']}',
        name: '${c['name'] ?? ''}',
        type: '${c['type'] ?? 'text'}',
        unit: (c['settings'] as Map?)?['unit'] as String?,
        max: ((c['settings'] as Map?)?['max'] as num?)?.toInt(),
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
    switch (c.type) {
      'last_updated' => {'at': task['updated_at'] ?? task['created_at']},
      'creation_log' => {
        'at': task['created_at'],
        'by': (task['created_by'] as Map?)?['label'],
      },
      _ when c.formula != null => evalFormula(
        c.formula!,
        (task['values'] as Map?) ?? const {},
      ),
      _ => (task['values'] as Map?)?[c.id],
    };

/// Works out a compiled formula over a task's values, Monday's way:
/// + - * / & and brackets; = <> < > <= >= ; "text", TRUE, FALSE; and
/// IF, AND, OR, NOT, VALUE, CONCATENATE, FLOOR, CEILING, ROUND, MOD, ABS,
/// MIN, MAX, SUM, AVERAGE, LEN, UPPER, LOWER, LEFT, RIGHT. An empty value
/// counts 0 in sums and "" in text. Null when it does not parse, divides by
/// 0 or names an unknown function.
final _formulaToken = RegExp(
  r'\s+|\{[^}]+\}|"(?:[^"]|"")*"|\d+(?:\.\d+)?|[A-Za-z_][A-Za-z_0-9]*|<=|>=|<>|[-+*/()&=<>,]',
);

/// Tokens per formula, so a cell does not re-lex it on every build.
final _formulaTokens = <String, List<String>?>{};

List<String>? _tokensOf(String compiled) =>
    _formulaTokens.putIfAbsent(compiled, () {
      final all = _formulaToken.allMatches(compiled).map((m) => m[0]!).toList();
      if (all.join() != compiled) return null;
      final tokens = List<String>.unmodifiable(
        all.where((t) => t.trim().isNotEmpty),
      );
      return tokens.isEmpty ? null : tokens;
    });

Object? evalFormula(String compiled, Map values) {
  final tokens = _tokensOf(compiled);
  if (tokens == null) return null;
  var i = 0;
  var bad = false;

  num n(Object? v) => switch (v) {
    null => 0,
    num x => x,
    bool b => b ? 1 : 0,
    _ => num.tryParse('$v'.replaceAll(RegExp(r'[,$\s]'), '')) ?? 0,
  };
  String text(Object? v) => switch (v) {
    null => '',
    double d when d == d.roundToDouble() => '${d.toInt()}',
    _ => '$v',
  };
  bool truth(Object? v) => switch (v) {
    null => false,
    bool b => b,
    num x => x != 0,
    String t => t.isNotEmpty && t.toLowerCase() != 'false',
    _ => true,
  };
  num tidy(num x) =>
      x is double && x == x.roundToDouble() && x.abs() < 1e15 ? x.toInt() : x;

  late Object? Function() compare;
  List<Object?> args() {
    final out = <Object?>[];
    if (i < tokens.length && tokens[i] == ')') {
      i++;
      return out;
    }
    while (true) {
      out.add(compare());
      if (i >= tokens.length) {
        bad = true;
        return out;
      }
      final t = tokens[i++];
      if (t == ')') return out;
      if (t != ',') {
        bad = true;
        return out;
      }
    }
  }

  Object? call(String name, List<Object?> a) {
    num at(int k) => n(k < a.length ? a[k] : null);
    final nums = [for (final x in a) n(x)];
    switch (name.toUpperCase()) {
      case 'IF':
        return truth(a.isNotEmpty ? a[0] : null)
            ? (a.length > 1 ? a[1] : null)
            : (a.length > 2 ? a[2] : null);
      case 'AND':
        return a.every(truth);
      case 'OR':
        return a.any(truth);
      case 'NOT':
        return !truth(a.isNotEmpty ? a[0] : null);
      case 'VALUE':
        return n(a.isNotEmpty ? a[0] : null);
      case 'CONCATENATE':
        return a.map(text).join();
      case 'FLOOR':
        return at(0).floor();
      case 'CEILING':
        return at(0).ceil();
      case 'ROUND':
        final f = math.pow(10, at(1).toInt());
        return tidy((at(0) * f).round() / f);
      case 'MOD':
        if (at(1) == 0) {
          bad = true;
          return null;
        }
        return tidy(at(0) % at(1));
      case 'ABS':
        return at(0).abs();
      case 'MIN':
        return nums.isEmpty ? 0 : nums.reduce(math.min);
      case 'MAX':
        return nums.isEmpty ? 0 : nums.reduce(math.max);
      case 'SUM':
        return tidy(nums.fold<num>(0, (x, y) => x + y));
      case 'AVERAGE':
        return nums.isEmpty
            ? 0
            : tidy(nums.fold<num>(0, (x, y) => x + y) / nums.length);
      case 'LEN':
        return text(a.isNotEmpty ? a[0] : null).length;
      case 'UPPER':
        return text(a.isNotEmpty ? a[0] : null).toUpperCase();
      case 'LOWER':
        return text(a.isNotEmpty ? a[0] : null).toLowerCase();
      case 'LEFT' || 'RIGHT':
        final t = text(a.isNotEmpty ? a[0] : null);
        final k = (a.length > 1 ? at(1).toInt() : 1).clamp(0, t.length);
        return name.toUpperCase() == 'LEFT'
            ? t.substring(0, k)
            : t.substring(t.length - k);
    }
    bad = true;
    return null;
  }

  Object? factor() {
    if (i >= tokens.length) {
      bad = true;
      return null;
    }
    final t = tokens[i++];
    if (t == '-') return -n(factor());
    if (t == '+') return n(factor());
    if (t == '(') {
      final e = compare();
      if (i >= tokens.length || tokens[i++] != ')') bad = true;
      return e;
    }
    if (t.startsWith('{')) {
      final v = values[t.substring(1, t.length - 1)];
      return v is String ? (num.tryParse(v) ?? v) : v;
    }
    if (t.startsWith('"')) {
      return t.substring(1, t.length - 1).replaceAll('""', '"');
    }
    final number = num.tryParse(t);
    if (number != null) return number;
    if (t.toUpperCase() == 'TRUE') return true;
    if (t.toUpperCase() == 'FALSE') return false;
    if (i < tokens.length && tokens[i] == '(') {
      i++;
      return call(t, args());
    }
    bad = true;
    return null;
  }

  Object? term() {
    var left = factor();
    while (!bad &&
        i < tokens.length &&
        (tokens[i] == '*' || tokens[i] == '/')) {
      final op = tokens[i++];
      final right = n(factor());
      if (op == '/' && right == 0) {
        bad = true;
        return null;
      }
      left = tidy(op == '*' ? n(left) * right : n(left) / right);
    }
    return left;
  }

  Object? sum() {
    var left = term();
    while (!bad &&
        i < tokens.length &&
        const {'+', '-', '&'}.contains(tokens[i])) {
      final op = tokens[i++];
      final right = term();
      left = op == '&'
          ? text(left) + text(right)
          : tidy(op == '+' ? n(left) + n(right) : n(left) - n(right));
    }
    return left;
  }

  compare = () {
    final left = sum();
    if (bad || i >= tokens.length) return left;
    final op = tokens[i];
    if (!const {'=', '<>', '<', '>', '<=', '>='}.contains(op)) return left;
    i++;
    final right = sum();
    if (op == '=' || op == '<>') {
      final same = (left is num || right is num)
          ? n(left) == n(right)
          : text(left) == text(right);
      return op == '=' ? same : !same;
    }
    final c = n(left).compareTo(n(right));
    return switch (op) {
      '<' => c < 0,
      '>' => c > 0,
      '<=' => c <= 0,
      _ => c >= 0,
    };
  };

  final out = compare();
  if (bad || i != tokens.length) return null;
  return out ?? 0;
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
    this.onAutoAssign,
    this.api,
    this.boardId,
    this.onFilter,
    this.tasks,
    this.onAddFile,
    this.taskDone = false,
  });

  /// The task's status is a done one: its timeline is never overdue.
  final bool taskDone;

  /// Searches customers and jobs for a link column's card.
  final ApiClient? api;

  /// Asks the AI for this task's label in this column (null: none fits).
  final Future<String?> Function()? onAutoAssign;

  /// With [api], a People cell's faces open Monday's person card on hover.
  final String? boardId;

  /// "Filter by this value" on the cell's right-click menu.
  final VoidCallback? onFilter;

  /// The tasks a Connect or Dependency cell can pick from, (id, title).
  final List<(String, String)>? tasks;

  /// A Files cell's "+": picks a file and adds it to the task.
  final VoidCallback? onAddFile;

  /// Whether the cell is edited in place, with its own right-click menu.
  static bool editsInPlace(BoardColumn c, {required bool editable}) =>
      editable && !_ownControl.contains(c.type);

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
    'people',
    'formula',
    'rating',
    'files',
    'connect',
    'dependency',
    'mirror',
    'last_updated',
    'creation_log',
  };

  /// A click edits the value where it is (Jose, 2026-11-12: the sidebar is
  /// for Updates and the task, not for typing one field).
  @override
  Widget build(BuildContext context) {
    final shown = _display(context);
    if ((column.type == 'connect' || column.type == 'dependency') &&
        onChanged != null &&
        tasks != null) {
      return TaskPickCell(
        column: column,
        value: value,
        tasks: tasks!,
        onChanged: onChanged!,
        child: shown,
      );
    }
    if (column.type == 'files' && onAddFile != null) {
      return Row(
        children: [
          Expanded(child: shown),
          IconButton(
            key: ValueKey('column-file-add-${column.id}'),
            tooltip: 'Add a file',
            visualDensity: VisualDensity.compact,
            iconSize: 18,
            icon: const Icon(Icons.attach_file),
            onPressed: onAddFile,
          ),
        ],
      );
    }
    if (onChanged == null || _ownControl.contains(column.type)) return shown;
    return InlineCellEditor(
      api: api,
      column: column,
      value: value,
      people: people,
      formats: formats,
      onChanged: onChanged!,
      onFilter: onFilter,
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
        final picked = _option(column, v);
        final fill = picked?.colour ?? blankLabel(theme);
        return LazyMenuAnchor(
          menuChildren: [
            Builder(
              builder: (context) => LabelBlocks(
                keyPrefix: 'column-option-${column.id}',
                labels: [
                  for (final o in column.options)
                    if (!o.retired || o.id == v)
                      (id: o.id, label: o.label, colour: o.colour),
                  (id: null, label: '', colour: null),
                ],
                current: picked?.id,
                onPick: (id) {
                  MenuController.maybeOf(context)?.close();
                  onChanged?.call(id);
                },
                onEdit: onEditOptions == null
                    ? null
                    : () {
                        MenuController.maybeOf(context)?.close();
                        onEditOptions!();
                      },
                onAutoAssign: onAutoAssign == null
                    ? null
                    : () async {
                        MenuController.maybeOf(context)?.close();
                        final id = await onAutoAssign!();
                        if (id != null) onChanged?.call(id);
                      },
              ),
            ),
          ],
          onOpen: () => onMenu?.call(true),
          onClose: () => onMenu?.call(false),
          builder: (context, menu, _) => ColoredBox(
            color: fill,
            child: CellTap(
              key: ValueKey('column-cell-${column.id}'),
              onTap: onChanged == null
                  ? null
                  : () => menu.isOpen ? menu.close() : menu.open(),
              child: Center(
                child: Text(
                  picked?.label ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 12,
                    color: labelInk(fill),
                  ),
                ),
              ),
            ),
          ),
        );
      case 'dropdown':
        final picked = _option(column, v);
        final fill = picked?.colour;
        return LazyMenuAnchor(
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
          builder: (context, menu, _) => CellTap(
            key: ValueKey('column-cell-${column.id}'),
            onTap: onChanged == null
                ? null
                : () => menu.isOpen ? menu.close() : menu.open(),
            child: Center(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
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
        );
      case 'people':
        final ids = [for (final id in (v as List?) ?? const []) '$id'];
        return LazyMenuAnchor(
          menuChildren: [
            for (final p in people.entries)
              CheckboxMenuButton(
                key: ValueKey('column-person-${column.id}-${p.key}'),
                value: ids.contains(p.key),
                closeOnActivate: false,
                onChanged: (on) {
                  final next = [
                    for (final id in ids)
                      if (id != p.key) id,
                    if (on == true) p.key,
                  ];
                  onChanged?.call(next.isEmpty ? null : next);
                },
                child: Text(p.value),
              ),
          ],
          builder: (context, menu, _) => CellTap(
            key: ValueKey('column-cell-${column.id}'),
            onTap: onChanged == null
                ? null
                : () => menu.isOpen ? menu.close() : menu.open(),
            child: Center(
              child: PeopleAvatars(
                names: [for (final id in ids) people[id] ?? '?'],
                ids: ids,
                api: api,
                boardId: boardId,
              ),
            ),
          ),
        );
      case 'tags':
        final ids = [for (final id in (v as List?) ?? const []) '$id'];
        return LazyMenuAnchor(
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
          builder: (context, menu, _) => CellTap(
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
        return TimelinePill(value: v, formats: formats, done: taskDone);
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
      case 'rating':
        // settings.max stars (default 5), more if a value is higher.
        final n = v is num ? v.toInt() : 0;
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (var i = 1; i <= math.max(column.max ?? 5, n); i++)
              InkWell(
                key: ValueKey('column-rating-${column.id}-$i'),
                onTap: onChanged == null
                    ? null
                    : () => onChanged!(i == n ? i - 1 : i),
                child: Icon(
                  i <= n ? Icons.star : Icons.star_border,
                  size: 16,
                  color: i <= n
                      ? theme.colorScheme.tertiary
                      : theme.colorScheme.outline,
                ),
              ),
          ],
        );
      case 'location':
        final address = v is Map ? '${v['address'] ?? ''}' : '';
        return Text(
          address.isEmpty ? '—' : address,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: address.isEmpty ? muted : null,
        );
      case 'files' || 'connect' || 'dependency':
        final count = v is List ? v.length : 0;
        final noun = column.type == 'files' ? 'file' : 'task';
        return Text(
          count == 0 ? '—' : '$count $noun${count == 1 ? '' : 's'}',
          style: count == 0 ? muted : null,
        );
      case 'mirror':
        final items = v is List ? v.map((x) => '$x').join(', ') : '';
        return Text(
          items.isEmpty ? '—' : items,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: items.isEmpty ? muted : null,
        );
      case 'last_updated' || 'creation_log':
        final at = v is Map ? DateTime.tryParse('${v['at']}')?.toLocal() : null;
        return Text(
          at == null
              ? '—'
              : [
                  if (v is Map && v['by'] != null) '${v['by']}',
                  formats.formatDate(at),
                ].join(' · '),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: at == null ? muted : null,
        );
      case 'person':
        // A face, not a name (Jose 11-16: "just use the icon ... like
        // monday does"); the hover card says who.
        final name = people['$v'];
        return Center(
          child: PeopleAvatars(
            names: [?name],
            ids: [if (name != null) '$v'],
            api: api,
            boardId: boardId,
          ),
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
      'location' => '${(widget.value as Map?)?['address'] ?? ''}',
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
    if (widget.column.type == 'location') {
      // Typed: an address; lat and lng come from an import or a map pick.
      final was = (widget.value as Map?)?['address'];
      if (typed != (was ?? '')) {
        widget.onChanged?.call(
          typed.isEmpty ? null : {'address': typed, 'lat': null, 'lng': null},
        );
      }
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
      case 'people':
        final ids = [
          for (final id in (widget.value as List?) ?? const []) '$id',
        ];
        return Wrap(
          key: key,
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final p in widget.people.entries)
              FilterChip(
                key: ValueKey('column-editor-${c.id}-${p.key}'),
                label: Text(p.value),
                selected: ids.contains(p.key),
                onSelected: editable
                    ? (on) {
                        final next = [
                          for (final id in ids)
                            if (id != p.key) id,
                          if (on) p.key,
                        ];
                        widget.onChanged!(next.isEmpty ? null : next);
                      }
                    : null,
              ),
          ],
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
        // One calendar: first click starts, second ends (Jose, 2026-11-16).
        return RangeCalendar(
          key: key,
          from: range['from'] as String?,
          to: range['to'] as String?,
          onChanged: editable
              ? (from, to) => widget.onChanged?.call(
                  from == null ? null : {'from': from, 'to': to},
                )
              : null,
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
  ('people', 'People', Icons.group_outlined),
  ('status', 'Status', Icons.label_outline),
  ('dropdown', 'Label', Icons.arrow_drop_down_circle_outlined),
  ('tags', 'Tags', Icons.sell_outlined),
  ('link', 'Customer / job', Icons.handshake_outlined),
  ('timeline', 'Timeline', Icons.view_timeline_outlined),
  ('formula', 'Formula', Icons.functions),
  ('location', 'Location', Icons.place_outlined),
  ('rating', 'Rating', Icons.star_border),
  ('files', 'Files', Icons.attach_file),
  ('connect', 'Connect boards', Icons.device_hub),
  ('mirror', 'Mirror', Icons.flip),
  ('dependency', 'Dependency', Icons.account_tree_outlined),
  ('last_updated', 'Last updated', Icons.update),
  ('creation_log', 'Creation log', Icons.history),
];

/// The + popover's sections, as Monday has them; the rest is More.
const columnTypeSections = [
  ('Essentials', ['status', 'dropdown', 'text', 'date', 'people', 'number']),
  (
    'Super useful',
    ['files', 'checkbox', 'long_text', 'formula', 'connect', 'timeline'],
  ),
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

  /// Mirror: the connect column it follows, and the column of the tasks
  /// that one names which it shows.
  late String? _mirrorConnect =
      widget.raw?['settings']?['connect_column_id'] as String?;
  late String? _mirrorColumn = widget.raw?['settings']?['column_id'] as String?;

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
    if (_type == 'mirror') ...{
      'connect_column_id': ?_mirrorConnect,
      'column_id': ?_mirrorColumn,
    },
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

  List<Widget> _mirrorFields() {
    final connects = [
      for (final c in widget.others)
        if (c.type == 'connect' && c.id != widget.column?.id) c,
    ];
    final shown = [
      for (final c in widget.others)
        if (c.type != 'mirror' &&
            c.type != 'connect' &&
            c.id != widget.column?.id)
          c,
    ];
    return [
      const SizedBox(height: 12),
      if (connects.isEmpty)
        const Padding(
          padding: EdgeInsets.only(bottom: 8),
          child: Text(
            'Add a Connect column first: a mirror shows a column of the tasks '
            'it connects to.',
          ),
        ),
      Text(
        'Connect column to follow',
        style: Theme.of(context).textTheme.labelMedium,
      ),
      const SizedBox(height: 4),
      SearchPickerField<String>(
        key: const Key('column-mirror-connect'),
        value: connects.any((c) => c.id == _mirrorConnect)
            ? _mirrorConnect
            : null,
        hint: 'Pick a Connect column',
        options: [
          for (final c in connects)
            SearchOption<String>(value: c.id, label: c.name),
        ],
        onChanged: (v) => setState(() => _mirrorConnect = v),
      ),
      const SizedBox(height: 12),
      Text('Column to show', style: Theme.of(context).textTheme.labelMedium),
      const SizedBox(height: 4),
      SearchPickerField<String>(
        key: const Key('column-mirror-column'),
        value: shown.any((c) => c.id == _mirrorColumn) ? _mirrorColumn : null,
        hint: 'Pick a column',
        options: [
          for (final c in shown)
            SearchOption<String>(value: c.id, label: c.name),
        ],
        onChanged: (v) => setState(() => _mirrorColumn = v),
      ),
    ];
  }

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
          if (_type == 'mirror') ..._mirrorFields(),
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
    this.api,
    required this.column,
    required this.value,
    required this.people,
    required this.formats,
    required this.onChanged,
    required this.child,
    this.onFilter,
  });

  /// Adds "Filter by this value" to the right-click menu.
  final VoidCallback? onFilter;

  final BoardColumn column;
  final Object? value;
  final Map<String, String> people;
  final DisplayFormats formats;
  final ValueChanged<Object?> onChanged;
  final Widget child;

  final ApiClient? api;

  /// How many cells are open for editing right now. A board's live refresh
  /// patches rows in place but holds back inserts and removals while one is
  /// open, so the row under the editor stays the row being edited.
  static final editing = ValueNotifier<int>(0);

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

  /// The value this cell last sent, and whether the value under it moved to
  /// something else while it was open (a remote change: Monday, a colleague).
  Object? _sent;
  bool _elsewhere = false;

  static bool _same(Object? a, Object? b) =>
      a == b || jsonEncode(a) == jsonEncode(b);

  void _start() {
    setState(() => _editing = true);
    InlineCellEditor.editing.value++;
    if (!_inPlace) _card.show();
  }

  void _stop() {
    if (!mounted || !_editing) return;
    if (_card.isShowing) _card.hide();
    InlineCellEditor.editing.value--;
    setState(() {
      _editing = false;
      _elsewhere = false;
      _sent = null;
    });
  }

  @override
  void didUpdateWidget(InlineCellEditor old) {
    super.didUpdateWidget(old);
    // The editor keeps what the person typed; the marker says the saved
    // value under it changed meanwhile.
    if (_editing &&
        !_same(widget.value, old.value) &&
        !_same(widget.value, _sent)) {
      _elsewhere = true;
    }
  }

  @override
  void dispose() {
    if (_editing) InlineCellEditor.editing.value--;
    super.dispose();
  }

  Widget _editor() => CallbackShortcuts(
    bindings: {const SingleActivator(LogicalKeyboardKey.escape): _stop},
    child: TapRegion(
      groupId: this,
      onTapOutside: (_) => Future.microtask(_stop),
      child: Stack(
        clipBehavior: Clip.none,
        fit: StackFit.passthrough,
        children: [
          _columnEditor(),
          if (_elsewhere)
            Positioned(
              top: -6,
              right: -6,
              child: Tooltip(
                message: 'Changed elsewhere; saving keeps yours',
                child: Icon(
                  key: const Key('cell-changed-elsewhere'),
                  Icons.sync_problem,
                  size: 14,
                  color: Theme.of(context).colorScheme.error,
                ),
              ),
            ),
        ],
      ),
    ),
  );

  Widget _columnEditor() => ColumnEditor(
    api: widget.api,
    column: widget.column,
    value: widget.value,
    people: widget.people,
    formats: widget.formats,
    autofocus: true,
    onDone: () => Future.microtask(_stop),
    onChanged: (v) {
      _sent = v;
      widget.onChanged(v);
      // A timeline is two days: stay open for the second.
      if (widget.column.type != 'timeline' &&
          widget.column.type != 'datetime' &&
          !_inPlace) {
        _stop();
      }
    },
  );

  /// What a copied cell carries: text as typed, anything else as JSON so a
  /// paste into the same kind of column puts back the same value.
  String _copyText(Object? v) => switch (v) {
    null => '',
    String s => s,
    num n => '$n',
    _ => jsonEncode(v),
  };

  Object? _pasteValue(String text) {
    final t = text.trim();
    if (t.isEmpty) return null;
    switch (widget.column.type) {
      case 'number' || 'currency' || 'rating':
        return num.tryParse(t.replaceAll(RegExp(r'[,$\s]'), ''));
      case 'text' || 'long_text' || 'email' || 'phone' || 'url':
        return t;
    }
    try {
      return jsonDecode(t);
    } on FormatException {
      return t;
    }
  }

  /// Right-click on a cell: Copy, Paste, Clear (Monday's cell menu).
  Future<void> _menu(Offset at) async {
    final box = Overlay.of(context).context.findRenderObject() as RenderBox;
    final choice = await showMenu<String>(
      context: context,
      position: RelativeRect.fromRect(
        at & const Size(1, 1),
        Offset.zero & box.size,
      ),
      items: [
        const PopupMenuItem(
          key: Key('cell-rc-copy'),
          value: 'copy',
          height: 36,
          child: Text('Copy'),
        ),
        const PopupMenuItem(
          key: Key('cell-rc-paste'),
          value: 'paste',
          height: 36,
          child: Text('Paste'),
        ),
        const PopupMenuItem(
          key: Key('cell-rc-clear'),
          value: 'clear',
          height: 36,
          child: Text('Clear'),
        ),
        if (widget.onFilter != null && widget.value != null)
          const PopupMenuItem(
            key: Key('cell-filter-value'),
            value: 'filter',
            height: 36,
            child: Text('Filter by this value'),
          ),
      ],
    );
    switch (choice) {
      case 'copy':
        await Clipboard.setData(ClipboardData(text: _copyText(widget.value)));
      case 'paste':
        final data = await Clipboard.getData(Clipboard.kTextPlain);
        final v = _pasteValue(data?.text ?? '');
        if (v != null) widget.onChanged(v);
      case 'clear':
        widget.onChanged(null);
      case 'filter':
        widget.onFilter?.call();
    }
  }

  @override
  Widget build(BuildContext context) {
    final key = ValueKey('column-cell-edit-${widget.column.id}');
    if (_editing && _inPlace) {
      return KeyedSubtree(key: key, child: _editor());
    }
    final cell = CellTap(
      key: key,
      onTap: _editing ? null : _start,
      onSecondaryTapDown: _editing ? null : (d) => _menu(d.globalPosition),
      child: SizedBox.expand(
        child: Align(alignment: Alignment.centerLeft, child: widget.child),
      ),
    );
    // The card's portal only while editing: one per cell on every row was
    // most of a big board's build (Task Board lag, 11-16).
    if (!_editing) return CompositedTransformTarget(link: _link, child: cell);
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
        child: cell,
      ),
    );
  }
}

/// One label in a [LabelBlocks] menu: its id (null: the blank label),
/// name and colour.
typedef LabelBlock = ({String? id, String label, Color? colour});

/// Text that reads on a label's solid colour, the same in light and dark:
/// white on a dark fill, ink on a light one (Monday's cells, Jose 11-13).
Color labelInk(Color fill) =>
    ThemeData.estimateBrightnessForColor(fill) == Brightness.dark
    ? EzBiziTheme.fieldInkLight
    : EzBiziTheme.fieldInkDark;

/// The grey a blank label wears.
Color blankLabel(ThemeData theme) => theme.colorScheme.outline;

/// Monday's label picker: solid colour blocks in one to three columns
/// (more labels, more columns), the current one outlined, then the tools.
class LabelBlocks extends StatelessWidget {
  const LabelBlocks({
    super.key,
    required this.keyPrefix,
    required this.labels,
    required this.current,
    required this.onPick,
    this.onEdit,
    this.onAutoAssign,
  });

  final String keyPrefix;
  final List<LabelBlock> labels;
  final String? current;
  final ValueChanged<String?> onPick;
  final VoidCallback? onEdit;
  final VoidCallback? onAutoAssign;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final columns = labels.length > 12 ? 3 : (labels.length > 6 ? 2 : 1);
    const width = 150.0;
    return Padding(
      padding: const EdgeInsets.all(8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            width: columns * width + (columns - 1) * 6,
            child: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final l in labels)
                  _block(theme, l, width, l.id == current),
              ],
            ),
          ),
          if (onEdit != null || onAutoAssign != null) const SizedBox(height: 8),
          if (onEdit != null)
            TextButton.icon(
              key: ValueKey('$keyPrefix-edit'),
              onPressed: onEdit,
              icon: const Icon(Icons.edit_outlined, size: 18),
              label: const Text('Edit labels'),
            ),
          if (onAutoAssign != null)
            TextButton.icon(
              key: ValueKey('$keyPrefix-auto'),
              onPressed: onAutoAssign,
              icon: const Icon(Icons.auto_awesome_outlined, size: 18),
              label: const Text('Auto-assign label'),
            ),
        ],
      ),
    );
  }

  Widget _block(ThemeData theme, LabelBlock l, double width, bool on) {
    final fill = l.colour ?? blankLabel(theme);
    return SizedBox(
      width: width,
      height: 32,
      child: Material(
        color: fill,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(4),
          side: on
              ? BorderSide(color: theme.colorScheme.onSurface, width: 2)
              : BorderSide.none,
        ),
        child: InkWell(
          key: ValueKey('$keyPrefix-${l.id ?? 'blank'}'),
          onTap: () => onPick(l.id),
          child: Center(
            child: Text(
              l.label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: labelInk(fill),
                fontWeight: FontWeight.w700,
                fontSize: 12,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Monday's timeline cell: a pill with the elapsed part filled; "-" empty.
class TimelinePill extends StatelessWidget {
  const TimelinePill({
    super.key,
    required this.value,
    required this.formats,
    this.now,
    this.done = false,
  });

  final Object? value;
  final DisplayFormats formats;
  final DateTime? now;

  /// The task is done: an end date in the past is finished, not late.
  final bool done;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final label = timelineLabel(value, formats);
    if (label == null) {
      return Center(
        child: Text(
          '-',
          style: TextStyle(color: theme.colorScheme.onSurfaceVariant),
        ),
      );
    }
    final v = value as Map;
    final from = DateTime.parse('${v['from']}');
    final to = DateTime.parse('${v['to']}').add(const Duration(days: 1));
    final at = now ?? DateTime.now();
    final total = to.difference(from).inMinutes;
    final elapsed = total <= 0
        ? 1.0
        : (at.difference(from).inMinutes / total).clamp(0.0, 1.0);
    final back = theme.colorScheme.onSurfaceVariant;
    // Monday: an open task whose end date has passed wears red.
    final day = DateTime(at.year, at.month, at.day);
    // [to] is the midnight after the last day: late from that midnight on.
    final overdue = !done && !to.isAfter(day);
    return Center(
      child: Container(
        height: 22,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: back,
          borderRadius: BorderRadius.circular(11),
        ),
        child: Stack(
          children: [
            FractionallySizedBox(
              key: const Key('timeline-elapsed'),
              widthFactor: elapsed,
              heightFactor: 1,
              child: Container(
                color: overdue
                    ? theme.colorScheme.error
                    : theme.colorScheme.primary,
              ),
            ),
            Center(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: labelInk(overdue ? theme.colorScheme.error : back),
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A group footer's stacked bar: each label's share of the group's tasks,
/// in its colour; blank labels grey.
class LabelBar extends StatelessWidget {
  const LabelBar({super.key, required this.counts});

  /// Colour (null: blank) and how many tasks wear it.
  final List<(Color?, int)> counts;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final shown = [
      for (final c in counts)
        if (c.$2 > 0) c,
    ];
    if (shown.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 7),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final (colour, n) in shown)
              Expanded(
                flex: n,
                child: ColoredBox(color: colour ?? blankLabel(theme)),
              ),
          ],
        ),
      ),
    );
  }
}

/// A group footer's date range: the earliest to the latest day in [days];
/// null when there are none.
String? dateRangeLabel(Iterable<DateTime> days, DisplayFormats formats) {
  final list = days.toList()..sort();
  if (list.isEmpty) return null;
  return list.first == list.last
      ? formats.formatDate(list.first)
      : '${formats.formatDate(list.first)} – ${formats.formatDate(list.last)}';
}

/// Every day a value covers, for a footer's range: a date, or a timeline's
/// two ends.
Iterable<DateTime> daysOf(Object? v) sync* {
  if (v is String) {
    if (DateTime.tryParse(v) case final d?) yield d;
  } else if (v is Map) {
    if (DateTime.tryParse('${v['from']}') case final d?) yield d;
    if (DateTime.tryParse('${v['to']}') case final d?) yield d;
  }
}

/// Monday's People cell: up to three overlapping initials and "+N";
/// "—" when nobody.
class PeopleAvatars extends StatelessWidget {
  const PeopleAvatars({
    super.key,
    required this.names,
    this.ids,
    this.api,
    this.boardId,
  });

  final List<String> names;

  /// Each name's user id, in order; with [api] and [boardId] every face
  /// carries the person hover card.
  final List<String>? ids;
  final ApiClient? api;
  final String? boardId;

  bool get _cards =>
      api != null &&
      boardId != null &&
      ids != null &&
      ids!.length == names.length;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (names.isEmpty) {
      // Nobody yet: a faint person-plus; the cell's own tap assigns.
      return Semantics(
        label: 'Nobody assigned',
        child: Icon(
          Icons.person_add_alt_outlined,
          key: const Key('people-empty'),
          size: 18,
          color: theme.colorScheme.onSurfaceVariant.withValues(alpha: 0.45),
        ),
      );
    }
    final shown = names.take(3).toList();
    final more = names.length - shown.length;
    // The photo where there is one, initials while it loads or without.
    Widget photo(int i, String n) => !_cards
        ? _initials(theme, n)
        : Container(
            width: 26,
            height: 26,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              border: Border.all(color: theme.colorScheme.surface, width: 2),
            ),
            child: UserAvatar(api: api!, userId: ids![i], name: n, radius: 11),
          );
    Widget face(int i, String n) => !_cards
        ? photo(i, n)
        : PersonHoverCard(
            key: ValueKey('people-face-${ids![i]}'),
            api: api!,
            boardId: boardId!,
            userId: ids![i],
            child: photo(i, n),
          );
    return Tooltip(
      // The card says who; a tooltip on top of it would cover it.
      message: _cards ? '' : names.join(', '),
      excludeFromSemantics: true,
      child: Semantics(
        label: names.join(', '),
        child: ExcludeSemantics(child: _faces(theme, shown, more, face)),
      ),
    );
  }

  Widget _faces(
    ThemeData theme,
    List<String> shown,
    int more,
    Widget Function(int, String) face,
  ) {
    return SizedBox(
      height: 26,
      width: 26.0 + (shown.length - 1) * 16 + (more > 0 ? 22 : 0),
      child: Stack(
        children: [
          for (final (i, n) in shown.indexed)
            Positioned(left: i * 16.0, child: face(i, n)),
          if (more > 0)
            Positioned(
              left: shown.length * 16.0 + 6,
              top: 5,
              child: Text(
                '+$more',
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _initials(ThemeData theme, String name) {
    final words = name.trim().split(RegExp(r'\s+'));
    final letters = [
      for (final w in words.take(2))
        if (w.isNotEmpty) w[0].toUpperCase(),
    ].join();
    final fill = EzBiziTheme
        .avatarPalette[name.hashCode.abs() % EzBiziTheme.avatarPalette.length];
    return Container(
      width: 26,
      height: 26,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: fill,
        shape: BoxShape.circle,
        border: Border.all(color: theme.colorScheme.surface, width: 2),
      ),
      child: Text(
        letters,
        style: TextStyle(
          color: labelInk(fill),
          fontSize: 10,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

/// A Connect or Dependency cell: a click opens a small card under it to tick
/// the tasks it points at, with a search on top (Monday's item picker).
class TaskPickCell extends StatefulWidget {
  const TaskPickCell({
    super.key,
    required this.column,
    required this.value,
    required this.tasks,
    required this.onChanged,
    required this.child,
  });

  final BoardColumn column;
  final Object? value;
  final List<(String, String)> tasks;
  final ValueChanged<Object?> onChanged;
  final Widget child;

  @override
  State<TaskPickCell> createState() => _TaskPickCellState();
}

class _TaskPickCellState extends State<TaskPickCell> {
  final _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  List<String> get _ids => [
    for (final id in (widget.value as List?) ?? const []) '$id',
  ];

  @override
  Widget build(BuildContext context) {
    final q = _search.text.trim().toLowerCase();
    final ids = _ids;
    final shown = [
      for (final (id, title) in widget.tasks)
        if (q.isEmpty || title.toLowerCase().contains(q)) (id, title),
    ].take(30);
    return MenuAnchor(
      menuChildren: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 6, 8, 4),
          child: SizedBox(
            width: 260,
            child: TextField(
              key: ValueKey('task-pick-search-${widget.column.id}'),
              controller: _search,
              autofocus: true,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                isDense: true,
                hintText: 'Find a task',
                prefixIcon: Icon(Icons.search, size: 18),
                border: OutlineInputBorder(),
              ),
            ),
          ),
        ),
        for (final (id, title) in shown)
          CheckboxMenuButton(
            key: ValueKey('task-pick-${widget.column.id}-$id'),
            value: ids.contains(id),
            closeOnActivate: false,
            onChanged: (on) {
              final next = [
                for (final x in ids)
                  if (x != id) x,
                if (on == true) id,
              ];
              widget.onChanged(next.isEmpty ? null : next);
            },
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 220),
              child: Text(title, overflow: TextOverflow.ellipsis),
            ),
          ),
        if (shown.isEmpty)
          const MenuItemButton(onPressed: null, child: Text('No tasks match')),
      ],
      builder: (context, menu, _) => InkWell(
        key: ValueKey('column-cell-${widget.column.id}'),
        onTap: () {
          if (menu.isOpen) return menu.close();
          _search.clear();
          menu.open();
        },
        child: Align(alignment: Alignment.centerLeft, child: widget.child),
      ),
    );
  }
}
