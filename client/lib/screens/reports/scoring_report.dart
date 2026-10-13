import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/ai_mark.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import 'report_dates.dart';

/// Reports › Scoring (Jose, 2026-10-13: "the Scoring and Performance of each
/// employee ... the employees as cards, and clicking on them shows me the
/// current analysis that the Grok AI has given them, their score, etc ...
/// sort of like a ranking system ... very visual"; UI queue 85, desktop 1
/// and phone 4).
///
/// Reports' `GET /reports/scoring` (08436069): each person's average AI call
/// score out of 10, the seven checks as shares, a few strengths and things to
/// improve from their calls, Workiz's own score where it scores them, the
/// server's rank (none below `min_calls_to_rank` scored calls), and Grok's
/// latest Daily and Weekly read. The dates before are asked too, for the
/// trend. Every view says what the score is: a guide for coaching, not a
/// verdict.
class ScoringReport extends StatefulWidget {
  const ScoringReport({
    super.key,
    required this.api,
    required this.formats,
    this.now,
  });

  final ApiClient api;
  final DisplayFormats formats;
  final DateTime Function()? now;

  @override
  State<ScoringReport> createState() => _ScoringReportState();
}

/// What the seven checks ask, in words (Performance's own list).
const _checks = {
  'greeted': 'Greeted with the company name',
  'got_name': "Got the caller's name",
  'got_address': 'Got the address',
  'found_need': 'Asked what the problem is',
  'offered_time': 'Offered a time',
  'gave_price': 'Explained the price',
  'asked_for_booking': 'Asked to book',
};

const _caveat =
    'Scored by AI from the transcript. A guide for coaching, not a verdict: '
    'the transcript can mishear, and some calls cannot be won.';

List<String> _strings(Object? v) => [
  for (final s in (v as List?) ?? const [])
    if (s is String && s.trim().isNotEmpty) s,
];

class _Person {
  _Person(Map<String, dynamic> row)
    : name = '${row['person']}',
      n = (row['calls_scored'] as num?)?.toInt() ?? 0,
      avg = (row['avg_score'] as num?)?.toDouble() ?? 0,
      rank = (row['rank'] as num?)?.toInt(),
      checks = {
        for (final key in _checks.keys)
          key: (((row['checks'] as Map?)?[key] as num?) ?? 0) / 100,
      },
      strengths = _strings(row['strengths']),
      improve = _strings(row['improve']),
      workiz = (row['workiz'] as Map?)?.cast<String, dynamic>(),
      daily = (row['review_daily'] as Map?)?.cast<String, dynamic>(),
      weekly = (row['review_weekly'] as Map?)?.cast<String, dynamic>();

  final String name;
  final int n;
  final double avg;
  final int? rank;
  final Map<String, double> checks;
  final List<String> strengths;
  final List<String> improve;
  final Map<String, dynamic>? workiz;
  final Map<String, dynamic>? daily;
  final Map<String, dynamic>? weekly;
  double? before;

  bool get ranked => rank != null;
  double? get change => before == null ? null : avg - before!;

  String get initials => [
    for (final p in name.trim().split(RegExp(r'\s+')).take(2))
      if (p.isNotEmpty) p[0].toUpperCase(),
  ].join();
}

class _Team {
  _Team(this.people, this.minCalls);

  final List<_Person> people;
  final int minCalls;
  double? before;

  int get calls => people.fold(0, (s, p) => s + p.n);

  double? get avg => calls == 0
      ? null
      : people.fold<double>(0, (s, p) => s + p.avg * p.n) / calls;

  double rate(String check) => calls == 0
      ? 0
      : people.fold<double>(0, (s, p) => s + (p.checks[check] ?? 0) * p.n) /
            calls;
}

enum _Sort { score, improved }

class _ScoringReportState extends State<ScoringReport> {
  DateTime get _today => DateSpan.single((widget.now ?? DateTime.now)()).from;

  late DateSpan _span = DateSpan(
    DateTime(_today.year, _today.month, _today.day - 29),
    _today,
  );

