import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import 'settings_pane.dart';

/// Phone › Settings › Extensions — short numbers that reach one person.
///
/// ── Twilio has no such thing ─────────────────────────────────────
///
/// There is no extension object in the Twilio API and nothing to register.
/// A caller who presses 101 has sent us the digits 101 and nothing more; what
/// makes that reach Analorena is a row in `phone_extensions` and the renderer
/// looking it up before it dials. So this screen is not configuring a carrier
/// feature — it IS the feature, and a number typed here does nothing until an
/// IVR menu has a step that collects digits.
///
/// ── Why the list includes people who have none ───────────────────
///
/// The question somebody opens this with is usually "what is Bar's number", and
/// the answer is often "she has not got one". A list of only the assigned
/// cannot say that: an absent name reads as a loading problem. So every active
/// person is listed and a blank field is the answer.
class ExtensionsPane extends StatefulWidget {
  const ExtensionsPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ExtensionsPane> createState() => _ExtensionsPaneState();
}

class _ExtensionsPaneState extends State<ExtensionsPane> {
  bool _loading = true;
  bool _saving = false;
  String? _error;

  String? _connectionId;
  List<_Person> _people = const [];

  /// What each person's field currently holds, against what the server last
  /// gave us. The pane saves the difference.
  final Map<String, TextEditingController> _fields = {};
  Map<String, String> _saved = const {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final field in _fields.values) {
      field.dispose();
    }
    super.dispose();
  }

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    setState(() => _error = null);

    final response = await widget.api.get('/phone/extensions');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.reason;
      });
      return;
    }

    final people = [
      for (final row in (response.data as List? ?? const []))
        if (row is Map<String, dynamic>) _Person.fromJson(row),
    ];

    setState(() {
      _loading = false;
      _people = people;
      _connectionId = response.body['connection_id'] as String?;
      _saved = {for (final p in people) p.userId: p.extension};

      for (final person in people) {
        _fields
            .putIfAbsent(person.userId, TextEditingController.new)
            .text = person.extension;
      }
    });
  }

  /// Names whose field no longer matches what the server holds.
  List<String> get _unsavedNames => [
    for (final person in _people)
      if ((_fields[person.userId]?.text.trim() ?? '') !=
          (_saved[person.userId] ?? ''))
        person.name,
  ];

  Future<void> _save() async {
    final connectionId = _connectionId;
    if (connectionId == null) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    // One request per person, because the endpoint takes one person — and
    // stopping at the first refusal rather than carrying on, since the
    // refusal names who already holds that number and burying it under three
    // more would lose the one useful sentence.
    for (final person in _people) {
      final typed = _fields[person.userId]?.text.trim() ?? '';
      if (typed == (_saved[person.userId] ?? '')) continue;

      final response = await widget.api.put('/phone/extensions', {
        'connection_id': connectionId,
        'user_id': person.userId,
        'extension': typed.isEmpty ? null : typed,
      });

      if (!mounted) return;

      if (!response.ok) {
        // What already saved stays saved — `_saved` has been advanced for
        // those — so the footer now names only the people still outstanding.
        setState(() {
          _saving = false;
          _error = response.reason;
        });
        return;
      }

      _saved = {..._saved, person.userId: typed};
    }

    setState(() => _saving = false);
  }

  void _discard() {
    setState(() {
      for (final person in _people) {
        _fields[person.userId]?.text = _saved[person.userId] ?? '';
      }
      _error = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    final unsaved = _unsavedNames;

    return SettingsPane(
      title: 'Extensions',
      subtitle: 'Short numbers that reach one person, when a menu asks the '
          'caller to dial one.',
      error: _error,

      // A refused save is reported here and retried with Save; the head's
      // retry reloads, which would discard what was typed. Reload is only
      // offered when there is nothing typed to lose.
      onRetry: unsaved.isEmpty ? _load : null,

      footer: _canEdit && _connectionId != null
          ? SettingsPaneFooter(
              unsaved: unsaved,
              saving: _saving,
              onSave: _save,
              onDiscard: _discard,
            )
          : null,
      children: [
        SettingsSection(
          title: 'Who answers what',
          children: [
            Text(
              'Twilio has no idea these exist. A caller pressing 101 has sent '
              'us three digits, and it is EzBizi that turns them into a '
              'person — so a number here does nothing until an IVR menu has a '
              'step that asks for one.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 16),

            if (_connectionId == null)
              Text(
                'No phone account is connected yet, so there is nothing for an '
                'extension to ring on.',
                style: theme.textTheme.bodySmall,
              )
            else
              for (final person in _people) _row(person, theme),
          ],
        ),
      ],
    );
  }

  Widget _row(_Person person, ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          Expanded(child: Text(person.name, style: theme.textTheme.bodyMedium)),
          const SizedBox(width: 12),
          SizedBox(
            width: 130,
            child: TextField(
              key: Key('extension:${person.userId}'),
              controller: _fields[person.userId],
              enabled: _canEdit,
              keyboardType: TextInputType.number,
              inputFormatters: [
                FilteringTextInputFormatter.digitsOnly,
                LengthLimitingTextInputFormatter(8),
              ],
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                hintText: 'None',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Person {
  const _Person({
    required this.userId,
    required this.name,
    required this.extension,
  });

  final String userId;
  final String name;
  final String extension;

  factory _Person.fromJson(Map<String, dynamic> json) => _Person(
    userId: json['user_id'] as String? ?? '',
    name: json['name'] as String? ?? '',
    extension: (json['extension'] ?? '').toString(),
  );
}
