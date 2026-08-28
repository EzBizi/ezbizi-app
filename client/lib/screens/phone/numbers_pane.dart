import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import 'buy_number_panel.dart';
import 'carrier_settings.dart';

/// Phone › Settings › Numbers — the lines this business answers on.
///
/// ── Why importing comes before buying ────────────────────────────
///
/// Every path into this module assumed a number arrives by being bought. But a
/// company whose Twilio account EzBizi adopted has been answering its lines for
/// years, and none of them had a row here — so a correctly connected account
/// showed an empty Numbers screen, which reads as broken rather than as
/// unfinished. A-1 Chimney had five such lines.
///
/// So the top of this screen is what the account already holds and EzBizi does
/// not know about yet. Importing writes the row AND repoints the carrier at us;
/// a row without the repoint is a line we list and never hear from, which is
/// worse than not listing it.
///
/// ── Why a line is configured here rather than only in Ring groups ─
///
/// Ring groups own "which lines do I answer", because that is where somebody
/// setting up who-rings-whom is already standing. This screen owns the rest of
/// what a line is: its name, whether it records, whether it transcribes, and
/// what happens out of hours. Both write `phone_numbers`; neither is the only
/// way in.
class NumbersPane extends StatefulWidget {
  const NumbersPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<NumbersPane> createState() => _NumbersPaneState();
}

class _NumbersPaneState extends State<NumbersPane> {
  bool _loading = true;
  String? _error;

  List<_Line> _lines = const [];
  List<_Conn> _connections = const [];
  List<_Group> _groups = const [];
  List<_Schedule> _schedules = const [];

  /// Lines Twilio holds that EzBizi has no row for, per connection scope.
  List<_Importable> _importable = const [];
  String? _importNote;
  final Set<String> _picked = <String>{};
  bool _importing = false;

  /// Which connection's account we are looking at. Null until loaded.
  _Conn? _scope;

  _Line? _editing;
  bool _saving = false;
  String? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal => _me.refusal('phone.edit', 'change a line');

