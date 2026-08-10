import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/side_panel.dart';

/// The dashboard (plan §18 Phase 4).
///
/// Every panel is rendered from what the server sends, and which panels exist is
/// the user's own preference. There is no fixed list of cards in here — a widget
/// added to the registry appears the next time this screen loads, without a
/// client release. That is the standing rule ("nothing hardcoded") applied to
/// the one screen where it is most tempting to break it.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _loading = true;

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it: the kill switch could sit
  /// reading "Off" for a module that was on, until somebody navigated away.
  int _request = 0;
  String? _error;
  List<Map<String, dynamic>> _panels = [];
  List<Map<String, dynamic>> _catalogue = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. Both
      // collections, not just the panels: choosing NO panels is a legitimate
      // answer, and an organization sitting on the empty state would otherwise
      // have it — and the "Choose panels" button on it — replaced by a spinner
      // every time the chooser saved. The catalogue is empty only before the
      // first answer arrives, so together they say whether anything is loaded.
      _loading = _panels.isEmpty && _catalogue.isEmpty;
      _error = null;
    });

    final results = await Future.wait([
      widget.api.get('/dashboard'),
      widget.api.get('/dashboard/catalogue'),
    ]);

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      // Either failure is an error. The catalogue drives the chooser, and a
      // silently empty one meant pressing Save wrote an empty layout — erasing
      // the dashboard because a request nobody was told about had failed.
      _error = results[0].ok
          ? (results[1].ok ? null : results[1].message)
          : results[0].message;
      _panels = results[0].ok
          ? (results[0].body['panels'] as List<dynamic>)
                .cast<Map<String, dynamic>>()
          : [];
      _catalogue = results[1].ok
          ? (results[1].body['widgets'] as List<dynamic>)
                .cast<Map<String, dynamic>>()
          : [];
    });
  }

  Future<void> _setLayout(List<Map<String, dynamic>> widgets) async {
    final response = await widget.api.put('/dashboard/layout', {
      'widgets': widgets,
    });

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    _load();
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) {
          // Two columns when there is room, one when there is not. Panels are
          // self-contained, so this needs no breakpoint table.
          final columns = constraints.maxWidth >= 900 ? 2 : 1;

          return ListView(
            padding: const EdgeInsets.all(24),
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Dashboard',
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                  ),
                  IconButton(
                    onPressed: _load,
                    icon: const Icon(Icons.refresh),
                    tooltip: 'Refresh',
                  ),
                  IconButton(
                    onPressed: _choosePanels,
                    icon: const Icon(Icons.tune),
                    tooltip: 'Choose panels',
                  ),
                ],
              ),
              const SizedBox(height: 16),
              if (_panels.isEmpty)
                _EmptyState(onChoose: _choosePanels)
              else
                Wrap(
                  spacing: 16,
                  runSpacing: 16,
                  children: [
                    for (final panel in _panels)
                      SizedBox(
                        width: columns == 1
                            ? constraints.maxWidth - 48
                            : (constraints.maxWidth - 64) / 2,
                        child: _Panel(panel: panel),
                      ),
                  ],
                ),
            ],
          );
        },
      ),
    );
  }

  void _choosePanels() {
    final shown = _panels.map((p) => p['key'] as String).toSet();

    showSidePanel<void>(
      context,
      builder: (panelContext) => SidePanel(
        title: 'Choose panels',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(panelContext).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              Navigator.of(panelContext).pop();

              // Existing options are preserved for panels that stay — turning a
              // panel off and on again should not silently reset how it was
              // configured.
              _setLayout([
                for (final entry in _catalogue)
                  if (shown.contains(entry['key']))
                    {
                      'key': entry['key'],
                      'options':
                          _panels.firstWhere(
                            (p) => p['key'] == entry['key'],
                            orElse: () => const {
                              'options': <String, dynamic>{},
                            },
                          )['options'] ??
                          <String, dynamic>{},
                    },
              ]);
            },
            child: const Text('Save'),
          ),
        ],
        // The footer reads `shown` when Save is pressed, so only the list needs
        // rebuilding as boxes are ticked.
        child: StatefulBuilder(
          builder: (context, setInner) => Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final entry in _catalogue)
                CheckboxListTile(
                  dense: true,
                  value: shown.contains(entry['key']),
                  title: Text(entry['label'] as String),
                  onChanged: (on) => setInner(() {
                    on == true
                        ? shown.add(entry['key'] as String)
                        : shown.remove(entry['key'] as String);
                  }),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.onChoose});

  final VoidCallback onChoose;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            Text(
              'Nothing chosen yet',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 8),
            Text(
              'Pick the numbers you want to see. Nothing here is fixed — '
              'panels are yours, not the organization\'s.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: onChoose,
              child: const Text('Choose panels'),
            ),
          ],
        ),
      ),
    );
  }
}

