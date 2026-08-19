import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/tag.dart';

/// Generated Reports: search, a list of hits, the frozen report beside them.
///
/// The body is the preset and answers that were stamped on submit. Builder
/// changing later does not restyle this. Archived rows stay hidden until the
/// filter is on. At 375px the list and the report stack.
class ReportsTab extends StatefulWidget {
  const ReportsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  static const double stackBelow = 900;

  @override
  State<ReportsTab> createState() => _ReportsTabState();
}

class _ReportsTabState extends State<ReportsTab> {
  bool _loading = true;
  String? _error;
  int _request = 0;
  final _search = TextEditingController();
  bool _archived = false;
  List<Map<String, dynamic>> _hits = const [];
  String? _empty;
  String? _selectedId;
  Map<String, dynamic>? _open;
  String? _busy;

  bool get _mayArchive =>
      widget.session.me?.permissions.contains('inspection.archive') ?? false;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({String? openId}) async {
    final request = ++_request;
    setState(() {
      _loading = _hits.isEmpty && _open == null;
      _error = null;
    });

    final query = <String, String>{
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      if (_archived) 'archived': '1',
    };
    final list = await widget.api.get('/inspections/reports', query);
    if (!mounted || request != _request) return;

    if (!list.ok) {
      setState(() {
        _loading = false;
        _error = list.reason;
      });
      return;
    }

    final hits = [
      for (final row in (list.data as List? ?? const []))
        if (row is Map) Map<String, dynamic>.from(row),
    ];
    final message = list.body['message'] as String?;
    final keep = openId ?? _selectedId ?? (hits.isNotEmpty ? hits.first['id'] as String? : null);

    setState(() {
      _loading = false;
      _hits = hits;
      _empty = (message != null && message.isNotEmpty)
          ? message
          : (hits.isEmpty
              ? 'Finished inspections show here. None have been submitted yet.'
              : null);
      _selectedId = keep;
    });

    if (keep != null) {
      await _openReport(keep);
    } else {
      setState(() => _open = null);
    }
  }

  Future<void> _openReport(String id) async {
    final response = await widget.api.get('/inspections/reports/$id');
    if (!mounted) return;
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    setState(() {
      _selectedId = id;
      _open = Map<String, dynamic>.from(response.data as Map);
    });
  }

  Future<void> _archive(bool hide) async {
    final id = _selectedId;
    if (id == null) return;
    setState(() => _busy = hide ? 'archive' : 'unarchive');
    final path = hide
        ? '/inspections/reports/$id/archive'
        : '/inspections/reports/$id/unarchive';
    final response = await widget.api.post(path, {});
    if (!mounted) return;
    setState(() => _busy = null);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    await _load(openId: id);
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(
              DashboardCard.padding,
              DashboardCard.padding,
              DashboardCard.padding,
              8,
            ),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _search,
                    decoration: const InputDecoration(
                      hintText: 'Client name or job number',
                      prefixIcon: Icon(Icons.search),
                    ),
                    textInputAction: TextInputAction.search,
                    onSubmitted: (_) => _load(),
                  ),
                ),
                const SizedBox(width: 8),
                FilterChip(
                  label: const Text('Archived'),
                  selected: _archived,
                  onSelected: (on) {
                    setState(() => _archived = on);
                    _load();
                  },
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: LayoutBuilder(
              builder: (context, constraints) {
                final stacked = constraints.maxWidth < ReportsTab.stackBelow;
                final list = _listPane();
                final doc = _documentPane();
                if (stacked) {
                  return ListView(
                    children: [
                      SizedBox(height: 240, child: list),
                      SizedBox(height: 520, child: doc),
                    ],
                  );
                }
                return Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(width: 280, child: list),
                    Expanded(child: doc),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _listPane() {
    return Container(
      key: const Key('inspection-reports-list'),
      decoration: BoxDecoration(
        border: Border(right: BorderSide(color: EzBiziTheme.edge(context))),
      ),
      child: _hits.isEmpty
          ? Padding(
              padding: const EdgeInsets.all(DashboardCard.padding),
              child: DashboardNote(_empty ?? 'Client not found'),
            )
          : ListView.builder(
              itemCount: _hits.length,
              itemBuilder: (context, index) {
                final row = _hits[index];
                return ListTile(
                  selected: row['id'] == _selectedId,
                  title: Text('${row['definition_name'] ?? ''}'),
                  subtitle: Text(
                    [
                      if (row['customer_name'] != null) '${row['customer_name']}',
                      if (row['job_number'] != null) '${row['job_number']}',
                    ].join(' · '),
                  ),
                  trailing: row['archived'] == true ? const Tag('Archived') : null,
                  onTap: () => _openReport('${row['id']}'),
                );
              },
            ),
    );
  }

  Widget _documentPane() {
    final report = _open;
    if (report == null) {
      return const Padding(
        key: Key('inspection-reports-document'),
        padding: EdgeInsets.all(DashboardCard.padding),
        child: DashboardNote(
          'Pick a finished inspection to read it. The look is the one it was submitted with.',
        ),
      );
    }

    final answers = report['answers'] is Map
        ? Map<String, dynamic>.from(report['answers'] as Map)
        : const <String, dynamic>{};
    final screens = [
      for (final s in (report['screens'] as List? ?? const []))
        if (s is Map) Map<String, dynamic>.from(s),
    ];
    final stamp = formatStamp(
      report['submitted_at'],
      'at an unrecorded time',
      formats: _formats,
    );

    return Container(
      key: const Key('inspection-reports-document'),
      padding: const EdgeInsets.all(DashboardCard.padding),
      child: ListView(
        children: [
          Text(
            '${report['definition_name'] ?? ''}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 8),
          Text(
            [
              if (report['customer_name'] != null) '${report['customer_name']}',
              if (report['job_number'] != null) '${report['job_number']}',
            ].join(' · '),
          ),
          const SizedBox(height: 4),
          Text(
            'Submitted by ${report['submitted_by_name'] ?? 'someone'} $stamp.',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 16),
          if (screens.isEmpty)
            for (final entry in answers.entries) ...[
              Text(entry.key, style: Theme.of(context).textTheme.labelMedium),
              const SizedBox(height: 4),
              Text('${entry.value}'),
              const SizedBox(height: 12),
            ]
          else
            for (final screen in screens) ...[
              Text(
                '${screen['name'] ?? ''}',
                style: Theme.of(context).textTheme.titleSmall,
              ),
              const SizedBox(height: 8),
              for (final field in [
                for (final f in (screen['fields'] as List? ?? const []))
                  if (f is Map) Map<String, dynamic>.from(f),
              ]) ...[
                Text(
                  '${field['label'] ?? field['key']}',
                  style: Theme.of(context).textTheme.labelMedium,
                ),
                const SizedBox(height: 4),
                Text('${answers[field['key']] ?? ''}'),
                const SizedBox(height: 12),
              ],
            ],
          if (_mayArchive) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton(
                onPressed: _busy != null
                    ? null
                    : () => _archive(report['archived'] != true),
                child: Text(
                  report['archived'] == true ? 'Unarchive' : 'Archive',
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
