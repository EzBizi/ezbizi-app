import '../widgets/module_page_header.dart';
import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/tab_bar_like.dart';
import 'inventory/general_tab.dart';
import 'inventory/item_list.dart';
import 'inventory/location_list.dart';
import 'inventory/order_list.dart';
import 'inventory/stock_list.dart';

/// Inventory (spec §13).
///
/// Up to five chips — General (the module's dashboard, first and opened
/// first: spec 2026-09-09-inventory-general-tab, built 2026-10-02), Stock,
/// Items, Orders, Locations — each drawn only for a role holding its
/// `inventory.<tab>.view` (Jose, 2026-09-17: grant by sub tab).
/// Nothing selected: the list is the whole face. A row or Add selected: 25%
/// list / 75% pane. Below 600px the pane is the whole body with Back. Add is
/// that pane, not a modal.
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
  int _tab = 0;

  final Set<int> _shown = {0};

  /// The Stock tile a General figure asked for, with a count so asking twice
  /// for the same tile still reaches Stock.
  ({String? tile, int serial})? _stockTile;

  /// Every chip this screen has, with the permission key that opens it.
  ///
  /// ── Why the builder takes its own visibility ──────────────────
  ///
  /// It used to read `visible: _tab == 2`, a literal position. That was correct
  /// only while the list was never filtered: grant a role Items but not Stock
  /// and Items slides to index 0 while still asking whether index 1 is
  /// selected, so the pane that IS on screen believes it is not — no error, no
  /// red, just a list that never loads. The builder is handed the answer by the
  /// one place that knows the filtered order.
  late final List<(String, String, IconData, Widget Function(bool))>
  _declaredTabs = [
    (
      'general',
      'General',
      Icons.dashboard_outlined,
      (bool visible) => InventoryGeneral(
        api: widget.api,
        visible: visible,
        onOpen: _openLink,
      ),
    ),
    (
      'stock',
      'Stock',
      Icons.inventory_2_outlined,
      (bool visible) => StockList(
        api: widget.api,
        session: widget.session,
        visible: visible,
        tileRequest: _stockTile,
      ),
    ),
    (
      'items',
      'Items',
      Icons.category_outlined,
      (bool visible) =>
          ItemList(api: widget.api, session: widget.session, visible: visible),
    ),
    (
      'orders',
      'Orders',
      Icons.receipt_long_outlined,
      (bool visible) =>
          OrderList(api: widget.api, session: widget.session, visible: visible),
    ),
    (
      'locations',
      'Locations',
      Icons.place_outlined,
      (bool visible) => LocationList(
        api: widget.api,
        session: widget.session,
        visible: visible,
      ),
    ),
  ];

  /// The chips this caller may open, in declared order.
  ///
  /// `_tab` indexes THIS list, so a role without Stock opens on whatever it
  /// does hold rather than on a pane it may not see — the default falls out of
  /// the filtering instead of being a second rule that can disagree with it.
  List<(String, String, IconData, Widget Function(bool))> get _tabs => [
    for (final tab in _declaredTabs)
      if (_mayOpen(tab.$1)) tab,
  ];

  /// An absent `/me` answers NO. "I do not know yet" draws nothing, never
  /// everything.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('inventory.$tab.view') ?? false;

  /// Open the chip a General link names - `stock?low_items=1`, `orders` - if
  /// this role holds it. One it does not hold stays where it is.
  void _openLink(String link) {
    final uri = Uri.parse(link);
    final index = _tabs.indexWhere((tab) => tab.$1 == uri.path);
    if (index < 0) return;

    setState(() {
      if (uri.path == 'stock') {
        _stockTile = (
          tile: uri.queryParameters['low_items'] == '1' ? 'low' : null,
          serial: (_stockTile?.serial ?? 0) + 1,
        );
      }
      _tab = index;
      _shown.add(index);
    });
  }

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs;

    /*
     * No chip at all. The sidebar only offers Inventory with `inventory.view`,
     * so this is a role holding the module and none of its four tabs — a
     * misconfiguration rather than a refusal, and the migration that granted
     * every tab to every existing role should make it unreachable. Saying so
     * beats a blank pane that looks like a failed load.
     */
    if (tabs.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('No inventory tabs have been granted to your role.'),
        ),
      );
    }

    final selected = _tab.clamp(0, tabs.length - 1);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-inventory-tabs'),
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: selected,
            onSelected: _select,
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < tabs.length; i++)
                Offstage(
                  offstage: i != selected,
                  child: ModuleActionGate(
                    active: i == selected,
                    child: _shown.contains(i)
                        ? tabs[i].$4(i == selected)
                        : const SizedBox.shrink(),
                  ),
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

        final body = AsyncBody(
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
                        // A back arrow on wide screens too. The full-width table
                        // and tiles draw only while no pane is open, so a pane
                        // without one was a one-way door off the main view
                        // (Analorena Ventoso, 2026-10-01). Containment: every
                        // editor drawn in the module's area carries its own way
                        // back, at every width.
                        child: pane(showBack: true),
                      ),
                    ),
                  ],
                )
              : KeyedSubtree(key: const Key('inventory-list'), child: list),
        );

        // An open record on a phone is the whole screen, with its own back.
        if (paneOnly) return body;

        return HeaderAboveList(header: header, list: body);
      },
    );
  }
}
