import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import '../../widgets/list_table.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import 'test_player.dart';
import 'video_embed.dart';

/// The guide reader, laid out and locked the way A1 Tools' study guide was.
///
/// Left: a progress card ("12 / 27 topics completed") over the guide's
/// outline — chapters, then sections, then topics — each topic showing a
/// lock, a tick, or a play mark. Right: the topic — "Chapter › Section", its
/// title, and every block in order (text, pictures, videos, tables), then
/// "Complete & Continue".
///
/// ── The lock ─────────────────────────────────────────────────────
///
/// Topics unlock in order: the first is open, and each next one opens when
/// the one before it is COMPLETED — by pressing "Complete & Continue", not by
/// opening it. So `last_screen` (the server's high-water mark, see
/// ProgressController) is how far the person has completed, topic `i` is
/// unlocked while `i <= last_screen + 1`, and the server refuses a completion
/// past that, so the lock holds whatever the client does.
///
/// The guide's own test sits under the outline. If the test requires the
/// guide, it opens once every topic is complete; otherwise it is open.
///
/// There is no `GET .../guides/{id}` learner endpoint, so the guide and its
/// test are read from `GET /training/learn` — `assigned` and `library`
/// together, since a guide and its test can sit on different shelves.
///
/// Below [splitAbove] the outline and the topic are shown one at a time.
class GuidePlayer extends StatefulWidget {
  const GuidePlayer({
    super.key,
    required this.api,
    required this.session,
    required this.guideId,
    required this.onLeave,
  });

  final ApiClient api;
  final Session session;
  final String guideId;
  final VoidCallback onLeave;

  static const double splitAbove = 600;

  @override
  State<GuidePlayer> createState() => _GuidePlayerState();
}

class _GuidePlayerState extends State<GuidePlayer> {
  bool _loading = true;
  String? _error;

  String _title = '';
  List<Map<String, dynamic>> _topics = const [];

  /// The server's high-water mark of COMPLETED topics; -1 when none.
  int _lastScreen = -1;
  DateTime? _completedAt;

  Map<String, dynamic>? _test;

  int _current = 0;
  bool _completing = false;
  bool _testOpen = false;

  /// Narrow only: the outline is on screen instead of the topic.
  bool _outlineOpen = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _topics.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/training/learn');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    final entries = [
      ..._rows(response.body['assigned']),
      ..._rows(response.body['library']),
    ];

    final guide = entries.firstWhere(
      (e) => e['type'] == 'guide' && e['id'] == widget.guideId,
      orElse: () => const <String, dynamic>{},
    );

    if (guide.isEmpty) {
      setState(() {
        _loading = false;
        _error = 'This guide is no longer available to you.';
      });
      return;
    }

    final test = entries.firstWhere(
      (e) => e['type'] == 'test' && e['guide_id'] == widget.guideId,
      orElse: () => const <String, dynamic>{},
    );

    final topics = _rows(guide['screens']);
    final raw = guide['last_screen'];
    final last = raw is num ? raw.toInt() : -1;

    setState(() {
      _loading = false;
      _title = guide['title'] as String? ?? '';
      _topics = topics;
      _lastScreen = last;
      _completedAt = _parseIso(guide['completed_at'] as String?);
      _test = test.isEmpty ? null : test;
      // Resume at the first topic not yet completed.
      _current = topics.isEmpty
          ? 0
          : (last + 1).clamp(0, topics.length - 1).toInt();
    });
  }

  bool _unlocked(int i) => i <= _lastScreen + 1;

  bool _done(int i) => i <= _lastScreen;

  void _select(int i) {
    if (!_unlocked(i)) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(
          content: Text(
            'This topic is locked. Complete the topics before it first.',
          ),
        ),
      );
      return;
    }
    setState(() {
      _current = i;
      _outlineOpen = false;
    });
  }

  /// "Complete & Continue": the server records it and says how far the
  /// person now is; this adopts that, never a local guess.
  Future<void> _complete() async {
    setState(() => _completing = true);

    final response = await widget.api.post(
      '/training/learn/guides/${widget.guideId}/seen',
      {'screen': _current},
    );
    if (!mounted) return;

    setState(() => _completing = false);

    if (!response.ok) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(response.message)));
      return;
    }

    final last = response.body['last_screen'];

    setState(() {
      _lastScreen = last is num ? last.toInt() : _lastScreen;
      _completedAt = _parseIso(response.body['completed_at'] as String?);
      if (_current < _topics.length - 1) _current++;
    });
  }

  bool get _testOpenable {
    final test = _test;
    if (test == null) return false;
    return test['requires_guide'] != true || _completedAt != null;
  }

  @override
  Widget build(BuildContext context) {
    final test = _test;
    if (_testOpen && test != null) {
      // In-body, never a Navigator.push (the containment rule).
      return TestPlayer(
        api: widget.api,
        session: widget.session,
        testId: test['id'] as String,
        onLeave: () => setState(() => _testOpen = false),
      );
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= GuidePlayer.splitAbove;
        final total = _topics.length;
        final doneCount = (_lastScreen + 1).clamp(0, total).toInt();

        final outline = _Outline(
          topics: _topics,
          current: _current,
          doneCount: doneCount,
          unlocked: _unlocked,
          done: _done,
          onSelect: _select,
          test: test,
          testOpenable: _testOpenable,
          onStartTest: () => setState(() => _testOpen = true),
        );

        final reader = total == 0
            ? Center(
                child: Text(
                  'This guide has no topics yet.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              )
            : _Topic(
                key: ValueKey('training.topic-$_current'),
                topic: _topics[_current],
                locked: !_unlocked(_current),
                done: _done(_current),
                isLast: _current == total - 1,
                completing: _completing,
                onPrevious: _current > 0 ? () => _select(_current - 1) : null,
                onComplete: _complete,
                onNext: _current < total - 1 && _unlocked(_current + 1)
                    ? () => _select(_current + 1)
                    : null,
              );

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _Header(
              title: _title,
              wide: wide,
              outlineOpen: _outlineOpen,
              onLeave: widget.onLeave,
              onToggleOutline: () =>
                  setState(() => _outlineOpen = !_outlineOpen),
            ),
            Divider(height: 1, color: EzBiziTheme.edge(context)),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: wide
                    ? Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          SizedBox(
                            width: 320,
                            child: KeyedSubtree(
                              key: const Key('training.screen-rail'),
                              child: outline,
                            ),
                          ),
                          VerticalDivider(
                            width: 1,
                            thickness: 1,
                            color: EzBiziTheme.edge(context),
                          ),
                          Expanded(child: reader),
                        ],
                      )
                    : (_outlineOpen ? outline : reader),
              ),
            ),
          ],
        );
      },
    );
  }
}

