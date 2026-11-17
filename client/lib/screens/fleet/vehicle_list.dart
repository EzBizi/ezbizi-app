import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../widgets/filters_fold.dart';

import '../../widgets/load_more.dart';
import '../../widgets/not_filled_in.dart';
import '../../widgets/tag.dart';
import 'vehicle_card.dart';

/// The fleet register as a list: search, chips, rows.
///
/// Filters are chips on this list rather than homes of their own (spec §11).
/// A missing status chip is a request WITH NO `status` — the server then hides
/// sold and returned. Inventing an "active" default here would duplicate that
/// rule and drift the moment the server's set changes.
class VehicleList extends StatelessWidget {
  const VehicleList({
    super.key,
    required this.vehicles,
    required this.search,
    required this.onSearch,
    required this.onSearchChanged,
    required this.onClearSearch,
    required this.selectedId,
    required this.onSelect,
    required this.statuses,
    required this.onToggleStatus,
    required this.owned,
    required this.rented,
    required this.onToggleOwned,
    required this.onToggleRented,
    required this.unassigned,
    required this.dueSoon,
    required this.onToggleUnassigned,
    required this.onToggleDueSoon,
    this.uninsured = false,
    this.onToggleUninsured,
    this.insuranceExpiring = false,
    this.onToggleInsuranceExpiring,
    required this.emptyLabel,
    required this.shown,
    required this.total,
    required this.lastPage,
    required this.page,
    required this.fetching,
    required this.pageError,
    required this.onMore,
    required this.organizationName,
    this.places = const [],
    this.place,
    this.onPlace,
  });

  /// The states the register spans, from the server's `meta.places`:
  /// `{id, name, count}`. Eve, 2026-10-12, as in A-1 Tools: "I click
  /// Connecticut and I see all of Connecticut". Drawn only when there is more
  /// than one, since a single state has nothing to choose between.
  final List<Map<String, dynamic>> places;

  /// The open state's id, or null for every state at once.
  final String? place;
  final ValueChanged<String?>? onPlace;

  final List<Map<String, dynamic>> vehicles;
  final TextEditingController search;
  final VoidCallback onSearch;
  final VoidCallback onSearchChanged;
  final VoidCallback onClearSearch;
  final String? selectedId;
  final ValueChanged<Map<String, dynamic>> onSelect;

  final Set<String> statuses;
  final ValueChanged<String> onToggleStatus;
  final bool owned;
  final bool rented;
  final VoidCallback onToggleOwned;
  final VoidCallback onToggleRented;
  final bool unassigned;
  final bool dueSoon;
  final VoidCallback onToggleUnassigned;
  final VoidCallback onToggleDueSoon;

  /// No open insurance policy (`uninsured=1`), the General tab's Add insurance.
  final bool uninsured;
  final VoidCallback? onToggleUninsured;

  /// An open policy expired or expiring within 30 days
  /// (`insurance_expiring=1`), the General tab's Renew insurance.
  final bool insuranceExpiring;
  final VoidCallback? onToggleInsuranceExpiring;

  final String emptyLabel;
  final int shown;
  final int total;
  final int lastPage;
  final int page;
  final bool fetching;
  final String? pageError;
  final VoidCallback onMore;
  final String organizationName;

  static const statusChips = <(String, String)>[
    ('active', 'Active'),
    ('in_shop', 'In shop'),
    ('out_of_service', 'Out of service'),
    ('sold', 'Sold'),
    ('returned', 'Returned'),
  ];

  @override
  Widget build(BuildContext context) =>
      LayoutBuilder(builder: (context, box) => _build(context, box.maxWidth));

