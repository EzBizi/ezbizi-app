import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../phone/recording_playback_bar.dart';
import '../phone/recording_player.dart';

/// One day of A-1's calls against the jobs they became (TEMPORARY).
///
/// This pane goes when EzBizi's own Phone and Jobs modules answer the question.
/// It reads RingCentral and Workiz and nothing in EzBizi, which is why it has
/// no view switcher and no place picker — there is nothing tenant-shaped in it.
///
/// ── The screen's job is to stop a number being read wrongly ─────
///
/// Three of the sentences here exist only for that:
///
/// 1. "Rang" and "answered" are different columns, because a queue call rings
///    three or four people until one picks up. The rang column is deliberately
///    NOT totalled — adding it would produce a number larger than the day's
///    calls that looks like the day's calls.
/// 2. The Workiz accounts covered are named. The accounts are disjoint and the
///    same people book into all of them, so a missing account makes a person's
///    job count wrong rather than merely omitting a region.
/// 3. A person known to only one of the two systems is marked. RingCentral's
///    "Adeline Pierre Louis-Aghaji" is Workiz's "Adeline Pierrelouis" and
///    nothing joins them; a near-match rule would credit one person's work to
///    another, which is worse than a visible duplicate.
class ExperimentalCallReport extends StatefulWidget {
  const ExperimentalCallReport({super.key, required this.api});

  final ApiClient api;

  static const double tableAbove = 900;

  @override
  State<ExperimentalCallReport> createState() => _ExperimentalCallReportState();
}

class _ExperimentalCallReportState extends State<ExperimentalCallReport> {
  DateTime _day = DateTime.now();
  Map<String, dynamic>? _report;
  String? _error;
  bool _loading = true;

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

  final TextEditingController _token = TextEditingController();
  String? _disconnecting;
  String? _confirmingDisconnect;
  bool _managing = false;
  bool _connecting = false;
  String? _connectError;
  bool _showConnect = false;

  /// Which card is open beneath the figures - 'missed', 'existing' or
  /// 'not_booked' - and what the Missed list is narrowed to.
  String? _panel;
  String? _nobodyFilter;
  String? _clientFilter;

