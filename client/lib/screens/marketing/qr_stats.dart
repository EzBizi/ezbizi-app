import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';

/// QR code stats (UI queue 63; Jose: "add stats to each QR Code to see how
/// many people used it"; no answer in 10 minutes, so desktop 1 and phone 4).
///
/// A scanner is one phone or browser on one day: Marketing hashes IP and user
/// agent with a salt thrown away daily, so the same phone cannot be followed
/// from one day to the next. A period's scanners are therefore the DAILY
/// figures added up, never people: the screen says "daily scanners" and says
/// what that means, and never "people" (Marketing, 2026-10-06).

/// The last 30 days as a strip of small bars on a card, oldest first. A day
/// with no scans is a flat stub, not a gap; days since a code was paused are
/// grey, since its scans still count.
class QrDayStrip extends StatelessWidget {
  const QrDayStrip({super.key, required this.days, this.pausedFrom});

  final List<int> days;

  /// Index of the first paused day, or null while the code is active.
  final int? pausedFrom;

  static const double height = 28;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final most = days.fold<int>(0, (m, n) => n > m ? n : m);

    return SizedBox(
      height: height,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          for (var i = 0; i < days.length; i++) ...[
            if (i > 0) const SizedBox(width: 2),
            Container(
              key: ValueKey('qr-strip-day:$i'),
              width: 5,
              height: days[i] == 0 || most == 0
                  ? 2
                  : 4 + (height - 4) * days[i] / most,
              decoration: BoxDecoration(
                color: days[i] == 0
                    ? theme.colorScheme.outlineVariant
                    : (pausedFrom != null && i >= pausedFrom!)
                    ? theme.colorScheme.outline
                    : theme.colorScheme.primary,
                borderRadius: BorderRadius.circular(1),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// One code's numbers over 30 or 90 days, in a side panel.
class QrStatsPanel extends StatefulWidget {
  const QrStatsPanel({
    super.key,
    required this.api,
    required this.codeId,
    required this.allTimeScans,
    this.deactivatedAt,
    this.formats = DisplayFormats.fallback,
    this.now = DateTime.now,
  });

  final ApiClient api;
  final String codeId;
  final int allTimeScans;
  final DateTime? deactivatedAt;
  final DisplayFormats formats;
  final DateTime Function() now;

  @override
  State<QrStatsPanel> createState() => _QrStatsPanelState();
}

class _QrStatsPanelState extends State<QrStatsPanel> {
  int _span = 30;
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _stats;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final response = await widget.api.get(
      '/marketing/qr-codes/${widget.codeId}/stats',
      {'days': '$_span'},
    );
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final data = response.body['data'];
      _stats = data is Map ? Map<String, dynamic>.from(data) : const {};
    });
  }

  DateTime? _date(Object? value) => DateTime.tryParse('${value ?? ''}');

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = widget.now();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: SegmentedButton<int>(
            key: const Key('qr-stats-span'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: 30, label: Text('30 days')),
              ButtonSegment(value: 90, label: Text('90 days')),
            ],
            selected: {_span},
            onSelectionChanged: _loading
                ? null
                : (picked) {
                    setState(() => _span = picked.first);
                    _load();
                  },
          ),
        ),
        const SizedBox(height: 12),
        if (_loading)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 32),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_error != null) ...[
          ErrorNote(_error!),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(onPressed: _load, child: const Text('Try again')),
          ),
        ] else
          ..._body(context, theme, today),
      ],
    );
  }

  List<Widget> _body(BuildContext context, ThemeData theme, DateTime today) {
    final stats = _stats ?? const {};
    final days = [
      for (final row in (stats['days'] as List? ?? const []))
        if (row is Map) Map<String, dynamic>.from(row),
    ];
    final from = _date(stats['from']);
    final to = _date(stats['to']);
    int count(Map<String, dynamic> day, String key) =>
        (day[key] as num?)?.toInt() ?? 0;

    Map<String, dynamic>? busiest;
    for (final day in days) {
      if (count(day, 'scans') > 0 &&
          (busiest == null || count(day, 'scans') > count(busiest, 'scans'))) {
        busiest = day;
      }
    }
    final busiestDate = busiest == null ? null : _date(busiest['date']);

    // The first paused day inside the chart, from `deactivated_at` alone:
    // there is no pause history to draw earlier pauses from.
    final pausedFrom = widget.deactivatedAt == null
        ? null
        : DateUtils.dateOnly(widget.deactivatedAt!.toLocal());
    int? pausedIndex;
    if (pausedFrom != null) {
      for (var i = 0; i < days.length; i++) {
        final d = _date(days[i]['date']);
        if (d != null && !d.isBefore(pausedFrom)) {
          pausedIndex = i;
          break;
        }
      }
    }

    String words(DateTime d) =>
        dayWords(d, today, widget.formats, weekday: false);

    return [
      if (from != null && to != null)
        Text(
          spanWords(DateSpan(from, to), today, widget.formats),
          key: const Key('qr-stats-range'),
          style: theme.textTheme.bodySmall,
        ),
      const SizedBox(height: 8),
      IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: _figure(
                theme,
                'Scans',
                '${(stats['scans'] as num?)?.toInt() ?? 0}',
                key: const Key('qr-stats-scans'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _figure(
                theme,
                'Daily scanners',
                '${(stats['people'] as num?)?.toInt() ?? 0}',
                key: const Key('qr-stats-scanners'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _figure(
                theme,
                busiest == null
                    ? 'Busiest day'
                    : 'Busiest day · ${count(busiest, 'scans')}',
                busiestDate == null ? '—' : words(busiestDate),
                key: const Key('qr-stats-busiest'),
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 12),
      _legend(theme, paused: pausedIndex != null),
      const SizedBox(height: 6),
      QrDailyBars(
        key: const Key('qr-stats-chart'),
        scans: [for (final d in days) count(d, 'scans')],
        scanners: [for (final d in days) count(d, 'people')],
        pausedFrom: pausedIndex,
      ),
      const SizedBox(height: 4),
      if (days.isNotEmpty)
        Row(
          children: [
            Text(
              words(_date(days.first['date']) ?? today),
              style: theme.textTheme.bodySmall,
            ),
            const Spacer(),
            Text(
              to != null && DateUtils.isSameDay(to, today)
                  ? 'today'
                  : words(_date(days.last['date']) ?? today),
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      const SizedBox(height: 12),
      Text(
        'A daily scanner is one phone or browser on one day. The days are '
        'added up, so the same phone on two days counts twice.',
        key: const Key('qr-stats-meaning'),
        style: theme.textTheme.bodySmall,
      ),
      if (pausedIndex != null) ...[
        const SizedBox(height: 4),
        Text(
          'Scans while it was paused still count.',
          style: theme.textTheme.bodySmall,
        ),
      ],
      const SizedBox(height: 4),
      Text(
        'All time: ${widget.allTimeScans} '
        '${widget.allTimeScans == 1 ? 'scan' : 'scans'}.',
        key: const Key('qr-stats-all-time'),
        style: theme.textTheme.bodySmall,
      ),
    ];
  }

  Widget _figure(ThemeData theme, String label, String value, {Key? key}) =>
      Container(
        key: key,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          border: Border.all(color: EzBiziTheme.edge(context)),
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              value,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            Text(label, style: theme.textTheme.bodySmall),
          ],
        ),
      );

  Widget _legend(ThemeData theme, {required bool paused}) {
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
        const SizedBox(width: 5),
        Text(label, style: theme.textTheme.bodySmall),
      ],
    );

    return Wrap(
      spacing: 14,
      children: [
        swatch(theme.colorScheme.primary, 'Scans'),
        swatch(theme.colorScheme.primaryContainer, 'Daily scanners'),
        if (paused) swatch(theme.colorScheme.outline, 'Paused'),
      ],
    );
  }
}

/// Scans per day as bars, with the day's scanners inside each one. Every day
/// is drawn, zeros as a flat stub.
class QrDailyBars extends StatelessWidget {
  const QrDailyBars({
    super.key,
    required this.scans,
    required this.scanners,
    this.pausedFrom,
    this.height = 140,
  });

  final List<int> scans;
  final List<int> scanners;
  final int? pausedFrom;
  final double height;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final most = scans.fold<int>(0, (m, n) => n > m ? n : m);
    double tall(int n) => n == 0 || most == 0 ? 2 : 4 + (height - 4) * n / most;
    final gap = scans.length > 45 ? 1.0 : 2.0;

    return SizedBox(
      height: height,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          for (var i = 0; i < scans.length; i++) ...[
            if (i > 0) SizedBox(width: gap),
            Expanded(
              child: Align(
                alignment: Alignment.bottomCenter,
                child: SizedBox(
                  key: ValueKey('qr-bar:$i'),
                  width: double.infinity,
                  height: tall(scans[i]),
                  child: Stack(
                    alignment: Alignment.bottomCenter,
                    children: [
                      Positioned.fill(
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            color: scans[i] == 0
                                ? theme.colorScheme.outlineVariant
                                : (pausedFrom != null && i >= pausedFrom!)
                                ? theme.colorScheme.outline
                                : theme.colorScheme.primary,
                            borderRadius: const BorderRadius.vertical(
                              top: Radius.circular(2),
                            ),
                          ),
                        ),
                      ),
                      // The day's scanners, inside its scans: never more.
                      if (i < scanners.length && scanners[i] > 0)
                        FractionallySizedBox(
                          key: ValueKey('qr-bar-scanners:$i'),
                          widthFactor: 0.45,
                          heightFactor: (tall(scanners[i]) / tall(scans[i]))
                              .clamp(0, 1),
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              color: theme.colorScheme.primaryContainer,
                              borderRadius: const BorderRadius.vertical(
                                top: Radius.circular(2),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
