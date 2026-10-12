import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/ai_mark.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';
import '../phone/recording_playback_bar.dart';
import '../phone/recording_player.dart';

/// One day of A-1's calls against the jobs they became (TEMPORARY).
///
/// This pane goes when EzBizi's own Phone and Jobs modules answer the question.
/// It reads RingCentral and Workiz and nothing in EzBizi, which is why it has
/// no view switcher and no place picker — there is nothing tenant-shaped in it.
///
/// Three tabs since UI queue 60 (Jose: "go ahead as u recommend", desktop 1
/// and phone 4): Overview answers "how did we do", People is the table, Calls
/// is every call. Each question got a page of its own instead of panels
/// opening into the middle of the table.
///
/// ── The screen's job is to stop a number being read wrongly ─────
///
/// Three of the sentences here exist only for that:
///
/// 1. "Rang" and "answered" are different columns, because a queue call rings
///    three or four people until one picks up. The rang column is deliberately
///    NOT totalled — adding it would produce a number larger than the day's
///    calls that looks like the day's calls.
/// 2. The Workiz accounts covered are named, in Sources, and one that did not
///    answer is an amber line above the tabs. The accounts are disjoint and the
///    same people book into all of them, so a missing account makes a person's
///    job count wrong rather than merely omitting a region.
/// 3. A person known to only one of the two systems is marked. RingCentral's
///    "Adeline Pierre Louis-Aghaji" is Workiz's "Adeline Pierrelouis" and
///    nothing joins them; a near-match rule would credit one person's work to
///    another, which is worse than a visible duplicate.
class ExperimentalCallReport extends StatefulWidget {
  const ExperimentalCallReport({
    super.key,
    required this.api,
    this.formats = DisplayFormats.fallback,
    this.now = DateTime.now,
    this.canEdit = true,
    this.openCall,
  });

  /// A call to open on arrival (from a missed-call notification): Calls,
  /// Missed, with that call's details showing.
  final String? openCall;

  final ApiClient api;

  /// Whether this person may change what the report records (Convert to
  /// booked, a person's New/Existing tag): the server asks reports.build for
  /// those, while reading the report needs only the Performance tab.
  final bool canEdit;

  /// Which way round a date is written: "Oct 5" or "5 Oct".
  final DisplayFormats formats;

  /// The clock, so a test can say what today is.
  final DateTime Function() now;

  static const double tableAbove = 900;

  @override
  State<ExperimentalCallReport> createState() => _ExperimentalCallReportState();
}

class _ExperimentalCallReportState extends State<ExperimentalCallReport> {
  /// The dates the report covers (UI queue 57). Opens on today, as it did.
  late DateSpan _span = DateSpan.single(widget.now());

  /// The picker is open: under the bar on a wide screen, as its own page on a
  /// phone (Jose: "as u recommend", desktop 1 and phone 4).
  bool _picking = false;

  Map<String, dynamic>? _report;
  String? _error;
  bool _loading = true;

  /// The tab showing: 'overview', 'people' or 'calls'. Kept as a key, so the
  /// strip and the page drawn under it cannot disagree.
  String _view = 'overview';

  /// What the Calls tab is narrowed to by result: 'all', 'booked',
  /// 'not_booked' or 'missed'. Who called is the bar's other half,
  /// [_clientFilter] (UI queue 71). A figure opens the tab already narrowed.
  String _result = 'all';

  /// Which column the table is ordered by, and which way.
  ///
  /// Defaults to answered, descending: the question somebody opens this on a
  /// Monday morning is who took the calls, and alphabetical by first name
  /// answers nothing. Null would mean the server's order, which is
  /// alphabetical - available by tapping Person.
  String _sortBy = 'answered';
  bool _ascending = false;

  /// The person whose calls are open, by name. One at a time: several open
  /// rows turn a table into a wall and the comparison the table exists for is
  /// lost.
  String? _expanded;

  /// Built once and shared by every row's transport, so opening a second
  /// person stops the first rather than playing two calls at once.
  final RecordingPlayer _player = createRecordingPlayer();

  /// Why a recording would not play, kept per call id.
  final Map<String, String> _recordingErrors = {};

  /// Why a missed call reached nobody, when the list is narrowed to one.
  String? _nobodyFilter;

  /// Inbound or outbound, inside Missed (Jose, 2026-10-12: "on missed calls
  /// i want also to differentiate between inbound and outbound calls").
  String? _directionFilter;

  /// Calls whose details are open (Bar, via Jose, 2026-10-12: a missed
  /// call's row opens to its caller, the line it rang, and a voicemail).
  final Set<String> _openDetails = {};

  /// 'new' or 'existing' client, or null for both: the second half of the
  /// Calls bar.
  String? _clientFilter;

  /// The call whose recording is open under its row. One at a time: the
  /// player is shared, so a second would only take the sound from the first.
  String? _listeningTo;

  /// The people table narrowed to one hand-set tag, or everybody.
  String? _tagFilter;

  /// Calls being marked as booked right now, so a double tap sends once.
  final Set<String> _converting = {};

  /// Calls whose transcript is unfolded.
  final Set<String> _openTranscripts = {};

  /// Whose AI labels the counts show, and the label the list is narrowed to
  /// (Jose, 2026-10-06: both Claude and Grok, to compare).
  String _labeller = 'claude';
  String? _labelFilter;

  static const _existingLabels = {
    'complaint': 'Complaint',
    'reschedule': 'Reschedule',
    'eta': 'ETA',
    'billing': 'Billing',
    'other': 'Other',
  };

  static const _notBookedLabels = {
    'price': 'Price',
    'availability': 'Availability',
    'other': 'Other',
    'booked': 'Booked (per AI)',
  };

  static const _newQueue = 'A1 - New Client';
  static const _existingQueue = 'A1 - Existing Client';

