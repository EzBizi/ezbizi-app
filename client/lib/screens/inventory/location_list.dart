import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../widgets/empty_state.dart';
import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/load_more.dart';
import '../../widgets/refused.dart';
import '../../widgets/tag.dart';
import '../inventory_screen.dart';
import 'add_location_pane.dart';
import 'item_pane.dart';
import 'stock_join.dart';
import 'location_pane.dart';

/// Locations chip: the places stock is kept.
class LocationList extends StatefulWidget {
  const LocationList({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<LocationList> createState() => _LocationListState();
}

class _LocationListState extends State<LocationList> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _locations = [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;
  bool _fetching = false;
  int _request = 0;
  bool _closed = false;

  /// The tile narrowing the list (null, `storage`, `shop` or `van`), and the
  /// counts on the tiles: open places, across every page (Inventory,
  /// 83999f2c), with their values beside them for a role with financials.
  String? _tile;
  Map<String, dynamic>? _tiles;
  Map<String, dynamic>? _tileValues;
  bool _adding = false;
  String? _selectedId;
  Map<String, dynamic>? _open;


  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant LocationList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  bool get _hasPane => _adding || _open != null;

  Future<void> _load({int page = 1}) async {
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      _loading = page == 1 && _locations.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/inventory/locations', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      if (_closed) 'status': 'active,closed',
      'kind': ?_tile,
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _fetching = false;

      if (!response.ok) {
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = inventoryRows(response.data);
      _locations = page == 1 ? rows : [..._locations, ...rows];
      _total = inventoryTotal(response.body, _locations.length);
      _lastPage = inventoryLastPage(response.body);
      _page = page;
      if (page == 1) {
        final meta = response.body['meta'];
        Map<String, dynamic>? block(String key) => meta is Map && meta[key] is Map
            ? Map<String, dynamic>.from(meta[key] as Map)
            : null;
        _tiles = block('tiles');
        _tileValues = block('tiles_value_minor');
      }
    });
  }

  /// Stock's tiles, for the places stock is kept (Analorena Ventoso,
  /// suggestion 0075). A value is the server's, and null where nothing there
  /// is priced: said as nothing, never as $0.00.
  List<InventoryTile> _tileList(Map<String, dynamic> tiles) {
    InventoryTile tile(String? key, String count, String label, String what) {
      final n = (tiles[count] as num?)?.toInt() ?? 0;
      final value = _tileValues?[count] as num?;
      return InventoryTile(
        key: key,
        label: label,
        value: '$n',
        note: value == null ? what : '${inventoryMoney(value.toInt())} held',
      );
    }

    return [
      tile(null, 'places', 'All places', 'open places'),
      tile('storage', 'storage', 'Storage', 'warehouses, cages, receiving'),
      tile('shop', 'shops', 'Shops', 'shops'),
      tile('van', 'vans', 'Vans', 'vans'),
    ];
  }

  void _clearPane() => setState(() {
    _adding = false;
    _selectedId = null;
    _open = null;
  });

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final canCreate = me.can('inventory.create');
    final nowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('add a location')
        : null;
    final refused = canCreate ? nowhere : null;

    return InventorySplitScaffold(
      hasPane: _hasPane,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        note: (_loading || _error != null)
            ? 'Where stock is kept.'
            : _locations.length < _total
            ? 'Showing ${_locations.length} of $_total.'
            : _total == 1
            ? '1 location in this view.'
            : '$_total in this view.',
        action: canCreate
            ? Refused(
                reason: refused,
                child: FilledButton.icon(
                  key: const ValueKey('tour-inventory-locations-add'),
                  onPressed: refused == null
                      ? () => setState(() {
                          _adding = true;
                          _selectedId = null;
                          _open = null;
                        })
                      : null,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add location'),
                ),
              )
            : null,
      ),
      list: _list(),
      pane: ({required showBack}) => _pane(showBack: showBack),
    );
  }

  Widget _list() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_tiles case final tiles?)
          Padding(
            key: const ValueKey('tour-inventory-locations-tiles'),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
            child: LayoutBuilder(
              builder: (context, box) => inventoryTiles(
                context,
                prefix: 'locations',
                tiles: _tileList(tiles),
                picked: _tile,
                onPick: (key) {
                  setState(() => _tile = key);
                  _load();
                },
                twoByTwo: box.maxWidth < 760,
              ),
            ),
          ),
        Padding(
          key: const ValueKey('tour-inventory-locations-search'),
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: ListSearch(
            controller: _search,
            hint: 'Name',
            onChanged: (_) => setState(() {}),
            onSubmitted: (_) => _load(),
            onClear: () {
                          _search.clear();
                          _load();
                        },
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: FilterChip(
              key: const ValueKey('tour-inventory-locations-closed'),
              label: const Text('Closed'),
              selected: _closed,
              onSelected: (_) {
                setState(() => _closed = !_closed);
                _load();
              },
            ),
          ),
        ),
        Expanded(
          child: _locations.isEmpty
              ? _search.text.trim().isEmpty && !_closed && _tile == null
                    ? const EmptyState(
                        icon: Icons.warehouse_outlined,
                        title: 'No locations yet',
                        message: 'There are no locations.',
                      )
                    : const EmptyState(
                        icon: Icons.search_off,
                        title: 'No matches',
                        message: 'Nothing matches those filters.',
                      )
              : LayoutBuilder(
                  builder: (context, box) => box.maxWidth >= 760 && !_hasPane
                      ? _table(context)
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                          itemCount:
                              _locations.length + (_page < _lastPage ? 1 : 0),
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (context, i) {
                            if (i == _locations.length) return _more();
                            return _row(context, _locations[i]);
                          },
                        ),
                ),
        ),
      ],
    );
  }

  Widget _more() => LoadMore(
    shown: _locations.length,
    total: _total,
    busy: _fetching,
    error: _pageError,
    onMore: () => _load(page: _page + 1),
  );

  /// One line per place: its kind, how many items it holds and what they
  /// are worth at last cost (UI queue 43, option 1).
  Widget _table(BuildContext context) {
    final theme = Theme.of(context);
    final money = widget.session.me!.can('inventory.financials');

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        inventoryTableRow(context, [
          (4, inventoryHeading(context, 'Place')),
          (2, inventoryHeading(context, 'Kind')),
          (2, inventoryHeading(context, 'Holds', align: TextAlign.right)),
          if (money)
            (2, inventoryHeading(context, 'Value', align: TextAlign.right)),
          (2, const SizedBox.shrink()),
        ]),
        for (final location in _locations)
          () {
            final id = location['id'] as String?;
            // Both null without Stock; value_minor absent without
            // financials and null when nothing there has a cost (8f2d23be).
            final holds = location['holds'];
            final value = location['value_minor'];
            final status = location['status'] as String? ?? '';
            final vehicle = inventoryMap(location['vehicle']);

            return inventoryTableRow(
              context,
              key: Key('location-row-$id'),
              selected: id != null && id == _selectedId,
              onTap: () => setState(() {
                _adding = false;
                _selectedId = id;
                _open = location;
              }),
              [
                (
                  4,
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        location['name'] as String? ?? 'Location',
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleSmall,
                      ),
                      Text(
                        [
                          vehicle?['label'],
                          inventoryOwnerLabel(
                            location,
                            widget.session.me!.organization.name,
                          ),
                        ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                (2, Text(inventoryKindLabel(location['kind'] as String?))),
                (
                  2,
                  Text(
                    holds is! num
                        ? '—'
                        : holds == 1
                        ? '1 item'
                        : '$holds items',
                    textAlign: TextAlign.right,
                  ),
                ),
                if (money)
                  (
                    2,
                    Text(
                      value is num ? inventoryMoney(value.toInt()) : '—',
                      textAlign: TextAlign.right,
                    ),
                  ),
                (
                  2,
                  Align(
                    alignment: Alignment.centerRight,
                    child: status == 'closed'
                        ? const Tag('Closed', warn: true)
                        : const SizedBox.shrink(),
                  ),
                ),
              ],
            );
          }(),
        if (_page < _lastPage) _more(),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> location) {
    final id = location['id'] as String?;
    final kind = location['kind'] as String?;
    final status = location['status'] as String? ?? '';
    final vehicle = inventoryMap(location['vehicle']);

    return Card(
      child: ListTile(
        selected: id != null && id == _selectedId,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => setState(() {
          _adding = false;
          _selectedId = id;
          _open = location;
        }),
        title: Text(
          location['name'] as String? ?? 'Location',
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Wrap(
                children: [
                  if (kind != null) Tag(inventoryKindLabel(kind)),
                  if (status.isNotEmpty)
                    Tag(
                      inventoryLocationStatusLabel(status),
                      warn: status == 'closed',
                    ),
                ],
              ),
              Text(
                [
                  inventoryOwnerLabel(
                    location,
                    widget.session.me!.organization.name,
                  ),
                  inventoryPlaceLabel(location),
                  vehicle?['label'],
                ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _pane({required bool showBack}) {
    if (_adding) {
      return AddLocationPane(
        api: widget.api,
        session: widget.session,
        showBack: showBack,
        onCancel: _clearPane,
        onSaved: (created) {
          setState(() {
            _adding = false;
            _open = created;
            _selectedId = created['id'] as String?;
          });
          _load();
        },
      );
    }

    final location = _open;
    if (location == null) return const SizedBox.shrink();

    return LocationPane(
      location: location,
      organizationName: widget.session.me!.organization.name,
      showBack: showBack,
      onBack: _clearPane,
    );
  }
}
