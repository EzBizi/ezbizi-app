import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/date_range_picker.dart';
import '../widgets/list_search.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/user_avatar.dart';
import 'reports/scoring_report.dart' show scoringCheckLabels;

/// Leaderboard (Trey, approved by Jose 2026-11-25): "only the top 3
/// performers, and the person who is entering the tab should also see their
/// own rank", "another sub tab for their own Performance ... only for that
/// person's stuff, like their calls", and "from Sunday to Friday each week
/// ... you can check previous weeks winners". Design 212, option 1 + phone 4:
/// a ranked card of three, a You card, Past weeks; My performance as tiles,
/// then Score and AI reads beside scored calls and every call.
///
/// Reads GET leaderboard, leaderboard/weeks, leaderboard/mine and
/// leaderboard/mine/calls (Reports), which send three names and the reader's
/// own rows only.
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

  /// This week's Sunday; on a Saturday, the week just ended.
  DateTime get _thisSunday {
    final t = (widget.now ?? DateTime.now)();
    return DateTime(t.year, t.month, t.day - t.weekday % 7);
  }

  /// Sunday to Friday (Trey: "each week it would start over on sunday").
  /// Both tabs read the same week.
  DateSpan get _span {
    final s = _thisSunday;
    final sunday = DateTime(s.year, s.month, s.day - 7 * _weeksBack);
    return DateSpan(
      sunday,
      DateTime(sunday.year, sunday.month, sunday.day + 5),
    );
  }

  /// A past week picked from Past weeks.
  void _openWeek(DateTime from) {
    final days = _thisSunday.difference(from).inDays;
    setState(() => _weeksBack = (days / 7).round().clamp(0, 520));
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me;
    final tabs = [
      if (me?.can('leaderboard.view') ?? false)
        ('Leaderboard', Icons.emoji_events_outlined),
      if (me?.can('leaderboard.mine.view') ?? false)
        ('My performance', Icons.insights_outlined),
    ];
    if (tabs.isEmpty) {
      return const Center(
        child: Text('No Leaderboard tabs have been granted to your role.'),
      );
    }
    final selected = _tab < tabs.length ? _tab : 0;
    final span = _span;
    final formats = me!.display;
    final theme = Theme.of(context);
    final board = tabs[selected].$1 == 'Leaderboard';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-leaderboard-tabs'),
            labels: [for (final t in tabs) (t.$1, t.$2)],
            selected: selected,
            onSelected: (i) => setState(() => _tab = i),
          ),
        ),
        const Divider(height: 1),
        // ‹ This week · Sun Nov 22 – Fri Nov 27 ›
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
          child: Row(
            children: [
              IconButton(
                key: const ValueKey('leaderboard-week-previous'),
                tooltip: 'The week before',
                icon: const Icon(Icons.chevron_left),
                onPressed: () => setState(() => _weeksBack++),
              ),
              Flexible(
                child: Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(
                        text: _weeksBack == 0
                            ? 'This week'
                            : _weeksBack == 1
                            ? 'Last week'
                            : 'Week',
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      TextSpan(
                        text:
                            ' · ${formats.formatDate(span.from)} – '
                            '${formats.formatDate(span.to)}',
                      ),
                    ],
                  ),
                  key: const ValueKey('leaderboard-week'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
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
          child: board
              ? _Board(
                  key: ValueKey('board-$span'),
                  api: widget.api,
                  span: span,
                  formats: formats,
                  onOpenWeek: _openWeek,
                )
              : _Mine(
                  key: ValueKey('mine-$span'),
                  api: widget.api,
                  span: span,
                  formats: formats,
                ),
        ),
      ],
    );
  }
}

String _score(Object? score) => score is num ? score.toStringAsFixed(1) : '—';

/// A card with a small capitals heading, as in design 212.
class _Section extends StatelessWidget {
  const _Section(this.title, this.child, {super.key, this.tint});

  final String title;
  final Widget child;
  final Color? tint;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      color: tint,
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title.toUpperCase(), style: theme.textTheme.labelSmall),
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );
  }
}

/// Gold, silver, bronze with the rank on it.
class _Medal extends StatelessWidget {
  const _Medal(this.rank, {this.size = 30});

