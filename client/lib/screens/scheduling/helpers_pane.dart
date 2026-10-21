import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

typedef _Row = Map<String, dynamic>;

/// The three slots, in the order the form offers them.
const _slots = ['full', 'am', 'pm'];

String _slotLabel(String slot) => switch (slot) {
  'am' => 'Morning',
  'pm' => 'Afternoon',
  _ => 'Whole day',
};

/// Three distinct tones, so a month reads by slot at a glance: blue a whole
/// day, green the morning, amber the afternoon (the design's legend).
Tone _slotTone(String slot) => switch (slot) {
  'am' => Tone.ok,
  'pm' => Tone.caution,
  _ => Tone.info,
};

/// The server's rule (SchedulingHelperBooking::collides): a whole day takes
/// both halves.
List<String> _collides(String slot) => slot == 'full' ? _slots : ['full', slot];

String _ymd(DateTime d) =>
    '${d.year}-${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';

String _first(Object? name) => '${name ?? ''}'.trim().split(' ').first;

const _weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const _monthNames = [
  'January', 'February', 'March', 'April', 'May', 'June', //
  'July', 'August', 'September', 'October', 'November', 'December',
];

String _dayTitle(String ymd) {
  final d = DateTime.parse(ymd);
  return '${_weekdays[d.weekday - 1]} ${d.day} '
      '${_monthNames[d.month - 1].substring(0, 3)}';
}

