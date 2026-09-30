import 'package:flutter/widgets.dart';

/// Neither a browser nor a native build: no player (see video_embed.dart).
bool get videoEmbedSupported => false;

Widget buildVideoEmbed(String url) => const SizedBox.shrink();
