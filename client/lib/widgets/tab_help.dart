import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/tab_tours.dart';
import 'side_panel.dart';

/// The (?) and ▶ beside a tab's title, the first-visit offer under it, the
/// spotlight walkthrough and the how-to video (UI queue 81; Jose: "as u
/// recommend" — 1 and 4).
///
/// Nothing here is a dialog: the walkthrough is an overlay that dims the page
/// around one control, and the video plays in the side panel, so the tab
/// stays on screen to follow along.

/// One tab's how-to video, as `GET /help/videos` sends it.
class HelpVideo {
  const HelpVideo({
    required this.url,
    this.title,
    this.seconds,
    this.chapters = const [],
    this.captionsUrl,
  });

  factory HelpVideo.fromJson(Map<String, dynamic> json) => HelpVideo(
    url: json['url'] as String,
    title: json['title'] as String?,
    seconds: (json['duration_seconds'] as num?)?.toInt(),
    chapters: [
      for (final c in (json['chapters'] as List? ?? const []).cast<Map>())
        (at: (c['at'] as num).toInt(), label: '${c['label']}'),
    ],
    captionsUrl: json['captions_url'] as String?,
  );

  final String url;
  final String? title;
  final int? seconds;
  final List<({int at, String label})> chapters;
  final String? captionsUrl;
}

String _clock(int seconds) =>
    '${seconds ~/ 60}:${(seconds % 60).toString().padLeft(2, '0')}';

/// Every tab's video, fetched once per sign-in and shared by every header.
final _videos = Expando<Future<Map<String, HelpVideo>>>();

Future<Map<String, HelpVideo>> helpVideos(ApiClient api) =>
    _videos[api] ??= api.get('/help/videos').then((res) {
      final data = res.ok ? res.body['data'] : null;
      if (data is! Map) return const <String, HelpVideo>{};
      return {
        for (final e in data.entries)
          if (e.value is Map)
            '${e.key}': HelpVideo.fromJson((e.value as Map).cast()),
      };
    });

/// The walkthrough version this person last finished or declined, by tab.
/// An empty map arrives from PHP as `[]`, which reads as nothing seen.
Map<String, int> _seen(Session session) {
  final raw = session.me?.preferences['help.tours_seen'];
  if (raw is! Map) return const {};
  return {
    for (final e in raw.entries)
      if (e.value is num) '${e.key}': (e.value as num).toInt(),
  };
}

Future<void> _markSeen(Session session, String module, int version) => session
    .setPreference('help.tours_seen', {..._seen(session), module: version});

/// The steps worth offering: all of them the first time, the new ones after
/// the tab's version went up, none once this version is seen.
List<TourStep> _due(Session session, String module) {
  final tour = tabTours[module];
  if (tour == null) return const [];
  final seen = _seen(session)[module] ?? 0;
  if (seen >= tour.version) return const [];
  return [
    for (final s in tour.steps)
      if (s.since > seen) s,
  ];
}

/// (?) and ▶, beside the title. Each draws only when the tab has one.
class TabHelpButtons extends StatelessWidget {
  const TabHelpButtons({
    super.key,
    required this.module,
    required this.label,
    required this.api,
    required this.session,
  });

  final String module;
  final String label;
  final ApiClient api;
  final Session session;

  @override
  Widget build(BuildContext context) {
    final tour = tabTours[module];

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (tour != null)
          IconButton(
            key: const Key('tab-help-tour'),
            tooltip: 'Walk me through $label',
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.help_outline),
            onPressed: () => startTour(
              context,
              steps: tour.steps,
              onEnd: () => _markSeen(session, module, tour.version),
            ),
          ),
        FutureBuilder<Map<String, HelpVideo>>(
          future: helpVideos(api),
          builder: (context, snap) {
            final video = snap.data?[module];
            if (video == null) return const SizedBox.shrink();
            final length = video.seconds == null
                ? ''
                : ' · ${_clock(video.seconds!)}';
            return IconButton(
              key: const Key('tab-help-video'),
              tooltip: 'Watch how $label works$length',
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.play_circle_outline),
              onPressed: () => playHelpVideo(context, video, label: label),
            );
          },
        ),
      ],
    );
  }
}

