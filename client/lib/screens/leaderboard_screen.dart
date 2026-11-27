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
                  myId: me.id,
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

String _pct(Object? rate) => rate is num ? '${rate.round()}%' : '—';

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
    this.myId,
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

  /// Whose row is "You" in the ranked list.
  final String? myId;

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

  /// Where the reader stands; when they are not ranked, why, and who can
  /// change it (design 212's four empty states).
  Widget _you(BuildContext context, Map<String, dynamic> me, Object? min) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final on = scheme.onPrimaryContainer;
    final calls = me['decided'] as int? ?? 0;
    final need = min is int ? (min - calls).clamp(1, min) : 1;
    final (String head, String? body) = switch (me['why']) {
      null when me['rank'] != null => ('#${me['rank']}', null),
      'not_new' => (
        'Not on New Client Calls',
        'You are not ranked: only people who take New Client Calls are. '
            'Your manager marks that in Reports › Performance › People.',
      ),
      'too_few_calls' => (
        '$calls of ${min ?? '?'} new-client calls',
        'Answer $need more new-client call${need == 1 ? '' : 's'} this week '
            'and you are on the board.',
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
                  '${_pct(me['booking_rate'])} booked · answer rate '
                  '${_pct(me['answer_rate'])} · ${_score(me['score'])} / 10',
                  style: theme.textTheme.titleSmall?.copyWith(color: on),
                ),
              ],
            ],
          ),
          if (ranked && _Spark.of(me['trend']).whereType<num>().length > 1)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: _Spark(_Spark.of(me['trend']), width: 220, height: 36),
            ),
          // What moves you up (design 214): the bookings that pass the
          // person above at your call count.
          if (me['to_next'] case final Map next
              when ranked && next['bookings'] is int)
            Padding(
              key: const ValueKey('leaderboard-to-next'),
              padding: const EdgeInsets.only(top: 4),
              child: Text.rich(
                TextSpan(
                  children: [
                    TextSpan(
                      text:
                          '${next['bookings']} more booking'
                          '${next['bookings'] == 1 ? '' : 's'}',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    TextSpan(
                      text:
                          ' at your call count put you past '
                          '${next['person'] ?? '#${next['rank']}'}'
                          '${next['person'] != null ? ' (#${next['rank']})' : ''}.',
                    ),
                  ],
                ),
                style: theme.textTheme.bodyMedium?.copyWith(color: on),
              ),
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
                              '${_pct((w['top'] as List).first['booking_rate'])}',
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

  Widget _avatar(Map p, double radius) {
    final name = '${p['person'] ?? ''}';
    final id = p['user_id'];
    return id is String
        ? UserAvatar(api: widget.api, userId: id, name: name, radius: radius)
        : CircleAvatar(
            radius: radius,
            backgroundColor:
                EzBiziTheme.avatarPalette[name.hashCode.abs() %
                    EzBiziTheme.avatarPalette.length],
            child: Text(
              name.isEmpty ? '#' : name[0].toUpperCase(),
              style: TextStyle(
                color: EzBiziTheme.fieldInkLight,
                fontWeight: FontWeight.w700,
                fontSize: radius * 0.8,
              ),
            ),
          );
  }

  /// The team's week (design 214): five equal tiles, each with its change.
  Widget _team(BuildContext context, Map team, {required bool phone}) {
    final theme = Theme.of(context);
    final change = (team['change'] as Map?) ?? const {};
    Widget tile(String label, String value, Object? delta, String units) {
      // "1 pt", "2 pts".
      final unit = units == ' pts' && delta is num && delta.abs() == 1
          ? ' pt'
          : units;
      final up = delta is num && delta > 0;
      final down = delta is num && delta < 0;
      return Card(
        key: ValueKey('leaderboard-team-$label'),
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(value, style: theme.textTheme.headlineSmall),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: label),
                    if (delta is num)
                      TextSpan(
                        text:
                            '  ${up
                                ? '▲'
                                : down
                                ? '▼'
                                : '–'} '
                            '${up || down ? '${delta.abs().toStringAsFixed(delta.abs() < 10 && delta % 1 != 0 ? 1 : 0)}$unit' : ''}',
                        style: TextStyle(
                          color: up
                              ? ToneColours.of(context, Tone.ok).ink
                              : down
                              ? ToneColours.of(context, Tone.bad).ink
                              : null,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                  ],
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      );
    }

    final tiles = [
      tile(
        'New client calls',
        '${team['new_calls'] ?? 0}',
        change['new_calls'],
        '%',
      ),
      tile('Booked', '${team['booked'] ?? 0}', change['booked'], '%'),
      tile(
        'Team booking rate',
        _pct(team['booking_rate']),
        change['booking_rate'],
        ' pts',
      ),
      tile(
        'Answer rate',
        _pct(team['answer_rate']),
        change['answer_rate'],
        ' pts',
      ),
      if (!phone || team['score'] != null)
        tile('Avg score', '${_score(team['score'])} / 10', change['score'], ''),
    ];
    Widget row(List<Widget> ws) => IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (i, w) in ws.indexed) ...[
            if (i > 0) const SizedBox(width: 10),
            Expanded(child: w),
          ],
        ],
      ),
    );
    if (!phone) return row(tiles);
    return Column(
      children: [
        for (var i = 0; i < tiles.length; i += 2) ...[
          if (i > 0) const SizedBox(height: 10),
          row([
            tiles[i],
            if (i + 1 < tiles.length) tiles[i + 1] else const SizedBox(),
          ]),
        ],
      ],
    );
  }

  /// 2 · 1 · 3, the steps in the medals' colours (design 214).
  Widget _podium(BuildContext context, List<Map> top, {required bool phone}) {
    final theme = Theme.of(context);
    final order = [if (top.length > 1) 1, 0, if (top.length > 2) 2];
    return Row(
      key: const ValueKey('leaderboard-podium'),
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        for (final i in order)
          Expanded(
            child: Padding(
              key: ValueKey('leaderboard-top-${top[i]['rank']}'),
              padding: const EdgeInsets.symmetric(horizontal: 6),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  _avatar(top[i], phone ? 18 : 26),
                  const SizedBox(height: 6),
                  Text(
                    '${top[i]['person'] ?? ''}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    _pct(top[i]['booking_rate']),
                    style: phone
                        ? theme.textTheme.titleLarge
                        : theme.textTheme.headlineMedium,
                  ),
                  Text(
                    '${top[i]['booked'] ?? 0} of ${top[i]['decided'] ?? 0} booked',
                    style: theme.textTheme.bodySmall,
                  ),
                  if (!phone) ...[
                    Text(
                      'Answer ${_pct(top[i]['answer_rate'])} · '
                      'Score ${_score(top[i]['score'])}',
                      style: theme.textTheme.bodySmall,
                    ),
                    const SizedBox(height: 4),
                    _Spark(_Spark.of(top[i]['trend']), width: 110, height: 24),
                  ],
                  const SizedBox(height: 6),
                  Container(
                    height: (phone
                        ? [54.0, 40.0, 30.0]
                        : [88.0, 64.0, 48.0])[i],
                    alignment: Alignment.topCenter,
                    padding: const EdgeInsets.only(top: 6),
                    decoration: BoxDecoration(
                      color: EzBiziTheme.medals[i],
                      borderRadius: const BorderRadius.vertical(
                        top: Radius.circular(10),
                      ),
                    ),
                    child: Text(
                      '${i + 1}',
                      style: theme.textTheme.titleLarge?.copyWith(
                        color: EzBiziTheme.fieldInkLight,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  /// Everyone ranked (design 214); past 3rd a row without a name shows its
  /// place and rate only, until Jose says names show for everyone.
  Widget _ranking(
    BuildContext context,
    List<Map> rows, {
    required bool phone,
    required String? myId,
  }) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall;
    Widget head(String t, {double? width, int flex = 0}) {
      final text = Text(t, style: muted?.copyWith(fontWeight: FontWeight.w600));
      return width != null
          ? SizedBox(width: width, child: text)
          : Expanded(flex: flex == 0 ? 1 : flex, child: text);
    }

    return Column(
      key: const ValueKey('leaderboard-ranked'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Row(
            children: [
              head('#', width: 34),
              head('Person', flex: 3),
              head('Booking rate', flex: 3),
              if (!phone) ...[
                head('Booked', width: 70),
                head('Answer', width: 60),
                head('Score', width: 50),
                head('6 weeks', width: 90),
              ],
              head('Week', width: 44),
            ],
          ),
        ),
        for (final r in rows)
          Container(
            key: ValueKey('leaderboard-row-${r['rank']}'),
            padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
            decoration: BoxDecoration(
              color: (r['me'] == true || (myId != null && r['user_id'] == myId))
                  ? theme.colorScheme.primaryContainer
                  : null,
              borderRadius: BorderRadius.circular(6),
              border: Border(top: BorderSide(color: theme.dividerColor)),
            ),
            child: Row(
              children: [
                SizedBox(
                  width: 34,
                  child: (r['rank'] as int? ?? 9) <= 3
                      ? Align(
                          alignment: Alignment.centerLeft,
                          child: _Medal(r['rank'] as int, size: 22),
                        )
                      : Text('${r['rank'] ?? ''}'),
                ),
                Expanded(
                  flex: 3,
                  child: Row(
                    children: [
                      if (r['person'] != null) ...[
                        _avatar(r, 12),
                        const SizedBox(width: 8),
                      ],
                      Flexible(
                        child: Text(
                          r['me'] == true ||
                                  (myId != null && r['user_id'] == myId)
                              ? 'You'
                              : '${r['person'] ?? '—'}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ),
                Expanded(
                  flex: 3,
                  child: Row(
                    children: [
                      SizedBox(
                        width: 40,
                        child: Text(
                          _pct(r['booking_rate']),
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                      ),
                      if (!phone)
                        Expanded(
                          child: Padding(
                            padding: const EdgeInsets.only(right: 12),
                            child: LinearProgressIndicator(
                              value: ((r['booking_rate'] as num?) ?? 0) / 100,
                              minHeight: 6,
                              borderRadius: BorderRadius.circular(3),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                if (!phone) ...[
                  SizedBox(
                    width: 70,
                    child: Text('${r['booked'] ?? 0} / ${r['decided'] ?? 0}'),
                  ),
                  SizedBox(width: 60, child: Text(_pct(r['answer_rate']))),
                  SizedBox(width: 50, child: Text(_score(r['score']))),
                  SizedBox(
                    width: 90,
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: _Spark(_Spark.of(r['trend'])),
                    ),
                  ),
                ],
                SizedBox(width: 44, child: _Change(r['change'])),
              ],
            ),
          ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final waiting = pending();
    if (waiting != null) return waiting;
    final theme = Theme.of(context);
    final top = [for (final p in data!['top'] as List) (p as Map).cast()];
    // Everyone ranked, once Reports sends it; the top three until then.
    final ranked = [
      for (final p in (data!['ranked'] as List?) ?? data!['top'] as List)
        (p as Map).cast(),
    ];
    final team = data!['team'] as Map?;
    final me = (data!['me'] as Map).cast<String, dynamic>();
    final min = data!['min_calls_to_rank'];
    const gap = SizedBox(height: 12);

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 600;
        final wide = box.maxWidth >= 1000;
        final podium = _Section(
          'Top 3 by booking rate · New Client Calls',
          top.isEmpty
              ? Text(
                  'Nobody is ranked yet. No one has answered '
                  '${min ?? 3} new-client calls in this week; try the '
                  'week before.',
                  key: const ValueKey('leaderboard-nobody'),
                  style: theme.textTheme.bodyMedium,
                )
              : _podium(context, top, phone: phone),
        );
        final list = ranked.length > top.length
            ? _Section(
                'Everyone ranked',
                _ranking(context, ranked, phone: phone, myId: widget.myId),
              )
            : null;
        final you = _you(context, me, min);
        final past = _weeks.any((w) => (w['top'] as List?)?.isNotEmpty ?? false)
            ? _past(context)
            : null;

        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (team != null) ...[_team(context, team, phone: phone), gap],
            if (wide)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    flex: 2,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        podium,
                        if (list != null) ...[gap, list],
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        you,
                        if (past != null) ...[gap, past],
                      ],
                    ),
                  ),
                ],
              )
            else ...[
              podium,
              gap,
              you,
              if (list != null) ...[gap, list],
              if (past != null) ...[gap, past],
            ],
          ],
        );
      },
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
  String? _scoredError;
  int _searches = 0;
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
    final ticket = ++_searches;
    final r = await widget.api.get(
      '/leaderboard/mine/calls?$_dates'
      '${_query.isEmpty ? '' : '&q=${Uri.encodeQueryComponent(_query)}'}',
    );
    // An older search answering late does not replace a newer one.
    if (!mounted || ticket != _searches) return;
    setState(() {
      // A failed search says so, not "No scored calls" (audit 2026-11-27).
      _scoredError = r.ok ? null : r.message;
      _scored = r.ok
          ? [for (final c in r.body['data'] as List) (c as Map).cast()]
          : [];
    });
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
                    _scoredError ??
                        (_query.isEmpty
                            ? 'No scored calls in this week.'
                            : 'No scored calls match "$_query".'),
                    key: const ValueKey('leaderboard-scored-empty'),
                    style: _scoredError == null
                        ? muted
                        : muted?.copyWith(color: theme.colorScheme.error),
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

/// A person's last weeks as a line (design 214): booking rate per week,
/// oldest first, a gap where they were not ranked, a dot on the latest.
class _Spark extends StatelessWidget {
  const _Spark(this.values, {this.width = 80, this.height = 22});

  final List<num?> values;
  final double width;
  final double height;

  static List<num?> of(Object? trend) => [
    for (final v in (trend as List?) ?? const []) v is num ? v : null,
  ];

  @override
  Widget build(BuildContext context) {
    if (values.whereType<num>().length < 2) {
      return SizedBox(width: width, height: height);
    }
    return SizedBox(
      width: width,
      height: height,
      child: CustomPaint(
        painter: _SparkPainter(values, Theme.of(context).colorScheme.primary),
      ),
    );
  }
}

class _SparkPainter extends CustomPainter {
  _SparkPainter(this.values, this.colour);

  final List<num?> values;
  final Color colour;

  @override
  void paint(Canvas canvas, Size size) {
    final known = values.whereType<num>();
    final lo = known.reduce((a, b) => a < b ? a : b) - 2;
    final hi = known.reduce((a, b) => a > b ? a : b) + 2;
    final pen = Paint()
      ..color = colour
      ..strokeWidth = 2
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;
    Offset at(int i) => Offset(
      values.length == 1 ? 0 : i / (values.length - 1) * size.width,
      size.height - (values[i]! - lo) / (hi - lo) * size.height,
    );
    final path = Path();
    var drawing = false;
    Offset? last;
    for (var i = 0; i < values.length; i++) {
      if (values[i] == null) {
        drawing = false;
        continue;
      }
      final p = at(i);
      drawing ? path.lineTo(p.dx, p.dy) : path.moveTo(p.dx, p.dy);
      drawing = true;
      last = p;
    }
    canvas.drawPath(path, pen);
    if (last != null) canvas.drawCircle(last, 2.6, Paint()..color = colour);
  }

  @override
  bool shouldRepaint(_SparkPainter old) =>
      old.colour != colour || old.values.join() != values.join();
}

/// Places up or down since last week: ▲ 2, ▼ 1, – , or New.
class _Change extends StatelessWidget {
  const _Change(this.change);

  final Object? change;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = change is Map ? (change as Map)['rank'] : null;
    final (String text, Color colour) = switch (c) {
      final int n when n > 0 => ('▲ $n', ToneColours.of(context, Tone.ok).ink),
      final int n when n < 0 => (
        '▼ ${-n}',
        ToneColours.of(context, Tone.bad).ink,
      ),
      0 => ('–', theme.colorScheme.onSurfaceVariant),
      _ when change is Map => ('New', theme.colorScheme.primary),
      _ => ('', theme.colorScheme.onSurfaceVariant),
    };
    return Text(
      text,
      style: theme.textTheme.labelMedium?.copyWith(
        color: colour,
        fontWeight: FontWeight.w700,
      ),
    );
  }
}
