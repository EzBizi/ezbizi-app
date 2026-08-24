import 'package:flutter/material.dart';

import '../../widgets/tag.dart';
import 'item_pane.dart';

/// One location: identity only.
class LocationPane extends StatelessWidget {
  const LocationPane({
    super.key,
    required this.location,
    required this.organizationName,
    required this.onBack,
    this.showBack = false,
  });

  final Map<String, dynamic> location;
  final String organizationName;
  final VoidCallback onBack;
  final bool showBack;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final kind = location['kind'] as String?;
    final status = location['status'] as String? ?? '';
    final vehicle = inventoryMap(location['vehicle']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(showBack ? 8 : 20, 12, 20, 8),
          child: Row(
            children: [
              if (showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: onBack,
                ),
              Expanded(
                child: Text(
                  location['name'] as String? ?? 'Location',
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
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
                const SizedBox(height: 8),
                Text(
                  [
                    inventoryOwnerLabel(location, organizationName),
                    inventoryPlaceLabel(location),
                    vehicle?['label'],
                  ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodyMedium,
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