/// One panel, rendered from its shape rather than its name.
///
/// The server decides what a widget returns; this decides how a shape is drawn.
/// A widget returning `rows` gets a list, one returning `total` gets a big
/// number. A new widget that reuses a shape needs nothing here.
class _Panel extends StatelessWidget {
  const _Panel({required this.panel});

  final Map<String, dynamic> panel;

  @override
  Widget build(BuildContext context) {
    final data = panel['data'] as Map<String, dynamic>;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              panel['label'] as String,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            ..._body(context, data),
          ],
        ),
      ),
    );
  }

  List<Widget> _body(BuildContext context, Map<String, dynamic> data) {
    if (data['needs_configuring'] == true) {
      return [
        Text(
          data['message'] as String? ?? 'Needs configuring.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
      ];
    }

    // Money. Rendered per currency because the server refuses to add them
    // together — see the Revenue widget for why.
    // By SHAPE, not by name. Keying on the literal 'jobs.revenue' means the
    // next widget that reports money renders as a bare list of numbers with no
    // currency beside them — and a number that might be dollars is worse than
    // no number.
    if (_isMoney(data)) {
      final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();

      if (rows.isEmpty) return [const Text('No work in this period.')];

      return [
        for (final row in rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  _money(row['amount_minor'] as int, row['currency'] as String),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                Text(
                  '${row['jobs']} jobs',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
      ];
    }

    if (panel['key'] == 'workflows.health') {
      final waiting = data['waiting'] as int;

      if (data['idle'] == true) {
        // Not "0 failures" — that reads as health, and silence is not health.
        // And a backlog is reported HERE too: nothing running while events pile
        // up is the exact signature of a stopped scheduler, and hiding it behind
        // "idle" would bury the one number that says so.
        return [
          const Text('No automations have run in this period.'),
          if (waiting > 0)
            Text(
              '$waiting event${waiting == 1 ? '' : 's'} waiting to be delivered.',
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
        ];
      }

      return [
        Text(
          '${data['runs']} runs',
          style: Theme.of(context).textTheme.headlineSmall,
        ),
        if ((data['failed'] as int) > 0)
          Text(
            '${data['failed']} failed',
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
        if (waiting > 0) Text('$waiting waiting'),
      ];
    }

    if (panel['key'] == 'jobs.upcoming') {
      final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();

      if (rows.isEmpty) return [const Text('Nothing scheduled.')];

      return [
        for (final row in rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${row['number']} · ${row['title']}',
                  overflow: TextOverflow.ellipsis,
                ),
                Text(
                  // The whole reason somebody opens this panel is WHEN. The
                  // generic row renderer showed the status and the customer and
                  // dropped both the job and its time.
                  '${formatStamp(row['scheduled_for'] as String?, 'Not scheduled')}'
                  '${row['customer'] == null ? '' : ' · ${row['customer']}'}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
      ];
    }

    if (data.containsKey('rows')) {
      final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();

      if (rows.isEmpty) return [const Text('Nothing to show yet.')];

      return [
        for (final row in rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    (row['status'] ?? row['title'] ?? '').toString(),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Text(
                  (row['total'] ?? row['customer'] ?? '').toString(),
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
      ];
    }

    if (data.containsKey('total')) {
      return [
        Text(
          '${data['total']}',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
        if (data.containsKey('organization_total'))
          Text(
            '${data['organization_total']} across the organization',
            style: Theme.of(context).textTheme.bodySmall,
          ),
      ];
    }

    return [const Text('Nothing to show yet.')];
  }

  /// Does this widget report amounts of money?
  static bool _isMoney(Map<String, dynamic> data) {
    final rows = data['rows'];

    return rows is List &&
        rows.isNotEmpty &&
        rows.first is Map &&
        (rows.first as Map).containsKey('amount_minor');
  }

  /// Minor units to something readable.
  ///
  /// Two decimal places is an assumption that is wrong for JPY, which is why the
  /// server never does this — it stays a display concern, in one place, where it
  /// can be corrected when a zero-decimal currency turns up.
  String _money(int minor, String currency) {
    final major = (minor / 100).toStringAsFixed(2);

    return '$currency $major';
  }
}