  Widget _build(BuildContext context, double width) {
    final total = places.fold<int>(
      0,
      (sum, p) => sum + ((p['count'] as num?)?.toInt() ?? 0),
    );

    final folded = [
      if (places.length > 1 && onPlace != null)
        Padding(
          key: const ValueKey('tour-fleet_management-states'),
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final p in places)
                ChoiceChip(
                  label: Text('${p['name']}  ${p['count']}'),
                  selected: place == p['id'],
                  onSelected: (_) => onPlace!(p['id'] as String?),
                ),
              ChoiceChip(
                label: Text('All states  $total'),
                selected: place == null,
                onSelected: (_) => onPlace!(null),
              ),
            ],
          ),
        ),
      Padding(
        key: const ValueKey('tour-fleet_management-filters'),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            FilterChip(
              label: const Text('Owned'),
              selected: owned,
              onSelected: (_) => onToggleOwned(),
            ),
            FilterChip(
              label: const Text('Rented'),
              selected: rented,
              onSelected: (_) => onToggleRented(),
            ),
            for (final chip in statusChips)
              FilterChip(
                label: Text(chip.$2),
                selected: statuses.contains(chip.$1),
                onSelected: (_) => onToggleStatus(chip.$1),
              ),
            FilterChip(
              label: const Text('Unassigned'),
              selected: unassigned,
              onSelected: (_) => onToggleUnassigned(),
            ),
            FilterChip(
              label: const Text('Due soon'),
              selected: dueSoon,
              onSelected: (_) => onToggleDueSoon(),
            ),
            if (onToggleUninsured != null)
              FilterChip(
                label: const Text('No insurance'),
                selected: uninsured,
                onSelected: (_) => onToggleUninsured!(),
              ),
            if (onToggleInsuranceExpiring != null)
              FilterChip(
                label: const Text('Insurance expiring'),
                selected: insuranceExpiring,
                onSelected: (_) => onToggleInsuranceExpiring!(),
              ),
          ],
        ),
      ),
    ];
    // Mobile review P10: on a phone the state chips and the eleven filter
    // chips fold behind one "Filters · n" button under the search.
    final setCount =
        (place != null ? 1 : 0) +
        statuses.length +
        [
          owned,
          rented,
          unassigned,
          dueSoon,
          uninsured,
          insuranceExpiring,
        ].where((on) => on).length;
    final filters = [
      Padding(
        key: const ValueKey('tour-fleet_management-search'),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
        child: ListSearch(
          controller: search,
          hint: 'Name, plate, VIN, number or driver',
          onChanged: (_) => onSearchChanged(),
          onSubmitted: (_) => onSearch(),
          onClear: onClearSearch,
        ),
      ),
      FiltersFold(
        key: const ValueKey('fleet-filters-fold'),
        count: setCount,
        width: width,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: folded,
        ),
      ),
    ];
    // All states: one section per branch, "Company · Branch  N" (Jose,
    // 2026-11-03: "divissions in the list"). One state open, no headers.
    final entries = <Object>[];
    if (place == null && places.length > 1) {
      final groups = <String, List<Map<String, dynamic>>>{};
      for (final v in vehicles) {
        final id = '${fleetMap(v['franchise'])?['id'] ?? fleetGeneralValue}';
        (groups[id] ??= []).add(v);
      }
      for (final MapEntry(key: id, value: rows) in groups.entries) {
        final known = places.where((p) => '${p['id']}' == id).firstOrNull;
        final n = (known?['count'] as num?)?.toInt() ?? rows.length;
        final company = fleetMap(rows.first['company'])?['name'] as String?;
        final branch = fleetPlaceLabel(rows.first);
        entries
          ..add((
            id,
            company == null || company == branch
                ? branch
                : '$company · $branch',
            n,
          ))
          ..addAll(rows);
      }
    } else {
      entries.addAll(vehicles);
    }
    final count = entries.length + (page < lastPage ? 1 : 0);

    Widget item(BuildContext context, int i) {
      if (i == entries.length) {
        return LoadMore(
          shown: shown,
          total: total,
          busy: fetching,
          error: pageError,
          onMore: onMore,
        );
      }
      final entry = entries[i];
      if (entry is (String, String, int)) {
        final theme = Theme.of(context);
        return Padding(
          key: ValueKey('fleet-section-${entry.$1}'),
          padding: EdgeInsets.only(top: i == 0 ? 0 : 8),
          child: Text(
            '${entry.$2}  ${entry.$3}',
            style: theme.textTheme.titleSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        );
      }
      return _row(context, entry as Map<String, dynamic>);
    }

    // On a phone the search and the nine chips scroll with the rows. Standing
    // above them they took three lines of chips and 200 of the screen's 800
    // pixels; see HeaderAboveList, which scrolls the header above them away.
    // The list's own width, not the screen's: a narrow list pane on a computer
    // wraps the chips as tall as a phone does (512px at 265 wide), above rows
    // left with no room.
    if (width < 600) {
      return ListView.builder(
        padding: const EdgeInsets.only(bottom: 24),
        itemCount: filters.length + (vehicles.isEmpty ? 1 : count),
        itemBuilder: (context, i) {
          if (i < filters.length) return filters[i];
          if (vehicles.isEmpty) {
            return Padding(
              padding: const EdgeInsets.all(32),
              child: Center(child: Text(emptyLabel)),
            );
          }
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: item(context, i - filters.length),
          );
        },
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ...filters,
        Expanded(
          child: vehicles.isEmpty
              ? Center(child: Text(emptyLabel))
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  itemCount: count,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: item,
                ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> vehicle) {
    final id = vehicle['id'] as String?;
    final ownership = vehicle['ownership'];
    final kind = ownership is Map ? ownership['kind'] as String? : null;
    final assignment = vehicle['assignment'];
    final user = assignment is Map ? assignment['user'] : null;
    final driver = user is Map ? user['name'] as String? : null;
    final place = fleetPlaceLabel(vehicle);
    final status = vehicle['status'] as String? ?? '';
    final missing = (vehicle['missing'] as num?)?.toInt() ?? 0;

    return Card(
      child: ListTile(
        selected: id != null && id == selectedId,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => onSelect(vehicle),
        title: Row(
          children: [
            Expanded(
              child: Text(
                vehicle['label'] as String? ?? 'Unidentified vehicle',
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            // The amber boxes on its Identity card, counted by the server
            // (VehiclesController::missing), as HR's list counts a record.
            MissingCount(
              missing,
              suffix: ' missing',
              tooltip:
                  '$missing ${missing == 1 ? 'field' : 'fields'} '
                  'not filled in',
            ),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Wrap(
                children: [
                  if (status.isNotEmpty)
                    Tag(
                      _statusLabel(status),
                      warn:
                          status == 'out_of_service' ||
                          status == 'sold' ||
                          status == 'returned',
                    ),
                  if (kind != null) Tag(kind == 'rented' ? 'Rented' : 'Owned'),
                ],
              ),
              Text(
                [
                  vehicle['number'],
                  fleetOwnerLabel(vehicle, organizationName),
                  place,
                  driver ?? 'Nobody has the keys',
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

  static String _statusLabel(String status) => switch (status) {
    'in_shop' => 'In shop',
    'out_of_service' => 'Out of service',
    'sold' => 'Sold',
    'returned' => 'Returned',
    'active' => 'Active',
    _ => status,
  };
}
