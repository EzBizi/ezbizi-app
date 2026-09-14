import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';

/// Phone › Settings › General — what answers a call, set in one place.
///
/// ── Why this is separate from Call flow ──────────────────────────
///
/// Call flow READS: it says what happens today, in the order the renderer
/// decides it, and it is deliberately not editable — a screen that both
/// reports and edits tends to report what you just typed rather than what the
/// system will do.
///
/// This one WRITES. Same four questions, asked in the same order, because the
/// order is the behaviour: hours first, then the menu, then who rings, then
/// what happens out of hours. Setting them anywhere else meant opening a line
/// under Numbers and knowing that "Edit" was where call routing lived.
///
/// ── Per line, not per company ────────────────────────────────────
///
/// The reference product settles this once for the whole business. EzBizi
/// hangs it on the number, which is more capable — a sales line and a service
/// line can ring different people — so the line picker is the price of that,
/// and the panel below it is otherwise the same panel.
class GeneralPane extends StatefulWidget {
  const GeneralPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<GeneralPane> createState() => _GeneralPaneState();
}

class _GeneralPaneState extends State<GeneralPane> {
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _saved;

  List<_Company> _companies = const [];
  List<_Named> _groups = const [];
  List<_Named> _schedules = const [];
  List<_Named> _menus = const [];
  List<_Named> _boxes = const [];

  String? _companyId;

