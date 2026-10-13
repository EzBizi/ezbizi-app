import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/module_overview.dart';

/// Fleet's General tab (Mia, 2026-10-02): what is on the road, what needs
/// attention, and - with financials - what it cost, from one request to
/// `GET /vehicles/summary`. A figure or warning with a link opens the
/// vehicle list filtered to it through [onOpen].
class FleetGeneral extends StatefulWidget {
  const FleetGeneral({
    super.key,
    required this.api,
    required this.visible,
    required this.onOpen,
    required this.canAdd,
    required this.onAdd,
  });

  final ApiClient api;
  final bool visible;

  /// A list filter in query form: `status=in_shop`, `unassigned=1`, or ''.
  final ValueChanged<String> onOpen;

  final bool canAdd;
  final VoidCallback onAdd;

  @override
  State<FleetGeneral> createState() => _FleetGeneralState();
}

class _FleetGeneralState extends State<FleetGeneral> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  String _period = 'month';
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant FleetGeneral oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _data == null;
      _error = null;
    });

    final response = await widget.api.get('/vehicles/summary', {
      'period': _period,
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _data = response.body;
      } else {
        _error = response.message;
      }
    });
  }

  VoidCallback? _link(Object? link) =>
      link is String ? () => widget.onOpen(link) : null;

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _data == null ? const SizedBox.shrink() : _body(context, _data!),
    );
  }

  Widget _body(BuildContext context, Map<String, dynamic> data) {
    final health = (data['health'] as Map?)?.cast<String, dynamic>() ?? {};

    if (health['empty'] == true) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(
            '${health['sentence'] ?? 'No vehicles to show.'}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          if (widget.canAdd) ...[
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.icon(
                onPressed: widget.onAdd,
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add vehicle'),
              ),
            ),
          ],
        ],
      );
    }

    final links = (health['links'] as Map?)?.cast<String, dynamic>() ?? {};
    int count(String key) => (health[key] as num?)?.toInt() ?? 0;

    final figures = [
      OverviewFigure(
        key: const Key('fleet-general-vehicles'),
        label: 'Vehicles',
        value: '${count('vehicles')}',
        note: '${count('owned')} owned · ${count('rented')} rented',
        onTap: _link(links['vehicles']),
      ),
      OverviewFigure(
        key: const Key('fleet-general-active'),
        label: 'Active',
        value: '${count('active')}',
        tone: Tone.ok,
        onTap: _link(links['active']),
      ),
      OverviewFigure(
        key: const Key('fleet-general-in-shop'),
        label: 'In shop',
        value: '${count('in_shop')}',
        tone: count('in_shop') > 0 ? Tone.caution : null,
        onTap: _link(links['in_shop']),
      ),
      OverviewFigure(
        key: const Key('fleet-general-out-of-service'),
        label: 'Out of service',
        value: '${count('out_of_service')}',
        tone: count('out_of_service') > 0 ? Tone.bad : null,
        onTap: _link(links['out_of_service']),
      ),
      OverviewFigure(
        key: const Key('fleet-general-unassigned'),
        label: 'Nobody has the keys',
        value: '${count('unassigned')}',
        onTap: _link(links['unassigned']),
      ),
    ];

    final warnings = [
      for (final raw in (data['warnings'] as List? ?? const []))
        if (raw is Map)
          OverviewWarning(
            sentence: '${raw['sentence'] ?? ''}',
            tone: const {
              'service_overdue',
              'insurance_expired',
            }.contains(raw['key'])
                ? Tone.bad
                : Tone.caution,
            onTap: _link(raw['link']),
          ),
    ];

    final spending = data['spending'] is Map
        ? (data['spending'] as Map).cast<String, dynamic>()
        : null;

    final attention = OverviewSection(
      key: const ValueKey('tour-fleet_management-attention'),
      title: 'Needs attention',
      child: OverviewWarnings(warnings: warnings),
    );

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        OverviewFigures(figures: figures),
        const SizedBox(height: 16),
        // Without financials there is no money block at all, and the
        // warnings take the whole width rather than leaving a hole.
        if (spending == null)
          attention
        else
          OverviewPair(first: _spending(context, spending), second: attention),
      ],
    );
  }

  Widget _spending(BuildContext context, Map<String, dynamic> spending) {
    final buckets = [
      for (final b in (spending['buckets'] as List? ?? const [])) '$b',
    ];
    final points = [
      for (final p in (spending['points'] as List? ?? const []))
        ((p as num?) ?? 0) / 100,
    ];
    final categories = [
      for (final c in (spending['categories'] as List? ?? const []))
        if (c is Map)
          (
            '${c['label'] ?? c['key']}',
            _money((c['amount_minor'] as num?)?.toInt() ?? 0),
          ),
    ];

    return OverviewSection(
      key: const Key('fleet-general-spending'),
      title: 'Spending',
      trailing: OverviewPeriodPicker(
        key: const ValueKey('tour-fleet_management-period'),
        period: _period,
        onChanged: (period) {
          setState(() => _period = period);
          _load();
        },
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            _money((spending['total_minor'] as num?)?.toInt() ?? 0),
            style: Theme.of(
              context,
            ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 8),
          OverviewTrend(
            buckets: buckets,
            lines: [('Spent', points)],
            emptySentence: 'No payments were recorded in this period.',
          ),
          if (categories.isNotEmpty) ...[
            const SizedBox(height: 12),
            OverviewBreakdown(rows: categories),
          ],
        ],
      ),
    );
  }

  /// Cents as "$5,057.00".
  static String _money(int cents) {
    final whole = (cents.abs() ~/ 100).toString().replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );
    final part = (cents.abs() % 100).toString().padLeft(2, '0');
    return '${cents < 0 ? '-' : ''}\$$whole.$part';
  }
}
