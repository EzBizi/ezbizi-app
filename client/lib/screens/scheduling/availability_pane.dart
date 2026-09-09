import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';

/// Availability — search a zip, a city or an area, read that area's week.
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
///
/// ── The box suggests instead of guessing ─────────────────────────
///
/// It used to decide what you meant from the SHAPE of what you typed: all
/// digits was a zip, anything else was an area name. So a city reached nothing,
/// a half-typed area name reached nothing, and both looked exactly like "we do
/// not cover that" — a wrong answer in the same words as the right one.
///
/// Now it asks the server what the letters so far could mean and offers the
/// answers, because the three namespaces genuinely overlap: an area called
/// Dallas and a city called Dallas are different questions with different
/// answers, and no rule about string shape can tell them apart.
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
  final _focus = FocusNode();

  bool _loading = false;
  String? _error;
  Map<String, dynamic>? _result;
  String? _week;

  /// What the caller picked, so paging weeks re-asks the same question rather
  /// than re-running the search box's text.
  Map<String, dynamic>? _picked;

  List<Map<String, dynamic>> _suggestions = [];
  bool _suggesting = false;

  int _request = 0;
  int _suggestRequest = 0;

  @override
  void dispose() {
    _search.dispose();
    _focus.dispose();
    super.dispose();
  }

  /// ponytail: one request per keystroke, staleness handled by a counter
  /// rather than a debounce timer. The server is local and the query is three
  /// indexed prefix lookups; add a timer when that stops being true.
  Future<void> _suggest(String raw) async {
    final q = raw.trim();
    final request = ++_suggestRequest;

    if (q.isEmpty) {
      setState(() {
        _suggestions = [];
        _suggesting = false;
      });
      return;
    }

    setState(() => _suggesting = true);

    final response = await widget.api.get('/scheduling/suggest', {'q': q});

    if (!mounted || request != _suggestRequest) return;

    setState(() {
      _suggesting = false;
      _suggestions = response.ok
          ? (response.body['data'] as List? ?? const [])
                .cast<Map<String, dynamic>>()
          : const [];
    });
  }

  Future<void> _pick(Map<String, dynamic> suggestion) async {
    _search.text = suggestion['label'] as String? ?? '';
    _focus.unfocus();

    setState(() {
      _picked = suggestion;
      _suggestions = [];
    });

    await _load();
  }

  /// Asks about whatever was picked, or — when somebody typed and pressed
  /// Enter without picking — about the text as all three things at once, and
  /// lets the server resolve it.
  Future<void> _load({String? week}) async {
    final typed = _search.text.trim();
    if (typed.isEmpty && _picked == null) return;

    final request = ++_request;

    setState(() {
      _loading = true;
      _error = null;
      _week = week;
      _suggestions = [];
    });

    final picked = _picked;

    final query = <String, String>{
      if (week case final String w) 'week': w,
      if (picked != null && picked['area_id'] != null)
        // Exact: the suggestion already resolved to one area.
        'area_id': picked['area_id'] as String
      else if (picked != null && picked['type'] == 'city')
        'city': picked['label'] as String
      else if (picked != null && picked['type'] == 'zip')
        'zip': picked['label'] as String
      else if (RegExp(r'^\d{3,10}$').hasMatch(typed))
        'zip': typed
      else
        'area_name': typed,
    };

    final response = await widget.api.get('/scheduling/availability', query);

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        _result = null;
        return;
      }
      _result = response.body['data'] as Map<String, dynamic>?;
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
              Text(
                'What this business is set up to take. Search a zip code, a '
                'city or a service area.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('availability-search'),
                controller: _search,
                focusNode: _focus,
                textInputAction: TextInputAction.search,
                onChanged: (value) {
                  // Typing again abandons the previous pick, so paging weeks
                  // cannot silently answer about the thing you replaced.
                  _picked = null;
                  _suggest(value);
                },
                onSubmitted: (_) => _load(),
                decoration: InputDecoration(
                  isDense: true,
                  prefixIcon: const Icon(Icons.search, size: 20),
                  hintText: 'Zip code, city or area name',
                  border: const OutlineInputBorder(),
                  suffixIcon: _suggesting
                      ? const Padding(
                          padding: EdgeInsets.all(12),
                          child: SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                        )
                      : IconButton(
                          icon: const Icon(Icons.arrow_forward, size: 20),
                          tooltip: 'Search',
                          onPressed: () => _load(),
                        ),
                ),
              ),
              if (_suggestions.isNotEmpty) _suggestionList(theme),
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

  Widget _suggestionList(ThemeData theme) {
    const icons = {
      'area': Icons.map_outlined,
      'city': Icons.location_city_outlined,
      'zip': Icons.markunread_mailbox_outlined,
    };

    return Container(
      margin: const EdgeInsets.only(top: 6),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        children: [
          for (final s in _suggestions)
            ListTile(
              dense: true,
              leading: Icon(icons[s['type']] ?? Icons.search, size: 18),
              title: Text(s['label'] as String? ?? ''),
              subtitle: (s['detail'] as String? ?? '').isEmpty
                  ? null
                  : Text(s['detail'] as String),
              onTap: () => _pick(s),
            ),
        ],
      ),
    );
  }

  Widget _prompt(ThemeData theme) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        'Search a zip code, a city or an area name to see its week.',
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

    final picked = _picked;
    final split = picked != null &&
        picked['type'] == 'city' &&
        picked['area_id'] == null;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                _heading(matched),
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
        // A city whose zips sit in more than one area cannot have "a" week.
        // Saying so beats showing one area's week under the city's name.
        if (split)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: ErrorNote(
              '${picked['label']} covers more than one service area. This is '
              '${matched['area_name']} only — open the others from Settings.',
            ),
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

  /// Says how it matched, so the screen never shows a different place than the
  /// one that was asked for without saying why.
  String _heading(Map<String, dynamic> matched) {
    final area = matched['area_name'] ?? '';
    final typed = _search.text.trim();

    return switch (matched['by']) {
      'zip' => '$typed is in $area',
      'city' => '$typed is in $area',
      _ => '$area',
    };
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
