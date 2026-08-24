import 'package:flutter/material.dart';

import '../../widgets/tag.dart';
import 'item_pane.dart';

/// A posted issue is read-only.
class IssuePane extends StatelessWidget {
  const IssuePane({
    super.key,
    required this.issue,
    required this.onBack,
    this.showBack = false,
  });

  final Map<String, dynamic> issue;
  final VoidCallback onBack;
  final bool showBack;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final location = inventoryMap(issue['location']);
    final job = inventoryMap(issue['job']);
    final lines = inventoryMaps(issue['lines']);

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
                  issue['number'] as String? ?? 'Issue',
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
                const Tag('Issue'),
                const SizedBox(height: 8),
                Text(
                  [
                    location?['name'],
                    issue['issued_on'],
                    job == null
                        ? issue['note']
                        : [
                            job['number'],
                            job['title'],
                          ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                  ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 16),
                for (final line in lines) ...[
                  Text(
                    inventoryMap(line['item'])?['name'] as String? ?? 'Item',
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    inventoryQty(line['qty']),
                    style: theme.textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 12),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