List<Map<String, dynamic>> _rows(Object? raw) => raw is List
    ? raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
    : const [];

DateTime? _parseIso(String? iso) =>
    iso == null ? null : DateTime.tryParse(iso)?.toLocal();

class _Header extends StatelessWidget {
  const _Header({
    required this.title,
    required this.wide,
    required this.outlineOpen,
    required this.onLeave,
    required this.onToggleOutline,
  });

  final String title;
  final bool wide;
  final bool outlineOpen;
  final VoidCallback onLeave;
  final VoidCallback onToggleOutline;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Material(
      color: theme.colorScheme.surface,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            TextButton.icon(
              onPressed: onLeave,
              icon: const Icon(Icons.arrow_back, size: 18),
              label: const Text('Leave'),
            ),
            const SizedBox(width: 4),
            Expanded(
              child: Text(
                title,
                style: theme.textTheme.titleMedium,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            if (!wide)
              IconButton(
                key: const Key('training.toggle-outline'),
                tooltip: outlineOpen ? 'Back to the topic' : 'Contents',
                onPressed: onToggleOutline,
                icon: Icon(outlineOpen ? Icons.article_outlined : Icons.list),
              ),
          ],
        ),
      ),
    );
  }
}

/// A run of consecutive topics sharing a label — how chapters and sections
/// are drawn from a flat, ordered topic list.
class _Group {
  _Group(this.label);

  final String? label;
  final List<int> topics = [];
}

List<_Group> _groupBy(List<int> indexes, String? Function(int) label) {
  final groups = <_Group>[];
  for (final i in indexes) {
    final l = label(i);
    if (groups.isEmpty || groups.last.label != l) groups.add(_Group(l));
    groups.last.topics.add(i);
  }
  return groups;
}

class _Outline extends StatelessWidget {
  const _Outline({
    required this.topics,
    required this.current,
    required this.doneCount,
    required this.unlocked,
    required this.done,
    required this.onSelect,
    required this.test,
    required this.testOpenable,
    required this.onStartTest,
  });

  final List<Map<String, dynamic>> topics;
  final int current;
  final int doneCount;
  final bool Function(int) unlocked;
  final bool Function(int) done;
  final ValueChanged<int> onSelect;
  final Map<String, dynamic>? test;
  final bool testOpenable;
  final VoidCallback onStartTest;