/// One line under the title the first time a tab is opened, or after its
/// walkthrough gained steps. It never starts the walkthrough by itself:
/// somebody may have come to do a job.
class TourOffer extends StatelessWidget {
  const TourOffer({
    super.key,
    required this.module,
    required this.label,
    required this.session,
  });

  final String module;
  final String label;
  final Session session;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: session,
      builder: (context, _) {
        final due = _due(session, module);
        if (due.isEmpty) return const SizedBox.shrink();
        final tour = tabTours[module]!;
        final fresh = (_seen(session)[module] ?? 0) == 0;
        final scheme = Theme.of(context).colorScheme;
        final phone = MediaQuery.sizeOf(context).width < 600;

        return Padding(
          padding: EdgeInsets.fromLTRB(phone ? 16 : 20, 8, 12, 0),
          child: Material(
            key: const Key('tab-help-offer'),
            color: scheme.primaryContainer,
            borderRadius: BorderRadius.circular(10),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 6, 6, 6),
              child: Wrap(
                spacing: 8,
                runSpacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(
                    fresh
                        ? 'New to $label? A short walkthrough shows what each '
                              'part does.'
                        : "What's new in $label: ${due.length} new "
                              '${due.length == 1 ? 'part' : 'parts'} to see.',
                    style: TextStyle(color: scheme.onPrimaryContainer),
                  ),
                  FilledButton(
                    key: const Key('tab-help-offer-start'),
                    onPressed: () => startTour(
                      context,
                      steps: due,
                      onEnd: () => _markSeen(session, module, tour.version),
                    ),
                    child: Text(fresh ? 'Walk me through' : 'Show me'),
                  ),
                  TextButton(
                    key: const Key('tab-help-offer-dismiss'),
                    onPressed: () => _markSeen(session, module, tour.version),
                    child: const Text('Not now'),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

// ── The walkthrough ─────────────────────────────────────────────

/// The widget carrying `ValueKey(anchor)` that is actually on screen: not in
/// an Offstage (a tab kept alive behind another), and laid out.
Element? _findAnchor(String anchor) {
  final key = ValueKey<String>(anchor);
  Element? hit;

  bool shown(Element e) {
    var visible = true;
    e.visitAncestorElements((a) {
      final w = a.widget;
      if ((w is Offstage && w.offstage) || (w is Visibility && !w.visible)) {
        visible = false;
        return false;
      }
      return true;
    });
    final box = e.findRenderObject();
    return visible && box is RenderBox && box.hasSize && box.attached;
  }

  void visit(Element e) {
    if (hit != null) return;
    if (e.widget.key == key && shown(e)) {
      hit = e;
      return;
    }
    e.visitChildren(visit);
  }

  WidgetsBinding.instance.rootElement?.visitChildren(visit);
  return hit;
}

/// Runs [steps] over the page, skipping any whose control is not on screen.
/// [onEnd] runs when it is finished or skipped, so it is not offered again.
void startTour(
  BuildContext context, {
  required List<TourStep> steps,
  required VoidCallback onEnd,
}) {
  final shown = [
    for (final s in steps)
      if (_findAnchor(s.anchor) != null) s,
  ];
  if (shown.isEmpty) {
    onEnd();
    return;
  }

  late final OverlayEntry entry;
  entry = OverlayEntry(
    builder: (_) => _Tour(
      steps: shown,
      onClose: () {
        entry.remove();
        onEnd();
      },
    ),
  );
  Overlay.of(context, rootOverlay: true).insert(entry);
}

class _Tour extends StatefulWidget {
  const _Tour({required this.steps, required this.onClose});

  final List<TourStep> steps;
  final VoidCallback onClose;

  @override
  State<_Tour> createState() => _TourState();
}

class _TourState extends State<_Tour> {
  int _index = 0;
  Rect? _hole;

  /// Taken from whatever button started the tour, so Esc and the arrows
  /// reach it.
  final _focus = FocusNode(debugLabel: 'tour');

  @override
  void initState() {
    super.initState();
    _show(0);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _focus.requestFocus();
    });
  }

  @override
  void dispose() {
    _focus.dispose();
    super.dispose();
  }

  /// Scrolls the control into view, then measures where it ended up.
  Future<void> _show(int index) async {
    final anchor = _findAnchor(widget.steps[index].anchor);
    if (anchor == null) {
      // Gone since the tour began (a filter, a refresh): skip it.
      index + 1 < widget.steps.length ? _show(index + 1) : widget.onClose();
      return;
    }
    setState(() => _index = index);
    await Scrollable.ensureVisible(
      anchor,
      alignment: 0.3,
      duration: const Duration(milliseconds: 200),
    );
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final box = _findAnchor(widget.steps[_index].anchor)?.findRenderObject();
      final overlay = context.findRenderObject();
      if (box is! RenderBox || overlay is! RenderBox) return;
      final topLeft = box.localToGlobal(Offset.zero, ancestor: overlay);
      setState(() => _hole = topLeft & box.size);
    });
    // A page that is standing still schedules no frame of its own, and the
    // callback above would wait for one forever.
    WidgetsBinding.instance.ensureVisualUpdate();
  }

  void _next() =>
      _index + 1 < widget.steps.length ? _show(_index + 1) : widget.onClose();

  void _back() {
    if (_index > 0) _show(_index - 1);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final size = MediaQuery.sizeOf(context);
    final hole = _hole;
    final step = widget.steps[_index];
    final last = _index == widget.steps.length - 1;
    final phone = size.width < 600;

    final card = Material(
      key: const Key('tour-card'),
      elevation: 8,
      color: theme.colorScheme.surface,
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 12, 10),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'STEP ${_index + 1} OF ${widget.steps.length}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.primary,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.6,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              step.title,
              style: theme.textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 4),
            Text(step.body, style: theme.textTheme.bodyMedium),
            const SizedBox(height: 10),
            Row(
              children: [
                for (var i = 0; i < widget.steps.length; i++)
                  Container(
                    width: 7,
                    height: 7,
                    margin: const EdgeInsets.only(right: 5),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: i == _index
                          ? theme.colorScheme.primary
                          : theme.colorScheme.outlineVariant,
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            // Under the dots, so Skip, Back and Next share one line at 320;
            // a wider font wraps them rather than pushing Next off the card.
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(
                alignment: WrapAlignment.end,
                spacing: 6,
                runSpacing: 4,
                children: [
                  if (!last)
                    TextButton(
                      key: const Key('tour-skip'),
                      onPressed: widget.onClose,
                      child: const Text('Skip tour'),
                    ),
                  if (_index > 0)
                    OutlinedButton(
                      key: const Key('tour-back'),
                      onPressed: _back,
                      child: const Text('Back'),
                    ),
                  FilledButton(
                    key: const Key('tour-next'),
                    onPressed: _next,
                    child: Text(last ? 'Done' : 'Next'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );

    return Focus(
      focusNode: _focus,
      onKeyEvent: (_, event) {
        if (event is! KeyDownEvent) return KeyEventResult.ignored;
        if (event.logicalKey == LogicalKeyboardKey.escape) {
          widget.onClose();
        } else if (event.logicalKey == LogicalKeyboardKey.arrowRight) {
          _next();
        } else if (event.logicalKey == LogicalKeyboardKey.arrowLeft) {
          _back();
        } else {
          return KeyEventResult.ignored;
        }
        return KeyEventResult.handled;
      },
      child: Stack(
        children: [
          // Everything but the control, dimmed; taps on it go nowhere.
          Positioned.fill(
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () {},
              child: CustomPaint(
                painter: _Dim(
                  hole: hole,
                  scrim: theme.colorScheme.scrim.withValues(alpha: 0.55),
                  ring: theme.colorScheme.primary,
                ),
              ),
            ),
          ),
          if (hole != null)
            if (phone)
              Positioned(
                left: 12,
                right: 12,
                bottom: 12 + MediaQuery.paddingOf(context).bottom,
                child: card,
              )
            else
              _placed(card, hole, size),
        ],
      ),
    );
  }

  /// Beside the control where there is room, else under it.
  Widget _placed(Widget card, Rect hole, Size size) {
    const width = 320.0;
    const gap = 16.0;
    double left;
    double top = hole.top;
    if (hole.right + gap + width <= size.width - 12) {
      left = hole.right + gap;
    } else if (hole.left - gap - width >= 12) {
      left = hole.left - gap - width;
    } else {
      left = hole.left.clamp(12, size.width - width - 12);
      top = hole.bottom + gap;
    }
    return Positioned(
      left: left,
      top: top.clamp(12, size.height - 200),
      width: width,
      child: card,
    );
  }
}

class _Dim extends CustomPainter {
  _Dim({required this.hole, required this.scrim, required this.ring});

  final Rect? hole;
  final Color scrim;
  final Color ring;

  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size);
    if (hole == null) {
      canvas.drawPath(path, Paint()..color = scrim);
      return;
    }
    final cut = RRect.fromRectAndRadius(
      hole!.inflate(6),
      const Radius.circular(10),
    );
    canvas.drawPath(path..addRRect(cut), Paint()..color = scrim);
    canvas.drawRRect(
      cut,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3
        ..color = ring,
    );
  }

  @override
  bool shouldRepaint(_Dim old) =>
      old.hole != hole || old.scrim != scrim || old.ring != ring;
}

// ── The video ───────────────────────────────────────────────────

/// In the side panel; Keep it small hands the same player to a corner of the
/// window, so it carries on while the person tries what it showed.
Future<void> playHelpVideo(
  BuildContext context,
  HelpVideo video, {
  required String label,
}) async {
  final player = VideoPlayerController.networkUrl(
    Uri.parse(video.url),
    closedCaptionFile: video.captionsUrl == null
        ? null
        : http
              .get(Uri.parse(video.captionsUrl!))
              .then((r) => WebVTTCaptionFile(r.body)),
  );
  final overlay = Overlay.of(context, rootOverlay: true);

  final small = await showSidePanel<bool>(
    context,
    builder: (_) => _VideoPanel(video: video, label: label, player: player),
  );

  if (small != true) {
    player.dispose();
    return;
  }

  late final OverlayEntry entry;
  entry = OverlayEntry(
    builder: (_) => _MiniPlayer(
      player: player,
      title: video.title ?? 'How $label works',
      onClose: () {
        entry.remove();
        player.dispose();
      },
    ),
  );
  overlay.insert(entry);
}

class _VideoPanel extends StatefulWidget {
  const _VideoPanel({
    required this.video,
    required this.label,
    required this.player,
  });

  final HelpVideo video;
  final String label;
  final VideoPlayerController player;

  @override
  State<_VideoPanel> createState() => _VideoPanelState();
}

class _VideoPanelState extends State<_VideoPanel> {
  /// Set when this platform has no player (Windows), or the file will not
  /// play: the link opens in the browser instead.
  bool _cannotPlay = false;

  @override
  void initState() {
    super.initState();
    final player = widget.player;
    if (player.value.isInitialized) return;
    player
        .initialize()
        .then((_) {
          if (mounted) player.play();
        })
        .catchError((Object _) {
          if (mounted) setState(() => _cannotPlay = true);
        });
  }

  @override
  Widget build(BuildContext context) {
    final video = widget.video;
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return SidePanel(
      title: video.title ?? 'How ${widget.label} works',
      subtitle: video.seconds == null ? null : _clock(video.seconds!),
      width: 480,
      actions: [
        if (!_cannotPlay)
          OutlinedButton(
            key: const Key('help-video-small'),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Keep it small'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_cannotPlay)
            OutlinedButton.icon(
              key: const Key('help-video-open'),
              onPressed: () => launchUrl(
                Uri.parse(video.url),
                mode: LaunchMode.externalApplication,
              ),
              icon: const Icon(Icons.open_in_new, size: 18),
              label: const Text('Open the video'),
            )
          else
            _Screen(player: widget.player),
          const SizedBox(height: 12),
          for (final c in video.chapters)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Text(
                _clock(c.at),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w700,
                ),
              ),
              title: Text(c.label),
              onTap: _cannotPlay
                  ? null
                  : () => widget.player.seekTo(Duration(seconds: c.at)),
            ),
          const SizedBox(height: 8),
          Text(
            'Made by EzBizi, the same for every organization.',
            style: muted,
          ),
        ],
      ),
    );
  }
}

/// The picture, its captions, and play/pause with a scrubber.
class _Screen extends StatelessWidget {
  const _Screen({required this.player, this.compact = false});

