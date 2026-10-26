import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/module_overview.dart';
import 'stock_join.dart';

/// Inventory's General tab, laid out the way design 149 (option 1, phone 4)
/// laid out Fleet's: two columns on a desktop, one on a phone, from one
/// request to `GET /inventory/summary`.
///
/// Left: Stock status (one stacked bar) and Needs attention (only the rows
/// that apply, each with its fix). Right: Movement for the chosen period.
///
/// Drawn only from what the server sends. Stock by location, top items by
/// value, movement against the previous period and overdue counts are not in
/// the payload, so they are not drawn.
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

  /// Below this width the two columns stack.
  static const _twoColumns = 900.0;

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

    final status = OverviewSection(
      key: const ValueKey('inventory-general-items'),
      title: 'Stock status',
      child: _StockStatus(health: health),
    );
    Widget attention({required bool narrow}) => OverviewSection(
      key: const ValueKey('tour-inventory-attention'),
      title: 'Needs attention',
      child: _attention(context, health, data['warnings'], narrow: narrow),
    );
    final picker = OverviewPeriodPicker(
      key: const ValueKey('tour-inventory-period'),
      period: _period,
      onChanged: (period) {
        setState(() => _period = period);
        _load();
      },
    );
    final movementBody = _Movement(raw: data['movement']);

    return LayoutBuilder(
      builder: (context, box) {
        if (box.maxWidth < _twoColumns) {
          return ListView(
            key: const Key('inventory-general-one-column'),
            padding: const EdgeInsets.all(16),
            children: [
              status,
              const SizedBox(height: 16),
              attention(narrow: true),
              const SizedBox(height: 16),
              OverviewSection(
                key: const ValueKey('tour-inventory-movement'),
                title: 'Movement',
                trailing: picker,
                child: movementBody,
              ),
            ],
          );
        }

        // IntrinsicHeight + stretch, with the last card of each column
        // Expanded, so both columns end on the same line whatever is in them.
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(
                    key: const Key('inventory-general-left'),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        status,
                        const SizedBox(height: 16),
                        Expanded(child: attention(narrow: false)),
                      ],
                    ),
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    key: const Key('inventory-general-right'),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Expanded(
                          child: _MeasurableSection(
                            key: const ValueKey('tour-inventory-movement'),
                            title: 'Movement',
                            trailing: picker,
                            child: movementBody,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  /// Rows only for what applies, each with the button that fixes it. The
  /// server's other warnings open the list their link names.
  Widget _attention(
    BuildContext context,
    Map<String, dynamic> health,
    Object? rawWarnings, {
    required bool narrow,
  }) {
    int count(String key) => (health[key] as num?)?.toInt() ?? 0;
    String plural(int n, String one, String many) => n == 1 ? one : many;

    final low = count('low');
    final out = count('out');
    final orders = count('open_purchase_orders');

    final rows = <Widget>[
      if (low > 0)
        _AttentionRow(
          narrow: narrow,
          key: const Key('inventory-attention-low'),
          tone: Tone.caution,
          sentence: plural(
            low,
            '1 item is at or below its reorder point.',
            '$low items are at or below their reorder point.',
          ),
          fix: 'Create purchase order',
          onFix: () => widget.onOpen('stock?low_items=1'),
        ),
      if (out > 0)
        _AttentionRow(
          narrow: narrow,
          key: const Key('inventory-attention-out'),
          tone: Tone.bad,
          sentence: plural(
            out,
            '1 item is out of stock.',
            '$out items are out of stock.',
          ),
          fix: 'Reorder',
          onFix: () => widget.onOpen('orders'),
        ),
      if (orders > 0)
        _AttentionRow(
          narrow: narrow,
          key: const Key('inventory-attention-receive'),
          tone: Tone.info,
          sentence: plural(
            orders,
            '1 purchase order is open.',
            '$orders purchase orders are open.',
          ),
          fix: 'Receive',
          onFix: () => widget.onOpen('orders'),
        ),
      for (final raw in (rawWarnings as List? ?? const []))
        if (raw is Map &&
            !const {'out_of_stock', 'below_reorder'}.contains(raw['key']))
          _AttentionRow(
            narrow: narrow,
            key: Key('inventory-attention-${raw['key']}'),
            tone: Tone.caution,
            sentence: '${raw['sentence'] ?? ''}',
            fix: 'Open',
            onFix: raw['link'] is String
                ? () => widget.onOpen(raw['link'] as String)
                : null,
          ),
    ];

    if (rows.isEmpty) {
      final ok = ToneColours.of(context, Tone.ok);
      return Row(
        key: const Key('inventory-attention-clear'),
        children: [
          Icon(Icons.check_circle_outline, color: ok.ink, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'Nothing needs attention.',
              style: Theme.of(
                context,
              ).textTheme.bodyMedium?.copyWith(color: ok.ink),
            ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < rows.length; i++) ...[
          if (i > 0) const Divider(height: 16),
          rows[i],
        ],
      ],
    );
  }
}

/// One stacked bar: in stock, below reorder, out of stock; and the value line.
class _StockStatus extends StatelessWidget {
  const _StockStatus({required this.health});

  final Map<String, dynamic> health;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    int count(String key) => (health[key] as num?)?.toInt() ?? 0;
    final value = (health['value'] as Map?)?.cast<String, dynamic>();
    final locations = count('locations');

    final parts = [
      ('in_stock', 'In stock', Tone.ok, count('in_stock')),
      ('low', 'Below reorder', Tone.caution, count('low')),
      ('out', 'Out of stock', Tone.bad, count('out')),
    ];
    final total = parts.fold<int>(0, (a, p) => a + p.$4);

    final where = locations == 1 ? '1 location' : '$locations locations';
    final line = value == null
        ? where
        : 'Stock value '
              '${inventoryMoney((value['amount_minor'] as num?)?.toInt() ?? 0)}'
              ' at last cost · $where';

    Widget dot(Tone tone) => Container(
      width: 10,
      height: 10,
      decoration: BoxDecoration(
        color: ToneColours.of(context, tone).ink,
        shape: BoxShape.circle,
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          child: SizedBox(
            key: const Key('inventory-status-bar'),
            height: 16,
            child: total == 0
                ? ColoredBox(color: EzBiziTheme.edge(context))
                : Row(
                    // Stretch: a childless ColoredBox is otherwise 0 high.
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (final (key, _, tone, n) in parts)
                        if (n > 0)
                          Expanded(
                            key: Key('inventory-status-bar-$key'),
                            flex: n,
                            child: ColoredBox(
                              color: ToneColours.of(context, tone).ink,
                            ),
                          ),
                    ],
                  ),
          ),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 16,
          runSpacing: 8,
          children: [
            for (final (key, label, tone, n) in parts)
              Row(
                key: Key('inventory-status-$key'),
                mainAxisSize: MainAxisSize.min,
                children: [
                  dot(tone),
                  const SizedBox(width: 6),
                  Text('$label ', style: theme.textTheme.bodyMedium),
                  Text(
                    '$n',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
          ],
        ),
        const SizedBox(height: 12),
        Text(
          line,
          key: const Key('inventory-status-value'),
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }
}

class _AttentionRow extends StatelessWidget {
  const _AttentionRow({
    super.key,
    required this.tone,
    required this.sentence,
    required this.fix,
    required this.onFix,
    required this.narrow,
  });

  /// On a phone the button goes under the sentence rather than beside it.
  final bool narrow;
  final Tone tone;
  final String sentence;
  final String fix;
  final VoidCallback? onFix;

  @override
  Widget build(BuildContext context) {
    final line = Row(
      children: [
        Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(
            color: ToneColours.of(context, tone).ink,
            shape: BoxShape.circle,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(child: Text(sentence)),
        if (onFix != null && !narrow) ...[
          const SizedBox(width: 8),
          OutlinedButton(onPressed: onFix, child: Text(fix)),
        ],
      ],
    );
    if (onFix == null || !narrow) return line;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        line,
        const SizedBox(height: 8),
        OutlinedButton(onPressed: onFix, child: Text(fix)),
      ],
    );
  }
}

/// The period's totals as bars: received, issued and sold, adjusted (signed)
/// and moved between your own places (net zero, never folded into the
/// others). The server sends no previous period, so nothing is set against
/// it.
class _Movement extends StatelessWidget {
  const _Movement({required this.raw});

  final Object? raw;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final source = raw;
    final movement = source is Map
        ? source.cast<String, dynamic>()
        : const <String, dynamic>{};
    final lines = {
      for (final line in (movement['lines'] as List? ?? const []))
        if (line is Map) '${line['key']}': line,
    };
    num total(String key) => [
      for (final p in (lines[key]?['points'] as List? ?? const []))
        (p as num?) ?? 0,
    ].fold<num>(0, (a, b) => a + b);
    String qty(num n) =>
        n == n.roundToDouble() ? n.toInt().toString() : n.toStringAsFixed(2);

    final rows = [
      ('in', 'Received', total('in')),
      ('out', 'Issued and sold', total('out')),
      ('adjusted', 'Adjusted', total('adjusted')),
      ('transferred', 'Moved between your places', total('transferred')),
    ];

    if (rows.every((r) => r.$3 == 0)) {
      return Text(
        'Nothing was received, issued, adjusted or moved in this period.',
        key: const Key('inventory-movement-empty'),
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      );
    }

    final most = rows.map((r) => r.$3.abs()).reduce((a, b) => a > b ? a : b);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (key, label, n) in rows)
          Padding(
            key: Key('inventory-movement-$key'),
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(label, style: theme.textTheme.bodyMedium),
                    ),
                    Text(
                      qty(n),
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                _Bar(share: most == 0 ? 0 : n.abs() / most),
              ],
            ),
          ),
      ],
    );
  }
}

