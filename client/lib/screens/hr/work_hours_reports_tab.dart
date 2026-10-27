/// Work Hours › Reports — saved weekly hours reports (Jose, 2026-09-28).
///
/// "an automation that basically sends the Total works hour for the week …
/// make sure that what we need to do would be possible to do manually as well
/// … and also make a template, we should be able to choose which roles, or
/// which specific person to add to the report".
///
/// Each row is one saved report: who is in it and who receives it. Preview
/// shows the email's table for last week (or this week so far) and sends it
/// from there; the same saved report is what a scheduled automation rule
/// sends. Editing, deleting and sending need `work_hours.manage`; the tab
/// alone reads.
///
/// Panels, not dialogs — DEVELOPMENT-RULES' "No modal dialogs".
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

class WorkHoursReportsTab extends StatefulWidget {
  const WorkHoursReportsTab({
    super.key,
    required this.api,
    this.canManage = false,
  });

  final ApiClient api;
  final bool canManage;

  @override
  State<WorkHoursReportsTab> createState() => _WorkHoursReportsTabState();
}

class _WorkHoursReportsTabState extends State<WorkHoursReportsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _reports = const [];
  List<Map<String, dynamic>> _roles = const [];
  List<Map<String, dynamic>> _people = const [];

  /// The report whose Delete has been pressed once and awaits the second.
  String? _confirmingDelete;

  /// What the search bar holds; matched against what each row shows.
  String _query = '';

  /// The reports whose name, who is in it or who receives it hold the term.
  List<Map<String, dynamic>> get _shown {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return _reports;
    return [
      for (final report in _reports)
        if ([
          '${report['name'] ?? ''}',
          _scope(report),
          for (final r in (report['recipients'] as List? ?? const []))
            _recipientLabel('$r'),
        ].any((text) => text.toLowerCase().contains(needle)))
          report,
    ];
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _reports.isEmpty;
      _error = null;
    });
    final response = await widget.api.get('/hr/time-clock/reports');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      List<Map<String, dynamic>> list(Object? raw) => [
        for (final row in (raw as List?) ?? const [])
          if (row is Map) Map<String, dynamic>.from(row),
      ];
      _reports = list(response.body['data']);
      _roles = list(response.body['roles']);
      _people = list(response.body['people']);
    });
  }

  void _say(String message) => ScaffoldMessenger.maybeOf(
    context,
  )?.showSnackBar(SnackBar(content: Text(message)));

  String _personName(String id) =>
      _people.firstWhere(
            (p) => '${p['id']}' == id,
            orElse: () => const {},
          )['name']
          as String? ??
      'Someone';

  String _recipientLabel(String entry) =>
      entry.startsWith('user:') ? _personName(entry.substring(5)) : entry;

  String _scope(Map<String, dynamic> report) =>
      workHoursReportCovers(report, roles: _roles, people: _people);

  Future<void> _edit([Map<String, dynamic>? report]) async {
    final saved = await editWorkHoursReport(
      context,
      api: widget.api,
      report: report,
      roles: _roles,
      people: _people,
    );
    if (saved) await _load();
  }

  Future<void> _preview(Map<String, dynamic> report) async {
    await showSidePanel<void>(
      context,
      builder: (context) => _ReportPreview(
        api: widget.api,
        report: report,
        canSend: widget.canManage,
        recipientLabel: _recipientLabel,
      ),
    );
  }

  Future<void> _delete(Map<String, dynamic> report) async {
    final id = '${report['id']}';
    if (_confirmingDelete != id) {
      setState(() => _confirmingDelete = id);
      return;
    }
    final response = await widget.api.delete('/hr/time-clock/reports/$id');
    if (!mounted) return;
    setState(() => _confirmingDelete = null);
    if (!response.ok) {
      _say(response.reason);
      return;
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final narrow = MediaQuery.sizeOf(context).width < 600;
    // Standard field height, as tall as Leads search (Jose, 2026-10-27).
    final search = SizedBox(
      width: 240,
      child: TextField(
        key: const Key('work-hours-reports-search'),
        textAlignVertical: TextAlignVertical.center,
        decoration: const InputDecoration(
          isDense: true,
          prefixIconConstraints: BoxConstraints(minWidth: 36),
          prefixIcon: Icon(Icons.search, size: 18),
          hintText: 'Find a report',
          border: OutlineInputBorder(),
        ),
        onChanged: (v) => setState(() => _query = v),
      ),
    );

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        key: const ValueKey('tour-work_hours-reports'),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Weekly hours reports. Each one lists the hours of the people '
                  'in it for a whole week, and can be sent now or by an '
                  'automation rule on a schedule.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ),
              if (!narrow) ...[const SizedBox(width: 12), search],
              if (widget.canManage) ...[
                const SizedBox(width: 12),
                FilledButton.icon(
                  key: const ValueKey('tour-work_hours-reports-new'),
                  onPressed: () => _edit(),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('New report'),
                ),
              ],
            ],
          ),
          if (narrow) ...[
            const SizedBox(height: 8),
            Align(alignment: Alignment.centerLeft, child: search),
          ],
          const SizedBox(height: 16),
          if (_reports.isEmpty)
            Padding(
              key: const ValueKey('tour-work_hours-reports-empty'),
              padding: const EdgeInsets.symmetric(vertical: 32),
              child: Text(
                widget.canManage
                    ? 'No reports yet. New report makes one.'
                    : 'No reports yet.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
            ),
          if (_reports.isNotEmpty && _shown.isEmpty)
            Text(
              'No reports match "${_query.trim()}".',
              style: theme.textTheme.bodyMedium,
            ),
          for (final report in _shown)
            Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
              decoration: BoxDecoration(
                border: Border.all(color: scheme.outlineVariant),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${report['name']}',
                          style: theme.textTheme.titleSmall,
                        ),
                        const SizedBox(height: 2),
                        Text(_scope(report), style: theme.textTheme.bodySmall),
                        Text(
                          (report['recipients'] as List? ?? []).isEmpty
                              ? 'Nobody to send to yet'
                              : 'To: ${[for (final r in (report['recipients'] as List)) _recipientLabel('$r')].join(', ')}',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                  TextButton(
                    onPressed: () => _preview(report),
                    child: const Text('Preview'),
                  ),
                  if (widget.canManage) ...[
                    IconButton(
                      tooltip: 'Edit report',
                      onPressed: () => _edit(report),
                      icon: const Icon(Icons.edit_outlined, size: 18),
                    ),
                    // Two presses, the second in the error colour: no dialog.
                    _confirmingDelete == '${report['id']}'
                        ? TextButton(
                            onPressed: () => _delete(report),
                            style: TextButton.styleFrom(
                              foregroundColor: scheme.error,
                            ),
                            child: const Text('Confirm delete'),
                          )
                        : IconButton(
                            tooltip: 'Delete report',
                            onPressed: () => _delete(report),
                            icon: const Icon(Icons.delete_outline, size: 18),
                          ),
                  ],
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// "Technicians, + Cy, − Ben": who a report is about, in one line. This tab,
/// the Reports library and the automation step all say it (queue 33), so
/// they say it the same way.
String workHoursReportCovers(
  Map<String, dynamic> report, {
  required List<Map<String, dynamic>> roles,
  required List<Map<String, dynamic>> people,
}) {
  String name(List<Map<String, dynamic>> list, Object? id, String none) =>
      list.firstWhere(
            (e) => '${e['id']}' == '$id',
            orElse: () => const {},
          )['name']
          as String? ??
      none;

  final parts = [
    for (final id in (report['role_ids'] as List? ?? const []))
      name(roles, id, 'A role'),
    for (final id in (report['include_user_ids'] as List? ?? const []))
      '+ ${name(people, id, 'Someone')}',
    for (final id in (report['exclude_user_ids'] as List? ?? const []))
      '− ${name(people, id, 'Someone')}',
  ];

  return parts.isEmpty
      ? 'Everybody you can see on Work Hours'
      : parts.join(', ');
}

/// The report editor, wherever a Work Hours report is shown: this tab, the
/// Reports library and the automation step that sends it (queue 33). One
/// editor, so the report reads and changes the same from all three. True
/// when it saved.
Future<bool> editWorkHoursReport(
  BuildContext context, {
  required ApiClient api,
  Map<String, dynamic>? report,
  required List<Map<String, dynamic>> roles,
  required List<Map<String, dynamic>> people,
}) async =>
    await showSidePanel<bool>(
      context,
      builder: (context) =>
          _ReportEditor(api: api, report: report, roles: roles, people: people),
    ) ??
    false;

/// Name, who is in it, who it goes to.
class _ReportEditor extends StatefulWidget {
  const _ReportEditor({
    required this.api,
    required this.report,
    required this.roles,
    required this.people,
  });

  final ApiClient api;
  final Map<String, dynamic>? report;
  final List<Map<String, dynamic>> roles;
  final List<Map<String, dynamic>> people;

  @override
  State<_ReportEditor> createState() => _ReportEditorState();
}

class _ReportEditorState extends State<_ReportEditor> {
  late final _name = TextEditingController(
    text: '${widget.report?['name'] ?? 'Weekly hours'}',
  );
  final _address = TextEditingController();
  late final Set<String> _roles = {
    for (final id in (widget.report?['role_ids'] as List? ?? [])) '$id',
  };
  late final Set<String> _include = {
    for (final id in (widget.report?['include_user_ids'] as List? ?? [])) '$id',
  };
  late final Set<String> _exclude = {
    for (final id in (widget.report?['exclude_user_ids'] as List? ?? [])) '$id',
  };
  late final List<String> _recipients = [
    for (final r in (widget.report?['recipients'] as List? ?? [])) '$r',
  ];
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _address.dispose();
    super.dispose();
  }

  String _personName(String id) =>
      widget.people.firstWhere(
            (p) => '${p['id']}' == id,
            orElse: () => const {},
          )['name']
          as String? ??
      'Someone';

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final body = {
      'name': _name.text.trim(),
      'role_ids': _roles.toList(),
      'include_user_ids': _include.toList(),
      'exclude_user_ids': _exclude.toList(),
      'recipients': _recipients,
    };
    final id = widget.report?['id'];
    final response = id == null
        ? await widget.api.post('/hr/time-clock/reports', body)
        : await widget.api.patch('/hr/time-clock/reports/$id', body);
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.reason;
      });
      return;
    }
    Navigator.of(context).pop(true);
  }

  /// The house person picker, not a bottom sheet (Jose, 2026-09-17: a list of
  /// people is a search picker). It searches once the list is long enough to
  /// need it.
  Future<String?> _pickPerson(Set<String> except) => showSearchPicker<String>(
    context,
    title: 'Choose a person',
    options: [
      for (final p in widget.people)
        if (!except.contains('${p['id']}'))
          SearchOption(
            value: '${p['id']}',
            label: '${p['name']}',
            detail: '${p['email'] ?? ''}'.isEmpty ? null : '${p['email']}',
          ),
    ],
  );

  Widget _heading(String text, [String? hint]) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(top: 18, bottom: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(text, style: theme.textTheme.titleSmall),
          if (hint != null)
            Text(
              hint,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
        ],
      ),
    );
  }

  Widget _peopleChips(Set<String> ids, String addLabel, Set<String> except) {
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final id in ids)
          InputChip(
            label: Text(_personName(id)),
            onDeleted: () => setState(() => ids.remove(id)),
          ),
        ActionChip(
          avatar: const Icon(Icons.add, size: 16),
          label: Text(addLabel),
          onPressed: () async {
            final id = await _pickPerson({...ids, ...except});
            if (id != null) setState(() => ids.add(id));
          },
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.report == null ? 'New report' : 'Edit report',
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.of(context).pop(false),
          child: Text(MaterialLocalizations.of(context).cancelButtonLabel),
        ),
        FilledButton(
          onPressed: _saving || _name.text.trim().isEmpty ? null : _save,
          child: Text(MaterialLocalizations.of(context).saveButtonLabel),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name'),
            onChanged: (_) => setState(() {}),
          ),
          _heading(
            'Roles',
            'Everybody holding any of these. None ticked and nobody added '
                'below means everybody you can see on Work Hours.',
          ),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final role in widget.roles)
                FilterChip(
                  label: Text('${role['name']}'),
                  selected: _roles.contains('${role['id']}'),
                  onSelected: (on) => setState(
                    () => on
                        ? _roles.add('${role['id']}')
                        : _roles.remove('${role['id']}'),
                  ),
                ),
            ],
          ),
          _heading('Also include', 'People added whatever their role.'),
          _peopleChips(_include, 'Add a person', _exclude),
          _heading('Leave out', 'People left out even when their role is in.'),
          _peopleChips(_exclude, 'Leave someone out', _include),
          _heading('Send to'),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final r in _recipients)
                InputChip(
                  label: Text(
                    r.startsWith('user:') ? _personName(r.substring(5)) : r,
                  ),
                  onDeleted: () => setState(() => _recipients.remove(r)),
                ),
              ActionChip(
                avatar: const Icon(Icons.person_add_alt, size: 16),
                label: const Text('Add a person'),
                onPressed: () async {
                  final id = await _pickPerson({
                    for (final r in _recipients)
                      if (r.startsWith('user:')) r.substring(5),
                  });
                  if (id != null) setState(() => _recipients.add('user:$id'));
                },
              ),
            ],
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _address,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(
              labelText: 'Add an email address',
              suffixIcon: IconButton(
                tooltip: 'Add address',
                icon: const Icon(Icons.add),
                onPressed: _addAddress,
              ),
            ),
            onSubmitted: (_) => _addAddress(),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }

  void _addAddress() {
    final value = _address.text.trim();
    if (value.isEmpty || _recipients.contains(value)) return;
    setState(() {
      _recipients.add(value);
      _address.clear();
    });
  }
}

