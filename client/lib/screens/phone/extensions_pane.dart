import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import 'settings_pane.dart';

/// The short numbers customers dial, and what each one reaches.
///
/// ── What Jose asked for ──────────────────────────────────────────
///
/// 2026-09-17: *"On Settings i want a new tab called Extensions, so i can
/// assign extensions to users, ivr menus, ring groups"*.
///
/// ── Why this is a screen of its own and not the Users tab ────────
///
/// The Users tab answers "what is this PERSON's extension", keyed on the
/// person, which is right for a roster and cannot hold a team's. This screen's
/// subject is the DIGITS — a number somebody has published to customers — so
/// it lists every one of them, including the ones no person holds.
///
/// ── What it does not do yet ─────────────────────────────────────
///
/// Menus. `phone_extensions` has the column and the renderer refuses rather
/// than guessing, because carrying WHICH menu through the webhook round trip
/// that follows a keypress is real work and a half-built version would play
/// the right prompt and route the keypress to the line's own menu.
class ExtensionsPane extends StatefulWidget {
  const ExtensionsPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ExtensionsPane> createState() => _ExtensionsPaneState();
}

class _ExtensionsPaneState extends State<ExtensionsPane> {
  bool _loading = true;
  String? _error;
  String? _saveError;
  String? _connectionId;

  List<_Extension> _rows = const [];

  /// The tile picked: `user`, `ring_group`, or null for every extension. The
  /// directory comes back whole, so the tiles count it here (Mia, 2026-10-02).
  String? _kindShown;

  /// What is typed in the search box. /phone/extensions/directory returns
  /// every extension, so the filter runs here.
  String _query = '';
  List<_Target> _people = const [];
  List<_Target> _groups = const [];

  final _digits = TextEditingController();
  String _kind = 'user';
  String? _targetId;
  bool _saving = false;

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _digits.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/phone/extensions/directory');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });

