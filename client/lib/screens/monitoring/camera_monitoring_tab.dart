import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'camera_view.dart';
import 'note_card.dart';

/// Camera Monitoring — the tile grid of connected cameras (spec §"Sub-project
/// 3"), the last of the three Monitoring sub-tabs to get a real screen.
///
/// ── The rule this tab exists to keep honest ────────────────────────
///
/// Same guarantee every other Monitoring tab carries: nothing here may be
/// shown as though it were more live than it really is.
///
///   * No `monitoring.cameras.view` → [noPermissionNote], no fetch at all —
///     same reasoning as [DesktopMonitoringTab]'s own gate: the server would
///     403 every read anyway, so asking first only to show the same sentence
///     a beat later teaches nobody anything.
///   * A camera with `enabled: false` never renders as though it were being
///     watched — no inline frame, no deep-link controls, just a plainly
///     labelled disabled tile. See [_DisabledBody].
///   * `hls` cameras play inline in a real `<video>` — but ONLY on web and
///     ONLY where the vendored hls.js or native HLS can carry it. Off-web, or
///     in a browser that can do neither, an `hls` tile falls back to the same
///     copyable-URL card, which never claims to be playing.
///   * `rtsp` cameras are a copyable URL and nothing more, on every platform:
///     no browser can play RTSP, so there is nothing honest to show inline.
///   * `mjpeg`/`snapshot` cameras get a real inline `<img>` — but ONLY on
///     web, where a DOM element can exist at all. Everywhere else (desktop,
///     mobile) they get the same kind of URL card the deep-link tiles get,
///     worded for what IS true on that platform: open it in a browser to see it.
class CameraMonitoringTab extends StatefulWidget {
  const CameraMonitoringTab({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  static const String noPermissionNote =
      'Your role does not include monitoring.cameras.view, so cameras are '
      'not shown here.';

  static const String noCamerasNote = 'No cameras connected yet.';

  // The empty state draws its button UNDER this sentence, which said above.
  static const String noCamerasManageHint =
      'Use "Add camera" below to connect one.';

  /// The four protocols the backend accepts, in the order the "Add camera"
  /// form offers them.
  static const List<String> protocols = ['mjpeg', 'snapshot', 'hls', 'rtsp'];

  @override
  State<CameraMonitoringTab> createState() => _CameraMonitoringTabState();
}

class _CameraMonitoringTabState extends State<CameraMonitoringTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _cameras = [];

  bool get _canView =>
      widget.session.me?.can('monitoring.cameras.view') ?? false;

  bool get _canManage =>
      widget.session.me?.can('monitoring.cameras.manage') ?? false;

  @override
  void initState() {
    super.initState();
    if (_canView) _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _cameras.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/monitoring/cameras');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _cameras = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    });
  }

  Future<void> _addCamera() async {
    final result = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => const _AddCameraPanel(),
    );

    if (result == null || !mounted) return;

    final response = await widget.api.post('/monitoring/cameras', result);

    if (!mounted) return;

    if (!response.ok) {
      _snack(response.message, isError: true);
      return;
    }

    await _load();
  }

