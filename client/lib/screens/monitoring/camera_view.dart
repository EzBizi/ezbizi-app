import 'package:flutter/material.dart';

import 'camera_view_stub.dart'
    if (dart.library.js_interop) 'camera_view_web.dart' as impl;

/// A camera's live frame, inline — an `mjpeg` stream or a `snapshot` still,
/// rendered as a DOM `<img>` pointed straight at the camera's own URL.
///
/// Same shape as `MessageHtmlView` (message_html.dart): the real work lives
/// behind a conditional import so `dart:ui_web` and `package:web` compile
/// into the web build only. Everywhere else this is a no-op — but unlike
/// `MessageHtmlView`, nothing here actually falls back to it, because the
/// caller (`_InlineViewCard` in camera_monitoring_tab.dart) only constructs
/// this widget behind its own `kIsWeb` check and shows a URL card itself on
/// every other platform. It stays a conditional import anyway, for the same
/// reason `kIsWeb` alone would not be enough: `kIsWeb` is a runtime check,
/// and without the conditional import `dart:ui_web`/`package:web` would
/// still need to compile for non-web targets, which they cannot.
class CameraImageView extends StatelessWidget {
  const CameraImageView({
    super.key,
    required this.url,
    this.fit = BoxFit.contain,
    this.onAspect,
  });

  final String url;

  /// [BoxFit.contain] shows the whole frame; [BoxFit.cover] fills the box and
  /// crops what does not fit (the full-screen view's Fill).
  final BoxFit fit;

  /// The stream's width / height, once its first frame says so — what lets a
  /// host size its box to the feed instead of drawing bands around it.
  final ValueChanged<double>? onAspect;

  @override
  Widget build(BuildContext context) =>
      impl.buildCameraImage(url, fit: fit, onAspect: onAspect);
}

/// A camera's live HLS stream, inline — a DOM `<video>` fed by the vendored
/// hls.js, or by the browser's own HLS on Safari. Same web/stub split as
/// [CameraImageView], just a `<video>` element instead of an `<img>`. The
/// caller only builds this once [cameraHlsInlineSupported] is true and only on
/// web (`kIsWeb`); off-web the stub half makes it a no-op.
class CameraHlsView extends StatelessWidget {
  const CameraHlsView({super.key, required this.url});

  final String url;

  @override
  Widget build(BuildContext context) => impl.buildCameraHls(url);
}

/// Whether an HLS stream can play inline in this browser — hls.js loaded and
/// runnable here, or native HLS (Safari/iOS). Always false off-web, where the
/// stub half answers. The tab uses this to decide between the inline
/// [CameraHlsView] and the copy-URL deep-link card.
bool cameraHlsInlineSupported() => impl.hlsInlineSupported();
