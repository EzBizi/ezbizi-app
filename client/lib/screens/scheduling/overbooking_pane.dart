import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';

/// Scheduling › Overbooking (Jose, 2026-09-29: "a new tab on scheduling that
/// warns me about all the overbookings"). Days booked past what an area or a
/// franchise takes, from today forward, and the areas with no spot left in the
/// next seven days. The rules are the server's (App\Support\Scheduling\
/// Overbooking); the same list is what the morning warning email reports.
class OverbookingPane extends StatefulWidget {
  const OverbookingPane({super.key, required this.api, required this.visible});

  final ApiClient api;
  final bool visible;

  @override
  State<OverbookingPane> createState() => _OverbookingPaneState();
}

class _OverbookingPaneState extends State<OverbookingPane> {
  static const _spans = [7, 14, 30, 60];
  static const _weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  static const _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  int _days = 30;
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant OverbookingPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  Future<void> _load() async {
    final ticket = ++_request;
    setState(() {
      _loading = _data == null;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/overbooking', {
      'days': '$_days',
    });
    if (!mounted || ticket != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _data = response.body['data'] as Map<String, dynamic>?;
    });
  }

  static String _day(String ymd) {
    final d = DateTime.parse(ymd);
    return '${_weekdays[d.weekday - 1]}, ${_months[d.month - 1]} ${d.day}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final data = _data;
    final over = (data?['overbooked'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final full = (data?['fully_booked'] as List? ?? const [])
        .cast<Map<String, dynamic>>();

    final byDate = <String, List<Map<String, dynamic>>>{};
    for (final r in over) {
      (byDate[r['date'] as String] ??= []).add(r);
    }

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 18, 20, 32),
        children: [
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 900),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          'Overbooking',
                          style: theme.textTheme.titleMedium,
                        ),
                      ),
                      IconButton(
                        tooltip: 'Refresh',
                        icon: const Icon(Icons.refresh),
                        onPressed: _load,
                      ),
                    ],
                  ),
                  Text(
                    'Days booked past what an area or a franchise takes, and '
                    'areas with no spot left in the next 7 days. A warning '
                    'email goes out each morning when there are any.',
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                  const SizedBox(height: 14),
                  // Two tiles, one size (Jose: "you always make that mistake").
                  IntrinsicHeight(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Expanded(
                          child: _stat(
                            theme,
                            key: 'overbooked',
                            count: over.length,
                            label: over.length == 1
                                ? 'overbooked day'
                                : 'overbooked days',
                            tone: over.isEmpty ? Tone.ok : Tone.bad,
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: _stat(
                            theme,
                            key: 'full',
                            count: full.length,
                            label: full.length == 1
                                ? 'area fully booked (7 days)'
                                : 'areas fully booked (7 days)',
                            tone: full.isEmpty ? Tone.ok : Tone.caution,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  Wrap(
                    spacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(
                        'Overbooked in the next',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: muted,
                        ),
                      ),
                      for (final n in _spans)
                        ChoiceChip(
                          label: Text('$n days'),
                          selected: _days == n,
                          onSelected: (_) {
                            setState(() => _days = n);
                            _load();
                          },
                        ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  if (over.isEmpty)
                    _allClear(
                      theme,
                      'No overbooked days from today through the next $_days '
                      'days.',
                    )
                  else
                    for (final e in byDate.entries) ...[
                      Padding(
                        padding: const EdgeInsets.only(top: 10, bottom: 4),
                        child: Text(
                          _day(e.key).toUpperCase(),
                          style: theme.textTheme.labelSmall?.copyWith(
                            letterSpacing: 0.8,
                            color: muted,
                          ),
                        ),
                      ),
                      for (final r in e.value) _overRow(theme, r),
                    ],
                  const SizedBox(height: 24),
                  Text(
                    'Fully booked in the next 7 days',
                    style: theme.textTheme.titleSmall,
                  ),
                  const SizedBox(height: 6),
                  if (full.isEmpty)
                    _allClear(
                      theme,
                      'Every area that works in the next 7 days still has a '
                      'spot.',
                    )
                  else
                    for (final r in full) _fullRow(theme, r),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _stat(
    ThemeData theme, {
    required String key,
    required int count,
    required String label,
    required Tone tone,
  }) {
    final c = ToneColours.of(context, tone);
    return Container(
      key: ValueKey('overbooking-stat-$key'),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Text(
            '$count',
            style: theme.textTheme.headlineSmall?.copyWith(
              color: c.ink,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodyMedium?.copyWith(color: c.ink),
            ),
          ),
        ],
      ),
    );
  }

  Widget _row(
    ThemeData theme, {
    required Key key,
    required IconData icon,
    required Tone tone,
    required String title,
    required String detail,
    String? pill,
  }) {
    final c = ToneColours.of(context, tone);
    return Container(
      key: key,
      margin: const EdgeInsets.symmetric(vertical: 3),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(icon, size: 20, color: c.ink),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: theme.textTheme.titleSmall),
                Text(
                  detail,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
          if (pill != null)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: c.fill,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                pill,
                style: theme.textTheme.labelMedium?.copyWith(
                  color: c.ink,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _overRow(ThemeData theme, Map<String, dynamic> r) {
    final franchise = r['level'] == 'franchise';
    final title = franchise
        ? '${r['name']} (whole franchise)'
        : [r['franchise_name'], r['name']].whereType<String>().join(' › ');
    final detail = r['closed'] == true
        ? '${r['booked']} booked on a day this area is closed'
        : '${r['booked']} booked of ${r['capacity']}'
              '${franchise ? ' (daily limit)' : ''}';

    return _row(
      theme,
      key: ValueKey('overbooked-${r['date']}-${r['level']}-${r['name']}'),
      icon: franchise ? Icons.domain : Icons.warning_amber_rounded,
      tone: Tone.bad,
      title: title,
      detail: detail,
      pill: '${r['over']} over',
    );
  }

  Widget _fullRow(ThemeData theme, Map<String, dynamic> r) {
    final next = r['next_opening'] as String?;
    return _row(
      theme,
      key: ValueKey('full-${r['area_id']}'),
      icon: Icons.event_busy_outlined,
      tone: Tone.caution,
      title: [r['franchise_name'], r['name']].whereType<String>().join(' › '),
      detail: next == null
          ? 'No opening found in the next year'
          : 'Next opening ${_day(next)}',
    );
  }

  Widget _allClear(ThemeData theme, String text) {
    final c = ToneColours.of(context, Tone.ok);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(Icons.check_circle_outline, color: c.ink, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: theme.textTheme.bodyMedium?.copyWith(color: c.ink),
            ),
          ),
        ],
      ),
    );
  }
}
