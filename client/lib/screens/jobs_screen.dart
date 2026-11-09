import '../core/deep_links.dart';
import '../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/branch_options.dart';
import '../core/job_money.dart';
import '../core/job_status.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/book_job_panel.dart';
import '../widgets/data_transfer.dart';
import '../widgets/error_note.dart';
import '../widgets/grouped_rows.dart';
import '../widgets/refused.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/tag.dart';
import 'jobs/estimates_tab.dart';
import 'jobs/job_days.dart';
import 'jobs/job_detail_panel.dart';
import 'jobs/job_labels.dart';
import 'jobs/job_where.dart';

/// Jobs (plan §5.2).
///
/// The opposite shape to customers: the server scopes this list to the caller's
/// reach, because jobs are never shared. A company-level user sees every
/// franchise beneath them — that is the roll-up, and it comes from the server so
/// the client never has to work out who can see what.
///
/// Exporting jobs is HERE rather than on a tab of its own (§12), the same move
/// Customers made. Only exporting: jobs are export-only on the server, because a
/// CSV of customer names cannot say WHICH "J. Smith" a job belongs to — see
/// ResourceMap. [DataTransfer] reads that from the catalogue and offers no
/// Import control, so this screen does not repeat the reasoning.
class JobsScreen extends StatefulWidget {
  const JobsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<JobsScreen> createState() => _JobsScreenState();
}

class _JobsScreenState extends State<JobsScreen> {
  /// Below this width the table has no room for its columns, and the list is
  /// the whole screen — a tap opens the job over it, full width on a phone.
  static const _tableFrom = 900.0;

  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _jobs = [];
  List<JobStatus> _statusChoices = const [];
  String? _status;

  /// The money filter (queue 31): null is any. Only with Financials.
  String? _money;

  /// What each money chip would show, and Workiz's totals per currency, from
  /// the first page. Empty from a server that sent none: no numbers rather
  /// than zeros.
  Map<String, int> _moneyCounts = const {};
  Map<String, Map<String, int>> _moneyTotals = const {};

  bool get _seesMoney => widget.session.me?.can('jobs.financials') ?? false;

  /// Jobs or Estimates (queue 32). Estimates are money: their own permission
  /// and the Financials toggle. With only Jobs there is no bar at all.
  String _tab = 'jobs';

  /// A tab is built on its first open, so it fetches nothing before anyone
  /// looks, and then kept, as the Jobs tab is.
  final Set<String> _opened = {'jobs'};

  List<String> get _tabs => [
    'jobs',
    if (_seesEstimates) 'estimates',
  ];

  bool get _seesEstimates =>
      _seesMoney && (widget.session.me?.can('estimates.view') ?? false);

  // Discovered from the rows themselves rather than fetched, because every
  // job already carries its role (Task 5) and a catalogue call would only
  // duplicate data already on the page. Accumulated across loads: narrowing
  // to one role must not make the others disappear from the filter that
  // offered them.
  final Map<String, String> _roles = {};
  String? _roleId;

  /// The organization's types, sub-statuses and tags (`meta.labels`), for the
  /// filters here and the pickers in a job's panel.
  JobLabels _labels = const JobLabels();

  /// Type and tag filters: a job matches any type picked AND any tag picked.
  Set<String> _types = {};
  Set<String> _tags = {};

  /// The Where picker's choice (queue 25): null is anywhere, `none` the jobs no
  /// area has claimed, `f:<id>` a whole franchise, `a:<id>` one of its areas.
  /// One choice, because a franchise and an area inside it are two answers to
  /// the same question.
  String? _where;

  /// Every area the reader can reach, from the server's first page. Empty from
  /// a server that does not send them, and the picker then offers franchises
  /// only — exactly the Franchise picker it replaced.
  List<({String id, String name, String franchiseId})> _areas = const [];

  /// Jobs with no area, within every filter but Where's own.
  int? _unplaced;

  String? get _whereFranchise =>
      _where?.startsWith('f:') ?? false ? _where!.substring(2) : null;

  String? get _whereArea => _where == 'none'
      ? 'none'
      : (_where?.startsWith('a:') ?? false ? _where!.substring(2) : null);

  int _total = 0;
  // Page one only, until asked. The header used to claim a total the list did
  // not render, with no next-page control and nothing saying records were
  // hidden — which reads as data loss rather than as pagination.
  int _page = 1;
  int _lastPage = 1;

  // See customers_screen for both of these: a failed next page must not blank
  // the rows already being read, and only one request may be in flight at a
  // time whichever page it is for. The second matters more than it used to —
  // a page-one refresh no longer hides the "Load more" control behind a
  // spinner, so page one (which replaces the list) and page four (which appends
  // to it) can now be asked for together.
  String? _pageError;
  bool _fetching = false;