  @override
  void dispose() {
    _player.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    if (widget.openCall case final call?) {
      _view = 'calls';
      _result = 'missed';
      _openDetails.add(call);
    }
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    // Any range, or one date as from = to (the server since Reports'
    // 2660648c). Weeks, months and the rest are all just dates by now.
    final response = await widget.api.get('/reports/experimental/calls', {
      'from': _span.fromKey,
      'to': _span.toKey,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _report = response.body;
      } else {
        _error = response.message;
      }
    });
  }

  /// Reloads, and hands the fresh report to the Sources panel: the panel is a
  /// route of its own, so this page rebuilding does not redraw it.
  Future<Map<String, dynamic>?> _reloaded() async {
    await _load();
    return _report;
  }

  void _openSources() {
    final report = _report;
    if (report == null) return;

    showSidePanel<void>(
      context,
      builder: (context) => SidePanel(
        title: 'Sources',
        subtitle: 'Where the jobs in this report come from',
        child: _Sources(api: widget.api, report: report, reload: _reloaded),
      ),
    );
  }

  /// The Calls tab narrowed to [filter] and nothing else, so the list holds
  /// exactly what the figure or the card that opened it counted.
  void _openCalls(String filter) => setState(() {
    _view = 'calls';
    final client = filter == 'new' || filter == 'existing';
    _result = client ? 'all' : filter;
    _clientFilter = client ? filter : null;
    _labelFilter = null;
    _nobodyFilter = null;
  });

  DateTime get _today => DateSpan.single(widget.now()).from;

  /// One length back or forward: a day, a week, ten days — or a whole
  /// calendar month when a month is what is showing, since months are not
  /// all one length. Never past today.
  void _move(int step) {
    final span = _span;
    final today = _today;
    final monthStart = span.from.day == 1 && span.from.month == span.to.month;
    final wholeMonth =
        monthStart &&
        (span.to == DateTime(span.to.year, span.to.month + 1, 0) ||
            span.to == today);

    var next = wholeMonth
        ? DateSpan(
            DateTime(span.from.year, span.from.month + step, 1),
            DateTime(span.from.year, span.from.month + step + 1, 0),
          )
        : span.shift(step);
    if (next.from.isAfter(today)) return;
    if (next.to.isAfter(today)) next = DateSpan(next.from, today);

    setState(() {
      _span = next;
      _picking = false;
    });
    _load();
  }

  void _apply(DateSpan span) {
    setState(() {
      _span = span;
      _picking = false;
    });
    _load();
  }

  /// Marks a not-booked call as booked by hand, then reloads so the figures,
  /// the list and the history all agree.
  Future<void> _convert(Map<String, dynamic> call) async {
    final id = '${call['id']}';
    if (!_converting.add(id)) return;
    setState(() {});

    final response = await widget.api.post(
      '/reports/experimental/calls/$id/convert',
      {'started_at': call['started_at']},
    );

    if (!mounted) return;
    _converting.remove(id);

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    _load();
  }

  /// A dispatcher's New / Existing tag, set by hand; null clears it.
  Future<void> _setTag(String person, String? tag) async {
    final response = await widget.api.put('/reports/experimental/people/tag', {
      'person': person,
      'tag': tag,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    _load();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 720;

        // On a phone the dates are a page of their own, in place of the
        // report, with the way back at the top (phone option 4).
        if (phone && _picking) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  IconButton(
                    tooltip: 'Back to the report',
                    onPressed: () => setState(() => _picking = false),
                    icon: const Icon(Icons.arrow_back),
                  ),
                  Text(
                    'Dates',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
              const Divider(height: 1),
              Expanded(
                child: DateRangePanel(
                  initial: _span,
                  today: _today,
                  formats: widget.formats,
                  narrow: true,
                  onApply: _apply,
                  onCancel: () => setState(() => _picking = false),
                ),
              ),
            ],
          );
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _dayBar(context, phone: phone),
            if (_picking) ...[
              const SizedBox(height: 8),
              DateRangePanel(
                initial: _span,
                today: _today,
                formats: widget.formats,
                onApply: _apply,
                onCancel: () => setState(() => _picking = false),
              ),
            ],
            const SizedBox(height: 12),
            Expanded(child: _body(context, phone: phone)),
          ],
        );
      },
    );
  }

  /// The dates, in words, on the one button that changes them; the arrows
  /// either side step by the same length. Replaces Day / Week / Month, which
  /// left "what week is it?" to the reader (Jose, 2026-10-06).
  Widget _dayBar(BuildContext context, {required bool phone}) {
    final today = _today;

    return Row(
      children: [
        Expanded(
          child: Align(
            alignment: Alignment.centerLeft,
            child: DateRangeBar(
              key: const ValueKey('call-report-period'),
              span: _span,
              today: today,
              formats: widget.formats,
              open: _picking,
              expand: phone,
              onToggle: _loading
                  ? null
                  : () => setState(() => _picking = !_picking),
              onPrevious: _loading ? null : () => _move(-1),
              // Forward is disabled on today rather than hidden: a control
              // that vanishes reads as a bug, one that is greyed reads as a
              // boundary.
              onNext: (_loading || !_span.to.isBefore(today))
                  ? null
                  : () => _move(1),
            ),
          ),
        ),
        if (!phone) _sourcesButton(),
        IconButton(
          onPressed: _loading ? null : _load,
          icon: const Icon(Icons.refresh),
          tooltip: 'Reload',
        ),
      ],
    );
  }

  /// The Workiz accounts behind the jobs, counted on the button that opens
  /// them. Beside the dates; on a phone, where the dates need all of their
  /// row and the tabs all of theirs, at the foot of the Overview.
  Widget _sourcesButton() {
    final count = (_report?['workiz_accounts'] as List?)?.length;

    return TextButton.icon(
      key: const Key('call-report-sources'),
      onPressed: _report == null ? null : _openSources,
      icon: const Icon(Icons.dns_outlined, size: 18),
      label: Text(
        count == null
            ? 'Sources'
            : 'Sources · $count Workiz ${count == 1 ? 'account' : 'accounts'}',
      ),
    );
  }

  Widget _body(BuildContext context, {required bool phone}) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Align(alignment: Alignment.topCenter, child: ErrorNote(_error!));
    }

    final report = _report!;

    if (report['connected'] != true) {
      return _notConnected(
        context,
        report['missing']?.toString() ?? 'A vendor',
      );
    }

    final people = (report['people'] as List? ?? const [])
        .map((row) => (row as Map).cast<String, dynamic>())
        .toList();

    final totals = (report['totals'] as Map? ?? const {})
        .cast<String, dynamic>();

    final calls = (report['calls'] as List? ?? const [])
        .map((row) => (row as Map).cast<String, dynamic>())
        .toList();

    final problem = _sourcesProblem(context, report);
    // The counts only where there is room for all three tabs beside them.
    final views = [
      ('overview', 'Overview', Icons.dashboard_outlined),
      (
        'people',
        phone ? 'People' : 'People · ${people.length}',
        Icons.people_outline,
      ),
      (
        'calls',
        phone ? 'Calls' : 'Calls · ${calls.length}',
        Icons.call_outlined,
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ?problem,
        TabBarLike(
          level: TabLevel.secondary,
          key: const Key('call-report-tabs'),
          labels: [for (final view in views) (view.$2, view.$3)],
          selected: views.indexWhere((view) => view.$1 == _view),
          onSelected: (i) => setState(() => _view = views[i].$1),
        ),
        Expanded(
          child: switch (_view) {
            'people' => _peopleTab(context, people, calls),
            'calls' => _callsTab(
              context,
              calls,
              report['conversions'],
              phone: phone,
            ),
            _ => _overview(context, totals, calls, phone: phone),
          },
        ),
      ],
    );
  }

  /// A problem with the Workiz accounts, as one amber line above the tabs.
  /// Which accounts there are, and changing them, is behind Sources; only
  /// what changes how the numbers read is said on the page.
  Widget? _sourcesProblem(BuildContext context, Map<String, dynamic> report) {
    final said = _workizProblem(report);
    if (said == null) return null;

    final theme = Theme.of(context);
    final caution = ToneColours.of(context, Tone.caution);

    return Container(
      key: const Key('call-report-problem'),
      margin: const EdgeInsets.only(bottom: 4),
      padding: const EdgeInsets.fromLTRB(12, 4, 4, 4),
      decoration: BoxDecoration(
        color: caution.fill,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Row(
        children: [
          Icon(Icons.warning_amber_rounded, size: 18, color: caution.ink),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              said,
              style: theme.textTheme.bodySmall?.copyWith(color: caution.ink),
            ),
          ),
          TextButton(onPressed: _openSources, child: const Text('Sources')),
        ],
      ),
    );
  }

  /// An absent credential is a STATE. An empty report and a missing connection
  /// look identical on a screen and mean opposite things.
  Widget _notConnected(BuildContext context, String vendor) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              '$vendor is not connected.',
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            Text(
              // Connected on the server, not from a screen, so the way in is
              // a person (UI review 2026-10-08: "no way to connect").
              'This report reads your calls from $vendor, and $vendor is not '
              'connected for your company yet. EzBizi support connects it: '
              'ask them, and your calls appear here.',
              style: theme.textTheme.bodyMedium,
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }

  Widget _nothing(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Text(
        'No calls and no jobs in these dates.',
        style: Theme.of(context).textTheme.bodyMedium,
        textAlign: TextAlign.center,
      ),
    );
  }

  // ── Overview ───────────────────────────────────────────────────

  /// How the dates went: the seven figures, then the two lists that need
  /// somebody — side by side when there is room, Not booked first because it
  /// is the one somebody acts on.
  Widget _overview(
    BuildContext context,
    Map<String, dynamic> totals,
    List<Map<String, dynamic>> calls, {
    required bool phone,
  }) {
    return LayoutBuilder(
      builder: (context, box) {
        final across = box.maxWidth >= 1100 ? 7 : (box.maxWidth >= 720 ? 4 : 2);
        final notBooked = _notBookedCard(context, calls);
        final missed = _missedCard(context, calls);

        return ListView(
          padding: const EdgeInsets.only(top: 4, bottom: 24),
          children: [
            _figures(context, totals, across),
            const SizedBox(height: 16),
            if (box.maxWidth >= ExperimentalCallReport.tableAbove)
              IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(child: notBooked),
                    const SizedBox(width: 12),
                    Expanded(child: missed),
                  ],
                ),
              )
            else ...[
              notBooked,
              const SizedBox(height: 12),
              missed,
            ],
            if (phone) ...[
              const SizedBox(height: 12),
              Align(alignment: Alignment.centerLeft, child: _sourcesButton()),
            ],
          ],
        );
      },
    );
  }

  /// The seven figures, one size, rows of [across].
  ///
  /// Jose's order, 2026-10-05: Calls > Missed Calls > Existing Client > New
  /// Client > Jobs Booked > Conv. Rate > Not Booked. A figure with calls
  /// behind it is the way into them.
  Widget _figures(
    BuildContext context,
    Map<String, dynamic> totals,
    int across,
  ) {
    final figures = <(String, Object?, String?, String?)>[
      ('Calls', totals['calls'], 'all', 'stat-calls'),
      (
        'Missed calls',
        totals['reached_nobody'],
        'missed',
        'stat-reached-nobody',
      ),
      (
        'Existing client',
        totals['existing_client'],
        'existing',
        'stat-existing',
      ),
      ('New client', totals['new_client'], 'new', 'stat-new'),
      ('Jobs booked', totals['jobs'], null, null),
      ('Conv. rate', _rate(totals['jobs'], totals['new_client']), null, null),
      ('Not booked', totals['not_booked'], 'not_booked', 'stat-not-booked'),
    ];
    const gap = 12.0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < figures.length; i += across) ...[
          if (i > 0) const SizedBox(height: gap),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (var j = i; j < i + across; j++) ...[
                  if (j > i) const SizedBox(width: gap),
                  Expanded(
                    child: j < figures.length
                        ? _stat(
                            context,
                            figures[j].$1,
                            figures[j].$2,
                            opens: figures[j].$3,
                            key: switch (figures[j].$4) {
                              final key? => ValueKey(key),
                              null => null,
                            },
                          )
                        : const SizedBox(),
                  ),
                ],
              ],
            ),
          ),
        ],
      ],
    );
  }

  /// Jobs over new-client calls, the same rule as each person's row; a dash
  /// when there were no new-client calls to convert.
  String _rate(Object? jobs, Object? newClient) {
    final n = (newClient as num?) ?? 0;
    if (n == 0) return '—';
    return '${((((jobs as num?) ?? 0) / n) * 100).round()}%';
  }

  Widget _stat(
    BuildContext context,
    String label,
    Object? value, {
    Key? key,
    String? opens,
  }) {
    final theme = Theme.of(context);
    final radius = BorderRadius.circular(EzBiziTheme.controlRadius);

    return Material(
      key: key,
      color: theme.colorScheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: BorderSide(color: theme.colorScheme.outlineVariant),
      ),
      child: InkWell(
        borderRadius: radius,
        onTap: opens == null ? null : () => _openCalls(opens),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('${value ?? 0}', style: theme.textTheme.headlineSmall),
              Text(label, style: theme.textTheme.bodySmall),
              // A figure that opens says so, or nobody finds out it does.
              if (opens != null) ...[
                const Spacer(),
                const SizedBox(height: 6),
                Text(
                  opens == 'all' ? 'See calls ›' : 'See them ›',
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: theme.colorScheme.primary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// New-client calls somebody answered that did not become a job: no Workiz
  /// job for the caller's number in the period, and not marked by hand.
  List<Map<String, dynamic>> _unbooked(List<Map<String, dynamic>> calls) =>
      calls
          .where(
            (c) =>
                c['queue'] == _newQueue &&
                c['answered_by'] != null &&
                c['booked'] != true,
          )
          .toList();

  /// The calls that reached nobody: customers calling in. Outbound calls
  /// nobody picked up are listed beside them in Missed, never counted as a
  /// missed customer.
  List<Map<String, dynamic>> _nobody(List<Map<String, dynamic>> calls) => calls
      .where((c) => c['outcome'] != 'answered' && !_isOutbound(c))
      .toList();

  /// Our calls out that nobody picked up.
  List<Map<String, dynamic>> _outbound(List<Map<String, dynamic>> calls) =>
      calls.where(_isOutbound).toList();

  static bool _isOutbound(Map<String, dynamic> c) =>
      c['direction'] == 'Outbound';

  Widget _notBookedCard(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) {
    final open = _unbooked(calls);
    final reasons = [
      for (final entry in _notBookedLabels.entries)
        if (open.where((c) => _labelOf(c, _labeller) == entry.key).length
            case final n when n > 0)
          '${entry.value} $n',
    ];

    return _listCard(
      context,
      key: const ValueKey('overview-not-booked'),
      title: 'Not booked',
      count: open.length,
      detail: reasons.isEmpty
          ? null
          : '${_labeller == 'grok' ? 'Grok' : 'Claude'}: ${reasons.join(' · ')}',
      empty: 'Every new-client call was booked.',
      rows: [
        for (final call in open.take(3))
          _briefCall(
            context,
            call,
            widget.canEdit
                ? OutlinedButton(
                    key: ValueKey('overview-convert-${call['id']}'),
                    onPressed: _converting.contains('${call['id']}')
                        ? null
                        : () => _convert(call),
                    child: const Text('Convert to booked'),
                  )
                : const SizedBox.shrink(),
          ),
      ],
      filter: 'not_booked',
    );
  }

  Widget _missedCard(BuildContext context, List<Map<String, dynamic>> calls) {
    final nobody = _nobody(calls);
    int count(String queue) => nobody.where((c) => c['queue'] == queue).length;

    return _listCard(
      context,
      key: const ValueKey('overview-missed'),
      title: 'Missed calls',
      count: nobody.length,
      detail: nobody.isEmpty
          ? null
          : 'New ${count(_newQueue)} · Existing ${count(_existingQueue)}',
      empty: 'Every call reached somebody.',
      rows: [
        for (final call in nobody.take(3))
          _briefCall(
            context,
            call,
            StatusPill(
              _outcomes[call['outcome']] ?? 'Nobody answered',
              tone: Tone.caution,
            ),
          ),
      ],
      filter: 'missed',
    );
  }

  /// A list that needs somebody, its first three calls and the way to the
  /// rest in the Calls tab.
  Widget _listCard(
    BuildContext context, {
    required Key key,
    required String title,
    required int count,
    required String? detail,
    required String empty,
    required List<Widget> rows,
    required String filter,
  }) {
    final theme = Theme.of(context);

    return Card(
      key: key,
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Text(
                  title,
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  '$count',
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: theme.colorScheme.outline,
                  ),
                ),
              ],
            ),
            if (detail != null) Text(detail, style: theme.textTheme.bodySmall),
            const SizedBox(height: 8),
            if (rows.isEmpty)
              Text(empty, style: theme.textTheme.bodyMedium)
            else
              ...rows,
            if (count > 0)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton(
                  key: ValueKey('see-all-$filter'),
                  onPressed: () => _openCalls(filter),
                  child: Text('See all $count in Calls ›'),
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// One call on a line: when, who called, who took it and for how long, or
  /// whom it rang. All of it — the recording, the words — is in Calls.
  Widget _briefCall(
    BuildContext context,
    Map<String, dynamic> call,
    Widget action,
  ) {
    final by = call['answered_by'];
    final rang = ((call['rang'] as List?) ?? const []).join(', ');
    final words = [
      _clock(call['started_at']),
      _caller(call),
      if (by != null) ...[
        '$by',
        _mmss(call['talk_seconds']),
      ] else if (rang.isNotEmpty)
        'rang $rang',
    ].where((w) => w.isNotEmpty).join(' · ');

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(words, style: Theme.of(context).textTheme.bodyMedium),
          ),
          const SizedBox(width: 8),
          action,
        ],
      ),
    );
  }

  // ── People ─────────────────────────────────────────────────────

  /// The table and its filter, alone, and what the conversion column claims.
  Widget _peopleTab(
    BuildContext context,
    List<Map<String, dynamic>> people,
    List<Map<String, dynamic>> calls,
  ) {
    final ordered = _sorted(
      _tagFilter == null
          ? people
          : people.where((p) => p['tag'] == _tagFilter).toList(),
    );

    /*
     * ── A CustomScrollView SO THE COLUMN HEADER CAN PIN ─────────
     *
     * Jose asked for the header to stay put while the people scroll. A
     * DataTable cannot do that - it draws its own header inside itself and
     * scrolls as one box - so the header is a sliver of its own and the rows
     * are a list beneath it.
     *
     * Which means the widths can no longer be decided by the table. They come
     * from _columns(), and the header and every row lay themselves out from
     * that one list, so a column added to it cannot appear in one and not the
     * other.
     */
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= ExperimentalCallReport.tableAbove;

        return CustomScrollView(
          slivers: [
            if (people.isNotEmpty)
              SliverToBoxAdapter(child: _tagFilters(context)),

            if (people.isEmpty)
              SliverToBoxAdapter(child: _nothing(context))
            else if (wide) ...[
              PinnedHeaderSliver(
                child: SizedBox(height: 45, child: _headerRow(context)),
              ),
              SliverList.builder(
                itemCount: ordered.length,
                itemBuilder: (context, index) =>
                    _personRow(context, ordered[index], calls),
              ),
            ] else
              SliverList.builder(
                itemCount: ordered.length,
                itemBuilder: (context, index) =>
                    _card(context, ordered[index], calls),
              ),

            if (people.isNotEmpty)
              SliverToBoxAdapter(child: _conversionNote(context)),
          ],
        );
      },
    );
  }

  /// All, or only the dispatchers tagged for one kind of client.
  Widget _tagFilters(BuildContext context) {
    final theme = Theme.of(context);
    Widget chip(String? value, String label) => ChoiceChip(
      key: ValueKey('people-tag-${value ?? 'all'}'),
      label: Text(label),
      selected: _tagFilter == value,
      // Drawn as the Calls bar is (UI queue 71).
      showCheckmark: false,
      onSelected: (_) => setState(() => _tagFilter = value),
    );

    return Padding(
      padding: const EdgeInsets.only(top: 8, bottom: 8),
      child: Wrap(
        spacing: 6,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Padding(
            padding: const EdgeInsets.only(right: 2),
            child: Text(
              'Answers for',
              style: theme.textTheme.labelMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          chip(null, 'Everyone'),
          chip('new', 'New client'),
          chip('existing', 'Existing client'),
        ],
      ),
    );
  }

  // ── Calls ──────────────────────────────────────────────────────

  /// Every call in the dates, narrowed by one bar (UI queue 71): what
  /// happened (Result) and who called (Client), each counted the same way.
  /// Why a call reached nobody joins the bar while Missed is showing. It used
  /// to be up to three rows of chips, counted two ways.
  Widget _callsTab(
    BuildContext context,
    List<Map<String, dynamic>> calls,
    Object? conversions, {
    required bool phone,
  }) {
    final theme = Theme.of(context);

    List<Map<String, dynamic>> byClient(String? client) => client == null
        ? calls
        : calls
              .where(
                (c) =>
                    c['queue'] ==
                    (client == 'new' ? _newQueue : _existingQueue),
              )
              .toList();
    List<Map<String, dynamic>> byResult(
      String result,
      List<Map<String, dynamic>> from,
    ) => switch (result) {
      'booked' => from.where((c) => c['booked'] == true).toList(),
      'not_booked' => _unbooked(from),
      'missed' => [..._nobody(from), ..._outbound(from)],
      _ => from.where((c) => !_isOutbound(c)).toList(),
    };

    final mine = byClient(_clientFilter);
    final shown = byResult(_result, mine);

    final reasons = <String, int>{};
    for (final call in _nobody(mine)) {
      final outcome = '${call['outcome']}';
      reasons[outcome] = (reasons[outcome] ?? 0) + 1;
    }

    Widget chip(String key, String label, bool on, VoidCallback pick) =>
        ChoiceChip(
          key: ValueKey('calls-filter-$key'),
          label: Text(label),
          selected: on,
          showCheckmark: false,
          onSelected: (_) => setState(pick),
        );

    Widget group(String label, List<Widget> chips) => Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          label,
          style: theme.textTheme.labelMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(width: 8),
        for (final chip in chips)
          Padding(padding: const EdgeInsets.only(right: 6), child: chip),
      ],
    );

    final groups = [
      group('Result', [
        for (final (key, label) in const [
          ('all', 'All'),
          ('booked', 'Booked'),
          ('not_booked', 'Not booked'),
          ('missed', 'Missed'),
        ])
          chip(key, '$label ${byResult(key, mine).length}', _result == key, () {
            _result = key;
            _labelFilter = null;
            _nobodyFilter = null;
            _directionFilter = null;
          }),
      ]),
      group('Client', [
        chip(
          'every-client',
          'All',
          _clientFilter == null,
          () => _clientFilter = null,
        ),
        for (final (key, label) in const [
          ('new', 'New'),
          ('existing', 'Existing'),
        ])
          chip(
            key,
            '$label ${byResult(_result, byClient(key)).length}',
            _clientFilter == key,
            () {
              _clientFilter = key;
              _labelFilter = null;
            },
          ),
      ]),
      if (_result == 'missed')
        group('Direction', [
          chip(
            'every-direction',
            'All',
            _directionFilter == null,
            () => _directionFilter = null,
          ),
          chip(
            'inbound',
            'Inbound ${_nobody(mine).length}',
            _directionFilter == 'inbound',
            () => _directionFilter = 'inbound',
          ),
          chip(
            'outbound',
            'Outbound ${_outbound(mine).length}',
            _directionFilter == 'outbound',
            () {
              _directionFilter = 'outbound';
              _nobodyFilter = null;
            },
          ),
        ]),
      if (_result == 'missed' && _directionFilter != 'outbound')
        group('Why', [
          for (final entry in _outcomes.entries)
            // A reason with no calls is still offered, at zero, so the
            // choices do not change shape from day to day - except
            // Other, which exists only to keep the count honest.
            if (entry.key != 'other' || (reasons['other'] ?? 0) > 0)
              FilterChip(
                key: ValueKey('nobody-${entry.key}'),
                label: Text('${entry.value} ${reasons[entry.key] ?? 0}'),
                selected: _nobodyFilter == entry.key,
                showCheckmark: false,
                onSelected: (on) =>
                    setState(() => _nobodyFilter = on ? entry.key : null),
              ),
          const Tooltip(
            message:
                'Missed call: rang a line or queue, nobody picked up. '
                'Voicemail: the caller left a message. '
                'Blocked: a blocked or rejected number. '
                'Callers who hung up in the phone menu are spam and not '
                'counted.',
            child: Icon(Icons.info_outline, size: 18),
          ),
        ]),
    ];

    // Sideways on a phone, where a thumb swipes the strip; wrapped wider.
    final bar = phone
        ? SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                for (final group in groups)
                  Padding(
                    padding: const EdgeInsets.only(right: 16),
                    child: group,
                  ),
              ],
            ),
          )
        : Wrap(spacing: 20, runSpacing: 8, children: groups);

    return ListView(
      padding: const EdgeInsets.only(top: 8, bottom: 24),
      children: [
        bar,
        const SizedBox(height: 12),
        ...switch (_result) {
          'not_booked' => _notBooked(context, mine, conversions),
          'missed' => _everyCall(
            context,
            shown
                .where(
                  (c) => switch (_directionFilter) {
                    'inbound' => !_isOutbound(c),
                    'outbound' => _isOutbound(c),
                    _ => true,
                  },
                )
                .where(
                  (c) => _nobodyFilter == null || c['outcome'] == _nobodyFilter,
                )
                .toList(),
          ),
          _ when _clientFilter == 'existing' => _existingCalls(context, shown),
          _ => _everyCall(context, shown),
        },
      ],
    );
  }

  /// Every call, or every new-client one, each with its words.
  List<Widget> _everyCall(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) => [
    if (calls.isEmpty)
      Text('No calls.', style: Theme.of(context).textTheme.bodyMedium)
    else
      for (final call in calls) ...[
        _call(context, null, call),
        // Nothing was said on a call nobody took.
        if (call['answered_by'] != null) _transcript(context, call),
        const Divider(height: 1),
      ],
  ];

  /// Every existing-client call in the period, with what each AI made of it.
  List<Widget> _existingCalls(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final all = calls;
    final mine = _byLabel(all);

    return [
      _labelCounts(context, all, _existingLabels),
      const SizedBox(height: 8),
      if (mine.isEmpty)
        Text('No existing-client calls.', style: theme.textTheme.bodyMedium)
      else
        for (final call in mine) ...[
          _call(context, null, call),
          _transcript(context, call),
          const Divider(height: 1),
        ],
    ];
  }

  /// The calls narrowed to the chosen label, from the chosen AI.
  List<Map<String, dynamic>> _byLabel(List<Map<String, dynamic>> calls) =>
      _labelFilter == null
      ? calls
      : calls.where((c) => _labelOf(c, _labeller) == _labelFilter).toList();

  String? _labelOf(Map<String, dynamic> call, String provider) =>
      (call['labels'] as Map?)?[provider] as String?;

  /// How many calls each AI put under each reason - "show only numbers" -
  /// with Claude and Grok switchable so the two can be compared on the same
  /// calls. Tapping a count narrows the list to those calls.
  Widget _labelCounts(
    BuildContext context,
    List<Map<String, dynamic>> calls,
    Map<String, String> labels,
  ) {
    final theme = Theme.of(context);
    final labelled = calls.where((c) => _labelOf(c, _labeller) != null).length;

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        // The reasons are AI's reading, not a count anybody checked (UI
        // queue 77).
        const AiMark(
          key: ValueKey('labels-ai-mark'),
          tooltip: 'Each call was read and sorted by AI, Claude or Grok. '
              'Switch between them to compare.',
        ),
        SegmentedButton<String>(
          key: const ValueKey('labeller'),
          showSelectedIcon: false,
          segments: const [
            ButtonSegment(value: 'claude', label: Text('Claude')),
            ButtonSegment(value: 'grok', label: Text('Grok')),
          ],
          selected: {_labeller},
          onSelectionChanged: (picked) => setState(() {
            _labeller = picked.first;
            _labelFilter = null;
          }),
        ),
        for (final entry in labels.entries)
          FilterChip(
            key: ValueKey('label-${entry.key}'),
            label: Text(
              '${entry.value} '
              '${calls.where((c) => _labelOf(c, _labeller) == entry.key).length}',
            ),
            selected: _labelFilter == entry.key,
            onSelected: (on) =>
                setState(() => _labelFilter = on ? entry.key : null),
          ),
        Text(
          labelled == calls.length
              ? 'All ${calls.length} read by AI.'
              : '${calls.length - labelled} of ${calls.length} not read by AI yet.',
          style: theme.textTheme.bodySmall,
        ),
      ],
    );
  }

  /// Both AIs' answer for one call, side by side.
  Widget _labelLine(BuildContext context, Map<String, dynamic> call) {
    final claude = _labelOf(call, 'claude');
    final grok = _labelOf(call, 'grok');
    if (claude == null && grok == null) return const SizedBox.shrink();

    String name(String? label) => label == null
        ? '—'
        : (_existingLabels[label] ?? _notBookedLabels[label] ?? label);

    final line = Text(
      'Claude: ${name(claude)} · Grok: ${name(grok)}',
      style: Theme.of(context).textTheme.bodySmall?.copyWith(
        color: Theme.of(context).colorScheme.primary,
      ),
    );
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          const AiMark(
            tooltip: 'What each AI read this call as. Neither was checked by '
                'a person.',
          ),
          const SizedBox(width: 6),
          Flexible(child: line),
        ],
      ),
    );
  }

  /// A call's words, folded until asked for: whole calls in a list would bury
  /// the list. Transcribed on EzBizi's server, or reused from the same call's
  /// Local Services lead; null until that has happened. Folded, it shows its
  /// first words, so a list can be read without opening every call.
  Widget _transcript(BuildContext context, Map<String, dynamic> call) {
    final theme = Theme.of(context);
    final id = '${call['id']}';
    final words = call['transcript'];

    if (words is! String) {
      return _under(
        Text(
          'Not transcribed yet.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.outline,
          ),
        ),
      );
    }

    final open = _openTranscripts.contains(id);

    return _under(
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _labelLine(context, call),
          TextButton(
            key: ValueKey('transcript-$id'),
            // A line of the row, not a button's worth of padding around it.
            style: TextButton.styleFrom(
              padding: EdgeInsets.zero,
              minimumSize: const Size(0, 30),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              alignment: Alignment.centerLeft,
              textStyle: theme.textTheme.bodyMedium,
            ),
            onPressed: () => setState(
              () =>
                  open ? _openTranscripts.remove(id) : _openTranscripts.add(id),
            ),
            child: Text(open ? 'Hide transcript' : _firstWords(words)),
          ),
          if (open)
            SelectableText(
              words.isEmpty ? 'Nothing was said.' : words,
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }

  /// New-client calls somebody answered that did not become a job: no Workiz
  /// job for the caller's number in the period, and not marked by hand. Each
  /// can be marked booked; the marks are the history beneath.
  List<Widget> _notBooked(
    BuildContext context,
    List<Map<String, dynamic>> calls,
    Object? conversions,
  ) {
    final theme = Theme.of(context);
    final unbooked = _unbooked(calls);
    final history = ((conversions as List?) ?? const [])
        .map((row) => (row as Map).cast<String, dynamic>())
        .toList();
    final shown = _byLabel(unbooked);

    return [
      _labelCounts(context, unbooked, _notBookedLabels),
      const SizedBox(height: 8),
      if (shown.isEmpty)
        Text(
          'Every new-client call was booked.',
          style: theme.textTheme.bodyMedium,
        )
      else
        for (final call in shown)
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _call(context, null, call),
              // The caller's number and the mark, beneath the call so a phone
              // never has to fit all three on one line.
              _under(
                Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 12,
                  runSpacing: 4,
                  children: [
                    SelectableText(
                      '${call['from'] ?? ''}',
                      style: theme.textTheme.bodySmall,
                    ),
                    if (widget.canEdit)
                      OutlinedButton(
                        key: ValueKey('convert-${call['id']}'),
                        onPressed: _converting.contains('${call['id']}')
                            ? null
                            : () => _convert(call),
                        child: const Text('Convert to booked'),
                      ),
                  ],
                ),
              ),
              _transcript(context, call),
              const Divider(height: 1),
            ],
          ),
      const SizedBox(height: 16),
      Text('Converted to booked', style: theme.textTheme.titleSmall),
      const SizedBox(height: 4),
      if (history.isEmpty)
        Text('None in this period.', style: theme.textTheme.bodySmall)
      else
        for (final row in history)
          Text(
            '${_clock(row['call_started_at'])} call · marked by '
            '${row['converted_by'] ?? 'someone no longer here'} at '
            '${_clock(row['converted_at'])}',
            style: theme.textTheme.bodySmall,
          ),
    ];
  }

  /// Why a call reached nobody, in Jose's words (2026-09-24). The server
  /// decides which is which from RingCentral's result; this only names them.
  static const _outcomes = {
    'missed': 'Missed call',
    'voicemail': 'Voicemail',
    'blocked': 'Blocked',
    'other': 'Other',
  };

  /// The columns, once, so the header and the rows cannot disagree.
  ///
  /// `flex` rather than fixed widths: above 900px there is room for all of
  /// them, and a fixed-width table would need its own horizontal scrollbar
  /// that the pinned header could not follow.
  List<({String key, String label, int flex, bool numeric})> _columns() =>
      const [
        (key: 'person', label: 'Person', flex: 3, numeric: false),
        (key: 'rang', label: 'Rang', flex: 2, numeric: true),
        (key: 'answered', label: 'Answered', flex: 2, numeric: true),
        (key: 'new_client', label: 'New', flex: 2, numeric: true),
        (key: 'existing_client', label: 'Existing', flex: 2, numeric: true),
        (key: 'jobs', label: 'Jobs', flex: 2, numeric: true),

        // Jose's column, 2026-09-17: "conversion rate %, which is calculated
        // between new and jobs".
        (key: 'conversion', label: 'Conversion', flex: 2, numeric: true),

        (key: 'avg_talk_seconds', label: 'Avg talk', flex: 2, numeric: true),
      ];

  /// Jobs booked as a share of new-client calls answered.
  ///
  /// ── IT IS A RATIO OF TWO HONEST NUMBERS, NOT A FUNNEL ───────
  ///
  /// `jobs` counts what a person created in Workiz on the day; `new_client`
  /// counts new-client calls they answered. Those are not the same population:
  /// somebody can book a job for an existing customer, or book today from a
  /// call taken last week. So this reads "jobs per new-client call", which is
  /// worth watching, and it is NOT "the share of their calls that converted".
  /// The screen says so under the table rather than leaving the percent sign
  /// to imply the stronger claim.
  ///
  /// Null when they answered no new-client calls — a dash. Zero divided by
  /// zero is not 0%, and 0% next to a name reads as a judgement about somebody
  /// who was never given the chance.
  double? _conversion(Map<String, dynamic> person) {
    final calls = (person['new_client'] as num?)?.toDouble() ?? 0;
    if (calls <= 0) return null;

    return ((person['jobs'] as num?)?.toDouble() ?? 0) / calls * 100;
  }

  /// What the conversion column does and does not claim.
  ///
  /// Written on the screen rather than left to the percent sign, because a
  /// column headed "Conversion" beside somebody's name will be read as a
  /// verdict on them. The two counts come from different systems and different
  /// populations, and the honest reading is narrower than the word.
  Widget _conversionNote(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      child: Text(
        'Conversion is jobs booked in Workiz divided by new-client calls '
        'answered, over the same dates. It is not the share of a person\'s '
        'calls that became work: a job can be booked for an existing customer, '
        'or booked in these dates from a call taken before them. A dash means '
        'they answered no new-client calls, which is different from converting '
        'none.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.outline,
        ),
      ),
    );
  }

  List<Map<String, dynamic>> _sorted(List<Map<String, dynamic>> people) {
    final ordered = [...people];

    ordered.sort((a, b) {
      final comparison = switch (_sortBy) {
        'person' => '${a['person']}'.toLowerCase().compareTo(
          '${b['person']}'.toLowerCase(),
        ),

        // Nulls last whichever way the arrow points. A person with no average
        // talk time has no value to rank, and sorting them to the top of a
        // descending column would read as the longest calls of the day.
        'conversion' => _nullsLast(_conversion(a), _conversion(b)),
        'avg_talk_seconds' => _nullsLast(
          (a['avg_talk_seconds'] as num?)?.toDouble(),
          (b['avg_talk_seconds'] as num?)?.toDouble(),
        ),

        _ => ((a[_sortBy] as num?) ?? 0).compareTo((b[_sortBy] as num?) ?? 0),
      };

      return _ascending ? comparison : -comparison;
    });

    return ordered;
  }

  /// Ranks two possibly-absent numbers, keeping absence at the bottom.
  ///
  /// Returns 0 when both are missing so the sort stays stable and two dashes
  /// do not swap places every time somebody flips the arrow.
  int _nullsLast(double? a, double? b) {
    if (a == null && b == null) return 0;

    // The sign is flipped by the caller, so "last" has to be expressed as
    // though ascending: a missing value sorts high, and the descending pass
    // inverts it back to the bottom.
    if (a == null) return _ascending ? 1 : -1;
    if (b == null) return _ascending ? -1 : 1;

    return a.compareTo(b);
  }

  void _sort(String key) {
    setState(() {
      if (_sortBy == key) {
        _ascending = !_ascending;
        return;
      }

      _sortBy = key;

      // A newly chosen column starts on the end somebody wants to see: most
      // calls first for a number, A first for a name.
      _ascending = key == 'person';
    });
  }

  Widget _headerRow(BuildContext context) {
    final theme = Theme.of(context);

    return Material(
      // Opaque on purpose: pinned, it scrolls over the rows beneath it, and a
      // transparent header would print the names through itself.
      color: theme.colorScheme.surface,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                for (final column in _columns())
                  Expanded(
                    flex: column.flex,
                    child: InkWell(
                      onTap: () => _sort(column.key),
                      child: Row(
                        mainAxisAlignment: column.numeric
                            ? MainAxisAlignment.end
                            : MainAxisAlignment.start,
                        children: [
                          Flexible(
                            child: Text(
                              column.label,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.labelLarge,
                            ),
                          ),

                          // Only on the column actually in force. An arrow on
                          // every header says nothing about which one is doing
                          // the sorting.
                          if (_sortBy == column.key)
                            Icon(
                              _ascending
                                  ? Icons.arrow_upward
                                  : Icons.arrow_downward,
                              size: 14,
                            ),
                        ],
                      ),
                    ),
                  ),

                // Matches the chevron's slot in every row below.
                const SizedBox(width: 40),
              ],
            ),
          ),
          const Divider(height: 1),
        ],
      ),
    );
  }

  Widget _personRow(
    BuildContext context,
    Map<String, dynamic> person,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final name = '${person['person']}';
    final open = _expanded == name;
    final only = _onlyIn(person);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        InkWell(
          onTap: () => setState(() => _expanded = open ? null : name),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                for (final column in _columns())
                  Expanded(
                    flex: column.flex,
                    child: Align(
                      alignment: column.numeric
                          ? Alignment.centerRight
                          : Alignment.centerLeft,
                      child: column.key == 'person'
                          ? Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(name, overflow: TextOverflow.ellipsis),
                                if (person['tag'] != null)
                                  Text(
                                    person['tag'] == 'new'
                                        ? 'New client'
                                        : 'Existing client',
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.primary,
                                    ),
                                  ),
                                if (only != null)
                                  Text(
                                    only,
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.outline,
                                    ),
                                  ),
                              ],
                            )
                          : Text(_cell(person, column.key)),
                    ),
                  ),
                SizedBox(
                  width: 40,
                  child: Icon(
                    open ? Icons.expand_less : Icons.expand_more,
                    color: theme.colorScheme.outline,
                  ),
                ),
              ],
            ),
          ),
        ),
        if (open) _calls(context, name, calls),
        const Divider(height: 1),
      ],
    );
  }

  String _cell(Map<String, dynamic> person, String key) => switch (key) {
    'avg_talk_seconds' => _mmss(person['avg_talk_seconds']),
    'conversion' => switch (_conversion(person)) {
      null => '—',
      final rate => '${rate.round()}%',
    },
    _ => '${person[key] ?? 0}',
  };

  /// The calls behind one person's row.
  ///
  /// ── A CALL THAT RANG THEM AND A CALL THEY TOOK ARE BOTH HERE ──
  ///
  /// The row has a Rang column and an Answered column, so drilling into it has
  /// to account for both or the list will not add up to the numbers that were
  /// clicked. Each call says which it was, and a call that rang four people
  /// appears under all four — which is what happened.
  Widget _calls(
    BuildContext context,
    String name,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);

    final mine = calls.where((call) {
      if (call['answered_by'] == name) return true;

      return ((call['rang'] as List?) ?? const []).contains(name);
    }).toList();

    final people = (_report?['people'] as List? ?? const []).map(
      (row) => (row as Map).cast<String, dynamic>(),
    );
    final tag = people.where((p) => p['person'] == name).firstOrNull?['tag'];

    // Set by hand per person (Jose, 2026-10-05). Three choices side by side
    // rather than a popup, the house rule for short choices.
    final tagger = Padding(
      padding: const EdgeInsets.fromLTRB(32, 0, 16, 8),
      child: Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: 8,
        runSpacing: 4,
        children: [
          Text('Answers for', style: theme.textTheme.bodySmall),
          SegmentedButton<String>(
            key: ValueKey('tag-$name'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: 'new', label: Text('New client')),
              ButtonSegment(value: 'existing', label: Text('Existing client')),
              ButtonSegment(value: 'none', label: Text('Not set')),
            ],
            selected: {'${tag ?? 'none'}'},
            onSelectionChanged: widget.canEdit
                ? (picked) => _setTag(
                    name,
                    picked.first == 'none' ? null : picked.first,
                  )
                : null,
          ),
        ],
      ),
    );

    if (mine.isEmpty) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          tagger,
          Padding(
            padding: const EdgeInsets.fromLTRB(32, 0, 16, 16),
            child: Text(
              'No calls for this person in this period. They are listed '
              'because Workiz has them booking jobs.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      );
    }

    return Container(
      color: theme.colorScheme.surfaceContainerLow,
      padding: const EdgeInsets.fromLTRB(32, 8, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [tagger, for (final call in mine) _call(context, name, call)],
      ),
    );
  }

  /// One call, on one row (UI queue 71): when, who took it, the caller,
  /// what it was and how it went, the talk time, and a play button. The
  /// recording opens under the row only when played; it used to be an empty
  /// bar under every call, stretched across the page. [name] is the person
  /// whose list it sits in, or null in Calls.
  Widget _call(BuildContext context, String? name, Map<String, dynamic> call) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final by = call['answered_by'];
    final id = call['recording_id']?.toString();

    // Said in words. Jose, 2026-09-24: "hard to understand what the icons
    // mean" - an arrow and a bell told nobody which was which.
    final status = _isOutbound(call)
        ? 'Called by ${call['placed_by'] ?? 'someone'} · no answer'
        : by == null
        ? (_outcomes[call['outcome']] ?? 'Nobody answered')
        : name == null
        ? 'Answered by $by'
        : by == name
        ? 'Answered'
        : 'Rang · $by answered';

    // Talk time on EVERY answered call, whoever took it. It used to say
    // "rang" on calls this person did not take, which read as a missing time.
    final talk = by == null ? 'no talk' : _mmss(call['talk_seconds']);
    final error = id == null ? null : _recordingErrors[id];
    final listening = id != null && _listeningTo == id;

    final reason = _existingLabels[_labelOf(call, _labeller)];
    final badges = [
      if (by == null)
        _isOutbound(call)
            ? const StatusPill('Outbound', tone: Tone.info)
            : const StatusPill('Inbound'),
      if (call['queue'] == _newQueue)
        const StatusPill('New client', tone: Tone.info)
      else if (call['queue'] == _existingQueue)
        const StatusPill('Existing client'),
      if (by == null)
        StatusPill(
          _isOutbound(call)
              ? 'No answer'
              : _outcomes[call['outcome']] ?? 'Nobody answered',
          tone: Tone.caution,
        )
      else if (call['queue'] == _newQueue)
        call['booked'] == true
            ? const StatusPill('Booked', tone: Tone.ok)
            : const StatusPill('Not booked', tone: Tone.caution)
      else if (call['queue'] == _existingQueue && reason != null)
        StatusPill(reason),
    ];

    final when = Text(
      _clock(call['started_at']),
      style: theme.textTheme.bodySmall?.copyWith(color: muted),
    );
    final who = Text(
      status,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.labelMedium?.copyWith(
        color: by != null && by == name ? theme.colorScheme.primary : muted,
        fontWeight: FontWeight.w600,
      ),
    );
    final caller = Text(
      _caller(call),
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
    );
    final talkText = Text(talk, style: theme.textTheme.bodySmall);
    final callId = '${call['id']}';
    // A call nobody answered opens to its details; its only recording is a
    // voicemail, and that plays in the details.
    final missed = by == null;
    final detailsOpen = missed && _openDetails.contains(callId);
    final voicemail = call['outcome'] == 'voicemail' && id != null;
    final line = [
      call['to_number'],
      call['to_name'],
    ].where((v) => v != null && '$v'.isNotEmpty).join(' · ');
    void toggleDetails() => setState(
      () =>
          detailsOpen ? _openDetails.remove(callId) : _openDetails.add(callId),
    );
    final play = missed
        ? IconButton(
            key: ValueKey('details-$callId'),
            tooltip: detailsOpen ? 'Hide details' : 'Show details',
            onPressed: toggleDetails,
            color: theme.colorScheme.onSurfaceVariant,
            icon: Icon(
              detailsOpen
                  ? Icons.expand_less
                  : voicemail
                  ? Icons.voicemail
                  : Icons.expand_more,
            ),
          )
        : id == null
        ? const SizedBox(width: 48)
        : IconButton(
            key: ValueKey('listen-$id'),
            tooltip: listening ? 'Close the recording' : 'Play the recording',
            onPressed: () {
              if (listening) unawaited(_player.stop());
              setState(() => _listeningTo = listening ? null : id);
            },
            icon: Icon(listening ? Icons.close : Icons.play_arrow),
          );

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            key: ValueKey('call-details-$callId'),
            onTap: missed ? toggleDetails : null,
            child: LayoutBuilder(
              builder: (context, box) => box.maxWidth >= _wideRow
                  ? Row(
                      children: [
                        SizedBox(width: _timeWidth, child: when),
                        SizedBox(
                          width: _whoWidth,
                          child: Padding(
                            padding: const EdgeInsets.only(right: 12),
                            child: who,
                          ),
                        ),
                        Expanded(child: caller),
                        const SizedBox(width: 12),
                        SizedBox(
                          width: 230,
                          child: Wrap(
                            spacing: 6,
                            runSpacing: 4,
                            children: badges,
                          ),
                        ),
                        SizedBox(
                          width: 48,
                          child: Align(
                            alignment: Alignment.centerRight,
                            child: talkText,
                          ),
                        ),
                        play,
                      ],
                    )
                  // A card's lines on a phone: when and how it went, then who.
                  : Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Wrap(
                                spacing: 6,
                                runSpacing: 4,
                                crossAxisAlignment: WrapCrossAlignment.center,
                                children: [when, ...badges, talkText],
                              ),
                              const SizedBox(height: 2),
                              Wrap(spacing: 6, children: [who, caller]),
                            ],
                          ),
                        ),
                        play,
                      ],
                    ),
            ),
          ),
          if (detailsOpen)
            _under(
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SelectableText(
                    '${_isOutbound(call) ? 'Number called' : 'Caller'}: ${call['from'] ?? 'unknown'}',
                    style: theme.textTheme.bodySmall,
                  ),
                  if (!_isOutbound(call))
                    SelectableText(
                      'Line called: ${line.isEmpty ? 'unknown' : line}',
                      style: theme.textTheme.bodySmall,
                    ),
                  if (voicemail) ...[
                    const SizedBox(height: 6),
                    ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 560),
                      child: RecordingPlaybackBar(
                        key: ValueKey('voicemail-$id'),
                        player: _player,
                        dense: true,
                        load: () => _recording(id),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          if (listening)
            _under(
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 560),
                child: RecordingPlaybackBar(
                  player: _player,
                  dense: true,
                  autoplay: true,
                  load: () => _recording(id),
                ),
              ),
            ),
          if (error != null)
            _under(
              Text(
                error,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
        ],
      ),
    );
  }

  /// A row this wide or wider lays a call out in columns; narrower, as a
  /// card's lines.
  static const _wideRow = 720.0;
  static const _whoWidth = 190.0;

  /// The time, and the day too when the dates span several.
  double get _timeWidth => _span.single ? 52 : 104;

  /// What hangs under a call - its words, its recording, its number - starts
  /// under the caller on a wide row, so the columns stay readable.
  Widget _under(Widget child) => LayoutBuilder(
    builder: (context, box) => Padding(
      padding: EdgeInsets.only(
        left: box.maxWidth >= _wideRow ? _timeWidth + _whoWidth : 0,
        bottom: 8,
      ),
      child: Align(alignment: Alignment.centerLeft, child: child),
    ),
  );

  /// Who called, said once. RingCentral's line name ends with the queue it
  /// rang ("NJ - LS Ads - A1 - New Client") and the queue is the client badge
  /// already, so the row said "A1 - New Client" twice. A queue that is
  /// neither (somebody's own extension) is still named.
  String _caller(Map<String, dynamic> call) {
    final queue = call['queue'] as String?;
    final known = queue == _newQueue || queue == _existingQueue;
    var label = '${call['caller_label'] ?? ''}';
    if (known && label.endsWith(queue!)) {
      label = label.substring(0, label.length - queue.length);
    }
    final parts = label
        .split(' - ')
        .map((part) => part.trim())
        .where((part) => part.isNotEmpty && part != '-');
    final said = parts.isEmpty
        ? '${call['from'] ?? 'Unknown caller'}'
        : parts.join(' · ');

    return known || queue == null ? said : '$said · $queue';
  }

  /// Fetches one recording, keeping the server's sentence when it refuses.
  ///
  /// `getBytesResponse` rather than `getBytes`, because the refusal here is
  /// worth reading: the recordings exist and the RingCentral app has not been
  /// granted permission to play them, which is something somebody can go and
  /// fix in a console. A bare "could not load" sends them into this codebase
  /// instead.
  Future<List<int>?> _recording(String id) async {
    final response = await widget.api.getBytesResponse(
      '/reports/experimental/calls/$id/recording',
    );

    if (response.bytes != null) {
      if (mounted) setState(() => _recordingErrors.remove(id));
      return response.bytes;
    }

    if (mounted) {
      setState(
        () => _recordingErrors[id] =
            response.message ?? 'That recording could not be loaded.',
      );
    }

    return null;
  }

  /// When a call was, out of an ISO stamp, without pulling in a date library.
  /// The time alone for one day; across several, the day as well, or a week
  /// of calls reads as one morning.
  String _clock(dynamic stamp) {
    final parsed = DateTime.tryParse('$stamp')?.toLocal();
    if (parsed == null) return '';

    final hour = parsed.hour.toString().padLeft(2, '0');
    final minute = parsed.minute.toString().padLeft(2, '0');
    if (_span.single) return '$hour:$minute';

    final day = dayWords(
      parsed,
      _today,
      widget.formats,
      weekday: _span.days <= 7,
    );
    return '$day $hour:$minute';
  }

  Widget _card(
    BuildContext context,
    Map<String, dynamic> person,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final only = _onlyIn(person);
    final name = '${person['person']}';
    final open = _expanded == name;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${person['person']}', style: theme.textTheme.titleMedium),
            if (only != null)
              Text(
                only,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            const SizedBox(height: 8),
            Text(
              '${person['answered']} answered of ${person['rang']} rung · '
              '${person['new_client']} new · '
              '${person['existing_client']} existing · '
              '${person['jobs']} jobs · '
              '${_cell(person, 'conversion')} conversion · '
              '${_mmss(person['avg_talk_seconds'])} avg talk',
              style: theme.textTheme.bodyMedium,
            ),

            // The same drill-down as the table, because a phone is where
            // somebody is most likely to want to hear the call rather than
            // read the count.
            InkWell(
              onTap: () => setState(() => _expanded = open ? null : name),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Row(
                  children: [
                    Text(
                      open ? 'Hide calls' : 'Show calls',
                      style: theme.textTheme.labelLarge,
                    ),
                    Icon(open ? Icons.expand_less : Icons.expand_more),
                  ],
                ),
              ),
            ),
            if (open) _calls(context, name, calls),
          ],
        ),
      ),
    );
  }

  /// Seconds as m:ss, and a DASH when there is no average at all.
  ///
  /// Null means they answered nothing, which is not the same fact as averaging
  /// zero seconds. Printing "0:00" would read as a measurement of a real call.
  String _mmss(Object? seconds) {
    if (seconds is! int) return '—';

    final minutes = seconds ~/ 60;
    final rest = (seconds % 60).toString().padLeft(2, '0');

    return '$minutes:$rest';
  }

  /// Flagged only when Workiz has never heard of somebody who took calls.
  ///
  /// ONE DIRECTION, because only one is knowable here. `in_jobs` is asked of a
  /// 30-day window of Workiz jobs, so a false answer means Workiz has no record
  /// of that name at all — which usually means the two systems spell them
  /// differently, and the reader is the only one who can tell.
  ///
  /// The reverse is deliberately NOT marked. This screen holds one day of
  /// calls, so "Workiz knows them, RingCentral does not" cannot be told apart
  /// from "they did not answer a phone today", and booking without answering is
  /// a normal way to work.
  ///
  /// It used to say "calls only" / "Workiz only" off the DAY's rows, which
  /// marked three people on the first live day who were in both systems and
  /// had simply booked nothing that morning.
  String? _onlyIn(Map<String, dynamic> person) {
    final inCalls = person['in_calls'] == true;
    final inJobs = person['in_jobs'] == true;

    return inCalls && !inJobs ? 'not in Workiz' : null;
  }
}