  Future<void> _load() async {
    setState(() {
      _loading = _lines.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/numbers'),
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/ring-groups'),
      widget.api.get('/phone/hours'),
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

    final connections = _Conn.listFrom(results[1].body);

    setState(() {
      _lines = _Line.listFrom(results[0].body);
      _connections = connections;
      _groups = _Group.listFrom(results[2].body);
      _schedules = _Schedule.listFrom(results[3].body);
      _scope ??= connections.isEmpty ? null : connections.first;
      _loading = false;
    });

    await _loadImportable();
  }

  /// What the carrier holds that we do not. Kept out of [_load] so switching
  /// place refetches only this, and a Twilio outage leaves the rest on screen.
  Future<void> _loadImportable() async {
    final scope = _scope;

    if (scope == null) {
      setState(() {
        _importable = const [];
        _importNote = null;
      });
      return;
    }

    final response = await widget.api.get(
      '/phone/numbers/importable'
      '?scope_type=${scope.scopeType}&scope_id=${scope.scopeId}',
    );

    if (!mounted) return;

    setState(() {
      _importable = response.ok ? _Importable.listFrom(response.body) : const [];
      _importNote = response.ok
          ? response.body['message'] as String?
          : response.reason;
      _picked.removeWhere((sid) => !_importable.any((i) => i.sid == sid));
    });
  }

  Future<void> _import() async {
    final scope = _scope;
    if (scope == null || _picked.isEmpty) return;

    setState(() {
      _importing = true;
      _formError = null;
    });

    final response = await widget.api.post('/phone/numbers/import', {
      'scope_type': scope.scopeType,
      'scope_id': scope.scopeId,
      'sids': _picked.toList()..sort(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _importing = false;
        _formError = response.reason;
      });
      return;
    }

    _picked.clear();
    setState(() => _importing = false);
    await _load();
  }

  Future<void> _save(_Line line, Map<String, dynamic> body) async {
    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.patch('/phone/numbers/${line.id}', body);

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
      _editing = null;
    });

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
          'No phone account is connected yet. Ask for phone service on the '
          'Connection screen, and lines will be set up here once it is on.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    final editing = _editing;

    if (editing != null) {
      return _LineForm(
        key: ValueKey(editing.id),
        api: widget.api,
        scopeType: _scope?.scopeType ?? '',
        scopeId: _scope?.scopeId ?? '',
        line: editing,
        groups: _groups,
        schedules: _schedules,
        saving: _saving,
        error: _formError,
        onCancel: () => setState(() {
          _editing = null;
          _formError = null;
        }),
        onSave: (body) => _save(editing, body),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('Numbers', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'The lines this business answers on, and what each one does.',
          style: theme.textTheme.bodySmall,
        ),

        if (_connections.length > 1) ...[
          const SizedBox(height: 16),
          _placePicker(theme),
        ],

        if (_formError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_formError!),
        ],

        const SizedBox(height: 20),
        ..._importSection(theme),

        // Importing is free and buying costs money, so what the account
        // already holds is offered first — a company that has been paying
        // for a line for a year should not buy a second one by accident.
        if (_canEdit && _scope != null) ...[
          const SizedBox(height: 24),
          BuyNumberPanel(
            key: ValueKey('buy:${_scope!.id}'),
            api: widget.api,
            scopeType: _scope!.scopeType,
            scopeId: _scope!.scopeId,
            onBought: _load,
          ),
        ],

        const SizedBox(height: 24),
        Text('Set up in EzBizi', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),

        // Said once at the top as well as per row, because the fix is not on
        // this screen: with no ring group in existence there is nothing to
        // pick, so pointing at the field would be a dead end.
        if (_lines.any((line) => !line.answers)) ...[
          _silentLinesNote(theme),
          const SizedBox(height: 12),
        ],

        if (_lines.isEmpty)
          Text(
            'No lines yet. Import the ones this account already holds, or buy '
            'a new one, above.',
            style: theme.textTheme.bodySmall,
          )
        else
          for (final line in _lines) _lineRow(line, theme),
      ],
    );
  }

  Widget _placePicker(ThemeData theme) {
    return Row(
      children: [
        Text('Phone account', style: theme.textTheme.bodySmall),
        const SizedBox(width: 12),
        Expanded(
          child: DropdownButtonFormField<String>(
            initialValue: _scope?.id,
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
            ),
            items: [
              for (final c in _connections)
                DropdownMenuItem(value: c.id, child: Text(c.label)),
            ],
            onChanged: (id) {
              final next = _connections.where((c) => c.id == id).firstOrNull;
              if (next == null) return;
              setState(() => _scope = next);
              _loadImportable();
            },
          ),
        ),
      ],
    );
  }

  List<Widget> _importSection(ThemeData theme) {
    if (_importNote != null && _importable.isEmpty) {
      return [
        Text('Already on this phone account', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        Text(_importNote!, style: theme.textTheme.bodySmall),
      ];
    }

    if (_importable.isEmpty) {
      return [
        Text('Already on this phone account', style: theme.textTheme.labelLarge),
        const SizedBox(height: 6),
        Text(
          'Every line on this phone account is already set up in EzBizi.',
          style: theme.textTheme.bodySmall,
        ),
      ];
    }

    return [
      Text('Already on this phone account', style: theme.textTheme.labelLarge),
      const SizedBox(height: 6),
      Text(
        'These lines belong to this account but EzBizi does not handle them '
        'yet. Importing points the line at EzBizi, so calls to it start '
        'arriving here.',
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 8),

      for (final line in _importable)
        CheckboxListTile(
          key: ValueKey('import:${line.sid}'),
          dense: true,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _picked.contains(line.sid),
          onChanged: _canEdit
              ? (on) => setState(() {
                  if (on == true) {
                    _picked.add(line.sid);
                  } else {
                    _picked.remove(line.sid);
                  }
                })
              : null,
          title: Text(line.heading),
          subtitle: line.answersElsewhere
              ? Text(
                  'This line currently answers somewhere else. Importing it '
                  'sends its calls to EzBizi instead.',
                  style: theme.textTheme.bodySmall,
                )
              : null,
        ),

      const SizedBox(height: 8),

      Align(
        alignment: Alignment.centerLeft,
        child: Refused(
          reason: _canEdit ? null : _editRefusal,
          child: FilledButton(
            onPressed: (!_canEdit || _picked.isEmpty || _importing)
                ? null
                : _import,
            child: Text(
              _importing
                  ? 'Importing…'
                  : _picked.length == 1
                  ? 'Import 1 line'
                  : 'Import ${_picked.length} lines',
            ),
          ),
        ),
      ),
    ];
  }

  /// Why the whole business is missing calls, and what to do about it.
  Widget _silentLinesNote(ThemeData theme) {
    final silent = _lines.where((line) => !line.answers).length;
    final noGroups = _groups.isEmpty;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            Icons.phone_missed_outlined,
            color: theme.colorScheme.onErrorContainer,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  silent == 1
                      ? 'One line is not reaching anybody'
                      : '$silent lines are not reaching anybody',
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: theme.colorScheme.onErrorContainer,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  noGroups
                      ? 'No ring groups exist yet, so there is nobody for a '
                            'call to ring. Make one on the Ring groups screen, '
                            'put the people who answer the phone in it, then '
                            'set it on each line here.'
                      : 'Open each line below and give it a ring group. Until '
                            'then every caller hears voicemail.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onErrorContainer,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _lineRow(_Line line, ThemeData theme) {
    final group = _groups.where((g) => g.id == line.ringGroupId).firstOrNull;

    // A line nobody answers is a fault, not a footnote. This was grey
    // secondary text on all five production lines for three hours and read as
    // a caption — five identical grey sentences on a screen somebody opened
    // to do something else are easy to scan straight past.
    final silent = !line.answers;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        key: ValueKey('line:${line.id}'),
        leading: silent
            ? Icon(Icons.warning_amber_rounded, color: theme.colorScheme.error)
            : null,
        title: Text(line.heading),
        subtitle: Text(
          silent
              ? 'Nobody answers this line. Every call goes straight to '
                    'voicemail — give it a ring group below.'
              : group == null
              ? 'Answered by a call menu.'
              : 'Rings ${group.name}.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: silent ? theme.colorScheme.error : null,
            fontWeight: silent ? FontWeight.w600 : null,
          ),
        ),
        trailing: _canEdit
            ? TextButton(
                onPressed: () => setState(() {
                  _editing = line;
                  _formError = null;
                }),
                child: const Text('Edit'),
              )
            : null,
      ),
    );
  }
}

