import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/refused.dart';
import 'fleet/add_vehicle_pane.dart';
import 'fleet/vehicle_list.dart';
import 'fleet/vehicle_pane.dart';

/// Fleet Management (spec §11).
///
/// A register, not a coming-soon tab. Nothing selected: the list is the whole
/// tab. A vehicle or Add selected: 25% list / 75% pane. Below 600px the pane
/// is the whole body with Back. Add is that pane, not a modal — covering the
/// list you are filing a van onto is the thing a side-by-side split exists
/// to avoid.
class FleetScreen extends StatefulWidget {
  const FleetScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Below this the selected vehicle (or Add) is the whole body.
  ///
  /// Jobs and Customers have no list-plus-detail breakpoint — they open a
  /// side panel — so this is the number the brief asked to use rather than a
  /// new one.
  static const double splitAbove = 600;

  @override
  State<FleetScreen> createState() => _FleetScreenState();
}

class _FleetScreenState extends State<FleetScreen> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _vehicles = [];
  int _total = 0;
  int _page = 1;
  int _lastPage = 1;
  String? _pageError;
  bool _fetching = false;
  int _request = 0;

  final Set<String> _statuses = {};
  bool _owned = false;
  bool _rented = false;
  bool _unassigned = false;
  bool _dueSoon = false;

  bool _adding = false;
  String? _selectedId;
  Map<String, dynamic>? _open;

  @override
  void initState() {
    super.initState();
    _load();
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
      _loading = page == 1 && _vehicles.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final ownership = _owned == _rented ? null : (_owned ? 'owned' : 'rented');

    final response = await widget.api.get('/vehicles', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      if (_statuses.isNotEmpty) 'status': _statuses.join(','),
      'ownership': ?ownership,
      if (_unassigned) 'unassigned': '1',
      if (_dueSoon) 'due_soon': '1',
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

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _vehicles = page == 1 ? rows : [..._vehicles, ...rows];

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _vehicles.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;
    });
  }

  void _clearPane() => setState(() {
    _adding = false;
    _selectedId = null;
    _open = null;
  });

  void _add() => setState(() {
    _adding = true;
    _selectedId = null;
    _open = null;
  });

  void _select(Map<String, dynamic> vehicle) => setState(() {
    _adding = false;
    _selectedId = vehicle['id'] as String?;
    _open = vehicle;
  });

  String _emptyCopy() {
    if (_search.text.trim().isNotEmpty ||
        _statuses.isNotEmpty ||
        _owned ||
        _rented ||
        _unassigned ||
        _dueSoon) {
      final term = _search.text.trim();
      return term.isEmpty
          ? 'Nothing matches those filters.'
          : 'Nothing matches "$term".';
    }
    return 'There are no vehicles.';
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final canCreate = me.can('fleet_management.create');
    final nowhere = (me.companies.isEmpty && me.franchises.isEmpty)
        ? me.nowhereToAct('add a vehicle')
        : null;
    final refused = canCreate ? nowhere : null;

    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= FleetScreen.splitAbove;
        final paneOnly = !wide && _hasPane;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (!paneOnly)
              PanelHeader(
                title: 'Fleet',
                note: (_loading || _error != null)
                    ? 'The company\'s vehicles, who has the keys, and what is due.'
                    : _vehicles.length < _total
                    ? 'Showing ${_vehicles.length} of $_total.'
                    : _total == 1
                    ? '1 vehicle in the register.'
                    : '$_total in the register.',
                action: canCreate
                    ? Refused(
                        reason: refused,
                        child: FilledButton.icon(
                          onPressed: refused == null ? _add : null,
                          icon: const Icon(Icons.add, size: 18),
                          label: const Text('Add vehicle'),
                        ),
                      )
                    : null,
              ),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: paneOnly
                    ? _pane(showBack: true)
                    : wide && _hasPane
                    ? Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            flex: 1,
                            child: KeyedSubtree(
                              key: const Key('fleet-list'),
                              child: _list(),
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
                              key: const Key('fleet-pane'),
                              child: _pane(showBack: false),
                            ),
                          ),
                        ],
                      )
                    : KeyedSubtree(
                        key: const Key('fleet-list'),
                        child: _list(),
                      ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _list() => VehicleList(
    vehicles: _vehicles,
    search: _search,
    onSearch: _load,
    onSearchChanged: () => setState(() {}),
    onClearSearch: () {
      _search.clear();
      _load();
    },
    selectedId: _selectedId,
    onSelect: _select,
    statuses: _statuses,
    onToggleStatus: (value) {
      setState(() {
        if (!_statuses.add(value)) _statuses.remove(value);
      });
      _load();
    },
    owned: _owned,
    rented: _rented,
    onToggleOwned: () {
      setState(() => _owned = !_owned);
      _load();
    },
    onToggleRented: () {
      setState(() => _rented = !_rented);
      _load();
    },
    unassigned: _unassigned,
    dueSoon: _dueSoon,
    onToggleUnassigned: () {
      setState(() => _unassigned = !_unassigned);
      _load();
    },
    onToggleDueSoon: () {
      setState(() => _dueSoon = !_dueSoon);
      _load();
    },
    emptyLabel: _emptyCopy(),
    shown: _vehicles.length,
    total: _total,
    lastPage: _lastPage,
    page: _page,
    fetching: _fetching,
    pageError: _pageError,
    onMore: () => _load(page: _page + 1),
  );

  Widget _pane({required bool showBack}) {
    if (_adding) {
      return AddVehiclePane(
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

    final vehicle = _open;
    if (vehicle == null) return const SizedBox.shrink();

    return VehiclePane(
      api: widget.api,
      session: widget.session,
      vehicle: vehicle,
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
