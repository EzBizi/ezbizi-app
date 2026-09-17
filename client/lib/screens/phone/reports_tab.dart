import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// Phone › Reports — counting the calls.
///
/// ── Why this is beside Records rather than inside it ─────────────
///
/// Records answers "what happened on that call" and is a list of rows. This
/// answers "how are we doing" and is a set of numbers. They are read by
/// different people for different reasons, and a filter bar bolted onto a list
/// makes the list worse at being a list without making it good at being a
/// report.
///
/// ── Why a report is saved as a question ──────────────────────────
///
/// "Last 30 days", never two dates. A report worth saving is one somebody wants
/// answered again; pinning it to August means opening it in October shows
/// August, which is never what was meant.
///
/// ── Why the empty state is not a chart ───────────────────────────
///
/// With no calls there is no rate. Drawing "0% missed" out of nothing reads as
/// a good week rather than as no week, so the totals say so in words instead.
class ReportsTab extends StatefulWidget {
  const ReportsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ReportsTab> createState() => _ReportsTabState();
}

class _ReportsTabState extends State<ReportsTab> {
  static const _periods = <String, String>{
    'last_7': 'Last 7 days',
    'last_30': 'Last 30 days',
    'last_90': 'Last 90 days',
    'this_month': 'This month',
    'last_month': 'Last month',
  };

  static const _groupings = <String, String>{
    'day': 'By day',
    'hour': 'By hour of day',
    'tag': 'By what it was about',
    'user': 'By who answered',
    'line': 'By line',
    'direction': 'In or out',
  };

  bool _loading = true;
  String? _error;

  String _period = 'last_30';
  String _groupBy = 'day';
  String? _direction;
  final Set<String> _lineIds = <String>{};
  final Set<String> _tagIds = <String>{};

  List<_Choice> _lines = const [];
  List<_Choice> _tags = const [];
  List<_Saved> _saved = const [];

  Map<String, dynamic> _totals = const {};
  List<_Row> _rows = const [];
  Map<String, String> _labels = const {};

