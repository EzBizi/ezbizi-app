import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import 'phone_refs.dart';
import 'phone_search.dart';

/// Phone › Settings › IVR menus — the menu a caller hears.
///
/// ── Why this draws one menu and not a tree ───────────────────────
///
/// The stored shape allows nesting, and `TwimlRenderer` reads exactly one
/// menu: it finds the first, and routes on the key pressed. An editor that let
/// somebody build "press 2, then 4 for accounts" would be drawing a thing the
/// renderer walks straight past — the caller would press 2 and land on the ring
/// group, and nobody would know why.
///
/// So: one prompt, up to ten keys, and every action here is one the renderer
/// actually performs. Deeper menus are a renderer change first, a screen
/// second.
///
/// ── Why a menu is optional and says so ───────────────────────────
///
/// Most lines want no menu at all — ring the desk. A line with no flow rings
/// its group directly, and the Numbers screen is where a menu gets attached.
class FlowsPane extends StatefulWidget {
  const FlowsPane({
    super.key,
    required this.api,
    required this.session,
    this.pickAudio,
  });

  final ApiClient api;
  final Session session;

  /// Picks a recording. Defaults to the file dialog; a test passes its own.
  final Future<PickedBytes?> Function()? pickAudio;

  @override
  State<FlowsPane> createState() => _FlowsPaneState();
}

class _FlowsPaneState extends State<FlowsPane> {
  static const _actions = <(String, String)>[
    ('ring_group', 'Ring a group'),
    ('voicemail', 'Take a message'),
    ('forward', 'Forward the call'),
    ('hangup', 'Say something and hang up'),

    // The renderer has dialled an extension from a menu key since extensions
    // existed; nothing could ever ask it to. A key here was the missing half.
    ('extension', 'Ring one person'),

    // Every phone menu has one, and this one did not: a caller who missed
    // which key was which had no way back and had to hang up and ring again.
    ('repeat', 'Repeat the menu'),
  ];

  bool _loading = true;
  String? _error;

  List<_Flow> _flows = const [];

  /// What is typed in the search box. /phone/flows returns every menu, so
  /// the filter runs here.
  String _query = '';
  List<RingGroupRef> _groups = const [];
  List<_Conn> _connections = const [];
  List<_Ext> _extensions = const [];

  _Draft? _draft;
  bool _saving = false;
  bool _uploading = false;

