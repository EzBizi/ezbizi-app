import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/formatted_notes.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

/// "Report wrong ZIP" (Nelly Escobar, suggestion 01a20e5c, Jose 11-24):
/// wherever Scheduling resolves a ZIP to an area, a flag that sends it to
/// Settings > Wrong ZIPs for somebody who edits areas.
class ReportWrongZipButton extends StatelessWidget {
  const ReportWrongZipButton({
    super.key,
    required this.api,
    required this.zip,
    this.areaId,
    this.areaName,
    this.compact = false,
  });

  final ApiClient api;
  final String zip;
  final String? areaId;
  final String? areaName;

  /// An icon with a tooltip, for a crowded header.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    void open() => showSidePanel<void>(
      context,
      builder: (_) =>
          _ReportDialog(api: api, zip: zip, areaId: areaId, areaName: areaName),
    );
    return compact
        ? IconButton(
            key: Key('report-wrong-zip-$zip'),
            tooltip: 'Report wrong ZIP',
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.flag_outlined),
            onPressed: open,
          )
        : TextButton.icon(
            key: Key('report-wrong-zip-$zip'),
            icon: const Icon(Icons.flag_outlined, size: 18),
            label: const Text('Report wrong ZIP'),
            onPressed: open,
          );
  }
}

/// Pulls the first five-digit ZIP out of whatever was typed.
String? zipIn(String? text) => RegExp(
  r'\b(\d{5})(?:-\d{4})?\b',
).allMatches(text ?? '').lastOrNull?.group(1);

class _ReportDialog extends StatefulWidget {
  const _ReportDialog({
    required this.api,
    required this.zip,
    this.areaId,
    this.areaName,
  });

  final ApiClient api;
  final String zip;
  final String? areaId;
  final String? areaName;

  @override
  State<_ReportDialog> createState() => _ReportDialogState();
}

class _ReportDialogState extends State<_ReportDialog> {
  final _note = FormattedNotesController();
  List<Map<String, dynamic>> _areas = const [];
  String _should = '';
  String? _error;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    widget.api.get('/scheduling/areas').then((r) {
      if (!mounted || !r.ok) return;
      setState(
        () => _areas = [
          for (final a in (r.body['data'] as List? ?? const []))
            if (a is Map && '${a['id']}' != widget.areaId)
              Map<String, dynamic>.from(a),
        ],
      );
    });
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _sending = true);
    final r = await widget.api.post('/scheduling/zip-reports', {
      'zip': widget.zip,
      if (widget.areaId != null) 'reported_area_id': widget.areaId,
      if (_should.isNotEmpty) 'suggested_area_id': _should,
      if (_note.markup.trim().isNotEmpty) 'note': _note.markup.trim(),
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _sending = false;
        _error = r.reason;
      });
      return;
    }
    final messenger = ScaffoldMessenger.maybeOf(context);
    Navigator.of(context).pop();
    messenger?.showSnackBar(
      SnackBar(content: Text('Reported ${widget.zip}. An admin will fix it.')),
    );
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    title: 'Report wrong ZIP ${widget.zip}',
    onClose: () => Navigator.of(context).pop(),
    actions: [
      FilledButton(
        key: const Key('report-zip-send'),
        onPressed: _sending ? null : _send,
        child: const Text('Report'),
      ),
    ],
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            widget.areaName == null
                ? 'No area covers it now.'
                : 'It shows up in ${widget.areaName}.',
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            key: const Key('report-zip-should-be'),
            label: 'Should be in (optional)',
            value: _should,
            options: [
              const SearchOption(value: '', label: "I don't know"),
              for (final a in _areas)
                SearchOption(value: '${a['id']}', label: '${a['name']}'),
            ],
            onChanged: (v) => setState(() => _should = v ?? ''),
          ),
          const SizedBox(height: 12),
          FormattedNotesField(
            fieldKey: const Key('report-zip-note'),
            controller: _note,
            maxLength: 500,
            label: 'Note (optional)',
            minLines: 1,
          ),
          if (_error != null) ErrorNote(_error!),
        ],
      ),
    ),
  );
}

