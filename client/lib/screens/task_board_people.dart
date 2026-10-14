import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../widgets/error_note.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';

/// Task Board › People and New board (Jose, 2026-10-13: "the owner of a board
/// can assign other people to the board, read up on how monday works"; UI
/// queue 89, desktop 1 and phone 4).
///
/// monday.com's model: every board has an owner, who adds people as Owner,
/// Member or Viewer; a board is Open (its company or branch finds and joins
/// it) or Private (only its people see it). The server says "main" for Open.

typedef _Json = Map<String, dynamic>;

const boardRoles = <(String, String, String)>[
  ('owner', 'Owner', 'changes the board and who is on it'),
  ('member', 'Member', 'works the tasks'),
  ('viewer', 'Viewer', 'reads only'),
];

String boardRoleLabel(Object? role) =>
    boardRoles.where((r) => r.$1 == role).firstOrNull?.$2 ?? 'Member';

/// "Open" or "Private", as the header and the picker say it.
String boardVisibilityLabel(Object? visibility) =>
    visibility == 'private' ? 'Private' : 'Open';

class BoardPeoplePanel extends StatefulWidget {
  const BoardPeoplePanel({super.key, required this.api, required this.board});

  final ApiClient api;
  final Map<String, dynamic> board;

  @override
  State<BoardPeoplePanel> createState() => _BoardPeoplePanelState();
}

class _BoardPeoplePanelState extends State<BoardPeoplePanel> {
  bool _loading = true;
  String? _error;
  List<_Json> _members = const [];
  List<_Json> _candidates = const [];
  late String _visibility = '${widget.board['visibility'] ?? 'main'}';

  String get _path => '/task-boards/${widget.board['id']}/members';

  /// Its owners and Administrators (the server says which: the client never
  /// knows which role is the administrator one).
  bool get _owner =>
      widget.board['can_manage'] as bool? ?? widget.board['my_role'] == 'owner';

  @override
  void initState() {
    super.initState();
    _load();
  }

  static String _name(_Json m) => '${m['name'] ?? 'Someone'}';

  static List<_Json> _rows(Object? list) => [
    for (final m in (list as List? ?? const []))
      if (m is Map) Map<String, dynamic>.from(m),
  ];

