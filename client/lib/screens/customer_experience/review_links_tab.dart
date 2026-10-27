import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../reports/report_dates.dart';
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
};

class _ReviewLinksTabState extends State<ReviewLinksTab> {
  late final DateTime _today = DateSpan.single(DateTime.now()).from;
  // Opens on today (Jose, 2026-10-20: "review links should default to today").
  late DateSpan _span = DateSpan.single(_today);
  bool _byPerson = false;
  Map<String, dynamic>? _data;
  String? _error;
  int _request = 0;

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
        ReviewSources(api: widget.api),
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
          const SizedBox(height: 20),
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
      ],
    );
  }

  Widget _tile(BuildContext context, String kind, String value, String? of) {
    final theme = Theme.of(context);
    final colour = switch (kind) {
      'happy' || 'google' => ToneColours.of(context, Tone.ok).ink,
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

    if (narrow) {
      return Column(
        children: [
          for (final r in rows)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(name(r)),
              subtitle: Text(
                'Sent ${_n(r, 'sent')} · Opened ${_n(r, 'opened')} · '
                'Green ${_n(r, 'happy')} · Red ${_n(r, 'sad')} · '
                'Google ${_n(r, 'google')} · Complaint ${_n(r, 'submitted')}',
              ),
            ),
        ],
      );
    }

    DataRow row(Map r, String label, {bool total = false}) => DataRow(
      cells: [
        DataCell(
          Text(
            label,
            style: total ? const TextStyle(fontWeight: FontWeight.w800) : null,
          ),
        ),
        DataCell(Text('${_n(r, 'sent')}')),
        DataCell(Text(_share(_n(r, 'opened'), _n(r, 'sent')))),
        DataCell(Text('${_n(r, 'happy')}')),
        DataCell(Text('${_n(r, 'sad')}')),
        DataCell(Text('${_n(r, 'google')}')),
        DataCell(Text('${_n(r, 'submitted')}')),
      ],
    );

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        key: const Key('review-links-table'),
        columns: [
          DataColumn(label: Text(_byPerson ? 'Person' : 'Branch')),
          for (final k in [
            'sent',
            'opened',
            'happy',
            'sad',
            'google',
            'submitted',
          ])
            DataColumn(label: Text(_labels[k]!), numeric: true),
        ],
        rows: [
          for (final r in rows) row(r, name(r)),
          row(totals ?? const {}, 'Total', total: true),
        ],
      ),
    );
  }
}
