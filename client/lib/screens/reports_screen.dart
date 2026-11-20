import '../widgets/module_page_header.dart';
import '../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../core/timestamps.dart';
import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/module_screens.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/date_range_picker.dart';
import '../widgets/error_note.dart';
import '../widgets/status_pill.dart';
import '../widgets/search_picker.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/tab_help.dart';
import 'hr/work_hours_reports_tab.dart';
import 'reports/experimental_call_report.dart';
import 'reports/marketing_report.dart';
import 'reports/meta_ads_report.dart';
import 'reports/overbooking_report_panel.dart';
import 'reports/report_builder.dart';
import 'reports/report_dates.dart';
import 'reports/report_range.dart';
import 'reports/scoring_report.dart';
import 'reports/thumbtack_report.dart';
import 'reports/job_status_report.dart';
import 'reports/jobs_report.dart';
import 'reports/report_run_view.dart';
import 'reports/report_workspace.dart';
import 'reports/work_hours_report_panel.dart';
import '../widgets/heading_pin.dart';

/// Reports (spec `docs/superpowers/specs/2026-09-10-reports-design.md`).
///
/// ── Two surfaces, not one that adapts ────────────────────────────
///
/// The builder works at any width (UI queue 72, 2026-10-09): above
/// [ReportBuilder.sideBySide] the fields sit beside the report; narrower, a
/// phone stacks the preview, the columns and the options. It replaces the
/// earlier ruling that a phone only RUNS what a desk built, because the
/// builder now shows its own result as it is made, so a report started on a
/// phone can be finished there.
///
/// ── Build and run are different surfaces, not one with a mode ────
///
/// Without `reports.build` there is no New control anywhere — not a disabled
/// one. A greyed builder tells somebody the app is for other people; a list of
/// what they can run tells them what they have.
///
/// ── The empty states are opposite facts ──────────────────────────
///
/// "No reports built yet" and "this report found nothing" mean different
/// things, and a refusal is a third thing again. The copy below is UI/UX's,
/// verbatim (`89398365`), including the two DIFFERENT empty-library sentences
/// for somebody who can build and somebody who cannot — the second says
/// another person makes the first report, so it does not read as this reader's
/// own dead end.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen({
    super.key,
    required this.api,
    required this.session,
    this.now = DateTime.now,
  });

  /// The clock, so a test can say what today is.
  final DateTime Function() now;

  final ApiClient api;
  final Session session;

  /// Guessed from a static gallery rather than measured against the real tree.
  /// Check it at 900–1100 with the tree open beside a six-column report before
  /// trusting it.
  static const double buildAbove = 900;

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  /// ONE date range for every tab that reads one (review 2026-10-30, item
  /// 14, Jose approved): Last 7 days on first open, then the person's last
  /// choice for the session, carried between tabs - and back into Reports
  /// after leaving it (Jose, 2026-11-16), so it lives beyond this State.
  /// Kept against the Session object, not this State.
  static final _lastSpan = Expando<DateSpan>('reports span');
  late DateSpan _span =
      _lastSpan[widget.session] ?? ReportDates.quick(_today)['1 week']!;

  void _setSpan(DateSpan span) =>
      setState(() => _span = _lastSpan[widget.session] = span);

  DateTime get _today => DateSpan.single(widget.now()).from;

  /// The tabs that take a range; the list, Builder and Workspace do not.
  static const _ranged = {
    'Performance',
    'Scoring',
    'Marketing Reports',
    'Meta',
    'Thumbtack',
    'Jobs',
  };

  /// Reports · Builder · Experimental. The builder is a DESTINATION now
  /// rather than a mode the list drops into, which is what Jose asked for and
  /// is also the honest shape: building and running are different jobs, and
  /// the chips say so before anybody clicks.
  int _chip = 0;

  /// The pin on every tab's table headings, held here so the tabs answer to
  /// one choice; saved as `reports.pin_headings` (Jose, 2026-11-16).
  bool? _headingsPinned;

  SavedReportSummary? _open;
  String? _confirming;
  String? _deleteError;
  List<SavedReportSummary>? _reports;
  String? _error;
  bool _loading = true;

  /// Work Hours' saved reports (queue 33, Jose's 1 and 4), for a reader who
  /// may see them there: listed beside the Builder's, still edited in Work
  /// Hours. Its own list under its own grant, so a refusal of either never
  /// blanks the other.
  List<Map<String, dynamic>>? _hours;
  List<Map<String, dynamic>> _hourRoles = const [];
  List<Map<String, dynamic>> _hourPeople = const [];
  String? _hoursError;

  /// Every report, the Builder's, or Work Hours'.
  String? _kind;

  /// The library's search, over what a row shows. The controller keeps the
  /// typed words in the field when a report opens and the library returns.
  String _query = '';
  final _search = TextEditingController();

  /// The Work Hours report open beside the list, or in place on a phone.
  String? _openHours;

  bool get _seesHours =>
      widget.session.me?.can('work_hours.reports.view') ?? false;

  /// Scheduling's reports - today the daily Overbooking warning (Jose,
  /// 2026-10-01: "it should show there"). Its own list under its own grant,
  /// the same as Work Hours'.
  List<Map<String, dynamic>>? _scheduling;
  String? _schedulingError;

  bool get _seesScheduling =>
      widget.session.me?.can('scheduling.rescheduling.view') ?? false;

  /// The call a missed-call notification was about, opened in Performance
  /// (Bar, via Jose, 2026-10-12: a notification opens "the exact thing").
  String? _openCall;

  @override
  void initState() {
    super.initState();
    _takeLink();
    DeepLinks.pending.addListener(_linkArrived);
    // Only somebody holding the library tab asks for the library. Asking
    // anyway drew the server's refusal in a pane they were never given.
    if (_mayOpen('library')) {
      _load();
    } else {
      _loading = false;
    }
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_linkArrived);
    _search.dispose();
    super.dispose();
  }

  /// A notification's call, if one is waiting for Reports: to Performance,
  /// when this person holds it, with that call open. Without the tab the link
  /// is still consumed, so it cannot open later behind their back.
  void _takeLink() {
    final link = DeepLinks.take('reports');
    if (link?.type != 'call') return;

    final canExperiment = widget.session.me?.can('reports.build') ?? false;
    final performance = _indexOf(
      'Performance',
      canBuildHere: canExperiment,
      canExperiment: canExperiment,
    );
    if (performance == null) return;

    _chip = performance;
    _openCall = link!.id;
  }

  void _linkArrived() => setState(_takeLink);

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
      _hoursError = null;
      _schedulingError = null;
    });

    // All at once, and the library waits for both: "No reports built yet"
    // must not show while the Work Hours list is still on its way.
    final built = widget.api.get('/reports');
    final hours = _seesHours ? widget.api.get('/hr/time-clock/reports') : null;
    final scheduling = _seesScheduling
        ? widget.api.get('/scheduling/reports')
        : null;
    final response = await built;
    final hoursResponse = await hours;
    final schedulingResponse = await scheduling;

    if (!mounted) return;

    List<Map<String, dynamic>> list(Object? raw) => [
      for (final row in (raw as List?) ?? const [])
        if (row is Map) Map<String, dynamic>.from(row),
    ];

    setState(() {
      _loading = false;

      if (response.ok) {
        _reports = (response.body['data'] as List<dynamic>? ?? const [])
            .cast<Map<String, dynamic>>()
            .map(SavedReportSummary.fromJson)
            .toList();
      } else {
        // The server's own sentence, not a replacement. A refusal here
        // explains that the grant lives on a role, which no client-side
        // wording knows.
        _error = response.message;
      }

      if (schedulingResponse != null) {
        if (schedulingResponse.ok) {
          _scheduling = list(schedulingResponse.body['data']);
        } else {
          _schedulingError = schedulingResponse.message;
        }
      }

      if (hoursResponse == null) return;
      if (hoursResponse.ok) {
        _hours = list(hoursResponse.body['data']);
        _hourRoles = list(hoursResponse.body['roles']);
        _hourPeople = list(hoursResponse.body['people']);
      } else {
        _hoursError = hoursResponse.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final canExperiment = widget.session.me?.can('reports.build') ?? false;
        // Any width since UI queue 72: a phone has its own stacked builder.
        final canBuildHere = canExperiment;

        final phone = constraints.maxWidth < 600;
        final chips = _chips(
          canBuildHere: canBuildHere,
          canExperiment: canExperiment,
        );
        final labels = [for (final c in chips) (c.$1, c.$2)];
        void select(int i) => setState(() {
          _chip = i;
          _open = null;
          _openHours = null;
          _confirming = null;
        });
        // A tab drawn by its own widget brings its own 16px; the library, the
        // Builder and Performance get the shell's, so every tab sits 16 in
        // (UI queue 87: five of them sat 32).
        final ownPadding = _chip < chips.length && chips[_chip].$3 != null;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // The heading row only while a report is open: with
                  // nothing in it, its button's height stood as a gap
                  // between the title and the chips (Mia, 2026-10-02).
                  if (_open != null) ...[
                    _header(context, canBuildHere: canBuildHere),
                    const SizedBox(height: 12),
                  ],
                  Row(
                    children: [
                      Expanded(
                        // Phone 4: one picker naming the tab and listing all
                        // of them, for a strip that showed two and hid seven.
                        child: phone && labels.isNotEmpty
                            ? OpenTabMark(
                                labels[_chip < labels.length ? _chip : 0].$1,
                                child: SearchPickerField<int>(
                                  key: const ValueKey('tour-reports-tabs'),
                                  value: _chip < labels.length ? _chip : 0,
                                  searchFrom: 99,
                                  options: [
                                    for (final (i, (label, icon))
                                        in labels.indexed)
                                      SearchOption<int>(
                                        value: i,
                                        label: label,
                                        child: Row(
                                          children: [
                                            Icon(icon, size: 18),
                                            const SizedBox(width: 10),
                                            Text(label),
                                          ],
                                        ),
                                      ),
                                  ],
                                  onChanged: (i) {
                                    if (i != null) select(i);
                                  },
                                ),
                              )
                            : TabBarLike(
                                key: const ValueKey('tour-reports-tabs'),
                                labels: labels,
                                selected: _chip,
                                onSelected: select,
                              ),
                      ),
                      ?_newReport(canBuildHere: canBuildHere, compact: phone),
                    ],
                  ),
                ],
              ),
            ),
            // Performance draws the same bar itself, with its Sources and
            // refresh on the row.
            if (_ranged.contains(
                  _chip < labels.length ? labels[_chip].$1 : null,
                ) &&
                labels[_chip].$1 != 'Performance')
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                child: KeyedSubtree(
                  // The tours' anchor, as each tab's own bar had it.
                  key: const ValueKey('tour-report-dates'),
                  child: ReportDates(
                    // Re-made on a change, so its own copy follows this one.
                    key: ValueKey(_span),
                    inline: true,
                    initial: _span,
                    today: _today,
                    formats:
                        widget.session.me?.display ?? DisplayFormats.fallback,
                    onChanged: _setSpan,
                  ),
                ),
              ),
            if (!ownPadding) const SizedBox(height: 12),
            Expanded(
              child: ReportRange(
                span: _span,
                onChanged: _setSpan,
                child: HeadingPin(
                  pinned:
                      _headingsPinned ??
                      widget.session.me?.reportHeadingsPinned ??
                      true,
                  onChanged: (pinned) {
                    setState(() => _headingsPinned = pinned);
                    // Not awaited: the screen is already right, and a write the
                    // server refuses comes back through Session's refresh.
                    widget.session.setPreference(
                      'reports.pin_headings',
                      pinned,
                    );
                  },
                  child: ownPadding
                      ? _body(context, constraints)
                      : Padding(
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                          child: _body(context, constraints),
                        ),
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  /// Builder is absent rather than disabled where it cannot be used - without
  /// `reports.build`. The same rule as the New report control: a chip leading
  /// to a refusal tells somebody the app is for other people.
  ///
  /// Experimental follows the same rule on the PERMISSION but not on the
  /// width. Its endpoint is gated on `reports.build` because the report names
  /// every member of staff and how many calls they took, so a chip shown to
  /// somebody holding only `reports.view` would lead straight to a refusal.
  /// It stays on a phone because reading a table is not building one.
  ///
  /// ── THE MODULES DRAWN INSIDE THIS ONE ───────────────────────────
  ///
  /// A module with `parent: 'reports'` gets NO sidebar row — `_hasDestination`
  /// in main.dart returns false for any parented module, by design, because it
  /// is meant to appear as a chip inside its parent's screen. Nothing in the
  /// client walks `childrenOf`; each parent draws its own children.
  ///
  /// So a hand-written strip silently swallows them. Google Ads shipped
  /// registered, permissioned, deployed, with a working endpoint and a screen
  /// in `moduleScreens` — and reachable by nobody, because this list was three
  /// hardcoded entries. Its registry test asserted the parent, the label, the
  /// order and the permissions, and passed the whole time.
  ///
  /// The loop is Marketing's, verbatim in shape. `reachableModules` is doing
  /// real work rather than belt-and-braces: `ads_performance.view` is a
  /// separate grant from `reports.view` and somebody can hold either without
  /// the other.
  List<(String, IconData, Widget Function()?)> _chips({
    required bool canBuildHere,
    required bool canExperiment,
  }) {
    final me = widget.session.me;
    // Download a table as Excel or CSV (Merav, 2026-11-19).
    final canExport = me?.can('reports.export') ?? false;

    return [
      /*
       * ── EACH TAB IS ITS OWN GRANT NOW (Jose, 2026-09-17) ──────
       *
       * "i should be able to grant access by sub tab as well ... so this
       * applies to every tab and it's sub tabs".
       *
       * The two conditions are ANDed rather than swapped. `canBuildHere` is
       * still what decides whether building is possible at all; the tab grant
       * decides whether this person is shown the pane. Replacing the first with
       * the second would hand the Builder to somebody who cannot save anything
       * out of it.
       *
       * Absent `/me` answers NO. A null session is a load that has not
       * finished, and the safe reading of "I do not know yet" is to draw
       * nothing rather than everything.
       */
      if (_mayOpen('library')) ('Reports', Icons.list_alt_outlined, null),
      if (canBuildHere && _mayOpen('builder'))
        ('Report Builder', Icons.build_outlined, null),
      // The tab alone (Jose, 2026-10-12: a Lead Manager given Performance
      // could not see it, because it also asked for Build). What inside it
      // changes data asks for Build itself: ExperimentalCallReport.canEdit.
      if (_mayOpen('experimental'))
        ('Performance', Icons.science_outlined, null),
      // Each dispatcher as a card, ranked by AI's call score (Jose,
      // 2026-10-13; UI queue 85). Its own grant: Administrator and Lead
      // Manager only, for now (Jose).
      if (_mayOpen('scoring'))
        (
          'Scoring',
          Icons.leaderboard_outlined,
          () => ScoringReport(
            api: widget.api,
            span: _span,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
            canWeigh:
                widget.session.me?.can('reports.scoring_weights') ?? false,
            canCorrect: widget.session.me?.can('reports.handles') ?? false,
          ),
        ),
      // Ad cost against jobs and profit (Jose, 2026-10-12). Its profit is the
      // P&L's margin, so it goes where the P&L can.
      if (_mayOpen('marketing') &&
          (me?.reachableModules.any((m) => m.key == 'profit_loss') ?? false))
        (
          // "Marketing Reports" (Jose, 2026-11-16): not the Marketing module.
          'Marketing Reports',
          Icons.campaign_outlined,
          () => MarketingReport(
            api: widget.api,
            span: _span,
            canExport: canExport,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
          ),
        ),
      // Facebook ad spend (Jose, 2026-10-12). Editing the campaign mapping
      // asks for Build, as the server does.
      if (_mayOpen('meta'))
        (
          'Meta',
          Icons.campaign_outlined,
          () => MetaAdsReport(
            api: widget.api,
            span: _span,
            canExport: canExport,
            canEdit: canBuildHere,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
          ),
        ),
      // Thumbtack lead spend (Jose, 2026-10-13).
      if (_mayOpen('thumbtack'))
        (
          'Thumbtack',
          Icons.request_quote_outlined,
          () => ThumbtackReport(
            api: widget.api,
            span: _span,
            canExport: canExport,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
          ),
        ),
      // Every job created in the dates, with both ids (Jose, 2026-11-11).
      if (_mayOpen('jobs'))
        (
          'Jobs',
          Icons.work_outline,
          () => JobsReport(
            api: widget.api,
            span: _span,
            canExport: canExport,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
          ),
        ),
      // Jobs cancelled and jobs pending, by the business's statuses (Shoval, 2026-11-18).
      if (_mayOpen('cancelled'))
        (
          'Cancelled jobs',
          Icons.event_busy_outlined,
          () => JobStatusReport(
            key: const ValueKey('report-cancelled'),
            api: widget.api,
            span: _span,
            pending: false,
            canExport: canExport,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
          ),
        ),
      if (_mayOpen('pending'))
        (
          'Pending jobs',
          Icons.pending_actions_outlined,
          () => JobStatusReport(
            key: const ValueKey('report-pending'),
            api: widget.api,
            span: _span,
            pending: true,
            canExport: canExport,
            formats: widget.session.me?.display ?? DisplayFormats.fallback,
          ),
        ),
      // Pick a report, preview it, export, approve or send it (takeover
      // row 10, UI queue 157, Jose 2026-10-29).
      if (_mayOpen('workspace'))
        (
          'Workspace',
          Icons.dashboard_customize_outlined,
          () => ReportWorkspace(api: widget.api, session: widget.session),
        ),
      if (me != null)
        for (final module in me.reachableModules)
          if (module.parent == 'reports')
            if (moduleScreens[module.key] case final screen?)
              (
                module.label,
                screen.icon,
                () => screen.build(widget.api, widget.session),
              ),
    ];
  }

  /// Whether this person may open one of Reports' own tabs.
  ///
  /// The child MODULES below — Google Ads, Profit & Loss — are not tabs and do
  /// not come through here: each is a module with a grant of its own, and
  /// `reachableModules` has already decided them.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('reports.$tab.view') ?? false;

  /// The chips shift when a tab is not granted, so a stored index would point
  /// at the wrong destination. Resolved by NAME rather than by number - and an
  /// index past the end is NOTHING, not the library. Falling back to
  /// 'Reports' drew the library for a role that does not hold it (deploy desk
  /// audit, 2026-09-29).
  String? _chipName({required bool canBuildHere, required bool canExperiment}) {
    final chips = _chips(
      canBuildHere: canBuildHere,
      canExperiment: canExperiment,
    );

    // Built-in tabs only: a child module carries its own builder and is
    // drawn before this is asked, so a module labelled "Builder" cannot
    // impersonate the tab.
    return _chip < chips.length && chips[_chip].$3 == null
        ? chips[_chip].$1
        : null;
  }

  /// Where a built-in tab sits in the strip right now, or null if absent.
  int? _indexOf(
    String name, {
    required bool canBuildHere,
    required bool canExperiment,
  }) {
    final chips = _chips(
      canBuildHere: canBuildHere,
      canExperiment: canExperiment,
    );
    final i = chips.indexWhere((c) => c.$1 == name && c.$3 == null);

    return i < 0 ? null : i;
  }

  Widget _header(BuildContext context, {required bool canBuildHere}) {
    return Row(
      children: [
        if (_open != null)
          IconButton(
            onPressed: () => setState(() => _open = null),
            icon: const Icon(Icons.arrow_back),
            tooltip: 'All reports',
          ),
        // ONLY the open report's name. The shell already draws this module's
        // label above every screen (`_titled` -> ModulePageHeader), so a second
        // "Reports" under the first was this screen saying its own name twice.
        // Marketing has no heading of its own for exactly that reason.
        Expanded(
          child: _open == null
              ? const SizedBox.shrink()
              : Text(
                  _open!.name,
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
        ),
      ],
    );
  }

  /// New report, beside the chips on the library (Mia, 2026-10-02).
  Widget? _newReport({required bool canBuildHere, bool compact = false}) {
    // canExperiment: canBuildHere already implies reports.build.
    final builder = _indexOf(
      'Report Builder',
      canBuildHere: canBuildHere,
      canExperiment: true,
    );
    if (builder == null ||
        _open != null ||
        _chipName(canBuildHere: canBuildHere, canExperiment: true) !=
            'Reports') {
      return null;
    }

    // To the Builder by NAME. `_chip = 1` was the Builder only while the
    // library sat at 0 (deploy desk audit, 2026-09-29).
    void open() => setState(() {
      _chip = builder;
      _open = null;
    });

    // Desktop review 2026-11-13, D3: header right, as every module.
    return ModuleAction(
      // On a phone it took the strip's width and left "Reports, Repor…" (UI
      // audit, 2026-10-13): the icon alone there, beside the picker.
      child: compact
          ? IconButton.filled(
              key: const ValueKey('tour-reports-new'),
              tooltip: 'New report',
              onPressed: open,
              icon: const Icon(Icons.add),
            )
          : FilledButton.icon(
              key: const ValueKey('tour-reports-new'),
              onPressed: open,
              icon: const Icon(Icons.add),
              label: const Text('New report'),
            ),
    );
  }

  Widget _body(BuildContext context, BoxConstraints constraints) {
    // In place rather than a dialog: the development rules allow screens and
    // side panels only, and a table inside a modal is unreadable on a phone.
    final canExperiment = widget.session.me?.can('reports.build') ?? false;
    final canBuildHere = canExperiment;

    final chips = _chips(
      canBuildHere: canBuildHere,
      canExperiment: canExperiment,
    );

    // A module drawn inside this one carries its own builder. Checked before
    // the switch so a child can never be shadowed by a built-in name.
    if (_chip < chips.length) {
      final build = chips[_chip].$3;

      if (build != null) return build();
    }

    switch (_chipName(
      canBuildHere: canBuildHere,
      canExperiment: canExperiment,
    )) {
      case 'Report Builder':
        return ReportBuilder(
          api: widget.api,
          onSaved: (_) {
            // Back to the list, where the report now is - if this person
            // holds it. Otherwise the Builder stays where it is.
            final library = _indexOf(
              'Reports',
              canBuildHere: canBuildHere,
              canExperiment: canExperiment,
            );

            if (library != null) {
              setState(() => _chip = library);
              _load();
            }
          },
        );

      case 'Performance':
        return ExperimentalCallReport(
          // A new link starts the report over on that call.
          key: ValueKey('performance-$_openCall'),
          openCall: _openCall,
          span: _span,
          onSpan: _setSpan,
          // The same clock as the shared range, so "Last 7 days" names it.
          now: widget.now,
          api: widget.api,
          // Handles and Convert to booked: their own tick (2026-11-02).
          canEdit: widget.session.me?.can('reports.handles') ?? false,
          formats: widget.session.me?.display ?? DisplayFormats.fallback,
        );

      case 'Reports':
        break;

      // No tab this person holds is selected - draw nothing rather than the
      // library they were not given.
      default:
        return const SizedBox.shrink();
    }

    final open = _open;

    if (open != null) {
      return ReportRunView(
        key: ValueKey(open.id),
        api: widget.api,
        reportId: open.id,
        name: open.name,
        canExport: widget.session.me?.can('reports.export') ?? false,
      );
    }

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    final reports = _reports ?? const <SavedReportSummary>[];
    final hours = _hours ?? const <Map<String, dynamic>>[];
    final scheduling = _scheduling ?? const <Map<String, dynamic>>[];

    // State 3. Deliberately its own branch and its own widget: refused means
    // "you may not", which is a different fact from "there is nothing", and a
    // reader who cannot tell them apart learns nothing from either. Only when
    // there is nothing else to draw: Work Hours' reports still stand.
    if (_error != null && hours.isEmpty && scheduling.isEmpty) {
      return Align(alignment: Alignment.topCenter, child: ErrorNote(_error!));
    }

    // Both kinds counted. "No reports built yet" above a Work Hours report
    // is the contradiction Jose hit (queue 33).
    if (reports.isEmpty &&
        hours.isEmpty &&
        scheduling.isEmpty &&
        _hoursError == null &&
        _schedulingError == null) {
      return _emptyLibrary(context);
    }

    return _library(context, constraints, reports, hours, scheduling);
  }

  /// Every saved report, whichever module made it (queue 33, Jose's 1 and
  /// 4): what kind it is, who it covers and what sends it. A Builder report
  /// runs in place, as before; a Work Hours one docks beside the list, or on
  /// a phone opens in place of it.
  Widget _library(
    BuildContext context,
    BoxConstraints constraints,
    List<SavedReportSummary> reports,
    List<Map<String, dynamic>> hours,
    List<Map<String, dynamic>> scheduling,
  ) {
    final wide = constraints.maxWidth >= ReportsScreen.buildAbove;

    // The docked report, whichever module's: `_openHours` holds its ROW id,
    // which is prefixed per kind so two modules' ids cannot collide.
    final docked = [
      for (final report in hours) _LibraryRow.hours(report),
      for (final report in scheduling) _LibraryRow.scheduling(report),
    ].where((row) => row.id == _openHours).firstOrNull;

    if (!wide && docked != null) return _dockedPanel(docked, inPlace: true);

    final rows = <_LibraryRow>[
      if (_kind == null || _kind == 'built')
        for (final report in reports) _LibraryRow.built(report),
      if (_kind == null || _kind == 'hours')
        for (final report in hours) _LibraryRow.hours(report),
      if (_kind == null || _kind == 'scheduling')
        for (final report in scheduling) _LibraryRow.scheduling(report),
    ]..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));

    final query = _query.trim().toLowerCase();
    final shown = query.isEmpty
        ? rows
        : [
            for (final row in rows)
              if ([
                row.name,
                _kindPill(row).label,
                _about(row),
                _covers(row),
              ].any((text) => text.toLowerCase().contains(query)))
                row,
          ];

    final kinds = [
      reports.isNotEmpty,
      hours.isNotEmpty,
      scheduling.isNotEmpty,
    ].where((present) => present).length;

    final scheme = Theme.of(context).colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // Standard field height, as tall as Leads search (Jose, 2026-10-27).
              SizedBox(
                width: 240,
                child: ListSearch(
                  key: const Key('reports-library-search'),
                  controller: _search,
                  hint: 'Find a report',
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
              if (kinds > 1)
                _kinds(
                  context,
                  reports.length,
                  hours.length,
                  scheduling.length,
                ),
            ],
          ),
        ),
        for (final error in [_error, _hoursError, _schedulingError])
          if (error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: ErrorNote(error),
            ),
        Expanded(
          child: shown.isEmpty && query.isNotEmpty
              ? Text(
                  'No reports match "${_query.trim()}".',
                  style: Theme.of(context).textTheme.bodyMedium,
                )
              : !wide
              ? _tiles(context, shown)
              // The report docks beside the table, level with it.
              : Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(child: _table(context, shown)),
                    if (docked != null) ...[
                      const SizedBox(width: 16),
                      Container(
                        width: 460,
                        decoration: BoxDecoration(
                          border: Border.all(color: scheme.outlineVariant),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        clipBehavior: Clip.antiAlias,
                        child: _dockedPanel(docked, inPlace: false),
                      ),
                    ],
                  ],
                ),
        ),
      ],
    );
  }

  Widget _hoursPanel(Map<String, dynamic> report, {required bool inPlace}) =>
      WorkHoursReportPanel(
        key: ValueKey('hours-report-${report['id']}'),
        api: widget.api,
        session: widget.session,
        report: report,
        roles: _hourRoles,
        people: _hourPeople,
        inPlace: inPlace,
        onClose: () => setState(() => _openHours = null),
        onChanged: _load,
      );

  Widget _dockedPanel(_LibraryRow row, {required bool inPlace}) {
    if (row.hours case final hours?) {
      return _hoursPanel(hours, inPlace: inPlace);
    }

    return OverbookingReportPanel(
      key: ValueKey('scheduling-report-${row.id}'),
      api: widget.api,
      session: widget.session,
      report: row.scheduling!,
      inPlace: inPlace,
      onClose: () => setState(() => _openHours = null),
    );
  }

  void _openRow(_LibraryRow row) => setState(() {
    if (row.built case final built?) {
      _open = built;
      _openHours = null;
    } else {
      _openHours = row.id;
    }
  });

  /// Every report, the Builder's or Work Hours', each with its count.
  Widget _kinds(BuildContext context, int built, int hours, int scheduling) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    Widget chip(String? value, String label, int count) => ChoiceChip(
      key: ValueKey('library-kind-${value ?? 'all'}'),
      showCheckmark: false,
      selected: _kind == value,
      label: Text.rich(
        TextSpan(
          text: label,
          children: [
            TextSpan(
              text: '  ${formatCount(count)}',
              style: TextStyle(color: muted, fontWeight: FontWeight.w500),
            ),
          ],
        ),
      ),
      onSelected: (_) => setState(() => _kind = value),
    );

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        chip(null, 'All', built + hours + scheduling),
        if (built > 0) chip('built', 'Built here', built),
        if (hours > 0) chip('hours', 'Work Hours', hours),
        if (scheduling > 0) chip('scheduling', 'Scheduling', scheduling),
      ],
    );
  }

  StatusPill _kindPill(_LibraryRow row) => row.built != null
      ? const StatusPill('Built here')
      : row.hours != null
      ? const StatusPill('Work Hours', tone: Tone.caution)
      : const StatusPill('Scheduling', tone: Tone.caution);

  String _about(_LibraryRow row) =>
      row.built?.description ??
      (row.scheduling == null
          ? 'Hours each person worked, per week'
          : '${row.scheduling!['description'] ?? ''}');

  /// Who a report covers: a Work Hours one names its roles and people; a
  /// Builder one counts what it is about, within the reader's reach.
  String _covers(_LibraryRow row) {
    if (row.hours case final hours?) {
      return workHoursReportCovers(
        hours,
        roles: _hourRoles,
        people: _hourPeople,
      );
    }
    if (row.scheduling case final scheduling?) {
      return '${scheduling['covers'] ?? '—'}';
    }
    final about = row.built?.rootLabel;
    return about == null ? '—' : '$about you can reach';
  }

  /// When a report goes out, and which rule sends it. Rule names are
  /// Automations': without `automations.view` the server says only whether
  /// one does (`sent_by` null), which is a different answer from none
  /// (`sent_by` empty).
  ({String when, String? by, bool quiet}) _sent(_LibraryRow row) {
    final rules =
        row.built?.sentBy ??
        ((row.hours ?? row.scheduling)?['sent_by'] as List?)
            ?.whereType<Map>()
            .map(Map<String, dynamic>.from)
            .toList();
    final automatic =
        row.built?.sentAutomatically ??
        (row.hours ?? row.scheduling)?['sent_automatically'] == true;

    if (rules == null) {
      return automatic
          ? (when: 'Sent automatically', by: null, quiet: false)
          : (when: 'Not sent by any automation', by: null, quiet: true);
    }
    if (rules.isEmpty) {
      return (when: 'Not sent by any automation', by: null, quiet: true);
    }

    final on = rules.where(senderIsOn).toList();
    final shown = on.isEmpty ? rules : on;
    final more = shown.length - 1;

    return (
      when: senderWhen(shown.first, widget.session.me!.display),
      by: 'by “${shown.first['name']}”${more > 0 ? ' and $more more' : ''}',
      quiet: on.isEmpty,
    );
  }

  Widget _table(BuildContext context, List<_LibraryRow> rows) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final head = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: scheme.onSurfaceVariant,
    );
    final canBuild = widget.session.me?.can('reports.build') ?? false;

    Widget line(Widget report, Widget covers, Widget sent, [Widget? end]) =>
        Row(
          children: [
            // The name and its description get the room: "Covers" and "Sent"
            // are a few words, and at equal widths the description was cut
            // off beside them (UI review, 2026-10-08).
            Expanded(flex: 7, child: report),
            const SizedBox(width: 16),
            Expanded(flex: 3, child: covers),
            const SizedBox(width: 16),
            Expanded(flex: 3, child: sent),
            SizedBox(width: 44, child: end),
          ],
        );

    return Container(
      key: const ValueKey('tour-reports-library'),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
            child: line(
              Text('Report', style: head),
              Text('Covers', style: head),
              Text('Sent', style: head),
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: ListView.builder(
              itemCount: rows.length,
              itemBuilder: (context, i) {
                final row = rows[i];
                if (row.built case final built? when _confirming == row.id) {
                  return Padding(
                    padding: const EdgeInsets.all(8),
                    child: _confirmDelete(context, built),
                  );
                }
                final sent = _sent(row);

                return Material(
                  key: ValueKey('library-row-${row.id}'),
                  color: row.id == _openHours
                      ? scheme.primaryContainer.withValues(alpha: .45)
                      : Colors.transparent,
                  child: InkWell(
                    onTap: () => _openRow(row),
                    child: Container(
                      padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
                      decoration: BoxDecoration(
                        border: Border(
                          bottom: BorderSide(
                            color: scheme.outlineVariant,
                            width: 0.5,
                          ),
                        ),
                      ),
                      child: line(
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              row.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 3),
                            Row(
                              children: [
                                _kindPill(row),
                                const SizedBox(width: 6),
                                Expanded(
                                  child: Text(
                                    _about(row),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: muted,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                        Text(
                          _covers(row),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              sent.when,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: sent.quiet
                                  ? muted
                                  : const TextStyle(
                                      fontWeight: FontWeight.w700,
                                    ),
                            ),
                            if (sent.by != null)
                              Text(
                                sent.by!,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: muted,
                              ),
                          ],
                        ),
                        row.built != null && canBuild
                            ? IconButton(
                                icon: const Icon(Icons.delete_outline),
                                tooltip: 'Delete report',
                                onPressed: () =>
                                    setState(() => _confirming = row.id),
                              )
                            : null,
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  /// The same rows on a phone: one card each, what it is, who it covers,
  /// and when it goes out.
  Widget _tiles(BuildContext context, List<_LibraryRow> rows) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final canBuild = widget.session.me?.can('reports.build') ?? false;

    return ListView.separated(
      itemCount: rows.length,
      separatorBuilder: (_, _) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final row = rows[i];
        if (row.built case final built? when _confirming == row.id) {
          return _confirmDelete(context, built);
        }
        final sent = _sent(row);

        return Card(
          key: ValueKey('library-row-${row.id}'),
          margin: EdgeInsets.zero,
          child: InkWell(
            onTap: () => _openRow(row),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 10, 4, 10),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                row.name,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.titleSmall?.copyWith(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            _kindPill(row),
                          ],
                        ),
                        const SizedBox(height: 3),
                        Text(
                          _covers(row),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: muted,
                        ),
                        Text(
                          sent.by == null
                              ? sent.when
                              : '${sent.when} · ${sent.by}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: muted,
                        ),
                      ],
                    ),
                  ),
                  if (row.built != null && canBuild)
                    PopupMenuButton<String>(
                      tooltip: 'More for ${row.name}',
                      icon: const Icon(Icons.more_vert),
                      onSelected: (_) => setState(() => _confirming = row.id),
                      itemBuilder: (_) => const [
                        PopupMenuItem(
                          value: 'delete',
                          child: Text('Delete report'),
                        ),
                      ],
                    )
                  else
                    const SizedBox(width: 10),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  /// Confirming in place rather than in a dialog: the development rules allow
  /// screens and side panels only, and a confirm dialog on a phone covers the
  /// thing it is asking about.
  Widget _confirmDelete(BuildContext context, SavedReportSummary report) {
    final theme = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(report.name, style: theme.textTheme.titleMedium),
            const SizedBox(height: 12),
            Text(
              'Delete this report? It stops being available to everybody who '
              'can run it. The records it reads on are untouched.',
              style: theme.textTheme.bodyMedium,
            ),
            if (_deleteError != null) ...[
              const SizedBox(height: 12),
              ErrorNote(_deleteError!),
            ],
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: () => setState(() {
                    _confirming = null;
                    _deleteError = null;
                  }),
                  child: const Text('Keep it'),
                ),
                const SizedBox(width: 12),
                FilledButton(
                  onPressed: () => _delete(report),
                  child: const Text('Delete report'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _delete(SavedReportSummary report) async {
    final response = await widget.api.delete('/reports/${report.id}');

    if (!mounted) return;

    if (!response.ok) {
      // The server's own sentence: a refusal here says the grant lives on a
      // role, which no client wording knows.
      setState(() => _deleteError = response.message);
      return;
    }

    setState(() {
      _confirming = null;
      _deleteError = null;
    });

    await _load();
  }

  Widget _emptyLibrary(BuildContext context) {
    final theme = Theme.of(context);
    final canBuild = (widget.session.me?.can('reports.build') ?? false);

    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('No reports built yet.', style: theme.textTheme.titleMedium),
          const SizedBox(height: 12),
          Text(
            // Two sentences, differing only by permission, and the difference
            // is the whole point: the second tells a runner that somebody else
            // makes the first report, so an empty library does not read as
            // their own dead end.
            canBuild
                ? 'Build one and it appears here for everybody who can run it.'
                : 'Somebody who can build reports has to make the first one.',
            style: theme.textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}

/// One row of the library, whichever module made the report (queue 33).
class _LibraryRow {
  _LibraryRow.built(SavedReportSummary this.built)
    : hours = null,
      scheduling = null,
      name = built.name;

  _LibraryRow.hours(Map<String, dynamic> this.hours)
    : built = null,
      scheduling = null,
      name = '${hours['name'] ?? ''}';

  _LibraryRow.scheduling(Map<String, dynamic> this.scheduling)
    : built = null,
      hours = null,
      name = '${scheduling['name'] ?? ''}';

  final SavedReportSummary? built;
  final Map<String, dynamic>? hours;
  final Map<String, dynamic>? scheduling;
  final String name;

  /// Scheduling's ids are words ('overbooking') from another table, so they
  /// are prefixed: a docked row is found by this id across every kind.
  String get id =>
      built?.id ??
      (hours != null ? '${hours!['id']}' : 'scheduling:${scheduling!['id']}');
}

/// One row of `GET /reports`.
class SavedReportSummary {
  const SavedReportSummary({
    required this.id,
    required this.name,
    this.description,
    this.rootLabel,
    this.sentAutomatically = false,
    this.sentBy,
  });

  factory SavedReportSummary.fromJson(Map<String, dynamic> json) {
    final definition = json['definition'];
    final root = definition is Map ? '${definition['root'] ?? ''}' : '';

    return SavedReportSummary(
      id: json['id'] as String,
      name: json['name'] as String? ?? '',
      description: json['description'] as String?,
      // What it counts, for the Covers column: the map's own word when the
      // server sends it, and the root made readable until then.
      rootLabel:
          json['root_label'] as String? ??
          (root.isEmpty
              ? null
              : '${root[0].toUpperCase()}${root.substring(1)}'.replaceAll(
                  '_',
                  ' ',
                )),
      sentAutomatically: json['sent_automatically'] == true,
      sentBy: (json['sent_by'] as List?)
          ?.whereType<Map>()
          .map(Map<String, dynamic>.from)
          .toList(),
    );
  }

  final String id;
  final String name;
  final String? description;

  /// "Jobs", "Customers": what the report counts.
  final String? rootLabel;

  /// A rule that is on sends it. Said to every reader.
  final bool sentAutomatically;

  /// The rules whose current version sends it, for a reader who may see
  /// rules; null for everybody else, which is not the same as none.
  final List<Map<String, dynamic>>? sentBy;
}
