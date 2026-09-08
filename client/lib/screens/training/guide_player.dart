import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/tag.dart';
import 'test_player.dart';
import 'video_embed.dart';

/// The guide reader — layout 4 of `docs/previews/training-layouts.html`.
///
/// A screen rail beside the reader: every screen of the guide, ticked for
/// what has been marked seen, and the guide's own test (if it has one) as
/// the LAST rail item — gated, reading "Unlocks when all N are seen" until
/// every screen has been seen (§4: a control that is offered must be a
/// control that works, so that gate renders disabled rather than a button
/// that would 403).
///
/// ── There is no `GET .../guides/{id}` learner endpoint ─────────────
///
/// So this widget reads its own guide — and any test naming it as its own
/// `guide_id` — out of the SAME `GET /training/learn` list Task 12's
/// `LearnShelves` already consumes. `assigned` and `library` are searched
/// together: a guide sitting in one shelf and its gating test sitting in the
/// other is an ordinary shape, not an edge case (see LearnShelves's own
/// docblock on `_guidesWithATest`).
///
/// ── `last_screen` is a high-water mark, never a cursor ─────────────
///
/// Exactly as `ProgressController::seen` documents it. This widget never
/// computes "how far read" from the screen currently on display — it trusts
/// only the `last_screen`/`completed_at` the SERVER hands back from each
/// `POST .../seen` call ([_markSeen]), so going back to re-read an earlier
/// screen of an already-finished guide cannot locally un-finish it. The
/// server's own `max()` is what actually enforces that; this widget's job is
/// only to not fight it by inventing a second, cursor-shaped notion of
/// progress.
///
/// ── Below [splitAbove] the rail is gone ─────────────────────────────
///
/// Same constant, same threshold `InventoryScreen` already uses for its own
/// list/pane split (`InventoryScreen.splitAbove`). Since the rail is the
/// only way layout 4 draws to reach the guide's test, and it disappears
/// below that width, the same "Test" entry ([_TestEntry]) is drawn a second
/// place on a narrow screen: appended under the reader's own content, always
/// visible rather than only reachable by paging past the last screen — the
/// mockup does not draw a narrow test-entry point at all, so this is the
/// minimal addition that keeps the test reachable on a phone without
/// inventing a new interaction model.
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

  /// Below this the rail collapses and the reader is the whole body — the
  /// same value as `InventoryScreen.splitAbove`.
  static const double splitAbove = 600;

  @override
  State<GuidePlayer> createState() => _GuidePlayerState();
}

class _GuidePlayerState extends State<GuidePlayer> {
  bool _loading = true;
  String? _error;

  String _title = '';
  List<Map<String, dynamic>> _screens = const [];

  /// -1: never opened. Otherwise the server's own high-water mark.
  int _lastScreen = -1;
  DateTime? _completedAt;

  /// The entry (if any) whose own `guide_id` names this guide — never a
  /// second, hand-rolled notion of which test belongs here.
  Map<String, dynamic>? _test;

  int _current = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _screens.isEmpty;
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

    final screens = (guide['screens'] as List? ?? const [])
        .whereType<Map>()
        .map((s) => Map<String, dynamic>.from(s))
        .toList();

    final rawLastScreen = guide['last_screen'];
    final resolvedLastScreen = rawLastScreen is num ? rawLastScreen.toInt() : -1;

    var start = resolvedLastScreen >= 0 ? resolvedLastScreen : 0;
    if (screens.isNotEmpty && start > screens.length - 1) start = screens.length - 1;
    if (start < 0) start = 0;

    setState(() {
      _loading = false;
      _title = guide['title'] as String? ?? '';
      _screens = screens;
      _lastScreen = resolvedLastScreen;
      _completedAt = _parseIso(guide['completed_at'] as String?);
      _test = test.isEmpty ? null : test;
      _current = start;
    });

