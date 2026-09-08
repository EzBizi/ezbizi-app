import 'package:flutter/material.dart';

/// Never actually reached. `VideoEmbed` (video_embed.dart) is only built by
/// `_ScreenMedia` (guide_player.dart) behind a `kIsWeb` check — non-web
/// platforms render their own "Open video" link instead and never
/// construct this widget at all. Present purely so the conditional import
/// in video_embed.dart resolves off-web, the same reason
/// message_html_stub.dart and camera_view_stub.dart exist.
Widget buildVideoEmbed(String url) => const SizedBox.shrink();
