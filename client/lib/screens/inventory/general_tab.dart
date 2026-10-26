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
/// that apply, each with its fix). Right: Movement for the chosen period
/// against the one before, Stock by location and the top five items.
///
/// Drawn only from what the server sends: a card whose list is missing or
/// empty is not drawn, and there are no overdue counts to show.
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

    final status = _MeasurableSection(
      key: const ValueKey('inventory-general-items'),
      title: 'Stock status',
      child: _StockStatus(health: health),
    );
    Widget attention({required bool narrow}) => _MeasurableSection(
      key: const ValueKey('tour-inventory-attention'),
      title: 'Needs attention',
      child: _attention(
        context,
        health,
        data['warnings'],
        data['purchase_orders'],
        narrow: narrow,
      ),
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
    final locations = _locationsCard(data['by_location']);
    final top = _topItemsCard(data['top_items']);
    final movementCard = _MeasurableSection(
      key: const ValueKey('tour-inventory-movement'),
      title: 'Movement',
      trailing: picker,
      child: movementBody,
    );

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
              if (locations != null) ...[const SizedBox(height: 16), locations],
              if (top != null) ...[const SizedBox(height: 16), top],
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
                        // The last card present takes the rest.
                        if (locations == null && top == null)
                          Expanded(child: movementCard)
                        else
                          movementCard,
                        if (locations != null) ...[
                          const SizedBox(height: 16),
                          if (top == null)
                            Expanded(child: locations)
                          else
                            locations,
                        ],
                        if (top != null) ...[
                          const SizedBox(height: 16),
                          Expanded(child: top),
                        ],
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

  /// Value and items per location. Value is drawn only when the server sent
  /// the key (financials); a null value is "Not priced", never zero.
  Widget? _locationsCard(Object? raw) {
    if (raw is! List || raw.isEmpty) return null;
    final rows = [
      for (final r in raw)
        if (r is Map) r,
    ];
    int itemsOf(Map r) => (r['items'] as num?)?.toInt() ?? 0;
    final most = rows.fold<int>(0, (a, r) => itemsOf(r) > a ? itemsOf(r) : a);

    return _MeasurableSection(
      key: const ValueKey('inventory-general-locations'),
      title: 'Stock by location',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final r in rows)
            _HoldingRow(
              key: Key('inventory-location-${(r['location'] as Map?)?['id']}'),
              name: '${(r['location'] as Map?)?['name'] ?? ''}',
              detail: itemsOf(r) == 1 ? '1 item' : '${itemsOf(r)} items',
              value: _valueText(r),
              share: most == 0 ? 0 : itemsOf(r) / most,
            ),
        ],
      ),
    );
  }

  /// The top five. Ranked by value when the server sends values, so the
  /// title says which ranking it is.
  Widget? _topItemsCard(Object? raw) {
    if (raw is! List || raw.isEmpty) return null;
    final rows = [
      for (final r in raw)
        if (r is Map) r,
    ];
    final byValue = rows.any((r) => r.containsKey('value_minor'));
    num qtyOf(Map r) => num.tryParse('${r['qty'] ?? 0}') ?? 0;
    num sizeOf(Map r) => byValue ? (r['value_minor'] as num?) ?? 0 : qtyOf(r);
    final most = rows.fold<num>(0, (a, r) => sizeOf(r) > a ? sizeOf(r) : a);

    return _MeasurableSection(
      key: const ValueKey('inventory-general-top'),
      title: byValue ? 'Top items by value' : 'Top items by quantity',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final r in rows)
            _HoldingRow(
              key: Key('inventory-top-${(r['item'] as Map?)?['id']}'),
              name: [
                (r['item'] as Map?)?['number'],
                (r['item'] as Map?)?['name'],
              ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
              detail: 'Qty ${_Movement._fmt(qtyOf(r))}',
              value: _valueText(r),
              share: most == 0 ? 0 : sizeOf(r) / most,
            ),
        ],
      ),
    );
  }

  /// Null when the key is absent (no financials); "Not priced" when null.
  static String? _valueText(Map r) {
    if (!r.containsKey('value_minor')) return null;
    final v = r['value_minor'];
    return v is num ? inventoryMoney(v.toInt()) : 'Not priced';
  }

  /// Rows only for what applies, each with the button that fixes it. The
  /// server's other warnings open the list their link names.
  Widget _attention(
    BuildContext context,
    Map<String, dynamic> health,
    Object? rawWarnings,
    Object? rawOrders, {
    required bool narrow,
  }) {
    int count(String key) => (health[key] as num?)?.toInt() ?? 0;
    String plural(int n, String one, String many) => n == 1 ? one : many;

    final low = count('low');
    final out = count('out');
    // Only an open order can be received; drafts are a note on the row. A
    // server without the split falls back to the combined count.
    final split = rawOrders is Map ? rawOrders : null;
    final orders = split == null
        ? count('open_purchase_orders')
        : (split['open'] as num?)?.toInt() ?? 0;
    final drafts = (split?['draft'] as num?)?.toInt() ?? 0;
    final draftNote = drafts == 0
        ? ''
        : drafts == 1
        ? ' 1 draft is not sent yet.'
        : ' $drafts drafts are not sent yet.';

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
            '1 purchase order is open to receive.$draftNote',
            '$orders purchase orders are open to receive.$draftNote',
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
/// others), each over a thinner bar for the previous period when the server
/// sends `movement.previous`.
class _Movement extends StatelessWidget {
  const _Movement({required this.raw});

  final Object? raw;

  static String _fmt(num n) =>
      n == n.roundToDouble() ? n.toInt().toString() : n.toStringAsFixed(2);

  /// "▲ 6 vs 12 last period", "▼ 3 vs ...", or "Same as last period".
  static String _change(num now, num was) {
    final diff = now - was;
    if (diff == 0) return 'Same as last period';
    return '${diff > 0 ? '▲' : '▼'} ${_fmt(diff.abs())} vs ${_fmt(was)} '
        'last period';
  }

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

    final previous = movement['previous'] is Map
        ? (movement['previous'] as Map)
        : null;
    num? before(String key) =>
        previous == null ? null : (previous[key] as num?) ?? 0;

    final rows = [
      ('in', 'Received', total('in'), before('received')),
      ('out', 'Issued and sold', total('out'), before('issued_sold')),
      ('adjusted', 'Adjusted', total('adjusted'), before('adjusted')),
      (
        'transferred',
        'Moved between your places',
        total('transferred'),
        before('moved'),
      ),
    ];

    if (rows.every((r) => r.$3 == 0 && (r.$4 ?? 0) == 0)) {
      return Text(
        'Nothing was received, issued, adjusted or moved in this period.',
        key: const Key('inventory-movement-empty'),
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      );
    }

    final most = rows
        .expand((r) => [r.$3.abs(), (r.$4 ?? 0).abs()])
        .reduce((a, b) => a > b ? a : b);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (key, label, n, was) in rows)
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
                if (was != null) ...[
                  const SizedBox(height: 2),
                  _Bar(
                    key: Key('inventory-movement-$key-previous'),
                    share: most == 0 ? 0 : was.abs() / most,
                    previous: true,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    _change(n, was),
                    key: Key('inventory-movement-$key-change'),
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    // The arrows come from a fallback font whose taller line
                    // the intrinsic pass did not count on the web: 2 px a row
                    // overflowed the stretched last card. A forced strut
                    // gives both passes one line height.
                    strutStyle: StrutStyle.fromTextStyle(
                      theme.textTheme.bodySmall ?? const TextStyle(),
                      forceStrutHeight: true,
                    ),
                  ),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

/// A thin bar filled to [share] (0..1) of its width.
class _Bar extends StatelessWidget {
  const _Bar({super.key, required this.share, this.previous = false});

  final num share;

  /// The previous period's bar: thinner and in the outline colour.
  final bool previous;

  @override
  Widget build(BuildContext context) {
    final filled = (share * 1000).round().clamp(0, 1000);

    return ClipRRect(
      borderRadius: BorderRadius.circular(4),
      child: SizedBox(
        height: previous ? 4 : 8,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (filled > 0)
              Expanded(
                flex: filled,
                child: ColoredBox(
                  color: previous
                      ? Theme.of(context).colorScheme.outline
                      : Theme.of(context).colorScheme.primary,
                ),
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
/// layout, which SegmentedButton cannot give, and under-reports its own
/// intrinsic height, which overflowed the stretched last card by 6 px with
/// real fonts. The fixed-height trailing answers without asking the button.
class _MeasurableSection extends StatelessWidget {
  const _MeasurableSection({
    super.key,
    required this.title,
    this.trailing,
    required this.child,
  });

  final String title;
  final Widget? trailing;
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
              if (trailing != null) ...[
                const SizedBox(width: 12),
                SizedBox(height: 40, child: trailing),
              ],
            ],
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}

/// One location or item: name, detail, optional value, and a bar.
class _HoldingRow extends StatelessWidget {
  const _HoldingRow({
    super.key,
    required this.name,
    required this.detail,
    required this.value,
    required this.share,
  });

  final String name;
  final String detail;
  final String? value;
  final num share;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  name,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              const SizedBox(width: 8),
              Text(detail, style: muted),
              if (value != null) ...[
                const SizedBox(width: 12),
                Text(
                  value!,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 4),
          _Bar(share: share),
        ],
      ),
    );
  }
}
