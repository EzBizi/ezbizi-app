import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/list_table.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import 'settings_pane.dart';

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
  const VoicemailPane({
    super.key,
    required this.api,
    required this.session,
    this.pickAudio,
  });

  /// Picks a recording. Defaults to the file dialog; a test passes its own.
  final Future<PickedBytes?> Function()? pickAudio;

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

  /// The recorded greeting's link, or null to speak [_greeting]. Jose,
  /// 2026-10-05: "voicemail settings ... text to speech or recorded".
  String? _greetingAudio;
  bool _uploading = false;

  /// Whose mailbox this is: `company`, `user` or `ring_group`, and which.
  /// Jose, 2026-10-05: "voicemail settings for each queue, each user". A
  /// group's or person's box takes the messages for calls that reached them.
  String _ownerType = 'company';
  String? _ownerId;
  List<(String, String)> _people = const [];
  List<(String, String)> _groups = const [];

  Future<void> _uploadGreeting() async {
    final picked = await (widget.pickAudio ??
        () => Files.pickBytes(accept: '.mp3,.wav'))();
    if (picked == null || !mounted) return;

    setState(() {
      _uploading = true;
      _error = null;
    });

    final response = await widget.api.postFile(
      '/phone/audio',
      'audio',
      picked.name,
      picked.bytes,
    );
    if (!mounted) return;

    setState(() {
      _uploading = false;
      if (response.ok) {
        _greetingAudio = (response.data as Map?)?['url'] as String?;
      } else {
        _error = response.reason;
      }
    });
  }

  /// The tile picked (tiles over a table, Mia 2026-10-02): `unused` or
  /// `transcribed`; null is every mailbox. Counted here - the endpoint is
  /// every mailbox in one answer.
  String? _tile;

  /// What is typed in the search box. /phone/voicemail-boxes returns every
  /// mailbox, so the filter runs here.
  String _query = '';

  /// The boxes an account sends voicemail to when a line names none
  /// (`default_voicemail_box_id`). Such a box with no line of its own is not
  /// unused: every line without a box lands in it.
  Set<String> _defaults = const {};

  bool _unused(_Box box) => box.lineCount == 0 && !_defaults.contains(box.id);

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

  /// Whichever company the switcher is on — the same words the top bar shows,
  /// so a suggestion never names a business the reader does not work for.
  String get _placeName {
    final me = widget.session.me;
    final label = me?.scope.label.trim() ?? '';

    return label.isEmpty ? (me?.organization.name ?? 'us') : label;
  }

  Future<void> _load() async {
    setState(() => _error = null);

    final results = await Future.wait([
      widget.api.get('/phone/voicemail-boxes'),
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/members'),
      widget.api.get('/phone/ring-groups'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;
      _boxes = results[0].ok ? _Box.listFrom(results[0].body) : const [];
      _connections = results[1].ok ? _Conn.listFrom(results[1].body) : const [];
      final people = results[2].ok ? results[2].body['data'] : null;
      _people = [
        for (final row in (people is Map ? people['people'] as List? : null) ??
            const [])
          if (row is Map) (row['id'].toString(), row['name']?.toString() ?? ''),
      ];
      final groups = results[3].ok ? results[3].body['data'] : null;
      _groups = [
        for (final row in (groups is List ? groups : const []))
          if (row is Map) (row['id'].toString(), row['name']?.toString() ?? ''),
      ];
      _defaults = {
        for (final c in _connections)
          if (c.defaultBoxId != null) c.defaultBoxId!,
      };
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
      _greetingAudio = box?.greetingAudioUrl;
      _ownerType = switch (box?.ownerType) {
        'user' || 'ring_group' => box!.ownerType!,
        _ => 'company',
      };
      _ownerId = _ownerType == 'company' ? null : box?.ownerId;
      _connectionId = box?.connectionId ?? _connectionId;
      _email.clear();
      _error = null;
      _opened = _formValues();
    });
  }

  Future<void> _save() async {
    final editing = _editing;
    if (editing == null) return;

    final seconds = int.tryParse(_seconds.text.trim()) ?? 300;

    if (_ownerType != 'company' && _ownerId == null) {
      setState(() => _error = _ownerType == 'user'
          ? 'Pick the person this mailbox belongs to.'
          : 'Pick the team this mailbox belongs to.');
      return;
    }

    final body = {
      'name': _name.text.trim(),
      'greeting_mode': _greetingAudio == null ? 'tts' : 'audio',
      'greeting_text': _greeting.text.trim(),
      'greeting_audio_url': _greetingAudio,
      'owner_type': _ownerType,
      'owner_id': _ownerType == 'company' ? null : _ownerId,
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

    final response = await widget.api.delete(
      '/phone/voicemail-boxes/${box.id}',
    );

    if (!mounted) return;

    setState(() {
      _saving = false;
      _error = response.ok ? null : response.reason;
    });

    if (response.ok) await _load();
  }

  /// The values that get saved, in the shape used to compare them.
  Map<String, Object?> _formValues() => {
    'name': _name.text.trim(),
    'greeting_text': _greeting.text.trim(),
    'greeting_audio': _greetingAudio,
    'owner': '$_ownerType:${_ownerId ?? ''}',
    'max_seconds': int.tryParse(_seconds.text.trim()) ?? 300,
    'transcribe': _transcribe,
    'notify_emails': _emails,
  };

  /// What this mailbox looked like when it was opened.
  Map<String, Object?> _opened = const {};

  /// Which fields differ from that, in words a person would use.
  ///
  /// Names rather than a count. "Two changes not saved" answers how many and
  /// never answers which, and on a form with four sections the second question
  /// is the one that makes somebody scroll.
  List<String> get _unsaved {
    const names = {
      'name': 'the name',
      'greeting_text': 'the greeting',
      'greeting_audio': 'the recording',
      'owner': 'who it belongs to',
      'max_seconds': 'the message length',
      'transcribe': 'whether messages are written down',
      'notify_emails': 'who is told',
    };

    final now = _formValues();

    return [
      for (final entry in names.entries)
        if (!_sameValue(_opened[entry.key], now[entry.key])) entry.value,
    ];
  }

  static bool _sameValue(Object? a, Object? b) {
    if (a is List && b is List) return listEquals(a, b);

    return a == b;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    return _editing == null ? _list(theme) : _form(theme);
  }

  Widget _list(ThemeData theme) {
    return SettingsPane(
      title: 'Voicemail',
      subtitle:
          'What a caller hears when nobody answers. Lines with no '
          'mailbox use the built-in sentence.',
      error: _error,
      onRetry: _load,
      trailing: Wrap(
        alignment: WrapAlignment.end,
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: 12,
        runSpacing: 8,
        children: [
          if (_boxes.isNotEmpty)
            _searchBox(
              key: const ValueKey('comm-voicemail-boxes-search'),
              hint: 'Search mailbox or owner',
              onChanged: (value) => setState(() => _query = value),
            ),
          if (_canEdit && _connections.isNotEmpty)
            OutlinedButton.icon(
              icon: const Icon(Icons.add, size: 18),
              label: const Text('New mailbox'),
              onPressed: () => _open(null),
            ),
        ],
      ),

      // No footer, on purpose. Nothing in this list is edited in place, so a
      // Save here would say the opposite of what is true — and the footer's
      // absence is the only thing telling you which kind of pane this is.
      children: [
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
        else ...[
          _tiles(),
          const SizedBox(height: 12),
          _table(theme),
        ],
      ],
    );
  }

  Widget _tiles() {
    return FilterTiles(
      prefix: 'voicemail-boxes',
      picked: _tile,
      onPick: (key) => setState(() => _tile = key),
      tiles: [
        FilterTile(
          key: null,
          label: 'Mailboxes',
          count: _boxes.length,
          note: 'all',
        ),
        FilterTile(
          key: 'unused',
          label: 'Not used',
          count: _boxes.where(_unused).length,
          note: 'no line sends to it',
          tone: Tone.caution,
        ),
        FilterTile(
          key: 'transcribed',
          label: 'Written down',
          count: _boxes.where((b) => b.transcribe).length,
          note: 'transcribed',
        ),
      ],
    );
  }

  Widget _table(ThemeData theme) {
    final shown = [
      for (final box in switch (_tile) {
        'unused' => _boxes.where(_unused),
        'transcribed' => _boxes.where((b) => b.transcribe),
        _ => _boxes,
      })
        if (_searchHit(_query, [box.name, box.belongsTo, box.greetingText]))
          box,
    ];
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _query.trim().isNotEmpty
              ? 'Nothing matches "${_query.trim()}".'
              : 'No mailboxes like that.',
          textAlign: TextAlign.center,
        ),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Mailbox', flex: 5),
        ListColumn('Belongs to', flex: 3),
        ListColumn('Lines', flex: 2),
        // No Written down column, as on Numbers: stacked it was a bare "Yes"
        // (Jose, 2026-10-05). The mailbox's own editor has it.
        ListColumn('', flex: 2, end: true),
      ],
      rowKey: (i) => ValueKey('vm:${shown[i].id}'),
      rows: [
        for (final box in shown)
          [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  box.name,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                // The sentence the caller actually gets, as the server
                // computes it - not re-derived here, so the preview cannot
                // disagree with the call.
                Text(
                  '“${box.spokenGreeting}”',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: small?.copyWith(fontStyle: FontStyle.italic),
                ),
              ],
            ),
            Text(box.belongsTo, style: small),
            _lines(box),
            if (_canEdit)
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
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
              )
            else
              const SizedBox.shrink(),
          ],
      ],
    );
  }

  Widget _lines(_Box box) {
    final count = box.lineCount;
    final fallback = _defaults.contains(box.id);

    if (count == null) return const Text('—');
    if (count == 0 && !fallback) {
      return const StatusPill('None', tone: Tone.caution);
    }

    return Text(fallback ? '$count + account default' : '$count');
  }

  Widget _form(ThemeData theme) {
    final editing = _editing!;
    final fresh = editing.id.isEmpty;

    return SettingsPane(
      title: fresh ? 'New mailbox' : editing.name,
      subtitle: 'What this mailbox says, and who hears about it afterwards.',
      leading: IconButton(
        tooltip: 'Back to the mailboxes',
        icon: const Icon(Icons.arrow_back),
        onPressed: () => setState(() => _editing = null),
      ),
      error: _error,

      // No retry here, deliberately, and this is the one place the skeleton's
      // "every pane gets one" rule is worth breaking. The failure this form
      // shows is a SAVE that was refused; the head's retry RELOADS, which
      // would throw away what somebody just typed in the course of offering
      // to recover it. Retrying a refused save is pressing Save again.
      onRetry: null,

      footer: SettingsPaneFooter(
        unsaved: _unsaved,
        saving: _saving,
        onSave: _canEdit ? _save : null,
        onDiscard: () => setState(() => _editing = null),
        discardLabel: fresh ? 'Cancel' : 'Discard',
      ),
      children: [
        SettingsSection(
          title: 'Mailbox',
          children: [
            TextField(
              controller: _name,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                labelText: 'Name',
                helperText: 'For your own list — callers never hear this.',
                border: OutlineInputBorder(),
              ),
            ),
            if (fresh && _connections.length > 1) ...[
              const SizedBox(height: 16),
              SearchPickerField<String>(
                value: _connectionId,
                label: 'Phone account',
                border: OutlineInputBorder(),
                options: [
                  for (final c in _connections)
                    SearchOption(value: c.id, label: c.label),
                ],
                onChanged: (id) => setState(() => _connectionId = id),
              ),
            ],
          ],
        ),

        SettingsSection(
          title: 'Belongs to',
          children: [
            SearchPickerField<String>(
              key: const ValueKey('voicemail-owner-type'),
              value: _ownerType,
              label: 'Whose mailbox',
              border: const OutlineInputBorder(),
              options: const [
                SearchOption(value: 'company', label: 'The company'),
                SearchOption(value: 'user', label: 'A person'),
                SearchOption(value: 'ring_group', label: 'A team'),
              ],
              onChanged: (v) => setState(() {
                _ownerType = v ?? 'company';
                // A person's id is not a team's.
                _ownerId = null;
              }),
            ),
            if (_ownerType != 'company') ...[
              const SizedBox(height: 12),
              SearchPickerField<String>(
                key: const ValueKey('voicemail-owner'),
                value: _ownerId,
                label: _ownerType == 'user' ? 'Person' : 'Team',
                border: const OutlineInputBorder(),
                options: [
                  for (final (id, name)
                      in _ownerType == 'user' ? _people : _groups)
                    SearchOption(value: id, label: name),
                ],
                onChanged: (v) => setState(() => _ownerId = v),
              ),
            ],
            const SizedBox(height: 8),
            Text(
              _ownerType == 'company'
                  ? 'Takes messages for the lines that use it.'
                  : 'Takes the messages for calls that reached '
                        '${_ownerType == 'user' ? 'this person' : 'this team'} '
                        'and nobody picked up.',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),

        SettingsSection(
          title: 'Greeting',
          children: [
            TextField(
              controller: _greeting,
              maxLines: 4,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                labelText: 'Greeting',

                // The place you are standing in, not a company from a
                // screenshot. A suggestion naming somebody else's business is
                // worse than no suggestion: it reads as a template nobody
                // adapted, and it is exactly the kind of thing that gets saved
                // unedited and then spoken to a real caller.
                hintText:
                    'You have reached $_placeName. Leave a number and we '
                    'will ring you back the same day.',
                helperText:
                    'Spoken to the caller. Left empty, they hear the '
                    'built-in sentence.',
                helperMaxLines: 2,
                border: const OutlineInputBorder(),
              ),
            ),

            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                OutlinedButton.icon(
                  key: const ValueKey('voicemail-recording-upload'),
                  icon: const Icon(Icons.mic_none_outlined, size: 18),
                  label: Text(
                    _greetingAudio == null
                        ? 'Use a recording instead'
                        : 'Replace the recording',
                  ),
                  onPressed: _uploading ? null : _uploadGreeting,
                ),
                if (_greetingAudio != null)
                  TextButton(
                    key: const ValueKey('voicemail-recording-remove'),
                    onPressed: () => setState(() => _greetingAudio = null),
                    child: const Text('Use text-to-speech'),
                  ),
              ],
            ),
            if (_greetingAudio != null)
              Text(
                'Callers hear your recording, not the text.',
                style: theme.textTheme.bodySmall,
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
          ],
        ),

        SettingsSection(
          title: 'Where messages go',
          children: [
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _transcribe,
              onChanged: (v) => setState(() => _transcribe = v),
              title: const Text('Write messages down'),
              subtitle: Text(
                'Twilio charges per transcription, and returns nothing at all '
                'for audio it cannot make out — a blank transcript is not an '
                'error, so the recording stays the record.',
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
                      onDeleted: () => setState(
                        () => _emails = [
                          for (final e in _emails)
                            if (e != email) e,
                        ],
                      ),
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
          ],
        ),

        SettingsSection(
          title: 'After the beep',
          children: [
            TextField(
              controller: _seconds,
              keyboardType: TextInputType.number,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                labelText: 'Longest message (seconds)',
                helperText: 'Between 10 and 3600. Twilio’s own limits.',
                border: OutlineInputBorder(),
              ),
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
  const _Conn(this.id, this.label, [this.defaultBoxId]);

  final String id;
  final String label;
  final String? defaultBoxId;

  static List<_Conn> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Conn(
          row['id'].toString(),
          (row['label'] ?? row['account_sid'] ?? 'Phone account').toString(),
          row['default_voicemail_box_id'] as String?,
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
    this.ownerType,
    this.ownerName,
    this.lineCount,
    this.greetingAudioUrl,
    this.ownerId,
  });

  const _Box.blank()
    : id = '',
      connectionId = '',
      name = '',
      greetingText = '',
      spokenGreeting = '',
      maxSeconds = 300,
      transcribe = false,
      notifyEmails = const [],
      ownerType = null,
      ownerName = null,
      lineCount = null,
      greetingAudioUrl = null,
      ownerId = null;

  final String id;
  final String connectionId;
  final String name;
  final String greetingText;

  /// What the caller actually hears, as the server computes it.
  final String spokenGreeting;
  final int maxSeconds;
  final bool transcribe;
  final List<String> notifyEmails;
  final String? ownerType;
  final String? ownerName;

  /// How many lines send voicemail here; null from a server that does not say.
  final int? lineCount;

  /// The recorded greeting, when the box plays one instead of [greetingText].
  final String? greetingAudioUrl;

  /// The person's or team's id when [ownerType] names one.
  final String? ownerId;

  /// "A person · Ana Ruiz" - the kind from `owner_type`, the name from
  /// `owner_name`, both the server's.
  String get belongsTo {
    final kind = switch (ownerType) {
      'user' => 'A person',
      'ring_group' => 'A ring group',
      'number' => 'A number',
      'company' => 'The company',
      _ => '',
    };
    final name = ownerName?.trim() ?? '';

    if (kind.isEmpty) return name.isEmpty ? '—' : name;

    return name.isEmpty ? kind : '$kind · $name';
  }

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
          greetingAudioUrl: row['greeting_mode'] == 'audio'
              ? row['greeting_audio_url'] as String?
              : null,
          spokenGreeting: (row['spoken_greeting'] ?? '').toString(),
          maxSeconds: (row['max_seconds'] as num?)?.toInt() ?? 300,
          transcribe: row['transcribe'] == true,
          notifyEmails: [
            for (final e in (row['notify_emails'] as List? ?? const []))
              e.toString(),
          ],
          ownerType: row['owner_type'] as String?,
          ownerId: row['owner_id'] as String?,
          ownerName: row['owner_name'] as String?,
          lineCount: (row['line_count'] as num?)?.toInt(),
        ),
    ];
  }
}

/// The list's search box, sized to sit in the pane's header beside its
/// buttons: 40 high, the height of the controls around it.
Widget _searchBox({
  required Key key,
  required String hint,
  required ValueChanged<String> onChanged,
}) => SizedBox(
  width: 260,
  child: TextField(
    key: key,
    onChanged: onChanged,
    textAlignVertical: TextAlignVertical.center,
    decoration: InputDecoration(
      isDense: true,
      hintText: hint,
      prefixIcon: const Icon(Icons.search, size: 18),
      prefixIconConstraints: const BoxConstraints(minWidth: 36),
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
