import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart' as wvf;
import 'package:webview_windows/webview_windows.dart' as wvw;
import 'package:window_manager/window_manager.dart';

import '../../core/exit_hooks.dart';

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
  // Released before the engine at exit — see ExitHooks.
  Future<void> Function()? _exitHook;
  bool _ready = false;
  String? _error;

  /// The player's Fullscreen button (Trey, 2026-09-30: "makes it like 1%
  /// bigger"). WebView2 only fills its own box with the video, and the box is
  /// the size the guide gave it. So when the page goes fullscreen, the window
  /// does too and the same WebView is drawn over the whole app until the
  /// player (or Esc) leaves fullscreen.
  StreamSubscription<bool>? _fullscreenSub;
  OverlayEntry? _fullscreen;

  @override
  void initState() {
    super.initState();
    _exitHook = ExitHooks.add(_controller.dispose);
    unawaited(_start());
  }

  Future<void> _start() async {
    try {
      await _controller.initialize();
      _fullscreenSub = _controller.containsFullScreenElementChanged.listen(_setFullscreen);
      await _controller.loadUrl(widget.url);
      if (mounted) setState(() => _ready = true);
    } catch (e) {
      // WebView2 missing or refused: say so rather than show a black box.
      if (mounted) setState(() => _error = 'This computer cannot play the video here (WebView2 unavailable).');
    }
  }

  Future<void> _setFullscreen(bool on) async {
    if (!mounted || on == (_fullscreen != null)) return;

    if (on) {
      _fullscreen = OverlayEntry(
        builder: (context) => ColoredBox(color: Theme.of(context).colorScheme.scrim, child: wvw.Webview(_controller)),
      );
      Overlay.of(context, rootOverlay: true).insert(_fullscreen!);
    } else {
      _fullscreen?.remove();
      _fullscreen = null;
    }
    setState(() {});

    try {
      await windowManager.ensureInitialized();
      await windowManager.setFullScreen(on);
    } catch (_) {
      // The overlay alone still fills the app window; a window that will not
      // go fullscreen is no reason to lose the video.
    }
  }

  @override
  void dispose() {
    unawaited(_fullscreenSub?.cancel());
    if (_fullscreen != null) {
      _fullscreen!.remove();
      unawaited(windowManager.setFullScreen(false).catchError((_) {}));
    }
    ExitHooks.remove(_exitHook);
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

    if (_fullscreen != null) return ColoredBox(color: Theme.of(context).colorScheme.scrim);

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
