import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:webview_windows/webview_windows.dart' as wvw;

import 'voice_device.dart';

/// The softphone on Windows: the browser SDK, hosted in WebView2.
///
/// ── Why a WebView and not a plugin ───────────────────────────────
///
/// The Twilio Voice SDK is a browser script. There is an Android SDK and an
/// iOS SDK and no Windows one at all, so the only way to carry call audio in a
/// Windows build is to run the browser SDK inside a browser — WebView2, which
/// ships with Windows. This is a port of the arrangement A-1 Tools already
/// runs, which matters: the awkward parts below were found there by somebody
/// watching a real phone fail, not reasoned out here.
///
/// ── The page is served from a virtual host, not a file ───────────
///
/// `getUserMedia` requires a secure context, and `file://` is not one. WebView2
/// maps a folder to a made-up https hostname it intercepts before DNS, so the
/// page is https, nothing reaches the network, and the microphone is available.
///
/// ── The view has to be MOUNTED ───────────────────────────────────
///
/// The microphone prompt is delivered to the `Webview` WIDGET's
/// `permissionRequested` delegate, so a purely headless controller never gets
/// asked and `getUserMedia` fails. [platformView] exists for that: the Dial tab
/// mounts it at zero size. It is not a display surface and nothing is drawn in
/// it — it is there so the permission has somewhere to arrive.
class WindowsVoiceDevice implements VoiceDevice {
  static const _virtualHost = 'ezbizisoftphone.local';
  static const _hostPage = 'softphone_host.html';

  wvw.WebviewController? _controller;
  StreamSubscription<dynamic>? _messages;

  bool _registered = false;
  bool _starting = false;

  final _ready = Completer<void>();

  /// Completed when the hosted page reports back whether it registered.
  ///
  /// Separate from [_ready], which only says the PAGE loaded. Registration is
  /// a round trip after that — the token is posted in, the Twilio SDK opens a
  /// signalling socket, and the page posts 'ready' back. Without waiting for
  /// it, [setup] returned while `registered` was still false and the Dial tab,
  /// which checks it the moment setup returns, drew "This desk is not
  /// connected to the phone network" on every launch. The desk then came up a
  /// second later with nobody re-reading the flag, so the banner stayed until
  /// the page was reloaded — which is why it looked like a network fault.
  Completer<void>? _settled;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  bool get supported => true;

  @override
  bool get registered => _registered;

  String? _registrationError;

  @override
  String? get registrationError => _registrationError;

  /// The WebView2 controller, for the Dial tab to mount. See the note above on
  /// why this cannot stay headless.
  @override
  Object? get platformView => _controller;

  @override
  Future<void> setup(String token) async {
    if (_starting) return;
    _starting = true;

    try {
      final controller = wvw.WebviewController();
      await controller.initialize();
      await controller.setPopupWindowPolicy(wvw.WebviewPopupWindowPolicy.deny);

      final assets = _assetsDir();
      if (assets == null) {
        _registered = false;

        return;
      }

      await controller.addVirtualHostNameMapping(
        _virtualHost,
        assets,
        wvw.WebviewHostResourceAccessKind.allow,
      );

      // Listening BEFORE navigating: the page posts as soon as it loads, and
      // a listener attached afterwards misses the first message — which is the
      // one saying whether the phone came up.
      _messages = controller.webMessage.listen(_receive);
      _controller = controller;

      await controller.loadUrl('https://$_virtualHost/$_hostPage');
      await _ready.future.timeout(
        const Duration(seconds: 15),
        onTimeout: () {},
      );

      _settled = Completer<void>();
      _post({'type': 'setup', 'token': token});

      // Same 10 seconds the web device allows its own register() call, and for
      // the same reason: a signalling socket that never connects leaves a
      // promise that never settles, so the wait has to end itself. Timing out
      // here lands on `registered == false`, which is the honest answer and
      // the one the banner already knows how to say.
      await _settled!.future.timeout(
        const Duration(seconds: 10),
        onTimeout: () {},
      );
    } catch (_) {
      // A WebView2 runtime that is missing or refused to start. The desk has
      // a state for a phone that cannot take a call, and this is it.
      _registered = false;
    }
  }

  void _receive(dynamic raw) {
    Map<String, dynamic> message;
    try {
      message = jsonDecode(raw as String) as Map<String, dynamic>;
    } catch (_) {
      return;
    }

    switch (message['type']) {
      case 'loaded':
        if (!_ready.isCompleted) _ready.complete();
      case 'ready':
        _registered = message['registered'] == true;
        // The bridge sends `message` alongside `registered: false` and this
        // discarded it, so the reason Twilio gave was thrown away one line
        // before the desk started guessing at it.
        final why = (message['message'] ?? '').toString().trim();
        _registrationError = _registered || why.isEmpty ? null : why;
        if (_settled?.isCompleted == false) _settled!.complete();
      case 'incoming':
        onIncoming?.call(
          VoiceIncoming(from: (message['from'] ?? '').toString()),
        );
      case 'ended':
        // Nothing to hold: the Dial tab tracks its own on-call state and the
        // page has already stopped the audio.
        break;
    }
  }

  void _post(Map<String, Object?> message) {
    final controller = _controller;
    if (controller == null) return;

    unawaited(controller.postWebMessage(jsonEncode(message)));
  }

  @override
  Future<void> connect({required String to, String? callerId}) async {
    _post({'type': 'connect', 'to': to, 'callerId': callerId});
  }

  @override
  void hangUp() => _post({'type': 'hangup'});

  @override
  void acceptIncoming() => _post({'type': 'accept'});

  @override
  void declineIncoming() => _post({'type': 'decline'});

  @override
  void dispose() {
    _messages?.cancel();
    _messages = null;
    onIncoming = null;

    final controller = _controller;
    _controller = null;
    if (controller != null) {
      try {
        controller.dispose();
      } catch (_) {
        // Already gone.
      }
    }
  }

  /// Where Flutter puts bundled assets beside the exe on Windows.
  static String? _assetsDir() {
    final exe = Platform.resolvedExecutable;
    if (exe.isEmpty) return null;

    final sep = Platform.pathSeparator;
    final cut = exe.lastIndexOf(sep);
    if (cut < 0) return null;

    return '${exe.substring(0, cut)}${sep}data${sep}flutter_assets'
        '${sep}assets${sep}softphone';
  }
}