/// A transcript's opening, quoted: the way into the rest of it.
String _firstWords(String words) {
  final text = words.trim().replaceAll(RegExp(r'\s+'), ' ');
  if (text.isEmpty) return 'Transcript · nothing was said';
  if (text.length <= 60) return '“$text”';

  // At a word, not through one.
  final cut = text.substring(0, 60);
  final space = cut.lastIndexOf(' ');
  return '“${(space > 30 ? cut.substring(0, space) : cut).trimRight()}…”';
}

List<Map<String, dynamic>> _accounts(Map<String, dynamic> report) =>
    (report['workiz_accounts'] as List? ?? const [])
        .map((a) => (a as Map).cast<String, dynamic>())
        .toList();

List<String> _failed(Map<String, dynamic> report) =>
    (report['workiz_failed'] as Map? ?? const {}).keys
        .map((k) => k.toString())
        .toList();

/// What is wrong with the Workiz accounts behind a report, or null.
///
/// The accounts are disjoint and the same people book into all of them, so
/// one that did not answer makes everybody's jobs low rather than leaving a
/// region out — which is why it is said where the numbers are read.
String? _workizProblem(Map<String, dynamic> report) {
  final accounts = _accounts(report);
  final failed = _failed(report);

  if (accounts.isEmpty) {
    return 'No Workiz account is connected, so no jobs are counted — that is '
        'not the same as nobody booking anything.';
  }
  if (accounts.every((a) => failed.contains('${a['name']}'))) {
    return 'No Workiz account answered, so no jobs are counted — that is not '
        'the same as nobody booking anything.';
  }
  if (failed.isEmpty) return null;

  return 'Did not answer: ${failed.join(', ')}. The same people book into '
      'every account, so those numbers are low rather than incomplete.';
}

