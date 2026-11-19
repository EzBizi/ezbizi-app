import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/empty_state.dart';

/// Settings › Lists (design 181, approved 2026-11-04): a business's own lists,
/// edited a row at a time.
///
/// Every write is one call for one row — a cell saves on blur, a checkbox at
/// once, ✕ removes with Undo — so this screen and the ezbizi MCP make the same
/// changes the same way. Opens in place on a phone (containment: no route).
class ListsScreen extends StatefulWidget {
  const ListsScreen({super.key, required this.api});

  final ApiClient api;

  @override
  State<ListsScreen> createState() => _ListsScreenState();
}

class _ListsScreenState extends State<ListsScreen> {
  List<Map<String, dynamic>> _lists = const [];
  bool _loading = true;
  String? _error;
  String? _open;
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _search.addListener(() => setState(() {}));
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/settings/lists');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = r.ok ? null : r.reason;
      if (r.ok) {
        _lists = ((r.body['data'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .toList();
      }
    });
  }

  Map<String, dynamic>? get _current {
    for (final l in _lists) {
      if (l['key'] == _open) return l;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return Center(child: Text(_error!));
    if (_lists.isEmpty) {
      return const EmptyState(
        icon: Icons.list_alt_outlined,
        title: 'No lists yet',
        message: 'Lists appear here as each module makes its values editable.',
      );
    }

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 900;
        final open = _current ?? (phone ? null : _lists.first);

        final pane = open == null
            ? null
            : _ListPane(
                key: ValueKey('list:${open['key']}'),
                api: widget.api,
                list: open,
                onBack: phone ? () => setState(() => _open = null) : null,
                onChanged: _load,
              );

        if (phone) return pane ?? _index(context, phone: true);

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(width: 250, child: _index(context, phone: false)),
            const VerticalDivider(width: 1),
            Expanded(child: pane!),
          ],
        );
      },
    );
  }

  Widget _index(BuildContext context, {required bool phone}) {
    final q = _search.text.trim().toLowerCase();
    final shown = _lists
        .where((l) => q.isEmpty || '${l['label']}'.toLowerCase().contains(q))
        .toList();
    final byModule = <String, List<Map<String, dynamic>>>{};
    for (final l in shown) {
      byModule.putIfAbsent('${l['module']}', () => []).add(l);
    }
    final selected = _current?['key'] ?? (phone ? null : _lists.first['key']);
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        TextField(
          key: const ValueKey('lists-search'),
          controller: _search,
          decoration: const InputDecoration(
            isDense: true,
            hintText: 'Search lists',
            prefixIcon: Icon(Icons.search, size: 20),
          ),
        ),
        const SizedBox(height: 8),
        for (final MapEntry(:key, :value) in byModule.entries) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 12, 8, 4),
            child: Text(
              _moduleWord(key),
              style: theme.textTheme.labelMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          for (final l in value)
            ListTile(
              key: ValueKey('list-entry:${l['key']}'),
              dense: true,
              selected: l['key'] == selected,
              title: Text('${l['label']}'),
              trailing: Text('${(l['items'] as List?)?.length ?? 0}'),
              onTap: () => setState(() => _open = l['key'] as String),
            ),
        ],
      ],
    );
  }

  String _moduleWord(String module) => module.isEmpty
      ? ''
      : module[0].toUpperCase() + module.substring(1).replaceAll('_', ' ');
}

class _ListPane extends StatefulWidget {
  const _ListPane({
    super.key,
    required this.api,
    required this.list,
    required this.onChanged,
    this.onBack,
  });

  final ApiClient api;
  final Map<String, dynamic> list;
  final Future<void> Function() onChanged;
  final VoidCallback? onBack;

  @override
  State<_ListPane> createState() => _ListPaneState();
}

class _ListPaneState extends State<_ListPane> {
  late List<Map<String, dynamic>> _rows;

  /// Errors by row key ('' for the row being added), under that row.
  final Map<String, String> _errors = {};
  Map<String, dynamic>? _adding;

  String get _path => '/settings/lists/${widget.list['key']}';

