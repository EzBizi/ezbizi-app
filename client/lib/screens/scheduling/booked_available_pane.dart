import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/tab_bar_like.dart';
import '../../core/session.dart';
import 'rescheduling_pane.dart';

/// Scheduling › Re-Scheduling: Rescheduling Availability (Booked vs available,
/// with the earlier-opening offers) and AI Rescheduling Suggestion (Jose,
/// 2026-10-26: moved here from Overbooking, which keeps overbooked days only).
///
/// design: docs/previews/scheduling-booked-vs-available-layouts.html — #1, phone #4
/// (UI queue 147). The availability endpoints take `scheduling.overbooking.view`
/// or `scheduling.rescheduling.view` (a3f200840); [showAvailability] is false
/// only with neither, and the sub-tab is not drawn. Offers still need
/// `scheduling.edit` ([canEdit]).
class ReschedulingTab extends StatefulWidget {
  const ReschedulingTab({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
    required this.canEdit,
    required this.showAvailability,
    this.linkDate,
    this.linkSerial = 0,
  });

  final ApiClient api;
  final Session session;
  final bool visible;
  final bool canEdit;
  final bool showAvailability;

  /// A deep link's day (`/scheduling/overbooking?date=`); a new [linkSerial]
  /// opens Rescheduling Availability on it.
  final String? linkDate;
  final int linkSerial;

  @override
  State<ReschedulingTab> createState() => _ReschedulingTabState();
}

class _ReschedulingTabState extends State<ReschedulingTab> {
  int _tab = 0;
  final Set<int> _shown = {0};

  @override
  void initState() {
    super.initState();
    if (widget.linkSerial > 0) _select(0);
  }

  @override
  void didUpdateWidget(covariant ReschedulingTab old) {
    super.didUpdateWidget(old);
    if (widget.linkSerial != old.linkSerial) _select(0);
  }

