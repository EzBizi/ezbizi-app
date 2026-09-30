import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart' as wvf;
import 'package:webview_windows/webview_windows.dart' as wvw;

/// The native half of video_embed.dart. Linux has neither WebView.
bool get videoEmbedSupported => Platform.isWindows || Platform.isAndroid || Platform.isIOS || Platform.isMacOS;

Widget buildVideoEmbed(String url) => Platform.isWindows ? _WindowsVideo(url: url) : _MobileVideo(url: url);

class _WindowsVideo extends StatefulWidget {
  const _WindowsVideo({required this.url});

  final String url;

  @override
  State<_WindowsVideo> createState() => _WindowsVideoState();
}

class _WindowsVideoState extends State<_WindowsVideo> {
  final _controller = wvw.WebviewController();
  bool _ready = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    unawaited(_start());
  }

  Future<void> _start() async {
    try {
      await _controller.initialize();
      await _controller.loadUrl(widget.url);
      if (mounted) setState(() => _ready = true);
    } catch (e) {
      // WebView2 missing or refused: say so rather than show a black box.
      if (mounted) setState(() => _error = 'This computer cannot play the video here (WebView2 unavailable).');
    }
  }

  @override
  void dispose() {
    unawaited(_controller.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) {
      return Center(
        child: Text(
          _error!,
          textAlign: TextAlign.center,
          style: TextStyle(color: Theme.of(context).colorScheme.onInverseSurface),
        ),
      );
    }

    return _ready ? wvw.Webview(_controller) : const Center(child: CircularProgressIndicator());
  }
}

class _MobileVideo extends StatefulWidget {
  const _MobileVideo({required this.url});

  final String url;

  @override
  State<_MobileVideo> createState() => _MobileVideoState();
}

class _MobileVideoState extends State<_MobileVideo> {
  // ponytail: the player's own play button starts it; autoplay would need the
  // Android/WebKit platform params (mediaPlaybackRequiresUserGesture) — add
  // if one extra tap proves annoying.
  late final wvf.WebViewController _controller = wvf.WebViewController()
    ..setJavaScriptMode(wvf.JavaScriptMode.unrestricted)
    ..loadRequest(Uri.parse(widget.url));

  @override
  Widget build(BuildContext context) => wvf.WebViewWidget(controller: _controller);
}
