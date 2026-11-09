import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart' as wvf;
import 'package:webview_windows/webview_windows.dart' as wvw;

import '../../core/exit_hooks.dart';

/// Received mail draws nothing here (the block body is used). EzBizi's own
/// HTML gets a WebView with scripts off: WebView2 on Windows, webview_flutter
/// on Android, iOS and macOS. Linux has neither.
Widget buildMessageHtml(String html, {bool ownHtml = false}) {
  // Widget tests have no WebView; the platform channel would never answer.
  if (!ownHtml || Platform.environment.containsKey('FLUTTER_TEST')) {
    return const SizedBox.expand();
  }
  if (Platform.isWindows) return _WindowsHtml(html: html);
  if (Platform.isAndroid || Platform.isIOS || Platform.isMacOS) {
    return _MobileHtml(html: html);
  }
  return const Center(child: Text('This computer cannot show the preview.'));
}

class _WindowsHtml extends StatefulWidget {
  const _WindowsHtml({required this.html});

  final String html;

  @override
  State<_WindowsHtml> createState() => _WindowsHtmlState();
}

class _WindowsHtmlState extends State<_WindowsHtml> {
  final _controller = wvw.WebviewController();
  // Released before the engine at exit — see ExitHooks.
  Future<void> Function()? _exitHook;
  bool _ready = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _exitHook = ExitHooks.add(_controller.dispose);
    unawaited(_start());
  }

  Future<void> _start() async {
    try {
      await _controller.initialize();
      await _controller.loadStringContent(widget.html);
      if (mounted) setState(() => _ready = true);
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'This computer cannot show the preview (WebView2 unavailable).',
        );
      }
    }
  }

  @override
  void didUpdateWidget(_WindowsHtml old) {
    super.didUpdateWidget(old);
    if (_ready && old.html != widget.html) {
      unawaited(_controller.loadStringContent(widget.html));
    }
  }

  @override
  void dispose() {
    ExitHooks.remove(_exitHook);
    unawaited(_controller.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null)
      return Center(child: Text(_error!, textAlign: TextAlign.center));

    return _ready
        ? wvw.Webview(_controller)
        : const Center(child: CircularProgressIndicator());
  }
}

class _MobileHtml extends StatefulWidget {
  const _MobileHtml({required this.html});

  final String html;

  @override
  State<_MobileHtml> createState() => _MobileHtmlState();
}

class _MobileHtmlState extends State<_MobileHtml> {
  late final wvf.WebViewController _controller = wvf.WebViewController()
    ..setJavaScriptMode(wvf.JavaScriptMode.disabled)
    ..loadHtmlString(widget.html);

  @override
  void didUpdateWidget(_MobileHtml old) {
    super.didUpdateWidget(old);
    if (old.html != widget.html)
      unawaited(_controller.loadHtmlString(widget.html));
  }

  @override
  Widget build(BuildContext context) =>
      wvf.WebViewWidget(controller: _controller);
}
