import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';

/// Availability — search a zip or an area, read that area's week.
///
/// design: docs/previews/scheduling-layouts.html #4 (and #6 at 375px)
///
/// ── The number is not called "remaining" ─────────────────────────
///
/// The server sends `basis`, and while it says `configured` this screen says
/// "can take" rather than "left". Nothing subtracts bookings yet — Job has no
/// service area — so a cell labelled "remaining" would read full for ever
/// however many jobs were booked. That is a confident false statement rather
/// than an empty screen, and worse than one, because it looks populated.
///
/// The word is taken from the payload rather than compiled in here, so the day
/// the server starts subtracting, the label changes with it and nobody who
/// learned the first meaning is quietly shown the second.
class AvailabilityPane extends StatefulWidget {
  const AvailabilityPane({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<AvailabilityPane> createState() => _AvailabilityPaneState();
}

class _AvailabilityPaneState extends State<AvailabilityPane> {
  static const _dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  final _search = TextEditingController();

  bool _loading = false;
  String? _error;
  Map<String, dynamic>? _result;
  String? _week;
  int _request = 0;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  bool get _isZip => RegExp(r'^\d{3,10}$').hasMatch(_search.text.trim());

  Future<void> _load({String? week}) async {
    final typed = _search.text.trim();
    if (typed.isEmpty) return;

    final request = ++_request;

    setState(() {
      _loading = true;
      _error = null;
      _week = week;
    });

    // A digits-only search is a zip; anything else is a name, which the server
    // resolves by area. Deciding here rather than sending both keeps the
    // "matched by" in the response meaningful.
    final response = await widget.api.get('/scheduling/availability', {
      if (_isZip) 'zip': typed else 'area_name': typed,
      if (week case final String w) 'week': w,
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        _result = null;
        return;
      }
      _result = (response.data ?? response.body['data']) as Map<String, dynamic>?;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // The heading here read "Availability" — the tab's own label,
              // word for word. The sentence under it is kept: it says what
              // the pane MEANS, which the tab does not.
              Text(
                'What this business is set up to take. Search a zip code or a '
                'service area.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _search,
                textInputAction: TextInputAction.search,
                onSubmitted: (_) => _load(),
                decoration: InputDecoration(
                  isDense: true,
                  prefixIcon: const Icon(Icons.search, size: 20),
                  hintText: 'Zip code or area name',
                  border: const OutlineInputBorder(),
                  suffixIcon: IconButton(
                    icon: const Icon(Icons.arrow_forward, size: 20),
                    tooltip: 'Search',
                    onPressed: _load,
                  ),
                ),
              ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: () => _load(week: _week),
            child: _result == null ? _prompt(theme) : _answer(theme, _result!),
          ),
        ),
      ],
    );
  }

  Widget _prompt(ThemeData theme) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        'Search a zip code or an area name to see its week.',
        textAlign: TextAlign.center,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    ),
  );

  Widget _answer(ThemeData theme, Map<String, dynamic> data) {
    final matched = data['matched'] as Map<String, dynamic>?;

    if (matched == null) {
      // Not an error. Somebody outside the service area is on the phone and
      // "we do not cover that" is the answer they need.
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No service area covers ${data['searched'] ?? 'that'}.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium,
          ),
        ),
      );
    }

    final days = (data['days'] as List? ?? const [])
        .cast<Map<String, dynamic>>()
        .where((d) => d['closed'] != true)
        .toList();

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                matched['by'] == 'zip'
                    ? '${_search.text.trim()} is in ${matched['area_name']}'
                    : '${matched['area_name']}',
                style: theme.textTheme.titleSmall,
              ),
            ),
            IconButton(
              icon: const Icon(Icons.chevron_left),
              tooltip: 'Previous week',
              onPressed: () => _load(week: data['previous_week'] as String?),
            ),
            IconButton(
              icon: const Icon(Icons.chevron_right),
              tooltip: 'Next week',
              onPressed: () => _load(week: data['next_week'] as String?),
            ),
          ],
        ),
        if (data['basis'] == 'configured')
          Padding(
            padding: const EdgeInsets.only(top: 6, bottom: 4),
            child: Text(
              'These are the numbers this area is set up to take. Booked jobs '
              'are not counted yet.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        const SizedBox(height: 12),
        if (days.isEmpty)
          Text(
            'Nothing is set up for this week.',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          )
        else
          // Closed days are dropped rather than drawn as empty columns — a
          // business open Monday to Thursday gets a four-day week, and the
          // layout does not spend space saying "nothing here" three times.
          for (final day in days) _dayCard(theme, day),
      ],
    );
  }

  Widget _dayCard(ThemeData theme, Map<String, dynamic> day) {
    final slots = (day['slots'] as List? ?? const []).cast<Map<String, dynamic>>();
    final weekday = day['weekday'] as int? ?? 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '${_dayNames[weekday]} ${_shortDate(day['date'] as String?)}',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 6),
            for (final slot in slots)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${slot['start_time']} – ${slot['end_time']}',
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                    Text(
                      'can take ${slot['capacity']}',
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// `2026-08-31` as `31 Aug`, without pulling in a date library for it.
  String _shortDate(String? iso) {
    if (iso == null || iso.length < 10) return '';

    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];

    final month = int.tryParse(iso.substring(5, 7));
    if (month == null || month < 1 || month > 12) return '';

    return '${int.parse(iso.substring(8, 10))} ${months[month - 1]}';
  }
}