  bool _loading = true;
  String? _error;
  _Team? _team;
  int _request = 0;
  _Sort _sort = _Sort.score;
  String? _open;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final ticket = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });

    final before = _span.shift(-1);
    final responses = await Future.wait([
      widget.api.get('/reports/scoring', {
        'from': _span.fromKey,
        'to': _span.toKey,
      }),
      widget.api.get('/reports/scoring', {
        'from': before.fromKey,
        'to': before.toKey,
      }),
    ]);
    if (!mounted || ticket != _request) return;

    final now = responses[0];
    setState(() {
      _loading = false;
      if (!now.ok) {
        _error = now.message;
        return;
      }
      final team = _read(now.body);
      // The dates before only feed the arrows; failing there costs nothing else.
      if (responses[1].ok) {
        final earlier = _read(responses[1].body);
        final byName = {for (final p in earlier.people) p.name: p};
        for (final p in team.people) {
          final was = byName[p.name];
          if (was != null && was.n > 0) p.before = was.avg;
        }
        team.before = earlier.avg;
      }
      _team = team;
    });
  }

  static _Team _read(Map<String, dynamic> body) {
    final data = (body['data'] as Map?)?.cast<String, dynamic>() ?? const {};
    return _Team([
      for (final row in (data['people'] as List?) ?? const [])
        if (row is Map && row['person'] is String)
          _Person(row.cast<String, dynamic>()),
    ], (data['min_calls_to_rank'] as num?)?.toInt() ?? 3);
  }

  List<_Person> _sorted(_Team team) {
    final ranked = team.people.where((p) => p.ranked).toList();
    final rest = team.people.where((p) => !p.ranked).toList()
      ..sort((a, b) => b.n.compareTo(a.n));
    if (_sort == _Sort.score) {
      ranked.sort((a, b) => a.rank!.compareTo(b.rank!));
    } else {
      ranked.sort((a, b) => (b.change ?? -99).compareTo(a.change ?? -99));
    }
    return [...ranked, ...rest];
  }

  @override
  Widget build(BuildContext context) {
    final dates = ReportDates(
      initial: _span,
      today: _today,
      formats: widget.formats,
      onChanged: (span) {
        setState(() => _span = span);
        _load();
      },
    );

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 700;
        final team = _team;
        final open = team?.people.where((p) => p.name == _open).firstOrNull;

        final Widget body;
        if (_loading && team == null) {
          body = const Padding(
            padding: EdgeInsets.all(32),
            child: Center(child: CircularProgressIndicator()),
          );
        } else if (_error != null) {
          body = ErrorNote(_error!);
        } else if (team == null || team.people.isEmpty) {
          body = const EmptyState(
            key: Key('scoring-empty'),
            icon: Icons.leaderboard_outlined,
            title: 'No scored calls yet',
            message:
                'None in these dates. AI scores a call once it is '
                'transcribed, and only calls from October 13, 2026 on.',
          );
        } else if (open != null) {
          body = _Detail(
            person: open,
            team: team,
            phone: phone,
            onBack: () => setState(() => _open = null),
            ranking: phone
                ? null
                : _Ranking(
                    people: _sorted(team),
                    team: team,
                    selected: open.name,
                    onOpen: (p) => setState(() => _open = p.name),
                  ),
          );
        } else {
          body = _Overview(
            team: team,
            people: _sorted(team),
            phone: phone,
            onOpen: (p) => setState(() => _open = p.name),
          );
        }

        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Wrap(
              spacing: 12,
              runSpacing: 8,
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                dates,
                if (open == null && team != null && team.people.isNotEmpty)
                  SegmentedButton<_Sort>(
                    key: const Key('scoring-sort'),
                    showSelectedIcon: false,
                    style: const ButtonStyle(
                      visualDensity: VisualDensity.compact,
                    ),
                    segments: const [
                      ButtonSegment(value: _Sort.score, label: Text('Score')),
                      ButtonSegment(
                        value: _Sort.improved,
                        label: Text('Most improved'),
                      ),
                    ],
                    selected: {_sort},
                    onSelectionChanged: (v) => setState(() => _sort = v.first),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            body,
          ],
        );
      },
    );
  }
}

