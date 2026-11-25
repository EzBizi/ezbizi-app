import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/date_range_picker.dart';
import '../widgets/empty_state.dart';
import '../widgets/list_search.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import 'reports/scoring_report.dart' show scoringCheckLabels;

/// Leaderboard (Trey, approved by Jose 2026-11-25): "only the top 3
/// performers, and the person who is entering the tab should also see their
/// own rank", and "another sub tab for their own Performance ... only for that
/// person's stuff, like their calls". Reports › Scoring's numbers, read from
/// GET leaderboard and leaderboard/mine, which send three names and the
/// reader's own rows only.
class LeaderboardScreen extends StatefulWidget {
  const LeaderboardScreen({
    super.key,
    required this.api,
    required this.session,
    this.now,
  });

  final ApiClient api;
  final Session session;
  final DateTime Function()? now;

  @override
  State<LeaderboardScreen> createState() => _LeaderboardScreenState();
}

class _LeaderboardScreenState extends State<LeaderboardScreen> {
  int _tab = 0;
  int _weeksBack = 0;

  /// Sunday to Friday (Trey: "each week it would start over on sunday"); on
  /// a Saturday, the week just ended. Both tabs read the same week.
  DateSpan get _span {
    final t = (widget.now ?? DateTime.now)();
    final sunday = DateTime(
      t.year,
      t.month,
      t.day - t.weekday % 7 - 7 * _weeksBack,
    );
    return DateSpan(
      sunday,
      DateTime(sunday.year, sunday.month, sunday.day + 5),
    );
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me;
    final tabs = [
      if (me?.can('leaderboard.view') ?? false)
        ('Leaderboard', Icons.emoji_events_outlined, _Board.new),
      if (me?.can('leaderboard.mine.view') ?? false)
        ('My performance', Icons.insights_outlined, _Mine.new),
    ];
    if (tabs.isEmpty) {
      return const Center(
        child: Text('No Leaderboard tabs have been granted to your role.'),
      );
    }
    final selected = _tab < tabs.length ? _tab : 0;
    final span = _span;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-leaderboard-tabs'),
            labels: [for (final t in tabs) (t.$1, t.$2)],
            selected: selected,
            onSelected: (i) => setState(() => _tab = i),
          ),
        ),
        const Divider(height: 1),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Row(
            children: [
              IconButton(
                key: const ValueKey('leaderboard-week-previous'),
                tooltip: 'The week before',
                icon: const Icon(Icons.chevron_left),
                onPressed: () => setState(() => _weeksBack++),
              ),
              Flexible(
                child: Text(
                  '${_weeksBack == 0 ? 'This week' : 'Week'} · '
                  '${me!.display.formatDate(span.from)} – '
                  '${me.display.formatDate(span.to)}',
                  key: const ValueKey('leaderboard-week'),
                ),
              ),
              IconButton(
                key: const ValueKey('leaderboard-week-next'),
                tooltip: 'The week after',
                icon: const Icon(Icons.chevron_right),
                onPressed: _weeksBack == 0
                    ? null
                    : () => setState(() => _weeksBack--),
              ),
            ],
          ),
        ),
        Expanded(
          child: tabs[selected].$3(
            key: ValueKey('${tabs[selected].$1}-$span'),
            api: widget.api,
            span: span,
            formats: me.display,
          ),
        ),
      ],
    );
  }
}

String _score(Object? score) =>
    score is num ? '${score.toStringAsFixed(1)} / 10' : '—';

/// One GET per tab and period; the key above makes a new period a new load.
mixin _Loads<T extends StatefulWidget> on State<T> {
  Map<String, dynamic>? data;
  String? error;

  Future<void> load(ApiClient api, String path) async {
    final r = await api.get(path);
    if (!mounted) return;
    setState(() {
      if (r.ok) {
        data = (r.body['data'] as Map).cast<String, dynamic>();
      } else {
        error = r.message;
      }
    });
  }

  Widget? pending() => error != null
      ? Center(child: Text(error!))
      : data == null
      ? const Center(child: CircularProgressIndicator())
      : null;
}

class _Board extends StatefulWidget {
  const _Board({
    super.key,
    required this.api,
    required this.span,
    required this.formats,
  });