/// The email's table for a week, and Send now.
class _ReportPreview extends StatefulWidget {
  const _ReportPreview({
    required this.api,
    required this.report,
    required this.canSend,
    required this.recipientLabel,
  });

  final ApiClient api;
  final Map<String, dynamic> report;
  final bool canSend;
  final String Function(String) recipientLabel;

  @override
  State<_ReportPreview> createState() => _ReportPreviewState();
}

class _ReportPreviewState extends State<_ReportPreview> {
  String _week = 'last';
  Map<String, dynamic>? _data;
  String? _error;
  bool _sending = false;
  String? _sent;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _data = null;
      _error = null;
    });
    final response = await widget.api.get(
      '/hr/time-clock/reports/${widget.report['id']}/preview?week=$_week',
    );
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _data = Map<String, dynamic>.from(response.body['data'] as Map);
    });
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    final response = await widget.api.post(
      '/hr/time-clock/reports/${widget.report['id']}/send?week=$_week',
      {},
    );
    if (!mounted) return;
    setState(() {
      _sending = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final to = (response.body['data']?['to'] as List? ?? []).length;
      _sent = to == 1 ? 'Sent to 1 address.' : 'Sent to $to addresses.';
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final data = _data;
    final rows = (data?['rows'] as List? ?? const []);

    return SidePanel(
      title: '${widget.report['name']}',
      subtitle: data == null ? null : '${data['range']}',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(MaterialLocalizations.of(context).closeButtonLabel),
        ),
        if (widget.canSend)
          FilledButton.icon(
            onPressed: _sending || data == null ? null : _send,
            icon: const Icon(Icons.send, size: 16),
            label: const Text('Send now'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'last', label: Text('Last week')),
              ButtonSegment(value: 'current', label: Text('This week so far')),
            ],
            selected: {_week},
            onSelectionChanged: (s) {
              setState(() => _week = s.first);
              _load();
            },
          ),
          const SizedBox(height: 16),
          if (data == null && _error == null)
            const Center(child: CircularProgressIndicator()),
          if (data != null) ...[
            if (rows.isEmpty)
              Text(
                'Nobody in this report clocked any time this week.',
                style: theme.textTheme.bodyMedium,
              ),
            for (final row in rows)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Expanded(child: Text('${row['name']}')),
                    Text(
                      '${row['hours']}',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
            if (rows.isNotEmpty) ...[
              const Divider(),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      rows.length == 1
                          ? 'Total (1 employee)'
                          : 'Total (${rows.length} employees)',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                  Text(
                    '${data['total']}',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 16),
            Text(
              (data['recipients'] as List? ?? []).isEmpty
                  ? 'Nobody to send to yet — edit the report to add an address.'
                  : 'Goes to: ${(data['recipients'] as List).join(', ')}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
          ],
          if (_sent != null) ...[
            const SizedBox(height: 12),
            Text(_sent!, style: TextStyle(color: StateColour.of(context).on)),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: TextStyle(color: scheme.error)),
          ],
        ],
      ),
    );
  }
}