  void _select(int i) {
    _tab = i;
    _shown.add(i);
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.showAvailability) {
      return ReschedulingPane(
        api: widget.api,
        session: widget.session,
        visible: widget.visible,
      );
    }
    final panes = <Widget Function()>[
      () => BookedAvailablePane(
        api: widget.api,
        visible: widget.visible && _tab == 0,
        canEdit: widget.canEdit,
        date: widget.linkDate,
        dateSerial: widget.linkSerial,
      ),
      () => ReschedulingPane(
        api: widget.api,
        session: widget.session,
        visible: widget.visible && _tab == 1,
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          key: const ValueKey('tour-scheduling-rescheduling-segments'),
          level: TabLevel.secondary,
          labels: const [
            ('Rescheduling Availability', Icons.stacked_bar_chart),
            ('AI rescheduling suggestions', Icons.auto_awesome_outlined),
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
class BookedAvailablePane extends StatefulWidget {
  const BookedAvailablePane({
    super.key,
    required this.api,
    required this.visible,
    required this.canEdit,
    this.date,
    this.dateSerial = 0,
  });

  final ApiClient api;
  final bool visible;

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
  String? _offerArea;
  _Offer? _offer;

  @override
  void initState() {
    super.initState();
    _date =
        widget.date ??
        formatCalendarDay(DateTime.now().add(const Duration(days: 1)));
    _load();
  }

  @override
  void didUpdateWidget(covariant BookedAvailablePane old) {
    super.didUpdateWidget(old);
    if (widget.dateSerial != old.dateSerial && widget.date != null) {
      _date = widget.date!;
      _load();
    } else if (widget.visible && !old.visible) {
      _load();
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
      _offerArea = null;
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
    final rows = [
      for (final a in _areas)
        if (q.isEmpty || '${a['name']} ${_place(a)}'.toLowerCase().contains(q))
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

  void _openOffer(Map<String, dynamic> a) {
    final id = '${a['area_id']}';
    if (_offerArea == id) {
      setState(() {
        _offerArea = null;
        _offer = null;
      });
      return;
    }
    final offer = _Offer(area: a);
    setState(() {
      _offerArea = id;
      _offer = offer;
    });
    widget.api
        .post('/scheduling/overbooking/offers/preview', {
          'area_id': id,
          'date': _date,
        })
        .then((response) {
          if (!mounted || _offer != offer) return;
          setState(() {
            offer.loading = false;
            if (!response.ok) {
              offer.error = response.message;
              return;
            }
            final d = response.body['data'] as Map<String, dynamic>? ?? {};
            offer.open = (d['open'] as List? ?? const [])
                .cast<Map<String, dynamic>>();
            offer.candidates = (d['candidates'] as List? ?? const [])
                .cast<Map<String, dynamic>>();
            offer.maxSends = _int(d['max_sends']);
            offer.message = d['message'] as String? ?? '';
            offer.ticked = {
              for (final c in offer.candidates.take(offer.maxSends))
                '${c['job_id']}',
            };
          });
        });
  }

  Future<void> _send(_Offer offer) async {
    setState(() => offer.sending = true);
    final response = await widget.api.post(
      '/scheduling/overbooking/offers/send',
      {
        'area_id': '${offer.area['area_id']}',
        'date': _date,
        'job_ids': [
          for (final c in offer.candidates)
            if (offer.ticked.contains('${c['job_id']}')) '${c['job_id']}',
        ],
      },
    );
    if (!mounted || _offer != offer) return;
    setState(() {
      offer.sending = false;
      if (response.statusCode == 422) {
        // Outside the send hours, or no spot left: the server's sentence.
        offer.refused = response.message;
        return;
      }
      if (!response.ok) {
        offer.error = response.message;
        return;
      }
      final d = response.body['data'] as Map<String, dynamic>? ?? {};
      offer.sent = (d['sent'] as List? ?? const []).length;
      offer.failed = (d['failed'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
    });
  }

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
                      _offerArea = null;
                      _offer = null;
                    }),
                  ),
                  Expanded(
                    child: Text(
                      'Offer openings · ${offer.area['name']}',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                ],
              ),
              _offerPanel(context, offer),
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
    final over = rows.where((a) => _int(a['gap']) < 0).length;
    final openSpots = rows.fold<int>(0, (n, a) {
      final g = _int(a['gap']);
      return g > 0 ? n + g : n;
    });
    final full = rows.where((a) => _int(a['gap']) == 0).length;
    final today = _today ?? formatCalendarDay(DateTime.now());
    final tomorrow = _shift(today, 1);

    return ListView(
      padding: EdgeInsets.all(phone ? 12 : 20),
      children: [
        Text(
          'Each area\'s day, booked against what it can take. Over capacity '
          'comes first. Where there are open spots, offer them by text to '
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
    final mayOffer = gap > 0 && widget.canEdit;
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
            label: Text(offerOpen ? 'Close offer' : 'Offer openings'),
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
              child: _offerPanel(context, _offer!),
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

  Widget _offerPanel(BuildContext context, _Offer offer) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    if (offer.loading) {
      return const Padding(
        padding: EdgeInsets.all(12),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (offer.error != null && offer.candidates.isEmpty) {
      return Text(
        offer.error!,
        style: TextStyle(color: theme.colorScheme.error),
      );
    }
    final label = theme.textTheme.labelSmall?.copyWith(
      letterSpacing: 0.8,
      color: muted,
    );
    final count = offer.ticked.length;
    final done = offer.sent != null;

    return Column(
      key: const ValueKey('booked-offer-panel'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('OPEN WINDOWS', style: label),
        const SizedBox(height: 4),
        if (offer.open.isEmpty)
          Text('No window has a spot left.', style: TextStyle(color: muted))
        else
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final w in offer.open)
                _pill(
                  context,
                  '${_clock('${w['start_time']}')}–${_clock('${w['end_time']}')}'
                  ' · ${_int(w['remaining'])} left',
                  Tone.ok,
                ),
            ],
          ),
        const SizedBox(height: 12),
        Text('OFFER TO (UP TO ${offer.maxSends}) · BOOKED LATER', style: label),
        if (offer.candidates.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Text(
              'Nobody in this area is booked later.',
              style: TextStyle(color: muted),
            ),
          ),
        for (final c in offer.candidates)
          Builder(
            builder: (context) {
              final jobId = '${c['job_id']}';
              final on = offer.ticked.contains(jobId);
              final full = !on && count >= offer.maxSends;
              final when = '${c['scheduled_for'] ?? ''}';
              return CheckboxListTile(
                key: ValueKey('booked-candidate-$jobId'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: on,
                onChanged: done || full
                    ? null
                    : (v) => setState(() {
                        if (v == true) {
                          offer.ticked.add(jobId);
                        } else {
                          offer.ticked.remove(jobId);
                        }
                      }),
                title: Text('${c['customer_name'] ?? 'Customer'}'),
                subtitle: Text(
                  [
                    if (when.length >= 10)
                      'booked ${_day(when.substring(0, 10))}',
                    c['phone'],
                  ].whereType<String>().join(' · '),
                ),
              );
            },
          ),
        const SizedBox(height: 8),
        Text('THEY\'LL GET', style: label),
        const SizedBox(height: 4),
        Container(
          key: const ValueKey('booked-offer-message'),
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Text(offer.message),
        ),
        const SizedBox(height: 12),
        if (offer.refused != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              offer.refused!,
              key: const ValueKey('booked-offer-refused'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        if (offer.error != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              offer.error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        if (done) ...[
          Text(
            offer.sent == 1 ? 'Sent 1 text.' : 'Sent ${offer.sent} texts.',
            key: const ValueKey('booked-offer-sent'),
            style: theme.textTheme.titleSmall,
          ),
          for (final f in offer.failed)
            Text(
              '${_nameOf(offer, '${f['job_id']}')}: ${f['reason']}',
              style: TextStyle(color: theme.colorScheme.error),
            ),
          const SizedBox(height: 4),
          Text(
            'Replies land in Leads as "Reschedule".',
            key: const ValueKey('booked-offer-replies'),
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        ] else
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.icon(
              key: const ValueKey('booked-offer-send'),
              icon: const Icon(Icons.send, size: 18),
              label: Text('Send to $count'),
              onPressed: count == 0 || offer.sending || offer.refused != null
                  ? null
                  : () => _send(offer),
            ),
          ),
      ],
    );
  }

  static String _nameOf(_Offer offer, String jobId) =>
      offer.candidates
              .where((c) => '${c['job_id']}' == jobId)
              .firstOrNull?['customer_name']
          as String? ??
      'A customer';
}

class _Offer {
  _Offer({required this.area});

  final Map<String, dynamic> area;
  bool loading = true;
  bool sending = false;
  String? error;

  /// The server's 422 sentence (outside the send hours): Send stays off.
  String? refused;
  List<Map<String, dynamic>> open = const [];
  List<Map<String, dynamic>> candidates = const [];
  int maxSends = 0;
  String message = '';
  Set<String> ticked = {};
  int? sent;
  List<Map<String, dynamic>> failed = const [];
}