  List<Map<String, dynamic>> get _columns =>
      ((widget.list['columns'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();

  bool get _mayEdit => widget.list['may_edit'] == true;

  @override
  void initState() {
    super.initState();
    _rows = ((widget.list['items'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => {
            ...r,
            'value': Map<String, dynamic>.from(r['value'] as Map? ?? {}),
          },
        )
        .toList();
  }

  String _item(String key) => '$_path/items/${Uri.encodeComponent(key)}';

  Future<void> _patch(String key, Map<String, dynamic> body) async {
    final r = await widget.api.patch(_item(key), body);
    if (!mounted) return;
    setState(() => r.ok ? _errors.remove(key) : _errors[key] = r.reason);
    if (r.ok) widget.onChanged();
  }

  Future<void> _remove(Map<String, dynamic> row) async {
    final key = row['key'] as String;
    final r = await widget.api.delete(_item(key));
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _errors[key] = r.reason);
      return;
    }
    setState(() => _rows.removeWhere((x) => x['key'] == key));
    widget.onChanged();
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      SnackBar(
        content: Text('Removed ${_rowTitle(row)}'),
        // An action makes a SnackBar persist by default (Flutter 3.35+); ours time out.
        persist: false,
        action: SnackBarAction(
          label: 'Undo',
          onPressed: () async {
            final back = await widget.api.post('$_path/items', {
              'key': key,
              'value': row['value'],
              'sort': row['sort'],
            });
            if (back.ok && mounted) {
              setState(() => _rows.insert(0, row));
              widget.onChanged();
            }
          },
        ),
      ),
    );
  }

  Future<void> _addRow() async {
    final value = _adding!;
    final r = await widget.api.post('$_path/items', {'value': value});
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _errors[''] = r.reason);
      return;
    }
    setState(() {
      _errors.remove('');
      _rows.add(Map<String, dynamic>.from(r.body['data'] as Map));
      _adding = null;
    });
    widget.onChanged();
  }

  Future<void> _reorder(int from, int to) async {
    if (to > from) to--;
    setState(() => _rows.insert(to, _rows.removeAt(from)));
    for (var i = 0; i < _rows.length; i++) {
      if (_rows[i]['sort'] != i) {
        _rows[i]['sort'] = i;
        await _patch(_rows[i]['key'] as String, {'sort': i});
      }
    }
  }

  Future<void> _reset() async {
    final r = await widget.api.post('$_path/reset', {});
    if (r.ok) widget.onChanged();
  }

  String _rowTitle(Map<String, dynamic> row) {
    final first = _columns.isEmpty ? null : _columns.first['key'];
    final v = (row['value'] as Map?)?[first];
    return v == null || '$v'.isEmpty ? '${row['key']}' : '$v';
  }