  String? _label(int i, String key) {
    final v = (topics[i][key] as String?)?.trim();
    return v == null || v.isEmpty ? null : v;
  }

  @override
  Widget build(BuildContext context) {
    final chapters = _groupBy([
      for (var i = 0; i < topics.length; i++) i,
    ], (i) => _label(i, 'chapter'));

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        _ProgressCard(done: doneCount, total: topics.length),
        const SizedBox(height: 12),
        for (final (ci, chapter) in chapters.indexed)
          chapter.label == null
              ? _sections(context, chapter, ci)
              : _ChapterCard(
                  key: ValueKey(
                    'training.chapter-$ci-${chapter.topics.contains(current)}',
                  ),
                  title: chapter.label!,
                  sectionCount: _groupBy(
                    chapter.topics,
                    (i) => _label(i, 'section'),
                  ).length,
                  expanded: chapter.topics.contains(current),
                  child: _sections(context, chapter, ci),
                ),
        if (test != null) ...[
          const SizedBox(height: 8),
          _TestEntry(test: test!, openable: testOpenable, onStart: onStartTest),
        ],
      ],
    );
  }

  Widget _sections(BuildContext context, _Group chapter, int ci) {
    final sections = _groupBy(chapter.topics, (i) => _label(i, 'section'));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (si, section) in sections.indexed)
          section.label == null
              ? Column(
                  children: [
                    for (final i in section.topics) _topicRow(context, i),
                  ],
                )
              : ExpansionTile(
                  key: ValueKey(
                    'training.section-$ci-$si-${section.topics.contains(current)}',
                  ),
                  initiallyExpanded: section.topics.contains(current),
                  shape: const Border(),
                  collapsedShape: const Border(),
                  tilePadding: const EdgeInsets.symmetric(horizontal: 8),
                  childrenPadding: const EdgeInsets.only(left: 8),
                  leading: const Icon(Icons.folder_outlined, size: 20),
                  title: Text(
                    section.label!,
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  subtitle: Text(
                    '${section.topics.length} ${section.topics.length == 1 ? 'topic' : 'topics'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  children: [
                    for (final i in section.topics) _topicRow(context, i),
                  ],
                ),
      ],
    );
  }

  Widget _topicRow(BuildContext context, int i) {
    final scheme = Theme.of(context).colorScheme;
    final isUnlocked = unlocked(i);
    final isDone = done(i);
    final selected = i == current;
    final green = ToneColours.of(context, Tone.ok).ink;

    final color = !isUnlocked
        ? scheme.onSurfaceVariant.withValues(alpha: 0.6)
        : selected
        ? scheme.primary
        : isDone
        ? green
        : scheme.onSurface;

    return ListTile(
      key: ValueKey('training.topic-row-$i'),
      dense: true,
      enabled: isUnlocked,
      selected: selected,
      contentPadding: const EdgeInsets.symmetric(horizontal: 8),
      visualDensity: VisualDensity.compact,
      leading: Icon(
        !isUnlocked
            ? Icons.lock
            : isDone
            ? Icons.check_circle
            : Icons.play_circle_outline,
        size: 18,
        color: !isUnlocked
            ? color
            : (isDone
                  ? green
                  : (selected ? scheme.primary : scheme.onSurfaceVariant)),
      ),
      title: Text(
        topics[i]['title'] as String? ?? 'Topic ${i + 1}',
        style: TextStyle(fontSize: 13, color: color),
      ),
      onTap: () => onSelect(i),
    );
  }
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard({required this.done, required this.total});

  final int done;
  final int total;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final fraction = total == 0 ? 0.0 : done / total;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: scheme.primary,
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.menu_book, color: scheme.onPrimary),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: scheme.onPrimary.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  '${(fraction * 100).round()}%',
                  style: TextStyle(
                    color: scheme.onPrimary,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            'Study Guide',
            style: TextStyle(
              color: scheme.onPrimary,
              fontSize: 16,
              fontWeight: FontWeight.w700,
            ),
          ),
          Text(
            '$done / $total topics completed',
            style: TextStyle(
              color: scheme.onPrimary.withValues(alpha: 0.85),
              fontSize: 12,
            ),
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(3),
            child: LinearProgressIndicator(
              value: fraction,
              minHeight: 6,
              color: scheme.onPrimary,
              backgroundColor: scheme.onPrimary.withValues(alpha: 0.25),
            ),
          ),
        ],
      ),
    );
  }
}