    if (screens.isNotEmpty) {
      unawaited(_markSeen(_current));
    }
  }

  /// Tells the server this screen has been seen, then adopts whatever it
  /// says back — never a locally-computed guess. This is what keeps a
  /// finished guide finished when the learner pages back to re-read an
  /// earlier screen: the server's `max()` (`ProgressController::seen`) is
  /// the only thing that decides `last_screen`/`completed_at` here.
  Future<void> _markSeen(int screen) async {
    final response = await widget.api.post(
      '/training/learn/guides/${widget.guideId}/seen',
      {'screen': screen},
    );
    if (!mounted || !response.ok) return;

    final lastScreen = response.body['last_screen'];

    setState(() {
      _lastScreen = lastScreen is num ? lastScreen.toInt() : _lastScreen;
      _completedAt = _parseIso(response.body['completed_at'] as String?);
    });
  }

  void _selectScreen(int index) {
    setState(() => _current = index);
    unawaited(_markSeen(index));
  }

  void _openTest(BuildContext context) {
    final test = _test;
    if (test == null) return;

    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => TestPlayer(
          api: widget.api,
          session: widget.session,
          testId: test['id'] as String,
          onLeave: () => Navigator.of(context).pop(),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final total = _screens.length;
    final seenCount = _lastScreen >= 0 ? (_lastScreen + 1).clamp(0, total).toInt() : 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Header(title: _title, seenCount: seenCount, total: total, onLeave: widget.onLeave),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: total == 0
                ? Center(
                    child: Text(
                      'This guide has no screens yet.',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  )
                : LayoutBuilder(
                    builder: (context, constraints) {
                      final wide = constraints.maxWidth >= GuidePlayer.splitAbove;
                      final unlocked = _completedAt != null;

                      final reader = _ReaderBody(
                        screen: _screens[_current],
                        onBack: _current > 0 ? () => _selectScreen(_current - 1) : null,
                        onNext: _current < total - 1 ? () => _selectScreen(_current + 1) : null,
                        trailing: (!wide && _test != null)
                            ? _TestEntry(
                                test: _test!,
                                unlocked: unlocked,
                                totalScreens: total,
                                onStart: () => _openTest(context),
                              )
                            : null,
                      );

                      if (!wide) return reader;

                      return Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            flex: 1,
                            child: KeyedSubtree(
                              key: const Key('training.screen-rail'),
                              child: _Rail(
                                screens: _screens,
                                current: _current,
                                lastScreen: _lastScreen,
                                onSelect: _selectScreen,
                                test: _test,
                                unlocked: unlocked,
                                onStartTest: () => _openTest(context),
                              ),
                            ),
                          ),
                          VerticalDivider(width: 1, thickness: 1, color: EzBiziTheme.edge(context)),
                          Expanded(flex: 3, child: reader),
                        ],
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}

List<Map<String, dynamic>> _rows(Object? raw) => raw is List
    ? raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
    : const [];

DateTime? _parseIso(String? iso) => iso == null ? null : DateTime.tryParse(iso)?.toLocal();

/// "← Leave", the title, and how much of the guide has been seen — the
/// mockup's own top bar for the player, minus the scope selector and the
/// signed-in avatar, which belong to the app's own persistent chrome and are
/// not this widget's to redraw.
class _Header extends StatelessWidget {
  const _Header({
    required this.title,
    required this.seenCount,
    required this.total,
    required this.onLeave,
  });

  final String title;
  final int seenCount;
  final int total;
  final VoidCallback onLeave;

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
            if (total > 0) Tag('$seenCount of $total seen'),
          ],
        ),
      ),
    );
  }
}

/// Every screen, ticked for what has been seen, and the guide's own test (if
/// it has one) as the last item — layout 4's own shape, drawn only when the
/// split is wide enough (`GuidePlayer.splitAbove`).
class _Rail extends StatelessWidget {
  const _Rail({
    required this.screens,
    required this.current,
    required this.lastScreen,
    required this.onSelect,
    required this.test,
    required this.unlocked,
    required this.onStartTest,
  });

  final List<Map<String, dynamic>> screens;
  final int current;
  final int lastScreen;
  final ValueChanged<int> onSelect;
  final Map<String, dynamic>? test;
  final bool unlocked;
  final VoidCallback onStartTest;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 8),
      children: [
        for (var i = 0; i < screens.length; i++)
          _ScreenRow(
            index: i,
            title: screens[i]['title'] as String? ?? 'Screen ${i + 1}',
            seen: i <= lastScreen,
            selected: i == current,
            onTap: () => onSelect(i),
          ),
        if (test != null) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(13, 12, 13, 8),
            child: Divider(color: EzBiziTheme.edge(context), height: 1),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 13),
            child: _TestEntry(
              test: test!,
              unlocked: unlocked,
              totalScreens: screens.length,
              onStart: onStartTest,
            ),
          ),
        ],
      ],
    );
  }
}

class _ScreenRow extends StatelessWidget {
  const _ScreenRow({
    required this.index,
    required this.title,
    required this.seen,
    required this.selected,
    required this.onTap,
  });

  final int index;
  final String title;
  final bool seen;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
        decoration: BoxDecoration(
          color: selected ? scheme.primary.withValues(alpha: 0.08) : null,
          border: Border(
            left: BorderSide(
              color: selected ? scheme.primary : Colors.transparent,
              width: 3,
            ),
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _Badge(seen: seen, label: '${index + 1}'),
            const SizedBox(width: 9),
            Expanded(
              child: Text(
                title,
                style: selected
                    ? theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w700)
                    : theme.textTheme.bodyMedium,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.seen, required this.label});

  final bool seen;
  final String label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: 20,
      height: 20,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: seen ? scheme.primary : scheme.surface,
        border: Border.all(color: seen ? scheme.primary : EzBiziTheme.edge(context)),
      ),
      child: seen
          ? Icon(Icons.check, size: 12, color: scheme.onPrimary)
          : Text(
              label,
              style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: scheme.onSurfaceVariant),
            ),
    );
  }
}

