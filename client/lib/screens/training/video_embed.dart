import 'package:flutter/material.dart';

import 'video_embed_stub.dart' if (dart.library.js_interop) 'video_embed_web.dart' as impl;

/// A guide screen's video, embedded — an iframe pointed at the author's own
/// link (task-13 brief: media is link-only, nothing is uploaded).
///
/// No existing screen in this app embeds third-party VIDEO specifically, but
/// two do embed other third-party content the identical way, and this
/// follows their shape exactly rather than inventing a new one:
/// `MessageHtmlView` (screens/email/message_html.dart, an `<iframe
/// srcdoc>` for mail) and `CameraImageView`/`CameraHlsView`
/// (screens/monitoring/camera_view.dart, an `<img>`/`<video>` for a live
/// camera). All three are a `platformViewRegistry` factory behind a
/// conditional import, so `dart:ui_web`/`package:web` compile into the web
/// build only — `flutter build web` is the one gate that would catch a
/// `dart:io`-shaped mistake here, `analyze`/`test` are blind to it.
///
/// Like `CameraImageView`, nothing here falls back on its own: the caller
/// (`_ScreenMedia` in guide_player.dart) only builds this behind its own
/// `kIsWeb` check and renders an "Open video" link everywhere else, because
/// there is no generic embeddable surface for an arbitrary third-party URL
/// off a browser — nothing here may assume one.
class VideoEmbed extends StatelessWidget {
  const VideoEmbed({super.key, required this.url});

  final String url;

  @override
  Widget build(BuildContext context) => impl.buildVideoEmbed(url);
}
