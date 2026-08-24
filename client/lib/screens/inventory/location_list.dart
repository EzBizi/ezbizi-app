import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/load_more.dart';
import '../../widgets/refused.dart';
import '../../widgets/tag.dart';
import '../inventory_screen.dart';
import 'add_location_pane.dart';
import 'item_pane.dart';
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
    });
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
        title: 'Locations',
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
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: TextField(
            controller: _search,
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: 'Name',
              suffixIcon: _search.text.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.clear),
                      tooltip: 'Clear the search',
                      onPressed: () {
                        _search.clear();
                        _load();
                      },
                    ),
            ),
            onSubmitted: (_) => _load(),
            onChanged: (_) => setState(() {}),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: FilterChip(
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
              ? Center(
                  child: Text(
                    _search.text.trim().isEmpty && !_closed
                        ? 'There are no locations.'
                        : 'Nothing matches those filters.',
                  ),
                )
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  itemCount: _locations.length + (_page < _lastPage ? 1 : 0),
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    if (i == _locations.length) {
                      return LoadMore(
                        shown: _locations.length,
                        total: _total,
                        busy: _fetching,
                        error: _pageError,
                        onMore: () => _load(page: _page + 1),
                      );
                    }
                    return _row(context, _locations[i]);
                  },
                ),
        ),
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