  Future<void> _deleteCamera(String id, String label) async {
    final confirmed = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Delete $label?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Delete'),
          ),
        ],
        child: const Text(
          'This removes the camera from EzBizi. It does not change anything '
          'on the camera or the device serving it.',
        ),
      ),
    );

    if (confirmed != true || !mounted) return;

    final response = await widget.api.delete('/monitoring/cameras/$id');

    if (!mounted) return;

    if (!response.ok) {
      _snack(response.message, isError: true);
      return;
    }

    await _load();
  }

  void _snack(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
        behavior: SnackBarBehavior.floating,
        width: 420,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (!_canView) {
      return NoteCard(CameraMonitoringTab.noPermissionNote);
    }

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _content(context),
    );
  }

  Widget _content(BuildContext context) {
    if (_cameras.isEmpty) {
      return _EmptyState(canManage: _canManage, onAdd: _addCamera);
    }

    return SingleChildScrollView(
      key: const Key('camera-monitoring-grid'),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_canManage) ...[
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                key: const Key('camera-monitoring-add-button'),
                onPressed: _addCamera,
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add camera'),
              ),
            ),
            const SizedBox(height: 14),
          ],
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            // 360 + the 12px spacing below never fits two columns inside the
            // 343px a 375px-wide screen leaves after this column's own 16px
            // padding on each side (R7) — one column there, more as the
            // screen widens, with no breakpoint math to keep in sync by hand.
            gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
              maxCrossAxisExtent: 360,
              mainAxisExtent: 360,
              crossAxisSpacing: 12,
              mainAxisSpacing: 12,
            ),
            itemCount: _cameras.length,
            itemBuilder: (context, i) {
              final camera = _cameras[i];
              return _CameraTile(
                camera: camera,
                canManage: _canManage,
                onDelete: () => _deleteCamera(
                  camera['id'] as String,
                  camera['label'] as String? ?? 'this camera',
                ),
              );
            },
          ),
        ],
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.canManage, required this.onAdd});

  final bool canManage;
  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    return Center(
      key: const ValueKey('tour-monitoring-cameras-empty'),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                CameraMonitoringTab.noCamerasNote,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              if (canManage) ...[
                const SizedBox(height: 6),
                Text(
                  CameraMonitoringTab.noCamerasManageHint,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 14),
                OutlinedButton.icon(
                  key: const Key('camera-monitoring-add-button'),
                  onPressed: onAdd,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add camera'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

String _protocolLabel(String protocol) => switch (protocol) {
  'mjpeg' => 'MJPEG',
  'snapshot' => 'Snapshot',
  'hls' => 'HLS',
  'rtsp' => 'RTSP',
  _ => protocol.toUpperCase(),
};

/// What a tile shows in its live-view slot. Kept as a value the tile switches
/// on, rather than an `if`-ladder inside `build`, for one reason: [kIsWeb] is a
/// compile-time `false` under `flutter test`, so the branch that only fires on
/// web can never be exercised through a widget pump. As a pure function of its
/// inputs it can be — the tab feeds it `kIsWeb`, a test feeds it `true`.
enum CameraBodyKind {
  /// Disabled camera — the [_DisabledBody] placeholder, whatever its protocol.
  disabled,

  /// `mjpeg`/`snapshot` on web — the inline `<img>` ([_InlineViewCard]); off-web
  /// this same kind renders that card's "open in a browser" fallback.
  inlineImage,

  /// `hls` on web with a player available — the inline `<video>`
  /// ([_InlineHlsCard]).
  inlineHls,

  /// Everything with no honest inline story here: `rtsp` always, `hls` off-web
  /// or with no player, anything unknown — the copy-URL [_DeepLinkCard].
  deepLink,
}

/// The single place that decides a tile's live-view slot. See
/// [CameraBodyKind]'s note for why this is a function and not inline `if`s.
///
/// `hls` is the only protocol whose answer depends on the platform: it plays
/// inline only on web AND only where hls.js or native HLS can carry it
/// ([cameraHlsInlineSupported]). `rtsp` has no browser story at all and stays a
/// deep link everywhere; `mjpeg`/`snapshot` are always the image host (which
/// itself shows the open-in-browser card off-web).
CameraBodyKind cameraBodyKind({
  required bool enabled,
  required String protocol,
  required bool isWeb,
  required bool hlsInlineSupported,
}) {
  if (!enabled) return CameraBodyKind.disabled;
  if (protocol == 'mjpeg' || protocol == 'snapshot') {
    return CameraBodyKind.inlineImage;
  }
  if (protocol == 'hls' && isWeb && hlsInlineSupported) {
    return CameraBodyKind.inlineHls;
  }
  return CameraBodyKind.deepLink;
}

class _CameraTile extends StatelessWidget {
  const _CameraTile({
    required this.camera,
    required this.canManage,
    required this.onDelete,
  });

  final Map<String, dynamic> camera;
  final bool canManage;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final id = camera['id'] as String;
    final label = camera['label'] as String? ?? 'Camera';
    final protocol = camera['protocol'] as String? ?? '';
    final url = camera['url'] as String? ?? '';
    final enabled = camera['enabled'] as bool? ?? true;

    final kind = cameraBodyKind(
      enabled: enabled,
      protocol: protocol,
      isWeb: kIsWeb,
      // Called off-web too, where it is the stub's `false` — no js_interop, no
      // cost. The `isWeb` above still gates it inside cameraBodyKind.
      hlsInlineSupported: cameraHlsInlineSupported(),
    );

    final body = switch (kind) {
      CameraBodyKind.disabled => const _DisabledBody(),
      CameraBodyKind.inlineImage => _InlineViewCard(
        key: ValueKey('camera-inline-$id'),
        label: label,
        url: url,
      ),
      CameraBodyKind.inlineHls => _InlineHlsCard(
        key: ValueKey('camera-hls-$id'),
        url: url,
      ),
      CameraBodyKind.deepLink => _DeepLinkCard(
        key: ValueKey('camera-deeplink-$id'),
        protocol: protocol,
        url: url,
      ),
    };

    final tile = Card(
      key: ValueKey('camera-tile-$id'),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    label,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                Tag(_protocolLabel(protocol)),
                if (!enabled) const Tag('Disabled', warn: true),
              ],
            ),
            const SizedBox(height: 8),
            Expanded(child: body),
            if (canManage) ...[
              const SizedBox(height: 4),
              Align(
                alignment: Alignment.centerRight,
                child: IconButton(
                  key: ValueKey('camera-tile-delete-$id'),
                  onPressed: onDelete,
                  icon: const Icon(Icons.delete_outline, size: 20),
                  tooltip: 'Delete $label',
                ),
              ),
            ],
          ],
        ),
      ),
    );

    // Greyed, per the same honesty rule DesktopMonitoringTab's stopped hero
    // uses — a disabled camera reads as not-live at a glance, before anybody
    // has to read the tag to know it.
    return enabled ? tile : Opacity(opacity: 0.6, child: tile);
  }
}