  final int rank;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    alignment: Alignment.center,
    decoration: BoxDecoration(
      color: EzBiziTheme.medals[(rank - 1).clamp(0, 2)],
      shape: BoxShape.circle,
    ),
    child: Text(
      '$rank',
      style: TextStyle(
        color: EzBiziTheme.fieldInkLight,
        fontWeight: FontWeight.w800,
        fontSize: size * 0.45,
      ),
    ),
  );
}

/// One GET per tab and week; the key above makes a new week a new load.
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
    required this.onOpenWeek,
  });

  final ApiClient api;
  final DateSpan span;
  final DisplayFormats formats;
  final ValueChanged<DateTime> onOpenWeek;

  @override
  State<_Board> createState() => _BoardState();
}

class _BoardState extends State<_Board> with _Loads {
  List<Map<String, dynamic>> _weeks = const [];

  @override
  void initState() {
    super.initState();
    load(widget.api, '/leaderboard?week=${widget.span.fromKey}');
    _loadWeeks();
  }

  Future<void> _loadWeeks() async {
    final r = await widget.api.get('/leaderboard/weeks?limit=8');
    if (!mounted || !r.ok) return;
    setState(
      () => _weeks = [
        for (final w in (r.body['data'] as List? ?? const []))
          (w as Map).cast<String, dynamic>(),
      ],
    );
  }

