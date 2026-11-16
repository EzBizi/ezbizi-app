import 'dart:async';

import 'package:flutter/material.dart';

import '../../widgets/search_picker.dart';
import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';

/// Availability › Calendar View: a month at a glance for a franchise or one of
/// its areas — spots left of what the day takes, green, amber or red.
///
/// Jose, 2026-09-25, with A1 Tools' calendar: "a sub tab that shows a sort of
/// Calendar View for each franchise or service area". Every number is the
/// server's (SchedulingAvailabilityController::calendar, which reads
/// Openings), so this and the search cannot disagree.
/// How often an open availability answer asks again (Jose, 2026-10-05). A
/// booking reaches EzBizi within the minute the Workiz sync takes.
const availabilityRefreshEvery = Duration(seconds: 30);

class AvailabilityCalendar extends StatefulWidget {
  const AvailabilityCalendar({
    super.key,
    required this.api,
    required this.franchises,
    this.month,
    this.visible = true,
  });

  /// Whether its tab is showing: it refreshes itself only then.
  final bool visible;

  final ApiClient api;

  /// `[{id, name}]`, the franchises in view that have areas.
  final List<Map<String, dynamic>> franchises;

  /// The month it opens on; this month when null.
  final DateTime? month;

  @override
  State<AvailabilityCalendar> createState() => _AvailabilityCalendarState();
}

class _AvailabilityCalendarState extends State<AvailabilityCalendar> {
  static const double cellHeight = 92;

  /// Null before a pick; [_all] is "All areas" — every franchise in view,
  /// added up (Jose, 2026-09-28).
  String? _franchiseId;
  static const _all = '*';
  String? _areaId;
  late DateTime _month = DateTime(
    (widget.month ?? DateTime.now()).year,
    (widget.month ?? DateTime.now()).month,
  );
  Map<String, dynamic>? _data;
  bool _loading = false;
  String? _error;
  int _request = 0;

  /// Kept current like the zip and area answers (Jose, 2026-10-05).
  Timer? _refresh;

  @override
  void initState() {
    super.initState();
    _refresh = Timer.periodic(
      availabilityRefreshEvery,
      (_) => widget.visible && !_loading ? _load(quiet: true) : null,
    );
  }

