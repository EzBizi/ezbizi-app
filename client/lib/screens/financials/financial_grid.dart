import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/theme.dart';
import 'financial_cells.dart';

typedef OpenRecord = void Function(Map? record, {Map initial});

/// One cell change: the record, the field, and the value to set (null clears).
typedef CellChange = ({Map record, String fieldId, dynamic value});

/// The spreadsheet (Jose, 2026-10-21: "like any excel sheet"). Click or
/// arrow keys select; shift extends; typing or Enter edits; Enter/Tab move;
/// Delete clears the selection; Ctrl+C / Ctrl+V copy and paste ranges as
/// tab-separated text (what Excel puts on the clipboard); Ctrl+D fills down;
/// Ctrl+Z undoes. Column edges drag to resize, remembered per person.
class FinancialGrid extends StatefulWidget {
  const FinancialGrid({
    super.key,
    required this.tableId,
    required this.fields,
    required this.allFields,
    required this.records,
    required this.config,
    required this.onOpen,
    required this.onChange,
    required this.onField,
    required this.canEdit,
    this.widths = const {},
    this.onWidths,
  });

  final String tableId;
  final List<Map> fields;
  final List<Map> allFields;
  final List<Map> records;
  final Map config;
  final OpenRecord onOpen;

  /// Saves a batch of cell changes; answers an error sentence, or null.
  final Future<String?> Function(List<CellChange> changes) onChange;
  final void Function(Map? field) onField;
  final bool canEdit;

  /// "tableId:fieldId" to pixels, this person's.
  final Map<String, dynamic> widths;
  final ValueChanged<Map<String, int>>? onWidths;

  @override
  State<FinancialGrid> createState() => _FinancialGridState();
}

typedef _Box =
    Widget Function(
      Widget child,
      double width, {
      bool header,
      bool selected,
      bool cursor,
    });

class _FinancialGridState extends State<FinancialGrid> {
  static const double _numberWidth = 56;
  static const double _rowHeight = 36;

  final _horizontal = ScrollController();
  final _vertical = ScrollController();
  final _focus = FocusNode(debugLabel: 'Financials grid');
  final _editor = TextEditingController();
  final _editorFocus = FocusNode(debugLabel: 'Financials cell editor');

  late final Map<String, int> _widths = {
    for (final e in widget.widths.entries)
      if (e.value is num) e.key: (e.value as num).toInt(),
  };
  (int, int)? _anchor; // row, column
  (int, int)? _cursor;
  bool _editing = false;
  final List<List<CellChange>> _undo = [];
  String? _error;

  @override
  void dispose() {
    _horizontal.dispose();
    _vertical.dispose();
    _focus.dispose();
    _editor.dispose();
    _editorFocus.dispose();
    super.dispose();
  }

  double _width(Map f) {
    final saved = _widths['${widget.tableId}:${f['id']}'];
    if (saved != null) return saved.toDouble();
    return f['is_primary'] == true
        ? 220
        : switch (f['type']) {
            'checkbox' || 'rating' => 110,
            'long_text' || 'multi_select' || 'attachment' => 240,
            _ => 160,
          };
  }

  /// Not typed into: formulas, files, and columns Airtable works out.
  bool _locked(Map f) =>
      !widget.canEdit ||
      f['locked'] == true ||
      const {'formula', 'attachment'}.contains(f['type']);

  // ── Selection ─────────────────────────────────────────────

  ({int top, int bottom, int left, int right})? get _range {
    final a = _anchor, c = _cursor;
    if (a == null || c == null) return null;
    return (
      top: math.min(a.$1, c.$1),
      bottom: math.max(a.$1, c.$1),
      left: math.min(a.$2, c.$2),
      right: math.max(a.$2, c.$2),
    );
  }

  bool _selected(int r, int c) {
    final s = _range;
    return s != null &&
        r >= s.top &&
        r <= s.bottom &&
        c >= s.left &&
        c <= s.right;
  }

  void _select(int r, int c, {bool extend = false}) {
    if (widget.records.isEmpty || widget.fields.isEmpty) return;
    r = r.clamp(0, widget.records.length - 1);
    c = c.clamp(0, widget.fields.length - 1);
    setState(() {
      _cursor = (r, c);
      if (!extend || _anchor == null) _anchor = (r, c);
      _editing = false;
      _error = null;
    });
    _focus.requestFocus();
    _reveal(r, c);
  }