class _ChapterCard extends StatelessWidget {
  const _ChapterCard({
    super.key,
    required this.title,
    required this.sectionCount,
    required this.expanded,
    required this.child,
  });

  final String title;
  final int sectionCount;
  final bool expanded;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
        border: Border.all(
          color: expanded ? scheme.primary : EzBiziTheme.edge(context),
          width: expanded ? 2 : 1,
        ),
      ),
      child: ExpansionTile(
        initiallyExpanded: expanded,
        shape: const Border(),
        collapsedShape: const Border(),
        tilePadding: const EdgeInsets.symmetric(horizontal: 10),
        childrenPadding: const EdgeInsets.fromLTRB(6, 0, 6, 6),
        leading: Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(
            color: scheme.primary.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          ),
          child: Icon(
            Icons.auto_stories_outlined,
            size: 20,
            color: scheme.primary,
          ),
        ),
        title: Text(
          title,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
            color: expanded ? scheme.primary : null,
          ),
        ),
        subtitle: Text(
          '$sectionCount ${sectionCount == 1 ? 'section' : 'sections'}',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        children: [child],
      ),
    );
  }
}

class _TestEntry extends StatelessWidget {
  const _TestEntry({
    required this.test,
    required this.openable,
    required this.onStart,
  });

  final Map<String, dynamic> test;
  final bool openable;
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Card(
      margin: EdgeInsets.zero,
      child: ListTile(
        key: const Key('training.start-test'),
        enabled: openable,
        onTap: openable ? onStart : null,
        leading: Icon(
          openable ? Icons.quiz_outlined : Icons.lock,
          color: openable ? scheme.tertiary : null,
        ),
        title: Text(
          test['title'] as String? ?? 'Test',
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        subtitle: Text(
          openable
              ? 'Ready — tap to begin'
              : 'Unlocks when every topic is complete',
          style: theme.textTheme.bodySmall?.copyWith(
            fontStyle: FontStyle.italic,
          ),
        ),
      ),
    );
  }
}

class _Topic extends StatelessWidget {
  const _Topic({
    super.key,
    required this.topic,
    required this.locked,
    required this.done,
    required this.isLast,
    required this.completing,
    required this.onPrevious,
    required this.onComplete,
    required this.onNext,
  });

  final Map<String, dynamic> topic;
  final bool locked;
  final bool done;
  final bool isLast;
  final bool completing;
  final VoidCallback? onPrevious;
  final VoidCallback onComplete;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    if (locked) {
      // Never drawn through the rail (locked rows are disabled); here so no
      // other path can show a locked topic's content either.
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.lock_outline, size: 40, color: scheme.onSurfaceVariant),
            const SizedBox(height: 8),
            Text('This topic is locked', style: theme.textTheme.titleMedium),
            Text(
              'Complete the topics before it to unlock it.',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      );
    }

    final crumbs = [
      topic['chapter'],
      topic['section'],
    ].whereType<String>().where((s) => s.trim().isNotEmpty);
    final blocks = _rows(topic['blocks']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(24),
            children: [
              if (crumbs.isNotEmpty) ...[
                Text(
                  crumbs.join(' › '),
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: scheme.primary,
                  ),
                ),
                const SizedBox(height: 6),
              ],
              Text(
                topic['title'] as String? ?? '',
                style: theme.textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 16),
              if (blocks.isEmpty)
                Text('No content yet.', style: theme.textTheme.bodyMedium),
              for (final block in blocks) ...[
                _Block(block: block),
                const SizedBox(height: 16),
              ],
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          // A row when it fits, stacked on a phone.
          child: OverflowBar(
            alignment: MainAxisAlignment.spaceBetween,
            overflowAlignment: OverflowBarAlignment.end,
            spacing: 8,
            overflowSpacing: 8,
            children: [
              OutlinedButton.icon(
                key: const Key('training.nav-back'),
                onPressed: onPrevious,
                icon: const Icon(Icons.arrow_back, size: 16),
                label: const Text('Previous'),
              ),
              if (!done)
                FilledButton.icon(
                  key: const Key('training.complete-topic'),
                  style: FilledButton.styleFrom(
                    backgroundColor: EzBiziTheme.clockIn(context),
                  ),
                  onPressed: completing ? null : onComplete,
                  icon: const Icon(Icons.check, size: 18),
                  label: const Text('Complete & Continue'),
                )
              else if (isLast)
                Chip(
                  key: const Key('training.all-done'),
                  avatar: Icon(
                    Icons.celebration,
                    size: 18,
                    color: ToneColours.of(context, Tone.ok).ink,
                  ),
                  label: const Text('All done!'),
                )
              else
                FilledButton.icon(
                  key: const Key('training.nav-next'),
                  onPressed: onNext,
                  icon: const Icon(Icons.arrow_forward, size: 18),
                  label: const Text('Next Topic'),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _Block extends StatelessWidget {
  const _Block({required this.block});

  final Map<String, dynamic> block;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    switch (block['type']) {
      case 'text':
        return SelectableText(
          block['text'] as String? ?? '',
          style: theme.textTheme.bodyLarge?.copyWith(height: 1.6),
        );
      case 'image':
        final url = block['url'] as String?;
        return Align(
          alignment: Alignment.centerLeft,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
              child: url == null || !isHttpUrl(url)
                  ? const _Missing(
                      icon: Icons.broken_image_outlined,
                      label: 'Image unavailable',
                    )
                  : Image.network(
                      url,
                      fit: BoxFit.contain,
                      errorBuilder: (_, _, _) => const _Missing(
                        icon: Icons.broken_image_outlined,
                        label: 'Image unavailable',
                      ),
                    ),
            ),
          ),
        );
      case 'video':
        return _VideoBlock(
          url: block['url'] as String? ?? '',
          embedUrl: block['embed_url'] as String?,
        );
      case 'table':
        return _TableBlock(
          rows: (block['rows'] as List? ?? const [])
              .map((r) => (r as List).map((c) => '$c').toList())
              .toList(),
        );
      default:
        return const SizedBox.shrink();
    }
  }
}

class _Missing extends StatelessWidget {
  const _Missing({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      height: 160,
      color: scheme.surfaceContainerHighest,
      alignment: Alignment.center,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: scheme.onSurfaceVariant),
          Text(label, style: Theme.of(context).textTheme.bodySmall),
        ],
      ),
    );
  }
}

