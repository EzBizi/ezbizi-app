import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/job_money.dart';
import '../../core/live_changes.dart';
import '../../core/job_status.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/record_facts.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'job_record_pill.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';
import '../../widgets/colour_field.dart';
import '../../widgets/tag.dart';
import 'job_labels.dart';
import 'job_files.dart';
import 'job_lines_tab.dart';
import 'job_notes.dart';
import 'job_where.dart';
import 'job_workiz.dart';

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
    this.onDone,
    this.dirty,
    this.labels,
    this.initialTab = 'job',
  });

  /// The tab it opens on: job, money, files or notes (Jose, 2026-11-10: the
  /// Attachments count in Project Management opens Photos & files).
  final String initialTab;

  final ApiClient api;
  final Session session;
  final String id;

  /// The words the organization's jobs carry, for the type, sub-status and
  /// tag pickers. Null from a screen without the list's first page: the
  /// pickers then offer the job's own words, and a new one can be typed.
  final JobLabels? labels;

  /// Set when the panel is DOCKED beside the jobs list rather than opened over
  /// it: called where the panel would otherwise pop its route — true after a
  /// save or a delete, false when it is closed. Its presence is what docks it.
  final ValueChanged<bool>? onDone;

  /// Kept true while something typed here is unsaved, so the list beside a
  /// docked panel can refuse to switch jobs underneath it. Clicking the next
  /// row is the natural move in that layout, and it would otherwise discard
  /// the edit without a word.
  final ValueNotifier<bool>? dirty;

  @override
  State<JobDetailPanel> createState() => _JobDetailPanelState();
}

class _JobDetailPanelState extends State<JobDetailPanel> {
  /// Job, Money, Photos & files or Notes (UI queue 58; Jose: "as
  /// recommended"; Notes is queue 122).
  late String _tab = widget.initialTab;

  final _title = TextEditingController();
  final _description = TextEditingController();
  final _scheduled = TextEditingController();
  final _duration = TextEditingController();

  Map<String, dynamic>? _row;
  List<JobStatus> _statusChoices = const [];
  List<String> _statuses = const [];
  String _status = 'scheduled';

  /// The job's service area (queue 25), and the ones its franchise offers.
  /// Null is "not placed" — a real answer somebody can choose, not a blank.
  String? _areaId;
  List<({String id, String name})> _areaChoices = const [];

  /// Type, sub-status and tags (queue 118). Saved as they change, each on its
  /// own: they are not part of the form Save sends.
  String? _jobType;
  String? _subStatus;
  List<String> _tags = const [];

  /// One of them was saved, so the list behind has something new to show
  /// whichever way the panel closes.
  bool _patched = false;

  /// The job's own photos and files (queue 119), loaded beside the job so
  /// the tab can count them before it is opened.
  late final JobAttachments _files = JobAttachments(
    api: widget.api,
    jobId: widget.id,
  );

  /// The job's notes, newest first (queue 122). Held apart from [_row] so an
  /// added one goes on top without reloading the panel.
  List<Map<String, dynamic>> _notes = const [];
  final _noteDraft = TextEditingController();

  bool _loading = true;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;

  String? get _editRefusal => _me.refusal('jobs.edit', 'change a job');
  String? get _deleteRefusal => _me.refusal('jobs.delete', 'delete a job');

  /// The fields as loaded, to tell an edit from the job as it stands.
  String _loaded = '';

  /// The job's time exactly as loaded, seconds included -- see [_minuteOf].
  String _loadedScheduled = '';

  String get _typed => [
    _title.text,
    _description.text,
    _minuteOf(_scheduled.text),
    _duration.text,
    _status,
    _areaId ?? '',
  ].join('\u0000');

  void _markDirty() {
    widget.dirty?.value = !_loading && _typed != _loaded;
    // Save lights as soon as something changed (design 196).
    if (mounted) setState(() {});
  }