  // The company being edited, held as fields so a half-made change is not
  // lost when the list refreshes underneath it.
  String? _scheduleId;
  String? _menuId;
  String? _groupId;
  String? _boxId;
  String _afterHours = 'voicemail';
  bool _record = false;
  bool _transcribe = false;
  bool _voicemailTranscribe = false;
  final _forwardTo = TextEditingController();
  final _fallback = TextEditingController();
  final _notice = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_forwardTo, _fallback, _notice]) {
      c.dispose();
    }
    super.dispose();
  }

  bool get _canEdit => widget.session.me?.can('phone.edit') ?? false;

  Future<void> _load() async {
    setState(() {
      _loading = _companies.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/connections'),
      widget.api.get('/phone/ring-groups'),
      widget.api.get('/phone/hours'),
      widget.api.get('/phone/flows'),
      widget.api.get('/phone/voicemail-boxes'),
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

    final companies = _Company.listFrom(results[0].body);

    setState(() {
      _loading = false;
      _companies = companies;
      _groups = _Named.listFrom(results[1].body);
      _schedules = _Named.listFrom(results[2].body);
      _menus = _Named.listFrom(results[3].body);
      _boxes = _Named.listFrom(results[4].body);
      _companyId ??= companies.isEmpty ? null : companies.first.id;
    });

    _adopt();
  }

  /// Load the chosen company's settings into the fields.
  void _adopt() {
    final company = _companies.where((c) => c.id == _companyId).firstOrNull;
    if (company == null) return;

    setState(() {
      _scheduleId = company.hoursScheduleId;
      _menuId = company.flowId;
      _groupId = company.ringGroupId;
      _boxId = company.voicemailBoxId;
      _afterHours = company.afterHours;
      _record = company.record;
      _transcribe = company.transcribe;
      _voicemailTranscribe = company.voicemailTranscribe;
      _forwardTo.text = company.afterHoursNumber ?? '';
      _fallback.text = company.fallbackNumber ?? '';
      _notice.text = company.recordingNotice ?? '';
      _saved = null;
    });
  }

  Future<void> _save() async {
    final id = _companyId;
    if (id == null) return;

    setState(() {
      _saving = true;
      _error = null;
      _saved = null;
    });

    final response = await widget.api.patch('/phone/connections/$id', {
      'default_hours_schedule_id': _scheduleId,
      'default_flow_id': _menuId,
      'default_ring_group_id': _groupId,
      'default_voicemail_box_id': _boxId,
      'default_after_hours': _afterHours,
      'default_after_hours_number': _afterHours == 'forward'
          ? _forwardTo.text.trim()
          : null,
      'default_fallback_number': _fallback.text.trim(),
      'default_record': _record,
      'default_recording_notice': _notice.text.trim(),
      'default_transcribe': _transcribe,
      'default_voicemail_transcribe': _voicemailTranscribe,
    });

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
      _saved = 'Saved. Every line on this company answers this way unless it '
          'says otherwise. Call flow shows the result.';
    });

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null && _companies.isEmpty) {
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

    if (_companies.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No phone account yet. Connect one under Connection, and what it '
          'does with a call is set here.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text('General', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'What answers a call, and what it does on the way — for every line '
          'this company has. A line can still be given its own answer, but it '
          'starts from here rather than from nothing. Call flow shows the '
          'result.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 16),

        // A company picker, and only when there is more than one.
        //
        // This used to be a LINE picker, which is what made General read as a
        // per-number editor: everything under it belonged to whichever number
        // was chosen, so "what happens when somebody rings us" had to be set
        // once per line and the next number bought answered differently from
        // all of them. Under a single company there is nothing to choose, so
        // the company is stated rather than offered — a dropdown with one
        // entry is a decision nobody is making.
        if (_companies.length > 1)
          DropdownButtonFormField<String>(
            initialValue: _companyId,
            isExpanded: true,
            decoration: const InputDecoration(
              labelText: 'Company',
              border: OutlineInputBorder(),
              helperText: 'These settings apply to every line this company '
                  'has.',
            ),
            items: [
              for (final company in _companies)
                DropdownMenuItem(value: company.id, child: Text(company.name)),
            ],
            onChanged: (id) {
              setState(() => _companyId = id);
              _adopt();
            },
          )
        else if (_companies.isNotEmpty)
          Text(
            'These settings apply to every line ${_companies.first.name} has.',
            style: theme.textTheme.bodySmall,
          ),

        const Divider(height: 32),
        Text('What answers a call', style: theme.textTheme.labelLarge),
        const SizedBox(height: 2),
        Text(
          'Checked in this order — the first usable one wins.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 16),
        _pick(
          label: '1. Business hours',
          value: _scheduleId,
          items: _schedules,
          none: 'Always open',
          helper: 'When it says CLOSED the call takes the out-of-hours route '
              'below and stops.',
          onChanged: (v) => setState(() => _scheduleId = v),
        ),

        const SizedBox(height: 16),
        _pick(
          label: '2. IVR menu',
          value: _menuId,
          items: _menus,
          none: 'No menu — go straight to whoever answers',
          helper: 'Skipped automatically if the menu has no usable step.',
          onChanged: (v) => setState(() => _menuId = v),
        ),

        const SizedBox(height: 16),
        _pick(
          label: '3. Ring group',
          value: _groupId,
          items: _groups,
          none: 'Nobody — every call goes to voicemail',
          helper: 'Who rings when no menu key answers the call first.',
          onChanged: (v) => setState(() => _groupId = v),
        ),

        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          initialValue: _afterHours,
          decoration: const InputDecoration(
            labelText: '4. Out of hours',
            border: OutlineInputBorder(),
            helperText: 'Used by the CLOSED branch above.',
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

        const SizedBox(height: 16),
        _pick(
          label: 'Voicemail greeting',
          value: _boxId,
          items: _boxes,
          none: 'Built-in — “Please leave a message after the tone.”',
          helper: 'What a caller hears when nobody answers. Mailboxes are '
              'written on the Voicemail screen.',
          onChanged: (v) => setState(() => _boxId = v),
        ),

        if (_afterHours == 'forward') ...[
          const SizedBox(height: 16),
          TextField(
            controller: _forwardTo,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: 'Forward to',
              hintText: '+17865550123',
              border: OutlineInputBorder(),
            ),
          ),
        ],

        const Divider(height: 32),
        Text('Fallback and recording', style: theme.textTheme.labelLarge),

        const SizedBox(height: 16),
        TextField(
          controller: _fallback,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(
            labelText: 'Fallback number',
            hintText: '+17865550123',
            helperText: 'Dialled when the steps above reach nobody and the '
                'line is open. Tried once, then voicemail.',
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
            'The announcement below is what gets the caller’s consent, so it '
            'is spoken before the call connects.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        if (_record) ...[
          TextField(
            controller: _notice,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'What callers are told',
              hintText: 'This call may be recorded.',
              helperText: 'Left empty, callers hear the sentence above.',
              helperMaxLines: 2,
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 8),
        ],

        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _transcribe,
          onChanged: (v) => setState(() => _transcribe = v),
          title: const Text('Write down what is said'),
          subtitle: Text(
            'Twilio bills live transcription per minute, so it stays off '
            'until you turn it on.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        // ── ONE SWITCH FOR EVERY LINE, which is the whole point ──
        //
        // Voicemail transcription existed already and only per line. Measured
        // 2026-09-14: thirteen numbers, every one of them off, 0 of 92
        // voicemails ever transcribed — because turning it on meant thirteen
        // visits to thirteen line settings, so nobody ever did it once.
        //
        // Jose: "we should be able to turn them all on or all off at the same
        // time, not per line."
        //
        // A company default set here beats a line's own value rather than
        // losing to it, which is deliberate and is what "all on or all off"
        // means — a line cannot hold itself off against the company. That is
        // how `record` and live transcription have always behaved, so this is
        // the existing rule applied to the one flag that had been left out of
        // it rather than a new rule.
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _voicemailTranscribe,
          onChanged: (v) => setState(() => _voicemailTranscribe = v),
          title: const Text('Write down voicemail messages'),
          subtitle: Text(
            'Every line on this account. Twilio bills per message '
            'transcribed, so it stays off until you turn it on.',
            style: theme.textTheme.bodySmall,
          ),
        ),

        if (_error != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_error!),
        ],

        if (_saved != null) ...[
          const SizedBox(height: 16),
          Text(_saved!, style: theme.textTheme.bodySmall),
        ],

        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: _canEdit && !_saving ? _save : null,
            child: Text(_saving ? 'Saving…' : 'Save'),
          ),
        ),

        if (!_canEdit) ...[
          const SizedBox(height: 8),
          Text(
            'You can see how calls are answered, but not change it.',
            style: theme.textTheme.bodySmall,
          ),
        ],
      ],
    );
  }

  Widget _pick({
    required String label,
    required String? value,
    required List<_Named> items,
    required String none,
    required String helper,
    required ValueChanged<String?> onChanged,
  }) {
    // Only offer a value the list actually holds: a stale id would make the
    // dropdown throw rather than fall back to None.
    final selected = items.any((i) => i.id == value) ? value : null;

    return DropdownButtonFormField<String?>(
      initialValue: selected,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: label,
        helperText: helper,
        helperMaxLines: 3,
        border: const OutlineInputBorder(),
      ),
      items: [
        DropdownMenuItem<String?>(value: null, child: Text(none)),
        for (final item in items)
          DropdownMenuItem<String?>(value: item.id, child: Text(item.name)),
      ],
      onChanged: onChanged,
    );
  }
}