      return;
    }

    final body = response.body;

    setState(() {
      _loading = false;
      _connectionId = body['connection_id'] as String?;
      // Sorted by the digits, because the digits are what somebody looks
      // up here: "who is 0300?" (layout A, Mia, 2026-10-02).
      _rows = [
        for (final row in (body['data'] as List? ?? const []))
          if (row is Map<String, dynamic>) _Extension.fromJson(row),
      ]..sort((a, b) => a.extension.compareTo(b.extension));
      _people = _targetsFrom(body['people']);
      _groups = _targetsFrom(body['ring_groups']);
      _targetId = null;
    });
  }

  List<_Target> _targetsFrom(Object? raw) => [
    for (final row in (raw as List? ?? const []))
      if (row is Map<String, dynamic>)
        _Target(
          id: row['id'] as String? ?? '',
          name: row['name'] as String? ?? '',
        ),
  ];

  List<_Target> get _choices => _kind == 'user' ? _people : _groups;

  Future<void> _add() async {
    final connectionId = _connectionId;
    final targetId = _targetId;
    if (connectionId == null || targetId == null || _saving) return;

    setState(() {
      _saving = true;
      _saveError = null;
    });

    final response = await widget.api.post('/phone/extensions/directory', {
      'connection_id': connectionId,
      'extension': _digits.text.trim(),
      'target_kind': _kind,
      'target_id': targetId,
    });

    if (!mounted) return;

    if (!response.ok) {
      // THE SENTENCE, not "could not save". The refusals here name who already
      // holds the digits, which is the thing somebody needs in order to pick
      // different ones.
      setState(() {
        _saving = false;
        _saveError = response.reason;
      });

      return;
    }

    _digits.clear();
    setState(() => _saving = false);
    await _load();
  }

  Future<void> _remove(_Extension row) async {
    final response = await widget.api.delete('/phone/extensions/${row.id}');
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _saveError = response.reason);

      return;
    }

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    return SettingsPane(
      title: 'Extensions',
      subtitle:
          'Short numbers a caller can dial from the menu. Every one starts '
          'with 0, which is what tells an extension apart from a menu key.',
      error: _error,
      onRetry: _load,
      trailing: _rows.isEmpty
          ? null
          : Align(
              alignment: Alignment.centerRight,
              child: _searchBox(
                key: const ValueKey('comm-extensions-search'),
                hint: 'Search extension or who',
                onChanged: (value) => setState(() => _query = value),
              ),
            ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        children: [
          if (_rows.isEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Text(
                'No extensions yet. A caller who presses 0 at the menu is '
                'asked to type one, so until there is at least one here that '
                'prompt does not appear.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),

          if (_rows.isNotEmpty) ...[
            FilterTiles(
              prefix: 'extensions',
              picked: _kindShown,
              onPick: (key) => setState(() => _kindShown = key),
              tiles: [
                FilterTile(
                  key: null,
                  label: 'Extensions',
                  count: _rows.length,
                  note: 'in use',
                ),
                FilterTile(
                  key: 'user',
                  label: 'People',
                  count: _rows.where((r) => r.kind == 'user').length,
                  note: 'ring one person',
                ),
                FilterTile(
                  key: 'ring_group',
                  label: 'Teams',
                  count: _rows.where((r) => r.kind == 'ring_group').length,
                  note: 'ring a group',
                ),
              ],
            ),
            const SizedBox(height: 12),
            _table(theme),
          ],

          if (_canEdit) ...[const Divider(height: 32), _addRow(theme)],

          if (_saveError != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(
                _saveError!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _table(ThemeData theme) {
    final shown = [
      for (final row in _rows)
        if ((_kindShown == null || row.kind == _kindShown) &&
            _searchHit(_query, [row.extension, row.targetName, row.kindLabel]))
          row,
    ];

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _query.trim().isNotEmpty
              ? 'Nothing matches "${_query.trim()}".'
              : 'No extensions of this kind.',
          textAlign: TextAlign.center,
        ),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Extension', flex: 2),
        ListColumn('Who', flex: 4),
        ListColumn('Kind', flex: 3),
        ListColumn('', flex: 2, end: true),
      ],
      rowKey: (i) => Key('extension:${shown[i].id}'),
      rows: [for (final row in shown) _cells(row, theme)],
    );
  }

  List<Widget> _cells(_Extension row, ThemeData theme) {
    // An extension whose team was deleted keeps its digits — somebody told
    // customers to dial them — so it is shown as needing a destination rather
    // than hidden, which is the only way anybody finds out.
    final orphan = row.targetName == null;
    final bad = ToneColours.of(context, Tone.bad).ink;

    return [
      Text(
        row.extension,
        style: theme.textTheme.titleMedium?.copyWith(
          fontFeatures: const [FontFeature.tabularFigures()],
        ),
      ),
      Text(
        orphan ? 'Reaches nothing' : row.targetName!,
        overflow: TextOverflow.ellipsis,
        style: theme.textTheme.bodyMedium?.copyWith(
          fontWeight: FontWeight.w600,
          color: orphan ? bad : null,
        ),
      ),
      Tooltip(
        message: orphan
            ? 'What this pointed at has been deleted. A caller dialling it is '
                  'told it is not set up.'
            : row.kindDetail,
        child: Text(
          row.kindLabel,
          style: theme.textTheme.bodySmall?.copyWith(
            color: orphan ? bad : theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ),
      _canEdit
          ? TextButton(
              onPressed: () => _remove(row),
              child: const Text('Retire'),
            )
          : const SizedBox.shrink(),
    ];
  }

  Widget _addRow(ThemeData theme) {
    return Wrap(
      spacing: 12,
      runSpacing: 12,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 120,
          child: TextField(
            key: const Key('extension-digits'),
            controller: _digits,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'Extension',
              hintText: '0101',
              helperText: 'Starts with 0',
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
        ),
        // SearchPickerField, not a DropdownButtonFormField: there is a test —
        // `no_popup_dropdown_test` — that forbids the latter anywhere in the
        // app, and it caught both of these. It also solves the overflow I had
        // just widened two SizedBoxes to avoid.
        SizedBox(
          width: 200,
          child: SearchPickerField<String>(
            key: const Key('extension-kind'),
            value: _kind,
            label: 'Reaches',
            border: const OutlineInputBorder(),
            isDense: true,
            options: const [
              SearchOption<String>(value: 'user', label: 'A person'),
              SearchOption<String>(value: 'ring_group', label: 'A team'),
            ],
            onChanged: (value) => setState(() {
              _kind = value ?? 'user';

              // Cleared, because a person's id is not a team's. Keeping it
              // would offer an Add that fails on a target the list no longer
              // shows.
              _targetId = null;
            }),
          ),
        ),
        SizedBox(
          width: 260,
          child: SearchPickerField<String>(
            key: const Key('extension-target'),
            value: _targetId,
            label: _kind == 'user' ? 'Person' : 'Team',
            border: const OutlineInputBorder(),
            isDense: true,
            options: [
              for (final choice in _choices)
                SearchOption<String>(value: choice.id, label: choice.name),
            ],
            onChanged: (value) => setState(() => _targetId = value),
          ),
        ),
        FilledButton(
          key: const Key('extension-add'),
          onPressed: _targetId == null || _digits.text.trim().isEmpty || _saving
              ? null
              : _add,
          child: Text(_saving ? 'Adding…' : 'Add'),
        ),
      ],
    );
  }
}

class _Extension {
  const _Extension({
    required this.id,
    required this.extension,
    required this.kind,
    required this.targetName,
  });

  final String id;
  final String extension;
  final String? kind;
  final String? targetName;

  String get kindLabel => switch (kind) {
    'user' => 'A person',
    'ring_group' => 'A team',
    _ => 'Not set up',
  };

  String get kindDetail => switch (kind) {
    'user' => 'Rings this person on every device they have signed in.',
    'ring_group' => 'Rings this team.',
    _ => 'Reaches nothing.',
  };

  factory _Extension.fromJson(Map<String, dynamic> json) => _Extension(
    id: json['id'] as String? ?? '',
    extension: json['extension'] as String? ?? '',
    kind: json['target_kind'] as String?,
    targetName: json['target_name'] as String?,
  );
}

class _Target {
  const _Target({required this.id, required this.name});

  final String id;
  final String name;
}

/// The list's search box, sized to sit in the pane's header beside its
/// buttons: 40 high, the height of the controls around it.
Widget _searchBox({
  required Key key,
  required String hint,
  required ValueChanged<String> onChanged,
}) => SizedBox(
  width: 260,
  height: 40,
  child: TextField(
    key: key,
    onChanged: onChanged,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      isDense: true,
      hintText: hint,
      prefixIcon: const Icon(Icons.search, size: 18),
      prefixIconConstraints: const BoxConstraints(minWidth: 36),
      contentPadding: const EdgeInsets.symmetric(horizontal: 8),
      border: const OutlineInputBorder(),
    ),
  ),
);

/// Whether any of [fields] holds [query], ignoring case. A query of digits
/// and phone punctuation also matches a number however it is stored, so
/// "(203) 555" finds +12035550199.
bool _searchHit(String query, Iterable<String?> fields) {
  final q = query.trim().toLowerCase();
  if (q.isEmpty) return true;
  final digits = q.replaceAll(RegExp(r'\D'), '');
  final phoneish = digits.length >= 3 && RegExp(r'^[\d\s()+.-]+$').hasMatch(q);
  for (final field in fields) {
    if (field == null || field.isEmpty) continue;
    final value = field.toLowerCase();
    if (value.contains(q)) return true;
    if (phoneish && value.replaceAll(RegExp(r'\D'), '').contains(digits)) {
      return true;
    }
  }
  return false;
}
