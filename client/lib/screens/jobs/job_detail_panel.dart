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
                if (row != null && row['materials'] is List) ...[
                  const SizedBox(height: 20),
                  const Divider(),
                  const SizedBox(height: 16),
                  _Materials(rows: row['materials'] as List),
                ],
                if (row != null && row['inspections'] is List) ...[
                  const SizedBox(height: 20),
                  const Divider(),
                  const SizedBox(height: 16),
                  _Inspections(rows: row['inspections'] as List),
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

/// What Inventory issued against this job (spec §9), read-only.
///
/// ── Drawn on the KEY, not on a permission the client checks ──────
///
/// `materials` is absent from the payload when the reader does not hold
/// `jobs.materials.view`, and an empty list when they do and nothing was
/// issued. So the `is List` test above is the tab filter: the server already
/// decided, and an absent `/me` cannot accidentally answer yes — which is what
/// inbox 0051 asks a screen to guarantee, reached from the server side rather
/// than by re-deriving the permission here.
///
/// The two states must stay distinguishable. "Nothing was used on this job" is
/// a fact about the job; "you may not see what was used" is a fact about the
/// reader. Drawing an empty section for the second would tell somebody the
/// first, and they would have no reason to doubt it.
///
/// ── No money, for anybody ────────────────────────────────────────
///
/// There is no cost here because the payload carries none, for any caller,
/// including one who may read costs everywhere else in the product. A job's
/// money is what the customer is charged; what the parts cost the business is
/// Inventory's. See JobsController::materialsFor().
///
// ponytail: a private widget in this file rather than job_materials_tab.dart,
// which the plan named. It is a read-only list with no state, and a file per
// section earns its keep when there is state to hold. Split it out if Photos
// and Inspection make this file unwieldy.
class _Materials extends StatelessWidget {
  const _Materials({required this.rows});

  final List rows;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Materials', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'Issued from Inventory. Add or return them there, not here.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),
        if (rows.isEmpty)
          Text(
            'Nothing has been issued against this job.',
            style: theme.textTheme.bodyMedium,
          )
        else
          for (final row in rows.whereType<Map>())
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text('${row['item'] ?? 'Unnamed item'}'),
                  ),
                  const SizedBox(width: 12),
                  Text('${row['qty'] ?? ''}', style: theme.textTheme.bodyMedium),
                  const SizedBox(width: 12),
                  Text(
                    '${row['issued_on'] ?? ''}',
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
      ],
    );
  }
}

/// The inspection reports submitted on this job (spec §10), read-only.
///
/// Drawn on the KEY, like [_Materials], and the key is present only for
/// somebody holding Inspection's own `inspection.view`. There is no Jobs tab
/// key behind this section on purpose: a job must not become a way past
/// InspectionReportPolicy. See JobsController::inspectionsFor().
///
/// The name is the report's own snapshot of what the inspection was called
/// when it was submitted, so renaming the template later leaves this alone.
///
// ponytail: listed, not linked. Opening a report means navigating into the
// Inspection module, which is its own screen and its own session's; a list
// that says what was inspected and when is useful on its own. Add a tap
// through to the report when somebody asks to open one from here.
class _Inspections extends StatelessWidget {
  const _Inspections({required this.rows});

  final List rows;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Inspections', style: theme.textTheme.titleMedium),
        const SizedBox(height: 12),
        if (rows.isEmpty)
          Text(
            'No inspection has been submitted on this job.',
            style: theme.textTheme.bodyMedium,
          )
        else
          for (final row in rows.whereType<Map>())
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text('${row['name'] ?? 'Unnamed inspection'}'),
                  ),
                  const SizedBox(width: 12),
                  Text(
                    _datePart(row['submitted_at'] as String?),
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
      ],
    );
  }
}

/// The calendar date of an ISO timestamp, as the API wrote it.
///
/// Taken from the string rather than parsed, so the date is the one in the
/// offset the server sent — parsing into a DateTime and formatting it would
/// move a late-evening submission onto the next day for anybody east of it.
String _datePart(String? stamp) =>
    (stamp == null || stamp.length < 10) ? '' : stamp.substring(0, 10);

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
