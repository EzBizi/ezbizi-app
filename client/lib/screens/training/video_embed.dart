import 'package:flutter/material.dart';

import 'video_embed_stub.dart'
    if (dart.library.js_interop) 'video_embed_web.dart'
    if (dart.library.io) 'video_embed_native.dart'
    as impl;

/// A training video, playing inside the app — as A1 Tools played it.
///
/// [url] is the EzBizi page that holds the player (`GET
/// /training/video/{platform}/{id}`, `embed_url` on a video block). A1 Tools
/// found its WebView refused a bare `player.vimeo.com` page, and played the
/// video inside an iframe on a real domain.
///
/// Web: an `<iframe>`. Windows: WebView2 (`webview_windows`, already how the
/// phone runs). Android, iOS, macOS: `webview_flutter`. Anywhere else
/// [supported] is false and the caller offers the link instead.
class VideoEmbed extends StatelessWidget {
  const VideoEmbed({super.key, required this.url});

  final String url;

  static bool get supported => impl.videoEmbedSupported;

  @override
  Widget build(BuildContext context) => impl.buildVideoEmbed(url);
}