  @override
  void initState() {
    super.initState();
    for (final c in [_title, _description, _scheduled, _duration]) {
      c.addListener(_markDirty);
    }
    _files.addListener(_filesChanged);
    _files.load();
    _load();
    // Live: a sync, webhook or automation changing this job shows here.
    _live = LiveChanges.of(widget.api).subscribe(
      {'jobs'},
      (batch) {
        if (batch.reload || batch.touched('jobs').contains(widget.id)) {
          return _refresh();
        }
      },
      active: LiveChanges.visible(this),
    );
  }

  LiveSubscription? _live;

  /// The job read again in place, with no spinner. Skipped while somebody is
  /// typing or saving: their edit is never overwritten from under them.
  Future<void> _refresh() async {
    if (_loading || _busy || _typed != _loaded) return;
    final response = await widget.api.get('/jobs/${widget.id}');
    if (!mounted || !response.ok || _busy || _typed != _loaded) return;
    final row = Map<String, dynamic>.from(response.data as Map);
    setState(() {
      _fill(row);
      _row = row;
      _notes = jobNotes(row['notes']);
    });
    _loaded = _typed;
    widget.dirty?.value = false;
  }

  /// Where the panel goes when it is finished: its route, or the list it is
  /// docked beside.
  void _done(bool? changed) {
    if (_patched) changed ??= true;
    final onDone = widget.onDone;
    if (onDone != null) {
      onDone(changed ?? false);
      return;
    }

    Navigator.of(context).pop(changed);
  }