/// A thin bar filled to [share] (0..1) of its width.
class _Bar extends StatelessWidget {
  const _Bar({required this.share});

  final num share;

  @override
  Widget build(BuildContext context) {
    final filled = (share * 1000).round().clamp(0, 1000);

    return ClipRRect(
      borderRadius: BorderRadius.circular(4),
      child: SizedBox(
        height: 8,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (filled > 0)
              Expanded(
                flex: filled,
                child: ColoredBox(color: Theme.of(context).colorScheme.primary),
              ),
            if (filled < 1000)
              Expanded(
                flex: 1000 - filled,
                child: ColoredBox(color: EzBiziTheme.edge(context)),
              ),
          ],
        ),
      ),
    );
  }
}

/// [OverviewSection]'s look with a one-line header, for inside
/// IntrinsicHeight: OverviewSection's Wrap asks its children for a dry
/// layout, which SegmentedButton cannot give, and the fixed-height trailing
/// answers its intrinsic height without asking the button at all.
class _MeasurableSection extends StatelessWidget {
  const _MeasurableSection({
    super.key,
    required this.title,
    required this.trailing,
    required this.child,
  });

  final String title;
  final Widget trailing;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text(title, style: theme.textTheme.titleMedium)),
              const SizedBox(width: 12),
              SizedBox(height: 40, child: trailing),
            ],
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}
