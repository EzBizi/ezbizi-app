import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/theme.dart';

/// A window picked on the [AvailabilityGrid]: its day and times ("HH:MM").
typedef PickedWindow = ({String date, String start, String end});

/// The area's days × windows with the spots left in each (design 197,
/// docs/previews/jobs-book-tab-layouts.html: option 1's grid, phone 4's day
/// strip). Reads GET /scheduling/availability/days (Scheduling's Openings),
/// so it and Availability cannot disagree.
///
/// Only a window with room can be picked: a full one is striped, a window
/// after the day's last start reads Blocked with its reason, a closed day
/// reads Closed. The server checks again on Create.
class AvailabilityGrid extends StatefulWidget {
  const AvailabilityGrid({
    super.key,
    required this.api,
    required this.zip,
    this.areaId,
    this.picked,
    required this.onPick,
    this.days = 7,
  });

  final ApiClient api;
  final String zip;
  final String? areaId;
  final PickedWindow? picked;
  final ValueChanged<PickedWindow> onPick;
  final int days;

  @override
  State<AvailabilityGrid> createState() => AvailabilityGridState();
}

class AvailabilityGridState extends State<AvailabilityGrid> {
  Map<String, dynamic>? _data;
  String? _error;
  String _key = '';

  /// The first day shown; null is Scheduling's own start (tomorrow).
  DateTime? _from;

  @override
  void initState() {
    super.initState();
    reload();
  }

