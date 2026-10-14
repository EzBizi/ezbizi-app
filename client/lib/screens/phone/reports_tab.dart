import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
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
    'key': 'By key pressed in the menu',
    'caller': 'By caller',
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

  /// The totals without the Direction filter. The tiles count the whole
  /// period, as tiles do on every tiles-over-a-table screen (Mia,
  /// 2026-10-02); read off the filtered totals, picking Incoming would show
  /// Outgoing as 0 calls, which is a claim about the period, not the filter.
  Map<String, dynamic> _wholeTotals = const {};
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

    String query({required bool withDirection}) => <String>[
      'period=$_period',
      'group_by=$_groupBy',
      if (withDirection && _direction != null) 'direction=$_direction',
      for (final id in _lineIds) 'number_ids[]=$id',
      for (final id in _tagIds) 'tag_ids[]=$id',
    ].join('&');

    // A second question only when a direction is picked: without one, the
    // filtered answer already is the whole.
    final answers = await Future.wait([
      widget.api.get('/phone/reports/run?${query(withDirection: true)}'),
      if (_direction != null)
        widget.api.get('/phone/reports/run?${query(withDirection: false)}'),
    ]);
    final response = answers.first;

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _running = false;
        _error = response.reason;
      });
      return;
    }

    final data = response.body['data'] as Map<String, dynamic>? ?? const {};
    // A grouping with no names — by day, the one this tab opens on — comes
    // back as `[]`, PHP's empty array. Cast to a map it threw here, after
    // the answer had arrived, and the spinner never stopped.
    final labels = data['labels'];
    final totals = data['totals'] as Map<String, dynamic>? ?? const {};
    final wholeData = answers.length > 1 && answers.last.ok
        ? answers.last.body['data']
        : null;
    final whole = wholeData is Map ? wholeData['totals'] : null;

    setState(() {
      _running = false;
      _totals = totals;
      _wholeTotals = whole is Map<String, dynamic>
          ? whole
          : (_direction == null ? totals : const {});
      _rows = _Row.listFrom(data['rows']);
      _labels = {
        if (labels is Map)
          for (final entry in labels.entries)
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

  /// Tiles rather than a card of figures (Mia, 2026-10-02). Incoming and
  /// Outgoing set the Direction filter above, so the two always agree.
  /// Missed, Voicemails, Average call and Not tagged do not filter: the run
  /// endpoint takes no status, voicemail or untagged parameter, and a tile
  /// that narrowed nothing would be a lie about what is under it. Missed is
  /// already drawn inside every bar and counted in the table.
  Widget _totalsCard(ThemeData theme) {
    if (_int(_totals, 'calls') == 0 && _int(_wholeTotals, 'calls') == 0) {
      return Card(
        key: const ValueKey('tour-phone-report-empty'),
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

    final t = _wholeTotals;

    // The whole-period answer failed while a direction was picked: a dash,
    // never a 0 that reads as a quiet week.
    String v(String text) => t.isEmpty ? '—' : text;
    final all = _int(t, 'calls');
    final inbound = _int(t, 'inbound');
    final outbound = _int(t, 'outbound');
    final missed = _int(t, 'missed');
    final rate = t['missed_rate'];

    String share(int n) => all == 0 ? '' : '${(n / all * 100).round()}%';

    return _ReportTiles(
      key: const ValueKey('tour-phone-report-totals'),
      picked: _direction,
      onPick: (key) {
        setState(() => _direction = key);
        _run();
      },
      tiles: [
        _ReportTile(
          key: null,
          label: 'Calls',
          value: v('$all'),
          note: 'in and out',
        ),
        _ReportTile(
          key: 'inbound',
          label: 'Incoming',
          value: v('$inbound'),
          note: share(inbound),
        ),
        _ReportTile(
          key: 'outbound',
          label: 'Outgoing',
          value: v('$outbound'),
          note: share(outbound),
        ),
        _ReportTile(
          key: 'missed',
          label: 'Missed',
          value: v('$missed'),
          note: rate == null ? '' : '$rate% of calls',
          tone: missed == 0 ? null : Tone.bad,
          filters: false,
        ),
        _ReportTile(
          key: 'voicemails',
          label: 'Voicemails',
          value: v('${_int(t, 'voicemails')}'),
          note: 'left a message',
          filters: false,
        ),
        _ReportTile(
          key: 'average',
          label: 'Average call',
          value: v(_duration(_int(t, 'average_seconds'))),
          note: '${_duration(_int(t, 'total_seconds'))} on calls',
          filters: false,
        ),

        // Not a vanity number: an untagged call is one that will not appear in
        // any breakdown by what it was about, so the tag report is only as
        // honest as this figure is small.
        _ReportTile(
          key: 'untagged',
          label: 'Not tagged',
          value: v('${_int(t, 'untagged')}'),
          note: 'no reason given',
          filters: false,
        ),
      ],
    );
  }

  static int _int(Map<String, dynamic> m, String key) =>
      (m[key] as num?)?.toInt() ?? 0;

  String _duration(int seconds) {
    if (seconds < 60) return '${seconds}s';
    final minutes = seconds ~/ 60;
    if (minutes < 60) return '${minutes}m';
    return '${minutes ~/ 60}h ${minutes % 60}m';
  }

  /// A real bar chart with the missed share drawn inside each bar, and the
  /// same numbers as a table under it (Mia, 2026-10-02). DashboardChart's bars
  /// carry one value each, so the calls-and-missed pair is painted here.
  Widget _breakdown(ThemeData theme) {
    if (_rows.isEmpty) return const SizedBox.shrink();

    final callsInk = theme.colorScheme.primary;
    final missedInk = ToneColours.of(context, Tone.bad).ink;

    Widget swatch(Color colour, String label) => Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(
            color: colour,
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        const SizedBox(width: 6),
        Text(label, style: theme.textTheme.bodySmall),
      ],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(_groupings[_groupBy] ?? '', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        DecoratedBox(
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            border: Border.all(color: EzBiziTheme.edge(context)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  key: const ValueKey('report-chart'),
                  height: 170,
                  width: double.infinity,
                  child: CustomPaint(
                    painter: _BarsPainter(
                      bars: [
                        for (final row in _rows)
                          (
                            label: _bucketLabel(row.bucket),
                            calls: row.calls,
                            missed: row.missed,
                          ),
                      ],
                      callsInk: callsInk,
                      missedInk: missedInk,
                      text: theme.textTheme.bodySmall!.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 16,
                  children: [
                    swatch(callsInk, 'Calls'),
                    swatch(missedInk, 'Missed'),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        ListTable(
          columns: [
            ListColumn(_bucketHeading, flex: 3),
            const ListColumn('Calls', end: true),
            const ListColumn('Missed', end: true),
            const ListColumn('Missed rate', end: true),
          ],
          rowKey: (i) => ValueKey('report-row:${_rows[i].bucket}'),
          rows: [
            for (final row in _rows)
              [
                Text(
                  _bucketLabel(row.bucket),
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                Text('${row.calls}', style: theme.textTheme.bodyMedium),
                Text(
                  '${row.missed}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: row.missed == 0 ? null : missedInk,
                  ),
                ),
                Text(
                  row.calls == 0
                      ? '—'
                      : '${(row.missed / row.calls * 100).round()}%',
                  style: theme.textTheme.bodyMedium,
                ),
              ],
          ],
        ),
      ],
    );
  }

  String get _bucketHeading => switch (_groupBy) {
    'day' => 'Day',
    'hour' => 'Hour',
    'tag' => 'About',
    'user' => 'Answered by',
    'line' => 'Line',
    'direction' => 'Direction',
    'key' => 'Key pressed',
    'caller' => 'Caller',
    _ => '',
  };

  /// Ids mean nothing on a screen, and an empty bucket is "not set" rather
  /// than a blank row nobody can interpret.
  String _bucketLabel(String bucket) {
    if (bucket.isEmpty) {
      return switch (_groupBy) {
        'tag' => 'Not tagged',
        'user' => 'Nobody answered',
        'key' => 'No key pressed',
        _ => 'Not set',
      };
    }

    if (_groupBy == 'hour') return '$bucket:00';
    if (_groupBy == 'direction') {
      return bucket == 'inbound' ? 'Incoming' : 'Outgoing';
    }
    if (_groupBy == 'key') return 'Pressed $bucket';
    if (_groupBy == 'caller') {
      final name = _labels[bucket];
      return name == null
          ? formatPhoneNumber(bucket)
          : '$name · ${formatPhoneNumber(bucket)}';
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
          ListTable(
            columns: const [
              ListColumn('Name', flex: 3),
              ListColumn('Shows', flex: 4),
              ListColumn('', end: true),
            ],
            rowKey: (i) => ValueKey('saved:${_saved[i].id}'),
            rows: [
              for (final report in _saved)
                [
                  Text(
                    report.name,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(
                    _shows(report),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  Row(
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
                ],
            ],
          ),
      ],
    );
  }

  /// What a saved report answers: period · breakdown, then any narrowing, so
  /// two reports with the same period and breakdown can be told apart.
  String _shows(_Saved report) {
    String names(List<String> ids, List<_Choice> choices) {
      final byId = {for (final c in choices) c.id: c.name};
      return ids
          .map((id) => byId[id] ?? '')
          .where((n) => n.isNotEmpty)
          .join(', ');
    }

    final lines = names(report.numberIds, _lines);
    final tags = names(report.tagIds, _tags);

    return [
      _periods[report.period] ?? report.period,
      _groupings[report.groupBy] ?? report.groupBy,
      if (report.direction == 'inbound') 'Incoming',
      if (report.direction == 'outbound') 'Outgoing',
      if (lines.isNotEmpty) lines,
      if (tags.isNotEmpty) tags,
    ].join(' · ');
  }
}

/// One totals tile. Unlike [FilterTile] its figure may be a duration, and it
/// may not filter at all.
@immutable
class _ReportTile {
  const _ReportTile({
    required this.key,
    required this.label,
    required this.value,
    this.note = '',
    this.tone,
    this.filters = true,
  });

  final String? key;
  final String label;
  final String value;
  final String note;
  final Tone? tone;
  final bool filters;
}

/// [FilterTiles]' look for the totals, keyed `reports-tile-<key>` the same
/// way. A private copy because the shared widget takes only counts that all
/// filter, and here Average call is a duration and four tiles cannot filter.
class _ReportTiles extends StatelessWidget {
  const _ReportTiles({
    super.key,
    required this.tiles,
    required this.picked,
    required this.onPick,
  });

  final List<_ReportTile> tiles;
  final String? picked;
  final ValueChanged<String?> onPick;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        const gap = 10.0;
        final across = (box.maxWidth / 170).floor().clamp(2, tiles.length);
        final width = (box.maxWidth - gap * (across - 1)) / across;

        return Wrap(
          spacing: gap,
          runSpacing: gap,
          children: [
            for (final tile in tiles)
              SizedBox(width: width, child: _tile(context, tile)),
          ],
        );
      },
    );
  }

  Widget _tile(BuildContext context, _ReportTile tile) {
    final theme = Theme.of(context);
    final chosen = tile.filters && picked == tile.key;
    final ink = tile.tone == null
        ? null
        : ToneColours.of(context, tile.tone!).ink;
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);

    return Material(
      key: Key('reports-tile-${tile.key ?? 'all'}'),
      color: chosen
          ? theme.colorScheme.primaryContainer
          : theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: BorderSide(
          color: chosen ? theme.colorScheme.primary : EzBiziTheme.edge(context),
        ),
      ),
      child: InkWell(
        borderRadius: radius,
        onTap: tile.filters ? () => onPick(chosen ? null : tile.key) : null,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                tile.label.toUpperCase(),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelSmall?.copyWith(
                  letterSpacing: 0.8,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                tile.value,
                style: theme.textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: ink,
                ),
              ),
              Text(
                tile.note,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Calls per bucket as bars, the missed part filled from the foot of each.
/// Labels and counts are thinned where they would collide (90 days by day):
/// the table under the chart carries every figure anyway.
class _BarsPainter extends CustomPainter {
  _BarsPainter({
    required this.bars,
    required this.callsInk,
    required this.missedInk,
    required this.text,
  });

  final List<({String label, int calls, int missed})> bars;
  final Color callsInk;
  final Color missedInk;
  final TextStyle text;

  @override
  void paint(Canvas canvas, Size size) {
    if (bars.isEmpty) return;

    const labelBand = 18.0;
    const countBand = 16.0;
    final most = bars.map((b) => b.calls).reduce(math.max);
    final slot = size.width / bars.length;
    final barWidth = math.min(46.0, slot * 0.7);
    final plot = size.height - labelBand - countBand;

    TextPainter layout(String s) => TextPainter(
      text: TextSpan(text: s, style: text),
      textDirection: TextDirection.ltr,
    )..layout();

    // Every nth label, n chosen so the widest one fits its share of the width.
    final widest = bars.map((b) => layout(b.label).width).reduce(math.max) + 6;
    final every = math.max(1, (widest / slot).ceil());
    final counts = slot >= 22;

    for (var i = 0; i < bars.length; i++) {
      final b = bars[i];
      final centre = slot * i + slot / 2;
      final h = most == 0 ? 0.0 : b.calls / most * plot;
      final top = countBand + plot - h;
      final rect = Rect.fromLTWH(centre - barWidth / 2, top, barWidth, h);

      canvas.drawRRect(
        RRect.fromRectAndCorners(
          rect,
          topLeft: const Radius.circular(4),
          topRight: const Radius.circular(4),
        ),
        Paint()..color = callsInk,
      );

      if (b.calls > 0 && b.missed > 0) {
        final mh = h * (b.missed / b.calls);
        canvas.drawRect(
          Rect.fromLTWH(rect.left, rect.bottom - mh, barWidth, mh),
          Paint()..color = missedInk,
        );
      }

      if (counts) {
        final c = layout('${b.calls}');
        c.paint(canvas, Offset(centre - c.width / 2, top - c.height - 2));
      }

      if (i % every == 0) {
        final l = layout(b.label);
        l.paint(
          canvas,
          Offset(centre - l.width / 2, size.height - labelBand + 3),
        );
      }
    }
  }

  @override
  bool shouldRepaint(_BarsPainter old) =>
      old.bars != bars ||
      old.callsInk != callsInk ||
      old.missedInk != missedInk ||
      old.text != text;
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
