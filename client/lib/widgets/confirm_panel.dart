import 'package:flutter/material.dart';

import 'side_panel.dart';

/// Asks before something that cannot be undone, in a side panel; true only
/// on [confirm] (audit 2026-11-27: deletes ran with no question).
Future<bool> confirmInPanel(
  BuildContext context, {
  required String title,
  required String body,
  required String confirm,
}) async =>
    await showSidePanel<bool>(
      context,
      builder: (panel) => SidePanel(
        title: title,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(panel).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const Key('confirm-panel-yes'),
            onPressed: () => Navigator.of(panel).pop(true),
            child: Text(confirm),
          ),
        ],
        child: Text(body),
      ),
    ) ??
    false;