  String get _singular {
    final label = '${widget.list['label']}';
    final s = label.endsWith('s')
        ? label.substring(0, label.length - 1)
        : label;
    return s.toLowerCase();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final edited = widget.list['edited'] == true;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          children: [
            if (widget.onBack != null)
              IconButton(
                key: const ValueKey('lists-back'),
                tooltip: 'Back',
                icon: const Icon(Icons.chevron_left),
                onPressed: widget.onBack,
              ),
            Flexible(
              child: Text(
                '${widget.list['label']}',
                style: theme.textTheme.titleLarge,
              ),
            ),
            const SizedBox(width: 8),
            if (!_mayEdit)
              const Chip(label: Text('Read only'))
            else if (!edited)
              const Chip(
                key: ValueKey('lists-defaults'),
                label: Text('Default values'),
              )
            else
              ..._resetControls(context),
          ],
        ),
        if ((widget.list['help'] as String?)?.isNotEmpty ?? false)
          Padding(
            padding: const EdgeInsets.only(top: 4, bottom: 8),
            child: Text(
              widget.list['help'] as String,
              style: theme.textTheme.bodySmall,
            ),
          ),
        Card(
          child: ReorderableListView(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            buildDefaultDragHandles: false,
            onReorder: _mayEdit ? _reorder : (_, _) {},
            children: [
              for (var i = 0; i < _rows.length; i++)
                _row(
                  context,
                  i,
                  _rows[i],
                  key: ValueKey('row:${_rows[i]['key']}'),
                ),
            ],
          ),
        ),
        if (_mayEdit) ...[
          if (_adding != null) _addingRow(context),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('lists-add'),
              onPressed: _adding != null
                  ? null
                  : () => setState(() => _adding = {}),
              icon: const Icon(Icons.add, size: 18),
              label: Text('Add a $_singular'),
            ),
          ),
        ],
      ],
    );
  }

  Widget _row(
    BuildContext context,
    int index,
    Map<String, dynamic> row, {
    required Key key,
  }) {
    final rowKey = row['key'] as String;
    final error = _errors[rowKey];

    return Column(
      key: key,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          child: Row(
            children: [
              if (_mayEdit)
                ReorderableDragStartListener(
                  index: index,
                  child: const Padding(
                    padding: EdgeInsets.all(4),
                    child: Icon(Icons.drag_indicator, size: 18),
                  ),
                ),
              ..._cells([
                for (final c in _columns)
                  _cell(
                    c,
                    (row['value'] as Map)[c['key']],
                    onSave: (v) {
                      (row['value'] as Map)[c['key']] = v;
                      _patch(rowKey, {
                        'value': {c['key']: v},
                      });
                    },
                    fieldKey: 'cell:$rowKey:${c['key']}',
                  ),
              ]),
              if (_mayEdit)
                IconButton(
                  key: ValueKey('remove:$rowKey'),
                  tooltip: 'Remove',
                  icon: Icon(
                    Icons.close,
                    size: 18,
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                  onPressed: () => _remove(row),
                ),
            ],
          ),
        ),
        if (error != null) _errorLine(context, error),
        const Divider(height: 1),
      ],
    );
  }

  Widget _addingRow(BuildContext context) {
    final error = _errors[''];
    final firstText = _columns.firstWhere(
      (c) => (c['type'] ?? 'text') == 'text',
      orElse: () => _columns.first,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            ..._cells([
              for (final c in _columns)
                _cell(
                  c,
                  _adding![c['key']],
                  autofocus: c == _columns.first,
                  fieldKey: 'add:${c['key']}',
                  onSave: (v) {
                    _adding![c['key']] = v;
                    final filled = '${_adding![firstText['key']] ?? ''}'
                        .trim()
                        .isNotEmpty;
                    if (filled) _addRow();
                  },
                ),
            ]),
          ],
        ),
        if (error != null) _errorLine(context, error),
      ],
    );
  }

  /// Side by side, or stacked as "Label value" lines on a phone once a list
  /// has 3 or more columns, so a wide list never overflows (UI/UX on 043f3e31).
  List<Widget> _cells(List<Widget> cells) {
    final stacked = widget.onBack != null && _columns.length >= 3;

    return stacked
        ? [
            Expanded(
              child: Column(
                key: const ValueKey('cells-stacked'),
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: cells,
              ),
            ),
          ]
        : [for (final c in cells) Expanded(child: c)];
  }

  /// Restore defaults is destructive and has no Undo, so it asks inline: the
  /// first tap turns it into a red question with Confirm and Cancel, which
  /// lapses after 5 seconds. No dialog.
  bool _confirmingReset = false;
  Timer? _resetLapse;

  List<Widget> _resetControls(BuildContext context) {
    final error = Theme.of(context).colorScheme.error;

    if (!_confirmingReset) {
      return [
        TextButton(
          key: const ValueKey('lists-reset'),
          style: TextButton.styleFrom(foregroundColor: error),
          onPressed: () {
            setState(() => _confirmingReset = true);
            _resetLapse = Timer(const Duration(seconds: 5), () {
              if (mounted) setState(() => _confirmingReset = false);
            });
          },
          child: const Text('Restore defaults'),
        ),
      ];
    }

    return [
      Flexible(
        child: Text(
          'Replace all ${_rows.length} rows with the defaults?',
          style: TextStyle(color: error),
        ),
      ),
      TextButton(
        key: const ValueKey('lists-reset-confirm'),
        style: TextButton.styleFrom(foregroundColor: error),
        onPressed: () {
          _resetLapse?.cancel();
          setState(() => _confirmingReset = false);
          _reset();
        },
        child: const Text('Confirm'),
      ),
      TextButton(
        key: const ValueKey('lists-reset-cancel'),
        onPressed: () {
          _resetLapse?.cancel();
          setState(() => _confirmingReset = false);
        },
        child: const Text('Cancel'),
      ),
    ];
  }

  @override
  void dispose() {
    _resetLapse?.cancel();
    super.dispose();
  }

  Widget _errorLine(BuildContext context, String error) => Padding(
    padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
    child: Text(
      error,
      style: TextStyle(
        color: Theme.of(context).colorScheme.error,
        fontSize: 12,
      ),
    ),
  );

  Widget _cell(
    Map<String, dynamic> column,
    Object? value, {
    required ValueChanged<Object?> onSave,
    required String fieldKey,
    bool autofocus = false,
  }) {
    final type = column['type'] ?? 'text';

    if (type == 'bool') {
      return CheckboxListTile(
        key: ValueKey(fieldKey),
        dense: true,
        contentPadding: EdgeInsets.zero,
        title: Text('${column['label']}'),
        value: value == true,
        onChanged: _mayEdit ? (v) => onSave(v == true) : null,
      );
    }

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: _BlurField(
        key: ValueKey(fieldKey),
        label: '${column['label']}',
        initial: value == null ? '' : '$value',
        number: type == 'number',
        enabled: _mayEdit,
        autofocus: autofocus,
        onSave: (text) =>
            onSave(type == 'number' ? num.tryParse(text) ?? text : text),
      ),
    );
  }
}

/// A field that saves when it loses focus, and only when the text changed.
class _BlurField extends StatefulWidget {
  const _BlurField({
    super.key,
    required this.label,
    required this.initial,
    required this.onSave,
    required this.enabled,
    this.number = false,
    this.autofocus = false,
  });

  final String label;
  final String initial;
  final ValueChanged<String> onSave;
  final bool enabled;
  final bool number;
  final bool autofocus;

  @override
  State<_BlurField> createState() => _BlurFieldState();
}

class _BlurFieldState extends State<_BlurField> {
  late final _controller = TextEditingController(text: widget.initial);
  final _focus = FocusNode();
  late String _saved = widget.initial;

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus && _controller.text != _saved) {
        _saved = _controller.text;
        widget.onSave(_controller.text);
      }
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    _focus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
    controller: _controller,
    focusNode: _focus,
    enabled: widget.enabled,
    autofocus: widget.autofocus,
    keyboardType: widget.number ? TextInputType.number : TextInputType.text,
    decoration: InputDecoration(isDense: true, labelText: widget.label),
    onSubmitted: (_) => _focus.unfocus(),
  );
}
