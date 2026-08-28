import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';

/// Phone › Settings › Call flows — the menu a caller hears.
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
  const FlowsPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<FlowsPane> createState() => _FlowsPaneState();
}

class _FlowsPaneState extends State<FlowsPane> {
  static const _actions = <(String, String)>[
    ('ring_group', 'Ring a group'),
    ('voicemail', 'Take a message'),
    ('forward', 'Forward the call'),
    ('hangup', 'Say something and hang up'),
  ];

  bool _loading = true;
  String? _error;

  List<_Flow> _flows = const [];
  List<_Group> _groups = const [];
  List<_Conn> _connections = const [];

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
    ]);

    if (!mounted) return;

    final failed = results.firstWhere((r) => !r.ok, orElse: () => results.first);

    if (!failed.ok) {
      setState(() {
        _loading = false;
        _error = failed.reason;
      });
      return;
    }

    setState(() {
      _flows = _Flow.listFrom(results[0].body);
      _groups = _Group.listFrom(results[1].body);
      _connections = _Conn.listFrom(results[2].body);
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
    if (draft.options.any((o) => o.action == 'ring_group' && (o.groupId ?? '').isEmpty)) {
      setState(() => _formError = 'Say which group each ringing key reaches.');
      return;
    }

    if (draft.options.any((o) => o.action == 'forward' && o.number.trim().isEmpty)) {
      setState(() => _formError = 'Say where each forwarding key sends the call.');
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final body = <String, dynamic>{
      'name': draft.name.trim(),
      'prompt': draft.prompt.trim(),
      'options': [
        for (final option in draft.options)
          {
            'digit': option.digit,
            'action': option.action,
            if (option.action == 'ring_group') 'ring_group_id': option.groupId,
            if (option.action == 'forward') 'number': option.number.trim(),
            if (option.action == 'hangup') 'say': option.say.trim(),
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
        Text('Call flows', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'What a caller hears before anybody picks up. A line with no menu '
          'rings its group straight away.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 16),

        Align(
          alignment: Alignment.centerLeft,
          child: Refused(
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
        ),

        const SizedBox(height: 20),

        if (_flows.isEmpty)
          Text(
            'No menus yet. Every line rings its group as soon as it is '
            'answered.',
            style: theme.textTheme.bodySmall,
          )
        else
          for (final flow in _flows)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                key: ValueKey('flow:${flow.id}'),
                title: Text(flow.name),
                subtitle: Text(
                  '${flow.options.length} '
                  '${flow.options.length == 1 ? 'key' : 'keys'}'
                  '${flow.numberIds.isEmpty ? ' · not on any line yet' : ' · on ${flow.numberIds.length} line(s)'}',
                  style: theme.textTheme.bodySmall,
                ),
                trailing: _canEdit
                    ? Row(
                        mainAxisSize: MainAxisSize.min,
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
                    : null,
              ),
            ),
      ],
    );
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

        if (_formError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_formError!),
        ],

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

        const SizedBox(height: 20),
        Text('What each key does', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(
          'A key you do not list, or no key at all, rings the line as usual. '
          'Nobody is ever hung up on for pressing the wrong thing.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),

        for (var i = 0; i < draft.options.length; i++) _optionRow(draft, i, theme),

        if (draft.options.length < 10)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              icon: const Icon(Icons.add),
              label: const Text('Add a key'),
              onPressed: () => setState(() => draft.options.add(_OptionDraft.blank())),
            ),
          ),

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
                  child: DropdownButtonFormField<String>(
                    key: ValueKey('digit:$index'),
                    initialValue: option.digit,
                    decoration: const InputDecoration(
                      labelText: 'Key',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    items: [
                      for (final d in const [
                        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '#',
                      ])
                        DropdownMenuItem(value: d, child: Text(d)),
                    ],
                    onChanged: (v) => setState(() => option.digit = v ?? '1'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    key: ValueKey('action:$index'),
                    initialValue: option.action,
                    decoration: const InputDecoration(
                      labelText: 'Does what',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    items: [
                      for (final (value, label) in _actions)
                        DropdownMenuItem(value: value, child: Text(label)),
                    ],
                    onChanged: (v) => setState(() => option.action = v ?? 'voicemail'),
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
                  constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
                  onPressed: () => setState(() => draft.options.removeAt(index)),
                ),
              ],
            ),

            if (option.action == 'ring_group') ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                key: ValueKey('group:$index'),
                initialValue: _groups.any((g) => g.id == option.groupId)
                    ? option.groupId
                    : null,
                decoration: const InputDecoration(
                  labelText: 'Which group',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                items: [
                  for (final g in _groups)
                    DropdownMenuItem(value: g.id, child: Text(g.name)),
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
  });

  factory _OptionDraft.blank() =>
      _OptionDraft(digit: '1', action: 'ring_group');

  String digit;
  String action;
  String? groupId;
  String number;
  String say;
}

class _Draft {
  _Draft({
    required this.id,
    required this.connectionId,
    required this.name,
    required this.prompt,
    required this.options,
  });

  final String? id;
  final String connectionId;
  String name;
  String prompt;
  final List<_OptionDraft> options;

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
    options: [
      for (final option in flow.options)
        _OptionDraft(
          digit: option['digit']?.toString() ?? '1',
          action: option['action']?.toString() ?? 'ring_group',
          groupId: option['ring_group_id']?.toString(),
          number: option['number']?.toString() ?? '',
          say: option['say']?.toString() ?? '',
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
  });

  final String id;
  final String connectionId;
  final String name;
  final String prompt;
  final List<Map<String, dynamic>> options;
  final List<String> numberIds;

  static List<_Flow> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data.whereType<Map<String, dynamic>>().map((row) {
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
      );
    }).toList(growable: false);
  }
}

@immutable
class _Group {
  const _Group({required this.id, required this.name});

  final String id;
  final String name;

  static List<_Group> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((r) => _Group(id: r['id'].toString(), name: r['name']?.toString() ?? 'Group'))
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
