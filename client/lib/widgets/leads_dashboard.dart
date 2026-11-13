import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../screens/leads_screen.dart' show sourceColours;
import 'calendar_day_field.dart';
import 'search_picker.dart';
import 'user_avatar.dart';

/// Leads › Manage › Dashboard: UI queue 38, Jose's 7 and 10 (2026-09-30,
/// "a new tab called Dashboard which gathers info, reply rates from each
/// user, response time, amounts of leads, graphs"). Six numbers for the
/// range, leads per day by source, how fast the first reply came, then a row
/// per person. Read from `GET /leads/dashboard`, which Leads serves.
///
/// It opens on the last 7 days. Until 2026-09-30 nearly every reply (261 of
/// 270 to Sep 28, measured by Leads) went out from A1 Tools or Google's own
/// app and carries no name, so a longer range is mostly "Outside EzBizi", and
/// the table says so when it is.
class LeadsDashboard extends StatefulWidget {
  const LeadsDashboard({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Tiles in one row and the graphs side by side from here; a column below.
  static const double deskFrom = 900;

  @override
  State<LeadsDashboard> createState() => _LeadsDashboardState();
}

const _periods = [
  ('today', 'Today'),
  ('7d', '7 days'),
  ('30d', '30 days'),
  ('month', 'This month'),
  ('custom', 'Custom'),
];

/// The source filter, in the words the lead list's source badges use.
const _sources = <({String word, String source, String? leadType})>[
  (word: 'LS Call', source: 'local_services', leadType: 'PHONE_CALL'),
  (word: 'LS Text', source: 'local_services', leadType: 'MESSAGE'),
  (word: 'LS Booking', source: 'local_services', leadType: 'BOOKING'),
  (word: 'Thumbtack', source: 'thumbtack', leadType: null),
  (word: 'Chat', source: 'website_chat', leadType: null),
  (word: 'Form', source: 'website_form', leadType: null),
  (word: 'Facebook', source: 'facebook', leadType: null),
  (word: 'Instagram', source: 'instagram', leadType: null),
  (word: 'Workiz', source: 'workiz', leadType: null),
  (word: 'WhatsApp', source: 'whatsapp', leadType: null),
  (word: 'RC Calls', source: 'ringcentral', leadType: null),
  (word: 'EZ Calls', source: 'ez_calls', leadType: null),
  (word: 'EZ Texts', source: 'ez_texts', leadType: null),
  (word: 'Promotions', source: 'promotions', leadType: null),
];

/// The badge word for a (source, lead_type) pair, as the list says it.
String dashboardSourceWord(String? source, String? leadType) {
  for (final s in _sources) {
    if (s.source == source && (s.leadType == null || s.leadType == leadType)) {
      return s.word;
    }
  }
  return source == 'local_services' ? 'Google' : source ?? 'Other';
}

/// "45 sec", "6 min", "1 h 20 min"; a dash when nothing was answered.
String replyTime(num? seconds) {
  if (seconds == null) return '—';
  final s = seconds.round();
  if (s < 60) return '$s sec';
  final m = (s / 60).round();
  if (m < 60) return '$m min';
  final h = m ~/ 60;
  final rest = m % 60;
  return rest == 0 ? '$h h' : '$h h $rest min';
}

/// A whole percentage of [part] in [whole], or null when there is no whole.
int? percentOf(num? part, num? whole) =>
    whole == null || whole == 0 ? null : ((part ?? 0) * 100 / whole).round();

const _buckets = [
  ('under_5m', '< 5 min', Tone.ok),
  ('m5_10', '5–10', Tone.ok),
  ('m10_30', '10–30', Tone.caution),
  ('m30_60', '30–60', Tone.bad),
  ('h1_4', '1–4 h', Tone.bad),
  ('over_4h', '> 4 h', Tone.bad),
];

class _LeadsDashboardState extends State<LeadsDashboard> {
  String _period = '7d';
  final _from = TextEditingController();
  final _to = TextEditingController();
  String? _companyId;
  String? _sourceWord;

  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;

  /// Only the newest answer is drawn when filters change faster than replies.
  int _asked = 0;

  /// The people table's sort: a column key, fastest first reply by default.
  String _sort = 'first_reply';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _from.dispose();
    _to.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final ask = ++_asked;
    // Custom waits for both days; an answer still coming is not drawn.
    if (_period == 'custom' && (_from.text.isEmpty || _to.text.isEmpty)) {
      setState(() => _loading = false);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    final source = _sources.where((s) => s.word == _sourceWord).firstOrNull;
    final response = await widget.api.get('/leads/dashboard', {
      'period': _period,
      if (_period == 'custom') ...{'from': _from.text, 'to': _to.text},
      'company_id': ?_companyId,
      'source': ?source?.source,
      'lead_type': ?source?.leadType,
    });
    if (!mounted || ask != _asked) return;
    setState(() {
      _loading = false;
      if (response.ok) {
        _data = (response.body['data'] as Map?)?.cast<String, dynamic>();
      } else {
        // Leads serves this; until that release lands the route is absent.
        _error = response.statusCode == 404
            ? 'The dashboard numbers are not available yet.'
            : response.reason;
      }
    });
  }

  void _choosePeriod(String period) {
    setState(() => _period = period);
    _load();
  }

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, box) {
      final desk = box.maxWidth >= LeadsDashboard.deskFrom;
      final data = _data;

      return ListView(
        key: const ValueKey('leads-dashboard'),
        padding: EdgeInsets.all(desk ? 16 : 12),
        children: [
          _filters(context, desk: desk),
          const SizedBox(height: 10),
          SizedBox(
            height: 3,
            child: _loading ? const LinearProgressIndicator() : null,
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                _error!,
                key: const ValueKey('dashboard-error'),
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          if (data != null) ...[
            const SizedBox(height: 8),
            _tiles(context, data, desk: desk),
            const SizedBox(height: 12),
            if (desk)
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(flex: 2, child: _perDay(context, data)),
                    const SizedBox(width: 12),
                    Expanded(child: _speed(context, data)),
                  ],
                ),
              )
            else ...[
              _perDay(context, data),
              const SizedBox(height: 12),
              _speed(context, data),
            ],
            const SizedBox(height: 12),
            _people(context, data, desk: desk),
          ],
        ],
      );
    },
  );

  // ── filters ─────────────────────────────────────────────────────

  Widget _filters(BuildContext context, {required bool desk}) {
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    final companies = widget.session.me?.switchableCompanies ?? const [];

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (final (key, label) in _periods)
          ChoiceChip(
            key: ValueKey('dashboard-period-$key'),
            label: Text(label),
            selected: _period == key,
            showCheckmark: false,
            onSelected: (_) => _choosePeriod(key),
          ),
        if (_period == 'custom') ...[
          SizedBox(
            width: 250,
            child: CalendarDayField(
              key: const ValueKey('dashboard-from'),
              controller: _from,
              formats: formats,
              label: 'From',
              dense: true,
              onChanged: (_) => _load(),
            ),
          ),
          SizedBox(
            width: 250,
            child: CalendarDayField(
              key: const ValueKey('dashboard-to'),
              controller: _to,
              formats: formats,
              label: 'To',
              dense: true,
              onChanged: (_) => _load(),
            ),
          ),
        ],
        if (companies.length > 1)
          SizedBox(
            width: desk ? 220 : double.infinity,
            child: SearchPickerField<String?>(
              key: const ValueKey('dashboard-company'),
              value: _companyId,
              isDense: true,
              options: [
                const SearchOption(value: null, label: 'All companies'),
                for (final c in companies)
                  SearchOption(value: c.id, label: c.name),
              ],
              onChanged: (v) {
                setState(() => _companyId = v);
                _load();
              },
            ),
          ),
        SizedBox(
          width: desk ? 180 : double.infinity,
          child: SearchPickerField<String?>(
            key: const ValueKey('dashboard-source'),
            value: _sourceWord,
            isDense: true,
            options: [
              const SearchOption(value: null, label: 'All sources'),
              for (final s in _sources)
                SearchOption(value: s.word, label: s.word),
            ],
            onChanged: (v) {
              setState(() => _sourceWord = v);
              _load();
            },
          ),
        ),
      ],
    );
  }

  // ── the six numbers ─────────────────────────────────────────────

  Widget _tiles(
    BuildContext context,
    Map<String, dynamic> data, {
    required bool desk,
  }) {
    final t = (data['totals'] as Map?)?.cast<String, dynamic>() ?? const {};
    final p = (data['previous'] as Map?)?.cast<String, dynamic>() ?? const {};
    final days = _days(data);
    final before = days == 1 ? 'the day before' : 'the $days days before';

    final answered = percentOf(t['answered'], t['answerable']);
    final answeredBefore = percentOf(p['answered'], p['answerable']);
    final quick = percentOf(t['within_10_min'], t['answered']);
    final quickBefore = percentOf(p['within_10_min'], p['answered']);
    final booked = percentOf(t['booked'], t['leads']);
    final leads = (t['leads'] as num?)?.toInt() ?? 0;
    final leadsBefore = (p['leads'] as num?)?.toInt();
    final median = t['first_reply_median_seconds'] as num?;
    final medianBefore = p['first_reply_median_seconds'] as num?;
    final waiting = (t['waiting_now'] as num?)?.toInt() ?? 0;

    final tiles = [
      _Tile(
        key: const ValueKey('tile-leads'),
        label: 'Leads',
        value: '$leads',
        note: leadsBefore == null || leadsBefore == 0
            ? null
            : _change(
                ((leads - leadsBefore) * 100 / leadsBefore).round(),
                '%',
                before,
              ),
      ),
      _Tile(
        key: const ValueKey('tile-answered'),
        label: 'Answered',
        value: answered == null ? '—' : '$answered%',
        note: answered == null
            ? const _Note('nothing to answer yet')
            : answeredBefore == null
            ? _Note('of ${t['answerable']} that can be')
            : _change(answered - answeredBefore, ' pts', before),
      ),
      _Tile(
        key: const ValueKey('tile-first-reply'),
        label: 'First reply',
        value: replyTime(median),
        note: _Note(
          medianBefore == null
              ? 'median, from a person'
              : 'median · was ${replyTime(medianBefore)}',
          tone: median == null || medianBefore == null
              ? null
              : median < medianBefore
              ? Tone.ok
              : median > medianBefore
              ? Tone.bad
              : null,
        ),
      ),
      _Tile(
        key: const ValueKey('tile-within-10'),
        label: 'Within 10 min',
        value: quick == null ? '—' : '$quick%',
        note: quick == null || quickBefore == null
            ? null
            : _change(quick - quickBefore, ' pts', before),
      ),
      _Tile(
        key: const ValueKey('tile-booked'),
        label: 'Booked',
        value: booked == null ? '—' : '$booked%',
        note: _Note(
          '${t['booked'] ?? 0} ${t['booked'] == 1 ? 'lead' : 'leads'} '
          'became jobs',
        ),
      ),
      _Tile(
        key: const ValueKey('tile-waiting'),
        label: 'Waiting now',
        value: '$waiting',
        valueTone: waiting > 0 ? Tone.bad : null,
        // The list's overdue mark, over the last 24 hours (eb010055).
        note: const _Note('over 10 min, last 24 h'),
      ),
    ];

    if (desk) {
      return IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final (i, tile) in tiles.indexed) ...[
              if (i > 0) const SizedBox(width: 10),
              Expanded(child: tile),
            ],
          ],
        ),
      );
    }
    // Two by three on a phone, each pair the same height (sibling tiles).
    return Column(
      children: [
        for (var i = 0; i < tiles.length; i += 2) ...[
          if (i > 0) const SizedBox(height: 8),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: tiles[i]),
                const SizedBox(width: 8),
                Expanded(child: tiles[i + 1]),
              ],
            ),
          ),
        ],
      ],
    );
  }

  /// "▲ 8% vs the 7 days before": green up, red down. Every number passed
  /// in is better higher; the first reply says its own "was" instead.
  _Note _change(int delta, String unit, String before) => _Note(
    delta == 0
        ? 'same as $before'
        // Signs, not ▲ ▼: Roboto has no triangles, and the fallback font's
        // taller line overflowed the tiles by 2px.
        : '${delta > 0 ? '+' : '−'}${delta.abs()}$unit vs $before',
    tone: delta > 0
        ? Tone.ok
        : delta < 0
        ? Tone.bad
        : null,
  );

  static int _days(Map<String, dynamic> data) {
    final from = DateTime.tryParse('${data['from']}');
    final to = DateTime.tryParse('${data['to']}');
    if (from == null || to == null) return 1;
    return to.difference(from).inDays + 1;
  }

  // ── the graphs ──────────────────────────────────────────────────

  Widget _box(BuildContext context, String title, Widget child, {Key? key}) {
    final theme = Theme.of(context);

    return Container(
      key: key,
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(title, style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          child,
        ],
      ),
    );
  }

  /// Leads per day, stacked by source in the colours the list's badges use.
  Widget _perDay(BuildContext context, Map<String, dynamic> data) {
    final theme = Theme.of(context);
    final days = [
      for (final d in (data['per_day'] as List? ?? const []).cast<Map>())
        (
          date: '${d['date']}',
          counts: {
            for (final s in (d['sources'] as List? ?? const []).cast<Map>())
              dashboardSourceWord(
                s['source'] as String?,
                s['lead_type'] as String?,
              ): (s['count'] as num?)?.toInt() ?? 0,
          },
        ),
    ];
    final words = <String>{for (final d in days) ...d.counts.keys}.toList();
    final top = days.fold<int>(
      0,
      (m, d) => d.counts.values.fold<int>(0, (a, b) => a + b) > m
          ? d.counts.values.fold<int>(0, (a, b) => a + b)
          : m,
    );
    const height = 120.0;

    return _box(
      context,
      'Leads per day',
      key: const ValueKey('dashboard-per-day'),
      top == 0
          ? Text('No leads in this range.', style: theme.textTheme.bodySmall)
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  height: height,
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      for (final d in days)
                        Expanded(
                          child: Tooltip(
                            message:
                                '${d.date}: ${d.counts.values.fold<int>(0, (a, b) => a + b)} '
                                '(${[for (final w in words)
                                  if ((d.counts[w] ?? 0) > 0) '$w ${d.counts[w]}'].join(', ')})',
                            // A bar at most 36 wide: a week's seven filled
                            // the width as slabs.
                            child: Center(
                              child: Container(
                                constraints: const BoxConstraints(maxWidth: 36),
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 1.5,
                                ),
                                child: Column(
                                  mainAxisAlignment: MainAxisAlignment.end,
                                  children: [
                                    for (final w in words.reversed)
                                      if ((d.counts[w] ?? 0) > 0)
                                        Container(
                                          height: height * d.counts[w]! / top,
                                          color: sourceColours(context, w).ink,
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
                const SizedBox(height: 4),
                Row(
                  children: [
                    Text(
                      _day(days.first.date),
                      style: theme.textTheme.labelSmall,
                    ),
                    const Spacer(),
                    Text(
                      _day(days.last.date),
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 12,
                  runSpacing: 4,
                  children: [
                    for (final w in words)
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 9,
                            height: 9,
                            color: sourceColours(context, w).ink,
                          ),
                          const SizedBox(width: 4),
                          Text(w, style: theme.textTheme.labelSmall),
                        ],
                      ),
                  ],
                ),
              ],
            ),
    );
  }

  /// A day of the range in the organization's own date format.
  String _day(String iso) {
    final at = DateTime.tryParse(iso);
    return at == null
        ? iso
        : (widget.session.me?.display ?? DisplayFormats.fallback).formatDate(
            at,
          );
  }

  /// How long the first reply by a person took, as six bars.
  Widget _speed(BuildContext context, Map<String, dynamic> data) {
    final theme = Theme.of(context);
    final raw =
        (data['first_reply_buckets'] as Map?)?.cast<String, dynamic>() ??
        const {};
    final counts = [
      for (final b in _buckets) (raw[b.$1] as num?)?.toInt() ?? 0,
    ];
    final total = counts.fold<int>(0, (a, b) => a + b);
    // Bars against the largest, so the tallest fills the box; the labels
    // still say each one's share of all of them.
    final most = counts.fold<int>(0, (a, b) => b > a ? b : a);
    const height = 96.0;

    return _box(
      context,
      'How fast the first reply came',
      key: const ValueKey('dashboard-speed'),
      total == 0
          ? Text(
              'Nothing answered in this range.',
              style: theme.textTheme.bodySmall,
            )
          : SizedBox(
              // The bar, its share above and its label below.
              height: height + 44,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  for (final (i, b) in _buckets.indexed)
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 4),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.end,
                          children: [
                            Text(
                              '${percentOf(counts[i], total)}%',
                              key: ValueKey('speed-${b.$1}'),
                              style: theme.textTheme.labelSmall?.copyWith(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Container(
                              height: height * counts[i] / most,
                              decoration: BoxDecoration(
                                color: ToneColours.of(context, b.$3).ink,
                                borderRadius: const BorderRadius.vertical(
                                  top: Radius.circular(3),
                                ),
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              b.$2,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: theme.colorScheme.onSurfaceVariant,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
    );
  }

  // ── the people ──────────────────────────────────────────────────

  Widget _people(
    BuildContext context,
    Map<String, dynamic> data, {
    required bool desk,
  }) {
    final theme = Theme.of(context);
    final people = [
      for (final p in (data['people'] as List? ?? const []).cast<Map>())
        p.cast<String, dynamic>(),
    ];
    int n(Map<String, dynamic> p, String k) => (p[k] as num?)?.toInt() ?? 0;
    people.sort(
      (a, b) => switch (_sort) {
        'replies' => n(b, 'replies').compareTo(n(a, 'replies')),
        'answered' => n(b, 'leads_answered').compareTo(n(a, 'leads_answered')),
        'within' =>
          (percentOf(b['within_10_min'], b['leads_answered']) ?? -1).compareTo(
            percentOf(a['within_10_min'], a['leads_answered']) ?? -1,
          ),
        'booked' => n(b, 'booked').compareTo(n(a, 'booked')),
        _ =>
          ((a['first_reply_median_seconds'] as num?) ?? double.infinity)
              .compareTo(
                (b['first_reply_median_seconds'] as num?) ?? double.infinity,
              ),
      },
    );
    final automatic =
        (data['automatic'] as Map?)?.cast<String, dynamic>() ?? const {};
    final outside =
        (data['outside'] as Map?)?.cast<String, dynamic>() ?? const {};
    final named = people.fold<int>(0, (s, p) => s + n(p, 'leads_answered'));
    final unnamed = n(outside, 'leads_answered');
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Container(
      key: const ValueKey('dashboard-people'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (unnamed > named)
            Container(
              key: const ValueKey('dashboard-unnamed'),
              padding: const EdgeInsets.all(10),
              color: ToneColours.of(context, Tone.caution).fill,
              child: Text(
                'Most first replies in this range were sent from A1 Tools or '
                "Google's own app, so they have no name. Names fill in for "
                'replies sent from EzBizi.',
                style: TextStyle(
                  color: ToneColours.of(context, Tone.caution).ink,
                  fontSize: 12.5,
                ),
              ),
            ),
          if (desk) _peopleHeader(context),
          if (people.isEmpty)
            Padding(
              padding: const EdgeInsets.all(12),
              child: Text(
                'Nobody replied from EzBizi in this range.',
                style: muted,
              ),
            ),
          for (final p in people) _personRow(context, p, desk: desk),
          if (n(automatic, 'replies') > 0)
            _otherRow(
              context,
              key: const ValueKey('people-automatic'),
              icon: Icons.smart_toy_outlined,
              name: 'Automatic replies',
              replies: n(automatic, 'replies'),
              answered: n(automatic, 'leads'),
              time: 'instant',
              note: 'not counted as a first reply',
              desk: desk,
            ),
          if (n(outside, 'replies') > 0)
            _otherRow(
              context,
              key: const ValueKey('people-outside'),
              icon: Icons.north_east,
              name: 'Outside EzBizi',
              replies: n(outside, 'replies'),
              answered: unnamed,
              time: replyTime(outside['first_reply_median_seconds'] as num?),
              note: "A1 Tools and Google's app: no name",
              desk: desk,
            ),
        ],
      ),
    );
  }

  static const _columns = [3, 2, 2, 2, 3, 2];

  Widget _peopleHeader(BuildContext context) {
    final theme = Theme.of(context);
    Widget head(
      String label,
      String? sort,
      int flex, {
      bool end = true,
    }) => Expanded(
      flex: flex,
      child: InkWell(
        key: sort == null ? null : ValueKey('people-sort-$sort'),
        onTap: sort == null ? null : () => setState(() => _sort = sort),
        child: Text(
          '${label.toUpperCase()}${_sort == sort ? (sort == 'first_reply' ? ' ▲' : ' ▼') : ''}',
          textAlign: end ? TextAlign.end : TextAlign.start,
          style: theme.textTheme.labelSmall?.copyWith(
            fontWeight: FontWeight.w800,
            color: _sort == sort
                ? theme.colorScheme.primary
                : theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ),
    );

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      color: theme.colorScheme.surfaceContainerHighest,
      child: Row(
        children: [
          head('Person', null, _columns[0], end: false),
          head('Replies', 'replies', _columns[1]),
          head('Leads answered', 'answered', _columns[2]),
          head('First reply', 'first_reply', _columns[3]),
          const SizedBox(width: 16),
          head('Within 10 min', 'within', _columns[4], end: false),
          head('Booked', 'booked', _columns[5]),
        ],
      ),
    );
  }

  Widget _avatar(BuildContext context, String seed, String name) {
    // A real user: their photo, initials when none (Jose, 2026-11-13).
    if (seed != name) {
      return UserAvatar(api: widget.api, userId: seed, name: name, radius: 13);
    }
    final colours = PersonColours.forSeed(seed, Theme.of(context).brightness);
    final initials = [
      for (final w in name.trim().split(RegExp(r'\s+')).take(2))
        if (w.isNotEmpty) w[0].toUpperCase(),
    ].join();

    return CircleAvatar(
      radius: 13,
      backgroundColor: colours.fill,
      child: Text(
        initials,
        style: TextStyle(
          fontSize: 10.5,
          fontWeight: FontWeight.w800,
          color: colours.ink,
        ),
      ),
    );
  }

  Widget _meter(BuildContext context, int? percent) {
    final tone = percent == null
        ? Tone.neutral
        : percent >= 70
        ? Tone.ok
        : percent >= 45
        ? Tone.caution
        : Tone.bad;

    return ClipRRect(
      borderRadius: BorderRadius.circular(3),
      child: LinearProgressIndicator(
        value: (percent ?? 0) / 100,
        minHeight: 6,
        color: ToneColours.of(context, tone).ink,
        backgroundColor: Theme.of(context).colorScheme.surfaceContainerHighest,
      ),
    );
  }

  Widget _personRow(
    BuildContext context,
    Map<String, dynamic> p, {
    required bool desk,
  }) {
    final theme = Theme.of(context);
    final user = (p['user'] as Map?)?.cast<String, dynamic>() ?? const {};
    final name = '${user['name'] ?? 'Someone'}';
    final id = '${user['id'] ?? name}';
    final within = percentOf(p['within_10_min'], p['leads_answered']);
    final time = replyTime(p['first_reply_median_seconds'] as num?);

    if (!desk) {
      return ListTile(
        key: ValueKey('person-$id'),
        leading: _avatar(context, id, name),
        title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: Text(
          '${p['replies'] ?? 0} replies · '
          '${within == null ? 'none answered' : '$within% within 10 min'}',
        ),
        trailing: Text(
          time,
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w800,
          ),
        ),
      );
    }

    Widget cell(String text, int flex, {bool bold = false}) => Expanded(
      flex: flex,
      child: Text(
        text,
        textAlign: TextAlign.end,
        style: bold ? const TextStyle(fontWeight: FontWeight.w700) : null,
      ),
    );

    return Container(
      key: ValueKey('person-$id'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        border: Border(
          top: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Row(
        children: [
          Expanded(
            flex: _columns[0],
            child: Row(
              children: [
                _avatar(context, id, name),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
              ],
            ),
          ),
          cell('${p['replies'] ?? 0}', _columns[1]),
          cell('${p['leads_answered'] ?? 0}', _columns[2]),
          cell(time, _columns[3], bold: true),
          const SizedBox(width: 16),
          Expanded(
            flex: _columns[4],
            child: Row(
              children: [
                Expanded(child: _meter(context, within)),
                SizedBox(
                  width: 44,
                  child: Text(
                    within == null ? '—' : '$within%',
                    textAlign: TextAlign.end,
                  ),
                ),
              ],
            ),
          ),
          cell('${p['booked'] ?? 0}', _columns[5]),
        ],
      ),
    );
  }

  Widget _otherRow(
    BuildContext context, {
    required Key key,
    required IconData icon,
    required String name,
    required int replies,
    required int answered,
    required String time,
    required String note,
    required bool desk,
  }) {
    final theme = Theme.of(context);
    final muted = TextStyle(color: theme.colorScheme.onSurfaceVariant);

    if (!desk) {
      return ListTile(
        key: key,
        leading: Icon(icon, color: theme.colorScheme.onSurfaceVariant),
        title: Text(name, style: muted),
        subtitle: Text('$replies replies · $note'),
        trailing: Text(time, style: muted),
      );
    }

    Widget cell(String text, int flex) => Expanded(
      flex: flex,
      child: Text(text, textAlign: TextAlign.end, style: muted),
    );

    return Container(
      key: key,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        border: Border(
          top: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Row(
        children: [
          Expanded(
            flex: _columns[0],
            child: Row(
              children: [
                Icon(icon, size: 20, color: theme.colorScheme.onSurfaceVariant),
                const SizedBox(width: 10),
                Flexible(child: Text(name, style: muted)),
              ],
            ),
          ),
          cell('$replies', _columns[1]),
          cell('$answered', _columns[2]),
          cell(time, _columns[3]),
          const SizedBox(width: 16),
          Expanded(
            flex: _columns[4],
            child: Text(
              note,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          cell('', _columns[5]),
        ],
      ),
    );
  }
}

/// One of the six numbers. Every tile is the same height as its siblings.
class _Tile extends StatelessWidget {
  const _Tile({
    super.key,
    required this.label,
    required this.value,
    this.note,
    this.valueTone,
  });

  final String label;
  final String value;
  final _Note? note;
  final Tone? valueTone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              fontWeight: FontWeight.w800,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            value,
            style: theme.textTheme.headlineSmall?.copyWith(
              fontWeight: FontWeight.w800,
              color: valueTone == null
                  ? null
                  : ToneColours.of(context, valueTone!).ink,
            ),
          ),
          if (note != null)
            Text(
              note!.text,
              style: theme.textTheme.bodySmall?.copyWith(
                color: note!.tone == null
                    ? theme.colorScheme.onSurfaceVariant
                    : ToneColours.of(context, note!.tone!).ink,
              ),
            ),
        ],
      ),
    );
  }
}

class _Note {
  const _Note(this.text, {this.tone});

  final String text;
  final Tone? tone;
}