  Future<void> _uploadRecording(_Draft draft) async {
    final picked = await (widget.pickAudio ??
        () => Files.pickBytes(accept: '.mp3,.wav'))();
    if (picked == null || !mounted) return;

    setState(() {
      _uploading = true;
      _formError = null;
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
        draft.promptAudio = (response.data as Map?)?['name'] as String?;
      } else {
        _formError = response.reason;
      }
    });
  }
  String? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal => _me.refusal('phone.edit', 'change call menus');

  Future<void> _load() async {
    setState(() {
      _loading = _flows.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/flows'),
      widget.api.get('/phone/ring-groups'),
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/extensions'),
    ]);

    if (!mounted) return;

    final failed = results.firstWhere(
      (r) => !r.ok,
      orElse: () => results.first,
    );

    if (!failed.ok) {
      setState(() {
        _loading = false;
        _error = failed.reason;
      });
      return;
    }

    setState(() {
      _flows = _Flow.listFrom(results[0].body);
      _groups = RingGroupRef.listFrom(results[1].body);
      _connections = _Conn.listFrom(results[2].body);
      _extensions = _Ext.listFrom(results[3].body);
      _loading = false;
    });
  }

  Future<void> _save() async {
    final draft = _draft!;

    if (draft.name.trim().isEmpty) {
      setState(() => _formError = 'Give this menu a name.');
      return;
    }

    if (draft.prompt.trim().isEmpty) {
      setState(() => _formError = 'Write what the caller hears.');
      return;
    }

    final digits = draft.options.map((o) => o.digit).toList();
    if (digits.length != digits.toSet().length) {
      setState(() => _formError = 'Two keys cannot do different things.');
      return;
    }

    // A key set to ring a group with no group chosen would post null and the
    // renderer would fall through to the line's own group — so the caller
    // reaches somebody, but never who this key promised.
    if (draft.options.any(
      (o) => o.action == 'ring_group' && (o.groupId ?? '').isEmpty,
    )) {
      setState(() => _formError = 'Say which group each ringing key reaches.');
      return;
    }

    if (draft.options.any(
      (o) => o.action == 'forward' && o.number.trim().isEmpty,
    )) {
      setState(
        () => _formError = 'Say where each forwarding key sends the call.',
      );
      return;
    }

    // A key set to ring one person with nobody chosen posts no digits, and the
    // renderer sends that caller to voicemail — so the key answers, which is
    // exactly why nobody would report it as broken.
    if (draft.options.any(
      (o) => o.action == 'extension' && o.extension.trim().isEmpty,
    )) {
      setState(() => _formError = 'Say which extension each key rings.');
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final body = <String, dynamic>{
      'name': draft.name.trim(),
      'prompt': draft.prompt.trim(),
      'prompt_audio': draft.promptAudio,
      'wait_seconds': draft.waitSeconds,
      'replays': draft.replays,
      'no_input': draft.noInput,
      'options': [
        for (final option in draft.options)
          {
            'digit': option.digit,
            'action': option.action,
            if (option.action == 'ring_group') 'ring_group_id': option.groupId,
            if (option.action == 'forward') 'number': option.number.trim(),
            if (option.action == 'hangup') 'say': option.say.trim(),
            if (option.action == 'extension')
              'extension': option.extension.trim(),
          },
      ],
    };

    final response = draft.id == null
        ? await widget.api.post('/phone/flows', {
            ...body,
            'connection_id': draft.connectionId,
          })
        : await widget.api.patch('/phone/flows/${draft.id}', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    setState(() {
      _saving = false;
      _draft = null;
    });

    await _load();
  }

  Future<void> _delete(_Flow flow) async {
    final response = await widget.api.delete('/phone/flows/${flow.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    await _load();
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
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    if (_connections.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'A call menu belongs to a phone account. Switch phone service on for '
          'a company and its menus are set here.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    if (_draft != null) return _form(theme);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('IVR menus', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'What a caller hears before anybody picks up. A line with no menu '
          'rings its group straight away.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),

        Row(
          children: [
            Refused(
              reason: _canEdit ? null : _editRefusal,
              child: FilledButton(
                onPressed: _canEdit
                    ? () => setState(() {
                        _draft = _Draft.blank(_connections.first.id);
                        _formError = null;
                      })
                    : null,
                child: const Text('Add a menu'),
              ),
            ),
            const SizedBox(width: 12),
            if (_flows.isNotEmpty)
              Expanded(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: phoneSearchBox(
                    key: const ValueKey('comm-ivr-menus-search'),
                    hint: 'Search menu or key',
                    onChanged: (value) => setState(() => _query = value),
                  ),
                ),
              ),
          ],
        ),

        const SizedBox(height: 20),

        if (_flows.isEmpty)
          Text(
            'No menus yet. Every line rings its group as soon as it is '
            'answered.',
            style: theme.textTheme.bodySmall,
          )
        else
          _table(theme),
      ],
    );
  }

  /// Name, the keys set, and the lines using it (layout A, Mia, 2026-10-02).
  /// The server sends the ids of the lines, not their names, so the column
  /// counts them.
  Widget _table(ThemeData theme) {
    final small = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final bad = ToneColours.of(context, Tone.bad).ink;
    final shown = [
      for (final flow in _flows)
        if (phoneSearchHit(_query, [
          flow.name,
          for (final option in flow.options) _keyLabel(option),
        ]))
          flow,
    ];

    if (shown.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'Nothing matches "${_query.trim()}".',
          textAlign: TextAlign.center,
        ),
      );
    }

    return ListTable(
      columns: const [
        ListColumn('Menu', flex: 3),
        ListColumn('Keys', flex: 5),
        ListColumn('Used by', flex: 2),
        ListColumn('', flex: 3, end: true),
      ],
      rowKey: (i) => ValueKey('flow:${shown[i].id}'),
      rows: [
        for (final flow in shown)
          [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  flow.name,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),

                // The menu that is configured and never heard.
                //
                // Everything else on the row reads as healthy while this is
                // true — it has keys, it is on a line — and no caller has
                // ever heard it. Nobody reports it either: the caller does
                // not know they missed a menu, and the owner does not know
                // theirs is skipped.
                if (!flow.plays)
                  Text(
                    'No caller ever hears this. It has no step that plays, '
                    'so calls go straight past it to whoever answers.',
                    style: theme.textTheme.bodySmall?.copyWith(color: bad),
                  ),
              ],
            ),
            Text(
              flow.options.isEmpty
                  ? 'No keys'
                  : [for (final option in flow.options) _keyLabel(option)]
                        .join(' · '),
              style: small,
            ),
            flow.numberIds.isEmpty
                ? const StatusPill('Not used', tone: Tone.caution)
                : Text(
                    'On ${flow.numberIds.length} '
                    '${flow.numberIds.length == 1 ? 'line' : 'lines'}',
                  ),
            _canEdit
                ? Wrap(
                    alignment: WrapAlignment.end,
                    children: [
                      TextButton(
                        onPressed: () => setState(() {
                          _draft = _Draft.of(flow);
                          _formError = null;
                        }),
                        child: const Text('Edit'),
                      ),
                      TextButton(
                        onPressed: () => _delete(flow),
                        child: const Text('Remove'),
                      ),
                    ],
                  )
                : const SizedBox.shrink(),
          ],
      ],
    );
  }

  /// One key, in a few words: "1 Sales", "0 Ring 0102", "9 Repeat". Named
  /// after where it goes when that is known, so the row reads like the menu.
  String _keyLabel(Map<String, dynamic> option) {
    final digit = option['digit']?.toString() ?? '?';
    final action = option['action']?.toString();

    String? groupName() {
      final id = option['ring_group_id']?.toString();
      for (final group in _groups) {
        if (group.id == id) return group.name;
      }
      return null;
    }

    String? holder() {
      final ext = option['extension']?.toString();
      for (final e in _extensions) {
        if (e.extension == ext) return e.name;
      }
      return ext == null || ext.isEmpty ? null : 'extension $ext';
    }

    final what = switch (action) {
      'ring_group' => groupName() ?? 'Ring a group',
      'extension' => holder() ?? 'Ring one person',
      'voicemail' => 'Message',
      'forward' => 'Forward',
      'hangup' => 'Hang up',
      'repeat' => 'Repeat',
      _ => 'Not set',
    };

    return '$digit $what';
  }

  Widget _form(ThemeData theme) {
    final draft = _draft!;

    return ListView(
      key: const ValueKey('flow-form'),
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Back to the list',
              icon: const Icon(Icons.arrow_back),
              onPressed: () => setState(() {
                _draft = null;
                _formError = null;
              }),
            ),
            const SizedBox(width: 4),
            Expanded(
              child: Text(
                draft.id == null ? 'New menu' : 'Edit menu',
                style: theme.textTheme.titleMedium,
              ),
            ),
          ],
        ),

        const SizedBox(height: 16),
        TextFormField(
          initialValue: draft.name,
          decoration: const InputDecoration(
            labelText: 'What this menu is called',
            hintText: 'Main menu',
            border: OutlineInputBorder(),
          ),
          onChanged: (v) => draft.name = v,
        ),

        const SizedBox(height: 16),
        TextFormField(
          initialValue: draft.prompt,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'What the caller hears',
            hintText: 'Press 1 for the office, 2 to leave a message.',
            border: OutlineInputBorder(),
          ),
          onChanged: (v) => draft.prompt = v,
        ),

        // TEXT-TO-SPEECH OR A RECORDING. Jose, 2026-10-05: "automatic audio
        // replies — text to speech or recorded".
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            OutlinedButton.icon(
              key: const ValueKey('flow-recording-upload'),
              icon: const Icon(Icons.mic_none_outlined, size: 18),
              label: Text(
                draft.promptAudio == null
                    ? 'Use a recording instead'
                    : 'Replace the recording',
              ),
              onPressed: _uploading ? null : () => _uploadRecording(draft),
            ),
            if (draft.promptAudio != null)
              TextButton(
                key: const ValueKey('flow-recording-remove'),
                onPressed: () => setState(() => draft.promptAudio = null),
                child: const Text('Use text-to-speech'),
              ),
          ],
        ),
        Text(
          draft.promptAudio == null
              ? 'Callers hear the text above, read aloud. An MP3 or WAV can '
                    'play instead.'
              : 'Callers hear your recording. The text above is what the '
                    'chart shows, and what is read if the recording cannot play.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 20),
        Text('What each key does', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(
          'A key you do not list rings the line as usual. Nobody is ever hung '
          'up on for pressing the wrong thing.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),

        for (var i = 0; i < draft.options.length; i++)
          _optionRow(draft, i, theme),

        if (draft.options.length < 10)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              icon: const Icon(Icons.add),
              label: const Text('Add a key'),
              onPressed: () =>
                  setState(() => draft.options.add(_OptionDraft.blank())),
            ),
          ),

        // IF NOBODY PRESSES A KEY. Jose, 2026-09-28: the menu rang the line
        // when it ended, and "spam callers are getting through that way".
        const SizedBox(height: 20),
        Text('If nobody presses a key', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            SizedBox(
              width: 180,
              child: SearchPickerField<int>(
                key: const ValueKey('flow-wait'),
                value: draft.waitSeconds,
                label: 'Wait for a key',
                border: const OutlineInputBorder(),
                isDense: true,
                options: [
                  for (final s in const [3, 5, 7, 10, 15, 20, 30])
                    SearchOption(value: s, label: '$s seconds'),
                ],
                onChanged: (v) => setState(() => draft.waitSeconds = v ?? 5),
              ),
            ),
            SizedBox(
              width: 200,
              child: SearchPickerField<int>(
                key: const ValueKey('flow-replays'),
                value: draft.replays,
                label: 'Play the menu',
                border: const OutlineInputBorder(),
                isDense: true,
                options: [
                  for (var n = 0; n <= 5; n++)
                    SearchOption(
                      value: n,
                      label: n == 0 ? 'Once' : '${n + 1} times',
                    ),
                ],
                onChanged: (v) => setState(() => draft.replays = v ?? 0),
              ),
            ),
            SizedBox(
              width: 220,
              child: SearchPickerField<String>(
                key: const ValueKey('flow-no-input'),
                value: draft.noInput,
                label: 'Then',
                border: const OutlineInputBorder(),
                isDense: true,
                options: const [
                  SearchOption(value: 'hangup', label: 'End the call'),
                  SearchOption(value: 'ring', label: 'Ring the line'),
                ],
                onChanged: (v) => setState(() => draft.noInput = v ?? 'ring'),
              ),
            ),
          ],
        ),

        // Beside Save, where the person who pressed it is looking. At the
        // top it scrolled out of a long form and the refusal was invisible.
        if (_formError != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_formError!),
        ],
        const SizedBox(height: 24),
        Row(
          children: [
            FilledButton(
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Saving…' : 'Save'),
            ),
            const SizedBox(width: 12),
            TextButton(
              onPressed: () => setState(() {
                _draft = null;
                _formError = null;
              }),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }

  Widget _optionRow(_Draft draft, int index, ThemeData theme) {
    final option = draft.options[index];

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                SizedBox(
                  // A dropdown with a label and a chevron needs about this
                  // much; 64 and 72 both overflowed on a phone.
                  width: 88,
                  child: SearchPickerField<String>(
                    key: ValueKey('digit:$index'),
                    value: option.digit,
                    label: 'Key',
                    border: OutlineInputBorder(),
                    isDense: true,
                    options: [
                      for (final d in const [
                        '0',
                        '1',
                        '2',
                        '3',
                        '4',
                        '5',
                        '6',
                        '7',
                        '8',
                        '9',
                        '*',
                        '#',
                      ])
                        SearchOption(value: d, label: d),
                    ],
                    onChanged: (v) => setState(() => option.digit = v ?? '1'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: SearchPickerField<String>(
                    key: ValueKey('action:$index'),
                    value: option.action,
                    label: 'Does what',
                    border: OutlineInputBorder(),
                    isDense: true,
                    options: [
                      for (final (value, label) in _actions)
                        SearchOption(value: value, label: label),
                    ],
                    onChanged: (v) =>
                        setState(() => option.action = v ?? 'voicemail'),
                  ),
                ),
                IconButton(
                  tooltip: 'Remove this key',
                  icon: const Icon(Icons.close),
                  // Default IconButton reserves 48px of tap target plus its own
                  // padding, which overflowed this row on a phone. Still 40px,
                  // which clears the 44px touch guidance with the row padding.
                  visualDensity: VisualDensity.compact,
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(
                    minWidth: 40,
                    minHeight: 40,
                  ),
                  onPressed: () =>
                      setState(() => draft.options.removeAt(index)),
                ),
              ],
            ),

            if (option.action == 'ring_group') ...[
              const SizedBox(height: 12),
              SearchPickerField<String>(
                key: ValueKey('group:$index'),
                value: _groups.any((g) => g.id == option.groupId)
                    ? option.groupId
                    : null,
                label: 'Which group',
                border: OutlineInputBorder(),
                isDense: true,
                options: [
                  for (final g in _groups)
                    SearchOption(value: g.id, label: g.name),
                ],
                onChanged: (v) => setState(() => option.groupId = v),
              ),
              if (_groups.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    'No ring groups yet. Make one on Ring groups first — a key '
                    'that rings nobody is worse than no key.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
            ],

            if (option.action == 'forward') ...[
              const SizedBox(height: 12),
              TextFormField(
                key: ValueKey('number:$index'),
                initialValue: option.number,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: 'Forward to',
                  hintText: '+12035550123',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                onChanged: (v) => option.number = v,
              ),
            ],

            if (option.action == 'extension') ...[
              const SizedBox(height: 12),
              SearchPickerField<String>(
                key: ValueKey('extension:$index'),
                value: _extensions.any((e) => e.extension == option.extension)
                    ? option.extension
                    : null,
                label: 'Which extension',
                border: OutlineInputBorder(),
                isDense: true,
                // The extensions somebody actually holds, not free digits: the
                // renderer looks these up, and digits nobody holds make a key
                // that says "there is no extension" to every caller.
                options: [
                  for (final e in _extensions)
                    SearchOption(value: e.extension, label: '${e.extension} · ${e.name}'),
                ],
                onChanged: (v) => setState(() => option.extension = v ?? ''),
              ),
              if (_extensions.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    'Nobody has an extension yet. Give somebody one on Users '
                    'first — a key that reaches nobody is worse than no key.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
            ],

            if (option.action == 'hangup') ...[
              const SizedBox(height: 12),
              TextFormField(
                key: ValueKey('say:$index'),
                initialValue: option.say,
                decoration: const InputDecoration(
                  labelText: 'Say this first',
                  hintText: 'Our opening hours are nine to five.',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                onChanged: (v) => option.say = v,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _OptionDraft {
  _OptionDraft({
    required this.digit,
    required this.action,
    this.groupId,
    this.number = '',
    this.say = '',
    this.extension = '',
  });

  factory _OptionDraft.blank() =>
      _OptionDraft(digit: '1', action: 'ring_group');

  String digit;
  String action;
  String? groupId;
  String number;
  String say;
  String extension;
}

class _Draft {
  _Draft({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.prompt,
    required this.options,
    this.waitSeconds = 5,
    this.replays = 0,
    this.noInput = 'ring',
    this.promptAudio,
  });

  final String? id;
  final String connectionId;
  String name;
  String prompt;
  final List<_OptionDraft> options;

  /// The recording played instead of [prompt], or null for text-to-speech.
  String? promptAudio;

  /// What happens when nobody presses a key; see the form.
  int waitSeconds;
  int replays;
  String noInput;

  static _Draft blank(String connectionId) => _Draft(
    id: null,
    connectionId: connectionId,
    name: '',
    prompt: '',
    options: [_OptionDraft.blank()],
  );

  static _Draft of(_Flow flow) => _Draft(
    id: flow.id,
    connectionId: flow.connectionId,
    name: flow.name,
    prompt: flow.prompt,
    waitSeconds: flow.waitSeconds,
    replays: flow.replays,
    noInput: flow.noInput,
    promptAudio: flow.promptAudio,
    options: [
      for (final option in flow.options)
        _OptionDraft(
          digit: option['digit']?.toString() ?? '1',
          action: option['action']?.toString() ?? 'ring_group',
          groupId: option['ring_group_id']?.toString(),
          number: option['number']?.toString() ?? '',
          say: option['say']?.toString() ?? '',
          extension: option['extension']?.toString() ?? '',
        ),
    ],
  );
}

@immutable
class _Flow {
  const _Flow({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.prompt,
    required this.options,
    required this.numberIds,
    required this.plays,
    this.waitSeconds = 5,
    this.replays = 0,
    this.noInput = 'ring',
    this.promptAudio,
  });

  final String? promptAudio;
  final int waitSeconds;
  final int replays;
  final String noInput;

  final String id;
  final String connectionId;
  final String name;
  final String prompt;
  final List<Map<String, dynamic>> options;
  final List<String> numberIds;

  /// Whether a caller ever hears this menu, answered by the server from the
  /// renderer's own lookup. A menu with no usable step is skipped at call
  /// time, and every other field here looks perfectly healthy while it is.
  final bool plays;

  static List<_Flow> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((row) {
          return _Flow(
            id: row['id'].toString(),
            connectionId: row['connection_id']?.toString() ?? '',
            name: row['name']?.toString() ?? 'Menu',
            prompt: row['prompt']?.toString() ?? '',
            options: (row['options'] as List? ?? const [])
                .whereType<Map<String, dynamic>>()
                .toList(),
            numberIds: (row['number_ids'] as List? ?? const [])
                .map((e) => e.toString())
                .toList(),

            // Absent on an older server. Defaulting to TRUE rather than false:
            // "this menu never plays" is a serious claim and inventing it from a
            // missing field would put a warning on every healthy menu.
            plays: row['plays'] == null ? true : row['plays'] == true,
            waitSeconds: (row['wait_seconds'] as num?)?.toInt() ?? 5,
            replays: (row['replays'] as num?)?.toInt() ?? 0,
            noInput: row['no_input']?.toString() ?? 'ring',
            promptAudio: row['prompt_audio'] as String?,
          );
        })
        .toList(growable: false);
  }
}

/// An extension somebody holds, as the roster on Users reports it.
@immutable
class _Ext {
  const _Ext({required this.extension, required this.name});

  final String extension;
  final String name;

  static List<_Ext> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    // Everybody is listed, holder or not — that roster answers "who has not
    // got one yet", which is not the question a menu key asks.
    return data
        .whereType<Map<String, dynamic>>()
        .where((r) => (r['extension']?.toString() ?? '').isNotEmpty)
        .map(
          (r) => _Ext(
            extension: r['extension'].toString(),
            name: r['name']?.toString() ?? 'Somebody',
          ),
        )
        .toList(growable: false);
  }
}

@immutable
class _Conn {
  const _Conn({required this.id});

  final String id;

  static List<_Conn> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((r) => _Conn(id: r['id'].toString()))
        .toList(growable: false);
  }
}
