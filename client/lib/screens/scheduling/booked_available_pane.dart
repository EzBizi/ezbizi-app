import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/tab_bar_like.dart';
import '../../core/session.dart';
import 'offer_panel.dart';
import 'rescheduling_pane.dart';

/// Scheduling › Re-Scheduling (Jose, 2026-11-09: one tab, Overbooking then
/// Underbooking): Overbooking (days booked past what they take), Underbooking
/// (booked vs available, with the earlier-opening offers) and the AI
/// rescheduling suggestions. All three take `scheduling.rescheduling.view`;
/// offers still need `scheduling.edit` ([canEdit]).
///
/// design: docs/previews/scheduling-booked-vs-available-layouts.html — #1, phone #4
/// (UI queue 147).
class ReschedulingTab extends StatefulWidget {
  const ReschedulingTab({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
    required this.canEdit,
    this.linkDate,
    this.linkSerial = 0,
  });

  final ApiClient api;
  final Session session;
  final bool visible;
  final bool canEdit;

  /// A deep link's day (`/scheduling/overbooking?date=`); a new [linkSerial]
  /// opens Underbooking on it.
  final String? linkDate;
  final int linkSerial;

  @override
  State<ReschedulingTab> createState() => _ReschedulingTabState();
}

class _ReschedulingTabState extends State<ReschedulingTab> {
  int _tab = 0;
  final Set<int> _shown = {0};

  static const _underbooking = 1;

  @override
  void initState() {
    super.initState();
    if (widget.linkSerial > 0) _select(_underbooking);
  }

  @override
  void didUpdateWidget(covariant ReschedulingTab old) {
    super.didUpdateWidget(old);
    if (widget.linkSerial != old.linkSerial) _select(_underbooking);
  }

  void _select(int i) {
    _tab = i;
    _shown.add(i);
  }

