import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart' as wvf;
import 'package:webview_windows/webview_windows.dart' as wvw;

import '../../core/exit_hooks.dart';

/// HTML mail in a WebView: WebView2 on Windows, webview_flutter on Android,
/// iOS and macOS. Linux has neither.
///
/// Received mail too since Jose 11-12 ("lacking the proper formatting": the
/// block body drops table layouts, so Google's call notices drew only their
/// logos). It is safe to: the server has purified it and its page carries a
/// Content-Security-Policy that lets nothing run or load but pictures,
/// styles and fonts; scripts are off on phones as well. A link opens in the
/// browser, never inside the pane.
Widget buildMessageHtml(String html, {bool ownHtml = false}) {
  // Widget tests have no WebView; the platform channel would never answer.
  if (Platform.environment.containsKey('FLUTTER_TEST')) {
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

  StreamSubscription<String>? _urls;

  Future<void> _start() async {
    try {
      await _controller.initialize();
      // A link the mail opens in a new window comes through [url] as well.
      await _controller.setPopupWindowPolicy(
        wvw.WebviewPopupWindowPolicy.sameWindow,
      );
      _urls = _controller.url.listen(_leave);
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

  /// A link was followed: open it in the browser and put the mail back.
  Future<void> _leave(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null ||
        !(uri.isScheme('http') ||
            uri.isScheme('https') ||
            uri.isScheme('mailto') ||
            uri.isScheme('tel'))) {
      return;
    }
    unawaited(launchUrl(uri, mode: LaunchMode.externalApplication));
    await _controller.loadStringContent(widget.html);
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
    unawaited(_urls?.cancel());
    ExitHooks.remove(_exitHook);
    unawaited(_controller.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) {
      return Center(child: Text(_error!, textAlign: TextAlign.center));
    }

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
    ..setNavigationDelegate(
      wvf.NavigationDelegate(
        onNavigationRequest: (request) {
          // The mail itself loads as about:blank / data:; anything else is a
          // link the reader tapped, and it opens in the browser.
          final uri = Uri.tryParse(request.url);
          if (uri == null || uri.isScheme('about') || uri.isScheme('data')) {
            return wvf.NavigationDecision.navigate;
          }
          unawaited(launchUrl(uri, mode: LaunchMode.externalApplication));
          return wvf.NavigationDecision.prevent;
        },
      ),
    )
    ..loadHtmlString(widget.html);

  @override
  void didUpdateWidget(_MobileHtml old) {
    super.didUpdateWidget(old);
    if (old.html != widget.html) {
      unawaited(_controller.loadHtmlString(widget.html));
    }
  }

  @override
  Widget build(BuildContext context) =>
      wvf.WebViewWidget(controller: _controller);
}