  Widget _person(BuildContext context, Map p, {required bool phone}) {
    final theme = Theme.of(context);
    final name = '${p['person'] ?? ''}';
    final id = p['user_id'];
    return Padding(
      key: ValueKey('leaderboard-top-${p['rank']}'),
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          _Medal(p['rank'] as int? ?? 1),
          const SizedBox(width: 10),
          // On a phone the medal says who is where; the name gets the room.
          if (!phone) ...[
            id is String
                ? UserAvatar(api: widget.api, userId: id, name: name)
                : CircleAvatar(
                    radius: 16,
                    backgroundColor:
                        EzBiziTheme.avatarPalette[name.hashCode.abs() %
                            EzBiziTheme.avatarPalette.length],
                    child: Text(
                      name.isEmpty ? '?' : name[0].toUpperCase(),
                      style: const TextStyle(
                        color: EzBiziTheme.fieldInkLight,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
            const SizedBox(width: 10),
          ],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleSmall,
                ),
                Text(
                  '${p['calls_scored'] ?? 0} calls scored',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
          Text.rich(
            TextSpan(
              children: [
                TextSpan(
                  text: _score(p['score']),
                  style: theme.textTheme.titleLarge,
                ),
                TextSpan(text: ' / 10', style: theme.textTheme.bodySmall),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Where the reader stands; when they are not ranked, why, and who can
  /// change it (design 212's four empty states).
  Widget _you(BuildContext context, Map<String, dynamic> me, Object? min) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final on = scheme.onPrimaryContainer;
    final calls = me['calls_scored'] as int? ?? 0;
    final need = min is int ? (min - calls).clamp(1, min) : 1;
    final (String head, String? body) = switch (me['why']) {
      null when me['rank'] != null => ('#${me['rank']}', null),
      'not_new' => (
        'Not on New Client Calls',
        'You are not ranked: only people who take New Client Calls are. '
            'Your manager marks that in Reports › Performance › People.',
      ),
      'too_few_calls' => (
        '$calls of ${min ?? '?'} scored calls',
        '$need more scored call${need == 1 ? '' : 's'} this week and you '
            'are on the board.',
      ),
      'not_matched' => (
        'Your calls are not linked yet',
        'No phone-system name matches you, so your calls cannot be counted. '
            'Your manager links it in Reports › Performance › People.',
      ),
      _ => ('Not ranked this week', null),
    };
    final ranked = me['rank'] != null && me['why'] == null;
    return _Section(
      'You',
      key: const ValueKey('leaderboard-me'),
      tint: scheme.primaryContainer,
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.end,
            spacing: 12,
            runSpacing: 4,
            children: [
              Text(
                head,
                style:
                    (ranked
                            ? theme.textTheme.headlineMedium
                            : theme.textTheme.titleMedium)
                        ?.copyWith(color: on),
              ),
              if (ranked) ...[
                Text(
                  'of ${me['of']} ranked',
                  style: theme.textTheme.bodySmall?.copyWith(color: on),
                ),
                Text(
                  '${_score(me['score'])} / 10 · $calls calls scored',
                  style: theme.textTheme.titleSmall?.copyWith(color: on),
                ),
              ],
            ],
          ),
          const SizedBox(height: 4),
          Text(
            body ??
                'Your calls and how they were scored are in My performance.',
            style: theme.textTheme.bodySmall?.copyWith(color: on),
          ),
        ],
      ),
    );
  }

  Widget _past(BuildContext context) {
    final theme = Theme.of(context);
    String day(Object? key) {
      final d = DateTime.tryParse('$key');
      return d == null ? '' : widget.formats.formatDate(d);
    }

    return _Section(
      'Past weeks',
      key: const ValueKey('leaderboard-past'),
      Column(
        children: [
          for (final w in _weeks)
            if ((w['top'] as List?)?.isNotEmpty ?? false)
              InkWell(
                key: ValueKey('leaderboard-week-${w['from']}'),
                borderRadius: BorderRadius.circular(8),
                onTap: () {
                  final from = DateTime.tryParse('${w['from']}');
                  if (from != null) widget.onOpenWeek(from);
                },
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Row(
                    children: [
                      SizedBox(
                        width: 110,
                        child: Text(
                          '${day(w['from'])} – ${day(w['to'])}',
                          style: theme.textTheme.bodySmall,
                        ),
                      ),
                      const _Medal(1, size: 22),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${(w['top'] as List).first['person']}  '
                              '${_score((w['top'] as List).first['score'])}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.titleSmall,
                            ),
                            Text(
                              [
                                for (final p in (w['top'] as List).skip(1))
                                  '${p['rank']} ${p['person']}',
                              ].join(' · '),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodySmall,
                            ),
                          ],
                        ),
                      ),
                      Icon(
                        Icons.chevron_right,
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ],
                  ),
                ),
              ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final waiting = pending();
    if (waiting != null) return waiting;
    final theme = Theme.of(context);
    final top = [for (final p in data!['top'] as List) (p as Map).cast()];
    final me = (data!['me'] as Map).cast<String, dynamic>();
    final min = data!['min_calls_to_rank'];
    final phone = MediaQuery.sizeOf(context).width < 600;
    const gap = SizedBox(height: 12);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _Section(
                  'Top 3 · New Client Calls',
                  top.isEmpty
                      ? Text(
                          'Nobody is ranked yet. No one has ${min ?? 3} '
                          'scored calls in this week; try the week before.',
                          key: const ValueKey('leaderboard-nobody'),
                          style: theme.textTheme.bodyMedium,
                        )
                      : Column(
                          children: [
                            for (final (i, p) in top.indexed) ...[
                              if (i > 0) const Divider(height: 1),
                              _person(context, p, phone: phone),
                            ],
                          ],
                        ),
                ),
                gap,
                _you(context, me, min),
                if (_weeks.any(
                  (w) => (w['top'] as List?)?.isNotEmpty ?? false,
                )) ...[
                  gap,
                  _past(context),
                ],
              ],
            ),
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
  String _pick = 'latest';
  String _callFilter = '';

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
    final muted = theme.textTheme.bodySmall;
    if (data!['person'] == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'Your calls are not linked yet: no phone-system name matches you. '
            'Your manager links it in Reports › Performance › People.',
            key: const ValueKey('leaderboard-mine-unlinked'),
            textAlign: TextAlign.center,
          ),
        ),
      );
    }
    final perf = (data!['performance'] as Map?)?.cast<String, dynamic>();
    final card = (data!['scoring'] as Map?)?.cast<String, dynamic>();
    final calls = [for (final c in data!['calls'] as List) (c as Map).cast()];
    final talk = perf?['avg_talk_seconds'] as int?;
    final checks = (card?['checks'] as Map?)?.cast<String, dynamic>() ?? {};
    final conversion = card?['conversion'];

    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= 900;

