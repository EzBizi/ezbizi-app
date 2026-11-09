import 'package:flutter/material.dart';

import 'side_panel.dart';

/// "Pause Cleveland?" - the confirmation every ad change asks for before it
/// reaches Google or Meta (Jose, 2026-11-09: confirmation before any change).
/// A side panel, not a dialog; true when Confirm was pressed.
Future<bool> confirmAdChange(
  BuildContext context, {
  required String title,
  required String change,
  String? detail,
}) async =>
    await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: title,
        onClose: () => Navigator.of(context).pop(false),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const ValueKey('ad-change-confirm'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Confirm'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(change, style: Theme.of(context).textTheme.bodyLarge),
            if (detail != null) ...[
              const SizedBox(height: 8),
              Text(detail, style: Theme.of(context).textTheme.bodySmall),
            ],
          ],
        ),
      ),
    ) ??
    false;
