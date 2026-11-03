import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/async_body.dart';
import '../widgets/refused.dart';
import '../widgets/tab_bar_like.dart';
import 'fleet/add_vehicle_pane.dart';
import 'fleet/fleet_general.dart';
import 'fleet/vehicle_list.dart';
import 'fleet/vehicle_pane.dart';

/// Fleet Management (spec §11).
///
/// A register, not a coming-soon tab. Nothing selected: the list is the whole
/// tab. A vehicle or Add selected: 25% list / 75% pane. Below 600px the pane
/// is the whole body with Back. Add is that pane, not a modal — covering the
/// list you are filing a van onto is the thing a side-by-side split exists
/// to avoid.
///
/// Two chips (Mia, 2026-10-02): General, the module's dashboard, opens first;
/// Vehicles is the register. A General figure opens Vehicles filtered to it.
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
  bool _uninsured = false;
  bool _insuranceExpiring = false;

  /// The open state (franchise id, or 'general'), or null for all of them.
  /// Opens on every state, the list split into one section per branch (Jose,
  /// 2026-11-03: "what eve asked was divissions in the list, not just show
  /// ct"). Eve, 2026-10-12: "I don't wanna see them all on one page because
  /// it's confusing".
  String? _place;
  List<Map<String, dynamic>> _places = const [];

  /// 0 General, 1 Vehicles.
  int _tab = 0;

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
      if (_uninsured) 'uninsured': '1',
      if (_insuranceExpiring) 'insurance_expiring': '1',
      'franchise_id': ?_place,
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

      final meta = response.body['meta'] as Map<String, dynamic>?;
      final places = meta?['places'];
      // A filter with no matches answers no places; keeping the last row stops
      // the chips vanishing and the next click landing on a different chip
      // (deep dive 2026-11-02 #8).
      if (places is List && places.isNotEmpty) {
        _places = [
          for (final p in places)
            if (p is Map) Map<String, dynamic>.from(p),
        ];
      }

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _vehicles = page == 1 ? rows : [..._vehicles, ...rows];

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

  /// Vehicles, filtered the way a General link says: `status=in_shop`,
  /// `ownership=rented`, `unassigned=1`, `due_soon=1`, or '' for all of them.
  void _openLink(String link) {
    final query = Uri.splitQueryString(link);
    setState(() {
      _search.clear();
      _statuses
        ..clear()
        ..addAll((query['status'] ?? '').split(',').where((s) => s.isNotEmpty));
      _owned = query['ownership'] == 'owned';
      _rented = query['ownership'] == 'rented';
      _unassigned = query['unassigned'] == '1';
      _dueSoon = query['due_soon'] == '1';
      _uninsured = query['uninsured'] == '1';
      _insuranceExpiring = query['insurance_expiring'] == '1';
      _tab = 1;
    });
    _load();
  }

  void _add() => setState(() {
    _tab = 1;
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
        _dueSoon ||
        _uninsured ||
        _insuranceExpiring) {
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
    final general = me.permissions.contains('fleet_management.general.view');
    final vehicles = me.permissions.contains('fleet_management.vehicles.view');

    // One tab granted: that tab is the whole screen, no strip to choose from.
    if (!general && !vehicles) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'No Fleet Management tabs have been granted to your role.',
          ),
        ),
      );
    }
    if (!general) return _register(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-fleet_management-tabs'),
            labels: [
              ('General', Icons.dashboard_outlined),
              if (vehicles) ('Vehicles', Icons.local_shipping_outlined),
            ],
            selected: vehicles ? _tab : 0,
            onSelected: (tab) => setState(() => _tab = tab),
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: IndexedStack(
            index: vehicles ? _tab : 0,
            children: [
              FleetGeneral(
                api: widget.api,
                visible: _tab == 0 || !vehicles,
                // Without Vehicles there is no list for a figure to open.
                onOpen: vehicles ? _openLink : (_) {},
                canAdd: canCreate && vehicles,
                onAdd: _add,
              ),
              if (vehicles) _register(context),
            ],
          ),
        ),
      ],
    );
  }

  Widget _register(BuildContext context) {
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

        final header = PanelHeader(
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
                    key: const ValueKey('tour-fleet_management-add'),
                    onPressed: refused == null ? _add : null,
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('Add vehicle'),
                  ),
                )
              : null,
        );

        final body = AsyncBody(
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
              : KeyedSubtree(key: const Key('fleet-list'), child: _list()),
        );

        // An open vehicle on a phone is the whole screen, with its own back.
        if (paneOnly) return body;

        return HeaderAboveList(header: header, list: body);
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
    uninsured: _uninsured,
    onToggleUninsured: () {
      setState(() => _uninsured = !_uninsured);
      _load();
    },
    insuranceExpiring: _insuranceExpiring,
    onToggleInsuranceExpiring: () {
      setState(() => _insuranceExpiring = !_insuranceExpiring);
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
    organizationName: widget.session.me!.organization.name,
    places: _places,
    place: _place,
    onPlace: (value) {
      setState(() => _place = value);
      _load();
    },
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