  bool _running = false;
  bool _savingReport = false;
  final _reportName = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _reportName.dispose();
    super.dispose();
  }

  Me get _me => widget.session.me!;

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/phone/reports');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.reason;
      });
      return;
    }

    final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};

    setState(() {
      _saved = _Saved.listFrom(response.body);
      _lines = _Choice.listFrom(meta['lines']);
      _tags = _Choice.listFrom(meta['tags']);
      _loading = false;
    });

    await _run();
  }

  Future<void> _run() async {
    setState(() {
      _running = true;
      _error = null;
    });

    final query = <String>[
      'period=$_period',
      'group_by=$_groupBy',
      if (_direction != null) 'direction=$_direction',
      for (final id in _lineIds) 'number_ids[]=$id',
      for (final id in _tagIds) 'tag_ids[]=$id',
    ].join('&');

    final response = await widget.api.get('/phone/reports/run?$query');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _running = false;
        _error = response.reason;
      });
      return;
    }

    final data = response.body['data'] as Map<String, dynamic>? ?? const {};

    setState(() {
      _running = false;
      _totals = data['totals'] as Map<String, dynamic>? ?? const {};
      _rows = _Row.listFrom(data['rows']);
      _labels = {
        for (final entry in (data['labels'] as Map? ?? const {}).entries)
          entry.key.toString(): entry.value.toString(),
      };
    });
  }

  Future<void> _saveReport() async {
    final name = _reportName.text.trim();

    if (name.isEmpty) {
      setState(() => _error = 'Give the report a name to save it.');
      return;
    }

    setState(() => _savingReport = true);

    final response = await widget.api.post('/phone/reports', {
      'name': name,
      'period': _period,
      'group_by': _groupBy,
      if (_direction != null) 'direction': _direction,
      'number_ids': _lineIds.toList(),
      'tag_ids': _tagIds.toList(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _savingReport = false;
        _error = response.reason;
      });
      return;
    }

    _reportName.clear();
    setState(() => _savingReport = false);
    await _load();
  }

  Future<void> _openSaved(_Saved report) async {
    setState(() {
      _period = report.period == 'custom' ? 'last_30' : report.period;
      _groupBy = report.groupBy;
      _direction = report.direction;
      _lineIds
        ..clear()
        ..addAll(report.numberIds);
      _tagIds
        ..clear()
        ..addAll(report.tagIds);
    });

    await _run();
  }

  Future<void> _deleteSaved(_Saved report) async {
    final response = await widget.api.delete('/phone/reports/${report.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (!_me.can('phone.view')) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          _me.refusal('phone.view', 'see call reports') ??
              'You cannot see call reports.',
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    return ListView(
      key: const ValueKey('reports-tab'),
      padding: const EdgeInsets.all(24),
      children: [
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 16)],

        _filters(theme),
        const SizedBox(height: 20),
        _totalsCard(theme),
        const SizedBox(height: 20),
        _breakdown(theme),
        const SizedBox(height: 24),
        _savedReports(theme),
      ],
    );
  }

  Widget _filters(ThemeData theme) {
    return Wrap(
      spacing: 12,
      runSpacing: 12,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 200,
          child: SearchPickerField<String>(
            key: const ValueKey('report-period'),
            value: _period,
            label: 'When',
            border: OutlineInputBorder(),
            isDense: true,
            options: [
              for (final entry in _periods.entries)
                SearchOption(value: entry.key, label: entry.value),
            ],
            onChanged: (v) {
              if (v == null) return;
              setState(() => _period = v);
              _run();
            },
          ),
        ),
        SizedBox(
          width: 220,
          child: SearchPickerField<String>(
            key: const ValueKey('report-grouping'),
            value: _groupBy,
            label: 'Broken down',
            border: OutlineInputBorder(),
            isDense: true,
            options: [
              for (final entry in _groupings.entries)
                SearchOption(value: entry.key, label: entry.value),
            ],
            onChanged: (v) {
              if (v == null) return;
              setState(() => _groupBy = v);
              _run();
            },
          ),
        ),
        SizedBox(
          width: 180,
          child: SearchPickerField<String?>(
            key: const ValueKey('report-direction'),
            value: _direction,
            label: 'Direction',
            border: OutlineInputBorder(),
            isDense: true,
            options: const [
              SearchOption(value: null, label: 'In and out'),
              SearchOption(value: 'inbound', label: 'Incoming'),
              SearchOption(value: 'outbound', label: 'Outgoing'),
            ],
            onChanged: (v) {
              setState(() => _direction = v);
              _run();
            },
          ),
        ),
        if (_running)
          const SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),

        // Narrowing by line and by tag, as chips rather than another two
        // dropdowns: these are the two people pick several of, and a
        // multi-select dropdown hides what is chosen behind a summary.
        if (_lines.length > 1) _chips('Lines', _lines, _lineIds),
        if (_tags.isNotEmpty) _chips('About', _tags, _tagIds),
      ],
    );
  }

  Widget _chips(String label, List<_Choice> choices, Set<String> picked) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(label, style: theme.textTheme.bodySmall),
        const SizedBox(height: 4),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final choice in choices)
              FilterChip(
                key: ValueKey('filter:${choice.id}'),
                label: Text(choice.name),
                selected: picked.contains(choice.id),
                onSelected: (on) {
                  setState(() {
                    if (on) {
                      picked.add(choice.id);
                    } else {
                      picked.remove(choice.id);
                    }
                  });
                  _run();
                },
              ),
          ],
        ),
      ],
    );
  }

  Widget _totalsCard(ThemeData theme) {
    final calls = (_totals['calls'] as num?)?.toInt() ?? 0;

    if (calls == 0) {
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(
            'No calls in this period. There is nothing to work a rate out of — '
            'a missed-call figure here would be about no calls rather than '
            'about none missed.',
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    final rate = _totals['missed_rate'];

    return Wrap(
      spacing: 12,
      runSpacing: 12,
      children: [
        _stat('Calls', '$calls', theme),
        _stat(
          'Incoming',
          '${(_totals['inbound'] as num?)?.toInt() ?? 0}',
          theme,
        ),
        _stat(
          'Outgoing',
          '${(_totals['outbound'] as num?)?.toInt() ?? 0}',
          theme,
        ),
        _stat('Missed', '${(_totals['missed'] as num?)?.toInt() ?? 0}', theme),
        _stat('Missed rate', rate == null ? '—' : '$rate%', theme),
        _stat(
          'Voicemails',
          '${(_totals['voicemails'] as num?)?.toInt() ?? 0}',
          theme,
        ),
        _stat(
          'Average call',
          _duration((_totals['average_seconds'] as num?)?.toInt() ?? 0),
          theme,
        ),
        _stat(
          'Time on calls',
          _duration((_totals['total_seconds'] as num?)?.toInt() ?? 0),
          theme,
        ),

        // Not a vanity number: an untagged call is one that will not appear in
        // any breakdown by what it was about, so the tag report is only as
        // honest as this figure is small.
        _stat(
          'Not tagged',
          '${(_totals['untagged'] as num?)?.toInt() ?? 0}',
          theme,
        ),
      ],
    );
  }

  Widget _stat(String label, String value, ThemeData theme) {
    return SizedBox(
      width: 150,
      child: Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: theme.textTheme.bodySmall),
              const SizedBox(height: 4),
              Text(value, style: theme.textTheme.titleLarge),
            ],
          ),
        ),
      ),
    );
  }

  String _duration(int seconds) {
    if (seconds < 60) return '${seconds}s';
    final minutes = seconds ~/ 60;
    if (minutes < 60) return '${minutes}m';
    return '${minutes ~/ 60}h ${minutes % 60}m';
  }

  Widget _breakdown(ThemeData theme) {
    if (_rows.isEmpty) return const SizedBox.shrink();

    final most = _rows.map((r) => r.calls).reduce((a, b) => a > b ? a : b);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(_groupings[_groupBy] ?? '', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        for (final row in _rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              children: [
                SizedBox(
                  width: 140,
                  child: Text(
                    _bucketLabel(row.bucket),
                    style: theme.textTheme.bodyMedium,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(
                      value: most == 0 ? 0 : row.calls / most,
                      minHeight: 14,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                SizedBox(
                  width: 96,
                  child: Text(
                    row.missed == 0
                        ? '${row.calls}'
                        : '${row.calls} · ${row.missed} missed',
                    style: theme.textTheme.bodySmall,
                    textAlign: TextAlign.right,
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  /// Ids mean nothing on a screen, and an empty bucket is "not set" rather
  /// than a blank row nobody can interpret.
  String _bucketLabel(String bucket) {
    if (bucket.isEmpty) {
      return switch (_groupBy) {
        'tag' => 'Not tagged',
        'user' => 'Nobody answered',
        _ => 'Not set',
      };
    }

    if (_groupBy == 'hour') return '$bucket:00';
    if (_groupBy == 'direction') {
      return bucket == 'inbound' ? 'Incoming' : 'Outgoing';
    }

    return _labels[bucket] ?? bucket;
  }

  Widget _savedReports(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Saved reports', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(
          'A saved report keeps the question, not the dates — so it answers '
          'itself again next month.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 12),

        Row(
          children: [
            Expanded(
              child: TextField(
                key: const ValueKey('report-name'),
                controller: _reportName,
                decoration: const InputDecoration(
                  labelText: 'Save this view as',
                  hintText: 'Missed calls by line',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
              ),
            ),
            const SizedBox(width: 12),
            FilledButton(
              onPressed: _savingReport ? null : _saveReport,
              child: Text(_savingReport ? 'Saving…' : 'Save'),
            ),
          ],
        ),

        const SizedBox(height: 12),

        if (_saved.isEmpty)
          Text('None saved yet.', style: theme.textTheme.bodySmall)
        else
          for (final report in _saved)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                key: ValueKey('saved:${report.id}'),
                title: Text(report.name),
                subtitle: Text(
                  '${_periods[report.period] ?? report.period} · '
                  '${_groupings[report.groupBy] ?? report.groupBy}',
                  style: theme.textTheme.bodySmall,
                ),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    TextButton(
                      onPressed: () => _openSaved(report),
                      child: const Text('Open'),
                    ),
                    TextButton(
                      onPressed: () => _deleteSaved(report),
                      child: const Text('Remove'),
                    ),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}

@immutable
class _Row {
  const _Row({required this.bucket, required this.calls, required this.missed});

  final String bucket;
  final int calls;
  final int missed;

  static List<_Row> listFrom(dynamic data) {
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Row(
            bucket: r['bucket']?.toString() ?? '',
            calls: (r['calls'] as num?)?.toInt() ?? 0,
            missed: (r['missed'] as num?)?.toInt() ?? 0,
          ),
        )
        .toList(growable: false);
  }
}

@immutable
class _Choice {
  const _Choice({required this.id, required this.name});

  final String id;
  final String name;

  static List<_Choice> listFrom(dynamic data) {
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Choice(
            id: r['id'].toString(),
            name: r['name']?.toString() ?? '',
          ),
        )
        .toList(growable: false);
  }
}

@immutable
class _Saved {
  const _Saved({
    required this.id,
    required this.name,
    required this.period,
    required this.groupBy,
    required this.numberIds,
    required this.tagIds,
    this.direction,
  });

  final String id;
  final String name;
  final String period;
  final String groupBy;
  final List<String> numberIds;
  final List<String> tagIds;
  final String? direction;

  static List<_Saved> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map((r) {
          return _Saved(
            id: r['id'].toString(),
            name: r['name']?.toString() ?? '',
            period: r['period']?.toString() ?? 'last_30',
            groupBy: r['group_by']?.toString() ?? 'day',
            direction: r['direction'] as String?,
            numberIds: (r['number_ids'] as List? ?? const [])
                .map((e) => e.toString())
                .toList(),
            tagIds: (r['tag_ids'] as List? ?? const [])
                .map((e) => e.toString())
                .toList(),
          );
        })
        .toList(growable: false);
  }
}