  @override
  void didUpdateWidget(covariant AvailabilityCalendar old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible && !_loading) _load(quiet: true);
  }

  @override
  void dispose() {
    _refresh?.cancel();
    super.dispose();
  }

  /// [quiet] is a refresh: no spinner, and a failed one leaves the month up.
  Future<void> _load({bool quiet = false}) async {
    final franchise = _franchiseId;
    if (franchise == null) return;

    final ticket = ++_request;
    if (!quiet) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    final response = await widget.api.get('/scheduling/availability/calendar', {
      'month':
          '${_month.year.toString().padLeft(4, '0')}-'
          '${_month.month.toString().padLeft(2, '0')}',
      if (franchise != _all) 'franchise_id': franchise,
      'area_id': ?_areaId,
    });
    if (!mounted || ticket != _request) return;
    if (quiet && !response.ok) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _data = response.body['data'] as Map<String, dynamic>?;
    });
  }

  void _shift(int months) {
    setState(() => _month = DateTime(_month.year, _month.month + months));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final data = _data;
    final areas = (data?['areas'] as List? ?? const [])
        .cast<Map<String, dynamic>>();

    if (widget.franchises.isEmpty) {
      return Text(
        'No service areas in this view yet.',
        textAlign: TextAlign.center,
        style: theme.textTheme.bodySmall?.copyWith(color: muted),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // One searchable picker rather than a chip per branch (deep dive
        // 2026-11-02: 30 chips over four rows).
        Center(
          child: SizedBox(
            width: 360,
            child: SearchPickerField<String>(
              key: const ValueKey('calendar-franchise-picker'),
              value: _franchiseId,
              hint: 'Choose a branch',
              options: [
                const SearchOption(value: _all, label: 'All areas'),
                for (final f in widget.franchises)
                  SearchOption(
                    value: f['id'] as String,
                    // "Company · Branch", labelled by the pane from /me.
                    label: f['label'] as String? ?? f['name'] as String? ?? '',
                  ),
              ],
              onChanged: (id) {
                if (id == null) return;
                setState(() {
                  _franchiseId = id;
                  _areaId = null;
                });
                _load();
              },
            ),
          ),
        ),
        if (_franchiseId == null)
          Padding(
            padding: const EdgeInsets.only(top: 20),
            child: Text(
              'Choose a branch, or All areas, to see its month.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ),
        if (areas.length > 1) ...[
          const SizedBox(height: 10),
          Wrap(
            alignment: WrapAlignment.center,
            spacing: 6,
            runSpacing: 6,
            children: [
              ChoiceChip(
                label: Text('All of ${data?['franchise']?['name'] ?? ''}'),
                selected: _areaId == null,
                onSelected: (_) {
                  setState(() => _areaId = null);
                  _load();
                },
              ),
              for (final a in areas)
                ChoiceChip(
                  label: Text(a['name'] as String? ?? ''),
                  selected: _areaId == a['id'],
                  onSelected: (_) {
                    setState(() => _areaId = a['id'] as String);
                    _load();
                  },
                ),
            ],
          ),
        ],
        if (_franchiseId != null) ...[
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              IconButton(
                tooltip: 'Previous month',
                icon: const Icon(Icons.chevron_left),
                onPressed: () => _shift(-1),
              ),
              SizedBox(
                width: 170,
                child: Text(
                  '${longMonths[_month.month - 1]} ${_month.year}',
                  key: const Key('calendar-month'),
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleMedium,
                ),
              ),
              IconButton(
                tooltip: 'Next month',
                icon: const Icon(Icons.chevron_right),
                onPressed: () => _shift(1),
              ),
            ],
          ),
          if (_error != null) ErrorNote(_error!),
          if (_loading && data == null)
            const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (data != null) ...[
            if (data['configured'] != true)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  'No capacity is set here yet, so there is nothing to offer. '
                  'Add it in Settings › Availability.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ),
            _grid(theme, data),
            const SizedBox(height: 8),
            Text(
              'Spots left of what the day takes. Green: room · amber: a third '
              'or less left · red: full. "Over" is booked past what the day '
              'takes.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ],
        ],
      ],
    );
  }

  /// Monday first, the month's days in place, blanks before the 1st. Every
  /// cell the same size (Jose: "you always make that mistake").
  Widget _grid(ThemeData theme, Map<String, dynamic> data) {
    final days = (data['days'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final lead = DateTime(_month.year, _month.month).weekday - 1;
    final cells = <Map<String, dynamic>?>[
      for (var i = 0; i < lead; i++) null,
      ...days,
    ];
    while (cells.length % 7 != 0) {
      cells.add(null);
    }

    return Column(
      children: [
        Row(
          children: [
            for (final d in shortWeekdays)
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Text(
                    d,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
              ),
          ],
        ),
        for (var w = 0; w < cells.length; w += 7)
          Row(
            children: [
              for (var i = w; i < w + 7; i++)
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.all(2),
                    child: SizedBox(
                      height: cellHeight,
                      child: cells[i] == null
                          ? const SizedBox.shrink()
                          : _cell(theme, cells[i]!, data['today'] as String?),
                    ),
                  ),
                ),
            ],
          ),
      ],
    );
  }

  Widget _cell(ThemeData theme, Map<String, dynamic> d, String? today) {
    final date = d['date'] as String;
    final closed = d['closed'] == true;
    final capacity = (d['capacity'] as num?)?.toInt() ?? 0;
    final remaining = (d['remaining'] as num?)?.toInt() ?? 0;
    final over = (d['over'] as num?)?.toInt() ?? 0;
    final isToday = date == today;
    final past = today != null && date.compareTo(today) < 0;
    final muted = theme.colorScheme.onSurfaceVariant;
    final bad = ToneColours.of(context, Tone.bad);

    final tone = ToneColours.of(
      context,
      remaining == 0
          ? Tone.bad
          : remaining * 3 <= capacity
          ? Tone.caution
          : Tone.ok,
    );

    // A tile looked selectable and a tap did nothing (deep dive 2026-11-02
    // #9). A tap (or hover) now says the day in words.
    return Tooltip(
      key: ValueKey('calendar-day-tip-$date'),
      triggerMode: TooltipTriggerMode.tap,
      message: closed
          ? (over > 0 ? 'Closed · $over booked' : 'Closed')
          : '$remaining of $capacity spots left'
                '${over > 0 ? ' · $over over' : ''}',
      child: Opacity(
        opacity: past ? 0.55 : 1,
        child: Container(
          key: ValueKey('calendar-day-$date'),
          padding: const EdgeInsets.fromLTRB(6, 4, 6, 6),
          decoration: BoxDecoration(
            color: isToday
                ? theme.colorScheme.primary.withValues(alpha: 0.08)
                : theme.colorScheme.surfaceContainerHighest.withValues(
                    alpha: 0.35,
                  ),
            border: Border.all(
              color: isToday ? theme.colorScheme.primary : Colors.transparent,
            ),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                '${int.parse(date.substring(8))}',
                style: theme.textTheme.labelSmall?.copyWith(color: muted),
              ),
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: closed
                      ? Column(
                          children: [
                            Text(
                              'Closed',
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: muted,
                              ),
                            ),
                            if (over > 0)
                              Text(
                                '$over booked',
                                style: theme.textTheme.labelSmall?.copyWith(
                                  color: bad.ink,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                          ],
                        )
                      : Column(
                          children: [
                            Text(
                              '$remaining',
                              style: theme.textTheme.titleLarge?.copyWith(
                                color: tone.ink,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                            Text(
                              'of $capacity',
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: muted,
                              ),
                            ),
                            if (over > 0)
                              Text(
                                '$over over',
                                style: theme.textTheme.labelSmall?.copyWith(
                                  color: bad.ink,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                          ],
                        ),
                ),
              ),
              if (!closed)
                ClipRRect(
                  borderRadius: BorderRadius.circular(2),
                  child: LinearProgressIndicator(
                    // Filled by what is TAKEN of what the day takes, so it agrees
                    // with the number above it. Not booked / capacity: jobs piled
                    // into one slot over its limit leave another slot open, and
                    // "3 of 8" beside a full bar read as a contradiction (Oct 2,
                    // Connecticut: 8 booked, 3 still offered at 11–2).
                    value: capacity == 0
                        ? 1
                        : (1 - remaining / capacity).clamp(0, 1),
                    minHeight: 4,
                    color: tone.ink,
                    backgroundColor: tone.fill,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
