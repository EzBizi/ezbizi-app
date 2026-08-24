import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/tab_bar_like.dart';
import 'inventory/item_list.dart';
import 'inventory/location_list.dart';
import 'inventory/order_list.dart';
import 'inventory/stock_list.dart';

/// Inventory (spec §13).
///
/// Four chips, not a coming-soon tab. Nothing selected: the list is the whole
/// face. A row or Add selected: 25% list / 75% pane. Below 600px the pane is
/// the whole body with Back. Add is that pane, not a modal.
class InventoryScreen extends StatefulWidget {
  const InventoryScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Below this the selected row (or Add) is the whole body.
  static const double splitAbove = 600;

  @override
  State<InventoryScreen> createState() => _InventoryScreenState();
}

class _InventoryScreenState extends State<InventoryScreen> {
  static const _stock = 0;

  int _tab = _stock;

  final Set<int> _shown = {_stock};

  late final List<(String, IconData, Widget Function())> _tabs = [
    (
      'Stock',
      Icons.inventory_2_outlined,
      () => StockList(
        api: widget.api,
        session: widget.session,
        visible: _tab == 0,
      ),
    ),
    (
      'Items',
      Icons.category_outlined,
      () => ItemList(
        api: widget.api,
        session: widget.session,
        visible: _tab == 1,
      ),
    ),
    (
      'Orders',
      Icons.receipt_long_outlined,
      () => OrderList(
        api: widget.api,
        session: widget.session,
        visible: _tab == 2,
      ),
    ),
    (
      'Locations',
      Icons.place_outlined,
      () => LocationList(
        api: widget.api,
        session: widget.session,
        visible: _tab == 3,
      ),
    ),
  ];

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            labels: [for (final tab in _tabs) (tab.$1, tab.$2)],
            selected: _tab,
            onSelected: _select,
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < _tabs.length; i++)
                Offstage(
                  offstage: i != _tab,
                  child: _shown.contains(i)
                      ? _tabs[i].$3()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

/// 25/75 list + pane, collapsing below [InventoryScreen.splitAbove].
class InventorySplitScaffold extends StatelessWidget {
  const InventorySplitScaffold({
    super.key,
    required this.hasPane,
    required this.header,
    required this.list,
    required this.pane,
    required this.loading,
    required this.error,
    required this.onRetry,
  });

  final bool hasPane;
  final Widget header;
  final Widget list;
  final Widget Function({required bool showBack}) pane;
  final bool loading;
  final String? error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= InventoryScreen.splitAbove;
        final paneOnly = !wide && hasPane;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (!paneOnly) header,
            Expanded(
              child: AsyncBody(
                loading: loading,
                error: error,
                onRetry: onRetry,
                child: paneOnly
                    ? pane(showBack: true)
                    : wide && hasPane
                    ? Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            flex: 1,
                            child: KeyedSubtree(
                              key: const Key('inventory-list'),
                              child: list,
                            ),
                          ),
                          VerticalDivider(
                            width: 1,
                            thickness: 1,
                            color: EzBiziTheme.edge(context),
                          ),
                          Expanded(
                            flex: 3,
                            child: KeyedSubtree(
                              key: const Key('inventory-pane'),
                              child: pane(showBack: false),
                            ),
                          ),
                        ],
                      )
                    : KeyedSubtree(
                        key: const Key('inventory-list'),
                        child: list,
                      ),
              ),
            ),
          ],
        );
      },
    );
  }
}
