import 'package:flutter/material.dart';

import 'tag.dart';

/// A whole screen or tab that is part of the plan and is not built yet.
///
/// Shown, marked, and saying what it is waiting for. The badge is the same word
/// the automation builder uses for an action the engine cannot run, on purpose:
/// somebody who has met one of these knows what the other one means.
///
/// ── Why it lives here rather than in the screen that used it ─────
///
/// It was declared inside hr_screen.dart, which was fine while HR's Dashboard
/// tab was the placeholder using it. That tab is built now, and a shared promise
/// left behind in a screen that no longer uses it is a widget nobody finds — the
/// next module with a tab ahead of its dependencies would write its own, which
/// is the "second way of saying the same thing" this widget exists to prevent.
///
/// ── It is page-shaped, and that is a real limit ──────────────────
///
/// Centred, with room for a paragraph. For one PANEL among others that is not
/// built — HR's expiring-certifications card is the first — this is the wrong
/// shape, and the card says it inline with the same `Tag('Not built yet')`
/// instead. The shared thing is the wording and the promise to explain, not the
/// layout.
class NotBuiltYet extends StatelessWidget {
  const NotBuiltYet({
    super.key,
    required this.title,
    required this.icon,
    required this.waitingFor,
  });

  final String title;
  final IconData icon;

  /// What has to exist before this can. In prose, and specific: "coming soon"
  /// tells a reader nothing they had not already worked out from the empty page.
  final String waitingFor;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 34, color: theme.colorScheme.onSurfaceVariant),
              const SizedBox(height: 16),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(title, style: theme.textTheme.titleMedium),
                  const Tag('Not built yet', warn: true),
                ],
              ),
              const SizedBox(height: 12),
              Text(
                waitingFor,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