/// The guide's own test, as the rail draws it (last item) and as a narrow
/// screen draws it (appended under the reader) — the SAME widget either way,
/// so the gate can never read differently in the two places. A real
/// `ElevatedButton` rather than a plain row: §4 wants a control that is
/// offered to be a control that works, and `onPressed: null` is what makes
/// "disabled" a fact a test (and a screen reader) can observe, not a look.
///
/// No colour is set on the two lines of text inside the button on purpose —
/// `ElevatedButton` supplies its own foreground per enabled/disabled state,
/// which is what actually dims the reason text when the gate is shut; baking
/// in `theme.textTheme`'s own ink colour here would print the same dark text
/// whether or not the control can be pressed.
class _TestEntry extends StatelessWidget {
  const _TestEntry({
    required this.test,
    required this.unlocked,
    required this.totalScreens,
    required this.onStart,
  });

  final Map<String, dynamic> test;
  final bool unlocked;
  final int totalScreens;
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      child: ElevatedButton(
        key: const Key('training.start-test'),
        onPressed: unlocked ? onStart : null,
        style: ElevatedButton.styleFrom(
          alignment: Alignment.centerLeft,
          padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 10),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              test['title'] as String? ?? 'Test',
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
            ),
            Text(
              unlocked ? 'Ready — tap to begin' : 'Unlocks when all $totalScreens are seen',
              style: const TextStyle(fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}

/// One screen: title, its media (if any), body text, and the Back/Next
/// navbar — the mockup's `.reader` + `.navbar`. [trailing], when set, is the
/// narrow layout's own copy of [_TestEntry] (see this file's own docblock,
/// "Below splitAbove the rail is gone").
class _ReaderBody extends StatelessWidget {
  const _ReaderBody({
    required this.screen,
    required this.onBack,
    required this.onNext,
    this.trailing,
  });

  final Map<String, dynamic> screen;
  final VoidCallback? onBack;
  final VoidCallback? onNext;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = screen['title'] as String? ?? '';
    final body = screen['body'] as String? ?? '';
    final mediaKind = screen['media_kind'] as String? ?? 'none';
    final mediaUrl = screen['media_url'] as String?;
    final hasMedia = mediaKind != 'none' && mediaUrl != null && mediaUrl.isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(title, style: theme.textTheme.headlineSmall),
              const SizedBox(height: 14),
              if (hasMedia) ...[
                _ScreenMedia(kind: mediaKind, url: mediaUrl),
                const SizedBox(height: 16),
              ],
              Text(body, style: theme.textTheme.bodyMedium),
              if (trailing != null) ...[
                const SizedBox(height: 20),
                trailing!,
              ],
            ],
          ),
        ),
        Divider(height: 1, color: EzBiziTheme.edge(context)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Row(
            children: [
              OutlinedButton(
                key: const Key('training.nav-back'),
                onPressed: onBack,
                child: const Text('◀ Back'),
              ),
              const Spacer(),
              ElevatedButton(
                key: const Key('training.nav-next'),
                onPressed: onNext,
                child: const Text('Next ▶'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// A screen's media — an image (a plain `Image.network`, no platform split
/// needed) or a video (link-only: an embedded iframe on web, an "Open video"
/// link everywhere else — see video_embed.dart's own docblock for the
/// precedent this follows).
class _ScreenMedia extends StatelessWidget {
  const _ScreenMedia({required this.kind, required this.url});

  final String kind;
  final String url;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return AspectRatio(
      aspectRatio: 16 / 9,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: EzBiziTheme.edge(context)),
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(9),
          child: kind == 'image'
              ? Image.network(
                  url,
                  fit: BoxFit.cover,
                  errorBuilder: (context, error, stack) =>
                      _MediaFallback(url: url, icon: Icons.broken_image_outlined, label: 'Open image'),
                )
              : kIsWeb
              ? VideoEmbed(url: url)
              : _MediaFallback(url: url, icon: Icons.play_circle_outline, label: 'Open video'),
        ),
      ),
    );
  }
}

/// What stands in for media that cannot render inline here — off-web video,
/// or an image link that failed to load. Nothing may assume a browser (task
/// brief): a link the learner can still open externally beats a broken box.
class _MediaFallback extends StatelessWidget {
  const _MediaFallback({required this.url, required this.icon, required this.label});

  final String url;
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 34, color: scheme.primary),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: () => _openExternal(url),
            icon: const Icon(Icons.open_in_new, size: 16),
            label: Text(label),
          ),
        ],
      ),
    );
  }
}

Future<void> _openExternal(String url) async {
  final uri = Uri.tryParse(url);
  if (uri == null) return;

  try {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {
    // No registered handler for the link — the same no-op
    // camera_monitoring_tab.dart's own deep-link card falls back to.
  }
}