  /// Which request is the current one.
  ///
  /// A page-one load must be allowed to SUPERSEDE another page-one load rather
  /// than be dropped. Refusing it outright — which a plain in-flight guard does
  /// — loses the newer intent permanently: clearing the search box while the
  /// search is still in flight left the box empty and the list still filtered,
  /// with nothing scheduled to correct it.
  ///
  /// So every load takes a ticket, and a response that is no longer holding the
  /// current one is discarded. That also fixes the out-of-order case, where the
  /// slower of two answers lands last and wins.
  int _request = 0;

  /// From today on, before today, or every job.
  ///
  /// Upcoming first, because the list used to open on whatever was booked
  /// furthest ahead: April 2027 above the ~560 jobs due that week (queue 24).
  String _when = 'upcoming';

  /// How many jobs each chip would show, from the server's first page. Empty
  /// from a server that does not count, and the chips then carry no numbers
  /// rather than zeros — an absent count is not a count of none.
  Map<String, int> _statusCounts = const {};
  Map<String, int> _whenCounts = const {};

  /// Each day heading's total, keyed `YYYY-MM-DD` / `none`. Merged page by
  /// page, because every page brings days of its own.
  final Map<String, int> _dayCounts = {};

  /// The job open beside the list on a wide screen. Null shows the table.
  String? _openId;
  final _openDirty = ValueNotifier<bool>(false);

