import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/list_table.dart';
import '../../widgets/date_range_picker.dart';
import '../reports/report_dates.dart';
import 'ekomi_view.dart';
import 'review_sources.dart';

/// Review links — what customers did with the link in their review text
/// (Jose, 2026-10-15). UI queue 98: desktop 1 (a funnel row, then a Branch |
/// Person table with a total row) and phone 4 (the tiles in two columns, then
/// one row per branch or person). Jose: "as u recommend".
///
/// Every count but Sent starts the day tracking began; the note says when.
class ReviewLinksTab extends StatefulWidget {
  const ReviewLinksTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ReviewLinksTab> createState() => _ReviewLinksTabState();
}

/// The six counts, in funnel order, with what each is called on screen.
const _labels = {
  'sent': 'Sent',
  'opened': 'Opened',
  'happy': 'Green',
  'sad': 'Red',
  'google': 'Google',
  'submitted': 'Complaint',
  'reviewed': 'Reviewed',
  'qr': 'QR scans',
};

class _ReviewLinksTabState extends State<ReviewLinksTab> {
  late final DateTime _today = DateSpan.single(DateTime.now()).from;
  // Opens on today (Jose, 2026-10-20: "review links should default to today").
  late DateSpan _span = DateSpan.single(_today);
  bool _byPerson = false;
  bool _allRanked = false;
  Map<String, dynamic>? _data;
  String? _error;
  int _request = 0;

