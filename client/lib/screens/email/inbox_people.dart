import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/search_picker.dart';

/// Who can open one inbox, person by person — on the inbox's Settings card.
///
/// Jose, 2026-10-06: "I also want to be able to assign emails to specific
/// people, instead of just roles — maybe done via the email settings tab".
///
/// The roles that hold the inbox are SHOWN here, not edited: role grants are
/// written by the roles editor, and two screens writing the same grant is the
/// failure this module already removed once. People are added and removed
/// here, and only from the server's own list of who COULD open it once added —
/// so "added" never means nothing on the day they try.
class InboxPeople extends StatefulWidget {
  const InboxPeople({super.key, required this.api, required this.mailboxId});

  final ApiClient api;
  final String mailboxId;

  @override
  State<InboxPeople> createState() => _InboxPeopleState();
}

class _Person {
  const _Person(this.id, this.name, this.email, [this.why]);

  final String id;
  final String name;
  final String email;

  /// Why adding them would not let them open it; null when it would.
  final String? why;

  static List<_Person> list(Object? json) => [
    for (final row in (json as List?) ?? const [])
      if (row is Map)
        _Person(
          '${row['id']}',
          '${row['name'] ?? ''}',
          '${row['email'] ?? ''}',
          row['why'] as String?,
        ),
  ];
}

class _InboxPeopleState extends State<InboxPeople> {
  List<_Person> _people = const [];
  List<_Person> _candidates = const [];

  /// Everybody who cannot be added, listed greyed with why, so nobody looks
  /// missing (Eve, 2026-11-09: 9 of 119 staff were offered, the rest hidden).
  List<_Person> _unavailable = const [];
  List<String> _roles = const [];
  bool _loading = true;
  bool _busy = false;
  String? _error;

  String get _path => '/email/mailboxes/${widget.mailboxId}/people';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    _take(await widget.api.get(_path));
  }

  Future<void> _add(String? userId) async {
    if (userId == null || _busy) return;
    setState(() => _busy = true);
    _take(await widget.api.post(_path, {'user_id': userId}));
  }

  Future<void> _remove(_Person person) async {
    if (_busy) return;
    setState(() => _busy = true);
    _take(await widget.api.delete('$_path/${person.id}'));
  }

  /// Every answer carries the whole picture, so the screen never guesses.
  void _take(ApiResponse response) {
    if (!mounted) return;

    setState(() {
      _loading = false;
      _busy = false;

      if (!response.ok) {
        // Said, in the server's words: why this person could not be added.
        _error = response.message;
        return;
      }

      final data = response.data;
      if (data is! Map) return;

      _error = null;
      _people = _Person.list(data['people']);
      _candidates = _Person.list(data['candidates']);
      _unavailable = _Person.list(data['unavailable']);
      _roles = [
        for (final role in (data['roles'] as List?) ?? const [])
          if (role is Map) '${role['name'] ?? ''}',
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 8),
        child: SizedBox(height: 2, child: LinearProgressIndicator()),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Who can open this inbox', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          _roles.isEmpty
              ? 'No role has been given this inbox. Roles are given inboxes in Settings › Roles & permissions.'
              : 'Everyone with the role ${_roles.join(', ')}, and the people below. Roles '
                    'are given inboxes in Settings › Roles & permissions.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        if (_people.isEmpty)
          Text('No individual people yet.', style: theme.textTheme.bodySmall)
        else
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final person in _people)
                InputChip(
                  label: Text(person.name),
                  tooltip: person.email,
                  onDeleted: _busy ? null : () => _remove(person),
                  deleteButtonTooltipMessage: 'Take ${person.name} off this inbox',
                ),
            ],
          ),
        const SizedBox(height: 8),
        SearchPickerField<String>(
          value: null,
          hint: _candidates.isEmpty && _unavailable.isEmpty
              ? 'Nobody else is on staff'
              : 'Add a person',
          isDense: true,
          options: [
            for (final person in _candidates)
              SearchOption(value: person.id, label: person.name, detail: person.email),
            for (final person in _unavailable)
              SearchOption(
                value: person.id,
                label: person.name,
                detail: person.why,
                enabled: false,
              ),
          ],
          onChanged: _busy || (_candidates.isEmpty && _unavailable.isEmpty)
              ? null
              : _add,
        ),
        if (_error != null) ...[
          const SizedBox(height: 6),
          Text(
            _error!,
            style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
          ),
        ],
      ],
    );
  }
}