// ── Pieces ───────────────────────────────────────────────────────

String _one(double v) => v.toStringAsFixed(1);

String _pct(double rate) => '${(rate * 100).round()}%';

Color _scoreColour(BuildContext context, double score) => ToneColours.of(
  context,
  score >= 8
      ? Tone.ok
      : score >= 6.5
      ? Tone.info
      : Tone.caution,
).ink;

Widget _trend(BuildContext context, double? change, {String? suffix}) {
  final theme = Theme.of(context);
  if (change == null) {
    return Text(
      'no earlier scores',
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );
  }
  final flat = change.abs() < 0.05;
  final tone = flat ? Tone.neutral : (change > 0 ? Tone.ok : Tone.caution);
  return Text(
    flat
        ? '— same as before'
        : '${change > 0 ? '▲' : '▼'} ${_one(change.abs())}${suffix ?? ''}',
    style: theme.textTheme.bodySmall?.copyWith(
      color: ToneColours.of(context, tone).ink,
      fontWeight: FontWeight.w700,
    ),
  );
}

class _Ring extends StatelessWidget {
  const _Ring({required this.score, this.size = 56});

  final double score;
  final double size;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return SizedBox.square(
      dimension: size,
      child: Stack(
        fit: StackFit.expand,
        children: [
          CircularProgressIndicator(
            value: (score / 10).clamp(0, 1),
            strokeWidth: size / 9,
            strokeCap: StrokeCap.round,
            backgroundColor: scheme.surfaceContainerHighest,
            color: _scoreColour(context, score),
          ),
          Center(
            child: Text(
              _one(score),
              style: TextStyle(
                fontSize: size * 0.3,
                fontWeight: FontWeight.w800,
                color: scheme.onSurface,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Rank extends StatelessWidget {
  const _Rank(this.person, {required this.team, this.of});

  final _Person person;
  final _Team team;
  final int? of;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final tone = switch (person.rank) {
      1 => ToneColours.of(context, Tone.caution),
      2 => ToneColours.of(context, Tone.neutral),
      3 => ToneColours.of(context, Tone.review),
      _ => null,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
      decoration: BoxDecoration(
        color: tone?.fill ?? scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        person.ranked
            ? '#${person.rank}${of == null ? '' : ' of $of'}'
            : 'not enough calls yet',
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w800,
          color: tone?.ink ?? scheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar(this.person, {this.radius = 14});

  final _Person person;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return CircleAvatar(
      radius: radius,
      backgroundColor: scheme.primaryContainer,
      child: Text(
        person.initials,
        style: TextStyle(
          fontSize: radius * 0.75,
          fontWeight: FontWeight.w800,
          color: scheme.onPrimaryContainer,
        ),
      ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.label,
    required this.value,
    this.note,
    this.trailing,
  });

  final String label;
  final String value;
  final String? note;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: muted),
            Row(
              children: [
                Flexible(
                  child: Text(
                    value,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                if (trailing != null) ...[const SizedBox(width: 6), trailing!],
              ],
            ),
            if (note != null)
              Text(
                note!,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: muted,
              ),
          ],
        ),
      ),
    );
  }
}

Widget _equalTiles(List<Widget> tiles, int columns) => LayoutBuilder(
  builder: (context, box) {
    final width = (box.maxWidth - 10 * (columns - 1)) / columns;
    return Wrap(
      spacing: 10,
      runSpacing: 10,
      children: [for (final t in tiles) SizedBox(width: width, child: t)],
    );
  },
);

class _Overview extends StatelessWidget {
  const _Overview({
    required this.team,
    required this.people,
    required this.phone,
    required this.onOpen,
  });

  final _Team team;
  final List<_Person> people;
  final bool phone;
  final ValueChanged<_Person> onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ranked = people.where((p) => p.ranked).toList();
    final unranked = people.where((p) => !p.ranked).toList();
    final improved = [
      for (final p in ranked)
        if ((p.change ?? 0) > 0.05) p,
    ]..sort((a, b) => b.change!.compareTo(a.change!));
    final weakest = _checks.keys.reduce(
      (a, b) => team.rate(a) <= team.rate(b) ? a : b,
    );
    final avg = team.avg!;

    final tiles = [
      _Tile(
        label: 'Team score',
        value: _one(avg),
        trailing: _trend(
          context,
          team.before == null ? null : avg - team.before!,
        ),
        note: 'over ${team.calls} scored calls',
      ),
      _Tile(
        label: 'Ranked',
        value: '${ranked.length} of ${people.length}',
        note: unranked.isEmpty
            ? 'everyone has ${team.minCalls} scored calls'
            : '${unranked.length} with fewer than ${team.minCalls} '
                  'scored calls',
      ),
      _Tile(
        label: 'Most improved',
        value: improved.isEmpty ? '—' : improved.first.name,
        note: improved.isEmpty
            ? 'nobody moved up yet'
            : '${_one(improved.first.before!)} → ${_one(improved.first.avg)}',
      ),
      _Tile(
        label: 'Weakest check, team-wide',
        value: _checks[weakest]!,
        note: 'met on ${_pct(team.rate(weakest))} of calls',
      ),
    ];

    final cards = LayoutBuilder(
      builder: (context, box) {
        final columns = phone ? 1 : math.max(1, (box.maxWidth / 270).floor());
        final width = (box.maxWidth - 10 * (columns - 1)) / columns;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final p in people)
              SizedBox(
                width: width,
                child: _Card(person: p, team: team, onTap: () => onOpen(p)),
              ),
          ],
        );
      },
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        phone
            ? _equalTiles(tiles.take(1).toList(), 1)
            : LayoutBuilder(
                builder: (context, box) =>
                    _equalTiles(tiles, box.maxWidth >= 900 ? 4 : 2),
              ),
        const SizedBox(height: 12),
        if (phone)
          cards
        else
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 320,
                child: _Ranking(people: people, team: team, onOpen: onOpen),
              ),
              const SizedBox(width: 12),
              Expanded(child: cards),
            ],
          ),
        const SizedBox(height: 10),
        Text(
          _caveat,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
            fontStyle: FontStyle.italic,
          ),
        ),
      ],
    );
  }
}