  /// Day, week or month (Jose, 2026-10-05: "add options to choose period of
  /// time"). The server works out the range around `_day`.
  String _period = 'day';

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
    _token.dispose();
    _player.dispose();
    super.dispose();
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
    _load();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  String get _dayKey =>
      '${_day.year.toString().padLeft(4, '0')}-'
      '${_day.month.toString().padLeft(2, '0')}-'
      '${_day.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/reports/experimental/calls', {
      'day': _dayKey,
      'period': _period,
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

  /// One period back or forward: a day, a week, or a calendar month.
  void _move(int step) {
    setState(() {
      _day = switch (_period) {
        'week' => _day.add(Duration(days: 7 * step)),
        'month' => DateTime(_day.year, _day.month + step, 1),
        _ => _day.add(Duration(days: step)),
      };
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
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _dayBar(context),
        const SizedBox(height: 12),
        Expanded(child: _body(context)),
      ],
    );
  }

  Widget _dayBar(BuildContext context) {
    final today = DateTime.now();
    final isToday =
        _dayKey ==
        '${today.year.toString().padLeft(4, '0')}-'
            '${today.month.toString().padLeft(2, '0')}-'
            '${today.day.toString().padLeft(2, '0')}';

    final todayKey =
        '${today.year.toString().padLeft(4, '0')}-'
        '${today.month.toString().padLeft(2, '0')}-'
        '${today.day.toString().padLeft(2, '0')}';
    final from = '${_report?['from'] ?? _dayKey}';
    final to = '${_report?['to'] ?? _dayKey}';

    // A period that already reaches today has no "next".
    final atToday = isToday || to.compareTo(todayKey) >= 0;
    final unit = switch (_period) {
      'week' => 'week',
      'month' => 'month',
      _ => 'day',
    };

    return Row(
      children: [
        Expanded(
          child: Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SegmentedButton<String>(
                key: const ValueKey('call-report-period'),
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(value: 'day', label: Text('Day')),
                  ButtonSegment(value: 'week', label: Text('Week')),
                  ButtonSegment(value: 'month', label: Text('Month')),
                ],
                selected: {_period},
                onSelectionChanged: _loading
                    ? null
                    : (picked) {
                        setState(() => _period = picked.first);
                        _load();
                      },
              ),
              const SizedBox(width: 8),
              IconButton(
                onPressed: _loading ? null : () => _move(-1),
                icon: const Icon(Icons.chevron_left),
                tooltip: 'Previous $unit',
              ),
              Text(
                from == to ? from : '$from – $to',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              // Forward is disabled on today rather than hidden: a control that
              // vanishes reads as a bug, one that is greyed reads as a boundary.
              IconButton(
                onPressed: (_loading || atToday) ? null : () => _move(1),
                icon: const Icon(Icons.chevron_right),
                tooltip: 'Next $unit',
              ),
            ],
          ),
        ),
        IconButton(
          onPressed: _loading ? null : _load,
          icon: const Icon(Icons.refresh),
          tooltip: 'Reload',
        ),
      ],
    );
  }

  Widget _body(BuildContext context) {
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
            SliverToBoxAdapter(child: _totals(context, totals)),
            if (_panel == 'missed')
              SliverToBoxAdapter(child: _reachedNobody(context, calls)),
            if (_panel == 'existing')
              SliverToBoxAdapter(child: _existingCalls(context, calls)),
            if (_panel == 'not_booked')
              SliverToBoxAdapter(
                child: _notBooked(context, calls, report['conversions']),
              ),
            const SliverToBoxAdapter(child: SizedBox(height: 16)),
            SliverToBoxAdapter(child: _coverage(context, report)),
            const SliverToBoxAdapter(child: SizedBox(height: 16)),
            if (people.isNotEmpty)
              SliverToBoxAdapter(child: _tagFilters(context)),

            if (people.isEmpty)
              SliverToBoxAdapter(child: _nothing(context))
            else if (wide) ...[
              SliverPersistentHeader(
                pinned: true,
                delegate: _StickyHeader(child: _headerRow(context)),
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
              'This report reads $vendor directly, and no credential is '
              'configured for it. Nothing has gone wrong and no calls are '
              'missing — there is simply nothing to read.',
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
        'No calls and no jobs on this day.',
        style: Theme.of(context).textTheme.bodyMedium,
        textAlign: TextAlign.center,
      ),
    );
  }

  Widget _totals(BuildContext context, Map<String, dynamic> totals) {
    return Wrap(
      spacing: 12,
      runSpacing: 12,
      children: [
        // Jose's order, 2026-10-05: Calls > Missed Calls > Existing Client >
        // New Client > Jobs Booked > Conv. Rate > Not Booked.
        _stat(context, 'Calls', totals['calls']),
        _stat(
          context,
          'Missed calls',
          totals['reached_nobody'],
          key: const ValueKey('stat-reached-nobody'),
          open: _panel == 'missed',
          onTap: () => _toggle('missed'),
        ),
        _stat(
          context,
          'Existing client',
          totals['existing_client'],
          key: const ValueKey('stat-existing'),
          open: _panel == 'existing',
          onTap: () => _toggle('existing'),
        ),
        _stat(context, 'New client', totals['new_client']),
        _stat(context, 'Jobs booked', totals['jobs']),
        _stat(
          context,
          'Conv. rate',
          _rate(totals['jobs'], totals['new_client']),
        ),
        _stat(
          context,
          'Not booked',
          totals['not_booked'],
          key: const ValueKey('stat-not-booked'),
          open: _panel == 'not_booked',
          onTap: () => _toggle('not_booked'),
        ),
      ],
    );
  }

  void _toggle(String panel) =>
      setState(() => _panel = _panel == panel ? null : panel);

  /// Jobs over new-client calls, the same rule as each person's row; a dash
  /// when there were no new-client calls to convert.
  String _rate(Object? jobs, Object? newClient) {
    final n = (newClient as num?) ?? 0;
    if (n == 0) return '—';
    return '${((((jobs as num?) ?? 0) / n) * 100).round()}%';
  }

  /// All, or only the dispatchers tagged for one kind of client.
  Widget _tagFilters(BuildContext context) {
    Widget chip(String? value, String label) => ChoiceChip(
      key: ValueKey('people-tag-${value ?? 'all'}'),
      label: Text(label),
      selected: _tagFilter == value,
      onSelected: (_) => setState(() => _tagFilter = value),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Wrap(
        spacing: 8,
        children: [
          chip(null, 'Everyone'),
          chip('new', 'New client'),
          chip('existing', 'Existing client'),
        ],
      ),
    );
  }

  Widget _panelBox(BuildContext context, List<Widget> children) => Container(
    margin: const EdgeInsets.only(top: 12),
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(8),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: children,
    ),
  );

  /// Every existing-client call in the period. The reason tags (Complaint,
  /// Reschedule, ETA, Billing, Other) need the calls transcribed and read,
  /// which is not switched on yet - the list says so rather than inventing
  /// numbers.
  Widget _existingCalls(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final all = calls.where((c) => c['queue'] == _existingQueue).toList();
    final mine = _byLabel(all);

    return _panelBox(context, [
      _labelCounts(context, all, _existingLabels),
      const SizedBox(height: 8),
      if (mine.isEmpty)
        Text('No existing-client calls.', style: theme.textTheme.bodyMedium)
      else
        for (final call in mine) ...[
          _call(context, null, call),
          _transcript(context, call),
        ],
    ]);
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

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Text(
        'Claude: ${name(claude)} · Grok: ${name(grok)}',
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
          color: Theme.of(context).colorScheme.primary,
        ),
      ),
    );
  }

  /// A call's words, folded until asked for: whole calls in a list would bury
  /// the list. Transcribed on EzBizi's server, or reused from the same call's
  /// Local Services lead; null until that has happened.
  Widget _transcript(BuildContext context, Map<String, dynamic> call) {
    final theme = Theme.of(context);
    final id = '${call['id']}';
    final words = call['transcript'];

    if (words is! String) {
      return Padding(
        padding: const EdgeInsets.only(left: 24, bottom: 8),
        child: Text(
          'Not transcribed yet.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.outline,
          ),
        ),
      );
    }

    final open = _openTranscripts.contains(id);

    return Padding(
      padding: const EdgeInsets.only(left: 24, bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _labelLine(context, call),
          TextButton(
            key: ValueKey('transcript-$id'),
            onPressed: () => setState(
              () =>
                  open ? _openTranscripts.remove(id) : _openTranscripts.add(id),
            ),
            child: Text(open ? 'Hide transcript' : 'Transcript'),
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
  Widget _notBooked(
    BuildContext context,
    List<Map<String, dynamic>> calls,
    Object? conversions,
  ) {
    final theme = Theme.of(context);
    final open = calls
        .where(
          (c) =>
              c['queue'] == _newQueue &&
              c['answered_by'] != null &&
              c['booked'] != true,
        )
        .toList();
    final history = ((conversions as List?) ?? const [])
        .map((row) => (row as Map).cast<String, dynamic>())
        .toList();
    final unbooked = open;
    final shown = _byLabel(unbooked);

    return _panelBox(context, [
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
              Padding(
                padding: const EdgeInsets.only(left: 24, bottom: 8),
                child: Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 12,
                  runSpacing: 4,
                  children: [
                    SelectableText(
                      '${call['from'] ?? ''}',
                      style: theme.textTheme.bodySmall,
                    ),
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
    ]);
  }

  Widget _stat(
    BuildContext context,
    String label,
    Object? value, {
    Key? key,
    bool open = false,
    VoidCallback? onTap,
  }) {
    final theme = Theme.of(context);

    return Material(
      key: key,
      color: open
          ? theme.colorScheme.secondaryContainer
          : theme.colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(8),
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('${value ?? 0}', style: theme.textTheme.headlineSmall),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(label, style: theme.textTheme.bodySmall),
                  // A card that opens says so, or nobody finds out it does.
                  if (onTap != null)
                    Icon(
                      open ? Icons.expand_less : Icons.expand_more,
                      size: 16,
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Why a call reached nobody, in Jose's words (2026-09-24). The server
  /// decides which is which from RingCentral's result; this only names them.
  static const _outcomes = {
    'missed': 'Missed call',
    'voicemail': 'Voicemail',
    'blocked': 'Blocked',
    'other': 'Other',
  };

  /// The calls behind the Reached nobody card, narrowed by reason.
  Widget _reachedNobody(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    // New or Existing narrows the list and the reason counts together
    // (Jose, 2026-10-05: "add filter for existing & new clients").
    final nobody = calls
        .where((c) => c['outcome'] != 'answered')
        .where(
          (c) =>
              _clientFilter == null ||
              c['queue'] ==
                  (_clientFilter == 'new' ? _newQueue : _existingQueue),
        )
        .toList();

    final counts = <String, int>{};
    for (final call in nobody) {
      final outcome = '${call['outcome']}';
      counts[outcome] = (counts[outcome] ?? 0) + 1;
    }

    final shown = _nobodyFilter == null
        ? nobody
        : nobody.where((c) => c['outcome'] == _nobodyFilter).toList();

    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final (value, label) in const [
                ('new', 'New client'),
                ('existing', 'Existing client'),
              ])
                FilterChip(
                  key: ValueKey('missed-client-$value'),
                  label: Text(label),
                  selected: _clientFilter == value,
                  onSelected: (on) =>
                      setState(() => _clientFilter = on ? value : null),
                ),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final entry in _outcomes.entries)
                // A reason with no calls is still offered, at zero, so the
                // choices do not change shape from day to day - except
                // Other, which exists only to keep the count honest.
                if (entry.key != 'other' || (counts['other'] ?? 0) > 0)
                  FilterChip(
                    key: ValueKey('nobody-${entry.key}'),
                    label: Text('${entry.value} (${counts[entry.key] ?? 0})'),
                    selected: _nobodyFilter == entry.key,
                    onSelected: (on) =>
                        setState(() => _nobodyFilter = on ? entry.key : null),
                  ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Missed call: rang a line or queue, nobody picked up. '
            'Voicemail: the caller left a message. '
            'Blocked: a blocked or rejected number. '
            'Callers who hung up in the phone menu are spam and not counted.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          if (shown.isEmpty)
            Text('No calls.', style: theme.textTheme.bodyMedium)
          else
            for (final call in shown) _call(context, null, call),
        ],
      ),
    );
  }

  /// What these numbers cover, by name.
  Widget _coverage(BuildContext context, Map<String, dynamic> report) {
    final theme = Theme.of(context);

    final accounts = (report['workiz_accounts'] as List? ?? const [])
        .map((a) => (a as Map).cast<String, dynamic>())
        .toList();

    final failed = (report['workiz_failed'] as Map? ?? const {}).keys
        .map((k) => k.toString())
        .toList();

    final covered = accounts
        .map((a) => '${a['name']}')
        .where((name) => !failed.contains(name))
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          covered.isEmpty
              ? 'No Workiz account answered, so the jobs column is blank — '
                    'that is not the same as nobody booking anything.'
              : 'Jobs cover ${covered.length} Workiz '
                    '${covered.length == 1 ? 'account' : 'accounts'}: '
                    '${covered.join(', ')}.',
          style: theme.textTheme.bodySmall,
        ),
        // A book nobody will ever read, named rather than absent. Jose,
        // 2026-09-15: "we dont pay the api access there". Saying "not
        // connected" would send somebody off to connect it.
        for (final account
            in (report['workiz_unreachable'] as List? ?? const []))
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
        if (failed.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(
            'Did not answer: ${failed.join(', ')}. The same people book into '
            'every account, so those numbers are low rather than incomplete.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],

        /*
         * ── THE ACCOUNTS ARE SAID IN WORDS, MANAGED BEHIND A CLICK ──
         *
         * A row of chips sitting above a table reads as a FILTER - Jose's
         * words: "they appear as if they were filters and people can get
         * confused". They were never filters; they are the connection list,
         * and their X disconnects a credential.
         *
         * So which accounts a number covers stays in the sentence above, where
         * it belongs to the report, and the chips move behind this toggle,
         * where they belong to setup. Nothing is hidden that the reader needs
         * in order to read the numbers correctly.
         *
         * The chips cannot be dropped entirely: a Workiz connection is a data
         * source, excluded from the shared connections list on purpose, so this
         * is still the ONLY place its id is published and the only way one can
         * be taken back.
         */
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => setState(() => _managing = !_managing),
            icon: Icon(
              _managing ? Icons.expand_less : Icons.expand_more,
              size: 18,
            ),
            label: Text(
              _managing
                  ? 'Hide Workiz accounts'
                  : 'Manage Workiz accounts (${accounts.length})',
            ),
          ),
        ),
        if (_managing) ...[
          // Each connected branch, with the way to remove it. This is the ONLY
          // place a Workiz account's id is published: it is a data source, and
          // the shared connections list excludes those on purpose, so without
          // these chips a credential could be connected and never taken back.
          if (accounts.isNotEmpty)
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
                      label: Text('${account['name']}'),
                      onDeleted: () => setState(
                        () => _confirmingDisconnect = '${account['id']}',
                      ),
                      deleteIcon: const Icon(Icons.close, size: 16),
                      tooltip: 'Disconnect ${account['name']}',
                    ),
              ],
            ),
          const SizedBox(height: 8),
          _connectControl(context),
        ],
      ],
    );
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
    });

    final response = await widget.api.delete('/social/connections/$id');

    if (!mounted) return;

    setState(() => _disconnecting = null);

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    _load();
  }

  /// The way a branch's Workiz account gets attached.
  ///
  /// It lives HERE rather than in Settings because the tokens and this report
  /// are retired together, and a connect flow parked somewhere permanent
  /// outlives the thing that needed it. It is also the entry point: without it
  /// the report has no way to reach its first account, which is the kind of
  /// dead end a screen full of stubbed rows never shows.
  Widget _connectControl(BuildContext context) {
    final theme = Theme.of(context);

    if (!_showConnect) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
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
        'answered, for the same day. It is not the share of a person\'s calls '
        'that became work: a job can be booked for an existing customer, or '
        'booked today from a call taken last week. A dash means they answered '
        'no new-client calls, which is different from converting none.',
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
            onSelectionChanged: (picked) =>
                _setTag(name, picked.first == 'none' ? null : picked.first),
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
      color: theme.colorScheme.surfaceContainerHighest,
      padding: const EdgeInsets.fromLTRB(32, 8, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [tagger, for (final call in mine) _call(context, name, call)],
      ),
    );
  }

  /// One call. [name] is the person whose list it sits in, or null in the
  /// Reached nobody list.
  Widget _call(BuildContext context, String? name, Map<String, dynamic> call) {
    final theme = Theme.of(context);
    final by = call['answered_by'];
    final id = call['recording_id']?.toString();

    // Said in words. Jose, 2026-09-24: "hard to understand what the icons
    // mean" - an arrow and a bell told nobody which was which.
    final status = by == null
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

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                status,
                style: theme.textTheme.labelMedium?.copyWith(
                  color: by != null && by == name
                      ? theme.colorScheme.primary
                      : theme.colorScheme.onSurfaceVariant,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  '${_clock(call['started_at'])} · '
                  '${call['caller_label'] ?? call['from'] ?? 'Unknown caller'}'
                  '${call['queue'] == null ? '' : ' · ${call['queue']}'}',
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              Text(talk, style: theme.textTheme.bodySmall),
            ],
          ),
          if (id != null)
            Padding(
              padding: const EdgeInsets.only(left: 24, top: 4),
              child: RecordingPlaybackBar(
                player: _player,
                dense: true,
                load: () => _recording(id),
              ),
            ),
          if (error != null)
            Padding(
              padding: const EdgeInsets.only(left: 24, top: 4),
              child: Text(
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

  /// The time of day out of an ISO stamp, without pulling in a date library
  /// for eight characters.
  String _clock(dynamic stamp) {
    final parsed = DateTime.tryParse('$stamp')?.toLocal();
    if (parsed == null) return '';

    final hour = parsed.hour.toString().padLeft(2, '0');
    final minute = parsed.minute.toString().padLeft(2, '0');

    return '$hour:$minute';
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

/// Holds the column header at the top of the list while the people scroll.
///
/// A fixed height, because the header is one row of labels and measuring it
/// would mean laying it out twice on every frame. It is stated in one place
/// and the header's own padding is derived from nothing else.
class _StickyHeader extends SliverPersistentHeaderDelegate {
  const _StickyHeader({required this.child});

  final Widget child;

  static const double _height = 45;

  @override
  double get minExtent => _height;

  @override
  double get maxExtent => _height;

  @override
  Widget build(
    BuildContext context,
    double shrinkOffset,
    bool overlapsContent,
  ) {
    return SizedBox(height: _height, child: child);
  }

  /// True whenever the header WIDGET differs, which it does on every sort
  /// change — the arrow moves and the labels rebuild. Returning false would
  /// pin a header showing the previous column's arrow forever.
  @override
  bool shouldRebuild(_StickyHeader old) => old.child != child;
}
