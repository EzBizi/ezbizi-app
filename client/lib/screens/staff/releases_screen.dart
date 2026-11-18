import 'dart:async';

import 'package:flutter/material.dart';
import '../../core/staff_session.dart';
import '../../core/theme.dart';
import '../../core/version_history.dart';
import '../../widgets/async_body.dart';

/// How far the release in flight has got (Jose, 2026-11: "a UI that shows the
/// state of the current release … percentage wise").
///
/// release.sh and deploy.sh append a line per stage boundary on the box;
/// `GET /staff/release-status` folds them into releases. This screen asks every
/// ten seconds while it is open — a release is minutes to an hour, so anything
/// faster would only be noise.
class ReleasesScreen extends StatefulWidget {
  const ReleasesScreen({
    super.key,
    required this.staff,
  });

  final StaffSession staff;

  /// How often to ask again.
  static const every = Duration(seconds: 10);

  @override
  State<ReleasesScreen> createState() => _ReleasesScreenState();
}

class _ReleasesScreenState extends State<ReleasesScreen> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  Timer? _timer;
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(ReleasesScreen.every, (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = _data == null;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/release-status');

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      // A poll that fails keeps the last answer on screen rather than
      // replacing it with an error mid-release.
      if (response.ok) {
        _data = response.body;
      } else if (_data == null) {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final current = _data?['current'] as Map<String, dynamic>?;
    final recent = ((_data?['recent'] as List<dynamic>?) ?? const [])
        .cast<Map<String, dynamic>>();
    final text = Theme.of(context).textTheme;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Row(
            children: [
              Expanded(child: Text('Releases', style: text.headlineMedium)),
              IconButton(
                onPressed: _load,
                icon: const Icon(Icons.refresh),
                tooltip: 'Check again',
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'What release.sh and deploy.sh report as they go. Updates every '
            '${ReleasesScreen.every.inSeconds} seconds while this tab is open.',
            style: text.bodyMedium,
          ),
          const SizedBox(height: 20),
          if (current == null)
            Text('No release has reported yet.', style: text.bodyMedium)
          else
            _Current(release: current),
          if (recent.isNotEmpty) ...[
            const SizedBox(height: 24),
            Text('Recent', style: text.titleMedium),
            const SizedBox(height: 8),
            for (final r in recent) _RecentRow(release: r),
          ],
        ],
      ),
    );
  }
}

String _elapsed(Object? seconds) {
  final s = (seconds as num?)?.toInt() ?? 0;
  final h = s ~/ 3600, m = (s % 3600) ~/ 60, sec = s % 60;
  if (h > 0) return '${h}h ${m}m';
  if (m > 0) return '${m}m ${sec}s';
  return '${sec}s';
}

/// "~41 min left · done around 3:05 PM" (Jose 11-12: "estimated time left").
String etaLine(int seconds, DateTime now) {
  final m = (seconds / 60).ceil();
  final left = seconds < 60
      ? 'Under a minute left'
      : m >= 60
      ? '~${m ~/ 60} h ${m % 60} min left'
      : '~$m min left';
  final at = now.add(Duration(seconds: seconds)).toLocal();
  final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
  final mm = at.minute.toString().padLeft(2, '0');
  return '$left · done around $h:$mm ${at.hour < 12 ? 'AM' : 'PM'}';
}

String _shortSha(Object? sha) {
  final s = sha as String? ?? '';
  return s.length > 7 ? s.substring(0, 7) : s;
}

Tone _tone(String? state) => switch (state) {
  'done' => Tone.ok,
  'failed' => Tone.bad,
  'stalled' => Tone.caution,
  'running' => Tone.info,
  // superseded: overtaken by a later release, nothing to act on.
  _ => Tone.neutral,
};

String _stateLabel(String? state) => switch (state) {
  'done' => 'Finished',
  'failed' => 'Failed',
  'stalled' => 'No word for 3 hours',
  'superseded' => 'Superseded',
  _ => 'In progress',
};

class _Current extends StatelessWidget {
  const _Current({required this.release});

  final Map<String, dynamic> release;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final state = release['state'] as String?;
    final percent = (release['percent'] as num?)?.toInt() ?? 0;
    final tone = ToneColours.of(context, _tone(state));
    final stages = ((release['stages'] as List<dynamic>?) ?? const [])
        .cast<Map<String, dynamic>>();
    final eta = (release['eta_seconds'] as num?)?.toInt();
    final log = ((release['log'] as List<dynamic>?) ?? const []).cast<String>();
    final ships = VersionHistory.releases
        .where((r) => r.version == release['version'])
        .expand((r) => r.items)
        .toList();

