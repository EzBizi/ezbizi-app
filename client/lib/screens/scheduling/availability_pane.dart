import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import 'availability_calendar.dart';
import 'availability_suggestion.dart';

/// Availability — "When can we come out?"
///
/// Laid out the way A1 Tools' Availability tab is, on Jose's instruction of
/// 2026-09-25: *"this is how it looks on A1Tools and my dispatchers are used to
/// it as well, obviously with ezbizi colors theme… i do like the green yellow
/// red gray color tabs for the time slots"*. Search by zip (or a city or area
/// name, which the box suggests as you type), or pick a franchise and see every
/// area in it. Each area answers with the first day it has room and the next
/// few after that, window by window.
///
/// ── The colours are the answer, so they come from the count ─────
///
/// Green, 3 or more left; yellow, 2; red, 1; grey, full. Taken from [Tone], the
/// theme's status palette, which a test holds to 4.5:1 contrast in both
/// brightnesses — not literals chosen here.
///
/// ── An honest unknown beats a confident wrong answer ────────────
///
/// A zip no area covers, an area nobody has set up, and an area with no
/// opening in a year all say "check availability on Workiz". A1 Tools once
/// said "we do not cover" and "fully booked" in those cases, and dispatchers
/// turned away jobs A-1 could have done.
class AvailabilityPane extends StatefulWidget {
  const AvailabilityPane({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
    this.initialZip,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  /// A zip to check straight away - Leads opens this in a side panel with the
  /// lead's zip (Jose, 2026-09-28: check availability without leaving Leads).
  final String? initialZip;

  @override
  State<AvailabilityPane> createState() => _AvailabilityPaneState();
}

enum _Mode { zip, area, calendar }

class _AvailabilityPaneState extends State<AvailabilityPane> {
  static const _unknown =
      'Information not available, please check availability on Workiz.';
  static const _weekdays = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
    'Sunday', //
  ];
  static const _months = [
    'January', 'February', 'March', 'April', 'May', 'June', 'July', //
    'August', 'September', 'October', 'November', 'December',
  ];

  final _search = TextEditingController();

  _Mode _mode = _Mode.zip;
  bool _loading = false;
  String? _error;

  /// null before the first search; an empty list is "nothing covers that".
  List<Map<String, dynamic>>? _areas;

  /// The address a search was looked up as, so the reader sees which house
  /// the zip came from (Mia, 2026-10-02). Null for any other search.
  Map<String, dynamic>? _resolved;

  List<Map<String, dynamic>> _franchises = [];
  String? _franchiseId;

  /// Earliest and Suggested for the zip searched (UI queue 83), or null: no
  /// zip in the search, or the server had nothing to say.
  Map<String, dynamic>? _suggestion;
  String? _suggestionZip;

  List<Map<String, dynamic>> _suggestions = [];
  int _request = 0;
  int _suggestRequest = 0;

  /// The answer on screen keeps itself current (Jose, 2026-10-05: on a call
  /// for DMV, "another dispatcher books something else on DMV, i still see
  /// the same availability"). The last search is asked again every
  /// [availabilityRefreshEvery] while the tab is showing, and at once when it
  /// comes back.
  Map<String, String>? _lastQuery;
  Timer? _refresh;

  @override
  void initState() {
    super.initState();
    _refresh = Timer.periodic(availabilityRefreshEvery, (_) => _refreshNow());
    _loadFranchises();
    final zip = widget.initialZip?.trim() ?? '';
    if (zip.isNotEmpty) {
      _search.text = zip;
      _check();
    }
  }

  @override
  void didUpdateWidget(covariant AvailabilityPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _refreshNow();
  }

  void _refreshNow() {
    final query = _lastQuery;
    if (!widget.visible || _loading || query == null) return;
    _run(query, quiet: true);
  }

  @override
  void dispose() {
    _refresh?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _loadFranchises() async {
    final response = await widget.api.get(
      '/scheduling/availability/franchises',
      const {},
    );
    if (!mounted || !response.ok) return;
    setState(() {
      _franchises = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
    });
  }

  /// ponytail: one request per keystroke, staleness handled by a counter
  /// rather than a debounce timer. The query is three indexed prefix lookups.
  Future<void> _suggest(String raw) async {
    final q = raw.trim();
    final ticket = ++_suggestRequest;

    if (q.length < 2) {
      setState(() => _suggestions = []);
      return;
    }

    final response = await widget.api.get('/scheduling/suggest', {'q': q});
    if (!mounted || ticket != _suggestRequest) return;

    setState(() {
      _suggestions = response.ok
          ? (response.body['data'] as List? ?? const [])
                .cast<Map<String, dynamic>>()
          : [];
    });
  }

  /// A suggestion is already a precise question: it names the area outright
  /// when it can (an area, a zip, a city inside one area), and a city split
  /// across two areas is asked about as a city.
  Future<void> _pick(Map<String, dynamic> s) async {
    final label = s['label'] as String? ?? '';
    _search.text = label;
    setState(() => _suggestions = []);

    final areaId = s['area_id'] as String?;
    await _run(
      areaId != null
          ? {'area_id': areaId}
          : s['type'] == 'city'
          ? {'city': label}
          : {'zip': label},
    );
  }

  Future<void> _check() async {
    final q = _search.text.trim();
    if (q.isEmpty) return;
    setState(() => _suggestions = []);

    // A house number then words is a street address ("86 Jerusalem hill
    // Trumbull"): the server finds its zip (Mia, 2026-10-02).
    if (_looksLikeAddress(q)) {
      await _run({'address': q});
      return;
    }

    // Digits are a zip; anything else is most likely an area name somebody
    // typed without waiting for the suggestion.
    if (!RegExp(r'^[\d\s-]+$').hasMatch(q)) {
      await _run({'area_name': q});
      return;
    }

    // Said here, before asking (Jovanni, 2026-10-01: "improve the zip code
    // validation"). The server refuses the same, in the same words.
    if (!_zipLengths.contains(q.replaceAll(RegExp(r'\D'), '').length)) {
      setState(() {
        _error = _notAZip;
        _areas = null;
      });
      return;
    }

    await _run({'zip': q});
  }

  static bool _looksLikeAddress(String q) =>
      RegExp(r'^\d+[A-Za-z]?\s+[A-Za-z]').hasMatch(q);

  /// 5 digits, zip+4, or a New England zip that lost its leading zero.
  static const _zipLengths = {4, 5, 9};

  static const _notAZip =
      'That is not a zip code. A zip code is 5 digits, like 06810, or zip+4, '
      'like 06810-1234.';

  /// [_all] is "All areas" (Jose, 2026-09-28): every area in view.
  static const _all = '*';

  Future<void> _pickFranchise(String id) async {
    setState(() => _franchiseId = id);
    await _run(id == _all ? {'all': '1'} : {'franchise_id': id});
  }

  /// [quiet] is a refresh: no spinner, and a failed one leaves the answer up.
  Future<void> _run(Map<String, String> query, {bool quiet = false}) async {
    final ticket = ++_request;
    _lastQuery = query;
    if (!quiet) {
      setState(() {
        _loading = true;
        _error = null;
        _suggestion = null;
      });
    }

    final response = await widget.api.get('/scheduling/availability', query);
    if (!mounted || ticket != _request) return;
    if (quiet && !response.ok) return;

    setState(() {
      _loading = false;
      _resolved = null;
      if (!response.ok) {
        _error = response.reason;
        _areas = null;
        return;
      }
      final resolved = response.body['data']?['resolved'];
      _resolved = resolved is Map ? resolved.cast<String, dynamic>() : null;
      _areas = (response.body['data']?['areas'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
    });

    final zip = query['zip'] ?? (_resolved?['zip'] as String?);
    if (response.ok && zip != null) await _askSuggestion(zip, ticket);
  }

  /// Asked beside the availability, not instead of it: a failure here only
  /// means no cards and no map, never a broken answer.
  Future<void> _askSuggestion(String zip, int ticket) async {
    final response = await widget.api.get(
      '/scheduling/availability/suggestion',
      {'zip': zip},
    );
    if (!mounted || ticket != _request) return;
    final data = response.ok ? response.body['data'] : null;
    setState(() {
      _suggestion = data is Map ? data.cast<String, dynamic>() : null;
      _suggestionZip = zip;
    });
  }

  void _setMode(_Mode mode) => setState(() {
    _mode = mode;
    _lastQuery = null;
    _areas = null;
    _error = null;
    _suggestions = [];
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 28, 16, 40),
      children: [
        Center(
          child: ConstrainedBox(
            // The calendar needs seven readable columns; the searches read
            // best narrow.
            constraints: BoxConstraints(
              maxWidth: _mode == _Mode.calendar ? 1000 : 680,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'When can we come out?',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'Search by zip code or address, pick an area, or see a month.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
                const SizedBox(height: 20),
                // The labels wrap rather than ellipsize: at phone width all
                // three read "Search by…", "Search by…", "Calendar…".
                // IntrinsicHeight keeps the three the same height when only
                // one of them wraps.
                //
                // Narrow - a side panel beside a lead or the dial pad, or a
                // phone - they are one word each: the long ones wrapped onto
                // two lines apiece (Jose, 2026-09-30: "the text is a bit
                // crowded ... just put Zip - Area - Calendar").
                LayoutBuilder(
                  builder: (context, constraints) {
                    final short = constraints.maxWidth < 560;

                    return IntrinsicHeight(
                      child: Row(
                        key: const ValueKey('tour-scheduling-modes'),
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            child: _modeButton(
                              _Mode.zip,
                              Icons.location_on_outlined,
                              short ? 'Zip' : 'Search by Zip Code',
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: _modeButton(
                              _Mode.area,
                              Icons.map_outlined,
                              short ? 'Area' : 'Search by Area',
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: _modeButton(
                              _Mode.calendar,
                              Icons.calendar_month_outlined,
                              short ? 'Calendar' : 'Calendar View',
                            ),
                          ),
                        ],
                      ),
                    );
                  },
                ),
                const SizedBox(height: 14),
                switch (_mode) {
                  _Mode.zip => _zipSearch(theme),
                  _Mode.area => _areaChips(),
                  _Mode.calendar => AvailabilityCalendar(
                    api: widget.api,
                    visible: widget.visible,
                    franchises: _franchises,
                  ),
                },
                const SizedBox(height: 20),
                if (_error != null) ErrorNote(_error!),
                if (_resolved != null && !_loading)
                  Padding(
                    key: const Key('availability-resolved'),
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Row(
                      children: [
                        Icon(Icons.place_outlined, size: 18, color: muted),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            '${_resolved!['address']} — zip ${_resolved!['zip']}'
                            '${_resolved!['exact'] == true ? '' : ' (approximate: check the street with the customer)'}',
                            style: theme.textTheme.bodySmall,
                          ),
                        ),
                      ],
                    ),
                  ),
                if (_loading)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Center(child: CircularProgressIndicator()),
                  )
                else if (_areas != null && _areas!.isEmpty)
                  _note(theme, _unknown)
                else
                  for (final area in _areas ?? const <Map<String, dynamic>>[])
                    _areaCard(theme, area),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _modeButton(_Mode mode, IconData icon, String label) {
    final selected = _mode == mode;
    final text = Text(label, textAlign: TextAlign.center);
    // On a phone the icon sits above the words so they get the button's whole
    // width; beside it, "Search by Zip Code" ran to three lines.
    final child = MediaQuery.sizeOf(context).width < 600
        ? Column(
            mainAxisSize: MainAxisSize.min,
            children: [Icon(icon, size: 18), const SizedBox(height: 4), text],
          )
        : Row(
            mainAxisAlignment: MainAxisAlignment.center,
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 18),
              const SizedBox(width: 8),
              Flexible(child: text),
            ],
          );
    const padding = EdgeInsets.symmetric(vertical: 14, horizontal: 8);

    return selected
        ? FilledButton(
            onPressed: () => _setMode(mode),
            style: FilledButton.styleFrom(padding: padding),
            child: child,
          )
        : OutlinedButton(
            onPressed: () => _setMode(mode),
            style: OutlinedButton.styleFrom(padding: padding),
            child: child,
          );
  }

  Widget _zipSearch(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: TextField(
                key: const Key('availability-search'),
                controller: _search,
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(
                  hintText: 'Zip code, address, city or area',
                  border: OutlineInputBorder(),
                ),
                onChanged: _suggest,
                onSubmitted: (_) => _check(),
              ),
            ),
            const SizedBox(width: 8),
            FilledButton(
              key: const ValueKey('tour-scheduling-check'),
              onPressed: _check,
              style: FilledButton.styleFrom(
                padding: const EdgeInsets.symmetric(
                  horizontal: 22,
                  vertical: 18,
                ),
              ),
              child: const Text('Check'),
            ),
          ],
        ),
        if (_suggestions.isNotEmpty)
          Card(
            margin: const EdgeInsets.only(top: 4),
            child: Column(
              children: [
                for (final s in _suggestions)
                  ListTile(
                    dense: true,
                    leading: Icon(switch (s['type']) {
                      'area' => Icons.map_outlined,
                      'city' => Icons.location_city_outlined,
                      _ => Icons.location_on_outlined,
                    }, size: 18),
                    title: Text(s['label'] as String? ?? ''),
                    subtitle: s['detail'] == null
                        ? null
                        : Text(s['detail'] as String),
                    onTap: () => _pick(s),
                  ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _areaChips() {
    if (_franchises.isEmpty) {
      return _note(Theme.of(context), 'No service areas in this view yet.');
    }

    return Wrap(
      alignment: WrapAlignment.center,
      spacing: 8,
      runSpacing: 8,
      children: [
        ChoiceChip(
          label: const Text('All areas'),
          selected: _franchiseId == _all,
          onSelected: (_) => _pickFranchise(_all),
        ),
        for (final f in _franchises)
          ChoiceChip(
            label: Text(f['name'] as String? ?? ''),
            selected: _franchiseId == f['id'],
            onSelected: (_) => _pickFranchise(f['id'] as String),
          ),
      ],
    );
  }

  Widget _areaCard(ThemeData theme, Map<String, dynamic> area) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final first = area['first'] as Map<String, dynamic>?;
    final after = (area['after'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    // Closed days the server named, up to the last day shown (Mia,
    // 2026-10-02). Rows with a `reason` are closed; the rest are openings.
    final closed = (area['closed'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    List<Map<String, dynamic>> closedBefore(String date) => [
      for (final day in closed)
        if ((day['date'] as String).compareTo(date) < 0) day,
    ];
    // The server only names closed days up to the last day it shows, so
    // every one after the first opening belongs in this list.
    List<Map<String, dynamic>> withClosed(List<Map<String, dynamic>> open) {
      if (first == null) return open;
      final from = first['date'] as String;
      return [
        ...open,
        for (final day in closed)
          if ((day['date'] as String).compareTo(from) > 0) day,
      ]..sort((a, b) => (a['date'] as String).compareTo(b['date'] as String));
    }
    final heading = [
      area['franchise_name'],
      area['area_name'],
    ].whereType<String>().join(' · ').toUpperCase();

    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              heading,
              style: theme.textTheme.labelSmall?.copyWith(
                color: muted,
                letterSpacing: 0.8,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 10),
            if (area['configured'] != true ||
                (first == null && area['none_because'] != 'closed'))
              Text(
                _unknown,
                style: theme.textTheme.bodyMedium?.copyWith(color: muted),
              )
            // Set up and closed every day (Mia, 2026-10-02): said, not
            // passed off to Workiz as unknown.
            else if (first == null)
              Text(
                'Closed. This area is not taking jobs on any day.',
                key: const Key('availability-closed-all'),
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              )
            else ...[
              // Closed days before the first opening: a holiday tomorrow
              // reads as closed, not as missing.
              for (final day in closedBefore(first['date'] as String))
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: _closedRow(theme, day),
                ),
              // Earliest beside Suggested, with the route (UI queue 83),
              // for the area the suggestion was worked out for.
              if (_suggestion?['area_id'] == area['area_id'] &&
                  _suggestion?['earliest'] is Map)
                AvailabilitySuggestion(
                  // A refresh that changes nothing does not replay the map.
                  key: ValueKey(jsonEncode(_suggestion)),
                  api: widget.api,
                  data: _suggestion!,
                  zip:
                      (_suggestion!['origin'] as Map?)?['zip'] as String? ??
                      _suggestionZip ??
                      '',
                  windowChip: _windowChip,
                  longDate: _longDate,
                )
              else ...[
                Text(
                  'First available',
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
                const SizedBox(height: 2),
                Text(
                  _longDate(first['date'] as String),
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: ToneColours.of(context, Tone.ok).ink,
                  ),
                ),
                const SizedBox(height: 10),
                _windowRow(first),
              ],
              _noTime(theme, first),
              if (withClosed(after).isNotEmpty) ...[
                const Divider(height: 28),
                Text(
                  'After that',
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
                const SizedBox(height: 8),
                for (final day in withClosed(after))
                  if (day['reason'] != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: _closedRow(theme, day),
                    )
                  else
                  Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SizedBox(
                          width: 92,
                          child: Padding(
                            padding: const EdgeInsets.only(top: 6),
                            child: Text(
                              _shortDate(day['date'] as String),
                              style: theme.textTheme.bodyMedium?.copyWith(
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ),
                        Expanded(child: _windowRow(day)),
                      ],
                    ),
                  ),
              ],
            ],
          ],
        ),
      ),
    );
  }

  /// A closed day in the list: its date and why (Mia, 2026-10-02).
  Widget _closedRow(ThemeData theme, Map<String, dynamic> day) {
    final neutral = ToneColours.of(context, Tone.neutral);
    final why = switch (day['reason']) {
      'date' => 'Closed that day',
      'not_yet' => 'Not open yet',
      _ => 'Closed',
    };

    return Row(
      key: ValueKey('availability-closed-${day['date']}'),
      children: [
        SizedBox(
          width: 92,
          child: Text(
            _shortDate(day['date'] as String),
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        Expanded(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
            decoration: BoxDecoration(
              color: neutral.fill,
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              why,
              style: theme.textTheme.bodySmall?.copyWith(
                color: neutral.ink,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// One chip per window, filling the row; they wrap on a phone rather than
  /// shrinking the text past reading.
  Widget _windowRow(Map<String, dynamic> day) {
    final windows = (day['windows'] as List? ?? const [])
        .cast<Map<String, dynamic>>();

    return LayoutBuilder(
      builder: (context, box) {
        final perRow = box.maxWidth < 420 ? 1 : windows.length;
        final width = perRow <= 1
            ? box.maxWidth
            : (box.maxWidth - 6 * (perRow - 1)) / perRow;

        return Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final w in windows)
              SizedBox(width: width, child: _windowChip(w)),
          ],
        );
      },
    );
  }

  Widget _windowChip(Map<String, dynamic> w) {
    final left = (w['remaining'] as num?)?.toInt() ?? 0;
    final tone = switch (left) {
      <= 0 => Tone.neutral,
      1 => Tone.bad,
      2 => Tone.caution,
      _ => Tone.ok,
    };
    final colours = ToneColours.of(context, tone);
    final label = left <= 0
        ? 'Full'
        : '$left ${left == 1 ? 'Spot' : 'Spots'} Left';

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: colours.fill,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        '${_time(w['start_time'] as String)} – '
        '${_time(w['end_time'] as String)}  ·  $label',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
          color: colours.ink,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }

  /// Jobs that day in no window — no time set in Workiz, or a time outside
  /// every window. Said rather than dropped, so nobody reads "2 spots left"
  /// without knowing two more are booked with no time on them.
  Widget _noTime(ThemeData theme, Map<String, dynamic> day) {
    final n = (day['no_time'] as num?)?.toInt() ?? 0;
    if (n == 0) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Text(
        '$n more booked that day with no time slot',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  Widget _note(ThemeData theme, String text) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 16),
    child: Text(
      text,
      textAlign: TextAlign.center,
      style: theme.textTheme.bodyMedium?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    ),
  );

  /// "2026-10-05" → "Monday, October 5".
  static String _longDate(String ymd) {
    final d = DateTime.parse(ymd);
    return '${_weekdays[d.weekday - 1]}, ${_months[d.month - 1]} ${d.day}';
  }

  /// "2026-10-05" → "Mon, Oct 5".
  static String _shortDate(String ymd) {
    final d = DateTime.parse(ymd);
    return '${_weekdays[d.weekday - 1].substring(0, 3)}, '
        '${_months[d.month - 1].substring(0, 3)} ${d.day}';
  }

  /// "13:00" → "1 PM", "10:30" → "10:30 AM".
  static String _time(String hm) {
    final parts = hm.split(':');
    final h = int.tryParse(parts.first) ?? 0;
    final m = parts.length > 1 ? parts[1] : '00';
    final h12 = h % 12 == 0 ? 12 : h % 12;
    final period = h < 12 ? 'AM' : 'PM';
    return m == '00' ? '$h12 $period' : '$h12:$m $period';
  }
}