/// The form for one line. A separate widget so its controllers live and die
/// with the line being edited rather than with the pane.
class _LineForm extends StatefulWidget {
  const _LineForm({
    super.key,
    required this.api,
    required this.scopeType,
    required this.scopeId,
    required this.line,
    required this.groups,
    required this.schedules,
    required this.saving,
    required this.error,
    required this.onCancel,
    required this.onSave,
  });

  final ApiClient api;
  final String scopeType;
  final String scopeId;
  final _Line line;
  final List<_Group> groups;
  final List<_Schedule> schedules;
  final bool saving;
  final String? error;
  final VoidCallback onCancel;
  final void Function(Map<String, dynamic>) onSave;

  @override
  State<_LineForm> createState() => _LineFormState();
}

class _LineFormState extends State<_LineForm> {
  late final TextEditingController _label;
  late final TextEditingController _forwardTo;
  late final TextEditingController _fallback;
  late final TextEditingController _notice;
  late String? _groupId;
  late String? _scheduleId;
  late bool _record;
  late bool _transcribe;
  late String _afterHours;

  /// Carrier fields, populated only once the panel reports a change.
  Map<String, dynamic> _carrier = const {};

  @override
  void initState() {
    super.initState();
    _label = TextEditingController(text: widget.line.label);
    _forwardTo = TextEditingController(text: widget.line.afterHoursNumber ?? '');
    _fallback = TextEditingController(text: widget.line.fallbackNumber ?? '');
    _notice = TextEditingController(text: widget.line.recordingNotice ?? '');
    _groupId = widget.line.ringGroupId;
    _scheduleId = widget.line.hoursScheduleId;
    _record = widget.line.record;
    _transcribe = widget.line.transcribe;
    _afterHours = widget.line.afterHours;
  }