  final ApiClient api;
  final DateSpan span;
  final DisplayFormats formats;

  @override
  State<_Board> createState() => _BoardState();
}

class _BoardState extends State<_Board> with _Loads {
  @override
  void initState() {
    super.initState();
    load(widget.api, '/leaderboard?week=${widget.span.fromKey}');
  }

  @override
  Widget build(BuildContext context) {
    final waiting = pending();
    if (waiting != null) return waiting;
    final top = [for (final p in data!['top'] as List) (p as Map).cast()];
    final me = (data!['me'] as Map).cast<String, dynamic>();
    final min = data!['min_calls_to_rank'];
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (top.isEmpty)
          EmptyState(
            icon: Icons.emoji_events_outlined,
            title: 'Nobody ranks yet',
            message:
                'The board ranks the people marked for New Client Calls '
                'with at least $min scored calls in the week.',
          )
        else
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              for (final p in top)
                SizedBox(
                  width: 220,
                  child: Card(
                    key: ValueKey('leaderboard-top-${p['rank']}'),
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        children: [
                          Icon(
                            Icons.emoji_events,
                            size: 32,
                            color: theme.colorScheme.primary,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '#${p['rank']}',
                            style: theme.textTheme.labelLarge,
                          ),
                          Text(
                            '${p['person']}',
                            style: theme.textTheme.titleMedium,
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 4),
                          Text(_score(p['score'])),
                          Text(
                            '${p['calls_scored']} calls scored',
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        const SizedBox(height: 16),
        Card(
          key: const ValueKey('leaderboard-me'),
          child: ListTile(
            leading: const Icon(Icons.person_outline),
            title: Text(
              me['rank'] != null
                  ? 'Your rank: #${me['rank']} of ${me['of']}'
                  : 'You are not on this board',
            ),
            subtitle: Text(switch (me['why']) {
              null => 'Your score: ${_score(me['score'])}',
              'not_new' =>
                'Only the people marked for New Client Calls are ranked here.',
              'too_few_calls' =>
                'You have ${me['calls_scored']} scored calls in these dates; '
                    '$min rank you.',
              _ =>
                'Your name in EzBizi matches no name the phone system '
                    'records, so your calls cannot be found.',
            }),
          ),
        ),
      ],
    );
  }
}

class _Mine extends StatefulWidget {
  const _Mine({
    super.key,
    required this.api,
    required this.span,
    required this.formats,
  });

  final ApiClient api;
  final DateSpan span;
  final DisplayFormats formats;

  @override
  State<_Mine> createState() => _MineState();
}

class _MineState extends State<_Mine> with _Loads {
  List<Map<String, dynamic>> _scored = [];
  String _query = '';

  String get _dates => 'from=${widget.span.fromKey}&to=${widget.span.toKey}';

  @override
  void initState() {
    super.initState();
    load(widget.api, '/leaderboard/mine?$_dates');
    _search('');
  }

  /// Their latest, best and worst scored calls; or, searched, the matches.
  Future<void> _search(String q) async {
    _query = q.trim();
    final r = await widget.api.get(
      '/leaderboard/mine/calls?$_dates'
      '${_query.isEmpty ? '' : '&q=${Uri.encodeQueryComponent(_query)}'}',
    );
    if (!mounted || !r.ok) return;
    setState(
      () =>
          _scored = [for (final c in r.body['data'] as List) (c as Map).cast()],
    );
  }

  @override
  Widget build(BuildContext context) {
    final waiting = pending();
    if (waiting != null) return waiting;
    final theme = Theme.of(context);
    final perf = (data!['performance'] as Map?)?.cast<String, dynamic>();
    final card = (data!['scoring'] as Map?)?.cast<String, dynamic>();
    final calls = [for (final c in data!['calls'] as List) (c as Map).cast()];
    if (data!['person'] == null) {
      return const EmptyState(
        icon: Icons.insights_outlined,
        title: 'Your calls cannot be found',
        message:
            'Your name in EzBizi matches no name the phone system records.',
      );
    }
    Widget heading(String text) => Padding(
      padding: const EdgeInsets.only(top: 20, bottom: 8),
      child: Text(text, style: theme.textTheme.titleMedium),
    );
    Widget tile(String label, Object? value) => SizedBox(
      width: 150,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: theme.textTheme.bodySmall),
              Text('${value ?? '—'}', style: theme.textTheme.titleLarge),
            ],
          ),
        ),
      ),
    );
    final talk = perf?['avg_talk_seconds'] as int?;
    final checks = (card?['checks'] as Map?)?.cast<String, dynamic>() ?? {};

    return ListView(
      key: const ValueKey('leaderboard-mine'),
      padding: const EdgeInsets.all(16),
      children: [
        heading('Your score'),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            tile('Score', _score(card?['score'])),
            tile('AI call score', card?['avg_score']),
            tile('Calls scored', card?['calls_scored'] ?? 0),
            tile(
              'Conversion',
              card?['conversion'] == null ? null : '${card!['conversion']}%',
            ),
          ],
        ),
        heading('Your calls'),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            tile('Answered', perf?['answered'] ?? 0),
            tile('Rang you', perf?['rang'] ?? 0),
            tile('Outbound', perf?['outbound'] ?? 0),
            tile('New-client calls', perf?['new_client'] ?? 0),
            tile('Booked', perf?['booked_new'] ?? 0),
            tile('Jobs created', perf?['jobs'] ?? 0),
            tile(
              'Average talk',
              talk == null ? null : formatClock(Duration(seconds: talk)),
            ),
          ],
        ),
        if (checks.isNotEmpty) ...[
          heading('What the AI checks'),
          for (final e in scoringCheckLabels.entries)
            ListTile(
              dense: true,
              title: Text(e.value),
              trailing: Text('${checks[e.key] ?? 0}%'),
            ),
        ],
        for (final (label, key) in [
          ('Strengths', 'strengths'),
          ('To work on', 'improve'),
        ])
          if (((card?[key] as List?) ?? const []).isNotEmpty) ...[
            heading(label),
            for (final line in card![key] as List) Text('• $line'),
          ],
        heading('Your scored calls'),
        ListSearch(
          key: const ValueKey('leaderboard-mine-search'),
          hint: 'Search by number or words said',
          onSubmitted: _search,
          onClear: () => _search(''),
        ),
        if (_scored.isEmpty)
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              _query.isEmpty
                  ? 'No scored calls in these dates.'
                  : 'No scored calls match "$_query".',
            ),
          ),
        for (final c in _scored)
          ExpansionTile(
            key: ValueKey('leaderboard-scored-${c['call_id']}'),
            title: Text(
              '${formatStamp(c['started_at'], '', formats: widget.formats)}'
              ' · ${c['caller_number'] ?? 'Unknown number'}',
            ),
            subtitle: Wrap(
              spacing: 6,
              children: [
                StatusPill('${c['score']} / 10', dense: true),
                for (final tag in (c['tags'] as List?) ?? const [])
                  StatusPill(
                    switch (tag) {
                      'best' => 'Best',
                      'worst' => 'Worst',
                      'latest' => 'Latest',
                      _ => 'Match',
                    },
                    dense: true,
                    tone: switch (tag) {
                      'best' => Tone.ok,
                      'worst' => Tone.caution,
                      _ => Tone.neutral,
                    },
                  ),
              ],
            ),
            childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            children: [
              if (c['strength'] != null) Text('Did well: ${c['strength']}'),
              if (c['improve'] != null) Text('To improve: ${c['improve']}'),
              const SizedBox(height: 8),
              SelectableText('${c['transcript'] ?? ''}'),
            ],
          ),
        heading('Every call · ${calls.length}'),
        for (final c in calls)
          ListTile(
            key: ValueKey('leaderboard-call-${c['id']}'),
            dense: true,
            title: Text(
              '${formatStamp(c['started_at'], '', formats: widget.formats)}'
              ' · ${c['from'] ?? 'Unknown number'}',
            ),
            subtitle: Text(
              [
                c['direction'] ?? '',
                if (c['answered_by'] != null &&
                    c['answered_by'] != data!['person'])
                  'answered by ${c['answered_by']}',
                if (c['booked'] == true) 'booked',
              ].join(' · '),
            ),
          ),
      ],
    );
  }
}
