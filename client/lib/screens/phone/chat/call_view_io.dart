import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart' as wvf;
import 'package:webview_flutter_android/webview_flutter_android.dart' as wva;
import 'package:webview_flutter_wkwebview/webview_flutter_wkwebview.dart'
    as wvk;
import 'package:webview_windows/webview_windows.dart' as wvw;

final _hangUps = <Key, Future<void> Function()>{};

Widget buildChatCallView({
  required String url,
  required void Function(Map<String, dynamic> event) onEvent,
  Key? key,
}) {
  if (Platform.isWindows) {
    return _WindowsCall(key: key, url: url, onEvent: onEvent);
  }
  if (Platform.isAndroid || Platform.isIOS || Platform.isMacOS) {
    return _NativeCall(key: key, url: url, onEvent: onEvent);
  }
  return const Center(
    child: Padding(
      padding: EdgeInsets.all(24),
      child: Text('Calls are not available in the Linux app yet.'),
    ),
  );
}

void hangUp(Key key) => unawaited(_hangUps[key]?.call());

void _decode(Object? raw, void Function(Map<String, dynamic>) onEvent) {
  try {
    final m = jsonDecode('$raw');
    if (m is Map<String, dynamic>) onEvent(m);
  } catch (_) {}
}

/// WebView2, as the softphone: camera and microphone are asked of the mounted
/// widget's delegate, and allowed for this page only.
class _WindowsCall extends StatefulWidget {
  const _WindowsCall({super.key, required this.url, required this.onEvent});

  final String url;
  final void Function(Map<String, dynamic>) onEvent;

  @override
  State<_WindowsCall> createState() => _WindowsCallState();
}

class _WindowsCallState extends State<_WindowsCall> {
  final _controller = wvw.WebviewController();
  StreamSubscription<dynamic>? _sub;
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    unawaited(_start());
    if (widget.key != null) {
      _hangUps[widget.key!] = () =>
          _controller.executeScript('window.ezbiziHangUp && ezbiziHangUp()');
    }
  }

  Future<void> _start() async {
    await _controller.initialize();
    await _controller.setPopupWindowPolicy(wvw.WebviewPopupWindowPolicy.deny);
    _sub = _controller.webMessage.listen((m) => _decode(m, widget.onEvent));
    await _controller.loadUrl(widget.url);
    if (mounted) setState(() => _ready = true);
  }

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    _hangUps.remove(widget.key);
    unawaited(_controller.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => !_ready
      ? const Center(child: Text('Starting the call…'))
      : wvw.Webview(
          _controller,
          permissionRequested: (url, kind, userInitiated) async =>
              kind == wvw.WebviewPermissionKind.microphone ||
                  kind == wvw.WebviewPermissionKind.camera
              ? wvw.WebviewPermissionDecision.allow
              : wvw.WebviewPermissionDecision.deny,
        );
}

/// The system web view on Android, iOS and macOS. Media plays without a tap
/// inside the page (the Answer or Call press in the app was the gesture), and
/// the page's camera/microphone request is granted for this view only; the OS
/// still asks the person once for the app.
class _NativeCall extends StatefulWidget {
  const _NativeCall({super.key, required this.url, required this.onEvent});

  final String url;
  final void Function(Map<String, dynamic>) onEvent;

  @override
  State<_NativeCall> createState() => _NativeCallState();
}

class _NativeCallState extends State<_NativeCall> {
  late final wvf.WebViewController _controller = _make();

  wvf.WebViewController _make() {
    final params = wvf.WebViewPlatform.instance is wvk.WebKitWebViewPlatform
        ? wvk.WebKitWebViewControllerCreationParams(
            allowsInlineMediaPlayback: true,
            mediaTypesRequiringUserAction: const {},
          )
        : const wvf.PlatformWebViewControllerCreationParams();
    final controller = wvf.WebViewController.fromPlatformCreationParams(
      params,
      onPermissionRequest: (request) => request.grant(),
    );
    if (controller.platform case final wva.AndroidWebViewController android) {
      unawaited(android.setMediaPlaybackRequiresUserGesture(false));
    }
    controller
      ..setJavaScriptMode(wvf.JavaScriptMode.unrestricted)
      ..addJavaScriptChannel(
        'EzBiziCall',
        onMessageReceived: (m) => _decode(m.message, widget.onEvent),
      )
      ..loadRequest(Uri.parse(widget.url));
    return controller;
  }

  @override
  void initState() {
    super.initState();
    if (widget.key != null) {
      _hangUps[widget.key!] = () =>
          _controller.runJavaScript('window.ezbiziHangUp && ezbiziHangUp()');
    }
  }

  @override
  void dispose() {
    _hangUps.remove(widget.key);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) =>
      wvf.WebViewWidget(controller: _controller);
}