  @override
  void dispose() {
    _label.dispose();
    _forwardTo.dispose();
    _fallback.dispose();
    _notice.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            IconButton(
              tooltip: 'Back to the lines',
              icon: const Icon(Icons.arrow_back),
              onPressed: widget.onCancel,
            ),
            const SizedBox(width: 4),
            Expanded(
              child: Text(widget.line.e164, style: theme.textTheme.titleMedium),
            ),
          ],
        ),

        if (widget.error != null) ...[
          const SizedBox(height: 12),
          ErrorNote(widget.error!),
        ],

        const SizedBox(height: 16),
        TextField(
          controller: _label,
          decoration: const InputDecoration(
            labelText: 'What this line is called',
            border: OutlineInputBorder(),
          ),
        ),

        const SizedBox(height: 16),
        DropdownButtonFormField<String?>(
          initialValue: _groupId,
          decoration: const InputDecoration(
            labelText: 'Who it rings',
            border: OutlineInputBorder(),
          ),
          items: [
            const DropdownMenuItem<String?>(
              value: null,
              child: Text('Nobody — go straight to voicemail'),
            ),
            for (final g in widget.groups)
              DropdownMenuItem<String?>(value: g.id, child: Text(g.name)),
          ],
          onChanged: (v) => setState(() => _groupId = v),
        ),

        const SizedBox(height: 16),
        DropdownButtonFormField<String?>(
          initialValue: _scheduleId,
          decoration: const InputDecoration(
            labelText: 'Opening hours',
            border: OutlineInputBorder(),
          ),
          items: [
            const DropdownMenuItem<String?>(
              value: null,
              child: Text('Always open'),
            ),
            for (final s in widget.schedules)
              DropdownMenuItem<String?>(value: s.id, child: Text(s.name)),
          ],
          onChanged: (v) => setState(() => _scheduleId = v),
        ),

        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          initialValue: _afterHours,
          decoration: const InputDecoration(
            labelText: 'When it is closed',
            border: OutlineInputBorder(),
          ),
          items: const [
            DropdownMenuItem(value: 'voicemail', child: Text('Take a voicemail')),
            DropdownMenuItem(value: 'forward', child: Text('Forward the call')),
            DropdownMenuItem(
              value: 'closed',
              child: Text('Say it is closed and hang up'),
            ),
          ],
          onChanged: (v) => setState(() => _afterHours = v ?? 'voicemail'),
        ),

        if (_afterHours == 'forward') ...[
          const SizedBox(height: 16),
          TextField(
            controller: _forwardTo,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: 'Forward to',
              hintText: '+12035550123',
              border: OutlineInputBorder(),
            ),
          ),
        ],

        const SizedBox(height: 16),
        TextField(
          controller: _fallback,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(
            labelText: 'Fallback number',
            hintText: '+17865550123',
            helperText:
                'Dialled when the steps above reach nobody. Tried once, then '
                'voicemail. Leave empty to go straight to voicemail.',
            helperMaxLines: 3,
            border: OutlineInputBorder(),
          ),
        ),

        const SizedBox(height: 8),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _record,
          onChanged: (v) => setState(() => _record = v),
          title: const Text('Record calls on this line'),
          subtitle: Text(
            'Callers are told out loud before the call connects.',
            style: theme.textTheme.bodySmall,
          ),
        ),
        // Only when recording is on: this sentence is what makes the recording
        // lawful, so it is shown next to the switch that starts it rather than
        // filed away somewhere the person turning recording on will not look.
        if (_record) ...[
          const SizedBox(height: 8),
          TextField(
            controller: _notice,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'What callers are told',
              hintText: 'This call may be recorded.',
              helperText:
                  'Spoken before the call connects. This wording is what gets '
                  'the caller’s consent, so it has to be true where you '
                  'operate. Left empty, callers hear the sentence above.',
              helperMaxLines: 4,
              border: OutlineInputBorder(),
            ),
          ),
        ],

        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _transcribe,
          onChanged: (v) => setState(() => _transcribe = v),
          title: const Text('Write down what is said'),
          subtitle: Text(
            'Costs more per call. Off unless you need it.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        const Divider(height: 32),
        CarrierSettings(
          api: widget.api,
          numberId: widget.line.id,
          scopeType: widget.scopeType,
          scopeId: widget.scopeId,
          callerIdLookup: widget.line.voiceCallerIdLookup,
          voicemailTranscribe: widget.line.voicemailTranscribe,
          emergencyAddressSid: widget.line.emergencyAddressSid,
          onChanged: (body) => _carrier = body,
        ),

        const SizedBox(height: 20),
        Row(
          children: [
            FilledButton(
              onPressed: widget.saving
                  ? null
                  : () => widget.onSave({
                      'label': _label.text.trim(),
                      'ring_group_id': _groupId,
                      'hours_schedule_id': _scheduleId,
                      'record': _record,
                      'transcribe': _transcribe,
                      'after_hours': _afterHours,
                      'after_hours_number': _afterHours == 'forward'
                          ? _forwardTo.text.trim()
                          : null,
                      'fallback_number': _fallback.text.trim(),

                      // Sent even when recording is off, so the wording a
                      // business already chose survives switching it off and
                      // on again.
                      'recording_notice': _notice.text.trim(),

                      // Only what the carrier panel actually reports having
                      // changed — sending an untouched emergency address back
                      // would be a pointless Twilio write on every save.
                      ..._carrier,
                    }),
              child: Text(widget.saving ? 'Saving…' : 'Save'),
            ),
            const SizedBox(width: 12),
            TextButton(onPressed: widget.onCancel, child: const Text('Cancel')),
          ],
        ),
      ],
    );
  }
}