/// Scheduling > Settings > Wrong ZIPs: each open report, moved to the right
/// area (which closes it) or dismissed.
class ZipReportsSettings extends StatefulWidget {
  const ZipReportsSettings({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
    this.onCount,
  });

  final ApiClient api;
  final Session session;
  final bool visible;
  final ValueChanged<int>? onCount;

  @override
  State<ZipReportsSettings> createState() => _ZipReportsSettingsState();
}

class _ZipReportsSettingsState extends State<ZipReportsSettings> {
  List<Map<String, dynamic>> _rows = const [];
  List<Map<String, dynamic>> _areas = const [];
  final Map<String, String> _to = {};
  String? _error;
  bool _loading = true;

  bool get _canFix =>
      widget.session.me?.permissions.contains('scheduling.edit') ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ZipReportsSettings old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/scheduling/zip-reports');
    final a = await widget.api.get('/scheduling/areas');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = r.ok ? null : r.reason;
      _rows = [
        for (final x in (r.body['data'] as List? ?? const []))
          if (x is Map) Map<String, dynamic>.from(x),
      ];
      _areas = [
        for (final x in (a.body['data'] as List? ?? const []))
          if (x is Map) Map<String, dynamic>.from(x),
      ];
    });
    widget.onCount?.call(_rows.length);
  }

  Future<void> _act(Future<ApiResponse> call) async {
    final r = await call;
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _error = r.reason);
      return;
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_loading) return const Center(child: CircularProgressIndicator());
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          '${_rows.length} open ${_rows.length == 1 ? 'report' : 'reports'}',
          key: const Key('zip-reports-count'),
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text(
          'ZIP codes somebody says landed in the wrong area. Moving one puts '
          'the ZIP in that area only and closes the report.',
          style: theme.textTheme.bodySmall,
        ),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
        const SizedBox(height: 12),
        if (_rows.isEmpty) const Text('Nothing reported.'),
        for (final r in _rows) _row(context, r),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> r) {
    final theme = Theme.of(context);
    final id = '${r['id']}';
    final suggested = (r['suggested_area'] as Map?)?['id'];
    final to = _to[id] ?? (suggested == null ? '' : '$suggested');
    final facts = [
      'In ${(r['reported_area'] as Map?)?['name'] ?? 'no area'}',
      if (suggested != null)
        'should be ${(r['suggested_area'] as Map)['name']}',
      if (r['reported_by'] != null) 'by ${r['reported_by']}',
    ].join(' · ');
    return Card(
      key: Key('zip-report-$id'),
      margin: const EdgeInsets.only(bottom: 10),
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${r['zip']}',
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  Text(facts, style: theme.textTheme.bodySmall),
                  if ('${r['note'] ?? ''}'.isNotEmpty)
                    FormattedNotesText('${r['note']}'),
                ],
              ),
            ),
            if (_canFix) ...[
              SizedBox(
                width: 220,
                child: SearchPickerField<String>(
                  key: Key('zip-report-to-$id'),
                  value: to,
                  isDense: true,
                  options: [
                    const SearchOption(value: '', label: 'Move to…'),
                    for (final a in _areas)
                      SearchOption(value: '${a['id']}', label: '${a['name']}'),
                  ],
                  onChanged: (v) => setState(() => _to[id] = v ?? ''),
                ),
              ),
              FilledButton(
                key: Key('zip-report-move-$id'),
                onPressed: to.isEmpty
                    ? null
                    : () => _act(
                        widget.api.post('/scheduling/zip-reports/$id/move', {
                          'area_id': to,
                        }),
                      ),
                child: const Text('Move'),
              ),
              TextButton(
                key: Key('zip-report-dismiss-$id'),
                onPressed: () => _act(
                  widget.api.post('/scheduling/zip-reports/$id/dismiss', {}),
                ),
                child: const Text('ZIP is right'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
