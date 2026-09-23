import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
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
      _rows = [
        for (final row in (body['data'] as List? ?? const []))
          if (row is Map<String, dynamic>) _Extension.fromJson(row),
      ];
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

          for (final row in _rows) _row(row, theme),

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

  Widget _row(_Extension row, ThemeData theme) {
    // An extension whose team was deleted keeps its digits — somebody told
    // customers to dial them — so it is shown as needing a destination rather
    // than hidden, which is the only way anybody finds out.
    final orphan = row.targetName == null;

    return ListTile(
      key: Key('extension:${row.id}'),
      contentPadding: EdgeInsets.zero,
      leading: Text(row.extension, style: theme.textTheme.titleMedium),
      title: Text(
        orphan ? 'Reaches nothing' : row.targetName!,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: orphan ? theme.colorScheme.error : null,
        ),
      ),
      subtitle: Text(
        orphan
            ? 'What this pointed at has been deleted. A caller dialling it is '
                  'told it is not set up.'
            : row.kindLabel,
        style: theme.textTheme.bodySmall?.copyWith(
          color: orphan ? theme.colorScheme.error : null,
        ),
      ),
      trailing: _canEdit
          ? IconButton(
              icon: const Icon(Icons.delete_outline),
              tooltip: 'Retire this extension',
              onPressed: () => _remove(row),
            )
          : null,
    );
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