  void _reveal(int r, int c) {
    if (_vertical.hasClients) {
      final top = r * _rowHeight;
      final view = _vertical.position.viewportDimension;
      if (top < _vertical.offset) _vertical.jumpTo(top);
      if (top + _rowHeight > _vertical.offset + view) {
        _vertical.jumpTo(top + _rowHeight - view);
      }
    }
    if (_horizontal.hasClients) {
      var left = _numberWidth;
      for (var i = 0; i < c; i++) {
        left += _width(widget.fields[i]);
      }
      final right = left + _width(widget.fields[c]);
      final view = _horizontal.position.viewportDimension;
      if (left - _numberWidth < _horizontal.offset) {
        _horizontal.jumpTo(left - _numberWidth);
      }
      if (right > _horizontal.offset + view) {
        _horizontal.jumpTo(
          math.min(right - view, _horizontal.position.maxScrollExtent),
        );
      }
    }
  }

  // ── Values ────────────────────────────────────────────────

  String _text(int r, int c) {
    final f = widget.fields[c];
    final v = (widget.records[r]['values'] as Map)[f['id']];
    if (v == null) return '';
    if (v is List) {
      return v
          .map((e) => e is Map ? (e['filename'] ?? e['url'] ?? '') : e)
          .join(', ');
    }
    if (v is bool) return v ? 'true' : '';
    return '$v';
  }

  /// What a typed or pasted string means for this column.
  dynamic _parse(Map f, String raw) {
    final t = raw.trim();
    if (t.isEmpty) return null;
    return switch (f['type']) {
      'checkbox' =>
        const {'true', 'yes', 'y', 'x', '1', '✓'}.contains(t.toLowerCase())
            ? true
            : null,
      'multi_select' => [
        for (final p in t.split(','))
          if (p.trim().isNotEmpty) p.trim(),
      ],
      'number' ||
      'currency' ||
      'percent' ||
      'rating' => t.replaceAll(RegExp(r'[\$,%\s]'), ''),
      _ => t,
    };
  }

  Future<void> _apply(List<CellChange> changes, {bool remember = true}) async {
    if (changes.isEmpty) return;
    final before = [
      for (final ch in changes)
        (
          record: ch.record,
          fieldId: ch.fieldId,
          value: (ch.record['values'] as Map)[ch.fieldId],
        ),
    ];
    final error = await widget.onChange(changes);
    if (!mounted) return;
    setState(() => _error = error);
    if (error == null && remember) {
      _undo.add(before);
      if (_undo.length > 50) _undo.removeAt(0);
    }
  }

  /// The selection's editable cells, each set from [value].
  List<CellChange> _fill(String Function(int r, int c) value) {
    final s = _range;
    if (s == null) return [];
    final out = <CellChange>[];
    for (var r = s.top; r <= s.bottom; r++) {
      for (var c = s.left; c <= s.right; c++) {
        final f = widget.fields[c];
        if (_locked(f)) continue;
        out.add((
          record: widget.records[r],
          fieldId: '${f['id']}',
          value: _parse(f, value(r, c)),
        ));
      }
    }
    return out;
  }

  Future<void> _copy() async {
    final s = _range;
    if (s == null) return;
    final lines = <String>[];
    for (var r = s.top; r <= s.bottom; r++) {
      final cells = <String>[];
      for (var c = s.left; c <= s.right; c++) {
        cells.add(_text(r, c).replaceAll('\t', ' ').replaceAll('\n', ' '));
      }
      lines.add(cells.join('\t'));
    }
    await Clipboard.setData(ClipboardData(text: lines.join('\n')));
  }