  @override
  Widget build(BuildContext context) {
    final panes = <Widget Function()>[
      () => BookedAvailablePane(
        api: widget.api,
        visible: widget.visible && _tab == 0,
        canEdit: widget.canEdit,
        mode: BookedMode.over,
      ),
      () => BookedAvailablePane(
        api: widget.api,
        visible: widget.visible && _tab == _underbooking,
        canEdit: widget.canEdit,
        date: widget.linkDate,
        dateSerial: widget.linkSerial,
      ),
      () => ReschedulingPane(
        api: widget.api,
        session: widget.session,
        visible: widget.visible && _tab == 2,
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          key: const ValueKey('tour-scheduling-rescheduling-segments'),
          level: TabLevel.secondary,
          labels: const [
            ('Overbooking', Icons.warning_amber_outlined),
            ('Underbooking', Icons.stacked_bar_chart),
            ('AI suggestions', Icons.auto_awesome_outlined),
          ],
          selected: _tab,
          onSelected: (i) => setState(() => _select(i)),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < panes.length; i++)
                Offstage(
                  offstage: i != _tab,
                  child: _shown.contains(i)
                      ? panes[i]()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Every area's day, booked against capacity, with texts offering the open
/// spots to customers booked later (server d9efd6504:
/// `GET /scheduling/overbooking/fill`, `POST …/offers/preview` and `…/send`).
/// Which rows the list shows (Jose, 2026-11-12: Overbooking and Underbooking
/// one layout; only the filter, the chip colour and the action wording differ).
enum BookedMode {
  /// Areas booked past what they take; offer their customers a move.
  over,

  /// Areas with open spots; offer them to customers booked later.
  open,
}

class BookedAvailablePane extends StatefulWidget {
  const BookedAvailablePane({
    super.key,
    required this.api,
    required this.visible,
    required this.canEdit,
    this.mode = BookedMode.open,
    this.date,
    this.dateSerial = 0,
  });

  final ApiClient api;
  final bool visible;

  /// Over or Open: the same rows, filtered.
  final BookedMode mode;

  /// `scheduling.edit`: the right to send offers.
  final bool canEdit;

  /// The day to open on; null is tomorrow, the day the day-before email is
  /// about.
  final String? date;
  final int dateSerial;

  /// Below this width the rows are cards and an offer fills the pane.
  static const phoneBelow = 720.0;

  @override
  State<BookedAvailablePane> createState() => _BookedAvailablePaneState();
}

class _BookedAvailablePaneState extends State<BookedAvailablePane> {
  late String _date;
  String? _today;
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _areas = const [];
  int _request = 0;
  final _search = TextEditingController();

  /// Rows opened to their jobs, by area id.
  final Map<String, Future<ApiResponse>> _jobs = {};

  /// The area whose offer panel is open, and that panel's state.
  /// The area row whose offer is open, if any.
  Map<String, dynamic>? _offer;
  String? get _offerArea => _offer == null ? null : '${_offer!['area_id']}';

  @override
  void initState() {
    super.initState();
    _date =
        widget.date ??
        formatCalendarDay(DateTime.now().add(const Duration(days: 1)));
    _load();
    _loadOverDays();
  }

  /// Over mode: which of the next 14 days are over, and in how many areas
  /// (UI-UX, 2026-11-12: a per-day pane lost "which days are over"). From
  /// the same list the multi-day Overbooking report reads.
  Map<String, int> _overDays = const {};

  Future<void> _loadOverDays() async {
    if (widget.mode != BookedMode.over) return;
    final response = await widget.api.get('/scheduling/overbooking', {
      'days': '14',
    });
    if (!mounted || !response.ok) return;
    final rows =
        ((response.body['data'] as Map?)?['overbooked'] as List?) ?? const [];
    final counts = <String, int>{};
    for (final r in rows.whereType<Map>()) {
      if (r['level'] != 'area') continue;
      final d = '${r['date']}';
      counts[d] = (counts[d] ?? 0) + 1;
    }
    setState(() => _overDays = counts);
  }

  @override
  void didUpdateWidget(covariant BookedAvailablePane old) {
    super.didUpdateWidget(old);
    if (widget.dateSerial != old.dateSerial && widget.date != null) {
      _date = widget.date!;
      _load();
    } else if (widget.visible && !old.visible) {
      _load();
      _loadOverDays();
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  static String _day(String ymd) {
    final d = DateTime.parse(ymd);
    return '${shortWeekdays[d.weekday - 1]} ${shortMonths[d.month - 1]} ${d.day}';
  }

  String _shift(String ymd, int days) {
    final d = DateTime.parse(ymd);
    return formatCalendarDay(DateTime(d.year, d.month, d.day + days));
  }

  Future<void> _load() async {
    final ticket = ++_request;
    setState(() {
      _loading = true;
      _error = null;
      _jobs.clear();
      _offer = null;
    });
    final response = await widget.api.get('/scheduling/overbooking/fill', {
      'date': _date,
    });
    if (!mounted || ticket != _request) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      final data = response.body['data'] as Map<String, dynamic>? ?? {};
      _today = data['today'] as String?;
      _areas = (data['areas'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
    });
  }

  void _go(String date) {
    if (date == _date) return;
    _date = date;
    _load();
  }

  static int _int(Object? v) => (v as num?)?.toInt() ?? 0;

  /// Over first (most over at the top), then open spots (most open), then full.
  static int _rank(Map<String, dynamic> a) {
    final gap = _int(a['gap']);
    return gap < 0 ? 0 : (gap > 0 ? 1 : 2);
  }

  static String _place(Map<String, dynamic> a) => [
    a['company_name'],
    a['franchise_name'],
  ].whereType<String>().where((s) => s.trim().isNotEmpty).join(' · ');

  List<Map<String, dynamic>> get _shownAreas {
    final q = _search.text.trim().toLowerCase();
    final over = widget.mode == BookedMode.over;
    final rows = [
      for (final a in _areas)
        if ((q.isEmpty ||
                '${a['name']} ${_place(a)}'.toLowerCase().contains(q)) &&
            (over ? _int(a['gap']) < 0 : _int(a['gap']) >= 0))
          a,
    ];
    rows.sort((a, b) {
      final r = _rank(a).compareTo(_rank(b));
      if (r != 0) return r;
      final ga = _int(a['gap']).abs(), gb = _int(b['gap']).abs();
      if (ga != gb) return gb.compareTo(ga);
      return '${a['name']}'.compareTo('${b['name']}');
    });
    return rows;
  }

  void _toggleJobs(Map<String, dynamic> a) {
    final id = '${a['area_id']}';
    setState(() {
      if (_jobs.remove(id) == null) {
        _jobs[id] = widget.api.get('/scheduling/overbooking/jobs', {
          'date': _date,
          'area_id': id,
        });
      }
    });
  }

  void _openOffer(Map<String, dynamic> a) =>
      setState(() => _offer = _offerArea == '${a['area_id']}' ? null : a);

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < BookedAvailablePane.phoneBelow;
        final offer = _offer;
        if (phone && offer != null) {
          // Phone #4: the offer fills the pane, with a way back.
          return ListView(
            key: const ValueKey('booked-offer-full'),
            padding: const EdgeInsets.all(16),
            children: [
              Row(
                children: [
                  IconButton(
                    key: const Key('booked-offer-back'),
                    tooltip: 'Back to the areas',
                    icon: const Icon(Icons.arrow_back),
                    onPressed: () => setState(() {
                      _offer = null;
                    }),
                  ),
                  Expanded(
                    child: Text(
                      '${widget.mode == BookedMode.over ? 'Offer a move' : 'Offer openings'} · ${offer['name']}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                ],
              ),
              _offerPanel(offer),
            ],
          );
        }
        return _list(context, phone);
      },
    );
  }

  Widget _list(BuildContext context, bool phone) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final rows = _shownAreas;
    // The tiles count every area, whichever list is showing: one picture of the day.
    final over = _areas.where((a) => _int(a['gap']) < 0).length;
    final openSpots = _areas.fold<int>(0, (n, a) {
      final g = _int(a['gap']);
      return g > 0 ? n + g : n;
    });
    final full = _areas.where((a) => _int(a['gap']) == 0).length;
    final today = _today ?? formatCalendarDay(DateTime.now());
    final tomorrow = _shift(today, 1);

    return ListView(
      padding: EdgeInsets.all(phone ? 12 : 20),
      children: [
        Text(
          widget.mode == BookedMode.over
              ? 'Areas booked past what they take that day. Offer their '
                    'customers a move to the next day with room, by text.'
              : 'Areas with open spots that day. Offer them by text to '
                    'customers booked later.',
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
        const SizedBox(height: 12),
        Wrap(
          key: const ValueKey('tour-booked-day'),
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  key: const Key('booked-day-back'),
                  tooltip: 'Day before',
                  icon: const Icon(Icons.chevron_left),
                  onPressed: () => _go(_shift(_date, -1)),
                ),
                Text(
                  _day(_date),
                  key: const ValueKey('booked-day'),
                  style: theme.textTheme.titleSmall,
                ),
                IconButton(
                  key: const Key('booked-day-next'),
                  tooltip: 'Day after',
                  icon: const Icon(Icons.chevron_right),
                  onPressed: () => _go(_shift(_date, 1)),
                ),
              ],
            ),
            ChoiceChip(
              key: const Key('booked-today'),
              label: const Text('Today'),
              selected: _date == today,
              onSelected: (_) => _go(today),
            ),
            ChoiceChip(
              key: const Key('booked-tomorrow'),
              label: const Text('Tomorrow'),
              selected: _date == tomorrow,
              onSelected: (_) => _go(tomorrow),
            ),
            SizedBox(
              width: phone ? double.infinity : 260,
              child: ListSearch(
                key: const ValueKey('booked-available-search'),
                controller: _search,
                hint: 'Area or branch',
                onChanged: (_) => setState(() {}),
              ),
            ),
          ],
        ),
        if (widget.mode == BookedMode.over && _overDays.isNotEmpty) ...[
          const SizedBox(height: 8),
          Wrap(
            key: const ValueKey('booked-over-days'),
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final e
                  in (_overDays.entries.toList()
                    ..sort((a, b) => a.key.compareTo(b.key))))
                ChoiceChip(
                  key: ValueKey('booked-over-day-${e.key}'),
                  selected: e.key == _date,
                  label: Text('${_day(e.key)} · ${e.value} over'),
                  labelStyle: TextStyle(
                    color: ToneColours.of(context, Tone.bad).ink,
                    fontWeight: FontWeight.w600,
                  ),
                  backgroundColor: ToneColours.of(context, Tone.bad).fill,
                  onSelected: (_) => _go(e.key),
                ),
            ],
          ),
        ],
        const SizedBox(height: 12),
        IntrinsicHeight(
          key: const ValueKey('tour-booked-summary'),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: _stat(
                  context,
                  'over',
                  '$over',
                  over == 1 ? 'area over capacity' : 'areas over capacity',
                  over == 0 ? Tone.ok : Tone.bad,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _stat(
                  context,
                  'open',
                  '$openSpots',
                  openSpots == 1 ? 'open spot' : 'open spots',
                  openSpots == 0 ? Tone.neutral : Tone.ok,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _stat(
                  context,
                  'full',
                  '$full',
                  full == 1 ? 'area full' : 'areas full',
                  Tone.neutral,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        if (_loading && _areas.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_error != null)
          Text(_error!, style: TextStyle(color: theme.colorScheme.error))
        else if (rows.isEmpty)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              _areas.isEmpty
                  ? 'No service areas to show for ${_day(_date)}.'
                  : _search.text.trim().isEmpty
                  ? (widget.mode == BookedMode.over
                        ? 'No area is over capacity on ${_day(_date)}.'
                        : 'No area has an open spot on ${_day(_date)}.')
                  : 'No area or branch matches "${_search.text.trim()}".',
              style: TextStyle(color: muted),
            ),
          )
        else ...[
          if (!phone) _header(theme),
          for (final a in rows) _row(context, a, phone),
        ],
      ],
    );
  }

  Widget _stat(
    BuildContext context,
    String key,
    String count,
    String label,
    Tone tone,
  ) {
    final theme = Theme.of(context);
    final c = ToneColours.of(context, tone);
    return Container(
      key: ValueKey('booked-stat-$key'),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            count,
            style: theme.textTheme.titleLarge?.copyWith(
              color: c.ink,
              fontWeight: FontWeight.w800,
            ),
          ),
          Text(label, style: theme.textTheme.bodySmall?.copyWith(color: c.ink)),
        ],
      ),
    );
  }

  Widget _header(ThemeData theme) {
    final style = theme.textTheme.labelSmall?.copyWith(
      letterSpacing: 0.8,
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 4),
      child: Row(
        children: [
          Expanded(flex: 3, child: Text('AREA', style: style)),
          Expanded(flex: 4, child: Text('BOOKED', style: style)),
          Expanded(flex: 4, child: Text('TIME WINDOWS', style: style)),
          const SizedBox(width: 150),
        ],
      ),
    );
  }

  /// "2 over", "3 open" or "Full", with its tone.
  static (String, Tone) _chip(int gap) => gap < 0
      ? ('${-gap} over', Tone.bad)
      : gap > 0
      ? ('$gap open', Tone.ok)
      : ('Full', Tone.neutral);

  Widget _pill(BuildContext context, String text, Tone tone, {Key? key}) {
    final c = ToneColours.of(context, tone);
    return Container(
      key: key,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: c.fill,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelMedium?.copyWith(
          color: c.ink,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  /// Booked against capacity; past capacity the overflow is red.
  Widget _bar(BuildContext context, Map<String, dynamic> a) {
    final id = '${a['area_id']}';
    final booked = _int(a['booked']);
    final capacity = _int(a['capacity']);
    final within = booked < capacity ? booked : capacity;
    final over = booked > capacity ? booked - capacity : 0;
    final room = capacity - within;
    final track = ToneColours.of(context, Tone.neutral).fill;
    return ClipRRect(
      key: ValueKey('booked-bar-$id'),
      borderRadius: BorderRadius.circular(4),
      child: SizedBox(
        height: 10,
        child: ColoredBox(
          color: track,
          child: Row(
            // Stretch, or each childless segment is laid out 0 px tall
            // (b6afab5c3).
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (within > 0)
                Expanded(
                  flex: within,
                  child: ColoredBox(
                    key: ValueKey('booked-bar-$id-booked'),
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
              if (room > 0) Expanded(flex: room, child: const SizedBox()),
              if (over > 0)
                Expanded(
                  flex: over,
                  child: ColoredBox(
                    key: ValueKey('booked-bar-$id-over'),
                    color: ToneColours.of(context, Tone.bad).ink,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  /// The day's time windows: room, full or over.
  Widget _windows(BuildContext context, Map<String, dynamic> a) {
    final windows = (a['windows'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final theme = Theme.of(context);
    if (windows.isEmpty) {
      return Text(
        'No time windows',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      );
    }
    return Wrap(
      spacing: 4,
      runSpacing: 4,
      children: [
        for (final w in windows)
          Builder(
            builder: (context) {
              final taken = _int(w['taken']);
              final cap = _int(w['capacity']);
              final tone = taken > cap
                  ? Tone.bad
                  : taken == cap
                  ? Tone.neutral
                  : Tone.ok;
              final c = ToneColours.of(context, tone);
              final start = _clock('${w['start_time']}');
              return Tooltip(
                message:
                    '${_clock('${w['start_time']}')}–'
                    '${_clock('${w['end_time']}')}: $taken of $cap taken',
                child: Container(
                  key: ValueKey(
                    'booked-window-${a['area_id']}-${w['start_time']}-${tone.name}',
                  ),
                  padding: const EdgeInsets.symmetric(
                    horizontal: 6,
                    vertical: 2,
                  ),
                  decoration: BoxDecoration(
                    color: c.fill,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    start,
                    style: theme.textTheme.labelSmall?.copyWith(color: c.ink),
                  ),
                ),
              );
            },
          ),
      ],
    );
  }

  /// "14:00:00" → "2pm", "10:30" → "10:30am".
  static String _clock(String t) {
    if (t.length < 5) return t;
    final h = int.tryParse(t.substring(0, 2)) ?? 0;
    final m = t.substring(3, 5);
    final hh = h % 12 == 0 ? 12 : h % 12;
    return '$hh${m == '00' ? '' : ':$m'}${h < 12 ? 'am' : 'pm'}';
  }

  Widget _row(BuildContext context, Map<String, dynamic> a, bool phone) {
    final theme = Theme.of(context);
    final id = '${a['area_id']}';
    final gap = _int(a['gap']);
    final (chipText, chipTone) = _chip(gap);
    final overMode = widget.mode == BookedMode.over;
    final mayOffer = (overMode ? gap < 0 : gap > 0) && widget.canEdit;
    final jobs = _jobs[id];
    final offerOpen = _offerArea == id && _offer != null;

    final name = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('${a['name']}', style: theme.textTheme.titleSmall),
        if (_place(a).isNotEmpty)
          Text(
            _place(a),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
      ],
    );
    final count = Text(
      '${_int(a['booked'])} / ${_int(a['capacity'])}',
      key: ValueKey('booked-count-$id'),
      style: theme.textTheme.bodyMedium,
    );
    final chip = _pill(
      context,
      chipText,
      chipTone,
      key: ValueKey('booked-chip-$id'),
    );
    final offerButton = mayOffer
        ? OutlinedButton.icon(
            key: ValueKey('booked-offer-$id'),
            icon: const Icon(Icons.sms_outlined, size: 18),
            label: Text(
              offerOpen
                  ? 'Close offer'
                  : overMode
                  ? 'Offer a move by text'
                  : 'Offer openings by text',
            ),
            onPressed: () => _openOffer(a),
          )
        : null;

    final Widget head = phone
        ? Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(child: name),
                  chip,
                ],
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(child: _bar(context, a)),
                  const SizedBox(width: 10),
                  count,
                ],
              ),
              const SizedBox(height: 8),
              _windows(context, a),
              if (offerButton != null) ...[
                const SizedBox(height: 8),
                Align(alignment: Alignment.centerLeft, child: offerButton),
              ],
            ],
          )
        : Row(
            children: [
              Expanded(flex: 3, child: name),
              Expanded(
                flex: 4,
                child: Row(
                  children: [
                    Expanded(child: _bar(context, a)),
                    const SizedBox(width: 10),
                    count,
                    const SizedBox(width: 8),
                    chip,
                    const SizedBox(width: 12),
                  ],
                ),
              ),
              Expanded(flex: 4, child: _windows(context, a)),
              SizedBox(
                width: 150,
                child: Align(
                  alignment: Alignment.centerRight,
                  child: offerButton ?? const SizedBox.shrink(),
                ),
              ),
            ],
          );

    return Container(
      key: ValueKey('booked-row-$id'),
      margin: const EdgeInsets.symmetric(vertical: 3),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(10),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            onTap: () => _toggleJobs(a),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              child: head,
            ),
          ),
          if (offerOpen && !phone)
            Container(
              decoration: BoxDecoration(
                border: Border(
                  top: BorderSide(color: EzBiziTheme.edge(context)),
                ),
              ),
              padding: const EdgeInsets.all(12),
              child: _offerPanel(_offer!),
            ),
          if (jobs != null) _jobList(context, jobs),
        ],
      ),
    );
  }

  Widget _jobList(BuildContext context, Future<ApiResponse> request) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    return FutureBuilder<ApiResponse>(
      future: request,
      builder: (context, snap) {
        final response = snap.data;
        if (response == null) {
          return const Padding(
            padding: EdgeInsets.all(12),
            child: Center(child: CircularProgressIndicator()),
          );
        }
        if (!response.ok) {
          return Padding(
            padding: const EdgeInsets.all(12),
            child: Text(response.message, style: TextStyle(color: muted)),
          );
        }
        final jobs = (response.body['data'] as List? ?? const [])
            .cast<Map<String, dynamic>>();
        if (jobs.isEmpty) {
          return Padding(
            padding: const EdgeInsets.all(12),
            child: Text('No jobs on this day.', style: TextStyle(color: muted)),
          );
        }
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final j in jobs)
              Container(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 8),
                decoration: BoxDecoration(
                  border: Border(
                    top: BorderSide(color: EzBiziTheme.edge(context)),
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      [
                        j['time'] == null
                            ? 'No time set'
                            : _clock('${j['time']}'),
                        j['service'],
                        if (j['number'] != null) '#${j['number']}',
                      ].whereType<String>().join('  ·  '),
                      style: theme.textTheme.titleSmall,
                    ),
                    Text(
                      [
                        (j['customer'] as Map?)?['name'],
                        j['status'],
                        (j['customer'] as Map?)?['phone'],
                      ].whereType<String>().join('  ·  '),
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ],
                ),
              ),
          ],
        );
      },
    );
  }

  Widget _offerPanel(Map<String, dynamic> area) => OfferPanel(
    kind: widget.mode == BookedMode.over
        ? OfferKind.overbooked
        : OfferKind.underbooked,
    key: ValueKey('offer-${area['area_id']}-$_date'),
    api: widget.api,
    areaId: '${area['area_id']}',
    date: _date,
  );
}