  @override
  void initState() {
    super.initState();
    _load();
    // A link to one job (a notification, or /jobs/{id} typed in the bar).
    DeepLinks.pending.addListener(_takeLink);
    WidgetsBinding.instance.addPostFrameCallback((_) => _takeLink());
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_takeLink);
    _search.dispose();
    _openDirty.dispose();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    // Appends only. Two appends at once duplicate rows, and an append landing
    // during a page-one refresh appends to a list that is being replaced. A
    // page-one load is never refused — it supersedes instead, via _request.
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. The
      // refetch after creating a job comes through here with rows on screen,
      // and they stay up until the new ones land.
      _loading = page == 1 && _jobs.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/jobs', {
      'page': '$page',
      'when': _when,
      // The reader's date, because "today" is theirs; the server compares it
      // with the franchise's wall clock (JobsController::index).
      'today': formatCalendarDay(DateTime.now()),
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      'status': ?_status,
      if (_seesMoney && _money != null) 'money': _money!,
      'assigned_role_id': ?_roleId,
      'franchise_id': ?_whereFranchise,
      'service_area_id': ?_whereArea,
      ..._listFilter('job_type', _types),
      ..._listFilter('tag', _tags),
    });

    // Superseded while this was in flight — a newer load owns the screen now,
    // and applying this answer would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _fetching = false;

      if (!response.ok) {
        // A first-page failure takes over the screen even when rows are still
        // in hand: they predate whatever the write did, and a refusal to
        // re-read them has to be said out loud rather than whispered.
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _jobs = page == 1 ? rows : [..._jobs, ...rows];

      for (final row in rows) {
        final role = row['assigned_role'] as Map<String, dynamic>?;
        if (role != null) _roles[role['id'] as String] = role['name'] as String;
      }

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _jobs.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;

      if (page == 1) {
        // The statuses where this person is looking, from the list itself: a
        // branch-only person cannot read organization settings (2026-10-27).
        final statuses = parseJobStatuses(meta?['statuses']);
        if (statuses.isNotEmpty) _statusChoices = statuses;
        _statusCounts = _counts(meta?['status_counts']);
        _whenCounts = _counts(meta?['when_counts']);
        _moneyCounts = _counts(meta?['money_counts']);
        _moneyTotals = {
          for (final e in ((meta?['money_totals'] as Map?) ?? const {}).entries)
            if (e.value is Map) '${e.key}': _counts(e.value),
        };
        _dayCounts.clear();

        final areas = meta?['service_areas'];
        if (areas is List) {
          _areas = [
            for (final a in areas)
              if (a is Map && a['id'] is String && a['name'] is String)
                (
                  id: a['id'] as String,
                  name: a['name'] as String,
                  franchiseId: '${a['franchise_id']}',
                ),
          ];
        }
        _labels = JobLabels.of(meta?['labels']) ?? _labels;

        final unplaced = meta?['unplaced_count'];
        _unplaced = unplaced is int ? unplaced : null;
      }
      _dayCounts.addAll(_counts(meta?['day_counts']));
    });
  }

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;
    if (tabs.length < 2) return _jobsTab(context);
    final selected = tabs.indexOf(_tab).clamp(0, tabs.length - 1);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-jobs-tabs'),
            labels: [
              for (final t in tabs)
                switch (t) {
                  'estimates' => ('Estimates', Icons.request_quote_outlined),
                  _ => ('Jobs', Icons.construction_outlined),
                },
            ],
            selected: selected,
            onSelected: (i) => setState(() {
              _tab = tabs[i];
              _opened.add(_tab);
            }),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          // Every tab stays alive: a look at the estimates must not drop a
          // docked job's unsaved edits, which the list itself refuses to
          // switch away from. Offstage rather than IndexedStack, for the
          // reason automations_screen gives: hidden, a tab leaves the
          // semantics tree too.
          child: Stack(
            fit: StackFit.expand,
            children: [
              Offstage(offstage: _tab != 'jobs', child: _jobsTab(context)),
              if (_seesEstimates)
                Offstage(
                  offstage: _tab != 'estimates',
                  child: _opened.contains('estimates')
                      ? EstimatesTab(api: widget.api, session: widget.session)
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _jobsTab(BuildContext context) {
    final me = widget.session.me!;

    // The permission first, because it is the one the button is named after.
    // Reach second, and stated as the ASSIGNMENT it is: telling somebody who
    // holds `jobs.create` that they lack permission sends them to ask for a
    // grant they already have. `franchises` carries reach rather than the whole
    // list — the server computes it — so this is not §4 reimplemented in Dart.
    //
    // Both sentences come from Me rather than from here, so this screen and the
    // 403 it would have got cannot come to describe the same refusal in two
    // different ways. They already had.
    final refused =
        me.refusal('jobs.create', 'add a job') ??
        (me.franchises.isEmpty ? me.nowhereToAct('add a job') : null);

    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= _tableFrom;

        return HeaderAboveList(
          header: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              PanelHeader(
                note: _note(me),
                // A Wrap rather than a Row, for the reason customers_screen
                // gives: two controls side by side overflow a narrow window.
                action: Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    // No `onImported`: jobs are export-only, so nothing this
                    // widget does can change the list behind it.
                    DataTransfer(
                      api: widget.api,
                      session: widget.session,
                      resource: 'jobs',
                    ),
                    Refused(
                      // §4 refuses in two places and they have different
                      // remedies. The screen used to test only the second, so
                      // somebody whose role lacks `jobs.create` was offered the
                      // button and got a 403; and the sentence it did have named
                      // "franchise" rather than whatever this organization calls
                      // one (§8).
                      reason: refused,
                      child: FilledButton.icon(
                        key: const ValueKey('tour-jobs-add'),
                        onPressed: refused == null ? _create : null,
                        icon: const Icon(Icons.add, size: 18),
                        label: const Text('Add job'),
                      ),
                    ),
                  ],
                ),
              ),
              // Chips on a wide screen, where they fit on a line and double as
              // the legend for the pills below. On a phone seven of them wrap to
              // three lines of chrome, so the same choice is a picker there.
              if (wide && _statusChoices.isNotEmpty) _statusChips(context),
              if (_seesMoney) _moneyChips(context, wide: wide),
              _filters(context, me, wide: wide),
              if (_seesMoney && _moneyTotals.isNotEmpty)
                _moneyTotalsLine(context, wide: wide),
            ],
          ),
          list: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _body(context, wide: wide),
          ),
        );
      },
    );
  }

  String _note(Me me) {
    if (_loading || _error != null) return 'Everything you can reach.';

    final reach =
        'across the ${me.organization.franchiseLabel.toLowerCase()}s you can reach';
    final which = switch (_when) {
      'upcoming' => ' upcoming',
      'past' => ' past',
      _ => '',
    };

    return _jobs.length < _total
        ? 'Showing ${_jobs.length} of ${formatCount(_total)}$which $reach.'
        : '${formatCount(_total)}$which $reach.';
  }

  /// Every status the organization uses, then any the jobs carry that it no
  /// longer lists — so the chips add up to the window's total.
  List<JobStatus> _chipStatuses() => [
    ..._statusChoices,
    for (final value in _statusCounts.keys)
      if (!_statusChoices.any((s) => s.value == value))
        JobStatus(value: value, label: jobStatusLabel(null, value)),
  ];

  /// Anywhere, the unplaced, then each franchise with its areas under it:
  /// "Illinois" is every area in Illinois, "Illinois · Chicago" one of them.
  /// Search reads the whole label, so typing "Denver" finds Nationwide's.
  List<SearchOption<String?>> _whereOptions(Me me) => [
    const SearchOption<String?>(value: null, label: 'Anywhere'),
    if (_areas.isNotEmpty)
      SearchOption<String?>(
        value: 'none',
        label: 'Not placed yet',
        detail: _unplaced == null
            ? 'Jobs no area has claimed'
            : 'Jobs no area has claimed — ${formatCount(_unplaced!)}',
      ),
    // "Main · Everdoors", by company then branch (Jose, 2026-10-21).
    for (final (f, label) in [
      for (final b in branchOptionsInView(me)) (b.branch, b.label),
    ]) ...[
      SearchOption<String?>(
        value: 'f:${f.id}',
        label: label,
        detail: _areas.any((a) => a.franchiseId == f.id)
            ? 'Every area in ${f.name}'
            : null,
      ),
      for (final a in _areas.where((a) => a.franchiseId == f.id))
        // A franchise whose only area is itself — Connecticut's "Connecticut"
        // — would offer the same word twice. The franchise row says it.
        if (!(a.name.toLowerCase() == f.name.toLowerCase() &&
            _areas.where((x) => x.franchiseId == f.id).length == 1))
          SearchOption<String?>(value: 'a:${a.id}', label: '${f.name} · ${a.name}'),
    ],
  ];

  Widget _statusChips(BuildContext context) {
    final counted = _statusCounts.isNotEmpty;
    final all = _statusCounts.values.fold(0, (a, b) => a + b);

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 10),
      child: Wrap(
        key: const ValueKey('tour-jobs-status'),
        spacing: 6,
        runSpacing: 6,
        children: [
          _chip(
            context,
            value: null,
            label: 'All',
            count: counted ? all : null,
          ),
          for (final s in _chipStatuses())
            _chip(
              context,
              value: s.value,
              label: s.label,
              count: counted ? (_statusCounts[s.value] ?? 0) : null,
              tone: toneForJobStatus(s.value),
            ),
        ],
      ),
    );
  }

  /// The money states beside the status chips they narrow with, each with
  /// its count (queue 31, Jose's 1 and 4). A desk wraps them; a phone swipes
  /// them, as it swipes nothing else here, because six chips wrap to three
  /// lines of chrome at 375.
  Widget _moneyChips(BuildContext context, {required bool wide}) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget chip(String? value, String label, {Tone? tone, int? count}) =>
        ChoiceChip(
          key: ValueKey('money-chip-${value ?? 'any'}'),
          avatar: tone == null ? null : ToneDot(tone),
          showCheckmark: false,
          selected: _money == value,
          label: Text.rich(
            TextSpan(
              text: label,
              children: [
                if (count != null)
                  TextSpan(
                    text: '  ${formatCount(count)}',
                    style: TextStyle(
                      color: muted,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
              ],
            ),
          ),
          onSelected: (_) {
            setState(() => _money = value);
            _load();
          },
        );

    final chips = [
      Text(
        'MONEY',
        style: theme.textTheme.labelSmall?.copyWith(
          color: muted,
          fontWeight: FontWeight.w800,
          letterSpacing: 0.6,
        ),
      ),
      chip(null, 'Any'),
      for (final (value, label, tone) in moneyStates)
        chip(value, label, tone: tone, count: _moneyCounts[value]),
    ];

    return Padding(
      key: const ValueKey('tour-jobs-money'),
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 10),
      child: wide
          ? Wrap(
              spacing: 6,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: chips,
            )
          : SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final c in chips)
                    Padding(
                      padding: const EdgeInsets.only(right: 6),
                      child: c,
                    ),
                ],
              ),
            ),
    );
  }

  /// Billed, paid, due and overdue over the jobs the other filters leave,
  /// per currency and never added across them (queue 31).
  Widget _moneyTotalsLine(BuildContext context, {required bool wide}) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget figure(String label, int minor, String currency, {Tone? tone}) =>
        Text.rich(
          TextSpan(
            text: '$label ',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
            children: [
              TextSpan(
                text: wide
                    ? formatMoney(minor, currency: currency, cents: false)
                    : shortMinor(minor, currency: currency),
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tone == null ? null : ToneColours.of(context, tone).ink,
                ),
              ),
            ],
          ),
          key: ValueKey('money-total-${label.toLowerCase()}-$currency'),
        );

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final MapEntry(key: currency, value: t) in _moneyTotals.entries)
            Wrap(
              spacing: 18,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                figure('Billed', t['total'] ?? 0, currency),
                figure('Paid', t['paid'] ?? 0, currency, tone: Tone.ok),
                figure('Due', t['due'] ?? 0, currency),
                figure('Overdue', t['overdue'] ?? 0, currency, tone: Tone.bad),
                if (wide)
                  Text(
                    'From Workiz · read only',
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
              ],
            ),
        ],
      ),
    );
  }

  /// A job's money state as a pill, or null for one with nothing billed.
  Widget? _moneyPill(Map<String, dynamic> job) {
    final pill = JobMoney.of(job['money'])?.pill(DateTime.now());

    return pill == null ? null : StatusPill(pill.$1, tone: pill.$2);
  }

  /// What is still due: red once it is late, a credit as the negative it is.
  Widget _dueCell(BuildContext context, Map<String, dynamic> job) {
    final money = JobMoney.of(job['money']);
    if (money == null || money.due == 0) return const Text('—');

    final tone = money.due < 0
        ? Tone.info
        : money.overdue(DateTime.now())
        ? Tone.bad
        : null;

    return Text(
      formatMoney(money.due, currency: money.currency),
      style: TextStyle(
        fontWeight: FontWeight.w700,
        color: tone == null ? null : ToneColours.of(context, tone).ink,
      ),
    );
  }

  /// The money a tile says after its title, in its tone: due, late, paid or
  /// owed back (queue 31, phone 4). A shared customer's tag stays beside it.
  Widget? _tileBadge(Map<String, dynamic> job) {
    const shared = Tag('Shared customer', warn: true);
    final isShared = !_ownedHere(job);
    final money = _seesMoney ? JobMoney.of(job['money']) : null;
    final pill = money?.pill(DateTime.now());
    if (money == null || pill == null) return isShared ? shared : null;

    String whole(int minor) =>
        formatMoney(minor, currency: money.currency, cents: false);
    final words = switch (pill.$1) {
      'Paid' => 'Paid',
      'Credit' => '${whole(money.credit)} credit',
      'Overdue' => '${whole(money.due)} overdue',
      _ => '${whole(money.due)} due',
    };
    final mark = Padding(
      padding: const EdgeInsets.only(left: 6),
      child: StatusPill(words, key: ValueKey('tile-money-${job['id']}'), tone: pill.$2),
    );

    return isShared
        ? Row(
            mainAxisSize: MainAxisSize.min,
            children: [mark, const SizedBox(width: 4), shared],
          )
        : mark;
  }

  Widget _chip(
    BuildContext context, {
    required String? value,
    required String label,
    int? count,
    Tone? tone,
  }) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    return ChoiceChip(
      key: ValueKey('status-chip-${value ?? 'all'}'),
      avatar: tone == null ? null : ToneDot(tone),
      // Not drawn beside the dot but ON it — see TabBarLike.
      showCheckmark: false,
      selected: _status == value,
      label: Text.rich(
        TextSpan(
          text: label,
          children: [
            if (count != null)
              TextSpan(
                text: '  ${formatCount(count)}',
                style: TextStyle(color: muted, fontWeight: FontWeight.w500),
              ),
          ],
        ),
      ),
      onSelected: (_) {
        setState(() => _status = value);
        _load();
      },
    );
  }

  Widget _filters(BuildContext context, Me me, {required bool wide}) {
    final franchises = me.franchisesInView;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
      child: Wrap(
        spacing: 12,
        runSpacing: 12,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: 320,
            child: ListSearch(
              key: const ValueKey('tour-jobs-search'),
              controller: _search,
              hint: 'Job number, title or customer',
              onChanged: (_) => setState(() {}),
              onSubmitted: (_) => _load(),
              onClear: () {
                            _search.clear();
                            _load();
                          },
            ),
          ),
          if (!wide && _statusChoices.isNotEmpty)
            SizedBox(
              // Two to a line at 375, not one each under the search box.
              width: wide ? 220 : 160,
              child: SearchPickerField<String?>(
                value: _status,
                label: 'Status',
                options: [
                  const SearchOption<String?>(value: null, label: 'Any status'),
                  for (final s in _chipStatuses())
                    SearchOption<String?>(value: s.value, label: s.label),
                ],
                onChanged: (value) {
                  setState(() => _status = value);
                  _load();
                },
              ),
            ),
          // One franchise with no areas is no choice, and a picker offering it
          // is chrome.
          if (franchises.length > 1 || _areas.isNotEmpty)
            SizedBox(
              // Two to a line at 375, not one each under the search box.
              width: wide ? 260 : 160,
              child: SearchPickerField<String?>(
                key: const Key('jobs-where'),
                value: _where,
                label: 'Where',
                options: _whereOptions(me),
                onChanged: (value) {
                  setState(() => _where = value);
                  _load();
                },
              ),
            ),
          // The same control as status, for the same reason: it sits in
          // this Wrap rather than opening over the form, and its choices
          // are discovered from the rows already on screen rather than
          // fetched, since every job already carries its role (Task 5).
          if (_roles.isNotEmpty)
            SizedBox(
              // Two to a line at 375, not one each under the search box.
              width: wide ? 220 : 160,
              child: SearchPickerField<String?>(
                value: _roleId,
                label: 'Role',
                options: [
                  const SearchOption<String?>(value: null, label: 'Any role'),
                  ..._roles.entries.map(
                    (role) => SearchOption<String?>(
                      value: role.key,
                      label: role.value,
                    ),
                  ),
                ],
                onChanged: (value) {
                  setState(() => _roleId = value);
                  _load();
                },
              ),
            ),
          if (_labels.jobTypes.isNotEmpty || _types.isNotEmpty)
            SizedBox(
              width: wide ? 220 : 160,
              child: SearchMultiPickerField<String>(
                key: const Key('jobs-type-filter'),
                values: _types,
                label: 'Type',
                hint: 'Any type',
                options: [
                  for (final t in {..._labels.jobTypes, ..._types})
                    SearchOption(value: t, label: t),
                ],
                onChanged: (picked) {
                  setState(() => _types = picked);
                  _load();
                },
              ),
            ),
          if (_labels.tags.isNotEmpty || _tags.isNotEmpty)
            SizedBox(
              width: wide ? 220 : 160,
              child: SearchMultiPickerField<String>(
                key: const Key('jobs-tag-filter'),
                values: _tags,
                label: 'Tags',
                hint: 'Any tag',
                options: [
                  for (final t in {..._labels.tags, ..._tags})
                    SearchOption(value: t, label: t),
                ],
                onChanged: (picked) {
                  setState(() => _tags = picked);
                  _load();
                },
              ),
            ),
          _window(wide: wide),
        ],
      ),
    );
  }

  Widget _window({required bool wide}) {
    // Counts only where there is room for them; on a phone three segments
    // with four-digit numbers do not fit 375 and the picker above says enough.
    String label(String word, int? n) =>
        wide && n != null ? '$word  ${formatCount(n)}' : word;

    final upcoming = _whenCounts['upcoming'];
    final past = _whenCounts['past'];

    return SegmentedButton<String>(
      key: const Key('jobs-window'),
      showSelectedIcon: false,
      segments: [
        ButtonSegment(
          value: 'upcoming',
          label: Text(label('Upcoming', upcoming)),
        ),
        ButtonSegment(value: 'past', label: Text(label('Past', past))),
        ButtonSegment(
          value: 'all',
          label: Text(
            label(
              'All',
              upcoming == null || past == null ? null : upcoming + past,
            ),
          ),
        ),
      ],
      selected: {_when},
      onSelectionChanged: (chosen) {
        setState(() => _when = chosen.first);
        _load();
      },
    );
  }

  Widget _body(BuildContext context, {required bool wide}) {
    if (_jobs.isEmpty) return Center(child: Text(_emptyCopy()));

    final groups = groupJobsByDay(
      _jobs,
      today: DateTime.now(),
      counts: _dayCounts,
    );
    final footer = _page < _lastPage ? _loadMore() : null;

    if (!wide) {
      return GroupedList<Map<String, dynamic>>(
        key: const ValueKey('tour-jobs-list'),
        groups: groups,
        idOf: _id,
        tile: _tile,
        onOpen: _openOver,
        footer: footer,
      );
    }

    if (_openId == null) {
      return GroupedTable<Map<String, dynamic>>(
        key: const ValueKey('tour-jobs-list'),
        columns: _columns(context),
        groups: groups,
        idOf: _id,
        onOpen: _dock,
        footer: footer,
      );
    }

    final scheme = Theme.of(context).colorScheme;

    return Container(
      margin: const EdgeInsets.fromLTRB(24, 4, 24, 24),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      clipBehavior: Clip.antiAlias,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 380,
            child: GroupedList<Map<String, dynamic>>(
              groups: groups,
              idOf: _id,
              tile: _tile,
              onOpen: _dock,
              selectedId: _openId,
              footer: footer,
            ),
          ),
          VerticalDivider(width: 1, color: scheme.outlineVariant),
          Expanded(
            child: JobDetailPanel(
              // A new job is a new panel: its fields, its load, its dirt.
              key: ValueKey('open-$_openId'),
              api: widget.api,
              session: widget.session,
              id: _openId!,
              labels: _labels,
              onDone: _undock,
              dirty: _openDirty,
            ),
          ),
        ],
      ),
    );
  }

  Widget _loadMore() => Padding(
    padding: const EdgeInsets.symmetric(vertical: 12),
    child: Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_pageError != null) ...[
            ErrorNote(_pageError!),
            const SizedBox(height: 8),
          ],
          TextButton(
            onPressed: _fetching ? null : () => _load(page: _page + 1),
            child: Text(
              _fetching
                  ? 'Loading…'
                  : _pageError != null
                  ? 'Try again'
                  : 'Load more (${formatCount(_total - _jobs.length)} left)',
            ),
          ),
        ],
      ),
    ),
  );

  List<TableColumn<Map<String, dynamic>>> _columns(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    return [
      // The type after the number (queue 118, Jose's 1), and room for it only
      // when a loaded job has one.
      TableColumn(
        'Job',
        width: _jobs.any((job) => jobType(job) != null) ? 220 : 104,
        cell: (_, job) => Row(
          children: [
            Flexible(
              child: Text('${job['number']}', style: TextStyle(color: muted)),
            ),
            if (jobType(job) != null) ...[
              const SizedBox(width: 6),
              Flexible(child: JobTypeLabel(jobType(job)!)),
            ],
          ],
        ),
      ),
      // Workiz's own "Job #" beside EzBizi's (Jose, 2026-09-29). Only when a
      // loaded job has one: other organizations have no Workiz, and a column
      // of dashes on every row is dead weight (Customers' review).
      if (_jobs.any((job) => job['workiz_job_number'] != null))
        TableColumn(
          'Workiz',
          // Workiz numbers are nine digits since 2025 ("#200021684"): 76-78 px
          // at 14 px in Segoe UI and Arial, measured. At 80 the cell left 68
          // and cut every one to "#20002…" (2026-10-06).
          width: 100,
          cell: (_, job) => Text(
            job['workiz_job_number'] == null
                ? '—'
                : '#${job['workiz_job_number']}',
            style: TextStyle(color: muted),
          ),
        ),
      TableColumn('Title', flex: 3, cell: (_, job) => _titleCell(job)),
      TableColumn(
        'Customer',
        flex: 3,
        cell: (_, job) => Text('${_customer(job)['name'] ?? ''}'),
      ),
      // Which company, only when looking at the whole organization (Jose,
      // 2026-10-19); one company selected already says it.
      if (widget.session.me?.scope.type == 'organization')
        TableColumn(
          'Company',
          flex: 2,
          cell: (_, job) => Text(
            '${(job['company'] as Map?)?['name'] ?? '—'}',
            overflow: TextOverflow.ellipsis,
          ),
        ),
      // Franchise and area in one column (queue 25): "Illinois · Chicago".
      TableColumn(
        'Where',
        flex: 3,
        cell: (_, job) => WhereLabel(job, placeable: _placeable(job)),
      ),
      // The time alone: the heading above already says which day.
      TableColumn('Time', width: 88, cell: (_, job) => Text(_time(job) ?? '—')),
      TableColumn(
        'Status',
        width: 180,
        cell: (_, job) => StatusPill(
          _statusLabel(job),
          tone: toneForJobStatus('${job['status'] ?? ''}'),
        ),
      ),
      // Two tags then "+N", all on hover. Only when a loaded job has any.
      if (_jobs.any((job) => jobTags(job).isNotEmpty))
        TableColumn(
          'Tags',
          flex: 3,
          cell: (_, job) => Align(
            alignment: AlignmentDirectional.centerStart,
            child: JobTagsSummary(jobTags(job)),
          ),
        ),
      // Only with the Financials toggle (Jose, 2026-09-29). Without it the
      // server sends no amount, and a column of dashes would read as "free".
      // Workiz's money where the job has it (queue 31): its state and what is
      // still due; a job EzBizi priced itself shows its own amount instead.
      // Only when a loaded job has some, as the Workiz column: two empty
      // columns were squeezing Title and Where (UI review, 2026-10-08).
      if (_seesMoney &&
          _jobs.any((job) => job['money'] != null || job['amount'] != null)) ...[
        TableColumn(
          'Money',
          width: 120,
          cell: (_, job) =>
              _moneyPill(job) ??
              Text(
                listAmount(job['amount']) ?? '—',
                style: TextStyle(color: muted),
              ),
        ),
        TableColumn(
          'Due',
          width: 110,
          numeric: true,
          cell: (context, job) => _dueCell(context, job),
        ),
      ],
    ];
  }

  Widget _titleCell(Map<String, dynamic> job) {
    final role = (job['assigned_role'] as Map?)?['name'] as String?;

    return Row(
      children: [
        Flexible(
          child: Text(
            '${job['title'] ?? ''}',
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
        // Null for the common case at booking time — a job has no performer
        // yet and often no role either — so this must not draw an empty tag
        // for the row that names nobody (§12.1). Flexible because a role name
        // is organization-chosen text with no length limit worth trusting.
        if (role != null && role.isNotEmpty)
          Flexible(child: Tag(role, key: const Key('job-role-tag'))),
        // So it is obvious before anyone edits anything that this work is
        // for a customer another franchise is accountable for (§5.2).
        if (!_ownedHere(job)) const Tag('Shared customer', warn: true),
      ],
    );
  }

  Widget _tile(BuildContext context, Map<String, dynamic> job) {
    final role = (job['assigned_role'] as Map?)?['name'] as String?;

    return TwoLineTile(
      title: '${job['title'] ?? ''}',
      badge: _tileBadge(job),
      topRight: _time(job),
      subtitle: [
        _customer(job)['name'],
        whereText(job),
        role,
      ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
      subtitleBadge: jobWhere(job).area == null && _placeable(job)
          ? const NotPlacedMark()
          : null,
      bottomRight: StatusPill(
        _statusLabel(job),
        tone: toneForJobStatus('${job['status'] ?? ''}'),
      ),
      // Phone 4: the type, then the first tag and "+N".
      footer: jobType(job) == null && jobTags(job).isEmpty
          ? null
          : Row(
              children: [
                if (jobType(job) != null)
                  Flexible(child: JobTypeLabel(jobType(job)!)),
                if (jobTags(job).isNotEmpty)
                  Flexible(child: JobTagsSummary(jobTags(job), shown: 1)),
              ],
            ),
    );
  }

  static String _id(Map<String, dynamic> job) => job['id'] as String;

  /// Its franchise has areas to place it in — see [WhereLabel.placeable].
  bool _placeable(Map<String, dynamic> job) {
    final franchise = (job['franchise'] as Map<String, dynamic>?)?['id'];
    return _areas.any((a) => a.franchiseId == franchise);
  }

  static Map<String, dynamic> _customer(Map<String, dynamic> job) =>
      job['customer'] as Map<String, dynamic>? ?? const {};

  static bool _ownedHere(Map<String, dynamic> job) =>
      _customer(job)['owned_by_this_franchise'] as bool? ?? true;

  static String _statusLabel(Map<String, dynamic> job) =>
      job['status_label'] as String? ?? '${job['status'] ?? ''}';

  /// The booked time as the franchise booked it — the wall clock, never the
  /// reader's, which is what the job's own panel shows too.
  String? _time(Map<String, dynamic> job) {
    final wall = parseWallDateTime(job['scheduled_for'] as String?);

    return wall == null
        ? null
        : widget.session.me!.display.formatTime(wall.hour, wall.minute);
  }

  /// Opens the job a [DeepLink] names: beside the list when there is room
  /// for it, over the list on a phone — the same two ways a tap opens one.
  void _takeLink() {
    if (!mounted || DeepLinks.pending.value?.module != 'jobs') return;
    final link = DeepLinks.take('jobs')!;
    final row = <String, dynamic>{'id': link.id};
    if (MediaQuery.sizeOf(context).width >= _tableFrom) {
      _dock(row);
    } else {
      _openOver(row);
    }
  }

  /// Open beside the list. Refused while the open job has unsaved changes:
  /// the next row is one click away in this layout, and that click would
  /// otherwise discard somebody's edit without a word.
  void _dock(Map<String, dynamic> job) {
    final id = _id(job);
    if (id == _openId) return;

    if (_openId != null && _openDirty.value) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Save or cancel the changes to this job first.'),
        ),
      );
      return;
    }

    setState(() => _openId = id);
  }

  void _undock(bool changed) {
    _openDirty.value = false;
    setState(() => _openId = null);
    if (changed) _load();
  }

  /// Open over the list — on a phone, where there is no room beside it.
  Future<void> _openOver(Map<String, dynamic> job) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => JobDetailPanel(
        api: widget.api,
        session: widget.session,
        id: _id(job),
        labels: _labels,
      ),
    );

    if (changed == true && mounted) _load();
  }

  Future<void> _create() async {
    final booked = await showBookJobPanel(
      context,
      api: widget.api,
      session: widget.session,
    );

    if (booked != null && mounted) _load();
  }

  String _emptyCopy() {
    final term = _search.text.trim();

    if (term.isEmpty &&
        _status == null &&
        _roleId == null &&
        _where == null &&
        _types.isEmpty &&
        _tags.isEmpty) {
      return switch (_when) {
        // Not "No jobs yet" — there may be thousands, all of them behind Past.
        'upcoming' when (_whenCounts['past'] ?? 0) > 0 =>
          'Nothing coming up. Earlier jobs are under Past.',
        'past' => 'Nothing before today.',
        _ => 'No jobs yet.',
      };
    }

    if (RegExp(r'^[A-Za-z][A-Za-z0-9]*-\d+(?:-\d+)?$').hasMatch(term)) {
      return 'Job not found.';
    }

    return 'Nothing matches "$term".';
  }
}

/// A several-value filter as the list reads it: `key[]` once per value, and
/// the single `key` too when there is exactly one, which is the form the
/// server took before it read lists.
Map<String, Object> _listFilter(String key, Set<String> values) => {
  if (values.isNotEmpty) '$key[]': values.toList(),
  if (values.length == 1) key: values.first,
};

/// A count map from the server, or nothing. Tolerates the `[]` an empty PHP
/// array encodes as — a map read as a list would throw.
Map<String, int> _counts(Object? raw) => raw is Map
    ? {
        for (final entry in raw.entries)
          if (entry.value is int) '${entry.key}': entry.value as int,
      }
    : const {};
