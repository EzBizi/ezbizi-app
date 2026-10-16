import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../screens/phone/recording_player.dart';
import 'search_picker.dart';
import 'side_panel.dart';

/// Edit a lead where it is read (UI queue 103, design 1): its name, ZIP,
/// classification and dispatcher, Re-classify, and every conversation this
/// caller has had across all their leads. The phone stays read-only.
///
/// Returns the lead as the server now holds it, or null when nothing was
/// saved.
Future<Map<String, dynamic>?> showLeadEditPanel(
  BuildContext context, {
  required ApiClient api,
  required Map<String, dynamic> lead,
  RecordingPlayer? player,
}) => showSidePanel<Map<String, dynamic>>(
  context,
  builder: (panel) => _LeadEditPanel(api: api, lead: lead, player: player),
);

const _classes = {'BOOK': 'Book', 'SUPPORT': 'Support', 'ARCHIVE': 'Archive'};

class _LeadEditPanel extends StatefulWidget {
  const _LeadEditPanel({required this.api, required this.lead, this.player});

  final ApiClient api;
  final Map<String, dynamic> lead;
  final RecordingPlayer? player;

  @override
  State<_LeadEditPanel> createState() => _LeadEditPanelState();
}

class _LeadEditPanelState extends State<_LeadEditPanel> {
  late final _name = TextEditingController(
    text: widget.lead['contact_name'] as String? ?? '',
  );
  late final _zip = TextEditingController(
    text: widget.lead['postal_code'] as String? ?? '',
  );
  late final _dispatcher = TextEditingController(
    text: widget.lead['dispatcher'] as String? ?? '',
  );
  late String? _class = (widget.lead['classification'] as String?)
      ?.toUpperCase();
  late final RecordingPlayer _player = widget.player ?? createRecordingPlayer();

  bool _busy = false;
  String? _message;
  List<Map<String, dynamic>>? _timeline;

  String get _id => '${widget.lead['id']}';

  @override
  void initState() {
    super.initState();
    _loadTimeline();
  }

  @override
  void dispose() {
    _name.dispose();
    _zip.dispose();
    _dispatcher.dispose();
    if (widget.player == null) _player.dispose();
    super.dispose();
  }

  Future<void> _loadTimeline() async {
    final response = await widget.api.get('/leads/$_id/timeline');
    if (!mounted) return;
    setState(
      () => _timeline = response.ok && response.data is List
          ? (response.data as List).whereType<Map<String, dynamic>>().toList()
          : const [],
    );
  }

  Future<void> _save({bool backToRingCentral = false}) async {
    setState(() {
      _busy = true;
      _message = null;
    });
    final response = await widget.api.patch('/leads/$_id', {
      'contact_name': _name.text.trim(),
      'postal_code': _zip.text.trim(),
      'classification': ?_class,
      // Blank hands the dispatcher back to RingCentral.
      'dispatcher': backToRingCentral ? '' : _dispatcher.text.trim(),
    });
    if (!mounted) return;
    setState(() => _busy = false);
    if (!response.ok) {
      setState(() => _message = response.message);
      return;
    }
    final data = response.data;
    Navigator.of(context).pop(data is Map<String, dynamic> ? data : null);
  }

  Future<void> _reclassify() async {
    setState(() {
      _busy = true;
      _message = null;
    });
    final response = await widget.api.post('/leads/$_id/reclassify', const {});
    if (!mounted) return;
    setState(() {
      _busy = false;
      _message = response.ok
          ? 'AI is reading it again. The new classification shows on the '
                'next load.'
          : response.message;
    });
  }

  Future<void> _play(Map<String, dynamic> row) async {
    final bytes = await widget.api.getBytes(
      '/leads/${row['lead_id']}/conversations/${row['id']}/recording',
    );
    if (!mounted || bytes == null) return;
    try {
      await _player.play(bytes);
    } on RecordingPlaybackFailure catch (e) {
      if (mounted) setState(() => _message = e.message);
    }
  }

  String _when(String? iso) {
    final at = DateTime.tryParse(iso ?? '')?.toLocal();
    if (at == null) return '';
    final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
    return '${at.month}/${at.day}/${at.year} $h:${'${at.minute}'.padLeft(2, '0')} '
        '${at.hour < 12 ? 'AM' : 'PM'}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final small = theme.textTheme.bodySmall;
    final overridden = widget.lead['dispatcher_overridden'] == true;

    return SidePanel(
      title: 'Edit lead',
      subtitle: '${widget.lead['phone'] ?? ''} · the phone is not changed here',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('lead-edit-save'),
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('lead-edit-name'),
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name'),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('lead-edit-zip'),
            controller: _zip,
            decoration: const InputDecoration(labelText: 'ZIP'),
          ),
          const SizedBox(height: 10),
          SearchPickerField<String?>(
            key: const ValueKey('lead-edit-class'),
            value: _class,
            label: 'Classification',
            options: [
              const SearchOption(value: null, label: 'Not classified'),
              for (final e in _classes.entries)
                SearchOption(value: e.key, label: e.value),
            ],
            onChanged: (v) => setState(() => _class = v),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('lead-edit-dispatcher'),
            controller: _dispatcher,
            decoration: InputDecoration(
              labelText: 'Dispatcher',
              helperText: overridden
                  ? 'Set by hand'
                  : 'From RingCentral, who answered the call',
            ),
          ),
          if (overridden)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                key: const ValueKey('lead-edit-dispatcher-reset'),
                onPressed: _busy ? null : () => _save(backToRingCentral: true),
                child: const Text('Back to RingCentral'),
              ),
            ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              key: const ValueKey('lead-edit-reclassify'),
              onPressed: _busy ? null : _reclassify,
              icon: const Icon(Icons.auto_awesome, size: 18),
              label: const Text('Re-classify'),
            ),
          ),
          if (_message != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(_message!, style: small),
            ),
          const SizedBox(height: 18),
          Text(
            "THIS CALLER'S HISTORY",
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.6,
            ),
          ),
          const SizedBox(height: 6),
          if (_timeline == null)
            const LinearProgressIndicator()
          else if (_timeline!.isEmpty)
            Text('Nothing else from this number.', style: small)
          else
            for (final row in _timeline!)
              ListTile(
                key: ValueKey('lead-timeline-${row['id']}'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  row['has_recording'] == true
                      ? Icons.call_outlined
                      : Icons.chat_bubble_outline,
                  size: 18,
                ),
                title: Text(
                  [
                    _when(
                      (row['at'] ?? row['occurred_at'] ?? row['created_at'])
                          as String?,
                    ),
                    '${row['source'] ?? ''}',
                    if (row['lead_id'] != widget.lead['id']) 'another lead',
                  ].where((s) => s.isNotEmpty).join(' · '),
                ),
                trailing: row['has_recording'] == true
                    ? IconButton(
                        tooltip: 'Play the recording',
                        icon: const Icon(Icons.play_arrow),
                        onPressed: () => _play(row),
                      )
                    : null,
              ),
        ],
      ),
    );
  }
}