        // The six numbers, one size (Jose: sibling tiles are equal).
        final tiles = [
          ('Answered', '${perf?['answered'] ?? 0}'),
          ('Outbound', '${perf?['outbound'] ?? 0}'),
          ('New client', '${perf?['new_client'] ?? 0}'),
          ('Booked new', '${perf?['booked_new'] ?? 0}'),
          ('Booking rate', conversion is num ? '${conversion.round()}%' : '—'),
          (
            'Avg talk',
            talk == null ? '—' : formatClock(Duration(seconds: talk)),
          ),
        ];
        Widget tile((String, String) t) => Card(
          key: ValueKey('leaderboard-tile-${t.$1}'),
          margin: EdgeInsets.zero,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(t.$2, style: theme.textTheme.titleLarge),
                Text(
                  t.$1,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: muted,
                ),
              ],
            ),
          ),
        );
        Widget tileRow(List<(String, String)> row) => IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final (i, t) in row.indexed) ...[
                if (i > 0) const SizedBox(width: 8),
                Expanded(child: tile(t)),
              ],
            ],
          ),
        );
        final tileBlock = wide
            ? tileRow(tiles)
            : Column(
                children: [
                  tileRow(tiles.sublist(0, 3)),
                  const SizedBox(height: 8),
                  tileRow(tiles.sublist(3)),
                ],
              );

        List<Widget> lines(String title, Object? items, Color colour) => [
          Text(title, style: muted),
          for (final s in (items as List?) ?? const [])
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text('• $s', style: TextStyle(color: colour)),
            ),
        ];
        final tones = ToneColours.of(context, Tone.ok).ink;
        final caution = ToneColours.of(context, Tone.caution).ink;
        final strengths = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: lines('Strengths', card?['strengths'], tones),
        );
        final improve = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: lines('To improve', card?['improve'], caution),
        );
        final score = _Section(
          'Score',
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Wrap(
                spacing: 14,
                crossAxisAlignment: WrapCrossAlignment.end,
                children: [
                  Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: _score(card?['score']),
                          style: theme.textTheme.headlineMedium,
                        ),
                        TextSpan(text: ' / 10', style: muted),
                      ],
                    ),
                  ),
                  Text(
                    'AI call score ${card?['avg_score'] ?? '—'} avg · '
                    '${card?['calls_scored'] ?? 0} calls',
                    style: muted,
                  ),
                ],
              ),
              const SizedBox(height: 8),
              for (final e in scoringCheckLabels.entries)
                if (checks[e.key] is num)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 3),
                    child: Row(
                      children: [
                        Expanded(child: Text(e.value)),
                        SizedBox(
                          width: wide ? 140 : 70,
                          child: LinearProgressIndicator(
                            value: (checks[e.key] as num) / 100,
                            minHeight: 6,
                            borderRadius: BorderRadius.circular(3),
                          ),
                        ),
                        SizedBox(
                          width: 44,
                          child: Text(
                            '${(checks[e.key] as num).round()}%',
                            textAlign: TextAlign.end,
                          ),
                        ),
                      ],
                    ),
                  ),
              const SizedBox(height: 8),
              if (wide)
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: strengths),
                    const SizedBox(width: 12),
                    Expanded(child: improve),
                  ],
                )
              else ...[
                strengths,
                const SizedBox(height: 8),
                improve,
              ],
            ],
          ),
        );

        Widget read(String label, Object? r) {
          final review = (r as Map?)?.cast<String, dynamic>();
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(
                        text: label,
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      if (review?['calls'] is num)
                        TextSpan(
                          text: ' · ${review!['calls']} calls',
                          style: muted,
                        ),
                    ],
                  ),
                ),
                Text(
                  review == null
                      ? 'No read yet. AI writes one for anyone with 3 scored '
                            'calls in the ${label == 'Daily' ? 'day' : 'week'}.'
                      : '${review['summary'] ?? ''}',
                  style: review == null ? muted : theme.textTheme.bodyMedium,
                ),
              ],
            ),
          );
        }

        final reviews = _Section(
          'AI reads',
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              read('Daily', card?['review_daily']),
              read('Weekly', card?['review_weekly']),
            ],
          ),
        );

        // Latest 3 · Best · Worst from one list, split by its tags; a search
        // shows its matches instead.
        final shown = _query.isNotEmpty
            ? _scored
            : [
                for (final c in _scored)
                  if (((c['tags'] as List?) ?? const []).contains(_pick)) c,
              ];
        final scored = _Section(
          'My scored calls',
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ListSearch(
                key: const ValueKey('leaderboard-mine-search'),
                hint: 'Search by number or words said',
                width: double.infinity,
                onSubmitted: _search,
                onClear: () => _search(''),
              ),
              if (_query.isEmpty) ...[
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  children: [
                    for (final (key, label) in [
                      ('latest', 'Latest 3'),
                      ('best', 'Best'),
                      ('worst', 'Worst'),
                    ])
                      ChoiceChip(
                        key: ValueKey('leaderboard-pick-$key'),
                        label: Text(label),
                        selected: _pick == key,
                        onSelected: (_) => setState(() => _pick = key),
                      ),
                  ],
                ),
              ],
              if (shown.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  child: Text(
                    _query.isEmpty
                        ? 'No scored calls in this week.'
                        : 'No scored calls match "$_query".',
                    style: muted,
                  ),
                ),
              for (final c in shown)
                ExpansionTile(
                  key: ValueKey('leaderboard-scored-${c['call_id']}'),
                  tilePadding: EdgeInsets.zero,
                  title: Text(
                    '${formatStamp(c['started_at'], '', formats: widget.formats)}'
                    ' · ${c['caller_number'] ?? 'Unknown number'}',
                    style: theme.textTheme.titleSmall,
                  ),
                  subtitle: Align(
                    alignment: Alignment.centerLeft,
                    child: StatusPill('${c['score']} / 10', dense: true),
                  ),
                  childrenPadding: const EdgeInsets.only(bottom: 12),
                  expandedCrossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (c['strength'] != null)
                      Text('Did well: ${c['strength']}'),
                    if (c['improve'] != null)
                      Text('To improve: ${c['improve']}'),
                    const SizedBox(height: 6),
                    SelectableText('${c['transcript'] ?? ''}', style: muted),
                  ],
                ),
            ],
          ),
        );

        final filter = _callFilter.toLowerCase();
        final listed = [
          for (final c in calls)
            if (filter.isEmpty ||
                '${c['from'] ?? ''} ${c['to'] ?? ''} ${c['direction'] ?? ''}'
                    .toLowerCase()
                    .contains(filter))
              c,
        ];
        final every = _Section(
          'My calls · ${calls.length}',
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ListSearch(
                key: const ValueKey('leaderboard-calls-search'),
                hint: 'Find a number',
                width: double.infinity,
                onChanged: (v) => setState(() => _callFilter = v.trim()),
                onClear: () => setState(() => _callFilter = ''),
              ),
              if (listed.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  child: Text(
                    calls.isEmpty
                        ? 'No calls in this week.'
                        : 'No call matches "$_callFilter".',
                    style: muted,
                  ),
                ),
              for (final c in listed)
                Padding(
                  key: ValueKey('leaderboard-call-${c['id']}'),
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '${c['from'] ?? 'Unknown number'}',
                              style: theme.textTheme.titleSmall,
                            ),
                            Text(
                              [
                                formatStamp(
                                  c['started_at'],
                                  '',
                                  formats: widget.formats,
                                ),
                                '${c['direction'] ?? ''}',
                                if (c['answered_by'] != null &&
                                    c['answered_by'] != data!['person'])
                                  'answered by ${c['answered_by']}',
                              ].where((s) => s.isNotEmpty).join(' · '),
                              style: muted,
                            ),
                          ],
                        ),
                      ),
                      if (c['booked'] == true)
                        const StatusPill('Booked', dense: true, tone: Tone.ok),
                    ],
                  ),
                ),
            ],
          ),
        );

        const gap = SizedBox(height: 12);
        return ListView(
          key: const ValueKey('leaderboard-mine'),
          padding: const EdgeInsets.all(16),
          children: [
            tileBlock,
            gap,
            if (wide)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: Column(children: [score, gap, reviews])),
                  const SizedBox(width: 12),
                  Expanded(child: Column(children: [scored, gap, every])),
                ],
              )
            else ...[
              score,
              gap,
              reviews,
              gap,
              scored,
              gap,
              every,
            ],
          ],
        );
      },
    );
  }
}