    final stageColumn = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final s in stages) _StageRow(stage: s),
        if (log.isNotEmpty) ...[
          const SizedBox(height: 12),
          _Log(lines: log),
        ],
      ],
    );
    final shipsColumn = ships.isEmpty
        ? null
        : Column(
            key: const ValueKey('release-ships'),
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'What ships · ${ships.length} changes',
                style: text.titleSmall,
              ),
              const SizedBox(height: 6),
              // ponytail: first five; the full list is the version stamp's.
              for (final item in ships.take(5))
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text('• $item', style: text.bodySmall),
                ),
              if (ships.length > 5)
                Text('+${ships.length - 5} more', style: text.bodySmall),
            ],
          );

    return Card(
      key: const ValueKey('release-current'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(
              spacing: 12,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text('v${release['version'] ?? '?'}', style: text.titleLarge),
                Text(_shortSha(release['sha']), style: text.bodySmall),
                Text(
                  _stateLabel(state),
                  style: text.labelLarge?.copyWith(color: tone.ink),
                ),
              ],
            ),
            if (eta != null) ...[
              const SizedBox(height: 12),
              Text(
                etaLine(eta, DateTime.now()),
                key: const ValueKey('release-eta'),
                style: text.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(6),
                    child: LinearProgressIndicator(
                      value: percent / 100,
                      minHeight: 12,
                      color: tone.ink,
                      backgroundColor: tone.fill,
                      semanticsLabel: 'Release progress',
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Text('$percent%', style: text.titleMedium),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              '${_elapsed(release['elapsed_seconds'])} elapsed'
              '${release['message'] == null ? '' : ' · ${release['message']}'}',
              style: text.bodySmall,
            ),
            const SizedBox(height: 16),
            LayoutBuilder(
              builder: (context, box) => shipsColumn == null
                  ? stageColumn
                  : box.maxWidth >= 760
                  ? Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(flex: 3, child: stageColumn),
                        const SizedBox(width: 24),
                        Expanded(flex: 2, child: shipsColumn),
                      ],
                    )
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        stageColumn,
                        const SizedBox(height: 16),
                        shipsColumn,
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StageRow extends StatelessWidget {
  const _StageRow({required this.stage});

  final Map<String, dynamic> stage;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final state = stage['state'] as String?;
    final tone = ToneColours.of(context, _tone(state));
    final percent = (stage['percent'] as num?)?.toInt() ?? 0;
    final seconds = (stage['seconds'] as num?)?.toInt();
    final median = (stage['median_seconds'] as num?)?.toInt();
    final time = state == 'done' && seconds != null
        ? _elapsed(seconds)
        : state == 'running' && seconds != null
        ? '${_elapsed(seconds)}${median == null ? '' : ' of ~${_elapsed(median)}'}'
        : state == 'pending' && median != null
        ? '~${_elapsed(median)}'
        : null;

    final Widget mark = switch (state) {
      'done' => Icon(Icons.check_circle, color: tone.ink, size: 20),
      'failed' => Icon(Icons.cancel, color: tone.ink, size: 20),
      'running' => SizedBox(
        width: 20,
        height: 20,
        child: CircularProgressIndicator(strokeWidth: 2.5, color: tone.ink),
      ),
      _ => Icon(Icons.radio_button_unchecked, color: tone.ink, size: 20),
    };

    return Padding(
      key: ValueKey('release-stage-${stage['key']}'),
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(label: state ?? 'pending', child: mark),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  state == 'running' && percent > 0
                      ? '${stage['label']} · $percent%'
                      : '${stage['label']}',
                  style: text.bodyLarge?.copyWith(
                    color: state == 'failed' ? tone.ink : null,
                  ),
                ),
                if (stage['message'] != null && state != 'pending')
                  Text(
                    stage['message'] as String,
                    style: text.bodySmall?.copyWith(
                      color: state == 'failed' ? tone.ink : null,
                    ),
                  ),
              ],
            ),
          ),
          if (time != null)
            Padding(
              padding: const EdgeInsets.only(left: 12, top: 2),
              child: Text(time, style: text.bodySmall),
            ),
        ],
      ),
    );
  }
}

/// The tail of the server gate's log, as it prints.
class _Log extends StatelessWidget {
  const _Log({required this.lines});

  final List<String> lines;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      key: const ValueKey('release-log'),
      width: double.infinity,
      height: 180,
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: SingleChildScrollView(
        reverse: true,
        child: SelectableText(
          lines.join('\n'),
          style: TextStyle(
            fontFamily: 'monospace',
            fontSize: 12,
            height: 1.4,
            color: scheme.onSurfaceVariant,
          ),
        ),
      ),
    );
  }
}

class _RecentRow extends StatelessWidget {
  const _RecentRow({required this.release});

  final Map<String, dynamic> release;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final state = release['state'] as String?;
    final tone = ToneColours.of(context, _tone(state));

    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: Icon(
        state == 'done'
            ? Icons.check_circle_outline
            : state == 'failed'
            ? Icons.error_outline
            : state == 'superseded'
            ? Icons.redo
            : Icons.schedule,
        color: tone.ink,
        semanticLabel: _stateLabel(state),
      ),
      title: Text(
        'v${release['version'] ?? '?'} · ${_shortSha(release['sha'])}',
      ),
      subtitle: Text(
        '${_stateLabel(state)} · ${release['percent'] ?? 0}% · '
        '${_elapsed(release['elapsed_seconds'])} · '
        'started ${release['started_at'] ?? '?'}',
        style: text.bodySmall,
      ),
    );
  }
}
