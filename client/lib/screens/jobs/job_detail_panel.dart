import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_status.dart';
import '../../core/session.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'job_lines_tab.dart';

/// One job, opened from the list.
///
/// Franchise and customer do not move: that would rewrite two histories.
/// Status, date, description and duration do. Delete is here too.
///
/// Price is not a field on this panel. It is `job['amount']`, summed
/// server-side from the service lines in the Work section below — see
/// [JobLinesTab]. A typed total was the bug this module replaced.
class JobDetailPanel extends StatefulWidget {
  const JobDetailPanel({
    super.key,
    required this.api,
    required this.session,
    required this.id,
  });

  final ApiClient api;
  final Session session;
  final String id;

  @override
  State<JobDetailPanel> createState() => _JobDetailPanelState();
}

class _JobDetailPanelState extends State<JobDetailPanel> {
  final _title = TextEditingController();
  final _description = TextEditingController();
  final _scheduled = TextEditingController();
  final _duration = TextEditingController();

  Map<String, dynamic>? _row;
  List<JobStatus> _statusChoices = const [];
  List<String> _statuses = const [];
  String _status = 'scheduled';
  bool _loading = true;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;

  String? get _editRefusal => _me.refusal('jobs.edit', 'change a job');
  String? get _deleteRefusal => _me.refusal('jobs.delete', 'delete a job');

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _title.dispose();
    _description.dispose();
    _scheduled.dispose();
    _duration.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/jobs/${widget.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final row = Map<String, dynamic>.from(response.data as Map);
    _fill(row);

    setState(() {
      _row = row;
      _loading = false;
    });
  }

  void _fill(Map<String, dynamic> row) {
    _title.text = row['title'] as String? ?? '';
    _description.text = row['description'] as String? ?? '';
    _scheduled.text = _wallClock(row['scheduled_for'] as String?);
    final duration = row['duration_minutes'];
    _duration.text = duration == null ? '' : '$duration';

    final parsed = parseJobStatuses(row['allowed_statuses']);
    _statusChoices = parsed.isEmpty
        ? [
            JobStatus(
              value: row['status'] as String? ?? 'scheduled',
              label:
                  row['status_label'] as String? ??
                  (row['status'] as String? ?? 'Scheduled'),
            ),
          ]
        : parsed;
    _statuses = _statusChoices.map((s) => s.value).toList();
    _status = row['status'] as String? ?? _statuses.first;
    if (!_statuses.contains(_status)) {
      _statusChoices = [
        JobStatus(
          value: _status,
          label: row['status_label'] as String? ?? _status,
        ),
        ..._statusChoices,
      ];
      _statuses = _statusChoices.map((s) => s.value).toList();
    }
  }

  Future<void> _save() async {
    final durationText = _duration.text.trim();
    final int? minutes;
    if (durationText.isEmpty) {
      minutes = null;
    } else {
      minutes = int.tryParse(durationText);
      if (minutes == null || minutes < 1) {
        setState(() {
          _error = 'Duration is minutes as a whole number, or leave it empty.';
        });
        return;
      }
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final scheduled = _scheduled.text.trim();

    final response = await widget.api.patch('/jobs/${widget.id}', {
      'title': _title.text.trim(),
      'status': _status,
      'description': _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      'scheduled_for': scheduled.isEmpty ? null : scheduled,
      'duration_minutes': minutes,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete('/jobs/${widget.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final row = _row;
    final customer = row?['customer'] as Map<String, dynamic>?;
    final franchise = row?['franchise'] as Map<String, dynamic>?;
    final editable = _editRefusal == null && !_busy && !_loading;

    return SidePanel(
      title: row == null ? 'Job' : row['title'] as String,
      subtitle: row == null
          ? null
          : [
              row['number'],
              customer?['name'],
              franchise?['name'],
            ].where((s) => s != null && '$s'.isNotEmpty).join(' · '),
      width: 520,
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        Refused(
          reason: _editRefusal,
          child: FilledButton(
            onPressed: editable ? _save : null,
            child: const Text('Save'),
          ),
        ),
      ],
      child: _loading
          ? const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (row != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        Tag(
                          row['status_label'] as String? ??
                              row['status'] as String,
                        ),
                        if (customer?['owned_by_this_franchise'] == false)
                          const Tag('Shared customer', warn: true),
                      ],
                    ),
                  ),
                TextField(
                  controller: _title,
                  decoration: const InputDecoration(
                    labelText: 'What is the job',
                  ),
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                SearchPickerField<String>(
                  value: _statuses.contains(_status) ? _status : null,
                  label: 'Status',
                  options: _statusChoices
                      .map((s) => SearchOption(value: s.value, label: s.label))
                      .toList(),
                  onChanged: editable
                      ? (v) => setState(() => _status = v ?? _status)
                      : null,
                ),
                const SizedBox(height: 12),
                CalendarDayField(
                  controller: _scheduled,
                  label: 'Scheduled for',
                  helper: 'Empty means not dated yet.',
                  formats: _me.display,
                  withTime: true,
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _duration,
                  decoration: const InputDecoration(
                    labelText: 'Duration',
                    helperText: 'Minutes. Optional.',
                  ),
                  keyboardType: TextInputType.number,
                  enabled: editable,
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _description,
                  decoration: const InputDecoration(labelText: 'Notes'),
                  minLines: 2,
                  maxLines: 6,
                  enabled: editable,
                ),
                if (row != null) ...[
                  const SizedBox(height: 20),
                  const Divider(),
                  const SizedBox(height: 16),
                  JobLinesTab(
                    api: widget.api,
                    jobId: widget.id,
                    job: row,
                    editable: editable,
                    refusalReason: _editRefusal,
                    onChanged: (fresh) => setState(() => _row = fresh),
                  ),
                ],
                const SizedBox(height: 20),
                Align(
                  alignment: Alignment.centerLeft,
                  child: Refused(
                    reason: _deleteRefusal,
                    child: OutlinedButton(
                      onPressed: (_deleteRefusal == null && !_busy && !_loading)
                          ? _delete
                          : null,
                      child: const Text('Delete'),
                    ),
                  ),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  ErrorNote(_error!),
                ],
              ],
            ),
    );
  }
}

/// ISO from the API (`2026-03-08T09:00:00-05:00`) as the wall clock a person
/// typed. The franchise timezone is already baked into the stored value.
String _wallClock(String? stamp) {
  if (stamp == null || stamp.isEmpty) return '';

  return stamp.replaceFirst('T', ' ').split(RegExp(r'[Z+-]')).first.trim();
}

String _firstError(ApiResponse response) {
  final errors = response.body['errors'];

  if (errors is Map && errors.isNotEmpty) {
    final first = errors.values.first;
    if (first is List && first.isNotEmpty) return first.first.toString();
  }

  return response.message;
}