  Future<void> _load() async {
    final res = await widget.api.get(_path);
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = res.ok ? null : res.message;
      if (res.ok) {
        final data = res.body['data'] as Map? ?? const {};
        _members = _rows(data['members']);
        // Everyone who works where the board is and is not on it yet.
        _candidates = _rows(data['candidates']);
      }
    });
  }

  Future<void> _write(Future<ApiResponse> call) async {
    final res = await call;
    if (!mounted) return;
    if (!res.ok) {
      setState(() => _error = res.message);
      return;
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return SidePanel(
      title: 'People · ${widget.board['name']}',
      subtitle: _owner
          ? 'Each change saves as you make it.'
          : 'Only the board\'s owner changes who is on it.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 12),
          ],
          // Jose, 2026-10-14: people on every board. On a personal board
          // that is sharing it (Task Board's members on every kind).
          if (widget.board['kind'] == 'user' && _owner) ...[
            Container(
              key: const Key('board-people-shares'),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: theme.colorScheme.primaryContainer,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                'Adding someone shares your board with them: they see it in '
                'Shared with me and can work its tasks.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onPrimaryContainer,
                ),
              ),
            ),
            const SizedBox(height: 12),
          ],
          if (_owner) ...[
            SearchPickerField<String>(
              key: const Key('board-people-add'),
              value: null,
              label: 'Add people',
              hint: 'Type a name',
              options: [
                for (final p in _candidates)
                  SearchOption<String>(
                    value: p['id'] as String,
                    label: '${p['name']}',
                    detail: p['role_name'] as String?,
                  ),
              ],
              onChanged: (id) {
                if (id != null) {
                  _write(
                    widget.api.post(_path, {'user_id': id, 'role': 'member'}),
                  );
                }
              },
            ),
            const SizedBox(height: 12),
          ],
          if (_loading)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            )
          else
            for (final m in _members)
              Padding(
                key: ValueKey('board-member-${m['id']}'),
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: [
                    CircleAvatar(
                      radius: 14,
                      child: Text(
                        _name(m).trim().isEmpty
                            ? '?'
                            : _name(m).trim()[0].toUpperCase(),
                        style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(_name(m), style: theme.textTheme.bodyMedium),
                    ),
                    if (_owner) ...[
                      SizedBox(
                        width: 130,
                        child: SearchPickerField<String>(
                          key: ValueKey('board-member-role-${m['id']}'),
                          value: '${m['role'] ?? 'member'}',
                          isDense: true,
                          searchFrom: 99,
                          options: [
                            for (final (key, label, about) in boardRoles)
                              SearchOption<String>(
                                value: key,
                                label: label,
                                detail: about,
                              ),
                          ],
                          onChanged: (role) {
                            if (role != null && role != m['role']) {
                              _write(
                                widget.api.patch('$_path/${m['id']}', {
                                  'role': role,
                                }),
                              );
                            }
                          },
                        ),
                      ),
                      IconButton(
                        key: ValueKey('board-member-remove-${m['id']}'),
                        tooltip: 'Take ${_name(m)} off this board',
                        icon: const Icon(Icons.close, size: 18),
                        onPressed: () =>
                            _write(widget.api.delete('$_path/${m['id']}')),
                      ),
                    ] else
                      Text(boardRoleLabel(m['role']), style: muted),
                  ],
                ),
              ),
          // Open or Private is a board you made; the others are found by
          // their place, or are personal.
          if (widget.board['kind'] == 'custom') ...[
            const SizedBox(height: 16),
            Text('Who can find it', style: theme.textTheme.labelLarge),
            const SizedBox(height: 6),
            SegmentedButton<String>(
              key: const Key('board-visibility'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(
                  value: 'main',
                  icon: Icon(Icons.public),
                  label: Text('Open'),
                ),
                ButtonSegment(
                  value: 'private',
                  icon: Icon(Icons.lock_outline),
                  label: Text('Private'),
                ),
              ],
              selected: {_visibility},
              onSelectionChanged: _owner
                  ? (v) {
                      setState(() => _visibility = v.first);
                      _write(
                        widget.api.patch('/task-boards/${widget.board['id']}', {
                          'visibility': v.first,
                        }),
                      );
                    }
                  : null,
            ),
            const SizedBox(height: 6),
            Text(
              _visibility == 'private'
                  ? 'Only the people above see this board.'
                  : 'Everyone where this board belongs can find it and join.',
              style: muted,
            ),
          ],
          const SizedBox(height: 12),
          Text(
            'Owner ${boardRoles[0].$3} · Member ${boardRoles[1].$3} · '
            'Viewer ${boardRoles[2].$3}. Administrators see every board.',
            style: muted,
          ),
        ],
      ),
    );
  }
}

/// A new board: its name, and Open or Private. It belongs where the board you
/// are on belongs, as a new group does.
class NewBoardPanel extends StatefulWidget {
  const NewBoardPanel({
    super.key,
    required this.api,
    this.companyId,
    this.franchiseId,
  });

  final ApiClient api;
  final String? companyId;
  final String? franchiseId;

  @override
  State<NewBoardPanel> createState() => _NewBoardPanelState();
}

class _NewBoardPanelState extends State<NewBoardPanel> {
  final _name = TextEditingController();
  String _visibility = 'private';
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _create() async {
    if (_name.text.trim().isEmpty) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    final res = await widget.api.post('/task-boards', {
      'name': _name.text.trim(),
      'visibility': _visibility,
      if (widget.companyId != null) 'company_id': widget.companyId,
      if (widget.franchiseId != null) 'franchise_id': widget.franchiseId,
    });
    if (!mounted) return;
    if (!res.ok) {
      setState(() {
        _saving = false;
        _error = res.message;
      });
      return;
    }
    Navigator.of(context).pop((res.body['data'] as Map?)?['id'] as String?);
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'New board',
      subtitle: 'You are its owner, and add the people on it next.',
      actions: [
        FilledButton(
          key: const Key('new-board-create'),
          onPressed: _saving ? null : _create,
          child: Text(_saving ? 'Creating…' : 'Create board'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            ErrorNote(_error!),
            const SizedBox(height: 12),
          ],
          TextField(
            key: const Key('new-board-name'),
            controller: _name,
            autofocus: true,
            maxLength: 120,
            decoration: const InputDecoration(
              labelText: 'Name',
              hintText: 'e.g. Spring cleaning push',
            ),
            onSubmitted: (_) => _create(),
          ),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            key: const Key('new-board-visibility'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(
                value: 'main',
                icon: Icon(Icons.public),
                label: Text('Open'),
              ),
              ButtonSegment(
                value: 'private',
                icon: Icon(Icons.lock_outline),
                label: Text('Private'),
              ),
            ],
            selected: {_visibility},
            onSelectionChanged: (v) => setState(() => _visibility = v.first),
          ),
        ],
      ),
    );
  }
}
