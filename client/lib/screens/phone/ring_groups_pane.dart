import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';

/// Phone › Settings › Ring groups — who a line rings, and what happens when
/// nobody picks up.
///
/// ── Why the lines are edited here and not on a Numbers screen ────
///
/// A ring group that no line points at rings nobody, and until this pane
/// existed `phone_numbers.ring_group_id` could never be set at all: the
/// renderer read it, nothing wrote it, and every inbound call on a fully
/// configured account fell through to voicemail with nothing on screen to
/// explain why.
///
/// Making "which lines does this group answer" part of the group — rather than
/// a field buried on each number — means the two halves of "the phone rings"
/// are one screen and one save. A separate Numbers screen can still set it
/// later; this is where somebody setting up a phone system is already standing.
class RingGroupsPane extends StatefulWidget {
  const RingGroupsPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<RingGroupsPane> createState() => _RingGroupsPaneState();
}

class _RingGroupsPaneState extends State<RingGroupsPane> {
  bool _loading = true;
  String? _error;

  List<_Group> _groups = const [];
  List<_Person> _people = const [];
  List<_Line> _lines = const [];
  List<_Conn> _connections = const [];

  /// Null when the form is shut. Empty id means "new".
  _Draft? _draft;
  bool _saving = false;
  String? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal => _me.refusal('phone.edit', 'edit ring groups');

  Future<void> _load() async {
    setState(() {
      _loading = _groups.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/ring-groups'),
      widget.api.get('/phone/members'),
      widget.api.get('/phone/numbers'),
      widget.api.get('/phone/connections'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;

      final failed = results.firstWhere((r) => !r.ok, orElse: () => results.first);
      if (!failed.ok) {
        _error = failed.message;
        return;
      }

      _groups = _listOf(results[0]).map(_Group.fromJson).toList(growable: false);

      final members = results[1].body['data'];
      _people = members is Map<String, dynamic> && members['people'] is List
          ? (members['people'] as List)
                .whereType<Map<String, dynamic>>()
                .map(_Person.fromJson)
                .toList(growable: false)
          : const [];

      _lines = _listOf(results[2]).map(_Line.fromJson).toList(growable: false);
      _connections = _listOf(results[3]).map(_Conn.fromJson).toList(growable: false);
    });
  }

  List<Map<String, dynamic>> _listOf(ApiResponse response) {
    final data = response.body['data'];
    return data is List
        ? data.whereType<Map<String, dynamic>>().toList(growable: false)
        : const [];
  }

  void _newGroup() {
    final connectionId = _connections.isNotEmpty ? _connections.first.id : null;

    setState(() {
      _formError = null;
      _draft = _Draft(
        id: '',
        connectionId: connectionId,
        name: '',
        mode: 'simultaneous',
        timeoutSeconds: 30,
        memberIds: <String>{},
        overflow: 'voicemail',
        overflowNumber: '',
        overflowGroupId: null,
        lineIds: <String>{},
      );
    });
  }

  void _editGroup(_Group group) {
    setState(() {
      _formError = null;
      _draft = _Draft(
        id: group.id,
        connectionId: group.connectionId,
        name: group.name,
        mode: group.mode,
        timeoutSeconds: group.timeoutSeconds,
        memberIds: group.memberUserIds.toSet(),
        overflow: group.overflow,
        overflowNumber: group.overflowNumber ?? '',
        overflowGroupId: group.overflowGroupId,
        lineIds: _lines
            .where((line) => line.ringGroupId == group.id)
            .map((line) => line.id)
            .toSet(),
      );
    });
  }

  Future<void> _save() async {
    final draft = _draft;
    if (draft == null || !_canEdit || _saving) return;

    if (draft.name.trim().isEmpty) {
      setState(() => _formError = 'Give the group a name.');
      return;
    }
    if (draft.connectionId == null) {
      setState(() => _formError = 'Connect a phone account for this place first.');
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final body = <String, dynamic>{
      'name': draft.name.trim(),
      'mode': draft.mode,
      'timeout_seconds': draft.timeoutSeconds,
      'member_user_ids': draft.memberIds.toList()..sort(),
      'member_role_ids': <String>[],
      'overflow': draft.overflow,
      'overflow_number': draft.overflow == 'forward' ? draft.overflowNumber.trim() : null,
      'overflow_group_id': draft.overflow == 'group' ? draft.overflowGroupId : null,
    };

    final response = draft.id.isEmpty
        ? await widget.api.post('/phone/ring-groups', {
            ...body,
            'connection_id': draft.connectionId,
          })
        : await widget.api.patch('/phone/ring-groups/${draft.id}', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.message;
      });
      return;
    }

    final saved = response.body['data'];
    final groupId = saved is Map<String, dynamic> ? saved['id'] as String? : null;

    // The lines are a second save on purpose: they are a different resource,
    // and a group that saved but whose lines did not must still exist rather
    // than roll back to nothing.
    final lineFailure = await _saveLines(draft, groupId);

    if (!mounted) return;

    setState(() {
      _saving = false;
      if (lineFailure != null) _formError = lineFailure;
    });

    if (lineFailure == null) {
      setState(() => _draft = null);
    }

    await _load();
  }