/// Sources: the Workiz accounts the jobs are read from (UI queue 60).
///
/// ── NAMED AND MANAGED HERE; A PROBLEM IS SAID ON THE PAGE ──────
///
/// A row of chips above the table read as a FILTER - Jose's words: "they
/// appear as if they were filters and people can get confused". They were
/// never filters; they are the connection list, and their X disconnects a
/// credential. So they are out of the page, behind Sources, and the one thing
/// the reader needs in order to read the numbers correctly — an account that
/// did not answer — is the amber line above the tabs.
///
/// The chips cannot be dropped entirely: a Workiz connection is a data source,
/// excluded from the shared connections list on purpose, so this is still the
/// ONLY place its id is published and the only way one can be taken back.
///
/// The connect flow lives here rather than in Settings because the tokens and
/// this report are retired together, and a connect flow parked somewhere
/// permanent outlives the thing that needed it. It is also the entry point:
/// without it the report has no way to reach its first account.
class _Sources extends StatefulWidget {
  const _Sources({
    required this.api,
    required this.report,
    required this.reload,
  });

  final ApiClient api;
  final Map<String, dynamic> report;

  /// Reloads the report behind the panel and hands back the new one.
  final Future<Map<String, dynamic>?> Function() reload;

  @override
  State<_Sources> createState() => _SourcesState();
}

