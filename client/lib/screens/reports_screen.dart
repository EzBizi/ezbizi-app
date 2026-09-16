import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/module_screens.dart';
import '../core/session.dart';
import '../widgets/error_note.dart';
import '../widgets/tab_bar_like.dart';
import 'reports/experimental_call_report.dart';
import 'reports/report_builder.dart';
import 'reports/report_run_view.dart';

/// Reports (spec `docs/superpowers/specs/2026-09-10-reports-design.md`).
///
/// ── Two surfaces, not one that adapts ────────────────────────────
///
/// Above [buildAbove] this is Jose's option 1 — a field tree beside the report.
/// Below it, option 5: a phone RUNS what a desk built. That is two designs
/// rather than a breakpoint on one, and it is UI/UX's ruling: an unlimited-hop
/// field picker at 375px would be a worse copy of a control that already works,
/// and offering it invites somebody to start a report they cannot finish.
///
/// A narrow screen therefore says so, rather than hiding the builder and
/// leaving a person who holds `reports.build` wondering where it went.
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
  const ReportsScreen({super.key, required this.api, required this.session});

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
  /// Reports · Builder · Experimental. The builder is a DESTINATION now
  /// rather than a mode the list drops into, which is what Jose asked for and
  /// is also the honest shape: building and running are different jobs, and
  /// the chips say so before anybody clicks.
  int _chip = 0;

  SavedReportSummary? _open;
  String? _confirming;
  String? _deleteError;
  List<SavedReportSummary>? _reports;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/reports');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        // The server's own sentence, not a replacement. A refusal here explains
        // that the grant lives on a role, which no client-side wording knows.
        _error = response.message;
      });
      return;
    }

    final rows = (response.body['data'] as List<dynamic>? ?? const [])
        .cast<Map<String, dynamic>>()
        .map(SavedReportSummary.fromJson)
        .toList();

    setState(() {
      _loading = false;
      _reports = rows;
    });
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final canExperiment = widget.session.me?.can('reports.build') ?? false;
        final canBuildHere =
            canExperiment && constraints.maxWidth >= ReportsScreen.buildAbove;

        return Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(context, canBuildHere: canBuildHere),
              const SizedBox(height: 12),
              TabBarLike(
                labels: _chipLabels(
                  canBuildHere: canBuildHere,
                  canExperiment: canExperiment,
                ),
                selected: _chip,
                onSelected: (i) => setState(() {
                  _chip = i;
                  _open = null;
                  _confirming = null;
                }),
              ),
              const SizedBox(height: 12),
              Expanded(child: _body(context, constraints)),
            ],
          ),
        );
      },
    );
  }

  /// Builder is absent rather than disabled where it cannot be used - without
  /// `reports.build`, and below the split where a phone runs what a desk
  /// built. The same rule as the New report control: a chip leading to a
  /// refusal tells somebody the app is for other people.
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

    return [
      ('Reports', Icons.list_alt_outlined, null),
      if (canBuildHere) ('Builder', Icons.build_outlined, null),
      if (canExperiment) ('Experimental', Icons.science_outlined, null),
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

  List<(String, IconData)> _chipLabels({
    required bool canBuildHere,
    required bool canExperiment,
  }) {
    return _chips(
      canBuildHere: canBuildHere,
      canExperiment: canExperiment,
    ).map((chip) => (chip.$1, chip.$2)).toList();
  }

  /// The chips shift when Builder is absent, so a stored index would point at
  /// the wrong destination. Resolved by NAME rather than by number.
  String _chipName({required bool canBuildHere, required bool canExperiment}) {
    final labels = _chipLabels(
      canBuildHere: canBuildHere,
      canExperiment: canExperiment,
    );

    return _chip < labels.length ? labels[_chip].$1 : 'Reports';
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
        if (canBuildHere && _open == null && _chip == 0)
          FilledButton.icon(
            onPressed: () => setState(() {
              _chip = 1;
              _open = null;
            }),
            icon: const Icon(Icons.add),
            label: const Text('New report'),
          ),
      ],
    );
  }

  Widget _body(BuildContext context, BoxConstraints constraints) {
    // In place rather than a dialog: the development rules allow screens and
    // side panels only, and a table inside a modal is unreadable on a phone.
    final canExperiment = widget.session.me?.can('reports.build') ?? false;
    final canBuildHere =
        canExperiment && constraints.maxWidth >= ReportsScreen.buildAbove;

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
      case 'Builder':
        return ReportBuilder(
          api: widget.api,
          onSaved: (_) {
            // Back to the list, where the report now is.
            setState(() => _chip = 0);
            _load();
          },
        );

      case 'Experimental':
        return ExperimentalCallReport(api: widget.api);
    }

    final open = _open;

    if (open != null) {
      return ReportRunView(
        key: ValueKey(open.id),
        api: widget.api,
        reportId: open.id,
        name: open.name,
      );
    }

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    // State 3. Deliberately its own branch and its own widget: refused means
    // "you may not", which is a different fact from "there is nothing", and a
    // reader who cannot tell them apart learns nothing from either.
    if (_error != null) {
      return Align(alignment: Alignment.topCenter, child: ErrorNote(_error!));
    }

    final reports = _reports ?? const <SavedReportSummary>[];

    if (reports.isEmpty) {
      return _emptyLibrary(context);
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: ListView.separated(
            itemCount: reports.length,
            separatorBuilder: (_, _) => const SizedBox(height: 12),
            itemBuilder: (context, i) => _reportCard(context, reports[i]),
          ),
        ),
        if ((widget.session.me?.can('reports.build') ?? false) &&
            constraints.maxWidth < ReportsScreen.buildAbove) ...[
          const SizedBox(height: 12),
          _builtOnAComputer(context),
        ],
      ],
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

  Widget _reportCard(BuildContext context, SavedReportSummary report) {
    final theme = Theme.of(context);

    if (_confirming == report.id) {
      return _confirmDelete(context, report);
    }

    return Card(
      margin: EdgeInsets.zero,
      child: ListTile(
        contentPadding: const EdgeInsets.all(16),
        title: Text(report.name, style: theme.textTheme.titleMedium),
        subtitle: report.description == null
            ? null
            : Text(report.description!, style: theme.textTheme.bodySmall),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (widget.session.me?.can('reports.build') ?? false)
              IconButton(
                icon: const Icon(Icons.delete_outline),
                tooltip: 'Delete report',
                onPressed: () => setState(() => _confirming = report.id),
              ),
            const Icon(Icons.chevron_right),
          ],
        ),
        onTap: () => setState(() => _open = report),
      ),
    );
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

  Widget _builtOnAComputer(BuildContext context) {
    return Text(
      'Reports are built on a computer. You can run any of them here.',
      style: Theme.of(context).textTheme.bodySmall,
      textAlign: TextAlign.center,
    );
  }
}

/// One row of `GET /reports`.
class SavedReportSummary {
  const SavedReportSummary({
    required this.id,
    required this.name,
    this.description,
  });

  factory SavedReportSummary.fromJson(Map<String, dynamic> json) {
    return SavedReportSummary(
      id: json['id'] as String,
      name: json['name'] as String? ?? '',
      description: json['description'] as String?,
    );
  }

  final String id;
  final String name;
  final String? description;
}
