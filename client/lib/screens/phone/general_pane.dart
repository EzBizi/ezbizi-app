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

  List<_Line> _lines = const [];
  List<_Named> _groups = const [];
  List<_Named> _schedules = const [];
  List<_Named> _menus = const [];
  List<_Named> _boxes = const [];

  String? _lineId;

  // The line being edited, held as fields so a half-made change is not lost
  // when the list refreshes underneath it.
  String? _scheduleId;
  String? _menuId;
  String? _groupId;
  String? _boxId;
  String _afterHours = 'voicemail';
  bool _record = false;
  bool _transcribe = false;
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
      _loading = _lines.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/phone/numbers'),
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

    final lines = _Line.listFrom(results[0].body);

    setState(() {
      _loading = false;
      _lines = lines;
      _groups = _Named.listFrom(results[1].body);
      _schedules = _Named.listFrom(results[2].body);
      _menus = _Named.listFrom(results[3].body);
      _boxes = _Named.listFrom(results[4].body);
      _lineId ??= lines.isEmpty ? null : lines.first.id;
    });

    _adopt();
  }

  /// Load the chosen line's settings into the fields.
  void _adopt() {
    final line = _lines.where((l) => l.id == _lineId).firstOrNull;
    if (line == null) return;

    setState(() {
      _scheduleId = line.hoursScheduleId;
      _menuId = line.flowId;
      _groupId = line.ringGroupId;
      _boxId = line.voicemailBoxId;
      _afterHours = line.afterHours;
      _record = line.record;
      _transcribe = line.transcribe;
      _forwardTo.text = line.afterHoursNumber ?? '';
      _fallback.text = line.fallbackNumber ?? '';
      _notice.text = line.recordingNotice ?? '';
      _saved = null;
    });
  }

  Future<void> _save() async {
    final id = _lineId;
    if (id == null) return;

    setState(() {
      _saving = true;
      _error = null;
      _saved = null;
    });

    final response = await widget.api.patch('/phone/numbers/$id', {
      'hours_schedule_id': _scheduleId,
      'flow_id': _menuId,
      'ring_group_id': _groupId,
      'voicemail_box_id': _boxId,
      'after_hours': _afterHours,
      'after_hours_number': _afterHours == 'forward' ? _forwardTo.text.trim() : null,
      'fallback_number': _fallback.text.trim(),
      'record': _record,
      'recording_notice': _notice.text.trim(),
      'transcribe': _transcribe,
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
      _saved = 'Saved. Call flow will show the new order.';
    });

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null && _lines.isEmpty) {
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

    if (_lines.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'No lines yet. Import or buy one on the Numbers screen, and what it '
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
          'What answers a call, and what it does on the way. Call flow shows '
          'the result of these settings.',
          style: theme.textTheme.bodySmall,
        ),

        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          initialValue: _lineId,
          isExpanded: true,
          decoration: const InputDecoration(
            labelText: 'Line',
            border: OutlineInputBorder(),
          ),
          items: [
            for (final line in _lines)
              DropdownMenuItem(value: line.id, child: Text(line.heading)),
          ],
          onChanged: (id) {
            setState(() => _lineId = id);
            _adopt();
          },
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

class _Line {
  const _Line({
    required this.id,
    required this.e164,
    required this.label,
    required this.afterHours,
    required this.record,
    required this.transcribe,
    this.ringGroupId,
    this.hoursScheduleId,
    this.flowId,
    this.voicemailBoxId,
    this.afterHoursNumber,
    this.fallbackNumber,
    this.recordingNotice,
  });

  final String id;
  final String e164;
  final String label;
  final String afterHours;
  final bool record;
  final bool transcribe;
  final String? ringGroupId;
  final String? hoursScheduleId;
  final String? flowId;
  final String? voicemailBoxId;
  final String? afterHoursNumber;
  final String? fallbackNumber;
  final String? recordingNotice;

  String get heading =>
      label.trim().isEmpty || label == e164 ? e164 : '$label · $e164';

  static List<_Line> listFrom(Map<String, dynamic> body) {
    final rows = body['data'];
    if (rows is! List) return const [];

    return [
      for (final row in rows.whereType<Map<String, dynamic>>())
        _Line(
          id: row['id'].toString(),
          e164: (row['e164'] ?? '').toString(),
          label: (row['label'] ?? '').toString(),
          afterHours: (row['after_hours'] ?? 'voicemail').toString(),
          record: row['record'] == true,
          transcribe: row['transcribe'] == true,
          ringGroupId: row['ring_group_id'] as String?,
          hoursScheduleId: row['hours_schedule_id'] as String?,
          flowId: row['flow_id'] as String?,
          voicemailBoxId: row['voicemail_box_id'] as String?,
          afterHoursNumber: row['after_hours_number'] as String?,
          fallbackNumber: row['fallback_number'] as String?,
          recordingNotice: row['recording_notice'] as String?,
        ),
    ];
  }
}
