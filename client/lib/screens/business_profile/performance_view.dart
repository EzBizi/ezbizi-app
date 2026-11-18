import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/search_picker.dart';

/// Business Profile › Performance (UI queue 114; Jose picked desktop 1 and
/// phone 4): how the listings did on Google — calls, website clicks,
/// directions, messages and views — from the stored daily counts, and the
/// words people searched, read live from Google.
///
/// Google fills the latest days in late, so the screen always says how far its
/// numbers run. Each tile carries the change from the period of the same
/// length just before, fetched as a second request with its own dates.
class BusinessProfilePerformance extends StatefulWidget {
  const BusinessProfilePerformance({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  /// Below this width of the tab: tiles two to a row, listings as rows, and a
  /// listing's searches open in place of the rows.
  static const double narrowBelow = 760;

  @override
  State<BusinessProfilePerformance> createState() =>
      _BusinessProfilePerformanceState();
}

/// The five tiles, in order: the key used on screen, its label, and the
/// Google metrics it adds up.
const _tiles = <(String, String, List<String>)>[
  ('calls', 'Calls', ['CALL_CLICKS']),
  ('website', 'Website clicks', ['WEBSITE_CLICKS']),
  ('directions', 'Directions', ['BUSINESS_DIRECTION_REQUESTS']),
  ('messages', 'Messages', ['BUSINESS_CONVERSATIONS']),
  ('views', 'Views', [..._searchViews, ..._mapsViews]),
];

const _searchViews = [
  'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
  'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
];
const _mapsViews = [
  'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
  'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
];

/// The quick ranges, by key, and how many days each covers.
const _presets = {'7': 7, '28': 28, '90': 90};

const _monthChoices = [1, 3, 6, 12];

/// "Sat 17 Oct".
String _day(DateTime d) =>
    '${shortWeekdays[d.weekday - 1]} ${d.day} ${shortMonths[d.month - 1]}';

/// 938, 2.1k, 48.2k, 1.2M.
String compactCount(int n) {
  if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
  if (n >= 1000) return '${(n / 1000).toStringAsFixed(1)}k';
  return '$n';
}

int _sum(Map? row, List<String> metrics) {
  var total = 0;
  for (final m in metrics) {
    total += (row?[m] as num?)?.toInt() ?? 0;
  }
  return total;
}

int _tileValue(Map? row, String tile) =>
    _sum(row, _tiles.firstWhere((t) => t.$1 == tile).$3);

class _BusinessProfilePerformanceState
    extends State<BusinessProfilePerformance> {
  late final DateTime _today = DateSpan.single(DateTime.now()).from;

  /// '7' | '28' | '90' | 'custom'.
  String _preset = '28';
  DateSpan? _custom;
  bool _picking = false;

  /// Null is every listing.
  String? _location;

  /// Every listing this person reaches, kept from the unfiltered answer so the
  /// picker does not shrink to one name once one is chosen.
  List<Map<String, dynamic>> _listings = const [];

  Map<String, dynamic>? _data;
  Map<String, dynamic>? _previous;
  String? _error;
  int _request = 0;

  String _metric = 'calls';

  /// The listing whose searches are open, or null.
  Map<String, dynamic>? _searchFor;
  int _months = 3;
  List<Map<String, dynamic>>? _terms;
  String? _termsError;
  int _termsRequest = 0;

  DateTime get _yesterday =>
      DateTime(_today.year, _today.month, _today.day - 1);

  DateSpan get _span {
    if (_preset == 'custom' && _custom != null) return _custom!;
    final days = _presets[_preset] ?? 28;
    final to = _yesterday;
    return DateSpan(DateTime(to.year, to.month, to.day - (days - 1)), to);
  }

  /// The same number of days, ending the day before [span] starts.
  static DateSpan _before(DateSpan span) {
    final to = DateTime(span.from.year, span.from.month, span.from.day - 1);
    return DateSpan(DateTime(to.year, to.month, to.day - (span.days - 1)), to);
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    final span = _span;
    final previous = _before(span);
    final location = _location;

    setState(() => _error = null);

    Map<String, String> query(DateSpan s) => {
      'from': s.fromKey,
      'to': s.toKey,
      'location': ?location,
    };

    final answers = await Future.wait([
      widget.api.get('/business-profile/performance', query(span)),
      widget.api.get('/business-profile/performance', query(previous)),
    ]);
    if (!mounted || request != _request) return;

    final current = answers[0];
    setState(() {
      if (!current.ok) {
        _error = current.message;
        _data = null;
        _previous = null;
        return;
      }
      _data = current.data as Map<String, dynamic>? ?? const {};
      _previous = answers[1].ok
          ? answers[1].data as Map<String, dynamic>?
          : null;
      if (location == null) {
        _listings = List<Map<String, dynamic>>.from(
          (_data!['by_location'] as List? ?? const [])
              .cast<Map<String, dynamic>>(),
        );
      }
    });
  }

  Future<void> _loadTerms() async {
    final listing = _searchFor;
    if (listing == null) return;
    final request = ++_termsRequest;

    setState(() {
      _terms = null;
      _termsError = null;
    });

    final response = await widget.api.get(
      '/business-profile/locations/${listing['id']}/search-terms',
      {'months': '$_months'},
    );
    if (!mounted || request != _termsRequest) return;

    setState(() {
      if (!response.ok) {
        // The server's own sentence: on a 502 it says Google did not answer.
        _termsError = response.message;
        return;
      }
      _terms = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
    });
  }

  void _openSearches(Map<String, dynamic> listing) {
    setState(() => _searchFor = listing);
    _loadTerms();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final narrow =
            constraints.maxWidth < BusinessProfilePerformance.narrowBelow;

        return ListView(
          key: const ValueKey('gbp-performance'),
          padding: const EdgeInsets.all(16),
          children: [
            _controls(context, narrow),
            if (_picking) ...[
              const SizedBox(height: 8),
              _panel(context, narrow),
            ],
            const SizedBox(height: 12),
            ..._body(context, narrow, constraints.maxWidth - 32),
          ],
        );
      },
    );
  }

