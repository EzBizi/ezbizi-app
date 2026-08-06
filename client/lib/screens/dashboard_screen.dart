import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';

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
  String? _error;
  List<Map<String, dynamic>> _panels = [];
  List<Map<String, dynamic>> _catalogue = [];

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

    final results = await Future.wait([
      widget.api.get('/dashboard'),
      widget.api.get('/dashboard/catalogue'),
    ]);

    if (!mounted) return;

    setState(() {
      _loading = false;
      _error = results[0].ok ? null : results[0].message;
      _panels = results[0].ok
          ? (results[0].body['panels'] as List<dynamic>).cast<Map<String, dynamic>>()
          : [];
      _catalogue = results[1].ok
          ? (results[1].body['widgets'] as List<dynamic>).cast<Map<String, dynamic>>()
          : [];
    });
  }

  Future<void> _setLayout(List<Map<String, dynamic>> widgets) async {
    final response = await widget.api.put('/dashboard/layout', {'widgets': widgets});

    if (!mounted) return;

    if (!response.ok) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(response.message)));
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
                    child: Text('Dashboard',
                        style: Theme.of(context).textTheme.headlineSmall),
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

    showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Choose panels'),
        content: SizedBox(
          width: 420,
          child: StatefulBuilder(
            builder: (context, setInner) => Column(
              mainAxisSize: MainAxisSize.min,
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
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              Navigator.of(dialogContext).pop();

              // Existing options are preserved for panels that stay — turning a
              // panel off and on again should not silently reset how it was
              // configured.
              _setLayout([
                for (final entry in _catalogue)
                  if (shown.contains(entry['key']))
                    {
                      'key': entry['key'],
                      'options': _panels.firstWhere(
                            (p) => p['key'] == entry['key'],
                            orElse: () => const {'options': <String, dynamic>{}},
                          )['options'] ??
                          <String, dynamic>{},
                    },
              ]);
            },
            child: const Text('Save'),
          ),
        ],
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
            Text('Nothing chosen yet',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(
              'Pick the numbers you want to see. Nothing here is fixed — '
              'panels are yours, not the organization\'s.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: onChoose, child: const Text('Choose panels')),
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
            Text(panel['label'] as String,
                style: Theme.of(context).textTheme.titleMedium),
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
        Text(data['message'] as String? ?? 'Needs configuring.',
            style: Theme.of(context).textTheme.bodyMedium),
      ];
    }

    // Money. Rendered per currency because the server refuses to add them
    // together — see the Revenue widget for why.
    if (panel['key'] == 'jobs.revenue') {
      final rows = (data['rows'] as List<dynamic>).cast<Map<String, dynamic>>();

      if (rows.isEmpty) return [const Text('No work in this period.')];

      return [
        for (final row in rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(_money(row['amount_minor'] as int, row['currency'] as String),
                    style: Theme.of(context).textTheme.headlineSmall),
                Text('${row['jobs']} jobs',
                    style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ),
      ];
    }

    if (panel['key'] == 'workflows.health') {
      if (data['idle'] == true) {
        // Not "0 failures" — that reads as health, and silence is not health.
        return [const Text('No automations have run in this period.')];
      }

      return [
        Text('${data['runs']} runs',
            style: Theme.of(context).textTheme.headlineSmall),
        if ((data['failed'] as int) > 0)
          Text('${data['failed']} failed',
              style: TextStyle(color: Theme.of(context).colorScheme.error)),
        if ((data['waiting'] as int) > 0) Text('${data['waiting']} waiting'),
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
                Text((row['total'] ?? row['customer'] ?? '').toString(),
                    style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ),
      ];
    }

    if (data.containsKey('total')) {
      return [
        Text('${data['total']}', style: Theme.of(context).textTheme.headlineMedium),
        if (data.containsKey('organization_total'))
          Text('${data['organization_total']} across the organization',
              style: Theme.of(context).textTheme.bodySmall),
      ];
    }

    return [const Text('Nothing to show yet.')];
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