/// A video: a play card, then the player in its place — inside the topic,
/// never a pushed screen. Only one WebView exists per video, and only once
/// asked for.
class _VideoBlock extends StatefulWidget {
  const _VideoBlock({required this.url, required this.embedUrl});

  final String url;
  final String? embedUrl;

  @override
  State<_VideoBlock> createState() => _VideoBlockState();
}

class _VideoBlockState extends State<_VideoBlock> {
  bool _playing = false;

  /// The page to play: EzBizi's own for Vimeo/YouTube; on the web any other
  /// http(s) link can sit in the iframe directly.
  String? get _playable {
    final embed = widget.embedUrl;
    if (embed != null && isHttpUrl(embed)) return embed;
    return kIsWeb && isHttpUrl(widget.url) ? widget.url : null;
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final playable = VideoEmbed.supported ? _playable : null;

    return Align(
      alignment: Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 720),
        child: AspectRatio(
          aspectRatio: 16 / 9,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            child: ColoredBox(
              color: scheme.inverseSurface,
              child: _playing && playable != null
                  ? VideoEmbed(url: playable)
                  : InkWell(
                      key: const Key('training.play-video'),
                      onTap: playable != null
                          ? () => setState(() => _playing = true)
                          : () => _openExternal(widget.url),
                      child: Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              Icons.play_circle_fill,
                              size: 72,
                              color: scheme.primary,
                            ),
                            const SizedBox(height: 8),
                            Text(
                              playable != null ? 'Play video' : 'Open video',
                              style: TextStyle(color: scheme.onInverseSurface),
                            ),
                          ],
                        ),
                      ),
                    ),
            ),
          ),
        ),
      ),
    );
  }
}

class _TableBlock extends StatelessWidget {
  const _TableBlock({required this.rows});

  final List<List<String>> rows;

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty) return const SizedBox.shrink();

    final header = rows.first;
    List<String> fit(List<String> r) => [
      for (var i = 0; i < header.length; i++) i < r.length ? r[i] : '',
    ];

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: ListTable(
        key: const Key('guide-table'),
        columns: [for (final h in header) ListColumn(h)],
        rows: [
          for (final r in rows.skip(1)) [for (final c in fit(r)) Text(c)],
        ],
      ),
    );
  }
}

/// Whether a link is safe to hand to an iframe or to `launchUrl`: http(s)
/// only, checked here as well as by the server at write time.
bool isHttpUrl(String url) {
  final uri = Uri.tryParse(url);
  return uri != null && (uri.scheme == 'http' || uri.scheme == 'https');
}

Future<void> _openExternal(String url) async {
  if (!isHttpUrl(url)) return;

  try {
    await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  } catch (_) {
    // No handler for the link: nothing else to offer.
  }
}
