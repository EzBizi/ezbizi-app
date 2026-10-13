import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/load_more.dart';
import '../../widgets/refused.dart';
import '../../widgets/tag.dart';
import '../inventory/item_pane.dart';
import '../inventory_screen.dart';
import 'add_service_pane.dart';
import 'service_pane.dart';

/// Catalogue chip: services for this view.
class ServiceList extends StatefulWidget {
  const ServiceList({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<ServiceList> createState() => _ServiceListState();
}

class _ServiceListState extends State<ServiceList> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _services = [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;
  bool _fetching = false;
  int _request = 0;
  bool _adding = false;
  String? _selectedId;
  Map<String, dynamic>? _open;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant ServiceList oldWidget) {
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
      _loading = page == 1 && _services.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/services', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
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
      _services = page == 1 ? rows : [..._services, ...rows];
      _total = inventoryTotal(response.body, _services.length);
      _lastPage = inventoryLastPage(response.body);
      _page = page;
      if (_open != null && _selectedId != null) {
        final match = _services.where((row) => row['id'] == _selectedId);
        if (match.isNotEmpty) _open = match.first;
      }
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
    final canCreate = me.can('services.create');
    final nowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('add a service')
        : null;
    final refused = canCreate ? nowhere : null;

    return InventorySplitScaffold(
      hasPane: _hasPane,
      loading: _loading,
      error: _error,
      onRetry: _load,
      header: PanelHeader(
        note: (_loading || _error != null)
            ? 'What this company sells as work.'
            : _services.length < _total
            ? 'Showing ${_services.length} of $_total.'
            : _total == 1
            ? '1 service in the catalogue.'
            : '$_total in the catalogue.',
        action: canCreate
            ? Refused(
                reason: refused,
                child: FilledButton.icon(
                  key: const ValueKey('tour-services-add'),
                  onPressed: refused == null
                      ? () => setState(() {
                          _adding = true;
                          _selectedId = null;
                          _open = null;
                        })
                      : null,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add service'),
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
            key: const ValueKey('tour-services-search'),
            controller: _search,
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: 'Name or number',
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
        Expanded(
          child: _services.isEmpty
              ? Center(
                  child: Text(
                    _search.text.trim().isEmpty
                        ? 'There are no services.'
                        : 'Nothing matches that search.',
                  ),
                )
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  itemCount: _services.length + (_page < _lastPage ? 1 : 0),
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    if (i == _services.length) {
                      return LoadMore(
                        shown: _services.length,
                        total: _total,
                        busy: _fetching,
                        error: _pageError,
                        onMore: () => _load(page: _page + 1),
                      );
                    }
                    return _row(context, _services[i]);
                  },
                ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> service) {
    final id = service['id'] as String?;
    final label = inventoryMap(service['label']);
    final group = inventoryMap(service['group']);
    final price = inventoryCents(service['price_minor']);

    return Card(
      child: ListTile(
        selected: id != null && id == _selectedId,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => setState(() {
          _adding = false;
          _selectedId = id;
          _open = service;
        }),
        leading: const SizedBox(
          width: 48,
          height: 48,
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.all(Radius.circular(8)),
            ),
            child: Icon(Icons.handyman_outlined),
          ),
        ),
        title: Text(
          service['name'] as String? ?? 'Service',
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
                  if (label != null) Tag(label['name'] as String? ?? ''),
                  if (group != null) Tag(group['name'] as String? ?? ''),
                ],
              ),
              Text(
                [
                  price,
                  service['number'],
                  inventoryOwnerLabel(
                    service,
                    widget.session.me!.organization.name,
                  ),
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
      return AddServicePane(
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

    final row = _open;
    if (row == null) return const SizedBox.shrink();

    return ServicePane(
      api: widget.api,
      session: widget.session,
      service: row,
      showBack: showBack,
      onBack: _clearPane,
      onChanged: (updated) {
        setState(() {
          _open = updated;
          _selectedId = updated['id'] as String?;
        });
        _load();
      },
      onDeleted: () {
        _clearPane();
        _load();
      },
    );
  }
}