@immutable
class _Line {
  const _Line({
    required this.id,
    required this.e164,
    required this.label,
    required this.record,
    required this.transcribe,
    required this.afterHours,
    required this.answers,
    required this.voiceCallerIdLookup,
    required this.voicemailTranscribe,
    this.afterHoursNumber,
    this.ringGroupId,
    this.hoursScheduleId,
    this.emergencyAddressSid,
    this.fallbackNumber,
    this.recordingNotice,
  });

  final String id;
  final String e164;
  final String label;
  final bool record;
  final bool transcribe;
  final String afterHours;
  final String? afterHoursNumber;
  final String? ringGroupId;
  final String? hoursScheduleId;

  /// Whether a call to this line can reach anybody at all.
  ///
  /// The server computes it from the same condition TwimlRenderer branches on
  /// — no ring group and no call flow means the renderer returns voicemail
  /// before it can emit a Dial. Judging it again here would be a second rule
  /// that could drift from the one that actually answers the phone.
  final bool answers;

  final bool voiceCallerIdLookup;
  final bool voicemailTranscribe;
  final String? emergencyAddressSid;
  final String? fallbackNumber;

  /// Null means the built-in consent sentence, never silence.
  final String? recordingNotice;

  String get heading => label.trim().isEmpty || label == e164
      ? e164
      : '$label · $e164';

  static List<_Line> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data.whereType<Map<String, dynamic>>().map((row) {
      return _Line(
        id: row['id'].toString(),
        e164: row['e164']?.toString() ?? '',
        label: row['label']?.toString() ?? '',
        record: row['record'] == true,
        transcribe: row['transcribe'] == true,
        afterHours: row['after_hours']?.toString() ?? 'voicemail',
        afterHoursNumber: row['after_hours_number'] as String?,
        ringGroupId: row['ring_group_id'] as String?,
        hoursScheduleId: row['hours_schedule_id'] as String?,

        // The server decides this, from the same condition the call renderer
        // branches on. Only when it does not say — an older server — is it
        // worked out here, and then by that same rule rather than by assuming
        // the line is fine: assuming fine is how this went unnoticed before.
        answers:
            row['answers'] as bool? ??
            (row['ring_group_id'] != null || row['flow_id'] != null),
        voiceCallerIdLookup: row['voice_caller_id_lookup'] == true,
        voicemailTranscribe: row['voicemail_transcribe'] == true,
        emergencyAddressSid: row['emergency_address_sid'] as String?,
        fallbackNumber: row['fallback_number'] as String?,
        recordingNotice: row['recording_notice'] as String?,
      );
    }).toList(growable: false);
  }
}

@immutable
class _Importable {
  const _Importable({
    required this.sid,
    required this.e164,
    required this.label,
    required this.answersElsewhere,
  });

  final String sid;
  final String e164;
  final String label;
  final bool answersElsewhere;

  String get heading =>
      label.trim().isEmpty || label == e164 ? e164 : '$label · $e164';

  static List<_Importable> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data.whereType<Map<String, dynamic>>().map((row) {
      return _Importable(
        sid: row['sid'].toString(),
        e164: row['e164']?.toString() ?? '',
        label: row['label']?.toString() ?? '',
        answersElsewhere: row['answers_elsewhere'] == true,
      );
    }).toList(growable: false);
  }
}

@immutable
class _Conn {
  const _Conn({
    required this.id,
    required this.label,
    required this.scopeType,
    required this.scopeId,
  });

  final String id;
  final String label;
  final String scopeType;
  final String scopeId;

  static List<_Conn> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data.whereType<Map<String, dynamic>>().map((row) {
      return _Conn(
        id: row['id'].toString(),
        label: row['scope_name']?.toString() ?? row['scope_type']?.toString() ?? 'This place',
        scopeType: row['scope_type']?.toString() ?? 'company',
        scopeId: row['scope_id']?.toString() ?? '',
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
class _Schedule {
  const _Schedule({required this.id, required this.name});

  final String id;
  final String name;

  static List<_Schedule> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((r) => _Schedule(id: r['id'].toString(), name: r['name']?.toString() ?? 'Hours'))
        .toList(growable: false);
  }
}