  Future<void> _paste() async {
    final s = _range;
    if (s == null || !widget.canEdit) return;
    final data = await Clipboard.getData(Clipboard.kTextPlain);
    final text = data?.text;
    if (text == null || text.isEmpty) return;
    final grid = text
        .replaceAll('\r\n', '\n')
        .replaceAll('\r', '\n')
        .split('\n')
        .map((line) => line.split('\t'))
        .toList();
    if (grid.length > 1 && grid.last.length == 1 && grid.last.first.isEmpty) {
      grid.removeLast();
    }

    // A single value fills the whole selection; a block pastes from the top-left.
    final single = grid.length == 1 && grid.first.length == 1;
    final changes = <CellChange>[];
    final rows = single ? s.bottom - s.top + 1 : grid.length;
    for (var i = 0; i < rows; i++) {
      final r = s.top + i;
      if (r >= widget.records.length) break;
      final cols = single ? s.right - s.left + 1 : grid[i].length;
      for (var j = 0; j < cols; j++) {
        final c = s.left + j;
        if (c >= widget.fields.length || _locked(widget.fields[c])) continue;
        changes.add((
          record: widget.records[r],
          fieldId: '${widget.fields[c]['id']}',
          value: _parse(widget.fields[c], single ? grid[0][0] : grid[i][j]),
        ));
      }
    }
    await _apply(changes);
  }

  Future<void> _fillDown() async {
    final s = _range;
    if (s == null || s.bottom == s.top) return;
    await _apply(_fill((r, c) => _text(s.top, c)));
  }

  Future<void> _undoLast() async {
    if (_undo.isEmpty) return;
    await _apply(_undo.removeLast(), remember: false);
  }