  Widget _controls(BuildContext context, bool narrow) {
    final theme = Theme.of(context);
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    final custom = _custom;
    final range = SearchPickerField<String>(
      key: const ValueKey('gbp-performance-range'),
      value: _preset,
      isDense: true,
      options: [
        for (final p in _presets.entries)
          SearchOption(value: p.key, label: 'Last ${p.value} days'),
        SearchOption(
          value: 'custom',
          label: custom == null
              ? 'Custom'
              : '${formats.formatDate(custom.from)} – '
                    '${formats.formatDate(custom.to)}',
        ),
      ],
      onChanged: (v) {
        if (v == null) return;
        if (v == 'custom') {
          // A phone: the dates on a page of their own, as Reports opens them.
          if (narrow) {
            _pickOnPage(formats);
          } else {
            setState(() => _picking = true);
          }
          return;
        }
        setState(() {
          _preset = v;
          _picking = false;
        });
        _load();
      },
    );
    final listing = SearchPickerField<String>(
      key: const ValueKey('gbp-performance-listing'),
      value: _location ?? '',
      isDense: true,
      options: [
        const SearchOption(value: '', label: 'All listings'),
        for (final l in _listings)
          SearchOption(
            value: l['id'] as String? ?? '',
            label: l['name'] as String? ?? 'Untitled listing',
          ),
      ],
      onChanged: (v) {
        setState(() {
          _location = (v == null || v.isEmpty) ? null : v;
          _searchFor = null;
        });
        _load();
      },
    );

    final last = _data?['last_date'] as String?;
    final fresh = last == null
        ? null
        : Text(
            "Google's numbers run to ${_day(DateTime.parse(last))}; "
            'the last days fill in later.',
            key: const ValueKey('gbp-performance-fresh'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          );

    if (narrow) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: range),
              const SizedBox(width: 8),
              Expanded(child: listing),
            ],
          ),
          if (fresh != null) ...[const SizedBox(height: 8), fresh],
        ],
      );
    }

    return Wrap(
      spacing: 12,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(width: 220, child: range),
        SizedBox(width: 260, child: listing),
        ?fresh,
      ],
    );
  }

  Future<void> _pickOnPage(DisplayFormats formats) async {
    final span = await pickDateSpan(
      context,
      initial: _span,
      today: _yesterday,
      formats: formats,
    );
    if (!mounted || span == null) return;
    setState(() {
      _custom = span;
      _preset = 'custom';
      _picking = false;
    });
    _load();
  }

  Widget _panel(BuildContext context, bool narrow) {
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    final panel = DateRangePanel(
      initial: _span,
      today: _yesterday,
      formats: formats,
      narrow: narrow,
      onApply: (span) {
        setState(() {
          _custom = span;
          _preset = 'custom';
          _picking = false;
        });
        _load();
      },
      onCancel: () => setState(() => _picking = false),
    );

    // The narrow panel fills the height it is given, and a list gives none.
    return narrow
        ? SizedBox(
            height: MediaQuery.sizeOf(context).height * 0.7,
            child: panel,
          )
        : panel;
  }

  List<Widget> _body(BuildContext context, bool narrow, double width) {
    final theme = Theme.of(context);
    final data = _data;

    if (_error != null) {
      return [
        Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton(
            onPressed: _load,
            child: const Text('Try again'),
          ),
        ),
      ];
    }
    if (data == null) {
      return const [
        Padding(
          padding: EdgeInsets.all(24),
          child: Center(child: CircularProgressIndicator()),
        ),
      ];
    }

    final rows = List<Map<String, dynamic>>.from(
      (data['by_location'] as List? ?? const []).cast<Map<String, dynamic>>(),
    );

    if (rows.isEmpty && _location == null) {
      return [
        _empty(
          theme,
          key: 'gbp-performance-no-listings',
          title: 'No listings yet',
          body:
              'Once a Business Profile is connected and Google has sent its '
              'listings, how each one performs appears here.',
        ),
      ];
    }

    return [
      if (data['last_date'] == null)
        _empty(
          theme,
          key: 'gbp-performance-no-data',
          title: 'No numbers for these dates',
          body:
              "Google has not sent any calls, clicks or views for these dates "
              'yet. Try a longer range, or check back tomorrow.',
        )
      else ...[
        _tileGrid(context, narrow, width),
        const SizedBox(height: 16),
        _chart(context),
      ],
      const SizedBox(height: 20),
      if (narrow)
        ..._narrowListings(context, rows)
      else
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: _table(context, rows)),
            if (_searchFor != null) ...[
              const SizedBox(width: 16),
              SizedBox(width: 320, child: _searches(context, narrow: false)),
            ],
          ],
        ),
    ];
  }

  Widget _empty(
    ThemeData theme, {
    required String key,
    required String title,
    required String body,
  }) {
    return Padding(
      key: ValueKey(key),
      padding: const EdgeInsets.symmetric(vertical: 24),
      child: Column(
        children: [
          Icon(
            Icons.insights_outlined,
            size: 40,
            color: theme.colorScheme.outline,
          ),
          const SizedBox(height: 12),
          Text(title, style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Text(
              body,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _tileGrid(BuildContext context, bool narrow, double width) {
    final tiles = [for (final t in _tiles) _tile(context, t.$1, t.$2)];

    if (narrow) {
      final each = (width - 8) / 2;
      return Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [for (final t in tiles) SizedBox(width: each, child: t)],
      );
    }

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < tiles.length; i++) ...[
            if (i > 0) const SizedBox(width: 10),
            Expanded(child: tiles[i]),
          ],
        ],
      ),
    );
  }

  Widget _tile(BuildContext context, String key, String label) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final totals = _data?['totals'] as Map?;
    final value = _tileValue(totals, key);
    final before = _previous == null
        ? null
        : _tileValue(_previous!['totals'] as Map?, key);
    final selected = _metric == key;
    final maps = _sum(totals, _mapsViews);

    return Material(
      color: selected ? scheme.primaryContainer : scheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        side: BorderSide(
          color: selected ? scheme.primary : scheme.outlineVariant,
        ),
      ),
      child: InkWell(
        key: ValueKey('gbp-performance-tile-$key'),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        onTap: () => setState(() => _metric = key),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(compactCount(value), style: theme.textTheme.titleLarge),
              const SizedBox(height: 2),
              Text(
                key == 'views' && value > 0
                    ? '$label · ${(maps * 100 / value).round()}% Maps'
                    : label,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
              if (before != null) ...[
                const SizedBox(height: 4),
                _change(context, key, value, before),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// "▲ 12%" against the period before; "▲ 3" when that period had none.
  Widget _change(BuildContext context, String key, int now, int before) {
    final theme = Theme.of(context);
    final diff = now - before;
    final String text;
    if (diff == 0) {
      text = 'No change';
    } else if (before == 0) {
      text = '${diff > 0 ? '▲' : '▼'} ${diff.abs()}';
    } else {
      text = '${diff > 0 ? '▲' : '▼'} ${(diff.abs() * 100 / before).round()}%';
    }

    return Text(
      text,
      key: ValueKey('gbp-performance-change-$key'),
      style: theme.textTheme.labelMedium?.copyWith(
        color: diff > 0
            ? EzBiziTheme.clockIn(context)
            : diff < 0
            ? theme.colorScheme.error
            : theme.colorScheme.onSurfaceVariant,
      ),
    );
  }

  Widget _chart(BuildContext context) {
    final theme = Theme.of(context);
    final data = _data!;
    final byDate = {
      for (final d in (data['daily'] as List? ?? const []))
        if (d is Map) '${d['date']}': d,
    };

    // Day by day from the start to the last day Google has filled in: a day
    // with no row is a zero, and the days after it are not drawn as zeros.
    final from = DateTime.parse(data['from'] as String);
    final last = DateTime.parse(data['last_date'] as String);
    final bars = <(DateTime, int)>[];
    for (
      var d = from;
      !d.isAfter(last);
      d = DateTime(d.year, d.month, d.day + 1)
    ) {
      bars.add((d, _tileValue(byDate[formatCalendarDay(d)], _metric)));
    }
    final label = _tiles.firstWhere((t) => t.$1 == _metric).$2;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('$label by day', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        SizedBox(
          height: 140,
          child: CustomPaint(
            key: ValueKey('gbp-performance-chart-$_metric'),
            size: Size.infinite,
            painter: _DailyBars(
              values: [for (final b in bars) b.$2],
              ink: theme.colorScheme.primary,
              track: theme.colorScheme.outlineVariant,
            ),
          ),
        ),
        const SizedBox(height: 4),
        if (bars.isNotEmpty)
          Row(
            children: [
              Text(_day(bars.first.$1), style: theme.textTheme.labelSmall),
              const Spacer(),
              Text(_day(bars.last.$1), style: theme.textTheme.labelSmall),
            ],
          ),
      ],
    );
  }

  Widget _table(BuildContext context, List<Map<String, dynamic>> rows) {
    final theme = Theme.of(context);
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    Widget line(List<Widget> cells) => Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Row(
        children: [
          Expanded(flex: 3, child: cells.first),
          for (final c in cells.skip(1))
            Expanded(
              child: Align(alignment: Alignment.centerRight, child: c),
            ),
        ],
      ),
    );

    return Container(
      key: const ValueKey('gbp-performance-table'),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          line([
            Text('LISTING', style: head),
            for (final t in [
              'CALLS',
              'WEBSITE',
              'DIRECTIONS',
              'MESSAGES',
              'VIEWS',
            ])
              Text(t, style: head),
          ]),
          for (final row in rows) ...[
            const Divider(height: 1),
            Material(
              color: _searchFor?['id'] == row['id']
                  ? theme.colorScheme.primaryContainer
                  : theme.colorScheme.surface,
              child: InkWell(
                key: ValueKey('gbp-performance-row-${row['id']}'),
                onTap: () => _openSearches(row),
                child: line([
                  Text(
                    row['name'] as String? ?? 'Untitled listing',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  for (final t in _tiles)
                    Text(compactCount(_tileValue(row, t.$1))),
                ]),
              ),
            ),
          ],
        ],
      ),
    );
  }

  List<Widget> _narrowListings(
    BuildContext context,
    List<Map<String, dynamic>> rows,
  ) {
    if (_searchFor != null) return [_searches(context, narrow: true)];

    final theme = Theme.of(context);
    return [
      for (final row in rows)
        Card(
          key: ValueKey('gbp-performance-row-${row['id']}'),
          margin: const EdgeInsets.only(bottom: 8),
          child: ListTile(
            title: Text(row['name'] as String? ?? 'Untitled listing'),
            subtitle: Text(
              '${compactCount(_tileValue(row, 'calls'))} calls · '
              '${compactCount(_tileValue(row, 'views'))} views',
              style: theme.textTheme.bodySmall,
            ),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _openSearches(row),
          ),
        ),
    ];
  }

  Widget _searches(BuildContext context, {required bool narrow}) {
    final theme = Theme.of(context);
    final listing = _searchFor!;
    final terms = _terms;

    final Widget content;
    if (_termsError != null) {
      content = Column(
        key: const ValueKey('gbp-performance-searches-error'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _termsError!,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            key: const ValueKey('gbp-performance-searches-retry'),
            onPressed: _loadTerms,
            child: const Text('Try again'),
          ),
        ],
      );
    } else if (terms == null) {
      content = const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      );
    } else if (terms.isEmpty) {
      content = Text(
        'Google has no searches to show for this listing in these months.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      );
    } else {
      final most = terms
          .map((t) => (t['impressions'] as num?)?.toInt() ?? 0)
          .fold<int>(0, math.max);
      content = Column(
        children: [
          for (final t in terms)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: _termBar(context, t, most),
            ),
        ],
      );
    }

    return Container(
      key: const ValueKey('gbp-performance-searches'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              if (narrow)
                IconButton(
                  key: const ValueKey('gbp-performance-searches-back'),
                  tooltip: 'Back to listings',
                  icon: const Icon(Icons.arrow_back),
                  onPressed: () => setState(() => _searchFor = null),
                ),
              Expanded(
                child: Text(
                  'Searches · ${listing['name'] ?? 'Untitled listing'}',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              if (!narrow)
                IconButton(
                  tooltip: 'Close',
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: () => setState(() => _searchFor = null),
                ),
            ],
          ),
          const SizedBox(height: 8),
          SearchPickerField<int>(
            key: const ValueKey('gbp-performance-months'),
            value: _months,
            isDense: true,
            options: [
              for (final m in _monthChoices)
                SearchOption(
                  value: m,
                  label: m == 1 ? 'Last month' : 'Last $m months',
                ),
            ],
            onChanged: (v) {
              if (v == null || v == _months) return;
              setState(() => _months = v);
              _loadTerms();
            },
          ),
          const SizedBox(height: 4),
          Text(
            'What people typed when Google showed this listing, and how '
            'many times.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 8),
          content,
        ],
      ),
    );
  }

  Widget _termBar(BuildContext context, Map<String, dynamic> t, int most) {
    final theme = Theme.of(context);
    final n = (t['impressions'] as num?)?.toInt() ?? 0;

    return Row(
      children: [
        Expanded(
          flex: 3,
          child: Text(
            t['term'] as String? ?? '',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          flex: 2,
          child: Align(
            alignment: Alignment.centerLeft,
            child: FractionallySizedBox(
              widthFactor: most == 0 ? 0 : n / most,
              child: Container(
                height: 8,
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary.withValues(alpha: 0.6),
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
          ),
        ),
        SizedBox(
          width: 52,
          child: Text(
            compactCount(n),
            textAlign: TextAlign.right,
            style: theme.textTheme.bodySmall,
          ),
        ),
      ],
    );
  }
}

/// One bar per day, scaled to the busiest day.
class _DailyBars extends CustomPainter {
  _DailyBars({required this.values, required this.ink, required this.track});

  final List<int> values;
  final Color ink;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawLine(
      Offset(0, size.height - 0.5),
      Offset(size.width, size.height - 0.5),
      Paint()..color = track,
    );
    if (values.isEmpty) return;

    final most = values.reduce(math.max);
    final slot = size.width / values.length;
    final width = math.max(1.0, math.min(28.0, slot * 0.7));

    for (var i = 0; i < values.length; i++) {
      final h = most == 0 ? 0.0 : values[i] / most * (size.height - 4);
      if (h <= 0) continue;
      final centre = slot * i + slot / 2;
      canvas.drawRRect(
        RRect.fromRectAndCorners(
          Rect.fromLTWH(centre - width / 2, size.height - h, width, h),
          topLeft: const Radius.circular(3),
          topRight: const Radius.circular(3),
        ),
        Paint()..color = ink,
      );
    }
  }

  @override
  bool shouldRepaint(_DailyBars old) =>
      old.ink != ink || old.track != track || !listEquals(old.values, values);
}