class _Ranking extends StatelessWidget {
  const _Ranking({
    required this.people,
    required this.team,
    required this.onOpen,
    this.selected,
  });

  final List<_Person> people;
  final _Team team;
  final ValueChanged<_Person> onOpen;
  final String? selected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Card(
      key: const Key('scoring-ranking'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(8, 12, 8, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 6),
              child: Text('Ranking', style: theme.textTheme.titleMedium),
            ),
            for (final p in people)
              Material(
                color: p.name == selected
                    ? scheme.primaryContainer
                    : Colors.transparent,
                borderRadius: BorderRadius.circular(8),
                child: InkWell(
                  key: ValueKey('scoring-rank-${p.name}'),
                  borderRadius: BorderRadius.circular(8),
                  onTap: () => onOpen(p),
                  child: Opacity(
                    opacity: p.ranked ? 1 : 0.6,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 6,
                      ),
                      child: Row(
                        children: [
                          SizedBox(
                            width: 20,
                            child: Text(
                              p.ranked ? '${p.rank}' : '–',
                              textAlign: TextAlign.end,
                              style: theme.textTheme.bodySmall?.copyWith(
                                fontWeight: FontWeight.w800,
                                color: scheme.onSurfaceVariant,
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          _Avatar(p, radius: 12),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              p.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodyMedium?.copyWith(
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          SizedBox(
                            width: 70,
                            child: p.ranked
                                ? ClipRRect(
                                    borderRadius: BorderRadius.circular(4),
                                    child: LinearProgressIndicator(
                                      value: p.avg / 10,
                                      minHeight: 8,
                                      backgroundColor:
                                          scheme.surfaceContainerHighest,
                                      color: _scoreColour(context, p.avg),
                                    ),
                                  )
                                : Text(
                                    '${p.n} of ${team.minCalls} calls',
                                    style: theme.textTheme.bodySmall,
                                  ),
                          ),
                          SizedBox(
                            width: 34,
                            child: Text(
                              p.ranked ? _one(p.avg) : '–',
                              textAlign: TextAlign.end,
                              style: theme.textTheme.bodyMedium?.copyWith(
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// The seven checks as marks: green where this person meets it at least as
/// often as the team does.
Widget _marks(BuildContext context, _Person p, _Team team) => Row(
  children: [
    for (final key in _checks.keys)
      Tooltip(
        message: '${_checks[key]}: ${_pct(p.checks[key]!)}',
        child: Container(
          width: 16,
          height: 6,
          margin: const EdgeInsets.only(right: 3),
          decoration: BoxDecoration(
            color: p.checks[key]! >= team.rate(key)
                ? ToneColours.of(context, Tone.ok).ink
                : ToneColours.of(
                    context,
                    Tone.caution,
                  ).ink.withValues(alpha: 0.55),
            borderRadius: BorderRadius.circular(3),
          ),
        ),
      ),
  ],
);

class _Card extends StatelessWidget {
  const _Card({required this.person, required this.team, required this.onTap});

  final _Person person;
  final _Team team;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = person;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        key: ValueKey('scoring-card-${p.name}'),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  _Avatar(p),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      p.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                  _Rank(p, team: team),
                ],
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  _Ring(score: p.avg),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _trend(context, p.change),
                        Text(
                          '${p.n} scored ${p.n == 1 ? 'call' : 'calls'}',
                          style: muted,
                        ),
                        if (p.weekly?['summary'] case final String s) ...[
                          const SizedBox(height: 4),
                          Text(
                            s,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              _marks(context, p, team),
            ],
          ),
        ),
      ),
    );
  }
}

class _Detail extends StatefulWidget {
  const _Detail({
    required this.person,
    required this.team,
    required this.phone,
    required this.onBack,
    this.ranking,
  });

  final _Person person;
  final _Team team;
  final bool phone;
  final VoidCallback onBack;
  final Widget? ranking;

  @override
  State<_Detail> createState() => _DetailState();
}

class _DetailState extends State<_Detail> {
  /// Weekly first: a week of calls says more than one day's.
  bool _weekly = true;

  static const _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  static String _day(Object? ymd) {
    final d = ymd is String ? DateTime.tryParse(ymd) : null;
    return d == null ? '' : '${_months[d.month - 1]} ${d.day}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final p = widget.person;
    final team = widget.team;
    final phone = widget.phone;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final ranked = team.people.where((x) => x.ranked).length;
    final workiz = p.workiz;

    Widget section(String title, Widget child, {Widget? trailing}) => Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(child: Text(title, style: theme.textTheme.titleSmall)),
                ?trailing,
              ],
            ),
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );

    final head = Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          children: [
            _Ring(score: p.avg, size: phone ? 72 : 100),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(
                        p.name,
                        style: theme.textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      _Rank(p, team: team, of: ranked),
                    ],
                  ),
                  Text(
                    '${p.n} scored ${p.n == 1 ? 'call' : 'calls'} in these '
                    'dates',
                    style: muted,
                  ),
                  const SizedBox(height: 4),
                  _trend(context, p.change, suffix: ' vs the dates before'),
                  if (workiz != null && workiz['score'] != null) ...[
                    const SizedBox(height: 4),
                    _Chip(
                      "Workiz's own score ${workiz['score']} / 100 over "
                      "${workiz['calls']} calls",
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );

    final checks = section(
      'The seven checks',
      Column(
        children: [
          for (final key in _checks.keys)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                children: [
                  SizedBox(
                    width: phone ? 120 : 200,
                    child: Text(
                      _checks[key]!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                  Expanded(
                    child: LayoutBuilder(
                      builder: (context, box) => Stack(
                        clipBehavior: Clip.none,
                        alignment: Alignment.centerLeft,
                        children: [
                          ClipRRect(
                            borderRadius: BorderRadius.circular(4),
                            child: LinearProgressIndicator(
                              value: p.checks[key],
                              minHeight: 8,
                              backgroundColor: scheme.surfaceContainerHighest,
                              color: p.checks[key]! >= team.rate(key)
                                  ? ToneColours.of(context, Tone.ok).ink
                                  : ToneColours.of(context, Tone.caution).ink,
                            ),
                          ),
                          // The team's share, as a mark on the bar.
                          Positioned(
                            left: (box.maxWidth * team.rate(key) - 1).clamp(
                              0,
                              math.max(0, box.maxWidth - 2),
                            ),
                            child: Container(
                              width: 2,
                              height: 14,
                              color: scheme.onSurface.withValues(alpha: 0.6),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  SizedBox(
                    width: 44,
                    child: Text(
                      _pct(p.checks[key]!),
                      textAlign: TextAlign.end,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
      trailing: Text('mark: the team', style: muted),
    );

    Widget list(String title, List<String> items) => items.isEmpty
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(title, style: theme.textTheme.labelLarge),
                for (final item in items)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text('• $item', style: theme.textTheme.bodyMedium),
                  ),
              ],
            ),
          );

    // Grok's read of the person (Grok AI, ezbizi:ai-person-reviews).
    final review = _weekly ? p.weekly : p.daily;
    final trend = switch (review?['trend']) {
      'up' => 'Better than the period before.',
      'down' => 'Below the period before.',
      'flat' => 'About the same as the period before.',
      _ => null,
    };
    final analysis = section(
      'Analysis by AI',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              key: const Key('scoring-review-period'),
              showSelectedIcon: false,
              style: const ButtonStyle(visualDensity: VisualDensity.compact),
              segments: const [
                ButtonSegment(value: false, label: Text('Daily')),
                ButtonSegment(value: true, label: Text('Weekly')),
              ],
              selected: {_weekly},
              onSelectionChanged: (v) => setState(() => _weekly = v.first),
            ),
          ),
          const SizedBox(height: 10),
          if (review == null)
            Text(
              _weekly
                  ? 'No weekly read yet. AI writes one each Monday for the '
                        'week before, for anyone with 3 scored calls in it.'
                  : 'No daily read yet. AI writes one each morning for the '
                        'day before, for anyone with 3 scored calls that day.',
              key: const Key('scoring-review-none'),
              style: muted,
            )
          else ...[
            Text(
              [
                if (_day(review['from']) case final from when from.isNotEmpty)
                  review['from'] == review['to']
                      ? from
                      : '$from – ${_day(review['to'])}',
                if (review['calls'] is num) 'from ${review['calls']} calls',
              ].join(' · '),
              style: muted,
            ),
            const SizedBox(height: 6),
            Text(
              '${review['summary'] ?? ''}',
              key: const Key('scoring-review-summary'),
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 10),
            list('Strengths', _strings(review['strengths'])),
            list('Work on', _strings(review['work_on'])),
            if (trend != null) Text(trend, style: muted),
          ],
        ],
      ),
      trailing: const AiMark.badge('Analysis by AI'),
    );

    final notes = section(
      'From their calls',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          list('Strong at', p.strengths),
          list('To improve', p.improve),
          Text(_caveat, style: muted?.copyWith(fontStyle: FontStyle.italic)),
        ],
      ),
    );

    final back = Align(
      alignment: Alignment.centerLeft,
      child: TextButton.icon(
        key: const Key('scoring-back'),
        onPressed: widget.onBack,
        icon: const Icon(Icons.arrow_back),
        label: const Text('All people'),
      ),
    );

    const gap = SizedBox(height: 10);
    if (phone) {
      return Column(
        key: ValueKey('scoring-detail-${p.name}'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [back, head, gap, analysis, gap, checks, gap, notes],
      );
    }

    return Column(
      key: ValueKey('scoring-detail-${p.name}'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        back,
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (widget.ranking != null) ...[
              SizedBox(width: 320, child: widget.ranking),
              const SizedBox(width: 12),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [head, gap, checks, gap, notes],
              ),
            ),
            const SizedBox(width: 12),
            SizedBox(width: 360, child: analysis),
          ],
        ),
      ],
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final colours = ToneColours.of(context, Tone.neutral);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
          color: colours.ink,
        ),
      ),
    );
  }
}