  void _filesChanged() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _live?.cancel();
    _files.removeListener(_filesChanged);
    _files.dispose();
    _title.dispose();
    _description.dispose();
    _scheduled.dispose();
    _duration.dispose();
    _noteDraft.dispose();
    super.dispose();
  }

  /// The opener's label words, replaced by the job's own once it loads.
  late JobLabels? _labels = widget.labels;

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
      _notes = jobNotes(row['notes']);
      // The job's own label words (Jobs, 2026-10-21), so the pickers fill
      // wherever the panel was opened from.
      _labels =
          JobLabels.of((response.body['meta'] as Map?)?['labels']) ?? _labels;
      _loading = false;
    });

    _loaded = _typed;
    widget.dirty?.value = false;
  }

  void _fill(Map<String, dynamic> row) {
    _title.text = row['title'] as String? ?? '';
    _description.text = row['description'] as String? ?? '';
    _scheduled.text = _wallClock(row['scheduled_for'] as String?);
    _loadedScheduled = _scheduled.text;
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

    _fillLabels(row);

    _areaId = (row['service_area'] as Map<String, dynamic>?)?['id'] as String?;
    final areas = row['allowed_service_areas'];
    _areaChoices = [
      if (areas is List)
        for (final a in areas)
          if (a is Map && a['id'] is String && a['name'] is String)
            (id: a['id'] as String, name: a['name'] as String),
    ];
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

  void _fillLabels(Map<String, dynamic> row) {
    _jobType = jobType(row);
    final sub = row['sub_status'];
    _subStatus = sub is String && sub.trim().isNotEmpty ? sub : null;
    _tags = jobTags(row);
  }

  /// One field, on its own: tags go as the whole list.
  Future<void> _patchLabel(String field, Object? value) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.patch('/jobs/${widget.id}', {
      field: value,
    });

    if (!mounted) return;

    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _patched = true;
      final fresh = response.data;
      // The server tidies the words (trims, drops repeats): show what it kept.
      if (fresh is Map) {
        _fillLabels(Map<String, dynamic>.from(fresh));
      } else if (field == 'tags') {
        _tags = List<String>.from(value as List);
      } else if (field == 'job_type') {
        _jobType = value as String?;
      } else {
        _subStatus = value as String?;
      }
    });
  }

  Future<void> _addTag() async {
    // The job's Workiz account's tags as they are now, each in its colour
    // (Jose, 2026-11-10); only where it has none, the organization's words.
    final workiz = [
      for (final t in (_row?['workiz_tags'] as List?) ?? const [])
        if (t is Map) ('${t['name']}', ColourField.parse('${t['colour']}')),
    ];
    final offered = workiz.isNotEmpty
        ? [
            for (final (name, colour) in workiz)
              if (!_tags.contains(name))
                SearchOption(
                  value: name,
                  label: name,
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Tag(name, colour: colour),
                  ),
                ),
          ]
        : [
            for (final t in {...?_labels?.tags})
              if (!_tags.contains(t)) SearchOption(value: t, label: t),
          ];
    final picked = await showSearchPicker<String>(
      context,
      title: 'Add a tag',
      options: offered,
      // A Workiz job takes only its account's tags.
      create: workiz.isNotEmpty ? null : (typed) => typed,
    );
    if (picked == null || picked.trim().isEmpty || _tags.contains(picked)) {
      return;
    }

    await _patchLabel('tags', [..._tags, picked]);
  }

  /// Words offered for a type or sub-status: the organization's, then the
  /// job's own when it is not among them.
  List<SearchOption<String>> _words(List<String>? known, String? current) => [
    for (final w in {...?known, ?current}) SearchOption(value: w, label: w),
  ];

  /// The tags as removable chips, then "+ Tag" (queue 118, Jose's 1).
  Widget _tagsLine(bool editable) => Refused(
    reason: _editRefusal,
    child: Wrap(
      key: const Key('job-tags'),
      spacing: 6,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (final t in _tags)
          InputChip(
            key: ValueKey('job-tag-$t'),
            label: Text(
              t,
              style: switch (jobTagColours(_row)[t]) {
                final c? => TextStyle(color: inkOn(c)),
                null => null,
              },
            ),
            backgroundColor: jobTagColours(_row)[t],
            visualDensity: VisualDensity.compact,
            onDeleted: editable
                ? () => _patchLabel('tags', [
                    for (final x in _tags)
                      if (x != t) x,
                  ])
                : null,
            deleteButtonTooltipMessage: 'Remove $t',
          ),
        TextButton.icon(
          key: const Key('job-tag-add'),
          onPressed: editable ? _addTag : null,
          icon: const Icon(Icons.add, size: 16),
          label: const Text('Tag'),
        ),
      ],
    ),
  );

  /// Status, Sub-status, Type. Sub-status only once one is set, or when the
  /// job is cancelled or on hold, which is what it explains.
  /// The job's status, said once: on the top line of the Job tab (design
  /// 196), where the pill that repeated it used to be.
  Widget _statusPicker(bool editable) => SearchPickerField<String>(
    key: const Key('job-status'),
    value: _statuses.contains(_status) ? _status : null,
    label: 'Status',
    isDense: true,
    options: _statusChoices
        .map((s) => SearchOption(value: s.value, label: s.label))
        .toList(),
    onChanged: editable
        ? (v) {
            setState(() => _status = v ?? _status);
            _markDirty();
          }
        : null,
  );

  Widget _statusLine(bool editable) {
    final showSub = _subStatus != null || statusTakesSubStatus(_status);
    final sub = SearchPickerField<String>(
      key: const Key('job-sub-status'),
      value: _subStatus,
      label: 'Sub-status',
      hint: 'None',
      allowClear: true,
      options: _words(_labels?.subStatuses, _subStatus),
      create: (typed) => typed,
      onChanged: editable
          ? (v) {
              if (v != _subStatus) _patchLabel('sub_status', v);
            }
          : null,
    );
    final type = SearchPickerField<String>(
      key: const Key('job-type'),
      value: _jobType,
      label: 'Type',
      hint: 'None',
      allowClear: true,
      options: _words(_labels?.jobTypes, _jobType),
      create: (typed) => typed,
      onChanged: editable
          ? (v) {
              if (v != _jobType) _patchLabel('job_type', v);
            }
          : null,
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        final pickers = [if (showSub) sub, type];
        // About 140 each to sit abreast. The status is on the top line
        // (design 196).
        if (constraints.maxWidth >= 140.0 * pickers.length + 16) {
          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (final (i, p) in pickers.indexed) ...[
                if (i > 0) const SizedBox(width: 8),
                Expanded(child: p),
              ],
            ],
          );
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (showSub) ...[
                  Expanded(child: sub),
                  const SizedBox(width: 8),
                ],
                Expanded(child: type),
              ],
            ),
          ],
        );
      },
    );
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

    // Unchanged to the minute -> send back exactly what was loaded. The date
    // field rewrites its text as `YYYY-MM-DD HH:MM` on blur, so a click in and
    // out turns 09:43:33 into 09:43; sending that would move the job by up to
    // 59 seconds, and the Workiz follow pass reads a changed time as a
    // person's edit and stops following the appointment.
    final typed = _scheduled.text.trim();
    final scheduled = _minuteOf(typed) == _minuteOf(_loadedScheduled)
        ? _loadedScheduled
        : typed;

    final response = await widget.api.patch('/jobs/${widget.id}', {
      'title': _title.text.trim(),
      'status': _status,
      'description': _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      'scheduled_for': scheduled.isEmpty ? null : scheduled,
      'duration_minutes': minutes,
      // Only where there was a choice to make. The server stamps a CHANGE as
      // a person's choice, which no zip may overwrite (Jose, 2026-08-31);
      // sending the value it already holds changes nothing.
      if (_areaChoices.isNotEmpty) 'service_area_id': _areaId,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    _done(true);
  }

  /// Deleting a job cannot be undone (Jose, 2026-11-09): a first panel that
  /// names the job, then a second where the job number is typed back.
  Future<bool> _confirmDelete() async {
    final row = _row ?? const <String, dynamic>{};
    final number = '${row['number'] ?? ''}';
    final client = '${(row['customer'] as Map?)?['name'] ?? 'No client'}';
    final money = JobMoney.of(row['money']);
    final total = money == null
        ? null
        : formatMoney(money.total, currency: money.currency);

    final first = await showSidePanel<bool>(
      context,
      builder: (panel) => SidePanel(
        title: 'Delete $number?',
        actions: [
          TextButton(
            key: const Key('job-delete-keep'),
            onPressed: () => Navigator.of(panel).pop(false),
            child: const Text('Keep it'),
          ),
          FilledButton(
            key: const Key('job-delete-continue'),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(panel).colorScheme.error,
            ),
            onPressed: () => Navigator.of(panel).pop(true),
            child: const Text('Continue'),
          ),
        ],
        child: Text(
          [
            '$number · $client${total == null ? '' : ' · $total'}',
            'The job, its lines, notes and files are deleted. This cannot be undone.',
          ].join('\n\n'),
        ),
      ),
    );
    if (first != true || !mounted) return false;

    final typed = TextEditingController();
    final second = await showSidePanel<bool>(
      context,
      builder: (panel) => StatefulBuilder(
        builder: (panel, setPanel) => SidePanel(
          title: 'Delete permanently',
          actions: [
            TextButton(
              onPressed: () => Navigator.of(panel).pop(false),
              child: const Text('Keep it'),
            ),
            FilledButton(
              key: const Key('job-delete-confirm'),
              style: FilledButton.styleFrom(
                backgroundColor: Theme.of(panel).colorScheme.error,
              ),
              onPressed: typed.text.trim() == number
                  ? () => Navigator.of(panel).pop(true)
                  : null,
              child: const Text('Yes, delete permanently'),
            ),
          ],
          child: TextField(
            key: const Key('job-delete-number'),
            controller: typed,
            autofocus: true,
            onChanged: (_) => setPanel(() {}),
            decoration: InputDecoration(labelText: 'Type $number to delete it'),
          ),
        ),
      ),
    );
    // ponytail: left to the GC; disposing here races the panel's exit animation.
    return second == true;
  }

  Future<void> _delete() async {
    if (!await _confirmDelete() || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete('/jobs/${widget.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    _done(true);
  }

  @override
  Widget build(BuildContext context) {
    final row = _row;
    final editable = _editRefusal == null && !_busy && !_loading;

    return SidePanel(
      title: row == null ? 'Job' : row['title'] as String,
      // Design 196: the rest of what this line held is the facts grid.
      subtitle: row?['number'] as String?,
      width: 520,
      headerActions: [if (row != null) JobRecordPill(jobId: '${row['id']}')],
      docked: widget.onDone != null,
      onClose: widget.onDone == null ? null : () => _done(null),
      // Save and Cancel belong to the Job tab's fields; Money, Photos and
      // Notes save themselves or are read only (deep dive 2026-11-02).
      actions: [
        if (!const {'money', 'files', 'notes'}.contains(_tab)) ...[
          TextButton(
            onPressed: _busy ? null : () => _done(null),
            child: const Text('Cancel'),
          ),
          Refused(
            reason: _editRefusal,
            child: FilledButton(
              // Lit once something changed (design 196).
              onPressed: editable && _typed != _loaded ? _save : null,
              child: const Text('Save'),
            ),
          ),
        ],
      ],
      child: _loading
          ? const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          : _tabbed(context, row, editable),
    );
  }

  /// The tabs: Money for a reader with Financials on a job Workiz has, and
  /// Photos & files on every job, since any job can be given its own (queue
  /// 119), counting the job's own and Workiz's together.
  Widget _tabbed(
    BuildContext context,
    Map<String, dynamic>? row,
    bool editable,
  ) {
    final money = JobMoney.of(row?['money']);
    final extras = WorkizExtras.of(row?['workiz']);
    final files = extras?.files ?? const [];
    final fileCount = _files.items.length + files.length;
    final tabs = <(String, String, IconData)>[
      ('job', 'Job', Icons.work_outline),
      if (money != null || (extras?.hasMoney ?? false))
        (
          'money',
          money == null
              ? 'Money'
              : 'Money · ${formatMoney(money.total, currency: money.currency)}',
          Icons.payments_outlined,
        ),
      (
        'files',
        fileCount == 0 ? 'Photos & files' : 'Photos & files · $fileCount',
        Icons.photo_library_outlined,
      ),
      (
        'notes',
        _notes.isEmpty ? 'Notes' : 'Notes · ${_notes.length}',
        Icons.sticky_note_2_outlined,
      ),
    ];
    final current = tabs.any((t) => t.$1 == _tab) ? _tab : 'job';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (tabs.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            // A row inside the Jobs tab: design 103's second level, the
            // way HR records and Memberships draw theirs (deep dive 2026-11-02).
            child: TabBarLike(
              key: const Key('job-tabs'),
              level: TabLevel.secondary,
              labels: [for (final t in tabs) (t.$2, t.$3)],
              selected: tabs.indexWhere((t) => t.$1 == current),
              onSelected: (i) => setState(() => _tab = tabs[i].$1),
            ),
          ),
        if (current == 'money') ...[
          if (money != null) _MoneyCard(money, formats: _me.display),
          if (extras != null && extras.hasMoney)
            WorkizMoneyDetail(extras: extras, formats: _me.display),
        ] else if (current == 'files')
          JobFiles(
            files: _files,
            workiz: files,
            formats: _me.display,
            addRefusal: _editRefusal,
          )
        else if (current == 'notes')
          JobNotes(
            api: widget.api,
            jobId: widget.id,
            notes: _notes,
            draft: _noteDraft,
            formats: _me.display,
            addRefusal: _editRefusal,
            onAdded: (note) => setState(() => _notes = [note, ..._notes]),
            onChanged: (notes) => setState(() => _notes = notes),
            workizNotes: _row?['workiz_notes'] as String?,
          )
        else
          _jobPage(context, row, editable, extras),
      ],
    );
  }

  /// "Latest note: … · who · when ›", opening the Notes tab (queue 122).
  Widget _latestNote(BuildContext context, Map<String, dynamic> note) {
    final theme = Theme.of(context);
    final text = [
      noteFirstLine(note),
      noteAuthor(note),
      noteWhen(note['created_at'], formats: _me.display),
    ].where((s) => s.isNotEmpty).join(' · ');

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: InkWell(
        key: const Key('job-latest-note'),
        borderRadius: BorderRadius.circular(10),
        onTap: () => setState(() => _tab = 'notes'),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
          child: Row(
            children: [
              Icon(
                Icons.sticky_note_2_outlined,
                size: 16,
                color: theme.colorScheme.onSurfaceVariant,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text.rich(
                  TextSpan(
                    children: [
                      const TextSpan(
                        text: 'Latest note: ',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      TextSpan(text: text),
                    ],
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              Icon(
                Icons.chevron_right,
                size: 18,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _jobPage(
    BuildContext context,
    Map<String, dynamic>? row,
    bool editable,
    WorkizExtras? extras,
  ) {
    final customer = row?['customer'] as Map<String, dynamic>?;
    final franchise = row?['franchise'] as Map<String, dynamic>?;
    final fields = extras?.fields ?? const [];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (row != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            // Design 196: the lead's header on the job. The status once,
            // with the marks beside it, then the facts grid.
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                SizedBox(width: 200, child: _statusPicker(editable)),
                if (customer?['owned_by_this_franchise'] == false)
                  const Tag('Shared customer', warn: true),
                // The same amber mark as its row in the list, so an
                // unplaced job reads the same open as closed.
                if (_areaChoices.isNotEmpty && _areaId == null)
                  const NotPlacedMark(),
              ],
            ),
          ),
        if (row != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: RecordFacts([
              RecordFact('Customer', '${customer?['name'] ?? ''}'),
              RecordFact(
                'Customer #',
                '${customer?['number'] ?? ''}',
                copy: true,
              ),
              RecordFact('Branch', '${franchise?['name'] ?? ''}'),
              // Trey Brannan, 2026-11-12: "so we know who booked the appt".
              RecordFact('Booked by', '${row['booked_by'] ?? ''}'),
              RecordFact('Job #', '${row['number'] ?? ''}', copy: true),
              RecordFact(
                'Workiz',
                row['workiz_job_number'] == null
                    ? ''
                    : '#${row['workiz_job_number']}',
                copy: true,
              ),
            ]),
          ),
        if (_notes.isNotEmpty) _latestNote(context, _notes.first),
        // What Workiz says the job IS, before anything to edit
        // (queue 58). Its money moved to the Money tab.
        if (fields.isNotEmpty) WorkizFacts(fields: fields),
        TextField(
          controller: _title,
          decoration: const InputDecoration(labelText: 'What is the job'),
          enabled: editable,
        ),
        if (row != null) ...[const SizedBox(height: 10), _tagsLine(editable)],
        const SizedBox(height: 12),
        _statusLine(editable),
        // Only when the franchise has areas to offer: a franchise with
        // none has no question to ask.
        if (_areaChoices.isNotEmpty) ...[
          const SizedBox(height: 12),
          SearchPickerField<String?>(
            key: const Key('job-service-area'),
            value: _areaId,
            label: 'Service area',
            hint: 'Not placed',
            helperText: _areaId == null
                ? 'Which of ${franchise?['name'] ?? 'the franchise'}\'s '
                      '${_areaChoices.length} areas is this job in?'
                : null,
            options: [
              const SearchOption<String?>(
                value: null,
                label: 'Not placed',
                detail: 'Leave it for somebody to place',
              ),
              for (final a in _areaChoices)
                SearchOption<String?>(value: a.id, label: a.name),
            ],
            onChanged: editable
                ? (v) {
                    setState(() => _areaId = v);
                    _markDirty();
                  }
                : null,
          ),
        ],
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
          decoration: const InputDecoration(labelText: 'Description'),
          minLines: 2,
          maxLines: 6,
          enabled: editable,
        ),
        if (row != null) ...[
          const SizedBox(height: 20),
          const Divider(),
          const SizedBox(height: 16),
          JobLinesTab(
            key: const ValueKey('tour-jobs-job-work'),
            api: widget.api,
            jobId: widget.id,
            job: row,
            editable: editable,
            refusalReason: _editRefusal,
            onChanged: (fresh) => setState(() => _row = fresh),
            money: _me.can('jobs.financials'),
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
              // Red: it destroys the record (phone audit 2026-11-04).
              style: OutlinedButton.styleFrom(
                foregroundColor: Theme.of(context).colorScheme.error,
              ),
              onPressed: (_deleteRefusal == null && !_busy && !_loading)
                  ? _delete
                  : null,
              child: const Text('Delete'),
            ),
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 14), ErrorNote(_error!)],
      ],
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
      key: const ValueKey('tour-jobs-materials'),
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
                  Expanded(child: Text('${row['item'] ?? 'Unnamed item'}')),
                  const SizedBox(width: 12),
                  Text(
                    '${row['qty'] ?? ''}',
                    style: theme.textTheme.bodyMedium,
                  ),
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
      key: const ValueKey('tour-jobs-inspections'),
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
///
/// Only a TRAILING zone is removed -- `Z`, or `+hh:mm` / `-hh:mm` at the very
/// end. This used to split on `[Z+-]` anywhere in the string, so it stopped at
/// the first hyphen of the DATE and returned "2026" for every timestamp: the
/// Scheduled-for field was empty on every panel, and an untouched Save sent
/// `scheduled_for: "2026"`, which the server refused. No dated job could be
/// saved from here from 38955ed6 (2026-08-19) until this. The date's own
/// hyphens are not a zone, and only a pattern anchored to the end can tell.
/// job_panel_wall_clock_test.dart pins it with a dated fixture, which no panel
/// test had.
/// A wall-clock time reduced to what a person can see and change: the minute.
///
/// CalendarDayField shows and writes `YYYY-MM-DD HH:MM`, while a stored job can
/// carry seconds (161 on production do, from Workiz). Comparing the two as text
/// made a click into the date and out again read as an edit -- on every dated
/// job, because even `08:00:00` and `08:00` differ as strings. Anything that
/// does not parse is returned as typed, so a half-typed date still counts as a
/// change.
String _minuteOf(String text) {
  final parsed = parseWallDateTime(text);
  return parsed == null
      ? text.trim()
      : formatWallDateTime(parsed.day, parsed.hour, parsed.minute);
}

String _wallClock(String? stamp) {
  if (stamp == null || stamp.isEmpty) return '';

  return stamp
      .replaceFirst('T', ' ')
      .replaceFirst(RegExp(r'(?:Z|[+-]\d{2}:?\d{2})$'), '')
      .trim();
}

/// Workiz's money on this job (queue 31, Jose's 1): total, paid and due, a
/// bar between paid and total, when it is due, and the lines folded under.
///
/// Read only, and said so on the card: Workiz is the truth for A-1's
/// invoices (Jose, 2026-09-29), so nothing here is a field. A Canceled job
/// with money due shows that money, because that is what Workiz says; its
/// status is the pill right above, for somebody to judge.
class _MoneyCard extends StatelessWidget {
  const _MoneyCard(this.money, {required this.formats});

  final JobMoney money;
  final DisplayFormats formats;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final today = DateTime.now();
    final pill = money.pill(today);
    final late = money.overdue(today);
    final lines = money.lines;
    final tax = money.total - money.subtotal;
    String amount(int minor) => formatMoney(minor, currency: money.currency);

    Widget figure(String label, String value, {Tone? tone}) => Expanded(
      child: Container(
        key: ValueKey('job-money-${label.toLowerCase()}'),
        padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
        decoration: BoxDecoration(
          color: theme.colorScheme.surface,
          border: Border.all(color: theme.colorScheme.outlineVariant),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                color: muted,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 2),
            // One size for the three: a long figure shrinks to its box
            // rather than wrapping under its neighbours.
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: AlignmentDirectional.centerStart,
              child: Text(
                value,
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tone == null
                      ? null
                      : ToneColours.of(context, tone).ink,
                ),
              ),
            ),
          ],
        ),
      ),
    );

    final dueOn = money.dueOn;
    final when = money.credit > 0
        ? '${amount(money.credit)} is owed back to the customer.'
        : money.due <= 0
        ? (money.total > 0 ? 'Paid in full.' : 'Nothing billed yet.')
        : dueOn == null
        ? 'Workiz has no due date for it.'
        : late
        ? 'Was due ${formats.formatDate(dueOn)}.'
        : 'Due by ${formats.formatDate(dueOn)}.';

    String quantity(Object? q) {
      final n = (q as num?) ?? 1;
      return n == n.roundToDouble() ? '${n.round()}' : '$n';
    }

    return Container(
      key: const Key('job-money'),
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Text(
                'Money',
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              if (pill != null) ...[
                const SizedBox(width: 8),
                StatusPill(pill.$1, tone: pill.$2),
              ],
              const Spacer(),
              const Flexible(child: Tag('From Workiz · read only')),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              figure('Total', amount(money.total)),
              const SizedBox(width: 8),
              figure('Paid', amount(money.paid), tone: Tone.ok),
              const SizedBox(width: 8),
              money.credit > 0
                  ? figure('Credit', amount(money.credit), tone: Tone.info)
                  : figure(
                      'Due',
                      amount(money.due > 0 ? money.due : 0),
                      tone: late ? Tone.bad : null,
                    ),
            ],
          ),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(99),
            child: LinearProgressIndicator(
              key: const Key('job-money-paid-bar'),
              value: money.total <= 0
                  ? 0
                  : (money.paid / money.total).clamp(0.0, 1.0),
              minHeight: 8,
              color: ToneColours.of(context, Tone.ok).ink,
              backgroundColor: theme.colorScheme.surfaceContainerHighest,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            when,
            key: const Key('job-money-when'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: late ? ToneColours.of(context, Tone.bad).ink : muted,
              fontWeight: late ? FontWeight.w700 : null,
            ),
          ),
          if (lines.isNotEmpty)
            ExpansionTile(
              key: const Key('job-money-lines'),
              tilePadding: EdgeInsets.zero,
              childrenPadding: const EdgeInsets.only(bottom: 6),
              shape: const Border(),
              collapsedShape: const Border(),
              title: Text(
                [
                  '${lines.length} ${lines.length == 1 ? 'line' : 'lines'}',
                  'subtotal ${amount(money.subtotal)}',
                  if (tax > 0) 'tax ${amount(tax)}',
                ].join(' · '),
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
              children: [
                for (final line in lines)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${line['name'] ?? ''}',
                                style: theme.textTheme.bodyMedium,
                              ),
                              if ((line['description'] as String?)
                                          ?.isNotEmpty ==
                                      true ||
                                  line['taxable'] == true)
                                Text(
                                  [
                                    if ((line['description'] as String?)
                                            ?.isNotEmpty ==
                                        true)
                                      line['description'] as String,
                                    if (line['taxable'] == true) 'taxable',
                                  ].join(' · '),
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: muted,
                                  ),
                                ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 12),
                        Text(
                          '${quantity(line['quantity'])} × '
                          '${amount((line['unit_price'] as num?)?.toInt() ?? 0)}',
                          style: theme.textTheme.bodyMedium,
                        ),
                      ],
                    ),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}