class _SourcesState extends State<_Sources> {
  late Map<String, dynamic> _report = widget.report;

  final TextEditingController _token = TextEditingController();
  String? _disconnecting;
  String? _confirmingDisconnect;
  bool _connecting = false;
  String? _connectError;
  bool _showConnect = false;
  String? _error;

  @override
  void dispose() {
    _token.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    final fresh = await widget.reload();
    if (mounted && fresh != null) setState(() => _report = fresh);
  }

  /// Attach one Workiz account to the branch currently in view.
  ///
  /// The server refuses outside a branch view and its sentence says so, which
  /// is why nothing here tries to work out whether the view is right. One place
  /// deciding beats two places agreeing.
  Future<void> _connect() async {
    setState(() {
      _connecting = true;
      _connectError = null;
    });

    final response = await widget.api.post('/reports/experimental/workiz', {
      'api_token': _token.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _connecting = false;
        _connectError = response.message;
      });
      return;
    }

    _token.clear();
    setState(() {
      _connecting = false;
      _showConnect = false;
    });
    await _refresh();
  }

  /// Take a branch's Workiz account back.
  ///
  /// Straight through the shared connections endpoint, which already gates on
  /// this provider's own connect permission and needs nothing from here beyond
  /// the id.
  ///
  /// IT REMOVES EZBIZI'S STORED TOKEN AND NOTHING ELSE. No Workiz data is
  /// touched, no job is deleted, nothing changes on their side — that branch
  /// simply stops appearing in this report until somebody pastes its token
  /// again. That is still worth a confirmation, because the token is write-only
  /// once stored and nobody can read it back out of the app.
  Future<void> _disconnect(String id) async {
    setState(() {
      _disconnecting = id;
      _confirmingDisconnect = null;
      _error = null;
    });

    final response = await widget.api.delete('/social/connections/$id');

    if (!mounted) return;

    setState(() => _disconnecting = null);

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final accounts = _accounts(_report);
    final failed = _failed(_report);
    final problem = _workizProblem(_report);
    final covered = [
      for (final account in accounts)
        if (!failed.contains('${account['name']}')) '${account['name']}',
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          covered.isEmpty
              ? (problem ?? '')
              : 'Jobs cover ${covered.length} Workiz '
                    '${covered.length == 1 ? 'account' : 'accounts'}: '
                    '${covered.join(', ')}.',
          style: theme.textTheme.bodyMedium,
        ),
        if (covered.isNotEmpty && problem != null) ...[
          const SizedBox(height: 4),
          Text(
            problem,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
        // A book nobody will ever read, named rather than absent. Jose,
        // 2026-09-15: "we dont pay the api access there". Saying "not
        // connected" would send somebody off to connect it.
        for (final account
            in (_report['workiz_unreachable'] as List? ?? const []))
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              '${(account as Map)['name']} is never included: '
              '${account['reason']}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.outline,
              ),
            ),
          ),
        const SizedBox(height: 16),
        Text('Workiz accounts', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        if (accounts.isEmpty)
          Text('None connected.', style: theme.textTheme.bodySmall)
        else
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (final account in accounts)
                if (_confirmingDisconnect == account['id'])
                  // Confirmed IN PLACE rather than in a dialog, the same way
                  // deleting a saved report is. The X used to disconnect on one
                  // click, which is a poor trade: undoing it needs that
                  // branch's Workiz token pasted again, and the token is not
                  // something the app can show you.
                  Wrap(
                    spacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(
                        'Disconnect ${account['name']}?',
                        style: theme.textTheme.bodySmall,
                      ),
                      TextButton(
                        key: ValueKey('sources-disconnect-${account['id']}'),
                        onPressed: _disconnecting == account['id']
                            ? null
                            : () => _disconnect('${account['id']}'),
                        child: const Text('Disconnect'),
                      ),
                      TextButton(
                        onPressed: () =>
                            setState(() => _confirmingDisconnect = null),
                        child: const Text('Keep'),
                      ),
                    ],
                  )
                else
                  InputChip(
                    key: ValueKey('sources-account-${account['id']}'),
                    label: Text('${account['name']}'),
                    onDeleted: () => setState(
                      () => _confirmingDisconnect = '${account['id']}',
                    ),
                    deleteIcon: const Icon(Icons.close, size: 16),
                    tooltip: 'Disconnect ${account['name']}',
                  ),
            ],
          ),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
        const SizedBox(height: 12),
        _connectControl(context),
      ],
    );
  }

  /// The way a branch's Workiz account gets attached.
  Widget _connectControl(BuildContext context) {
    final theme = Theme.of(context);

    if (!_showConnect) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: const Key('sources-connect'),
          onPressed: () => setState(() => _showConnect = true),
          icon: const Icon(Icons.add_link),
          label: const Text('Connect a Workiz account'),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Switch to the branch this account belongs to, then paste its API '
          'Token from the v1 tab of Workiz › Developer API.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: TextField(
                key: const Key('sources-token'),
                controller: _token,
                decoration: InputDecoration(
                  labelText: 'Workiz API Token',
                  border: const OutlineInputBorder(),
                  errorText: _connectError,
                ),
                // Obscured: it is an account credential that can delete jobs,
                // and it is being pasted on somebody's screen.
                obscureText: true,
                enabled: !_connecting,
              ),
            ),
            const SizedBox(width: 8),
            FilledButton(
              key: const Key('sources-connect-save'),
              onPressed: _connecting ? null : _connect,
              child: const Text('Connect'),
            ),
            TextButton(
              onPressed: _connecting
                  ? null
                  : () => setState(() {
                      _showConnect = false;
                      _connectError = null;
                    }),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }
}
