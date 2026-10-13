import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/ai_mark.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import 'report_dates.dart';

/// Reports › Scoring (Jose, 2026-10-13: "the Scoring and Performance of each
/// employee ... the employees as cards, and clicking on them shows me the
/// current analysis ... sort of like a ranking system ... very visual"; UI
/// queue 85, desktop 1 and phone 4).
///
/// Built from the calls Performance reads (`GET /reports/experimental/calls`):
/// each scored call carries AI's score out of 10, the seven checks, one
/// strength and one thing to improve; who answered comes from the call log.
/// A person is ranked after [rankedAfter] scored calls in the dates chosen
/// (Grok AI's rule, 2026-10-13), and every view says what the score is: a
/// guide for coaching, not a verdict.
///
/// ponytail: averaged here, as People does; a server endpoint and Grok's
/// Daily and Weekly read per person (being built by Grok AI) slot in beside
/// [_Person] when they exist.
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

  static const rankedAfter = 10;

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

class _Note {
  const _Note(this.text, this.day);
  final String text;
  final String day;
}

class _Person {
  _Person(this.name);

  final String name;
  final scores = <double>[];
  final checks = <String, int>{};
  final improve = <_Note>[];
  final strengths = <_Note>[];
  final byDay = <String, List<double>>{};
  int answered = 0;
  int booked = 0;
  double? before;
  Map<String, dynamic>? workiz;
  int rank = 0;

  int get n => scores.length;
  double get avg => scores.reduce((a, b) => a + b) / n;
  bool get ranked => n >= ScoringReport.rankedAfter;
  double? get change => before == null ? null : avg - before!;
  double? get bookedRate => answered == 0 ? null : booked / answered;
  double rate(String check) => n == 0 ? 0 : (checks[check] ?? 0) / n;

  String get initials {
    final parts = name.trim().split(RegExp(r'\s+'));
    return [
      for (final p in parts.take(2))
        if (p.isNotEmpty) p[0].toUpperCase(),
    ].join();
  }
}

class _Team {
  _Team(this.people, this.calls);

  final List<_Person> people;
  final List<double> calls;
  double? before;

  double? get avg =>
      calls.isEmpty ? null : calls.reduce((a, b) => a + b) / calls.length;

  double rate(String check) {
    final n = people.fold<int>(0, (s, p) => s + p.n);
    if (n == 0) return 0;
    return people.fold<int>(0, (s, p) => s + (p.checks[check] ?? 0)) / n;
  }

  double? get bookedRate {
    final answered = people.fold<int>(0, (s, p) => s + p.answered);
    if (answered == 0) return null;
    return people.fold<int>(0, (s, p) => s + p.booked) / answered;
  }
}