  void _startEdit({String? typed}) {
    final c = _cursor;
    if (c == null || _locked(widget.fields[c.$2])) return;
    final f = widget.fields[c.$2];
    if (f['type'] == 'checkbox') {
      final now = (widget.records[c.$1]['values'] as Map)[f['id']] == true;
      _apply([
        (
          record: widget.records[c.$1],
          fieldId: '${f['id']}',
          value: now ? null : true,
        ),
      ]);
      return;
    }
    setState(() {
      _editing = true;
      _editor.text = typed ?? _text(c.$1, c.$2);
      _editor.selection = TextSelection.collapsed(offset: _editor.text.length);
    });
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => _editorFocus.requestFocus(),
    );
  }

  Future<void> _commit({int dr = 0, int dc = 0}) async {
    final c = _cursor;
    if (c == null || !_editing) return;
    final f = widget.fields[c.$2];
    final changed = _editor.text != _text(c.$1, c.$2);
    setState(() => _editing = false);
    if (changed) {
      await _apply([
        (
          record: widget.records[c.$1],
          fieldId: '${f['id']}',
          value: _parse(f, _editor.text),
        ),
      ]);
    }
    if (!mounted) return;
    _select(c.$1 + dr, c.$2 + dc);
  }

  KeyEventResult _onKey(FocusNode node, KeyEvent event) {
    if (event is! KeyDownEvent && event is! KeyRepeatEvent) {
      return KeyEventResult.ignored;
    }
    if (_editing) return KeyEventResult.ignored;
    final c = _cursor;
    if (c == null) return KeyEventResult.ignored;
    final keys = HardwareKeyboard.instance;
    final shift = keys.isShiftPressed;
    final ctrl = keys.isControlPressed || keys.isMetaPressed;
    final k = event.logicalKey;

    if (ctrl) {
      final Future<void> Function()? action = switch (k) {
        LogicalKeyboardKey.keyC => _copy,
        LogicalKeyboardKey.keyV => _paste,
        LogicalKeyboardKey.keyD => _fillDown,
        LogicalKeyboardKey.keyZ => _undoLast,
        _ => null,
      };
      if (action != null) {
        action();
        return KeyEventResult.handled;
      }
      if (k == LogicalKeyboardKey.keyA) {
        setState(() {
          _anchor = (0, 0);
          _cursor = (widget.records.length - 1, widget.fields.length - 1);
        });
        return KeyEventResult.handled;
      }
      return KeyEventResult.ignored;
    }

    final move = switch (k) {
      LogicalKeyboardKey.arrowUp => (-1, 0),
      LogicalKeyboardKey.arrowDown => (1, 0),
      LogicalKeyboardKey.arrowLeft => (0, -1),
      LogicalKeyboardKey.arrowRight => (0, 1),
      LogicalKeyboardKey.tab => (0, shift ? -1 : 1),
      _ => null,
    };
    if (move != null) {
      _select(
        c.$1 + move.$1,
        c.$2 + move.$2,
        extend: shift && k != LogicalKeyboardKey.tab,
      );
      return KeyEventResult.handled;
    }
    if (k == LogicalKeyboardKey.enter || k == LogicalKeyboardKey.f2) {
      _startEdit();
      return KeyEventResult.handled;
    }
    if (k == LogicalKeyboardKey.delete || k == LogicalKeyboardKey.backspace) {
      if (widget.canEdit) _apply(_fill((r, c) => ''));
      return KeyEventResult.handled;
    }
    if (k == LogicalKeyboardKey.escape) {
      setState(() => _anchor = _cursor);
      return KeyEventResult.handled;
    }
    final ch = event.character;
    if (ch != null && ch.isNotEmpty && ch.codeUnitAt(0) >= 32) {
      _startEdit(typed: ch);
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  // ── Layout ────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final edge = EzBiziTheme.edge(context);
    final fields = widget.fields;
    final total =
        _numberWidth + fields.fold<double>(0, (w, f) => w + _width(f));

    Widget box(
      Widget child,
      double width, {
      bool header = false,
      bool selected = false,
      bool cursor = false,
    }) => Container(
      width: width,
      height: header ? 40 : _rowHeight,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      alignment: Alignment.centerLeft,
      decoration: BoxDecoration(
        border: cursor
            ? Border.all(color: theme.colorScheme.primary, width: 2)
            : Border(
                right: BorderSide(color: edge),
                bottom: BorderSide(color: edge),
              ),
        color: header
            ? theme.colorScheme.surfaceContainerHighest
            : selected
            ? theme.colorScheme.primaryContainer.withValues(alpha: 0.45)
            : null,
      ),
      child: child,
    );

    Widget header(Map f) {
      final key = '${widget.tableId}:${f['id']}';
      return Stack(
        children: [
          InkWell(
            onTap: widget.canEdit ? () => widget.onField(f) : null,
            child: box(
              Row(
                children: [
                  Icon(
                    _locked(f) && widget.canEdit
                        ? Icons.lock_outline
                        : fieldTypeIcons[f['type']],
                    size: 16,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      '${f['name']}',
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.labelLarge,
                    ),
                  ),
                ],
              ),
              _width(f),
              header: true,
            ),
          ),
          // The column's right edge: drag to resize.
          Positioned(
            right: 0,
            top: 0,
            bottom: 0,
            width: 8,
            child: MouseRegion(
              cursor: SystemMouseCursors.resizeColumn,
              child: GestureDetector(
                key: ValueKey('resize:${f['id']}'),
                behavior: HitTestBehavior.opaque,
                onHorizontalDragUpdate: (d) => setState(() {
                  _widths[key] = (_width(f) + d.delta.dx)
                      .clamp(60, 800)
                      .round();
                }),
                onHorizontalDragEnd: (_) =>
                    widget.onWidths?.call(Map.of(_widths)),
              ),
            ),
          ),
        ],
      );
    }

    Widget cellAt(int r, int c) {
      final f = fields[c];
      final values = widget.records[r]['values'] as Map;
      final isCursor = _cursor == (r, c);
      if (isCursor && _editing) {
        return box(
          TextField(
            controller: _editor,
            focusNode: _editorFocus,
            style: theme.textTheme.bodyMedium,
            decoration: const InputDecoration.collapsed(hintText: ''),
            onSubmitted: (_) => _commit(dr: 1),
            onTapOutside: (_) => _commit(),
          ),
          _width(f),
          cursor: true,
        );
      }
      final value = cellValue(f, values, widget.allFields);
      return GestureDetector(
        behavior: HitTestBehavior.opaque,
        // A click selects (no double-tap wait, so it is instant); a click
        // on the cell already selected starts editing it.
        onTap: () {
          if (_cursor == (r, c) && _anchor == (r, c)) {
            _startEdit();
          } else {
            _select(r, c, extend: HardwareKeyboard.instance.isShiftPressed);
          }
        },
        child: box(
          f['type'] == 'attachment'
              ? _Files(value: value)
              : CellView(field: f, value: value),
          _width(f),
          selected: _selected(r, c),
          cursor: isCursor,
        ),
      );
    }

    final rows = ListView.builder(
      controller: _vertical,
      itemCount: widget.records.length + 2,
      itemExtent: _rowHeight,
      itemBuilder: (context, r) {
        if (r == widget.records.length) {
          return widget.canEdit
              ? InkWell(
                  onTap: () => widget.onOpen(null),
                  child: box(
                    const Row(
                      children: [
                        Icon(Icons.add, size: 16),
                        SizedBox(width: 6),
                        Text('Add record'),
                      ],
                    ),
                    total,
                  ),
                )
              : const SizedBox.shrink();
        }
        if (r == widget.records.length + 1) return _footer(theme, box);
        return Row(
          children: [
            InkWell(
              onTap: () => widget.onOpen(widget.records[r]),
              child: box(
                Row(
                  children: [
                    Text('${r + 1}', style: theme.textTheme.bodySmall),
                    const Spacer(),
                    const Icon(Icons.open_in_full, size: 12),
                  ],
                ),
                _numberWidth,
              ),
            ),
            for (var c = 0; c < fields.length; c++) cellAt(r, c),
          ],
        );
      },
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_error != null)
          Container(
            color: theme.colorScheme.errorContainer,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            child: Text(
              _error!,
              style: TextStyle(color: theme.colorScheme.onErrorContainer),
            ),
          ),
        Expanded(
          child: Focus(
            focusNode: _focus,
            onKeyEvent: _onKey,
            child: Scrollbar(
              controller: _horizontal,
              thumbVisibility: true,
              interactive: true,
              // Only the sideways scroll drives this bar; the rows have their own.
              notificationPredicate: (n) =>
                  n.depth == 0 && n.metrics.axis == Axis.horizontal,
              child: SingleChildScrollView(
                key: const ValueKey('financials-grid-horizontal'),
                controller: _horizontal,
                scrollDirection: Axis.horizontal,
                child: SizedBox(
                  width: total + 48,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          box(
                            const SizedBox.shrink(),
                            _numberWidth,
                            header: true,
                          ),
                          for (final f in fields) header(f),
                          if (widget.canEdit)
                            IconButton(
                              tooltip: 'Add field',
                              icon: const Icon(Icons.add),
                              onPressed: () => widget.onField(null),
                            ),
                        ],
                      ),
                      Expanded(
                        child: Scrollbar(
                          controller: _vertical,
                          thumbVisibility: true,
                          interactive: true,
                          child: rows,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _footer(ThemeData theme, _Box box) {
    return Row(
      children: [
        box(
          Text('${widget.records.length}', style: theme.textTheme.labelSmall),
          _numberWidth,
        ),
        for (final f in widget.fields)
          box(
            Builder(
              builder: (_) {
                if (!(isNumeric('${f['type']}') || f['type'] == 'formula') ||
                    f['type'] == 'rating') {
                  return const SizedBox.shrink();
                }
                num sum = 0;
                var any = false;
                for (final r in widget.records) {
                  final v = cellValue(f, r['values'] as Map, widget.allFields);
                  if (v is num) {
                    sum += v;
                    any = true;
                  }
                }
                return any
                    ? Text(
                        'Sum ${formatCell(f['type'] == 'formula' ? {'type': 'formula'} : f, f['type'] == 'formula' ? sum.toDouble() : sum)}',
                        style: theme.textTheme.labelSmall,
                        textAlign: TextAlign.right,
                      )
                    : const SizedBox.shrink();
              },
            ),
            _width(f),
          ),
      ],
    );
  }
}

/// Attachment cells: a chip per file, opening it.
class _Files extends StatelessWidget {
  const _Files({required this.value});

  final dynamic value;

  @override
  Widget build(BuildContext context) {
    final files = value is List
        ? (value as List).whereType<Map>().toList()
        : const <Map>[];
    return ListView(
      scrollDirection: Axis.horizontal,
      children: [
        for (final f in files)
          Padding(
            padding: const EdgeInsets.only(right: 4),
            child: ActionChip(
              visualDensity: VisualDensity.compact,
              avatar: Icon(
                '${f['type'] ?? ''}'.startsWith('image/')
                    ? Icons.image_outlined
                    : Icons.attach_file,
                size: 14,
              ),
              label: Text(
                '${f['filename'] ?? 'File'}',
                overflow: TextOverflow.ellipsis,
              ),
              onPressed: f['url'] == null
                  ? null
                  : () => launchUrl(Uri.parse('${f['url']}')),
            ),
          ),
      ],
    );
  }
}
