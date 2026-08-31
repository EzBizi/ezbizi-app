import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Phone › Settings › Voicemail — the greeting, not the messages.
///
/// ── Why a business needs to write this ───────────────────────────
///
/// Every line took a message with the same sentence, in every organization:
/// "Please leave a message after the tone." A caller could not tell whether
/// they had reached the right company, and nothing said when anybody would
/// ring back. It is the last thing a customer hears before deciding to try
/// somebody else, and it was the one part of the call nobody had any say over.
///
/// Messages themselves are not here. Recordings hang off the call and keep
/// their retention there; this screen is the greeting and the settings, so
/// deleting a box silences a line rather than erasing what a caller said.
class VoicemailPane extends StatefulWidget {
  const VoicemailPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<VoicemailPane> createState() => _VoicemailPaneState();
}

class _VoicemailPaneState extends State<VoicemailPane> {
  bool _loading = true;
  bool _saving = false;
  String? _error;

  List<_Box> _boxes = const [];
  List<_Conn> _connections = const [];

  /// The box being edited. Empty id means a new one.
  _Box? _editing;

  final _name = TextEditingController();
  final _greeting = TextEditingController();
  final _seconds = TextEditingController(text: '300');
  final _email = TextEditingController();
  List<String> _emails = const [];
  bool _transcribe = false;
  String? _connectionId;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_name, _greeting, _seconds, _email]) {
      c.dispose();
    }
    super.dispose();
  }

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    setState(() => _error = null);

    final results = await Future.wait([
      widget.api.get('/phone/voicemail-boxes'),
      widget.api.get('/phone/connections'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;
      _boxes = results[0].ok ? _Box.listFrom(results[0].body) : const [];
      _connections = results[1].ok ? _Conn.listFrom(results[1].body) : const [];
      _error = results[0].ok ? null : results[0].reason;
      _connectionId ??= _connections.isEmpty ? null : _connections.first.id;
    });
  }

  void _open(_Box? box) {
    setState(() {
      _editing = box ?? const _Box.blank();
      _name.text = box?.name ?? '';
      _greeting.text = box?.greetingText ?? '';
      _seconds.text = (box?.maxSeconds ?? 300).toString();
      _emails = List<String>.from(box?.notifyEmails ?? const []);
      _transcribe = box?.transcribe ?? false;
      _connectionId = box?.connectionId ?? _connectionId;
      _email.clear();
      _error = null;
    });
  }

  Future<void> _save() async {
    final editing = _editing;
    if (editing == null) return;

    final seconds = int.tryParse(_seconds.text.trim()) ?? 300;

    final body = {
      'name': _name.text.trim(),
      'greeting_mode': 'tts',
      'greeting_text': _greeting.text.trim(),
      'max_seconds': seconds,
      'transcribe': _transcribe,
      'notify_emails': _emails,
      if (editing.id.isEmpty) 'connection_id': _connectionId,
    };

    setState(() {
      _saving = true;
      _error = null;
    });

    final response = editing.id.isEmpty
        ? await widget.api.post('/phone/voicemail-boxes', body)
        : await widget.api.patch('/phone/voicemail-boxes/${editing.id}', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _editing = null;
    });

    await _load();
  }

  Future<void> _delete(_Box box) async {
    setState(() => _saving = true);

    final response = await widget.api.delete('/phone/voicemail-boxes/${box.id}');

    if (!mounted) return;

    setState(() {
      _saving = false;
      _error = response.ok ? null : response.reason;
    });

    if (response.ok) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_editing != null) return _form(theme);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Voicemail', style: theme.textTheme.titleMedium),
            ),
            if (_canEdit && _connections.isNotEmpty)
              OutlinedButton.icon(
                icon: const Icon(Icons.add, size: 18),
                label: const Text('New mailbox'),
                onPressed: () => _open(null),
              ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          'What a caller hears when nobody answers. Lines with no mailbox use '
          'the built-in sentence.',
          style: theme.textTheme.bodySmall,
        ),

        if (_error != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_error!),
        ],

        const SizedBox(height: 20),

        if (_connections.isEmpty)
          Text(
            'No phone account is connected yet.',
            style: theme.textTheme.bodySmall,
          )
        else if (_boxes.isEmpty)
          Text(
            'No mailboxes yet. Every line takes a message with the built-in '
            'sentence until one is made and chosen on General.',
            style: theme.textTheme.bodySmall,
          )
        else
          for (final box in _boxes) _row(box, theme),
      ],
    );
  }

  Widget _row(_Box box, ThemeData theme) {
    return Card(
      key: ValueKey('vm:${box.id}'),
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(box.name, style: theme.textTheme.titleSmall),
                  const SizedBox(height: 2),
                  Text(
                    'Up to ${box.maxSeconds}s'
                    '${box.notifyEmails.isEmpty ? '' : ' · tells ${box.notifyEmails.length}'}'
                    '${box.transcribe ? ' · transcribed' : ''}',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 6),

                  // The sentence the caller actually gets, as the server
                  // computes it — not re-derived here, so the preview cannot
                  // disagree with the call.
                  Text(
                    '“${box.spokenGreeting}”',
                    style: theme.textTheme.bodySmall?.copyWith(
                      fontStyle: FontStyle.italic,
                    ),
                  ),
                ],
              ),
            ),
            if (_canEdit) ...[
              TextButton(
                onPressed: () => _open(box),
                child: const Text('Edit'),
              ),
              IconButton(
                tooltip: 'Delete this mailbox',
                icon: const Icon(Icons.delete_outline),
                onPressed: _saving ? null : () => _delete(box),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _form(ThemeData theme) {
    final editing = _editing!;

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Back to the mailboxes',
              icon: const Icon(Icons.arrow_back),
              onPressed: () => setState(() => _editing = null),
            ),
            const SizedBox(width: 4),
            Text(
              editing.id.isEmpty ? 'New mailbox' : editing.name,
              style: theme.textTheme.titleMedium,
            ),
          ],
        ),

        if (_error != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_error!),
        ],

        const SizedBox(height: 16),
        TextField(
          controller: _name,
          decoration: const InputDecoration(
            labelText: 'Name',
            helperText: 'For your own list — callers never hear this.',
            border: OutlineInputBorder(),
          ),
        ),

        if (editing.id.isEmpty && _connections.length > 1) ...[
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            initialValue: _connectionId,
            isExpanded: true,
            decoration: const InputDecoration(
              labelText: 'Phone account',
              border: OutlineInputBorder(),
            ),
            items: [
              for (final c in _connections)
                DropdownMenuItem(value: c.id, child: Text(c.label)),
            ],
            onChanged: (id) => setState(() => _connectionId = id),
          ),
        ],

        const SizedBox(height: 16),
        TextField(
          controller: _greeting,
          maxLines: 4,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(
            labelText: 'Greeting',
            hintText: 'You have reached A-1 Chimney. Leave a number and we '
                'will ring you back the same day.',
            helperText: 'Spoken to the caller. Left empty, they hear the '
                'built-in sentence.',
            helperMaxLines: 2,
            border: OutlineInputBorder(),
          ),
        ),

        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.record_voice_over_outlined, size: 18),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'A caller hears exactly: “'
                  '${_greeting.text.trim().isEmpty ? 'Please leave a message after the tone.' : _greeting.text.trim()}'
                  '”',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ],
          ),
        ),

        const SizedBox(height: 16),
        TextField(
          controller: _seconds,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(
            labelText: 'Longest message (seconds)',
            helperText: 'Between 10 and 3600. Twilio’s own limits.',
            border: OutlineInputBorder(),
          ),
        ),

        const SizedBox(height: 8),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _transcribe,
          onChanged: (v) => setState(() => _transcribe = v),
          title: const Text('Write messages down'),
          subtitle: Text(
            'Twilio charges per transcription, and returns nothing at all for '
            'audio it cannot make out — a blank transcript is not an error, so '
            'the recording stays the record.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        const Divider(height: 32),
        Text('Tell these people', style: theme.textTheme.labelLarge),
        const SizedBox(height: 2),
        Text(
          'Email addresses, not users — the person who should hear about a '
          'sales voicemail is often not signed in here at all.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 12),
        if (_emails.isNotEmpty)
          Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              for (final email in _emails)
                InputChip(
                  label: Text(email),
                  onDeleted: () => setState(() => _emails = [
                    for (final e in _emails)
                      if (e != email) e,
                  ]),
                ),
            ],
          ),

        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                onSubmitted: (_) => _addEmail(),
                decoration: const InputDecoration(
                  labelText: 'Add an address',
                  hintText: 'office@example.com',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
            ),
            const SizedBox(width: 12),
            OutlinedButton(onPressed: _addEmail, child: const Text('Add')),
          ],
        ),

        const SizedBox(height: 24),
        Row(
          children: [
            FilledButton(
              onPressed: _canEdit && !_saving ? _save : null,
              child: Text(_saving ? 'Saving…' : 'Save'),
            ),
            const SizedBox(width: 12),
            TextButton(
              onPressed: () => setState(() => _editing = null),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }

  void _addEmail() {
    final value = _email.text.trim();
    if (value.isEmpty || _emails.contains(value)) return;

    setState(() {
      _emails = [..._emails, value];
      _email.clear();
    });
  }
}

class _Conn {
  const _Conn(this.id, this.label);

  final String id;
  final String label;

  static List<_Conn> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Conn(
          row['id'].toString(),
          (row['label'] ?? row['account_sid'] ?? 'Phone account').toString(),
        ),
    ];
  }
}

class _Box {
  const _Box({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.greetingText,
    required this.spokenGreeting,
    required this.maxSeconds,
    required this.transcribe,
    required this.notifyEmails,
  });

  const _Box.blank()
    : id = '',
      connectionId = '',
      name = '',
      greetingText = '',
      spokenGreeting = '',
      maxSeconds = 300,
      transcribe = false,
      notifyEmails = const [];

  final String id;
  final String connectionId;
  final String name;
  final String greetingText;

  /// What the caller actually hears, as the server computes it.
  final String spokenGreeting;
  final int maxSeconds;
  final bool transcribe;
  final List<String> notifyEmails;

  static List<_Box> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Box(
          id: row['id'].toString(),
          connectionId: (row['connection_id'] ?? '').toString(),
          name: (row['name'] ?? '').toString(),
          greetingText: (row['greeting_text'] ?? '').toString(),
          spokenGreeting: (row['spoken_greeting'] ?? '').toString(),
          maxSeconds: (row['max_seconds'] as num?)?.toInt() ?? 300,
          transcribe: row['transcribe'] == true,
          notifyEmails: [
            for (final e in (row['notify_emails'] as List? ?? const []))
              e.toString(),
          ],
        ),
    ];
  }
}