class _Named {
  const _Named(this.id, this.name);

  final String id;
  final String name;

  static List<_Named> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Named(
          row['id'].toString(),
          (row['name'] ?? row['label'] ?? '—').toString(),
        ),
    ];
  }
}

/// One company's phone account, and how it answers a call.
///
/// Reads the `default_*` fields off the connection. There is exactly one
/// connection per company — the table is unique on (organization, scope_type,
/// scope_id) — which is what makes this the company row rather than a table of
/// its own.
class _Company {
  const _Company({
    required this.id,
    required this.name,
    required this.afterHours,
    required this.record,
    required this.transcribe,
    required this.voicemailTranscribe,
    this.ringGroupId,
    this.hoursScheduleId,
    this.flowId,
    this.voicemailBoxId,
    this.afterHoursNumber,
    this.fallbackNumber,
    this.recordingNotice,
  });

  final String id;
  final String name;
  final String afterHours;
  final bool record;
  final bool transcribe;
  final bool voicemailTranscribe;
  final String? ringGroupId;
  final String? hoursScheduleId;
  final String? flowId;
  final String? voicemailBoxId;
  final String? afterHoursNumber;
  final String? fallbackNumber;
  final String? recordingNotice;

  static List<_Company> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Company(
          id: row['id'].toString(),
          // scope_name is what the place is CALLED. Without it three
          // connections all read "company" and there is no way to tell which
          // is which — which is exactly the bug that put this field on the
          // row in the first place.
          name: (row['scope_name'] ?? 'This company').toString(),
          afterHours: (row['default_after_hours'] ?? 'voicemail').toString(),
          record: row['default_record'] == true,
          transcribe: row['default_transcribe'] == true,
          voicemailTranscribe: row['default_voicemail_transcribe'] == true,
          ringGroupId: row['default_ring_group_id'] as String?,
          hoursScheduleId: row['default_hours_schedule_id'] as String?,
          flowId: row['default_flow_id'] as String?,
          voicemailBoxId: row['default_voicemail_box_id'] as String?,
          afterHoursNumber: row['default_after_hours_number'] as String?,
          fallbackNumber: row['default_fallback_number'] as String?,
          recordingNotice: row['default_recording_notice'] as String?,
        ),
    ];
  }
}
