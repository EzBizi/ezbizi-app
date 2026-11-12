import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';

import '../core/build_info.dart';
import '../core/version_history.dart';
import 'side_panel.dart';
import 'tag.dart';

/// Open the version history from the stamp at the foot of the sidebar.
Future<void> showVersionHistory(BuildContext context, {ApiClient? api}) {
  return showSidePanel<void>(
    context,
    builder: (_) => VersionHistoryPanel(api: api),
  );
}

/// What shipped in each client version, newest first.
///
/// This is a memory, not a marketing page: the number on the sidebar is how
/// you get here, and the date next to each version is how you remember when
/// something landed. Add a row in [VersionHistory] in the same commit that
/// bumps `client/VERSION`.
class VersionHistoryPanel extends StatefulWidget {
  const VersionHistoryPanel({super.key, this.api, this.every = _every});

  /// Signed in: asks for the release on its way. Null shows the list only.
  final ApiClient? api;
  final Duration every;

  static const _every = Duration(seconds: 10);

  @override
  State<VersionHistoryPanel> createState() => _VersionHistoryPanelState();
}

/// Jose 11-12: the Releases bar here too, "so people know when an update is
/// coming". Asked every 10 seconds while the panel is open, like Releases.
class _VersionHistoryPanelState extends State<VersionHistoryPanel> {
  Map? _upcoming;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    if (widget.api == null) return;
    _load();
    _timer = Timer.periodic(widget.every, (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api!.get('/release/upcoming');
    if (!mounted || !response.ok) return;
    final data = response.data;
    setState(() => _upcoming = data is Map ? data : null);
  }

  @override
  Widget build(BuildContext context) {
    final upcoming = _upcoming;
    return SidePanel(
      title: 'What\'s been built',
      subtitle:
          'This app is ${BuildInfo.short}. Newest first. The number at the '
          'bottom of the sidebar is how you open this.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (upcoming != null) ...[
            _Upcoming(
              version: '${upcoming['version'] ?? ''}',
              percent: (upcoming['percent'] as num?)?.toInt() ?? 0,
            ),
            const SizedBox(height: 22),
          ],
          for (var i = 0; i < VersionHistory.releases.length; i++) ...[
            if (i > 0) const SizedBox(height: 22),
            _ReleaseBlock(release: VersionHistory.releases[i]),
          ],
        ],
      ),
    );
  }
}

class _Upcoming extends StatelessWidget {
  const _Upcoming({required this.version, required this.percent});

  final String version;
  final int percent;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Column(
      key: const ValueKey('version-upcoming'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('v$version is on its way · $percent%', style: text.titleSmall),
        const SizedBox(height: 8),
        LinearProgressIndicator(
          value: percent / 100,
          minHeight: 6,
          borderRadius: BorderRadius.circular(3),
          semanticsLabel: 'Update progress',
        ),
        const SizedBox(height: 6),
        Text(
          'What it brings is listed here once it is out.',
          style: text.bodySmall,
        ),
      ],
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
