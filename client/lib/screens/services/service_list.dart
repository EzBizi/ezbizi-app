import '../../widgets/module_page_header.dart';
import '../../widgets/list_search.dart';
import 'dart:typed_data';

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
  String? _kind;
  String _sort = 'name';

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
            ? ModuleAction(
                child: Refused(
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
                ),
              )
            : null,
      ),
      list: _list(),
      pane: ({required showBack}) => _pane(showBack: showBack),
    );
  }

  /// Grid or List, remembered per person (UI queue 148).
  static const viewPreference = 'services.catalogue_view';

  bool get _grid => widget.session.me?.preferences[viewPreference] != 'list';

  void _openService(Map<String, dynamic> service) => setState(() {
    _adding = false;
    _selectedId = service['id'] as String?;
    _open = service;
  });

  List<Map<String, dynamic>> _sorted(List<Map<String, dynamic>> rows) {
    final out = [...rows];
    if (_sort == 'price') {
      out.sort(
        (a, b) => ((a['price_minor'] as num?) ?? 0).compareTo(
          (b['price_minor'] as num?) ?? 0,
        ),
      );
    } else {
      out.sort(
        (a, b) => ('${a['name'] ?? ''}').toLowerCase().compareTo(
          ('${b['name'] ?? ''}').toLowerCase(),
        ),
      );
    }
    return out;
  }

  Widget _list() {
    final kinds = <String, int>{};
    for (final s in _services) {
      final k = serviceKind(s);
      kinds[k] = (kinds[k] ?? 0) + 1;
    }
    final kindNames = kinds.keys.toList()
      ..sort((a, b) {
        if (a == 'Other') return 1;
        if (b == 'Other') return -1;
        return a.toLowerCase().compareTo(b.toLowerCase());
      });
    final kind = kinds.containsKey(_kind) ? _kind : null;
    final shown = _sorted(
      kind == null
          ? _services
          : _services.where((s) => serviceKind(s) == kind).toList(),
    );
    final groups = <String, List<Map<String, dynamic>>>{
      for (final k in kindNames)
        if (kind == null || k == kind)
          k: shown.where((s) => serviceKind(s) == k).toList(),
    };
    final more = _page < _lastPage;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: ListSearch(
              key: const ValueKey('tour-services-search'),
              controller: _search,
              hint: 'Name or number',
              onClear: () {
                _search.clear();
                _load();
              },
              onSubmitted: (_) => _load(),
              onChanged: (_) => setState(() {}),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SegmentedButton<String>(
                key: const ValueKey('services-sort'),
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(value: 'name', label: Text('Name')),
                  ButtonSegment(value: 'price', label: Text('Price')),
                ],
                selected: {_sort},
                onSelectionChanged: (v) => setState(() => _sort = v.first),
              ),
              SegmentedButton<String>(
                key: const ValueKey('services-view'),
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(
                    value: 'grid',
                    icon: Icon(Icons.grid_view, size: 18),
                    label: Text('Grid'),
                  ),
                  ButtonSegment(
                    value: 'list',
                    icon: Icon(Icons.view_list, size: 18),
                    label: Text('List'),
                  ),
                ],
                selected: {_grid ? 'grid' : 'list'},
                onSelectionChanged: (v) {
                  // The setter repaints the session; this screen repaints too.
                  widget.session.setPreference(viewPreference, v.first);
                  setState(() {});
                },
              ),
            ],
          ),
        ),
        if (kindNames.isNotEmpty)
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              children: [
                for (final k in [null, ...kindNames])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      key: ValueKey('services-kind-${k ?? 'all'}'),
                      label: Text(
                        '${k ?? 'All'} · ${k == null ? _services.length : kinds[k]}',
                      ),
                      selected: kind == k,
                      onSelected: (_) => setState(() => _kind = k),
                    ),
                  ),
              ],
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
              : LayoutBuilder(
                  builder: (context, constraints) {
                    final width = constraints.maxWidth - 32;
                    final columns = (width / 210).floor().clamp(2, 5);
                    final cell = (width - 12 * (columns - 1)) / columns;
                    return CustomScrollView(
                      slivers: [
                        for (final entry in groups.entries)
                          if (entry.value.isNotEmpty) ...[
                            SliverPadding(
                              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                              sliver: SliverToBoxAdapter(
                                child: Text(
                                  '${entry.key} · ${entry.value.length}',
                                  key: ValueKey('services-group-${entry.key}'),
                                  style: Theme.of(context).textTheme.titleSmall,
                                ),
                              ),
                            ),
                            SliverPadding(
                              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                              sliver: _grid
                                  ? SliverGrid.builder(
                                      gridDelegate:
                                          SliverGridDelegateWithFixedCrossAxisCount(
                                            crossAxisCount: columns,
                                            crossAxisSpacing: 12,
                                            mainAxisSpacing: 12,
                                            mainAxisExtent:
                                                cell * 0.75 +
                                                ServiceCard.textHeight,
                                          ),
                                      itemCount: entry.value.length,
                                      itemBuilder: (context, i) => ServiceCard(
                                        api: widget.api,
                                        service: entry.value[i],
                                        organizationName: widget
                                            .session
                                            .me!
                                            .organization
                                            .name,
                                        selected:
                                            entry.value[i]['id'] == _selectedId,
                                        canEdit: widget.session.me!.can(
                                          'services.edit',
                                        ),
                                        onOpen: () =>
                                            _openService(entry.value[i]),
                                      ),
                                    )
                                  : SliverList.separated(
                                      itemCount: entry.value.length,
                                      separatorBuilder: (_, _) =>
                                          const SizedBox(height: 6),
                                      itemBuilder: (context, i) =>
                                          _row(context, entry.value[i]),
                                    ),
                            ),
                          ],
                        if (more)
                          SliverPadding(
                            padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                            sliver: SliverToBoxAdapter(
                              child: LoadMore(
                                shown: _services.length,
                                total: _total,
                                busy: _fetching,
                                error: _pageError,
                                onMore: () => _load(page: _page + 1),
                              ),
                            ),
                          ),
                        const SliverToBoxAdapter(child: SizedBox(height: 16)),
                      ],
                    );
                  },
                ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> service) {
    final id = service['id'] as String?;
    final price = inventoryCents(service['price_minor']);

    return Card(
      key: ValueKey('services-row-$id'),
      child: ListTile(
        dense: true,
        selected: id != null && id == _selectedId,
        onTap: () => _openService(service),
        leading: SizedBox(
          width: 40,
          height: 40,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: ServicePhotoBox(
              api: widget.api,
              photo: inventoryMap(service['photo']),
              iconSize: 20,
            ),
          ),
        ),
        title: Text(
          service['name'] as String? ?? 'Service',
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.titleSmall,
        ),
        subtitle: Text(
          [
            service['number'],
            serviceOwnerLabel(service, widget.session.me!.organization.name),
          ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.bodySmall,
        ),
        trailing: price == null
            ? null
            : Text(price, style: Theme.of(context).textTheme.titleSmall),
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

/// The kind a service is grouped under: its group, else its label, else Other.
String serviceKind(Map<String, dynamic> service) {
  for (final key in const ['group', 'label']) {
    final name = inventoryMap(service[key])?['name'] as String?;
    if (name != null && name.trim().isNotEmpty) return name.trim();
  }
  return 'Other';
}

/// "Company · Branch" when the service belongs to a branch, else the company.
String serviceOwnerLabel(
  Map<String, dynamic> service,
  String organizationName,
) {
  final owner = inventoryOwnerLabel(service, organizationName);
  final branch = inventoryMap(service['franchise'])?['name'] as String?;
  return branch == null || branch.isEmpty ? owner : '$owner · $branch';
}

/// One catalogue card: photo with the price on it, then the words.
class ServiceCard extends StatelessWidget {
  const ServiceCard({
    super.key,
    required this.api,
    required this.service,
    required this.organizationName,
    required this.selected,
    required this.canEdit,
    required this.onOpen,
  });

  /// Height of the words under the photo; every card is the same size.
  static const double textHeight = 118;

  final ApiClient api;
  final Map<String, dynamic> service;
  final String organizationName;
  final bool selected;
  final bool canEdit;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final photo = inventoryMap(service['photo']);
    final price = inventoryCents(service['price_minor']);
    final label = inventoryMap(service['label']);
    final group = inventoryMap(service['group']);
    final id = service['id'] as String?;

    return Card(
      key: ValueKey('services-card-$id'),
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: selected ? scheme.primary : scheme.outlineVariant,
          width: selected ? 2 : 1,
        ),
      ),
      child: InkWell(
        onTap: onOpen,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: Stack(
                fit: StackFit.expand,
                children: [
                  ServicePhotoBox(
                    key: ValueKey('services-photo-$id'),
                    api: api,
                    photo: photo,
                    iconSize: 36,
                    action: photo == null && canEdit
                        ? TextButton.icon(
                            onPressed: onOpen,
                            icon: const Icon(Icons.add_a_photo, size: 16),
                            label: const Text('Add a photo'),
                          )
                        : null,
                  ),
                  if (price != null)
                    Positioned(
                      top: 8,
                      right: 8,
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          color: scheme.surface,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 4,
                          ),
                          child: Text(
                            price,
                            style: theme.textTheme.labelLarge?.copyWith(
                              color: scheme.onSurface,
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
            SizedBox(
              height: textHeight,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      service['name'] as String? ?? 'Service',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${service['number'] ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                    const Spacer(),
                    ClipRect(
                      child: SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        physics: const NeverScrollableScrollPhysics(),
                        child: Row(
                          children: [
                            if (label != null)
                              Tag(label['name'] as String? ?? ''),
                            if (group != null)
                              Tag(group['name'] as String? ?? ''),
                          ],
                        ),
                      ),
                    ),
                    const Spacer(),
                    Text(
                      serviceOwnerLabel(service, organizationName),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A service photo filling its box, fetched with the session's token.
class ServicePhotoBox extends StatefulWidget {
  const ServicePhotoBox({
    super.key,
    required this.api,
    required this.photo,
    this.iconSize = 32,
    this.action,
  });

  final ApiClient api;
  final Map<String, dynamic>? photo;
  final double iconSize;
  final Widget? action;

  /// Bytes already fetched, so scrolling and toggling do not refetch.
  static final Map<String, Uint8List> _cache = {};

  @override
  State<ServicePhotoBox> createState() => _ServicePhotoBoxState();
}

class _ServicePhotoBoxState extends State<ServicePhotoBox> {
  Uint8List? _bytes;

  String? get _id {
    final id = widget.photo?['id'] as String?;
    final mime = widget.photo?['mime'] as String? ?? '';
    return id != null && mime.startsWith('image/') ? id : null;
  }

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  @override
  void didUpdateWidget(covariant ServicePhotoBox oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.photo?['id'] != widget.photo?['id']) {
      _bytes = null;
      _fetch();
    }
  }

  Future<void> _fetch() async {
    final id = _id;
    if (id == null) return;
    final cached = ServicePhotoBox._cache[id];
    if (cached != null) {
      _bytes = cached;
      return;
    }
    final bytes = await widget.api.getBytes('/attachments/$id');
    if (!mounted || bytes == null || id != _id) return;
    final data = Uint8List.fromList(bytes);
    ServicePhotoBox._cache[id] = data;
    setState(() => _bytes = data);
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    if (_bytes != null) {
      return Image.memory(
        _bytes!,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => _placeholder(scheme),
      );
    }
    return _placeholder(scheme);
  }

  Widget _placeholder(ColorScheme scheme) {
    return ColoredBox(
      color: scheme.surfaceContainerHighest,
      child: Center(
        child: FittedBox(
          fit: BoxFit.scaleDown,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.handyman_outlined,
                size: widget.iconSize,
                color: scheme.onSurfaceVariant,
              ),
              if (widget.action != null) ...[
                const SizedBox(height: 4),
                widget.action!,
              ],
            ],
          ),
        ),
      ),
    );
  }
}