/// What a disabled camera's tile shows instead of any live view, whatever
/// its protocol — never a frame or a deep link passed off as something being
/// watched.
class _DisabledBody extends StatelessWidget {
  const _DisabledBody();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return DecoratedBox(
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.videocam_off_outlined, color: scheme.onSurfaceVariant),
            const SizedBox(height: 6),
            Text(
              'Disabled — no view shown.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    );
  }
}

/// The live-view host for `mjpeg`/`snapshot` — [CameraImageView], which is a
/// DOM `<img>` on web and a native dart:io MJPEG/snapshot reader off-web
/// (camera_view_stub.dart). Inline on every platform now; the conditional
/// import keeps `dart:ui_web`/`package:web` out of non-web builds and `dart:io`
/// out of the web build.
class _InlineViewCard extends StatelessWidget {
  const _InlineViewCard({super.key, required this.label, required this.url});

  final String label;
  final String url;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    // Inline everywhere now: the web half is the DOM <img>, and the off-web
    // half (camera_view_stub.dart) is a native dart:io MJPEG/snapshot reader.
    // Jose, 2026-10-06: the cameras "should work on the app, not just on web."
    //
    // The grid tile is the LIGHT view — a frame every few seconds. Tapping it
    // opens the same camera as a real live stream (?live=1), full motion, in a
    // side panel, and the stream is torn down when the panel closes. Jose,
    // 2026-10-07: "click one of them and then it goes into live mode."
    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: Stack(
        fit: StackFit.expand,
        children: [
          CameraImageView(url: url),
          Material(
            type: MaterialType.transparency,
            child: InkWell(
              onTap: () => _openLivePanel(context, label, url),
              child: Align(
                alignment: Alignment.topRight,
                child: Padding(
                  padding: const EdgeInsets.all(6),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: scheme.inverseSurface.withValues(alpha: 0.72),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 4,
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.play_arrow_rounded,
                            size: 16,
                            color: scheme.onInverseSurface,
                          ),
                          const SizedBox(width: 2),
                          Text(
                            'Live',
                            style: Theme.of(context).textTheme.labelSmall
                                ?.copyWith(color: scheme.onInverseSurface),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Add `?live=1` to a camera URL — what the serving bridge reads to switch a
/// camera from the cheap snapshot feed to a real live stream. Preserves any
/// query already on the URL.
String _withLive(String url) {
  final u = Uri.tryParse(url);
  if (u == null) return url;
  final q = Map<String, String>.from(u.queryParameters)..['live'] = '1';
  return u.replace(queryParameters: q).toString();
}

Future<void> _openLivePanel(BuildContext context, String label, String url) {
  return showSidePanel<void>(
    context,
    builder: (context) => _LiveViewPanel(label: label, url: _withLive(url)),
  );
}

/// The enlarged live view — a side panel (not a full-screen push, not a modal
/// dialog; the grid stays visible beside it) hosting the same [CameraImageView]
/// pointed at the `?live=1` URL. Closing the panel drops the stream, which is
/// what tells the bridge to stop pulling the camera.
class _LiveViewPanel extends StatelessWidget {
  const _LiveViewPanel({required this.label, required this.url});

  final String label;
  final String url;

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: label,
      subtitle: 'Live — full motion while this is open.',
      width: 760,
      // The body wants the height for itself (a video fills it); a scroll view
      // would hand it unbounded height and nothing to fill.
      scrollable: false,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: CameraImageView(url: url),
      ),
    );
  }
}

/// The inline HLS live-view host — a real `<video>` playing the stream in the
/// tile, via [CameraHlsView] (hls.js on most browsers, native HLS on Safari).
///
/// Only ever built on web and only when [cameraHlsInlineSupported] is true (the
/// tab's [cameraBodyKind] gate), so there is no off-web branch here the way
/// [_InlineViewCard] carries one: an `hls` tile with no inline story never
/// reaches this widget, it routes to [_DeepLinkCard] instead.
class _InlineHlsCard extends StatelessWidget {
  const _InlineHlsCard({super.key, required this.url});

  final String url;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: CameraHlsView(url: url),
    );
  }
}

/// The deep-link card — a copyable link and nothing else, because there is
/// nothing else true to show for this tile.
///
/// Reaches: `rtsp` always (no browser can play it), and `hls` wherever inline
/// playback is not available — off-web (desktop/mobile), or a web browser with
/// neither hls.js nor native HLS.
///
/// ponytail: inline HLS now ships (see [_InlineHlsCard] / camera_view_web.dart,
/// backed by the vendored `web/vendor/hls-*.min.js`), so `hls` on a capable
/// browser no longer lands here. `rtsp` still has no browser story and stays a
/// deep link; there is nothing to fast-follow for it.
class _DeepLinkCard extends StatelessWidget {
  const _DeepLinkCard({super.key, required this.protocol, required this.url});

  final String protocol;
  final String url;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return DecoratedBox(
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(
              '${_protocolLabel(protocol)} stream',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 4),
            // Capped for the same reason the inline card's note is capped —
            // see that comment.
            Text(
              'Copy the link into a compatible player.',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 6),
            SelectableText(url, maxLines: 1, style: theme.textTheme.labelSmall),
            const SizedBox(height: 6),
            // A vertical stack rather than a `Wrap`: two buttons side by
            // side either fit or wrap to a second row depending on the
            // tile's width, which is exactly the kind of width-dependent
            // height this fixed-height grid cell cannot absorb. Stacked,
            // the height is the same at every width this tile ever renders
            // at.
            OutlinedButton.icon(
              onPressed: () => _copyUrl(context, url),
              icon: const Icon(Icons.copy_outlined, size: 16),
              label: const Text('Copy URL'),
            ),
            const SizedBox(height: 6),
            OutlinedButton.icon(
              onPressed: () => _openUrl(url),
              icon: const Icon(Icons.open_in_new, size: 16),
              label: const Text('Open in player'),
            ),
          ],
        ),
      ),
    );
  }
}

