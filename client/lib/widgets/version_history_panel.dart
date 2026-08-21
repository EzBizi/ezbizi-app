import 'package:flutter/material.dart';

import '../core/build_info.dart';
import '../core/version_history.dart';
import 'side_panel.dart';
import 'tag.dart';

/// Open the version history from the stamp at the foot of the sidebar.
Future<void> showVersionHistory(BuildContext context) {
  return showSidePanel<void>(
    context,
    builder: (_) => const VersionHistoryPanel(),
  );
}

/// What shipped in each client version, newest first.
///
/// This is a memory, not a marketing page: the number on the sidebar is how
/// you get here, and the date next to each version is how you remember when
/// something landed. Add a row in [VersionHistory] in the same commit that
/// bumps `client/VERSION`.
class VersionHistoryPanel extends StatelessWidget {
  const VersionHistoryPanel({super.key});

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'What\'s been built',
      subtitle:
          'This app is ${BuildInfo.short}. Newest first. The number at the '
          'bottom of the sidebar is how you open this.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < VersionHistory.releases.length; i++) ...[
            if (i > 0) const SizedBox(height: 22),
            _ReleaseBlock(release: VersionHistory.releases[i]),
          ],
        ],
      ),
    );
  }
}

class _ReleaseBlock extends StatelessWidget {
  const _ReleaseBlock({required this.release});

  final VersionRelease release;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final current = release.version == BuildInfo.version;

    return DecoratedBox(
      decoration: BoxDecoration(
        color: current
            ? scheme.surfaceContainerHighest.withValues(alpha: 0.55)
            : null,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Padding(
        padding: current
            ? const EdgeInsets.fromLTRB(12, 10, 12, 12)
            : EdgeInsets.zero,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              runSpacing: 4,
              children: [
                Text(
                  'v${release.version}',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                ),
                Text(release.date, style: theme.textTheme.bodySmall),
                if (current) const Tag('This build'),
              ],
            ),
            const SizedBox(height: 8),
            for (final item in release.items) ...[
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('·  ', style: theme.textTheme.bodyMedium),
                    Expanded(
                      child: Text(item, style: theme.textTheme.bodyMedium),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