enum _Sort { score, improved, booked }

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
      widget.api.get('/reports/experimental/calls', {
        'from': _span.fromKey,
        'to': _span.toKey,
      }),
      widget.api.get('/reports/experimental/calls', {
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
      // The dates before, for the trend; a failure there only costs the arrow.
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

  static _Team _read(Map<String, dynamic> report) {
    final people = <String, _Person>{};
    final all = <double>[];
    for (final c in (report['calls'] as List? ?? const [])) {
      if (c is! Map) continue;
      final name = c['answered_by'];
      if (name is! String || name.trim().isEmpty) continue;
      final p = people.putIfAbsent(name, () => _Person(name));
      p.answered++;
      if (c['booked'] == true) p.booked++;

      final score = c['score'];
      if (score is! Map || score['score'] is! num) continue;
      final value = (score['score'] as num).toDouble();
      final day = '${c['started_at'] ?? ''}'.split('T').first;
      p.scores.add(value);
      all.add(value);
      (p.byDay[day] ??= []).add(value);
      final checks = score['checks'];
      if (checks is Map) {
        for (final key in _checks.keys) {
          if (checks[key] == true) p.checks[key] = (p.checks[key] ?? 0) + 1;
        }
      }
      for (final (field, into) in [
        ('improve', p.improve),
        ('strength', p.strengths),
      ]) {
        final text = '${score[field] ?? ''}'.trim();
        if (text.isNotEmpty) into.add(_Note(text, day));
      }
    }

    final workiz = (report['workiz_scores'] as Map?) ?? const {};
    final scored = [
      for (final p in people.values)
        if (p.n > 0)
          p..workiz = (workiz[p.name] as Map?)?.cast<String, dynamic>(),
    ];
    for (final p in scored) {
      p.improve.sort((a, b) => b.day.compareTo(a.day));
      p.strengths.sort((a, b) => b.day.compareTo(a.day));
    }
    final ranked = scored.where((p) => p.ranked).toList()
      ..sort((a, b) => b.avg.compareTo(a.avg));
    for (final (i, p) in ranked.indexed) {
      p.rank = i + 1;
    }

    return _Team(scored, all);
  }

  List<_Person> _sorted(_Team team) {
    final ranked = team.people.where((p) => p.ranked).toList();
    final rest = team.people.where((p) => !p.ranked).toList()
      ..sort((a, b) => b.n.compareTo(a.n));
    switch (_sort) {
      case _Sort.score:
        ranked.sort((a, b) => a.rank.compareTo(b.rank));
      case _Sort.improved:
        ranked.sort((a, b) => (b.change ?? -99).compareTo(a.change ?? -99));
      case _Sort.booked:
        ranked.sort(
          (a, b) => (b.bookedRate ?? -1).compareTo(a.bookedRate ?? -1),
        );
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
          body = Padding(
            key: const Key('scoring-empty'),
            padding: const EdgeInsets.all(24),
            child: Text(
              'No scored calls in these dates. AI scores a call once it is '
              'transcribed; only calls from October 13, 2026 on are scored.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          );
        } else if (open != null) {
          body = _Detail(
            person: open,
            team: team,
            span: _span,
            phone: phone,
            onBack: () => setState(() => _open = null),
            ranking: phone
                ? null
                : _Ranking(
                    people: _sorted(team),
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
                      ButtonSegment(value: _Sort.booked, label: Text('Booked')),
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

String _pct(double? rate) => rate == null ? '—' : '${(rate * 100).round()}%';

Tone _toneOf(double score) => score >= 8
    ? Tone.ok
    : score >= 6.5
    ? Tone.info
    : Tone.caution;

Color _scoreColour(BuildContext context, double score) =>
    ToneColours.of(context, _toneOf(score)).ink;

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
  final text = flat
      ? '— same as before'
      : '${change > 0 ? '▲' : '▼'} ${_one(change.abs())}${suffix ?? ''}';
  return Text(
    text,
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
  const _Rank(this.person, {this.of});

  final _Person person;
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
    final text = person.ranked
        ? '#${person.rank}${of == null ? '' : ' of $of'}'
        : '${person.n} of ${ScoringReport.rankedAfter} calls';
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
      decoration: BoxDecoration(
        color: tone?.fill ?? scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text,
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

/// A line through each day's average, with the team's as a dashed rule.
class _Line extends CustomPainter {
  _Line(this.points, {required this.colour, this.team, this.rule});

  final List<double> points;
  final Color colour;
  final double? team;
  final Color? rule;

  @override
  void paint(Canvas canvas, Size size) {
    if (points.isEmpty) return;
    double y(double v) => size.height - (v / 10) * size.height;
    if (team != null && rule != null) {
      final paint = Paint()
        ..color = rule!
        ..strokeWidth = 1;
      for (var x = 0.0; x < size.width; x += 8) {
        canvas.drawLine(
          Offset(x, y(team!)),
          Offset(math.min(x + 4, size.width), y(team!)),
          paint,
        );
      }
    }
    final step = points.length == 1 ? 0.0 : size.width / (points.length - 1);
    final path = Path();
    for (final (i, v) in points.indexed) {
      final at = Offset(points.length == 1 ? size.width / 2 : i * step, y(v));
      i == 0 ? path.moveTo(at.dx, at.dy) : path.lineTo(at.dx, at.dy);
    }
    canvas.drawPath(
      path,
      Paint()
        ..color = colour
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.2
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(_Line old) =>
      old.points != points || old.colour != colour || old.team != team;
}

List<double> _daily(_Person p) {
  final days = p.byDay.keys.toList()..sort();
  return [
    for (final d in days)
      p.byDay[d]!.reduce((a, b) => a + b) / p.byDay[d]!.length,
  ];
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
        note: 'over ${team.calls.length} scored calls',
      ),
      _Tile(
        label: 'Ranked',
        value: '${ranked.length} of ${people.length}',
        note: unranked.isEmpty
            ? 'everyone has ${ScoringReport.rankedAfter} scored calls'
            : '${unranked.first.name} needs '
                  '${ScoringReport.rankedAfter - unranked.first.n} more calls',
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
        LayoutBuilder(
          builder: (context, box) {
            final columns = phone ? 1 : (box.maxWidth >= 900 ? 4 : 2);
            final shown = phone ? tiles.take(1).toList() : tiles;
            final width = (box.maxWidth - 10 * (columns - 1)) / columns;
            return Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                for (final t in shown) SizedBox(width: width, child: t),
              ],
            );
          },
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
                child: _Ranking(people: people, onOpen: onOpen),
              ),
              const SizedBox(width: 12),
              Expanded(child: cards),
            ],
          ),
        const SizedBox(height: 10),
        Text(
          _caveat,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
            color: Theme.of(context).colorScheme.onSurfaceVariant,
            fontStyle: FontStyle.italic,
          ),
        ),
      ],
    );
  }
}

class _Ranking extends StatelessWidget {
  const _Ranking({required this.people, required this.onOpen, this.selected});

  final List<_Person> people;
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
                                    '${p.n} of ${ScoringReport.rankedAfter}',
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

class _Card extends StatelessWidget {
  const _Card({required this.person, required this.team, required this.onTap});

  final _Person person;
  final _Team team;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final p = person;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final daily = _daily(p);

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
                  _Rank(p),
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
                          '${p.n} scored ${p.n == 1 ? 'call' : 'calls'} · '
                          '${_pct(p.bookedRate)} booked',
                          style: muted,
                        ),
                        const SizedBox(height: 4),
                        SizedBox(
                          height: 24,
                          width: double.infinity,
                          child: CustomPaint(
                            painter: _Line(
                              daily,
                              colour: _scoreColour(context, p.avg),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              // The seven checks as marks: green where this person meets it
              // at least as often as the team does.
              Row(
                children: [
                  for (final key in _checks.keys)
                    Tooltip(
                      message: '${_checks[key]}: ${_pct(p.rate(key))}',
                      child: Container(
                        width: 16,
                        height: 6,
                        margin: const EdgeInsets.only(right: 3),
                        decoration: BoxDecoration(
                          color: p.rate(key) >= team.rate(key)
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
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Detail extends StatelessWidget {
  const _Detail({
    required this.person,
    required this.team,
    required this.span,
    required this.phone,
    required this.onBack,
    this.ranking,
  });

  final _Person person;
  final _Team team;
  final DateSpan span;
  final bool phone;
  final VoidCallback onBack;
  final Widget? ranking;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final p = person;
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
                      _Rank(p, of: ranked),
                    ],
                  ),
                  Text(
                    '${p.n} scored ${p.n == 1 ? 'call' : 'calls'} in these dates',
                    style: muted,
                  ),
                  const SizedBox(height: 4),
                  _trend(context, p.change, suffix: ' vs the dates before'),
                  const SizedBox(height: 4),
                  Wrap(
                    spacing: 6,
                    runSpacing: 4,
                    children: [
                      _Chip(
                        '${_pct(p.bookedRate)} booked · team '
                        '${_pct(team.bookedRate)}',
                        tone: Tone.ok,
                      ),
                      if (workiz != null && workiz['score'] != null)
                        _Chip("Workiz's score ${workiz['score']} / 100"),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );

    final daily = _daily(p);
    final line = section(
      'Score by day',
      SizedBox(
        height: 70,
        child: CustomPaint(
          painter: _Line(
            daily,
            colour: _scoreColour(context, p.avg),
            team: team.avg,
            rule: scheme.onSurfaceVariant.withValues(alpha: 0.6),
          ),
        ),
      ),
      trailing: Text('dashed: the team, ${_one(team.avg!)}', style: muted),
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
                              value: p.rate(key),
                              minHeight: 8,
                              backgroundColor: scheme.surfaceContainerHighest,
                              color: p.rate(key) >= team.rate(key)
                                  ? ToneColours.of(context, Tone.ok).ink
                                  : ToneColours.of(context, Tone.caution).ink,
                            ),
                          ),
                          // The team's rate, as a mark on the bar.
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
                      _pct(p.rate(key)),
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

    Widget notes(String title, List<_Note> list) => list.isEmpty
        ? const SizedBox.shrink()
        : Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(title, style: theme.textTheme.labelLarge),
              for (final note in list.take(3))
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          '“${note.text}”',
                          style: theme.textTheme.bodyMedium,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text(_shortDay(note.day), style: muted),
                    ],
                  ),
                ),
              const SizedBox(height: 10),
            ],
          );

    final read = section(
      'From their calls',
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          notes('Strong at', p.strengths),
          notes('To improve', p.improve),
          Text(_caveat, style: muted?.copyWith(fontStyle: FontStyle.italic)),
        ],
      ),
      trailing: const AiMark.badge(
        'AI',
        tooltip: 'Written by AI from each scored call.',
      ),
    );

    final back = Align(
      alignment: Alignment.centerLeft,
      child: TextButton.icon(
        key: const Key('scoring-back'),
        onPressed: onBack,
        icon: const Icon(Icons.arrow_back),
        label: const Text('All people'),
      ),
    );

    if (phone) {
      return Column(
        key: ValueKey('scoring-detail-${p.name}'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          back,
          head,
          const SizedBox(height: 10),
          read,
          const SizedBox(height: 10),
          checks,
          const SizedBox(height: 10),
          line,
        ],
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
            if (ranking != null) ...[
              SizedBox(width: 320, child: ranking),
              const SizedBox(width: 12),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  head,
                  const SizedBox(height: 10),
                  line,
                  const SizedBox(height: 10),
                  checks,
                ],
              ),
            ),
            const SizedBox(width: 12),
            SizedBox(width: 340, child: read),
          ],
        ),
      ],
    );
  }

  static const _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  static String _shortDay(String ymd) {
    final d = DateTime.tryParse(ymd);
    return d == null ? '' : '${_months[d.month - 1]} ${d.day}';
  }
}

class _Chip extends StatelessWidget {
  const _Chip(this.text, {this.tone = Tone.neutral});

  final String text;
  final Tone tone;

  @override
  Widget build(BuildContext context) {
    final colours = ToneColours.of(context, tone);
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
