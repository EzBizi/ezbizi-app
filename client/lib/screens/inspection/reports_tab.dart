import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import 'dart:typed_data';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/files.dart';
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

  /// A phone shows the list or one report, never both (UI pass 11-16: the
  /// list and the whole first report were stacked in one scroll).
  bool _reading = false;
  bool _stacked = false;
  Map<String, dynamic>? _open;
  String? _busy;

  /// Photograph bytes, by attachment id, for the report on screen.
  ///
  /// Cleared with the report rather than kept: a technician scrolling a day's
  /// work would otherwise accumulate every picture they had glanced at.
  final Map<String, Uint8List> _photos = {};

  bool get _mayArchive =>
      widget.session.me?.permissions.contains('inspection.archive') ?? false;

  /// The PDF is the report. `inspection.view` is what opens this screen, so
  /// anybody reading a report may take a copy of the one they are reading —
  /// the server says the same thing, and one answer is better than two.
  Future<void> _download() async {
    final report = _open;
    if (report == null) return;

    setState(() => _busy = 'pdf');

    final response = await widget.api.getBytesResponse(
      '/inspections/reports/${report['id']}/pdf',
    );

    if (!mounted) return;
    setState(() => _busy = null);

    final bytes = response.bytes;
    if (bytes == null) {
      setState(
        () =>
            _error = response.message ?? 'The report could not be downloaded.',
      );
      return;
    }

    final name = [
      '${report['definition_name'] ?? 'inspection'}',
      if (report['customer_name'] != null) '${report['customer_name']}',
    ].join(' - ');

    Files.download('$name.pdf', bytes);
  }

  Future<void> _loadPhotos(Map<String, dynamic> report) async {
    final answers = report['answers'];
    if (answers is! Map) return;

    for (final screen in _items(report['screens'])) {
      if (screen is! Map) continue;
      for (final field in _items(screen['fields'])) {
        if (field is! Map) continue;
        if (field['type'] != 'photo' && field['type'] != 'signature') continue;
        for (final id in _items(answers['${field['key']}'])) {
          final key = '$id';
          if (_photos.containsKey(key)) continue;
          final bytes = await widget.api.getBytes('/attachments/$key');
          if (!mounted || bytes == null) continue;
          setState(() => _photos[key] = Uint8List.fromList(bytes));
        }
      }
    }
  }

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
    // A report submitted or archived elsewhere: the list re-reads quietly;
    // the open report is read again only when it is the one that changed.
    _live = LiveChanges.of(widget.api).subscribe({'inspection_reports'}, (
      batch,
    ) {
      if (_loading || _busy != null) return null;
      final open = _selectedId;
      return _load(
        quiet: true,
        reopen:
            batch.reload ||
            (open != null &&
                batch.touched('inspection_reports').contains(open)),
      );
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _search.dispose();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps the list on screen, and the
  /// open report (and its photos) is read again only when [reopen].
  Future<void> _load({
    String? openId,
    bool quiet = false,
    bool reopen = true,
  }) async {
    final request = ++_request;
    if (!quiet) {
      setState(() {
        _loading = _hits.isEmpty && _open == null;
        _error = null;
      });
    }

    final query = <String, String>{
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      if (_archived) 'archived': '1',
    };
    final list = await widget.api.get('/inspections/reports', query);
    if (!mounted || request != _request) return;
    if (quiet && !list.ok) return;

    if (!list.ok) {
      setState(() {
        _loading = false;
        _error = list.reason;
      });
      return;
    }

    final hits = [
      for (final row in _items(list.data))
        if (row is Map) Map<String, dynamic>.from(row),
    ];
    final message = list.body['message'] as String?;
    final keep =
        openId ??
        _selectedId ??
        (hits.isNotEmpty ? hits.first['id']?.toString() : null);

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
      if (reopen || _open?['id']?.toString() != keep) await _openReport(keep);
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
    final report = Map<String, dynamic>.from(response.data as Map);
    setState(() {
      _selectedId = id;
      _open = report;
      _photos.clear();
    });
    await _loadPhotos(report);
  }

  /// One answer, shown the way its type was answered.
  ///
  /// The list used to be `Text('$value')` for everything, which printed a photo
  /// answer as a row of uuids and a date as whatever string was saved. The
  /// report is the artefact somebody reads months later; it should not be the
  /// rawest possible view of the column.
  Widget _answer(Map<String, dynamic> field, dynamic value) {
    final type = '${field['type'] ?? 'text'}';

    // A signature is stored as a picture — the same one-element id list a
    // photo uses — so it renders the same way. Text would print a uuid.
    if (type == 'photo' || type == 'signature') {
      return _photoRow(value, signature: type == 'signature');
    }

    final text = value == null ? '' : '$value';

    if (text.trim().isEmpty) {
      return Text(
        // Not blank. "Nothing to report" and "nobody looked" are different
        // findings, and the second one is why the field was on the form.
        'Not answered',
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
          fontStyle: FontStyle.italic,
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      );
    }

    if (type == 'date') {
      return Text(formatStamp(text, text, formats: _formats));
    }

    // A choice answer is stored as its slug ("not-applicable"); the reader
    // gets words ("Not applicable"), never the stored value (Muse 10.3).
    if (type != 'text' &&
        RegExp(r'^[a-z0-9]+([_-][a-z0-9]+)*$').hasMatch(text)) {
      final words = text.replaceAll(RegExp(r'[_-]'), ' ');
      return Text(
        words == 'ok' ? 'OK' : words[0].toUpperCase() + words.substring(1),
      );
    }

    return Text(text);
  }

  /// The photographs on one answer, read-only.
  ///
  /// No remove control: a submitted report is frozen, and the server refuses a
  /// delete against one. A button that always failed would be a lie on screen.
  Widget _photoRow(dynamic value, {bool signature = false}) {
    final ids = [for (final id in _items(value)) '$id'];

    if (ids.isEmpty) {
      return Text(
        signature ? 'Not signed' : 'No photograph',
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
          fontStyle: FontStyle.italic,
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      );
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final id in ids)
          SizedBox(
            width: signature ? 220 : 96,
            height: signature ? 90 : 96,
            child: ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: _photos[id] == null
                  ? ColoredBox(
                      color: Theme.of(
                        context,
                      ).colorScheme.surfaceContainerHighest,
                      child: const Center(
                        child: SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      ),
                    )
                  // contain, not cover, for a signature: cropping somebody's
                  // name to fill a square is not a rendering choice to make.
                  : Image.memory(
                      _photos[id]!,
                      fit: signature ? BoxFit.contain : BoxFit.cover,
                    ),
            ),
          ),
      ],
    );
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
                  child: ListSearch(
                    key: const ValueKey('tour-inspection-reports-search'),
                    controller: _search,
                    hint: 'Client name or job number',
                    onSubmitted: (_) => _load(),
                  ),
                ),
                const SizedBox(width: 8),
                FilterChip(
                  key: const ValueKey('tour-inspection-reports-archived'),
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
                _stacked = stacked;
                final list = _listPane();
                final doc = _documentPane();
                if (stacked && !_reading) return list;
                if (stacked) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Padding(
                        padding: const EdgeInsets.fromLTRB(4, 4, 4, 0),
                        child: TextButton.icon(
                          key: const Key('inspection-reports-back'),
                          onPressed: () => setState(() => _reading = false),
                          icon: const Icon(Icons.arrow_back, size: 18),
                          label: const Text('All reports'),
                        ),
                      ),
                      Expanded(child: doc),
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
                  // A phone's list opens nothing yet, so nothing is lit.
                  selected: '${row['id']}' == _selectedId && !_stacked,
                  title: Text('${row['definition_name'] ?? ''}'),
                  subtitle: Text(
                    [
                      if (row['customer_name'] != null)
                        '${row['customer_name']}',
                      if (row['job_number'] != null) '${row['job_number']}',
                    ].join(' · '),
                  ),
                  trailing: row['archived'] == true
                      ? const Tag('Archived')
                      : null,
                  onTap: () {
                    setState(() => _reading = true);
                    _openReport('${row['id']}');
                  },
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
      for (final s in _items(report['screens']))
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
                for (final f in _items(screen['fields']))
                  if (f is Map) Map<String, dynamic>.from(f),
              ]) ...[
                Text(
                  '${field['label'] ?? field['key']}',
                  style: Theme.of(context).textTheme.labelMedium,
                ),
                const SizedBox(height: 4),
                _answer(field, answers['${field['key']}']),
                const SizedBox(height: 12),
              ],
            ],
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton.icon(
                key: const Key('inspection-report-pdf'),
                onPressed: _busy != null ? null : _download,
                icon: const Icon(Icons.download_outlined),
                label: Text(_busy == 'pdf' ? 'Preparing…' : 'Download PDF'),
              ),
              if (_mayArchive)
                OutlinedButton(
                  key: const ValueKey('tour-inspection-reports-archive'),
                  onPressed: _busy != null
                      ? null
                      : () => _archive(report['archived'] != true),
                  child: Text(
                    report['archived'] == true ? 'Unarchive' : 'Archive',
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// A column read as a list, whatever shape it came in. A report imported from
/// A1 keeps A1's shape: a photo answer can be one string, a keyed object or a
/// list, and a bare `as List?` on the first two crashed the whole tab to a
/// grey box (phone audit 2026-11-04, Section 3 #1).
List<dynamic> _items(dynamic value) => switch (value) {
  final List list => list,
  final Map map => map.values.toList(),
  final String text when text.trim().isNotEmpty => [text],
  _ => const [],
};