  /// The eKomi screen in this tab's place (UI queue 165).
  bool _ekomi = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    final end = DateTime(_span.to.year, _span.to.month, _span.to.day + 1);
    final response = await widget.api.get('/customer-experience/review-links', {
      'from': _span.from.toUtc().toIso8601String(),
      'to': end.toUtc().toIso8601String(),
    });
    if (!mounted || request != _request) return;
    setState(() {
      _error = response.ok ? null : response.message;
      _data = response.ok ? response.data as Map<String, dynamic> : null;
    });
  }

  static int _n(Map? m, String k) => (m?[k] as num?)?.toInt() ?? 0;

  /// "12 · 40%" — a count and its share of the step before it.
  static String _share(int n, int of) =>
      of == 0 ? '$n' : '$n · ${(n * 100 / of).round()}%';

  @override
  Widget build(BuildContext context) {
    if (_ekomi) {
      return EkomiView(
        api: widget.api,
        session: widget.session,
        onBack: () => setState(() => _ekomi = false),
      );
    }
    final theme = Theme.of(context);
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final data = _data;
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    final since = data?['tracked_since'] as String?;

    final note = Text(
      since == null
          ? 'Opens, faces and Google clicks are counted from the release that '
                'added them. Nothing has been tracked yet.'
          : 'Opens, faces and Google clicks counted since '
                '${formats.formatDate(DateTime.parse(since).toLocal())}.',
      key: const Key('review-links-since'),
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );
    final dates = ReportDates(
      initial: _span,
      today: _today,
      formats: formats,
      onChanged: (span) {
        _span = span;
        _load();
      },
    );

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Where reviews were written, above the funnel (design 154 #13).
        ReviewSources(
          api: widget.api,
          onEkomi: () => setState(() => _ekomi = true),
        ),
        const SizedBox(height: 20),
        if (narrow) ...[
          dates,
          const SizedBox(height: 8),
          note,
        ] else
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: note),
              const SizedBox(width: 16),
              dates,
            ],
          ),
        const SizedBox(height: 16),
        if (_error != null)
          Text(_error!, style: TextStyle(color: theme.colorScheme.error))
        else if (data == null)
          const Center(child: CircularProgressIndicator())
        else ...[
          _funnel(context, data['totals'] as Map?, narrow),
          _reviewedDetail(context, data['reviewed'] as Map?),
          const SizedBox(height: 20),
          _leaderboard(context, data['by_person'] as List?),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              key: const Key('review-links-split'),
              segments: const [
                ButtonSegment(value: false, label: Text('Branch')),
                ButtonSegment(value: true, label: Text('Person')),
              ],
              selected: {_byPerson},
              showSelectedIcon: false,
              onSelectionChanged: (v) => setState(() => _byPerson = v.first),
            ),
          ),
          const SizedBox(height: 10),
          _rows(
            context,
            List<Map<String, dynamic>>.from(
              (data[_byPerson ? 'by_person' : 'by_branch'] as List? ?? const [])
                  .cast<Map<String, dynamic>>(),
            ),
            data['totals'] as Map?,
            narrow,
          ),
        ],
      ],
    );
  }

  /// Sent → Opened → Green / Red → Google / Complaint. Every tile one size.
  Widget _funnel(BuildContext context, Map? t, bool narrow) {
    final sent = _n(t, 'sent'), opened = _n(t, 'opened');
    final happy = _n(t, 'happy'), sad = _n(t, 'sad');
    final tiles = [
      _tile(context, 'sent', '$sent', null),
      _tile(context, 'opened', _share(opened, sent), 'of sent'),
      _tile(context, 'happy', _share(happy, opened), 'of opened'),
      _tile(context, 'sad', _share(sad, opened), 'of opened'),
      _tile(context, 'google', _share(_n(t, 'google'), happy), 'of green'),
      _tile(context, 'submitted', _share(_n(t, 'submitted'), sad), 'of red'),
      // Jose, 2026-11-09: did they actually leave the review? Inferred by name.
      _tile(
        context,
        'reviewed',
        _share(_n(t, 'reviewed'), _n(t, 'google')),
        'of Google',
      ),
    ];

    if (narrow) {
      return GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 8,
        crossAxisSpacing: 8,
        childAspectRatio: 1.9,
        children: tiles,
      );
    }

    Widget step(List<Widget> children) => Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final c in children) ...[
            c,
            if (c != children.last) const SizedBox(height: 8),
          ],
        ],
      ),
    );
    const arrow = Padding(
      padding: EdgeInsets.symmetric(horizontal: 6),
      child: Icon(Icons.chevron_right),
    );
    return Row(
      children: [
        step([tiles[0]]),
        arrow,
        step([tiles[1]]),
        arrow,
        step([tiles[2], tiles[3]]),
        arrow,
        step([tiles[4], tiles[5]]),
        arrow,
        step([tiles[6]]),
      ],
    );
  }

  Widget _tile(BuildContext context, String kind, String value, String? of) {
    final theme = Theme.of(context);
    // A zero is no news either way, so it is neutral, not red or green
    // (phone audit 2026-11-04).
    final zero = value == '0' || value.startsWith('0 ');
    final colour = switch (kind) {
      _ when zero => theme.colorScheme.onSurfaceVariant,
      'happy' || 'google' || 'reviewed' => ToneColours.of(context, Tone.ok).ink,
      'sad' || 'submitted' => ToneColours.of(context, Tone.bad).ink,
      _ => theme.colorScheme.primary,
    };
    return SizedBox(
      key: Key('review-links-tile-$kind'),
      height: 76,
      child: Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                of == null ? _labels[kind]! : '${_labels[kind]} · $of',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelMedium,
              ),
              Text(
                value,
                style: theme.textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: colour,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// " · 4.8 ★", or nothing when no review was matched.
  String _stars(Object? v) => v is num ? ' · ${v.toStringAsFixed(1)} ★' : '';

  /// How sure each "Reviewed" is, under the funnel (Jose, 2026-11-09).
  Widget _reviewedDetail(BuildContext context, Map? d) {
    final by = (d?['by'] as Map?) ?? const {};
    int n(String k) => (by[k] as num?)?.toInt() ?? 0;
    final small = Theme.of(context).textTheme.bodySmall;
    // Only exact and likely count, plus possible ones a person confirmed
    // (Jose, 2026-11-09); the rest of the possible ones are shown beside.
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Reviewed, matched by name: exact ${n('exact')} · likely ${n('likely')}'
            '${n('confirmed') > 0 ? ' · confirmed ${n('confirmed')}' : ''}'
            ' · without a click ${n('without_click')}${_stars(d?['stars'])}.',
            key: const Key('review-links-reviewed-detail'),
            style: small,
          ),
          if (n('possible') > 0)
            Text(
              '+${n('possible')} possible matches, confirm in Reviews',
              key: const Key('review-links-possible'),
              style: small?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
        ],
      ),
    );
  }

  /// "Which technician got the most reviews" (Jose 2026-11-23, design 204):
  /// people ranked by reviews written, then green faces, then QR scans, over
  /// this tab's dates. Gold, silver and bronze for the top three.
  Widget _leaderboard(BuildContext context, List? byPerson) {
    final theme = Theme.of(context);
    final ranked =
        [
          for (final r in byPerson ?? const [])
            if (r is Map &&
                r['name'] != null &&
                (_n(r, 'reviewed') + _n(r, 'happy') + _n(r, 'qr')) > 0)
              r,
        ]..sort((a, b) {
          for (final k in const ['reviewed', 'happy', 'qr', 'opened']) {
            final d = _n(b, k) - _n(a, k);
            if (d != 0) return d;
          }
          return '${a['name']}'.compareTo('${b['name']}');
        });
    if (ranked.isEmpty) return const SizedBox.shrink();
    final shown = _allRanked ? ranked : ranked.take(10).toList();

    Widget medal(int i) => Container(
      width: 26,
      height: 26,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: i < 3
            ? EzBiziTheme.medals[i]
            : theme.colorScheme.surfaceContainerHighest,
      ),
      child: Text(
        '${i + 1}',
        style: TextStyle(
          fontWeight: FontWeight.w700,
          fontSize: 12,
          color: i < 3 ? EzBiziTheme.paper : theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );

    return Padding(
      key: const Key('review-links-leaderboard'),
      padding: const EdgeInsets.only(bottom: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Technician leaderboard', style: theme.textTheme.titleMedium),
          const SizedBox(height: 2),
          Text(
            'Ranked by reviews written, then green faces, then QR scans.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 8),
          ListTable(
            columns: const [
              ListColumn('#', flex: 1, namedWhenStacked: false),
              ListColumn('Technician', flex: 4),
              ListColumn('Reviewed', end: true),
              ListColumn('Green', end: true),
              ListColumn('Red', end: true),
              ListColumn('QR scans', end: true),
              ListColumn('Opened', end: true),
            ],
            rows: [
              for (final (i, r) in shown.indexed)
                [
                  Align(alignment: Alignment.centerLeft, child: medal(i)),
                  Text(
                    '${r['name']}',
                    key: ValueKey('review-links-rank-$i'),
                    style: i < 3
                        ? theme.textTheme.titleSmall
                        : theme.textTheme.bodyMedium,
                  ),
                  for (final k in const [
                    'reviewed',
                    'happy',
                    'sad',
                    'qr',
                    'opened',
                  ])
                    Text('${_n(r, k)}'),
                ],
            ],
          ),
          if (ranked.length > 10)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                key: const Key('review-links-leaderboard-all'),
                onPressed: () => setState(() => _allRanked = !_allRanked),
                child: Text(
                  _allRanked ? 'Show the top 10' : 'Show all ${ranked.length}',
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _rows(
    BuildContext context,
    List<Map<String, dynamic>> rows,
    Map? totals,
    bool narrow,
  ) {
    final theme = Theme.of(context);
    String name(Map r) =>
        (r['name'] as String?) ?? (_byPerson ? 'Nobody recorded' : 'No branch');

    if (rows.isEmpty) {
      return Text(
        'No review texts in these dates.',
        style: theme.textTheme.bodyMedium,
      );
    }

    // Mobile review 2026-11-13, P1: a ListTable, so a phone gets stacked
    // cards with every number named, and nothing scrolls sideways.
    final keys = [
      'sent',
      'opened',
      'happy',
      'sad',
      'google',
      'submitted',
      'reviewed',
      'qr',
    ];
    List<Widget> cells(Map r, String label, {bool total = false}) => [
      Text(
        label,
        style: total
            ? const TextStyle(fontWeight: FontWeight.w800)
            : theme.textTheme.titleSmall,
      ),
      for (final k in keys)
        Text(
          k == 'opened'
              ? _share(_n(r, 'opened'), _n(r, 'sent'))
              : '${_n(r, k)}',
        ),
      Text(
        r['reviewed_stars'] is num
            ? '${(r['reviewed_stars'] as num).toStringAsFixed(1)} ★'
            : '—',
      ),
    ];

    return ListTable(
      key: const Key('review-links-table'),
      columns: [
        ListColumn(_byPerson ? 'Person' : 'Branch', flex: 3),
        for (final k in keys) ListColumn(_labels[k]!, end: true),
        const ListColumn('Stars', end: true),
      ],
      rows: [
        for (final r in rows) cells(r, name(r)),
        cells(totals ?? const {}, 'Total', total: true),
      ],
    );
  }
}