  /// PATCH every line whose group changed. Untouched lines are left alone.
  Future<String?> _saveLines(_Draft draft, String? groupId) async {
    if (groupId == null) return null;

    for (final line in _lines) {
      final wants = draft.lineIds.contains(line.id);
      final has = line.ringGroupId == groupId;

      if (wants == has) continue;

      final response = await widget.api.patch('/phone/numbers/${line.id}', {
        'ring_group_id': wants ? groupId : null,
      });

      if (!response.ok) {
        return response.message;
      }
    }

    return null;
  }

  Future<void> _delete(_Group group) async {
    if (!_canEdit || _saving) return;

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.delete('/phone/ring-groups/${group.id}');

    if (!mounted) return;

    setState(() {
      _saving = false;
      if (!response.ok) _formError = response.message;
    });

    if (response.ok) {
      setState(() => _draft = null);
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: ErrorNote(_error!),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Ring groups', style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        Text(
          'A group is who a line rings. Someone only rings if they are signed '
          'in with Phone open and not on Do not disturb.',
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 16),

        if (_connections.isEmpty)
          Text(
            'Connect a phone account first. A group belongs to one.',
            style: theme.textTheme.bodyMedium,
          )
        else ...[
          for (final group in _groups) _groupCard(group, theme),
          if (_groups.isEmpty && _draft == null)
            Text(
              'No groups yet. Without one, a call goes straight to voicemail.',
              style: theme.textTheme.bodyMedium,
            ),
          const SizedBox(height: 12),
          if (_draft != null) _form(theme) else _addButton(),
        ],
      ],
    );
  }

  Widget _addButton() => Refused(
    reason: _canEdit ? null : _editRefusal,
    child: FilledButton.icon(
      onPressed: _canEdit && !_saving ? _newGroup : null,
      icon: const Icon(Icons.add),
      label: const Text('Add a ring group'),
    ),
  );

  Widget _groupCard(_Group group, ThemeData theme) {
    final answering = _lines.where((l) => l.ringGroupId == group.id).toList();
    final editing = _draft?.id == group.id;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: ListTile(
        title: Text(group.name),
        subtitle: Text(
          [
            '${group.memberUserIds.length} '
                '${group.memberUserIds.length == 1 ? "person" : "people"}',
            group.mode == 'sequential' ? 'one at a time' : 'all at once',
            answering.isEmpty
                ? 'answers no line yet'
                : 'answers ${answering.map((l) => l.e164).join(", ")}',
          ].join(' · '),
        ),
        trailing: editing
            ? null
            : Refused(
                reason: _canEdit ? null : _editRefusal,
                child: TextButton(
                  onPressed: _canEdit && !_saving ? () => _editGroup(group) : null,
                  child: const Text('Edit'),
                ),
              ),
      ),
    );
  }

  Widget _form(ThemeData theme) {
    final draft = _draft!;
    final sameConnection = _lines
        .where((line) => line.connectionId == draft.connectionId)
        .toList();
    final otherGroups = _groups
        .where((g) => g.id != draft.id && g.connectionId == draft.connectionId)
        .toList();

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              draft.id.isEmpty ? 'New ring group' : 'Edit ring group',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 12),

            TextFormField(
              initialValue: draft.name,
              decoration: const InputDecoration(labelText: 'Name'),
              enabled: !_saving,
              onChanged: (value) => draft.name = value,
            ),
            const SizedBox(height: 12),

            if (_connections.length > 1) ...[
              DropdownButtonFormField<String>(
                initialValue: draft.connectionId,
                decoration: const InputDecoration(labelText: 'Phone account'),
                items: [
                  for (final conn in _connections)
                    DropdownMenuItem(value: conn.id, child: Text(conn.label)),
                ],
                onChanged: _saving
                    ? null
                    : (value) => setState(() {
                        draft.connectionId = value;
                        // Lines and overflow groups belong to one account.
                        draft.lineIds.clear();
                        draft.overflowGroupId = null;
                      }),
              ),
              const SizedBox(height: 12),
            ],

            Text('Rings', style: theme.textTheme.labelLarge),
            RadioListTile<String>(
              value: 'simultaneous',
              // ignore: deprecated_member_use
              groupValue: draft.mode,
              // ignore: deprecated_member_use
              onChanged: _saving ? null : (v) => setState(() => draft.mode = v!),
              title: const Text('Everyone at once'),
              contentPadding: EdgeInsets.zero,
              dense: true,
            ),
            RadioListTile<String>(
              value: 'sequential',
              // ignore: deprecated_member_use
              groupValue: draft.mode,
              // ignore: deprecated_member_use
              onChanged: _saving ? null : (v) => setState(() => draft.mode = v!),
              title: const Text('One at a time'),
              contentPadding: EdgeInsets.zero,
              dense: true,
            ),

            const SizedBox(height: 8),
            Text(
              'Ring for ${draft.timeoutSeconds} seconds',
              style: theme.textTheme.labelLarge,
            ),
            Slider(
              value: draft.timeoutSeconds.toDouble(),
              min: 5,
              max: 60,
              divisions: 11,
              label: '${draft.timeoutSeconds}s',
              onChanged: _saving
                  ? null
                  : (v) => setState(() => draft.timeoutSeconds = v.round()),
            ),
            Text(
              'Twilio hangs up a browser call at 60 seconds, so it cannot ring longer.',
              style: theme.textTheme.bodySmall,
            ),

            const SizedBox(height: 16),
            Text('Who it rings', style: theme.textTheme.labelLarge),
            if (_people.isEmpty)
              Text('Nobody to add yet.', style: theme.textTheme.bodySmall)
            else
              for (final person in _people)
                CheckboxListTile(
                  value: draft.memberIds.contains(person.id),
                  onChanged: _saving
                      ? null
                      : (on) => setState(() {
                          if (on == true) {
                            draft.memberIds.add(person.id);
                          } else {
                            draft.memberIds.remove(person.id);
                          }
                        }),
                  title: Text(person.name),
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                ),

            const SizedBox(height: 16),
            Text('Lines it answers', style: theme.textTheme.labelLarge),
            if (sameConnection.isEmpty)
              Text(
                'No numbers on this phone account yet. A group with no line '
                'never rings.',
                style: theme.textTheme.bodySmall,
              )
            else
              for (final line in sameConnection)
                CheckboxListTile(
                  value: draft.lineIds.contains(line.id),
                  onChanged: _saving
                      ? null
                      : (on) => setState(() {
                          if (on == true) {
                            draft.lineIds.add(line.id);
                          } else {
                            draft.lineIds.remove(line.id);
                          }
                        }),
                  title: Text(line.label),
                  subtitle: Text(
                    line.ringGroupId != null && line.ringGroupId != draft.id
                        ? '${line.e164} · answered by another group'
                        : line.e164,
                  ),
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                ),

            const SizedBox(height: 16),
            Text('If nobody answers', style: theme.textTheme.labelLarge),
            for (final option in const [
              ('voicemail', 'Take a voicemail'),
              ('forward', 'Forward to another number'),
              ('group', 'Ring another group'),
            ])
              RadioListTile<String>(
                value: option.$1,
                // ignore: deprecated_member_use
                groupValue: draft.overflow,
                // ignore: deprecated_member_use
                onChanged: _saving
                    ? null
                    : (v) => setState(() => draft.overflow = v!),
                title: Text(option.$2),
                contentPadding: EdgeInsets.zero,
                dense: true,
              ),

            if (draft.overflow == 'forward')
              TextFormField(
                initialValue: draft.overflowNumber,
                decoration: const InputDecoration(
                  labelText: 'Forward to',
                  hintText: '+12035550123',
                ),
                enabled: !_saving,
                onChanged: (value) => draft.overflowNumber = value,
              ),

            if (draft.overflow == 'group')
              otherGroups.isEmpty
                  ? Text(
                      'There is no other group on this phone account to send '
                      'calls to.',
                      style: theme.textTheme.bodySmall,
                    )
                  : DropdownButtonFormField<String>(
                      initialValue: draft.overflowGroupId,
                      decoration: const InputDecoration(labelText: 'Then ring'),
                      items: [
                        for (final g in otherGroups)
                          DropdownMenuItem(value: g.id, child: Text(g.name)),
                      ],
                      onChanged: _saving
                          ? null
                          : (v) => setState(() => draft.overflowGroupId = v),
                    ),

            if (_formError != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_formError!),
            ],

            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                Refused(
                  reason: _canEdit ? null : _editRefusal,
                  child: FilledButton(
                    onPressed: _canEdit && !_saving ? _save : null,
                    child: const Text('Save'),
                  ),
                ),
                TextButton(
                  onPressed: _saving
                      ? null
                      : () => setState(() {
                          _draft = null;
                          _formError = null;
                        }),
                  child: const Text('Cancel'),
                ),
                if (draft.id.isNotEmpty)
                  Refused(
                    reason: _canEdit ? null : _editRefusal,
                    child: TextButton(
                      onPressed: _canEdit && !_saving
                          ? () => _delete(
                              _groups.firstWhere((g) => g.id == draft.id),
                            )
                          : null,
                      child: const Text('Delete this group'),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// The form's working copy. Mutable on purpose — it is scratch until Save.
class _Draft {
  _Draft({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.mode,
    required this.timeoutSeconds,
    required this.memberIds,
    required this.overflow,
    required this.overflowNumber,
    required this.overflowGroupId,
    required this.lineIds,
  });

  final String id;
  String? connectionId;
  String name;
  String mode;
  int timeoutSeconds;
  Set<String> memberIds;
  String overflow;
  String overflowNumber;
  String? overflowGroupId;
  Set<String> lineIds;
}

class _Group {
  const _Group({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.mode,
    required this.timeoutSeconds,
    required this.memberUserIds,
    required this.overflow,
    this.overflowGroupId,
    this.overflowNumber,
  });

  final String id;
  final String connectionId;
  final String name;
  final String mode;
  final int timeoutSeconds;
  final List<String> memberUserIds;
  final String overflow;
  final String? overflowGroupId;
  final String? overflowNumber;

  factory _Group.fromJson(Map<String, dynamic> json) => _Group(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    mode: json['mode'] as String? ?? 'simultaneous',
    timeoutSeconds: (json['timeout_seconds'] as num?)?.toInt() ?? 30,
    memberUserIds: (json['member_user_ids'] as List? ?? const [])
        .map((id) => id.toString())
        .toList(growable: false),
    overflow: json['overflow'] as String? ?? 'voicemail',
    overflowGroupId: json['overflow_group_id'] as String?,
    overflowNumber: json['overflow_number'] as String?,
  );
}

class _Person {
  const _Person({required this.id, required this.name});

  final String id;
  final String name;

  factory _Person.fromJson(Map<String, dynamic> json) => _Person(
    id: json['id'] as String? ?? '',
    name: json['name'] as String? ?? '',
  );
}

class _Line {
  const _Line({
    required this.id,
    required this.connectionId,
    required this.e164,
    required this.label,
    this.ringGroupId,
  });

  final String id;
  final String connectionId;
  final String e164;
  final String label;
  final String? ringGroupId;

  factory _Line.fromJson(Map<String, dynamic> json) => _Line(
    id: json['id'] as String? ?? '',
    connectionId: json['connection_id'] as String? ?? '',
    e164: json['e164'] as String? ?? '',
    label: json['label'] as String? ?? '',
    ringGroupId: json['ring_group_id'] as String?,
  );
}

class _Conn {
  const _Conn({required this.id, required this.label});

  final String id;
  final String label;

  factory _Conn.fromJson(Map<String, dynamic> json) => _Conn(
    id: json['id'] as String? ?? '',
    label: switch (json['scope_type'] as String?) {
      'organization' => 'The group',
      _ => 'Company account',
    },
  );
}