  @override
  void didUpdateWidget(AvailabilityGrid oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.zip != widget.zip || oldWidget.areaId != widget.areaId) {
      _from = null;
      reload();
    }
  }

  static String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  /// Fetches the days again: on a page turn, or after Create was refused
  /// because a window filled meanwhile.
  Future<void> reload() async {
    final key = '${widget.zip}|${widget.areaId}|$_from';
    _key = key;
    setState(() => _error = null);
    final response = await widget.api.get('/scheduling/availability/days', {
      if (widget.areaId != null)
        'area_id': widget.areaId!
      else
        'zip': widget.zip,
      'days': '${widget.days}',
      if (_from != null) 'from': _ymd(_from!),
    });
    if (!mounted || key != _key) return;
    setState(() {
      _data = response.ok && response.data is Map
          ? (response.data as Map).cast<String, dynamic>()
          : null;
      _error = response.ok ? null : response.message;
    });
  }

  List<Map<String, dynamic>> get _days => [
    for (final d in (_data?['days'] as List? ?? const []))
      if (d is Map) d.cast<String, dynamic>(),
  ];

  void _page(int by) {
    final days = _days;
    if (days.isEmpty) return;
    final first = DateTime.parse(days.first['date'] as String);
    _from = first.add(Duration(days: by * widget.days));
    reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    if (_error != null) {
      return Text(
        _error!,
        key: const ValueKey('grid-error'),
        style: muted?.copyWith(color: theme.colorScheme.error),
      );
    }
    final data = _data;
    if (data == null) return const LinearProgressIndicator();
    if (data['area_id'] == null) {
      return Text(
        'No service area covers ${widget.zip}. Pick the area under the address.',
        key: const ValueKey('grid-no-area'),
        style: muted,
      );
    }
    if (data['configured'] == false) {
      return Text(
        "Nobody has set up ${data['area_name']}'s windows yet; check Workiz.",
        key: const ValueKey('grid-unconfigured'),
        style: muted,
      );
    }
    final days = _days;
    final first = days.isEmpty ? null : DateTime.parse(days.first['date']);
    final tomorrow = DateUtils.dateOnly(
      DateTime.now(),
    ).add(const Duration(days: 1));
    final header = Row(
      children: [
        Expanded(
          child: Text(
            '${data['area_name']}',
            style: theme.textTheme.titleSmall,
            overflow: TextOverflow.ellipsis,
          ),
        ),
        IconButton(
          key: const ValueKey('grid-earlier'),
          tooltip: 'Earlier days',
          visualDensity: VisualDensity.compact,
          icon: const Icon(Icons.chevron_left),
          onPressed: first == null || !first.isAfter(tomorrow)
              ? null
              : () => _page(-1),
        ),
        IconButton(
          key: const ValueKey('grid-later'),
          tooltip: 'Later days',
          visualDensity: VisualDensity.compact,
          icon: const Icon(Icons.chevron_right),
          onPressed: days.isEmpty ? null : () => _page(1),
        ),
      ],
    );
    return LayoutBuilder(
      builder: (context, box) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          header,
          const SizedBox(height: 6),
          box.maxWidth >= 520 ? _table(context, days) : _strip(context, days),
        ],
      ),
    );
  }

  // ── Cells ─────────────────────────────────────────────────────

  static String _hm(Object? t) {
    final s = '${t ?? ''}';
    return s.length >= 5 ? s.substring(0, 5) : s;
  }

  static String _clock(String hm) {
    final parts = hm.split(':');
    final h = int.tryParse(parts.first) ?? 0;
    final m = parts.length > 1 ? parts[1] : '00';
    final h12 = h % 12 == 0 ? 12 : h % 12;
    return m == '00' ? '$h12' : '$h12:$m';
  }

  static String _span(String start, String end) =>
      '${_clock(start)}–${_clock(end)}';

  static const _weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  /// Every distinct window across the days, earliest first: the rows.
  static List<(String, String)> _rows(List<Map<String, dynamic>> days) {
    final seen = <(String, String)>{};
    for (final d in days) {
      for (final w in (d['windows'] as List? ?? const [])) {
        if (w is Map) seen.add((_hm(w['start_time']), _hm(w['end_time'])));
      }
    }
    return seen.toList()..sort((a, b) => a.$1.compareTo(b.$1));
  }

  static Map<String, dynamic>? _window(
    Map<String, dynamic> day,
    (String, String) row,
  ) {
    for (final w in (day['windows'] as List? ?? const [])) {
      if (w is Map &&
          _hm(w['start_time']) == row.$1 &&
          _hm(w['end_time']) == row.$2) {
        return w.cast<String, dynamic>();
      }
    }
    return null;
  }

  bool _isPicked(String date, (String, String) row) =>
      widget.picked?.date == date && widget.picked?.start == row.$1;

  /// What a cell says and whether it can be picked.
  ({String label, String? sub, Tone? tone, bool open, bool striped}) _cell(
    Map<String, dynamic> day,
    (String, String) row,
  ) {
    final windows = day['windows'] as List? ?? const [];
    if (day['closed'] != null || windows.isEmpty) {
      return (
        label: 'Closed',
        sub: null,
        tone: null,
        open: false,
        striped: false,
      );
    }
    final w = _window(day, row);
    if (w == null) {
      return (label: '—', sub: null, tone: null, open: false, striped: false);
    }
    final taken = (w['taken'] as num?)?.toInt() ?? 0;
    final capacity = (w['capacity'] as num?)?.toInt() ?? 0;
    final left = (w['remaining'] as num?)?.toInt() ?? 0;
    if (w['late'] == true) {
      return (
        label: 'Blocked',
        sub: (w['late_reason'] as String?) ?? 'After the last start',
        tone: null,
        open: false,
        striped: true,
      );
    }
    if (left <= 0) {
      return (
        label: 'Full',
        sub: '$taken/$capacity',
        tone: null,
        open: false,
        striped: true,
      );
    }
    return (
      label: '$left left',
      sub: '$taken/$capacity',
      tone: left == 1 ? Tone.caution : Tone.ok,
      open: true,
      striped: false,
    );
  }

  Widget _box(
    BuildContext context, {
    required Key key,
    required ({String label, String? sub, Tone? tone, bool open, bool striped})
    c,
    required bool picked,
    required VoidCallback onTap,
    bool wide = false,
    String? lead,
  }) {
    final theme = Theme.of(context);
    final tone = c.tone == null ? null : ToneColours.of(context, c.tone!);
    final fill = picked
        ? theme.colorScheme.primary
        : tone?.fill ??
              (c.striped
                  ? theme.colorScheme.surfaceContainerHighest
                  : Colors.transparent);
    final ink = picked
        ? theme.colorScheme.onPrimary
        : tone?.ink ?? theme.colorScheme.onSurfaceVariant;
    final text = Text(
      picked ? 'Picked' : c.label,
      style: theme.textTheme.labelMedium?.copyWith(
        color: ink,
        fontWeight: FontWeight.w700,
      ),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );
    final sub = c.sub == null
        ? null
        : Text(
            c.sub!,
            style: theme.textTheme.labelSmall?.copyWith(color: ink),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          );
    return Semantics(
      button: c.open,
      enabled: c.open,
      selected: picked,
      child: Material(
        key: key,
        color: fill,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
          side: c.tone == null && !c.striped && !picked
              ? BorderSide(color: EzBiziTheme.edge(context))
              : BorderSide.none,
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: c.open ? onTap : null,
          child: Padding(
            padding: EdgeInsets.symmetric(
              horizontal: wide ? 12 : 4,
              vertical: wide ? 10 : 6,
            ),
            child: wide
                ? Row(
                    children: [
                      Expanded(
                        child: Text(
                          lead ?? '',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: ink,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                      Flexible(
                        child: c.sub != null && !c.open
                            ? Column(
                                crossAxisAlignment: CrossAxisAlignment.end,
                                children: [text, sub!],
                              )
                            : text,
                      ),
                    ],
                  )
                : Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [text, ?sub],
                  ),
          ),
        ),
      ),
    );
  }

  /// Desktop: windows down the side, days across.
  Widget _table(BuildContext context, List<Map<String, dynamic>> days) {
    final theme = Theme.of(context);
    final rows = _rows(days);
    if (rows.isEmpty) {
      return Text(
        'Closed every day shown.',
        key: const ValueKey('grid-all-closed'),
        style: theme.textTheme.bodySmall,
      );
    }
    final head = theme.textTheme.labelMedium?.copyWith(
      fontWeight: FontWeight.w700,
    );
    return Table(
      key: const ValueKey('availability-grid'),
      columnWidths: {
        0: const FixedColumnWidth(56),
        for (var i = 1; i <= days.length; i++) i: const FlexColumnWidth(),
      },
      defaultVerticalAlignment: TableCellVerticalAlignment.middle,
      children: [
        TableRow(
          children: [
            const SizedBox.shrink(),
            for (final d in days)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Column(
                  children: [
                    Text(
                      _weekdays[DateTime.parse(d['date']).weekday - 1],
                      style: head,
                    ),
                    Text(
                      '${DateTime.parse(d['date']).day}',
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
          ],
        ),
        for (final row in rows)
          TableRow(
            children: [
              Text(_span(row.$1, row.$2), style: head),
              for (final d in days)
                Padding(
                  padding: const EdgeInsets.all(2),
                  child: _box(
                    context,
                    key: ValueKey('grid-${d['date']}-${row.$1}'),
                    c: _cell(d, row),
                    picked: _isPicked(d['date'] as String, row),
                    onTap: () => widget.onPick((
                      date: d['date'] as String,
                      start: row.$1,
                      end: row.$2,
                    )),
                  ),
                ),
            ],
          ),
      ],
    );
  }

  String? _stripDay;

  /// Phone: a strip of days, then that day's windows as rows.
  Widget _strip(BuildContext context, List<Map<String, dynamic>> days) {
    final theme = Theme.of(context);
    bool hasRoom(Map<String, dynamic> d) =>
        _rows([d]).any((r) => _cell(d, r).open);
    final chosen =
        days
            .where((d) => d['date'] == (_stripDay ?? widget.picked?.date))
            .firstOrNull ??
        days.where(hasRoom).firstOrNull ??
        days.firstOrNull;
    if (chosen == null) return const SizedBox.shrink();
    final rows = _rows([chosen]);
    return Column(
      key: const ValueKey('availability-strip'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (final d in days)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(
                    key: ValueKey('grid-day-${d['date']}'),
                    showCheckmark: false,
                    selected: d == chosen,
                    label: Text(
                      '${_weekdays[DateTime.parse(d['date']).weekday - 1]} ${DateTime.parse(d['date']).day}',
                      style: hasRoom(d)
                          ? null
                          : TextStyle(color: theme.disabledColor),
                    ),
                    onSelected: (_) =>
                        setState(() => _stripDay = d['date'] as String),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        if (rows.isEmpty)
          Text('Closed', style: theme.textTheme.bodySmall)
        else
          for (final row in rows)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: _box(
                context,
                key: ValueKey('grid-${chosen['date']}-${row.$1}'),
                c: _cell(chosen, row),
                picked: _isPicked(chosen['date'] as String, row),
                wide: true,
                lead: _span(row.$1, row.$2),
                onTap: () => widget.onPick((
                  date: chosen['date'] as String,
                  start: row.$1,
                  end: row.$2,
                )),
              ),
            ),
      ],
    );
  }
}
