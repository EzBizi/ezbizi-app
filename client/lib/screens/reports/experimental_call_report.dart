import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/deep_links.dart';
import '../../core/phone_numbers.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/ai_mark.dart';
import '../../widgets/date_range_picker.dart';
import '../../widgets/error_note.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';
import '../phone/recording_playback_bar.dart';
import '../phone/recording_player.dart';
import 'report_dates.dart';

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
    this.span,
    this.onSpan,
  });

  /// Changes the shared range: with it, this tab draws the shared bar itself
  /// so Sources and refresh share its row (UI-UX review of d1a6760d1).
  final ValueChanged<DateSpan>? onSpan;

  /// The range from the Reports screen's shared date bar (review item 14);
  /// with it, this tab draws no date bar of its own.
  final DateSpan? span;

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
  late DateSpan _span = widget.span ?? DateSpan.single(widget.now());

  @override
  void didUpdateWidget(ExperimentalCallReport old) {
    super.didUpdateWidget(old);
    if (widget.span != null && widget.span != old.span) _apply(widget.span!);
  }

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

  /// The Calls search (UI queue 78): a caller, a number or a person.
  String _query = '';

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
  // Grok first (Jose, 2026-10-13: "we only use grok for now, set it as the
  // default instead of claude"); Claude stays one tap away.
  // ponytail: one model, shown as "AI" (review 2026-10-30, item 9); a
  // Settings choice when a second model is in use again.
  final String _labeller = 'grok';
  String? _labelFilter;

  /// The People search and the Overview lists' searches (Jose, 2026-10-30).
  String _personQuery = '';
  String _notBookedQuery = '';
  String _missedQuery = '';

  static const _existingLabels = {
    'complaint': 'Complaint',
    'reschedule': 'Reschedule',
    'eta': 'ETA',
    'billing': 'Billing',
    'cancel': 'Cancel',
    'estimate_question': 'Estimate question',
    'job_status': 'Job status',
    'confirmation': 'Confirmation',
    'not_a_customer': 'Not a customer',
    'no_conversation': 'No conversation',
    'other': 'Other',
  };

  static const _notBookedLabels = {
    'price': 'Price',
    'availability': 'Availability',
    'reschedule': 'Reschedule',
    'cancel': 'Cancel',
    'confirmation': 'Confirmation',
    'service_question': 'Service question',
    'not_a_customer': 'Not a customer',
    'no_conversation': 'No conversation',
    'other': 'Other',
    'booked': 'AI heard a booking, no job yet',
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

    showWorkizSources(
      context,
      api: widget.api,
      report: report,
      reload: _reloaded,
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
    _directionFilter = null;
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
            if (widget.span == null)
              _dayBar(context, phone: phone)
            else
              KeyedSubtree(
                key: const ValueKey('tour-report-dates'),
                child: ReportDates(
                  key: ValueKey(widget.span),
                  inline: true,
                  initial: widget.span!,
                  today: _today,
                  formats: widget.formats,
                  onChanged: (span) => widget.onSpan?.call(span),
                  trailing: [
                    if (!phone) _sourcesButton(),
                    IconButton(
                      onPressed: _loading ? null : _load,
                      icon: const Icon(Icons.refresh),
                      tooltip: 'Reload',
                    ),
                  ],
                ),
              ),
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

    // The same quick ranges as every Reports tab (review 2026-10-30, 14).
    final quick = SingleChildScrollView(
      // One line on a phone too (UI-UX review): it scrolls sideways.
      scrollDirection: Axis.horizontal,
      child: Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final q in ReportDates.quick(today).entries)
            ChoiceChip(
              key: ValueKey('report-dates-${q.key}'),
              label: Text(ReportDates.quickLabels[q.key] ?? q.key),
              selected: _span == q.value,
              showCheckmark: false,
              onSelected: _loading ? null : (_) => _apply(q.value),
            ),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        quick,
        const SizedBox(height: 8),
        _dayRow(context, phone: phone, today: today),
      ],
    );
  }

  Widget _dayRow(
    BuildContext context, {
    required bool phone,
    required DateTime today,
  }) {
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
              phone: phone,
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
    final accounts = _report?['workiz_accounts'] as List?;
    final count = accounts?.length;
    final left = workizLeftOut(_report);

    return TextButton.icon(
      key: const Key('call-report-sources'),
      onPressed: _report == null ? null : _openSources,
      icon: const Icon(Icons.dns_outlined, size: 18),
      label: Text(
        count == null
            ? 'Sources'
            : left > 0
            ? 'Sources · ${count - left} of $count accounts'
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
    final accountCount =
        ((report['workiz_accounts'] as List?) ?? const []).length;
    final left = workizLeftOut(report);

    // Every account unticked: say so, never a page of zeros.
    if (accountCount > 0 && left == accountCount) {
      return Center(
        key: const Key('call-report-none-included'),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'No accounts included',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 8),
            FilledButton(
              key: const Key('call-report-choose-sources'),
              onPressed: _openSources,
              child: const Text('Choose sources'),
            ),
          ],
        ),
      );
    }
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
        phone
            ? 'Calls'
            : 'Calls · ${calls.where((c) => !_isOutbound(c)).length} in · '
                  '${calls.where(_isOutbound).length} out',
        Icons.call_outlined,
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ?problem,
        if (left > 0) LeftOutStrip(left: left, onChange: _openSources),
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
            // Missed is counted from the calls the card lists, so the
            // tile and the list cannot disagree (UI-UX review of 0a52fe906).
            _figures(context, {
              ...totals,
              'reached_nobody': _nobody(calls).length,
            }, across),
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
      ('Inbound calls', totals['calls'], 'all', 'stat-calls'),
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
      ('Jobs created', totals['jobs'], null, null),
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
                            help: _help[figures[j].$1],
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

  /// What each tile counts, said under it (review 2026-10-30).
  static const _help = {
    'Inbound calls': 'New + Existing + other lines',
    'Missed calls': 'Nobody picked up, incl. voicemail',
    'Jobs created': 'Workiz jobs for these dates',
    'Conv. rate': 'Jobs ÷ answered New-client calls',
  };

  /// Jobs over answered new-client calls, the same rule as each person's
  /// row, never above 100% (review item 3): more jobs than calls is said as
  /// "11 jobs · 6 calls". A dash when there were no new-client calls.
  static String _rate(Object? jobs, Object? newClient) {
    final n = (newClient as num?) ?? 0;
    final j = (jobs as num?) ?? 0;
    if (n == 0) return '—';
    if (j > n) return '$j jobs · $n calls';
    return '${(j / n * 100).round()}%';
  }

  Widget _stat(
    BuildContext context,
    String label,
    Object? value, {
    Key? key,
    String? opens,
    String? help,
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
              if (help != null)
                Text(
                  help,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.outline,
                  ),
                ),
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
                c['booked'] != true &&
                c['existing_job'] == null,
          )
          .toList();

  static String _existingJobTip(Map job) {
    String day(Object? v) => '$v'.length >= 10 ? '$v'.substring(0, 10) : '—';
    return 'Already had a job: booked ${day(job['created_at'])}, '
        'for ${day(job['scheduled_for'])}'
        '${job['status'] == null ? '' : ' (${job['status']})'}.';
  }

  /// The calls that reached nobody: customers calling in. Outbound calls
  /// nobody picked up are listed beside them in Missed, never counted as a
  /// missed customer.
  List<Map<String, dynamic>> _nobody(List<Map<String, dynamic>> calls) => calls
      .where((c) => c['outcome'] != 'answered' && !_isOutbound(c))
      .toList();

  /// Our calls out that nobody picked up.
  List<Map<String, dynamic>> _outbound(List<Map<String, dynamic>> calls) =>
      calls
          .where((c) => _isOutbound(c) && c['outcome'] != 'connected')
          .toList();

  /// A phone number typed in the search (seven digits or more): every call
  /// with it, outbound ones that connected included (Jose, 2026-10-13: "be
  /// able to search all calls by number as well").
  bool get _numberSearch =>
      _query.replaceAll(RegExp(r'[^0-9]'), '').length >= 7;

  static bool _isOutbound(Map<String, dynamic> c) =>
      c['direction'] == 'Outbound';

  /// A call whose caller name, line or number holds [query].
  bool Function(Map<String, dynamic>) _matches(String query) {
    final q = query.trim().toLowerCase();
    final digits = q.replaceAll(RegExp(r'[^0-9]'), '');
    return (c) =>
        q.isEmpty ||
        '${_who(c)} ${_line(c)}'.toLowerCase().contains(q) ||
        (digits.length >= 3 &&
            '${c['from'] ?? ''}'
                .replaceAll(RegExp(r'[^0-9]'), '')
                .contains(digits));
  }

  /// "Find a caller…" in an Overview list, styled as the Calls search.
  Widget _listSearch(String key, void Function(String) set) => TextField(
    key: ValueKey(key),
    onChanged: (value) => setState(() => set(value)),
    decoration: const InputDecoration(
      isDense: true,
      prefixIcon: Icon(Icons.search, size: 18),
      hintText: 'Find a caller…',
      border: OutlineInputBorder(),
    ),
  );

  Widget _notBookedCard(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) {
    final all = _unbooked(calls);
    final open = all.where(_matches(_notBookedQuery)).toList();
    // Every not-booked call has a reason or "Not read yet", so they add up
    // (review item 5); AI hearing a booking is said apart, as the booked side.
    final reasons = [
      for (final entry in _notBookedLabels.entries)
        if (entry.key != 'booked')
          if (all.where((c) => _labelOf(c, _labeller) == entry.key).length
              case final n when n > 0)
            '${entry.value} $n',
      if (all.where((c) => _labelOf(c, _labeller) == null).length case final n
          when n > 0)
        'Not read yet $n',
      if (all.where((c) => _labelOf(c, _labeller) == 'booked').length
          case final n when n > 0)
        '· ${_notBookedLabels['booked']} $n',
    ];

    return _listCard(
      context,
      key: const ValueKey('overview-not-booked'),
      title: 'Not booked',
      count: all.length,
      detail: reasons.isEmpty ? null : 'AI: ${reasons.join(' · ')}',
      empty: all.isEmpty
          ? 'Every new-client call was booked.'
          : 'No caller matches.',
      search: _listSearch(
        'overview-not-booked-search',
        (v) => _notBookedQuery = v,
      ),
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
    // One row per caller: the same number twice is "Name · 2 calls" (item 13).
    final byCaller = <String, List<Map<String, dynamic>>>{};
    for (final call in nobody.where(_matches(_missedQuery))) {
      byCaller.putIfAbsent('${call['from'] ?? call['id']}', () => []).add(call);
    }

    return _listCard(
      context,
      key: const ValueKey('overview-missed'),
      title: 'Missed calls',
      count: nobody.length,
      detail: nobody.isEmpty
          ? null
          : 'New ${count(_newQueue)} · Existing ${count(_existingQueue)}',
      empty: nobody.isEmpty
          ? 'Every call reached somebody.'
          : 'No caller matches.',
      search: _listSearch('overview-missed-search', (v) => _missedQuery = v),
      rows: [
        for (final repeat in byCaller.values.take(3))
          _briefCall(
            context,
            repeat.first,
            StatusPill(
              _outcomes[repeat.first['outcome']] ?? 'Nobody answered',
              tone: Tone.caution,
            ),
            times: repeat.length,
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
    Widget? search,
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
                if (search != null && count > 0) ...[
                  const SizedBox(width: 12),
                  Expanded(
                    child: Align(
                      alignment: Alignment.centerRight,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 220),
                        child: search,
                      ),
                    ),
                  ),
                ],
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
    Widget action, {
    int times = 1,
  }) {
    final by = call['answered_by'];
    final rang = ((call['rang'] as List?) ?? const []).join(', ');
    final words = [
      _clock(call['started_at']),
      times > 1 ? '${_who(call)} · $times calls' : _who(call),
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
    final q = _personQuery.trim().toLowerCase();
    final ordered = _sorted(
      people
          .where((p) => _tagFilter == null || p['tag'] == _tagFilter)
          .where((p) => q.isEmpty || '${p['person']}'.toLowerCase().contains(q))
          .toList(),
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
          SizedBox(
            width: 220,
            child: TextField(
              key: const ValueKey('people-search'),
              onChanged: (value) => setState(() => _personQuery = value),
              decoration: const InputDecoration(
                isDense: true,
                prefixIcon: Icon(Icons.search, size: 18),
                hintText: 'Find a person…',
                border: OutlineInputBorder(),
              ),
            ),
          ),
          const SizedBox(width: 10),
          Padding(
            padding: const EdgeInsets.only(right: 2),
            child: Text(
              // A filter, so not the setting's word (review item 11).
              'Show',
              style: theme.textTheme.labelMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          chip(null, 'Everyone'),
          chip('new', 'Handles New'),
          chip('existing', 'Handles Existing'),
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

    // Other: lines that are neither queue, so the three add up to All.
    List<Map<String, dynamic>> byClient(String? client) => switch (client) {
      null => calls,
      'other' =>
        calls
            .where(
              (c) => c['queue'] != _newQueue && c['queue'] != _existingQueue,
            )
            .toList(),
      _ =>
        calls
            .where(
              (c) =>
                  c['queue'] == (client == 'new' ? _newQueue : _existingQueue),
            )
            .toList(),
    };
    List<Map<String, dynamic>> byResult(
      String result,
      List<Map<String, dynamic>> from,
    ) => switch (result) {
      'booked' => from.where((c) => c['booked'] == true).toList(),
      'not_booked' => _unbooked(from),
      // Inbound only (review item 1): our calls out are their own chip.
      'missed' => _nobody(from),
      'outbound_no_answer' => _outbound(from),
      // Answered, not booked and not a lost booking: the rest of All (item 4).
      'answered_other' => () {
        final unbooked = _unbooked(from).toSet();
        return from
            .where(
              (c) =>
                  !_isOutbound(c) &&
                  c['outcome'] == 'answered' &&
                  c['booked'] != true &&
                  !unbooked.contains(c),
            )
            .toList();
      }(),
      // Grok read the call as booked, but no Workiz job matches it (Jose,
      // 2026-10-13: a list for a manager to follow up).
      'ai_booked_no_job' =>
        from
            .where(
              (c) =>
                  (c['labels'] as Map?)?['grok'] == 'booked' &&
                  c['booked'] != true &&
                  c['existing_job'] == null,
            )
            .toList(),
      _ => _numberSearch ? from : from.where((c) => !_isOutbound(c)).toList(),
    };

    // The search narrows everything under it, counts included, so a chip
    // never promises calls the list will not show.
    final query = _query.trim().toLowerCase();
    final digits = query.replaceAll(RegExp(r'[^0-9]'), '');
    bool found(Map<String, dynamic> c) =>
        query.isEmpty ||
        [
          _caller(c),
          c['answered_by'],
          c['placed_by'],
          c['to_name'],
        ].any((v) => v != null && '$v'.toLowerCase().contains(query)) ||
        (digits.length >= 3 &&
            '${c['from'] ?? ''}'
                .replaceAll(RegExp(r'[^0-9]'), '')
                .contains(digits));

    final mine = byClient(_clientFilter).where(found).toList();
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

    // Wraps: Result has seven chips now (review item 4).
    Widget group(String label, List<Widget> chips) => Wrap(
      crossAxisAlignment: WrapCrossAlignment.center,
      runSpacing: 6,
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
          ('answered_other', 'Answered · not a sale call'),
          ('missed', 'Missed'),
          ('outbound_no_answer', 'Outbound, no answer'),
          ('ai_booked_no_job', 'AI heard a booking, no job yet'),
        ])
          // The follow-up list only while it has calls, or is chosen.
          if (key != 'ai_booked_no_job' ||
              _result == key ||
              byResult(key, mine).isNotEmpty)
            chip(
              key,
              '$label ${byResult(key, mine).length}',
              _result == key,
              () {
                _result = key;
                _labelFilter = null;
                _nobodyFilter = null;
                _directionFilter = null;
              },
            ),
      ]),
      group('Client', [
        chip(
          'every-client',
          'All ${byResult(_result, byClient(null)).length}',
          _clientFilter == null,
          () => _clientFilter = null,
        ),
        for (final (key, label) in const [
          ('new', 'New'),
          ('existing', 'Existing'),
          ('other', 'Other'),
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
      // Always offered (review item 8); Why stays under Missed.
      group('Direction', [
        chip(
          'every-direction',
          'All',
          _directionFilter == null,
          () => _directionFilter = null,
        ),
        chip(
          'inbound',
          'Inbound ${mine.where((c) => !_isOutbound(c)).length}',
          _directionFilter == 'inbound',
          () => _directionFilter = 'inbound',
        ),
        chip(
          'outbound',
          'Outbound ${mine.where(_isOutbound).length}',
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
    final search = SizedBox(
      width: phone ? double.infinity : 260,
      child: TextField(
        key: const ValueKey('calls-search'),
        onChanged: (value) => setState(() => _query = value),
        decoration: const InputDecoration(
          isDense: true,
          prefixIcon: Icon(Icons.search, size: 18),
          hintText: 'Find a caller, person, or any call by number',
          border: OutlineInputBorder(),
        ),
      ),
    );
    // Which AI put each call where, above the table it narrows.
    final counts = switch (_result) {
      'not_booked' => _labelCounts(context, _unbooked(mine), _notBookedLabels),
      _
          when _clientFilter == 'existing' &&
              _result != 'missed' &&
              _result != 'outbound_no_answer' =>
        _labelCounts(context, shown, _existingLabels),
      _ => null,
    };

    // Direction narrows whatever Result shows; Outbound lists every call out.
    final directed = switch (_directionFilter) {
      'outbound' => mine.where(_isOutbound).toList(),
      'inbound' => shown.where((c) => !_isOutbound(c)).toList(),
      _ => shown,
    };
    final rows = [
      ...switch (_result) {
        _ when _directionFilter == 'outbound' => _everyCall(context, directed),
        'not_booked' => _notBooked(context, mine, conversions),
        'missed' => _everyCall(
          context,
          directed
              .where(
                (c) => _nobodyFilter == null || c['outcome'] == _nobodyFilter,
              )
              .toList(),
        ),
        _ when _clientFilter == 'existing' => _existingCalls(context, directed),
        _ => _everyCall(context, directed),
      },
    ];

    // A table whose headings stay put while the calls scroll, as People's
    // do (UI queue 78); a phone's cards need none.
    return LayoutBuilder(
      builder: (context, box) => CustomScrollView(
        slivers: [
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.only(top: 8, bottom: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (phone) ...[
                    bar,
                    const SizedBox(height: 8),
                    search,
                  ] else
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(child: bar),
                        const SizedBox(width: 16),
                        search,
                      ],
                    ),
                  if (_clientFilter != null) ...[
                    const SizedBox(height: 6),
                    Text(
                      'Results within ${switch (_clientFilter) {
                        'new' => 'New',
                        'existing' => 'Existing',
                        _ => 'Other',
                      }} (${byClient(_clientFilter).where((c) => !_isOutbound(c)).length})',
                      key: const ValueKey('calls-results-within'),
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                  if (counts != null) ...[const SizedBox(height: 10), counts],
                ],
              ),
            ),
          ),
          if (box.maxWidth >= _tableRow)
            PinnedHeaderSliver(child: _callsHeader(context)),
          SliverList.list(children: rows),
          const SliverToBoxAdapter(child: SizedBox(height: 24)),
        ],
      ),
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
      for (final call in calls) _call(context, null, call),
  ];

  /// Every existing-client call in the period, with what each AI made of it.
  List<Widget> _existingCalls(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final mine = _byLabel(calls);

    return [
      if (mine.isEmpty)
        Text('No existing-client calls.', style: theme.textTheme.bodyMedium)
      else
        for (final call in mine) _call(context, null, call),
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
        // queue 77). Which AI is the bar's "Reasons by" (UI queue 78).
        const AiMark(
          key: ValueKey('labels-ai-mark'),
          tooltip: 'Each call was read and sorted by AI.',
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
    final read = _labelOf(call, _labeller);
    if (read == null) return const SizedBox.shrink();

    String name(String? label) => label == null
        ? '—'
        : (_existingLabels[label] ?? _notBookedLabels[label] ?? label);

    final line = Text(
      'AI: ${name(read)}',
      style: Theme.of(context).textTheme.bodySmall?.copyWith(
        color: Theme.of(context).colorScheme.primary,
      ),
    );
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          const AiMark(
            tooltip: 'What AI read this call as. Not checked by a person.',
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
      return Text(
        'Not transcribed yet.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.outline,
        ),
      );
    }

    final open = _openTranscripts.contains(id);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
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
            () => open ? _openTranscripts.remove(id) : _openTranscripts.add(id),
          ),
          child: Text(open ? 'Hide transcript' : _firstWords(words)),
        ),
        if (open)
          SelectableText(
            words.isEmpty ? 'Nothing was said.' : words,
            style: theme.textTheme.bodySmall,
          ),
      ],
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

    // The number is the row's Number column and Convert is in what the row
    // opens to (UI queue 78), so a row here is the same row as anywhere.
    return [
      if (shown.isEmpty)
        Text(
          'Every new-client call was booked.',
          style: theme.textTheme.bodyMedium,
        )
      else
        for (final call in shown) _call(context, null, call),
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
  List<({String key, String label, int flex, bool numeric})> _columns() => [
    (key: 'person', label: 'Person', flex: 3, numeric: false),
    // Jose, 2026-10-30: set for all 34 from the table (design B1).
    (key: 'handles', label: 'Handles', flex: 5, numeric: false),
    (key: 'rang', label: 'Rang', flex: 2, numeric: true),
    (key: 'answered', label: 'Answered', flex: 2, numeric: true),

    // UI queue 78, from the two counts beside them: a call that rang this
    // person and that they did not pick up (someone else may have), and
    // the share they did. Their own phone rings for both.
    // Review item 6: a call a teammate took is not one this person missed.
    (key: 'teammate', label: 'Teammate took', flex: 3, numeric: true),
    (key: 'unanswered', label: 'Nobody answered', flex: 3, numeric: true),
    (key: 'answer_rate', label: 'Answer rate', flex: 2, numeric: true),
    (key: 'new_client', label: 'New', flex: 2, numeric: true),
    (key: 'existing_client', label: 'Existing', flex: 2, numeric: true),
    (key: 'jobs', label: 'Jobs', flex: 2, numeric: true),

    // Jose's column, 2026-09-17: "conversion rate %, which is calculated
    // between new and jobs".
    (key: 'conversion', label: 'Conversion', flex: 2, numeric: true),

    (key: 'avg_talk_seconds', label: 'Avg talk', flex: 2, numeric: true),

    // AI's coaching score (Jose, 2026-10-12), averaged over the calls a
    // person answered. Only once any call has one: scoring is switched
    // off until dispatchers have been told, and a column of dashes until
    // then would read as everyone scoring nothing.
    if (_scored) (key: 'score', label: 'Call score', flex: 2, numeric: true),
  ];

  /// The AI coaching reads of the calls [name] answered in these dates.
  List<Map<String, dynamic>> _scoresOf(String name) => [
    for (final call in (_report?['calls'] as List? ?? const []))
      if (call is Map && call['answered_by'] == name && call['score'] is Map)
        (call['score'] as Map).cast<String, dynamic>(),
  ];

  bool get _scored => (_report?['calls'] as List? ?? const []).any(
    (call) => call is Map && call['score'] is Map,
  );

  /// Mean score out of 10; null when none of their calls was scored.
  double? _avgScore(Map<String, dynamic> person) {
    final scores = _scoresOf('${person['person']}');
    if (scores.isEmpty) return null;

    return scores.fold<num>(0, (sum, s) => sum + ((s['score'] as num?) ?? 0)) /
        scores.length;
  }

  /// What the seven checks ask, in words.
  static const _checks = {
    'greeted': 'Greeted with the company name',
    'got_name': "Got the caller's name",
    'got_address': 'Got the address',
    'found_need': 'Asked what the problem is',
    'offered_time': 'Offered a time',
    'gave_price': 'Explained the price',
    'asked_for_booking': 'Asked to book',
  };

  /// One person's coaching: their average, how often each check was met,
  /// and the latest things AI said would have helped.
  /// Our coaching read and, where Workiz scores the branch (DMV, Illinois,
  /// New York), Workiz's own beside it (Jose, 2026-10-12).
  Widget _coaching(BuildContext context, String name) {
    final workiz = (_report?['workiz_scores'] as Map?)?[name];
    if (workiz is! Map) return _ourCoaching(context, name);

    final theme = Theme.of(context);
    String pct(Object? v) => v is num ? '${v.round()}%' : '—';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _ourCoaching(context, name),
        Padding(
          key: ValueKey('workiz-coaching-$name'),
          padding: const EdgeInsets.fromLTRB(0, 0, 16, 12),
          child: Text(
            "Workiz's own score · ${workiz['score']} / 100 over "
            '${workiz['calls']} call${workiz['calls'] == 1 ? '' : 's'} · '
            'booked ${pct(workiz['booked'])} · price discussed '
            '${pct(workiz['pricing'])} · positive ${pct(workiz['positive'])}',
            style: theme.textTheme.bodySmall,
          ),
        ),
      ],
    );
  }

  Widget _ourCoaching(BuildContext context, String name) {
    final scores = _scoresOf(name);
    if (scores.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final avg = _avgScore({'person': name})!;
    final notes = [
      for (final s in scores)
        if ('${s['improve'] ?? ''}'.trim().isNotEmpty) '${s['improve']}',
    ].take(3);

    return Padding(
      key: ValueKey('coaching-$name'),
      padding: const EdgeInsets.fromLTRB(0, 4, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Coaching · ${avg.toStringAsFixed(1)} / 10 over '
            '${scores.length} scored call${scores.length == 1 ? '' : 's'}',
            style: theme.textTheme.titleSmall,
          ),
          const SizedBox(height: 4),
          Wrap(
            spacing: 16,
            runSpacing: 2,
            children: [
              for (final check in _checks.entries)
                Text(
                  '${check.value}: ${(scores.where((s) => (s['checks'] as Map?)?[check.key] == true).length / scores.length * 100).round()}%',
                  style: theme.textTheme.bodySmall,
                ),
            ],
          ),
          for (final note in notes) ...[
            const SizedBox(height: 4),
            Text('To improve: $note', style: muted),
          ],
          const SizedBox(height: 4),
          Text(
            'Scored by AI from the transcript. A guide for coaching, not a '
            'verdict: the transcript can mishear, and some calls cannot be won.',
            style: muted?.copyWith(fontStyle: FontStyle.italic),
          ),
        ],
      ),
    );
  }

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
        'unanswered' => _unanswered(a).compareTo(_unanswered(b)),
        'teammate' => _missedBy(a).teammate.compareTo(_missedBy(b).teammate),
        'handles' => '${a['tag'] ?? 'both'}'.compareTo('${b['tag'] ?? 'both'}'),
        'answer_rate' => _nullsLast(_answerRate(a), _answerRate(b)),
        'avg_talk_seconds' => _nullsLast(
          (a['avg_talk_seconds'] as num?)?.toDouble(),
          (b['avg_talk_seconds'] as num?)?.toDouble(),
        ),
        'score' => _nullsLast(_avgScore(a), _avgScore(b)),

        _ => ((a[_sortBy] as num?) ?? 0).compareTo((b[_sortBy] as num?) ?? 0),
      };

      return _ascending ? comparison : -comparison;
    });

    return ordered;
  }

  /// Calls that rang this person and that they did not take. The answerer
  /// is among those it rang, so this cannot go below zero.
  int _unanswered(Map<String, dynamic> person) => _missedBy(person).nobody;

  /// Calls that rang [person] and that they did not take, split by whether a
  /// teammate took them or nobody did.
  ({int teammate, int nobody}) _missedBy(Map<String, dynamic> person) {
    final name = person['person'];
    var teammate = 0;
    var nobody = 0;
    for (final call in (_report?['calls'] as List? ?? const [])) {
      if (call is! Map || call['answered_by'] == name) continue;
      if (!((call['rang'] as List?) ?? const []).contains(name)) continue;
      call['answered_by'] == null ? nobody++ : teammate++;
    }
    return (teammate: teammate, nobody: nobody);
  }

  /// Answered out of the calls they could have taken: those a teammate took
  /// do not count against them. Null when nothing rang them.
  double? _answerRate(Map<String, dynamic> person) {
    final answered = (person['answered'] as num?) ?? 0;
    final chances = answered + _missedBy(person).nobody;
    if (chances == 0) return null;

    return answered / chances * 100;
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
                                if (only != null)
                                  Text(
                                    only,
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.outline,
                                    ),
                                  ),
                              ],
                            )
                          : column.key == 'handles'
                          ? _handles(context, person)
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

  /// New | Existing | Both, saved on the tap (design B1). Both is no tag,
  /// the default; without edit rights, the word alone.
  Widget _handles(BuildContext context, Map<String, dynamic> person) {
    final name = '${person['person']}';
    final tag = '${person['tag'] ?? 'both'}';
    if (!widget.canEdit) {
      return Text(switch (tag) {
        'new' => 'New',
        'existing' => 'Existing',
        _ => 'Both',
      }, key: ValueKey('handles-$name'));
    }
    return SegmentedButton<String>(
      key: ValueKey('handles-$name'),
      showSelectedIcon: false,
      style: const ButtonStyle(
        visualDensity: VisualDensity.compact,
        padding: WidgetStatePropertyAll(EdgeInsets.symmetric(horizontal: 8)),
      ),
      segments: const [
        ButtonSegment(value: 'new', label: Text('New')),
        ButtonSegment(value: 'existing', label: Text('Existing')),
        ButtonSegment(value: 'both', label: Text('Both')),
      ],
      selected: {tag},
      onSelectionChanged: (picked) =>
          _setTag(name, picked.first == 'both' ? null : picked.first),
    );
  }

  String _cell(Map<String, dynamic> person, String key) => switch (key) {
    'avg_talk_seconds' => _mmss(person['avg_talk_seconds']),
    'unanswered' => '${_unanswered(person)}',
    'teammate' => '${_missedBy(person).teammate}',
    'answer_rate' => switch (_answerRate(person)) {
      null => '—',
      final rate => '${rate.round()}%',
    },
    'conversion' => _rate(person['jobs'], person['new_client']),
    'score' => switch (_avgScore(person)) {
      null => '—',
      final avg => '${avg.toStringAsFixed(1)} / 10',
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

    if (mine.isEmpty) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
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
        children: [
          _coaching(context, name),
          for (final call in mine) _call(context, name, call),
        ],
      ),
    );
  }

  /// One call, on one row of a table (UI queue 78, after 71): when, in or
  /// out, who called and from which number, the client, how it went, who
  /// took it, AI's reason, the talk time, and play. Tapping it opens the
  /// rest beneath (see [_details]); the recording opens only when played.
  /// [name] is the person whose list it sits in, or null in Calls.
  Widget _call(BuildContext context, String? name, Map<String, dynamic> call) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final by = call['answered_by'];
    final id = call['recording_id']?.toString();
    final outbound = _isOutbound(call);
    // An outbound call that connected has nobody "answering" on our side.
    final missed = by == null && call['outcome'] != 'connected';

    // Said in words. Jose, 2026-09-24: "hard to understand what the icons
    // mean" - an arrow and a bell told nobody which was which.
    final whoText = outbound
        ? 'Called by ${call['placed_by'] ?? 'someone'}'
        : by == null
        ? '—'
        : name == null
        ? '$by'
        : by == name
        ? 'Answered'
        : 'Rang · $by answered';

    // Talk time on EVERY answered call, whoever took it. It used to say
    // "rang" on calls this person did not take, which read as a missing time.
    final talk = missed ? '—' : _mmss(call['talk_seconds']);
    final error = id == null ? null : _recordingErrors[id];
    final listening = id != null && _listeningTo == id;
    final callId = '${call['id']}';
    final detailsOpen = _openDetails.contains(callId);
    // A call nobody answered has only a voicemail to hear, and it plays in
    // the details.
    final voicemail = call['outcome'] == 'voicemail' && id != null;
    final reason = missed ? null : _labelName(_labelOf(call, _labeller));
    final line = [
      call['to_number'],
      call['to_name'],
    ].where((v) => v != null && '$v'.isNotEmpty).join(' · ');

    // A caller who pressed the other key is shown as what they were, marked
    // ↺ with what was pressed and why (Leads' correction, 2026-10-16).
    final fix = (call['reclassified'] as Map?)?.cast<String, dynamic>();
    final mark = fix == null ? '' : ' ↺';
    final pill = call['queue'] == _newQueue
        ? StatusPill('New$mark', tone: Tone.info)
        : call['queue'] == _existingQueue
        ? StatusPill('Existing$mark')
        : null;
    final client = pill == null || fix == null
        ? pill
        : Tooltip(
            key: ValueKey('reclassified-$callId'),
            message:
                'Re-classified: pressed '
                '${fix['pressed'] == _newQueue ? 'new' : 'existing'}'
                '${fix['reason'] == null ? '' : ' · ${fix['reason']}'}',
            child: pill,
          );
    final result = missed
        ? StatusPill(
            outbound
                ? 'No answer'
                : _outcomes[call['outcome']] ?? 'Nobody answered',
            tone: Tone.caution,
          )
        : call['queue'] == _newQueue
        ? call['booked'] == true
              ? const StatusPill('Booked', tone: Tone.ok)
              // The caller already had an open job (Jose, 2026-10-13): a
              // reschedule or a question, not a lost booking.
              : call['existing_job'] is Map
              ? Tooltip(
                  message: _existingJobTip(call['existing_job'] as Map),
                  // Jose, 2026-10-13: "maybe a rescheduled tag as well?"
                  child: StatusPill(
                    (call['labels'] as Map?)?[_labeller] == 'reschedule'
                        ? 'Rescheduled'
                        : 'Existing job',
                  ),
                )
              : const StatusPill('Not booked', tone: Tone.caution)
        : StatusPill(outbound ? 'Connected' : 'Answered');

    final small = theme.textTheme.bodySmall?.copyWith(color: muted);
    final dash = Text('—', style: small);
    final when = Text(
      _clock(call['started_at']),
      style: small,
      maxLines: 1,
      softWrap: false,
      overflow: TextOverflow.visible,
    );
    final direction = Text(
      outbound ? 'Out' : 'In',
      style: theme.textTheme.labelSmall?.copyWith(
        fontWeight: FontWeight.w700,
        color: outbound ? theme.colorScheme.primary : muted,
      ),
    );
    final named =
        call['from'] != null &&
        _who(call) != formatPhoneNumber('${call['from']}');
    // A name, with its number under it; or the number alone.
    final caller = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          _who(call),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.bodyMedium?.copyWith(
            fontWeight: FontWeight.w600,
          ),
        ),
        if (named)
          Text(
            formatPhoneNumber('${call['from']}'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: small,
          ),
      ],
    );
    final number = _line(call).isEmpty
        ? dash
        : Text(
            _line(call),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall,
          );
    final who = Text(
      whoText,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: theme.textTheme.bodySmall?.copyWith(
        color: by != null && by == name ? theme.colorScheme.primary : null,
        fontWeight: by != null && by == name ? FontWeight.w600 : null,
      ),
    );
    final why = reason == null
        ? dash
        : Text(
            reason,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall,
          );
    final talkText = Text(talk, style: theme.textTheme.bodySmall);

    void toggleDetails() => setState(
      () =>
          detailsOpen ? _openDetails.remove(callId) : _openDetails.add(callId),
    );
    final more = IconButton(
      key: ValueKey(missed ? 'details-$callId' : 'more-$callId'),
      tooltip: detailsOpen ? 'Hide details' : 'Show details',
      onPressed: toggleDetails,
      color: muted,
      visualDensity: VisualDensity.compact,
      icon: Icon(
        detailsOpen
            ? Icons.expand_less
            : voicemail
            ? Icons.voicemail
            : Icons.expand_more,
      ),
    );
    final play = missed || id == null
        ? null
        : IconButton(
            key: ValueKey('listen-$id'),
            tooltip: listening ? 'Close the recording' : 'Play the recording',
            visualDensity: VisualDensity.compact,
            onPressed: () {
              if (listening) unawaited(_player.stop());
              setState(() => _listeningTo = listening ? null : id);
            },
            icon: Icon(listening ? Icons.close : Icons.play_arrow),
          );
    final actions = Row(
      mainAxisSize: MainAxisSize.min,
      children: [?play, more],
    );
    // AI's coaching score for the call (Jose, 2026-10-13: "a column that
    // shows the score given by AI"); a dash until the call is scored, and
    // "Missed" on a call nobody answered (Jose, 2026-10-14).
    final aiScore = (call['score'] as Map?)?['score'];
    final score = Text(
      aiScore != null ? '$aiScore / 10' : '',
      key: ValueKey('score-cell-$callId'),
      style: aiScore == null ? TextStyle(color: muted) : null,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        InkWell(
          key: ValueKey('call-details-$callId'),
          onTap: toggleDetails,
          child: LayoutBuilder(
            builder: (context, box) => box.maxWidth >= _tableRow
                ? Container(
                    constraints: const BoxConstraints(minHeight: 44),
                    color: detailsOpen
                        ? theme.colorScheme.primaryContainer.withValues(
                            alpha: 0.35,
                          )
                        : null,
                    child: _cells([
                      when,
                      direction,
                      caller,
                      number,
                      client ?? dash,
                      result,
                      who,
                      why,
                      score,
                      talkText,
                      actions,
                    ]),
                  )
                // A card's lines on a phone, the same facts in the same
                // order: when and how it went, who called, who took it.
                : Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Wrap(
                                spacing: 6,
                                runSpacing: 4,
                                crossAxisAlignment: WrapCrossAlignment.center,
                                children: [
                                  when,
                                  result,
                                  ?client,
                                  if (aiScore != null) score,
                                  if (outbound) direction,
                                  if (!missed) talkText,
                                ],
                              ),
                              const SizedBox(height: 2),
                              caller,
                              Text(
                                [
                                  if (call['from'] != null)
                                    formatPhoneNumber('${call['from']}'),
                                  if (whoText != '—') whoText,
                                  ?reason,
                                ].join(' · '),
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: small,
                              ),
                            ],
                          ),
                        ),
                        actions,
                      ],
                    ),
                  ),
          ),
        ),
        if (detailsOpen) _under(_details(context, call, line, voicemail, id)),
        if (listening)
          _under(
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: RecordingPlaybackBar(
                player: _player,
                dense: true,
                autoplay: true,
                load: () => _recording(id),
                downloadName: RecordingPlaybackBar.nameFor(
                  call['started_at'],
                  call['from'],
                ),
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
        const Divider(height: 1),
      ],
    );
  }

  /// What a row opens to (UI queue 78): the numbers and a voicemail for a
  /// call nobody took; the words, both AIs' reading and the numbers for one
  /// somebody did, with Convert where it applies.
  Widget _details(
    BuildContext context,
    Map<String, dynamic> call,
    String line,
    bool voicemail,
    String? id,
  ) {
    final theme = Theme.of(context);
    final outbound = _isOutbound(call);
    final numbers = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SelectableText(
          '${outbound ? 'Number called' : 'Caller'}: ${call['from'] ?? 'unknown'}',
          style: theme.textTheme.bodySmall,
        ),
        if (!outbound)
          SelectableText(
            'Line called: ${line.isEmpty ? 'unknown' : line}',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );

    if (call['answered_by'] == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          numbers,
          if (voicemail) ...[
            const SizedBox(height: 6),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: RecordingPlaybackBar(
                key: ValueKey('voicemail-$id'),
                player: _player,
                dense: true,
                load: () => _recording(id!),
                downloadName: RecordingPlaybackBar.nameFor(
                  call['started_at'],
                  call['from'],
                ),
              ),
            ),
          ],
        ],
      );
    }

    final convertible = widget.canEdit && _unbooked([call]).isNotEmpty;

    return Wrap(
      spacing: 32,
      runSpacing: 10,
      children: [
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: _transcript(context, call),
        ),
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 360),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _labelLine(context, call),
              if (call['score'] case final Map score)
                Text(
                  'AI score ${score['score']}/10'
                  '${score['strength'] == null ? '' : ' · Did well: ${score['strength']}'}'
                  '${score['improve'] == null ? '' : ' · To improve: ${score['improve']}'}',
                  key: ValueKey('score-${call['id']}'),
                  style: theme.textTheme.bodySmall,
                ),
              numbers,
              if (convertible) ...[
                const SizedBox(height: 6),
                OutlinedButton(
                  key: ValueKey('convert-${call['id']}'),
                  onPressed: _converting.contains('${call['id']}')
                      ? null
                      : () => _convert(call),
                  child: const Text('Convert to booked'),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }

  /// A reason's words, from either AI's list.
  String? _labelName(String? label) => label == null
      ? null
      : (_existingLabels[label] ?? _notBookedLabels[label] ?? label);

  /// The Calls table's columns, once, so the heading and every row line up
  /// (UI queue 78): each fact has a column of its own rather than a pill in
  /// a shared box, so one added later is a column and not an overflow.
  Widget _cells(List<Widget> c) {
    Widget pad(Widget child) => Padding(
      padding: const EdgeInsets.only(right: 10),
      child: Align(alignment: Alignment.centerLeft, child: child),
    );

    return Row(
      children: [
        SizedBox(width: _timeWidth, child: pad(c[0])),
        SizedBox(width: 40, child: pad(c[1])),
        Expanded(flex: 4, child: pad(c[2])),
        SizedBox(width: 136, child: pad(c[3])),
        SizedBox(width: 92, child: pad(c[4])),
        SizedBox(width: 116, child: pad(c[5])),
        Expanded(flex: 3, child: pad(c[6])),
        Expanded(flex: 3, child: pad(c[7])),
        SizedBox(
          width: 112,
          child: Align(alignment: Alignment.centerRight, child: c[8]),
        ),
        SizedBox(
          width: 44,
          child: Align(alignment: Alignment.centerRight, child: c[9]),
        ),
        SizedBox(
          width: 92,
          child: Align(alignment: Alignment.centerRight, child: c[10]),
        ),
      ],
    );
  }

  /// The headings over the Calls table, pinned while the calls scroll.
  Widget _callsHeader(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.labelSmall?.copyWith(
      letterSpacing: 0.6,
      fontWeight: FontWeight.w700,
      color: theme.colorScheme.onSurfaceVariant,
    );
    Text heading(String text) => Text(text.toUpperCase(), style: style);

    return Material(
      // Opaque: pinned, it scrolls over the rows beneath it.
      color: theme.colorScheme.surface,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            height: 36,
            child: _cells([
              heading('Time'),
              const SizedBox.shrink(),
              heading('Caller'),
              heading('Line'),
              heading('Client'),
              heading('Result'),
              heading('Answered by'),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  heading('Reason'),
                  const SizedBox(width: 4),
                  const AiMark(tooltip: 'Each call was read and sorted by AI.'),
                ],
              ),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  heading('Score'),
                  const SizedBox(width: 4),
                  const AiMark(
                    tooltip:
                        'How well the call was handled, out of 10, scored by '
                        'AI from the transcript. Open the call for what it '
                        'did well and what would have helped.',
                  ),
                ],
              ),
              heading('Talk'),
              const SizedBox.shrink(),
            ]),
          ),
          const Divider(height: 1),
        ],
      ),
    );
  }

  /// A row this wide or wider is the table; narrower, a card's lines.
  static const _tableRow = 1000.0;

  /// The time, and the day too when the dates span several.
  double get _timeWidth => _span.single ? 56 : 108;

  /// What hangs under a call - its details, its recording - starts under the
  /// caller on a wide row, so the columns stay readable.
  Widget _under(Widget child) => LayoutBuilder(
    builder: (context, box) => Padding(
      padding: EdgeInsets.only(
        left: box.maxWidth >= _tableRow ? _timeWidth + 40 : 0,
        top: 4,
        bottom: 10,
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

  /// RingCentral's from-name is either the line called ("CLE - LS Ads - A1 -
  /// New Client") or the caller's own (CNAM, "SCHWEITZER,DONN").
  static bool _nameIsLine(Map<String, dynamic> call) =>
      '${call['caller_label'] ?? ''}'.contains(' - ');

  /// Who called (review item 7): their name in normal case, else the number.
  String _who(Map<String, dynamic> call) {
    final label = '${call['caller_label'] ?? ''}'.trim();
    if (label.isEmpty || _nameIsLine(call)) {
      return call['from'] == null
          ? 'Unknown caller'
          : formatPhoneNumber('${call['from']}');
    }
    // Only a shouted CNAM ("SCHWEITZER,DONN") is recased and reordered.
    if (label != label.toUpperCase()) return label;
    final parts = label
        .split(',')
        .map((p) => p.trim())
        .where((p) => p.isNotEmpty)
        .toList();
    final ordered = parts.length == 2 ? [parts.last, parts.first] : parts;
    return ordered
        .join(' ')
        .split(RegExp(r'\s+'))
        .map(
          (w) =>
              w.isEmpty ? w : w[0].toUpperCase() + w.substring(1).toLowerCase(),
        )
        .join(' ');
  }

  /// The line the call came in on: "CLE · LS Ads"; '' when not known.
  String _line(Map<String, dynamic> call) =>
      _nameIsLine(call) ? _caller(call) : '${call['to_name'] ?? ''}';

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

    // In the organization's own clock: "13:33" with 12-hour time set read
    // as a talk time beside the real ones (UI audit, 2026-10-13).
    final time = widget.formats.formatTime(parsed.hour, parsed.minute);
    if (_span.single) return time;

    final day = dayWords(
      parsed,
      _today,
      widget.formats,
      weekday: _span.days <= 7,
    );
    return '$day $time';
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
            const SizedBox(height: 4),
            _handles(context, person),
            if (only != null)
              Text(
                only,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            const SizedBox(height: 8),
            Text(
              '${person['answered']} answered of ${person['rang']} rung '
              '(${_cell(person, 'answer_rate')}) · '
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
/// credential. So they are out of the page, behind Sources, read-only (the
/// disconnect is Settings' alone, 2026-10-30), and the one thing
/// the reader needs in order to read the numbers correctly — an account that
/// did not answer — is the amber line above the tabs.
///
/// The chips cannot be dropped entirely: a Workiz connection is a data source,
/// excluded from the shared connections list on purpose, so this is still a
/// place its id is published.
///
/// The connect flow lives here rather than in Settings because the tokens and
/// this report are retired together, and a connect flow parked somewhere
/// permanent outlives the thing that needed it. It is also the entry point:
/// without it the report has no way to reach its first account.
/// How many Workiz accounts this person has unticked in Sources.
int workizLeftOut(Map<String, dynamic>? report) =>
    ((report?['workiz_accounts'] as List?) ?? const [])
        .where((a) => a is Map && a['included'] == false)
        .length;

/// Sources as a side panel: Performance's and Scoring's (UI-UX review of
/// d1a6760d1: one panel, not a copy). [report] carries `workiz_accounts`;
/// [reload] fetches it again after a tick.
void showWorkizSources(
  BuildContext context, {
  required ApiClient api,
  required Map<String, dynamic> report,
  required Future<Map<String, dynamic>?> Function() reload,
}) {
  // Read here: the panel's own context sits above the shell that provides it.
  final open = OpenDestination.of(context);
  showSidePanel<void>(
    context,
    builder: (context) => SidePanel(
      title: 'Sources',
      subtitle: 'Which Workiz accounts this report counts',
      child: _Sources(api: api, report: report, reload: reload, open: open),
    ),
  );
}

/// The slim strip a tab shows while some accounts are left out.
class LeftOutStrip extends StatelessWidget {
  const LeftOutStrip({super.key, required this.left, required this.onChange});

  final int left;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      key: const Key('call-report-left-out'),
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          Icon(
            Icons.filter_alt_outlined,
            size: 16,
            color: theme.colorScheme.outline,
          ),
          const SizedBox(width: 6),
          Text(
            '$left Workiz ${left == 1 ? 'account' : 'accounts'} left out',
            style: theme.textTheme.bodySmall,
          ),
          TextButton(onPressed: onChange, child: const Text('Change')),
        ],
      ),
    );
  }
}

class _Sources extends StatefulWidget {
  const _Sources({
    required this.api,
    required this.report,
    required this.reload,
    this.open,
  });

  final ApiClient api;
  final Map<String, dynamic> report;

  /// The shell's way to another destination, or null outside it.
  final void Function(String id)? open;

  /// Reloads the report behind the panel and hands back the new one.
  final Future<Map<String, dynamic>?> Function() reload;

  @override
  State<_Sources> createState() => _SourcesState();
}

class _SourcesState extends State<_Sources> {
  late Map<String, dynamic> _report = widget.report;

  final TextEditingController _token = TextEditingController();
  bool _connecting = false;
  String? _connectError;
  bool _showConnect = false;
  String? _error;
  String _find = '';
  bool _saving = false;

  /// Saves which accounts this person leaves out, then reloads the report.
  Future<void> _save(List<String> excluded) async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final response = await widget.api.put('/reports/experimental/sources', {
      'excluded': excluded,
    });
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (!response.ok) _error = response.message;
    });
    if (response.ok) await _refresh();
  }

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
        if (accounts.isEmpty)
          Text('None connected.', style: theme.textTheme.bodySmall)
        else ...[
          TextField(
            key: const Key('sources-search'),
            onChanged: (v) => setState(() => _find = v),
            decoration: const InputDecoration(
              isDense: true,
              prefixIcon: Icon(Icons.search, size: 18),
              hintText: 'Find an account…',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              TextButton(
                key: const Key('sources-all'),
                onPressed: _saving ? null : () => _save(const []),
                child: const Text('All'),
              ),
              TextButton(
                key: const Key('sources-none'),
                onPressed: _saving
                    ? null
                    : () => _save([for (final a in accounts) '${a['id']}']),
                child: const Text('None'),
              ),
            ],
          ),
          // A viewing choice, this person's own (review 2026-10-30, item 12):
          // a tick never disconnects and never changes anyone else's figures.
          for (final account in accounts)
            if ('${account['place'] ?? account['name']}'.toLowerCase().contains(
              _find.trim().toLowerCase(),
            ))
              CheckboxListTile(
                key: ValueKey('sources-account-${account['id']}'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: account['included'] != false,
                onChanged: _saving
                    ? null
                    : (on) => _save([
                        for (final a in accounts)
                          if (a['id'] == account['id']
                              ? on != true
                              : a['included'] == false)
                            '${a['id']}',
                      ]),
                title: Text('${account['place'] ?? account['name']}'),
                subtitle: Text(
                  'Connected',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.outline,
                  ),
                ),
              ),
        ],
        const SizedBox(height: 8),
        Text(
          'Unticking leaves an account out of your figures. It stays '
          'connected.',
          key: const Key('sources-help'),
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.outline,
          ),
        ),
        // A real way there (UI-UX review of d1a6760d1): the shell's
        // OpenDestination opens Settings, and the deep link names its tab.
        if (widget.open case final open?)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: const Key('sources-manage'),
              style: TextButton.styleFrom(padding: EdgeInsets.zero),
              onPressed: () {
                Navigator.of(context).maybePop();
                DeepLinks.open(
                  const DeepLink(
                    module: 'settings',
                    type: 'tab',
                    id: 'integrations',
                  ),
                );
                open('settings');
              },
              child: const Text(
                'Manage connections in Settings › Integrations',
              ),
            ),
          )
        else
          Text(
            'Manage connections in Settings › Integrations',
            key: const Key('sources-manage'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.outline,
            ),
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
