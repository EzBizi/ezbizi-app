import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/module_overview.dart';
import 'stock_join.dart';

/// Inventory's General tab (spec 2026-09-09-inventory-general-tab-design):
/// what is held, what needs attention, and what moved, from one request to
/// `GET /inventory/summary`. Every figure with a `links` entry opens the list
/// behind it through [onOpen]; one without is text.
class InventoryGeneral extends StatefulWidget {
  const InventoryGeneral({
    super.key,
    required this.api,
    required this.visible,
    required this.onOpen,
  });

  final ApiClient api;
  final bool visible;

  /// A server link such as `stock?low_items=1` or `orders`.
  final ValueChanged<String> onOpen;

  @override
  State<InventoryGeneral> createState() => _InventoryGeneralState();
}

class _InventoryGeneralState extends State<InventoryGeneral> {
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
  void didUpdateWidget(covariant InventoryGeneral oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _data == null;
      _error = null;
    });

    final response = await widget.api.get('/inventory/summary', {
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
            '${health['sentence'] ?? 'No items to show.'}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          const Text(
            'Add your first item on the Items tab and a place to keep it on '
            'Locations, and this page fills in.',
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton(
                onPressed: () => widget.onOpen('items'),
                child: const Text('Go to Items'),
              ),
              OutlinedButton(
                onPressed: () => widget.onOpen('locations'),
                child: const Text('Go to Locations'),
              ),
            ],
          ),
        ],
      );
    }

    final links = (health['links'] as Map?)?.cast<String, dynamic>() ?? {};
    final value = (health['value'] as Map?)?.cast<String, dynamic>();
    int count(String key) => (health[key] as num?)?.toInt() ?? 0;

    final figures = [
      OverviewFigure(
        key: const Key('inventory-general-items'),
        label: 'Items',
        value: '${count('items')}',
        note: '${count('in_stock')} in stock',
        onTap: _link(links['items']),
      ),
      if (value != null)
        OverviewFigure(
          key: const Key('inventory-general-value'),
          label: 'Stock value',
          value: inventoryMoney((value['amount_minor'] as num?)?.toInt() ?? 0),
          note: 'at last cost',
        ),
      OverviewFigure(
        key: const Key('inventory-general-low'),
        label: 'Below reorder',
        value: '${count('low')}',
        tone: count('low') > 0 ? Tone.caution : null,
        onTap: _link(links['low']),
      ),
      OverviewFigure(
        key: const Key('inventory-general-out'),
        label: 'Out of stock',
        value: '${count('out')}',
        tone: count('out') > 0 ? Tone.bad : null,
        onTap: _link(links['out']),
      ),
      OverviewFigure(
        key: const Key('inventory-general-locations'),
        label: 'Locations',
        value: '${count('locations')}',
        onTap: _link(links['locations']),
      ),
      OverviewFigure(
        key: const Key('inventory-general-orders'),
        label: 'Open purchase orders',
        value: '${count('open_purchase_orders')}',
        onTap: _link(links['open_purchase_orders']),
      ),
    ];

    final warnings = [
      for (final raw in (data['warnings'] as List? ?? const []))
        if (raw is Map)
          OverviewWarning(
            sentence: '${raw['sentence'] ?? ''}',
            tone: raw['key'] == 'out_of_stock' ? Tone.bad : Tone.caution,
            onTap: _link(raw['link']),
          ),
    ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        OverviewFigures(figures: figures),
        const SizedBox(height: 16),
        OverviewPair(
          first: OverviewSection(
            key: const ValueKey('tour-inventory-movement'),
            title: 'Movement',
            trailing: OverviewPeriodPicker(
              key: const ValueKey('tour-inventory-period'),
              period: _period,
              onChanged: (period) {
                setState(() => _period = period);
                _load();
              },
            ),
            child: _movement(context, data['movement']),
          ),
          second: OverviewSection(
            key: const ValueKey('tour-inventory-attention'),
            title: 'Needs attention',
            child: OverviewWarnings(warnings: warnings),
          ),
        ),
      ],
    );
  }

  /// Received and out as lines; adjusted (signed) and transferred (net zero
  /// between our own places, spec §8) as totals under the chart, so neither
  /// reads as extra volume.
  Widget _movement(BuildContext context, Object? raw) {
    final movement = raw is Map ? raw.cast<String, dynamic>() : const {};
    final buckets = [
      for (final b in (movement['buckets'] as List? ?? const [])) '$b',
    ];
    final lines = {
      for (final line in (movement['lines'] as List? ?? const []))
        if (line is Map) '${line['key']}': line,
    };

    List<num> points(String key) => [
      for (final p in (lines[key]?['points'] as List? ?? const []))
        (p as num?) ?? 0,
    ];
    num total(String key) => points(key).fold<num>(0, (a, b) => a + b);
    String qty(num n) => n == n.roundToDouble()
        ? n.toInt().toString()
        : n.toStringAsFixed(2);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        OverviewTrend(
          buckets: buckets,
          lines: [
            ('${lines['in']?['label'] ?? 'Received'}', points('in')),
            ('${lines['out']?['label'] ?? 'Issued and sold'}', points('out')),
          ],
          emptySentence: 'Nothing was received, issued or sold in this period.',
        ),
        const SizedBox(height: 12),
        OverviewBreakdown(
          rows: [
            ('Received', qty(total('in'))),
            ('Issued and sold', qty(total('out'))),
            ('Adjusted by a count', qty(total('adjusted'))),
            ('Moved between your places', qty(total('transferred'))),
          ],
        ),
      ],
    );
  }
}
