import 'package:flutter/material.dart';

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
typedef ColumnOption = ({String id, String label, Color? colour});

typedef BoardColumn = ({
  String id,
  String name,
  String type,
  String? unit,
  List<ColumnOption> options,
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
  return [
    for (final c in rows)
      (
        id: '${c['id']}',
        name: '${c['name'] ?? ''}',
        type: '${c['type'] ?? 'text'}',
        unit: (c['settings'] as Map?)?['unit'] as String?,
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
            ),
        ],
      ),
  ];
}

/// A task's value for one column.
Object? columnValue(Map<String, dynamic> task, BoardColumn c) =>
    (task['values'] as Map?)?[c.id];

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
class ColumnCell extends StatelessWidget {
  const ColumnCell({
    super.key,
    required this.column,
    required this.value,
    required this.people,
    required this.formats,
    this.onChanged,
  });

  final BoardColumn column;
  final Object? value;
  final Map<String, String> people;
  final DisplayFormats formats;

  /// Null where the board is view-only.
  final ValueChanged<Object?>? onChanged;

  @override
  Widget build(BuildContext context) {
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
              MenuItemButton(
                key: ValueKey('column-option-${column.id}-${o.id}'),
                onPressed: () => onChanged?.call(o.id),
                child: Text(o.label),
              ),
            if (v != null)
              MenuItemButton(
                onPressed: () => onChanged?.call(null),
                child: const Text('Clear'),
              ),
          ],
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
      case 'number':
        return Align(
          alignment: Alignment.centerRight,
          child: Text(
            v == null ? '—' : formatCellNumber(v, column.unit),
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
  });

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
      'number' => widget.value == null ? '' : _number(widget.value, null),
      _ => widget.value == null ? '' : '${widget.value}',
    },
  );

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _commitText() {
    final typed = _text.text.trim();
    if (widget.column.type == 'number') {
      final n = typed.isEmpty ? null : num.tryParse(typed);
      if (typed.isNotEmpty && n == null) return;
      if (n != widget.value) widget.onChanged?.call(n);
      return;
    }
    final next = typed.isEmpty ? null : typed;
    if (next != widget.value) widget.onChanged?.call(next);
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
      case 'date':
        return _DateEditor(
          key: key,
          value: widget.value as String?,
          formats: widget.formats,
          onChanged: widget.onChanged,
        );
      default:
        return TextField(
          key: key,
          controller: _text,
          enabled: editable,
          keyboardType: c.type == 'number'
              ? const TextInputType.numberWithOptions(decimal: true)
              : TextInputType.text,
          decoration: InputDecoration(
            isDense: true,
            border: const OutlineInputBorder(),
            suffixText: c.type == 'number' ? c.unit : null,
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

/// The seven column types, in the order the + menu offers them.
const columnTypes = [
  ('text', 'Text', Icons.short_text),
  ('number', 'Number', Icons.tag),
  ('status', 'Status', Icons.label_outline),
  ('date', 'Date', Icons.event_outlined),
  ('person', 'Person', Icons.person_outline),
  ('dropdown', 'Dropdown', Icons.arrow_drop_down_circle_outlined),
  ('checkbox', 'Checkbox', Icons.check_box_outlined),
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
  });

  final ApiClient api;
  final String boardId;
  final String type;

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
  late final List<({TextEditingController label, String colour, String? id})>
  _options = [
    for (final o in widget.column?.options ?? const <ColumnOption>[])
      (
        label: TextEditingController(text: o.label),
        colour: o.colour == null ? swatches.first : ColourField.hex(o.colour!),
        id: o.id,
      ),
  ];
  bool _saving = false;
  bool _confirmDelete = false;
  String? _error;

  bool get _hasOptions => widget.type == 'status' || widget.type == 'dropdown';

  @override
  void dispose() {
    _name.dispose();
    _unit.dispose();
    for (final o in _options) {
      o.label.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _settings => {
    if (widget.type == 'number' && _unit.text.trim().isNotEmpty)
      'unit': _unit.text.trim(),
    if (_hasOptions)
      'options': [
        for (final o in _options)
          if (o.label.text.trim().isNotEmpty)
            {'id': ?o.id, 'label': o.label.text.trim(), 'colour': o.colour},
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
      setState(() {
        _saving = false;
        _error = res.reason;
      });
    }
  }

  void _save() {
    if (_name.text.trim().isEmpty) {
      setState(() => _error = 'Give the column a name.');
      return;
    }
    final id = widget.column?.id;
    final body = {
      'name': _name.text.trim(),
      // Fixed once made: the server refuses a new type on a column with
      // values, so a change never sends one.
      if (id == null) 'type': widget.type,
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
          : 'Add a ${columnTypeLabel(widget.type).toLowerCase()} column',
      subtitle: columnTypeLabel(widget.type),
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
          if (widget.type == 'number') ...[
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
              widget.type == 'status' ? 'Labels' : 'Options',
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
                      tooltip: 'Remove',
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
                  )),
                ),
                icon: const Icon(Icons.add, size: 18),
                label: Text(
                  widget.type == 'status' ? 'Add a label' : 'Add an option',
                ),
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