/// Scheduling › Helpers: a branch's helpers booked to technicians for a whole
/// day, a morning or an afternoon, as A1 Tools' helper calendar drew it (A1
/// Leads takeover; UI queue 112, Jose: desktop 1 and phone 4,
/// docs/previews/scheduling-helpers-layouts.html).
///
/// The rules are the server's (SchedulingHelpersController): no double
/// booking, your own bookings unless you can edit Scheduling, and the roster
/// and booking for somebody else need `scheduling.edit`. The screen hides what
/// it cannot use — the technician picker, Change and Remove where the row's
/// `can_change` is false — and shows the server's refusal when it says no.
class HelpersPane extends StatefulWidget {
  const HelpersPane({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
    this.today,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  /// Today, for tests. Null is the clock's.
  final DateTime? today;

  /// Below this width of the tab itself, the phone layout (phone 4).
  static const double wideAbove = 760;

  @override
  State<HelpersPane> createState() => _HelpersPaneState();
}

class _HelpersPaneState extends State<HelpersPane> {
  String? _franchise;
  late DateTime _month;
  List<_Row> _helpers = [];
  List<_Row> _bookings = [];

  /// Who "For" can name: `people` on GET /scheduling/helpers, filled only
  /// for scheduling.edit (empty otherwise). Null until read.
  List<_Row>? _people;

  bool _started = false;
  bool _loading = true;
  String? _error;
  int _request = 0;

  /// The day open in the panel (wide), or listed under the month (narrow).
  String? _day;

  /// The booking the day panel's form is changing, on a wide screen.
  _Row? _editing;

  DateTime get _today => DateUtils.dateOnly(widget.today ?? DateTime.now());

  Me? get _me => widget.session.me;

  bool get _canEdit => _me?.permissions.contains('scheduling.edit') ?? false;

  List<FranchiseSummary> get _places => [
    for (final f in _me?.franchisesInView ?? const <FranchiseSummary>[])
      if (f.isActive) f,
  ];

  List<_Row> get _active => [
    for (final h in _helpers)
      if (h['is_active'] != false) h,
  ];

  @override
  void initState() {
    super.initState();
    _month = DateTime(_today.year, _today.month);
    if (widget.visible) _start();
  }

  @override
  void didUpdateWidget(covariant HelpersPane old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible && !_started) _start();
  }

  Future<void> _start() async {
    _started = true;
    final places = _places;
    _franchise = places.isEmpty ? null : places.first.id;
    await _load();
  }

  Future<void> _load() async {
    final franchise = _franchise;
    if (franchise == null) {
      setState(() => _loading = false);
      return;
    }
    final ticket = ++_request;
    setState(() => _error = null);
    final month = '${_month.year}-${_month.month.toString().padLeft(2, '0')}';
    final answers = await Future.wait([
      widget.api.get('/scheduling/helpers', {'franchise_id': franchise}),
      widget.api.get('/scheduling/helpers/bookings', {
        'franchise_id': franchise,
        'month': month,
      }),
    ]);
    if (!mounted || ticket != _request) return;
    List<_Row> rows(ApiResponse r) => [
      for (final x in (r.body['data'] as List? ?? const []))
        if (x is Map) x.cast<String, dynamic>(),
    ];
    setState(() {
      _loading = false;
      final failed = answers.where((a) => !a.ok).firstOrNull;
      if (failed != null) {
        _error = failed.message;
        return;
      }
      _helpers = rows(answers[0]);
      _people = [
        for (final u in (answers[0].body['people'] as List? ?? const []))
          if (u is Map) u.cast<String, dynamic>(),
      ];
      _bookings = rows(answers[1]);
    });
  }

  void _shift(int months) {
    setState(() {
      _month = DateTime(_month.year, _month.month + months);
      _day = null;
      _editing = null;
    });
    _load();
  }

  void _toToday() {
    setState(() {
      _month = DateTime(_today.year, _today.month);
      _day = null;
      _editing = null;
    });
    _load();
  }

  /// The day the narrow layout lists: the one tapped, else today when it is
  /// in the month shown, else the 1st.
  String get _shownDay =>
      _day ??
      (_today.year == _month.year && _today.month == _month.month
          ? _ymd(_today)
          : _ymd(_month));

  Map<String, List<_Row>> get _byDay {
    final days = <String, List<_Row>>{};
    for (final b in _bookings) {
      days.putIfAbsent('${b['date']}', () => []).add(b);
    }
    for (final list in days.values) {
      list.sort(
        (a, b) =>
            _slots.indexOf('${a['slot']}') - _slots.indexOf('${b['slot']}'),
      );
    }
    return days;
  }

  /// Who the form offers under For: yourself first, then everybody else.
  List<_Row>? get _forOptions {
    final me = _me;
    // Offered only when the server named somebody: it fills `people` for
    // exactly those who may book for others.
    if (me == null || (_people ?? const []).isEmpty) return null;
    return [
      {'id': me.id, 'name': '${me.name} (you)'},
      for (final p in _people ?? const <_Row>[])
        if (p['id'] != me.id) p,
    ];
  }

  Future<void> _remove(_Row booking) async {
    final response = await widget.api.delete(
      '/scheduling/helpers/bookings/${booking['id']}',
    );
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.reason)));
    }
    setState(() => _editing = null);
    _load();
  }

  Widget _form({
    required String date,
    _Row? booking,
    required VoidCallback onDone,
    VoidCallback? onCancel,
  }) => _BookForm(
    key: ValueKey('helpers-form-$date-${booking?['id']}'),
    api: widget.api,
    date: date,
    helpers: _active,
    people: _forOptions,
    me: _me!,
    booking: booking,
    onDone: onDone,
    onCancel: onCancel,
  );

  /// The form in a panel over the page: the phone's Book and Change.
  Future<void> _formPanel({_Row? booking}) async {
    final date = booking == null ? _shownDay : '${booking['date']}';
    await showSidePanel<void>(
      context,
      builder: (panel) => SidePanel(
        title: booking == null ? 'Book a helper' : 'Change booking',
        subtitle: _dayTitle(date),
        child: _active.isEmpty
            ? const Text('No helpers yet. Add them under Helpers.')
            : _form(
                date: date,
                booking: booking,
                onDone: () {
                  Navigator.of(panel).pop();
                  _load();
                },
              ),
      ),
    );
  }

  Future<void> _openRoster() async {
    final franchise = _franchise;
    if (franchise == null) return;
    final name = _places.where((f) => f.id == franchise).firstOrNull?.name;
    await showSidePanel<void>(
      context,
      builder: (_) => SidePanel(
        title: 'Helpers',
        subtitle: name,
        child: _Roster(
          api: widget.api,
          franchise: franchise,
          helpers: _helpers,
          canEdit: _canEdit,
          onChanged: _load,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final wide = box.maxWidth >= HelpersPane.wideAbove;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _bar(context, wide: wide),
            const Divider(height: 1),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _franchise == null
                    ? _sentence(
                        context,
                        'No branch in this view. Pick a company or branch '
                        'that has one.',
                      )
                    : wide
                    ? _wide(context)
                    : _narrow(context),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _sentence(BuildContext context, String text) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ),
    );
  }

  Widget _bar(BuildContext context, {required bool wide}) {
    final theme = Theme.of(context);
    final places = _places;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: wide ? 220 : 170,
            child: SearchPickerField<String>(
              key: const Key('helpers-branch'),
              label: 'Branch',
              isDense: true,
              value: _franchise,
              options: [
                for (final b in branchOptions(
                  places,
                  _me?.companies ?? const [],
                ))
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: (id) {
                if (id == null || id == _franchise) return;
                setState(() {
                  _franchise = id;
                  _helpers = [];
                  _bookings = [];
                  _day = null;
                  _editing = null;
                });
                _load();
              },
            ),
          ),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(
                key: const Key('helpers-prev'),
                tooltip: 'Previous month',
                icon: const Icon(Icons.chevron_left),
                onPressed: () => _shift(-1),
              ),
              Text(
                '${wide ? _monthNames[_month.month - 1] : _monthNames[_month.month - 1].substring(0, 3)} '
                '${_month.year}',
                key: const Key('helpers-month'),
                style: theme.textTheme.titleMedium,
              ),
              IconButton(
                key: const Key('helpers-next'),
                tooltip: 'Next month',
                icon: const Icon(Icons.chevron_right),
                onPressed: () => _shift(1),
              ),
            ],
          ),
          TextButton(onPressed: _toToday, child: const Text('Today')),
          OutlinedButton.icon(
            key: const Key('helpers-roster'),
            onPressed: _franchise == null ? null : _openRoster,
            icon: const Icon(Icons.groups_outlined, size: 18),
            label: Text('Helpers · ${_active.length}'),
          ),
          if (wide)
            FilledButton.icon(
              key: const Key('helpers-book'),
              onPressed: _franchise == null
                  ? null
                  : () => setState(() => _day ??= _shownDay),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Book a helper'),
            ),
        ],
      ),
    );
  }

  Widget _wide(BuildContext context) {
    final day = _day;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _grid(context, compact: false),
                const SizedBox(height: 10),
                _legend(context),
              ],
            ),
          ),
        ),
        if (day != null) ...[
          const VerticalDivider(width: 1),
          SizedBox(
            width: 400,
            child: SidePanel(
              key: const Key('helpers-day'),
              docked: true,
              title: _dayTitle(day),
              onClose: () => setState(() {
                _day = null;
                _editing = null;
              }),
              child: _dayDetail(context, day),
            ),
          ),
        ],
      ],
    );
  }

  Widget _narrow(BuildContext context) {
    final theme = Theme.of(context);
    final day = _shownDay;
    final booked = _byDay[day] ?? const <_Row>[];
    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
      children: [
        _grid(context, compact: true),
        const SizedBox(height: 8),
        _legend(context),
        const SizedBox(height: 16),
        Text(_dayTitle(day), style: theme.textTheme.titleSmall),
        const SizedBox(height: 6),
        if (booked.isEmpty)
          Text(
            'Nobody has a helper booked this day.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        for (final b in booked)
          _bookingRow(context, b, onChange: () => _formPanel(booking: b)),
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton.icon(
            key: const Key('helpers-book'),
            onPressed: () => _formPanel(),
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Book a helper'),
          ),
        ),
      ],
    );
  }

  /// Monday first, blanks before the 1st, every cell the same size.
  Widget _grid(BuildContext context, {required bool compact}) {
    final theme = Theme.of(context);
    final lead = DateTime(_month.year, _month.month).weekday - 1;
    final length = DateUtils.getDaysInMonth(_month.year, _month.month);
    final cells = <int?>[
      for (var i = 0; i < lead; i++) null,
      for (var d = 1; d <= length; d++) d,
    ];
    while (cells.length % 7 != 0) {
      cells.add(null);
    }
    final byDay = _byDay;

    return Column(
      key: const Key('helpers-month-grid'),
      children: [
        Row(
          children: [
            for (final d in _weekdays)
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Text(
                    compact ? d.substring(0, 1) : d,
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
                      height: compact ? 46 : 104,
                      child: cells[i] == null
                          ? const SizedBox.shrink()
                          : _cell(
                              context,
                              DateTime(_month.year, _month.month, cells[i]!),
                              byDay,
                              compact: compact,
                            ),
                    ),
                  ),
                ),
            ],
          ),
      ],
    );
  }

  Widget _cell(
    BuildContext context,
    DateTime date,
    Map<String, List<_Row>> byDay, {
    required bool compact,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final ymd = _ymd(date);
    final booked = byDay[ymd] ?? const <_Row>[];
    final weekend = date.weekday >= DateTime.saturday;
    final isToday = date == _today;
    final selected = ymd == (compact ? _shownDay : _day);

    return InkWell(
      key: ValueKey('helpers-day-$ymd'),
      borderRadius: BorderRadius.circular(8),
      onTap: () => setState(() {
        _day = ymd;
        _editing = null;
      }),
      child: Container(
        padding: EdgeInsets.fromLTRB(compact ? 4 : 6, 4, compact ? 4 : 6, 4),
        decoration: BoxDecoration(
          color: selected
              ? scheme.primary.withValues(alpha: 0.10)
              : scheme.surfaceContainerHighest.withValues(
                  alpha: weekend ? 0.7 : 0.25,
                ),
          border: Border.all(
            color: isToday ? scheme.primary : scheme.outlineVariant,
            width: isToday ? 2 : 1,
          ),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Column(
          crossAxisAlignment: compact
              ? CrossAxisAlignment.center
              : CrossAxisAlignment.stretch,
          children: [
            Text(
              '${date.day}',
              style: theme.textTheme.labelSmall?.copyWith(
                color: weekend ? scheme.onSurfaceVariant : scheme.onSurface,
                fontWeight: isToday ? FontWeight.w800 : null,
              ),
            ),
            const SizedBox(height: 2),
            if (compact)
              Wrap(
                spacing: 2,
                runSpacing: 2,
                children: [
                  for (final b in booked.take(4))
                    Container(
                      key: ValueKey('helpers-dot-${b['id']}'),
                      width: 6,
                      height: 6,
                      decoration: BoxDecoration(
                        color: ToneColours.of(
                          context,
                          _slotTone('${b['slot']}'),
                        ).ink,
                        shape: BoxShape.circle,
                      ),
                    ),
                ],
              )
            else ...[
              for (final b in booked.take(3)) _tag(context, b),
              if (booked.length > 3)
                Text(
                  '+${booked.length - 3} more',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }

  /// "helper → technician", in its slot's colour.
  Widget _tag(BuildContext context, _Row b) {
    final tone = ToneColours.of(context, _slotTone('${b['slot']}'));
    return Container(
      key: ValueKey('helpers-tag-${b['id']}'),
      margin: const EdgeInsets.only(bottom: 2),
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
      decoration: BoxDecoration(
        color: tone.fill,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        '${_first((b['helper'] as Map?)?['name'])} → '
        '${_first((b['technician'] as Map?)?['name'])}',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: Theme.of(
          context,
        ).textTheme.labelSmall?.copyWith(color: tone.ink),
      ),
    );
  }

  Widget _legend(BuildContext context) {
    final theme = Theme.of(context);
    return Wrap(
      spacing: 14,
      runSpacing: 4,
      children: [
        for (final s in _slots)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 10,
                height: 10,
                decoration: BoxDecoration(
                  color: ToneColours.of(context, _slotTone(s)).ink,
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
              const SizedBox(width: 5),
              Text(
                _slotLabel(s),
                style: theme.textTheme.labelSmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
      ],
    );
  }

  /// The day panel: every helper's halves, the bookings, and Book.
  Widget _dayDetail(BuildContext context, String day) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final booked = _byDay[day] ?? const <_Row>[];
    final active = _active;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Helpers', style: theme.textTheme.titleSmall),
        const SizedBox(height: 6),
        if (active.isEmpty)
          Text(
            'No helpers yet. Add them under Helpers above.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        for (final h in active)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${h['name']}', style: theme.textTheme.bodyMedium),
                const SizedBox(height: 3),
                Wrap(
                  spacing: 4,
                  runSpacing: 4,
                  children: [
                    for (final s in _slots)
                      _free(
                        context,
                        h['id'],
                        s,
                        taken: booked.any(
                          (b) =>
                              (b['helper'] as Map?)?['id'] == h['id'] &&
                              _collides(s).contains(b['slot']),
                        ),
                      ),
                  ],
                ),
              ],
            ),
          ),
        const Divider(height: 24),
        Text('Booked', style: theme.textTheme.titleSmall),
        const SizedBox(height: 6),
        if (booked.isEmpty)
          Text(
            'Nobody has a helper booked this day.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        for (final b in booked)
          _bookingRow(context, b, onChange: () => setState(() => _editing = b)),
        if (active.isNotEmpty) ...[
          const Divider(height: 24),
          Text(
            _editing == null ? 'Book' : 'Change booking',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 8),
          _form(
            date: day,
            booking: _editing,
            onDone: () {
              setState(() => _editing = null);
              _load();
            },
            onCancel: _editing == null
                ? null
                : () => setState(() => _editing = null),
          ),
        ],
      ],
    );
  }

  Widget _free(
    BuildContext context,
    Object? helper,
    String slot, {
    required bool taken,
  }) {
    final theme = Theme.of(context);
    final tone = ToneColours.of(
      context,
      taken ? _slotTone(slot) : Tone.neutral,
    );
    return Container(
      key: ValueKey('helpers-free-$helper-$slot'),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: taken ? tone.fill : null,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        '${_slotLabel(slot)} · ${taken ? 'taken' : 'free'}',
        style: theme.textTheme.labelSmall?.copyWith(
          color: taken ? tone.ink : theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  Widget _bookingRow(
    BuildContext context,
    _Row b, {
    required VoidCallback onChange,
  }) {
    final theme = Theme.of(context);
    final slot = '${b['slot']}';
    final tone = ToneColours.of(context, _slotTone(slot));
    final job = '${b['job_number'] ?? ''}'.trim();
    final note = '${b['note'] ?? ''}'.trim();
    return Padding(
      key: ValueKey('helpers-booking-${b['id']}'),
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          Container(
            width: 10,
            height: 10,
            decoration: BoxDecoration(color: tone.ink, shape: BoxShape.circle),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${(b['helper'] as Map?)?['name'] ?? ''} · '
                  '${_slotLabel(slot).toLowerCase()} for '
                  '${(b['technician'] as Map?)?['name'] ?? ''}',
                  style: theme.textTheme.bodyMedium,
                ),
                if (job.isNotEmpty || note.isNotEmpty)
                  Text(
                    [
                      if (job.isNotEmpty) 'Job $job',
                      if (note.isNotEmpty) note,
                    ].join(' · '),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),
          ),
          if (b['can_change'] == true) ...[
            TextButton(
              key: ValueKey('helpers-change-${b['id']}'),
              onPressed: onChange,
              child: const Text('Change'),
            ),
            TextButton(
              key: ValueKey('helpers-remove-${b['id']}'),
              onPressed: () => _remove(b),
              child: const Text('Remove'),
            ),
          ],
        ],
      ),
    );
  }
}

/// Book, or change one booking: helper, slot, who for, job number and note.
class _BookForm extends StatefulWidget {
  const _BookForm({
    super.key,
    required this.api,
    required this.date,
    required this.helpers,
    required this.people,
    required this.me,
    required this.onDone,
    this.booking,
    this.onCancel,
  });

  final ApiClient api;
  final String date;
  final List<_Row> helpers;

  /// Who it can be for. Null without `scheduling.edit`: you book for yourself.
  final List<_Row>? people;
  final Me me;
  final _Row? booking;
  final VoidCallback onDone;
  final VoidCallback? onCancel;

  @override
  State<_BookForm> createState() => _BookFormState();
}

class _BookFormState extends State<_BookForm> {
  String? _helper;
  String _slot = 'full';
  String? _for;
  final _job = TextEditingController();
  final _note = TextEditingController();
  bool _saving = false;
  String? _slotError;
  String? _error;

  @override
  void initState() {
    super.initState();
    final b = widget.booking;
    _helper =
        (b?['helper'] as Map?)?['id'] as String? ??
        (widget.helpers.length == 1
            ? widget.helpers.single['id'] as String?
            : null);
    _slot = b?['slot'] as String? ?? 'full';
    _for = (b?['technician'] as Map?)?['id'] as String? ?? widget.me.id;
    _job.text = '${b?['job_number'] ?? ''}';
    _note.text = '${b?['note'] ?? ''}';
  }

  @override
  void dispose() {
    _job.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_helper == null) {
      setState(() => _error = 'Pick a helper.');
      return;
    }
    final booking = widget.booking;
    final job = _job.text.trim();
    final note = _note.text.trim();
    final body = <String, dynamic>{
      'helper_id': _helper,
      'slot': _slot,
      if (booking == null) 'date': widget.date,
      // For somebody else only with the right; changing a booking an editor
      // may move to anybody, yourself included.
      if (widget.people != null && (booking != null || _for != widget.me.id))
        'technician_id': _for,
      if (booking != null) 'job_number': job.isEmpty ? null : job,
      if (booking == null && job.isNotEmpty) 'job_number': job,
      if (booking != null) 'note': note.isEmpty ? null : note,
      if (booking == null && note.isNotEmpty) 'note': note,
    };
    setState(() {
      _saving = true;
      _slotError = null;
      _error = null;
    });
    final response = booking == null
        ? await widget.api.post('/scheduling/helpers/bookings', body)
        : await widget.api.patch(
            '/scheduling/helpers/bookings/${booking['id']}',
            body,
          );
    if (!mounted) return;
    if (response.ok) {
      setState(() => _saving = false);
      widget.onDone();
      return;
    }
    final slot = (response.body['errors'] as Map?)?['slot'];
    setState(() {
      _saving = false;
      if (slot is List && slot.isNotEmpty) {
        _slotError = '${slot.first}';
      } else {
        _error = response.reason;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final people = widget.people;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SearchPickerField<String>(
          key: const Key('helpers-form-helper'),
          label: 'Helper',
          hint: 'Choose',
          value: _helper,
          options: [
            for (final h in widget.helpers)
              SearchOption(
                value: '${h['id']}',
                label: '${h['name']}',
                detail: (h['notes'] as String?)?.trim().isEmpty ?? true
                    ? null
                    : h['notes'] as String,
              ),
          ],
          onChanged: (id) => setState(() {
            _helper = id;
            _slotError = null;
          }),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final s in _slots)
              ChoiceChip(
                key: ValueKey('helpers-slot-$s'),
                label: Text(_slotLabel(s)),
                selected: _slot == s,
                onSelected: (_) => setState(() {
                  _slot = s;
                  _slotError = null;
                }),
              ),
          ],
        ),
        if (_slotError != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              _slotError!,
              key: const Key('helpers-slot-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        const SizedBox(height: 12),
        if (people != null)
          SearchPickerField<String>(
            key: const Key('helpers-form-for'),
            label: 'For',
            value: _for,
            options: [
              for (final p in people)
                SearchOption(value: '${p['id']}', label: '${p['name']}'),
            ],
            onChanged: (id) => setState(() => _for = id ?? widget.me.id),
          )
        else
          Text(
            'For you (${widget.me.name})',
            key: const Key('helpers-form-for-me'),
            style: theme.textTheme.bodyMedium,
          ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('helpers-form-job'),
          controller: _job,
          decoration: const InputDecoration(
            labelText: 'Job number (optional)',
            isDense: true,
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('helpers-form-note'),
          controller: _note,
          decoration: const InputDecoration(
            labelText: 'Note (optional)',
            isDense: true,
          ),
        ),
        if (_error != null) ...[const SizedBox(height: 10), ErrorNote(_error!)],
        const SizedBox(height: 14),
        Wrap(
          alignment: WrapAlignment.end,
          spacing: 8,
          children: [
            if (widget.onCancel != null)
              TextButton(
                onPressed: widget.onCancel,
                child: const Text('Cancel'),
              ),
            FilledButton(
              key: const Key('helpers-form-submit'),
              onPressed: _saving ? null : _submit,
              child: Text(widget.booking == null ? 'Book' : 'Save'),
            ),
          ],
        ),
      ],
    );
  }
}

/// The branch's helpers: add one, rename one or change its notes, switch one
/// off or back on. Changing it takes `scheduling.edit`.
class _Roster extends StatefulWidget {
  const _Roster({
    required this.api,
    required this.franchise,
    required this.helpers,
    required this.canEdit,
    required this.onChanged,
  });

  final ApiClient api;
  final String franchise;
  final List<_Row> helpers;
  final bool canEdit;
  final VoidCallback onChanged;

  @override
  State<_Roster> createState() => _RosterState();
}

class _RosterState extends State<_Roster> {
  late List<_Row> _rows = List.of(widget.helpers);
  final _name = TextEditingController();
  final _notes = TextEditingController();
  final _editName = TextEditingController();
  final _editNotes = TextEditingController();
  String? _editing;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _notes.dispose();
    _editName.dispose();
    _editNotes.dispose();
    super.dispose();
  }

  /// One write, then the list again; the screen behind reloads too.
  Future<void> _write(Future<ApiResponse> Function() call) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await call();
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }
    final list = await widget.api.get('/scheduling/helpers', {
      'franchise_id': widget.franchise,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (list.ok) {
        _rows = [
          for (final x in (list.body['data'] as List? ?? const []))
            if (x is Map) x.cast<String, dynamic>(),
        ];
      }
    });
    widget.onChanged();
  }

  Future<void> _add() async {
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _error = 'Give the helper a name.');
      return;
    }
    final notes = _notes.text.trim();
    await _write(
      () => widget.api.post('/scheduling/helpers', {
        'franchise_id': widget.franchise,
        'name': name,
        if (notes.isNotEmpty) 'notes': notes,
      }),
    );
    if (_error == null) {
      _name.clear();
      _notes.clear();
    }
  }

  Future<void> _save(_Row row) async {
    final name = _editName.text.trim();
    if (name.isEmpty) {
      setState(() => _error = 'Give the helper a name.');
      return;
    }
    final notes = _editNotes.text.trim();
    await _write(
      () => widget.api.patch('/scheduling/helpers/${row['id']}', {
        'name': name,
        'notes': notes.isEmpty ? null : notes,
      }),
    );
    if (_error == null && mounted) setState(() => _editing = null);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!widget.canEdit)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              'Changing this list takes the right to edit Scheduling.',
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ),
        if (_rows.isEmpty)
          Text(
            'No helpers yet.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        for (final row in _rows)
          if (_editing == row['id'])
            _editor(row)
          else
            Padding(
              key: ValueKey('helpers-roster-row-${row['id']}'),
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          row['is_active'] == false
                              ? '${row['name']} · off'
                              : '${row['name']}',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: row['is_active'] == false ? muted : null,
                          ),
                        ),
                        if ('${row['notes'] ?? ''}'.trim().isNotEmpty)
                          Text(
                            '${row['notes']}',
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: muted,
                            ),
                          ),
                      ],
                    ),
                  ),
                  if (widget.canEdit) ...[
                    IconButton(
                      key: ValueKey('helpers-roster-edit-${row['id']}'),
                      tooltip: 'Rename or change notes',
                      icon: const Icon(Icons.edit_outlined, size: 18),
                      onPressed: _busy
                          ? null
                          : () => setState(() {
                              _editing = row['id'] as String?;
                              _editName.text = '${row['name'] ?? ''}';
                              _editNotes.text = '${row['notes'] ?? ''}';
                            }),
                    ),
                    Switch(
                      key: ValueKey('helpers-roster-active-${row['id']}'),
                      value: row['is_active'] != false,
                      onChanged: _busy
                          ? null
                          : (on) => _write(
                              () => widget.api.patch(
                                '/scheduling/helpers/${row['id']}',
                                {'is_active': on},
                              ),
                            ),
                    ),
                  ],
                ],
              ),
            ),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
        if (widget.canEdit) ...[
          const Divider(height: 28),
          Text('Add a helper', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          TextField(
            key: const Key('helpers-roster-name'),
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name', isDense: true),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const Key('helpers-roster-notes'),
            controller: _notes,
            decoration: const InputDecoration(
              labelText: 'Notes (optional)',
              isDense: true,
            ),
          ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              key: const Key('helpers-roster-add'),
              onPressed: _busy ? null : _add,
              child: const Text('Add'),
            ),
          ),
        ],
      ],
    );
  }

  Widget _editor(_Row row) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('helpers-roster-edit-name'),
          controller: _editName,
          decoration: const InputDecoration(labelText: 'Name', isDense: true),
        ),
        const SizedBox(height: 8),
        TextField(
          key: const Key('helpers-roster-edit-notes'),
          controller: _editNotes,
          decoration: const InputDecoration(labelText: 'Notes', isDense: true),
        ),
        const SizedBox(height: 8),
        Wrap(
          alignment: WrapAlignment.end,
          spacing: 8,
          children: [
            TextButton(
              onPressed: () => setState(() => _editing = null),
              child: const Text('Cancel'),
            ),
            FilledButton(
              key: const Key('helpers-roster-save'),
              onPressed: _busy ? null : () => _save(row),
              child: const Text('Save'),
            ),
          ],
        ),
      ],
    ),
  );
}