  final VideoPlayerController player;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return ValueListenableBuilder<VideoPlayerValue>(
      valueListenable: player,
      builder: (context, value, _) {
        return ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: ColoredBox(
            color: scheme.inverseSurface,
            child: AspectRatio(
              aspectRatio: value.isInitialized ? value.aspectRatio : 16 / 9,
              child: !value.isInitialized
                  ? Center(
                      child: Text(
                        'Loading the video…',
                        style: TextStyle(color: scheme.onInverseSurface),
                      ),
                    )
                  : Stack(
                      fit: StackFit.expand,
                      children: [
                        VideoPlayer(player),
                        if (value.caption.text.isNotEmpty && !compact)
                          Positioned(
                            left: 12,
                            right: 12,
                            bottom: 34,
                            child: ClosedCaption(text: value.caption.text),
                          ),
                        Positioned(
                          left: 0,
                          right: 0,
                          bottom: 0,
                          // Dark behind the controls, so they read over a
                          // light frame as well as a dark one.
                          child: ColoredBox(
                            color: scheme.scrim.withValues(alpha: 0.45),
                            child: Row(
                              children: [
                                IconButton(
                                  key: const Key('help-video-play'),
                                  color: scheme.surface,
                                  tooltip: value.isPlaying ? 'Pause' : 'Play',
                                  icon: Icon(
                                    value.isPlaying
                                        ? Icons.pause
                                        : Icons.play_arrow,
                                  ),
                                  onPressed: value.isPlaying
                                      ? player.pause
                                      : player.play,
                                ),
                                Expanded(
                                  child: VideoProgressIndicator(
                                    player,
                                    allowScrubbing: true,
                                    colors: VideoProgressColors(
                                      playedColor: scheme.primary,
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 12),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
            ),
          ),
        );
      },
    );
  }
}

class _MiniPlayer extends StatelessWidget {
  const _MiniPlayer({
    required this.player,
    required this.title,
    required this.onClose,
  });

  final VideoPlayerController player;
  final String title;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final phone = MediaQuery.sizeOf(context).width < 600;

    return Positioned(
      right: 16,
      left: phone ? 16 : null,
      bottom: 16 + MediaQuery.paddingOf(context).bottom,
      width: phone ? null : 320,
      child: Material(
        key: const Key('help-video-mini'),
        elevation: 10,
        borderRadius: BorderRadius.circular(12),
        color: Theme.of(context).colorScheme.surface,
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _Screen(player: player, compact: true),
              Row(
                children: [
                  const SizedBox(width: 4),
                  Expanded(
                    child: Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                  IconButton(
                    key: const Key('help-video-mini-close'),
                    tooltip: 'Close the video',
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: onClose,
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
