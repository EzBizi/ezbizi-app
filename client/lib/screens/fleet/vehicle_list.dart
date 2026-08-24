import 'package:flutter/material.dart';

import '../../widgets/load_more.dart';
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
    required this.emptyLabel,
    required this.shown,
    required this.total,
    required this.lastPage,
    required this.page,
    required this.fetching,
    required this.pageError,
    required this.onMore,
    required this.organizationName,
  });

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
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: TextField(
            controller: search,
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: 'Name, plate, VIN, number or driver',
              suffixIcon: search.text.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.clear),
                      tooltip: 'Clear the search',
                      onPressed: onClearSearch,
                    ),
            ),
            onSubmitted: (_) => onSearch(),
            onChanged: (_) => onSearchChanged(),
          ),
        ),
        Padding(
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
            ],
          ),
        ),
        Expanded(
          child: vehicles.isEmpty
              ? Center(child: Text(emptyLabel))
              : ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  itemCount: vehicles.length + (page < lastPage ? 1 : 0),
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    if (i == vehicles.length) {
                      return LoadMore(
                        shown: shown,
                        total: total,
                        busy: fetching,
                        error: pageError,
                        onMore: onMore,
                      );
                    }
                    return _row(context, vehicles[i]);
                  },
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

    return Card(
      child: ListTile(
        selected: id != null && id == selectedId,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        onTap: () => onSelect(vehicle),
        title: Text(
          vehicle['label'] as String? ?? 'Unidentified vehicle',
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