Future<void> _openUrl(String url) async {
  final uri = Uri.tryParse(url);
  if (uri == null) return;
  try {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  } catch (_) {
    // No registered handler for the scheme (an rtsp:// deep-link with no
    // player installed throws rather than returning false). The URL is
    // copyable right beside this button, so a failed open is a no-op — never
    // an uncaught async error surfaced to the user.
  }
}

Future<void> _copyUrl(BuildContext context, String url) async {
  await Clipboard.setData(ClipboardData(text: url));
  if (!context.mounted) return;
  ScaffoldMessenger.of(
    context,
  ).showSnackBar(const SnackBar(content: Text('Camera URL copied')));
}

/// The "Add camera" form — label, protocol, URL. Pops the collected fields
/// back to [CameraMonitoringTab], which does the actual `POST` itself; same
/// division as automations_screen.dart's delete confirmation, where the
/// panel only decides WHAT to do and the caller does it once the panel is
/// already gone.
class _AddCameraPanel extends StatefulWidget {
  const _AddCameraPanel();

  @override
  State<_AddCameraPanel> createState() => _AddCameraPanelState();
}

class _AddCameraPanelState extends State<_AddCameraPanel> {
  final _labelController = TextEditingController();
  final _urlController = TextEditingController();
  String _protocol = CameraMonitoringTab.protocols.first;
  bool _enabled = true;

  @override
  void dispose() {
    _labelController.dispose();
    _urlController.dispose();
    super.dispose();
  }

  void _submit() {
    final label = _labelController.text.trim();
    final url = _urlController.text.trim();
    if (label.isEmpty || url.isEmpty) return;

    Navigator.of(context).pop({
      'label': label,
      'protocol': _protocol,
      'url': url,
      'enabled': _enabled,
    });
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Add camera',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const Key('camera-add-submit-button'),
          onPressed: _submit,
          child: const Text('Add camera'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const Key('camera-add-label-field'),
            controller: _labelController,
            autofocus: true,
            decoration: const InputDecoration(labelText: 'Label'),
          ),
          const SizedBox(height: 14),
          SearchPickerField<String>(
            key: const Key('camera-add-protocol-field'),
            value: _protocol,
            label: 'Protocol',
            options: [
              for (final protocol in CameraMonitoringTab.protocols)
                SearchOption(value: protocol, label: _protocolLabel(protocol)),
            ],
            onChanged: (value) {
              if (value != null) setState(() => _protocol = value);
            },
          ),
          const SizedBox(height: 14),
          TextField(
            key: const Key('camera-add-url-field'),
            controller: _urlController,
            decoration: const InputDecoration(labelText: 'URL'),
            keyboardType: TextInputType.url,
          ),
          const SizedBox(height: 4),
          SwitchListTile(
            key: const Key('camera-add-enabled-field'),
            contentPadding: EdgeInsets.zero,
            title: const Text('Enabled'),
            value: _enabled,
            onChanged: (value) => setState(() => _enabled = value),
          ),
        ],
      ),
    );
  }
}
