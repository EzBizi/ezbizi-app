import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/error_note.dart';
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
  SavedReportSummary? _open;
  bool _building = false;
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
        final canBuildHere =
            (widget.session.me?.can('reports.build') ?? false) &&
            constraints.maxWidth >= ReportsScreen.buildAbove;

        return Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _header(context, canBuildHere: canBuildHere),
              const SizedBox(height: 12),
              Expanded(child: _body(context, constraints)),
            ],
          ),
        );
      },
    );
  }

  Widget _header(BuildContext context, {required bool canBuildHere}) {
    return Row(
      children: [
        if (_open != null || _building)
          IconButton(
            onPressed: () => setState(() {
              _open = null;
              _building = false;
            }),
            icon: const Icon(Icons.arrow_back),
            tooltip: 'All reports',
          ),
        Expanded(
          child: Text(
            _building ? 'New report' : (_open?.name ?? 'Reports'),
            style: Theme.of(context).textTheme.headlineSmall,
          ),
        ),
        if (canBuildHere && _open == null && !_building)
          FilledButton.icon(
            onPressed: () => setState(() => _building = true),
            icon: const Icon(Icons.add),
            label: const Text('New report'),
          ),
      ],
    );
  }

  Widget _body(BuildContext context, BoxConstraints constraints) {
    // In place rather than a dialog: the development rules allow screens and
    // side panels only, and a table inside a modal is unreadable on a phone.
    if (_building) {
      return ReportBuilder(
        api: widget.api,
        onSaved: (_) {
          setState(() => _building = false);
          _load();
        },
      );
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
      return Align(
        alignment: Alignment.topCenter,
        child: ErrorNote(_error!),
      );
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

  Widget _reportCard(BuildContext context, SavedReportSummary report) {
    final theme = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      child: ListTile(
        contentPadding: const EdgeInsets.all(16),
        title: Text(report.name, style: theme.textTheme.titleMedium),
        subtitle: report.description == null
            ? null
            : Text(report.description!, style: theme.textTheme.bodySmall),
        trailing: const Icon(Icons.chevron_right),
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
