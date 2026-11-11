import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:webview_windows/webview_windows.dart' as wvw;

import '../../core/exit_hooks.dart';
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
class WindowsVoiceDevice implements VoiceDevice, SendsDigits {
  /// A browser in a desktop window — same open socket, same no push.
  @override
  String? get pushPlatform => null;

  static const _virtualHost = 'ezbizisoftphone.local';
  static const _hostPage = 'softphone_host.html';

  wvw.WebviewController? _controller;
  Future<void> Function()? _exitHook;
  StreamSubscription<dynamic>? _messages;

  bool _preparing = false;
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
  void Function()? onRegistrationChanged;

  @override
  void Function()? onTokenExpiring;

  @override
  void Function()? onEnded;

  /// Same as the browser: the desk's own button does the answering.
  @override
  void Function()? onAnswered;

  @override
  void Function(String reason)? onCallFailed;

  @override
  bool get supported => true;

  /// A WebView2 has no call UI of its own, so EzBizi rings.
  @override
  bool get ringsItself => false;

  @override
  bool get registered => _registered;

  String? _registrationError;

  @override
  String? get registrationError => _registrationError;
  // Android's Telecom framework is the only place this can be true; a browser,
  // a WebView and a platform with no SDK all have nothing to enable.
  @override
  bool get needsCallingAccount => false;

  @override
  Future<void> openCallingSettings() async {}

  /// The WebView2 controller, for the Dial tab to mount. See the note above on
  /// why this cannot stay headless.
  @override
  Object? get platformView => _controller;

  @override
  /// Phase one: build the controller and nothing else.
  ///
  /// Split out of [setup] on 3 Sep. [platformView] returns this controller, and
  /// the Dial tab can only mount the widget once it is non-null — so while both
  /// halves lived in one await, the page was already calling `getUserMedia`
  /// with nothing in the tree to answer the permission. Registration therefore
  /// failed on every desktop launch, and the banner blamed the network.
  ///
  /// Nothing here touches the page or the network: no navigation, no token.
  /// Safe to call before the screen has anything to show.
  @override
  Future<void> prepare() async {
    if (_controller != null || _preparing) return;
    _preparing = true;

    try {
      final controller = wvw.WebviewController();
      // Released before the engine at exit — see ExitHooks.
      _exitHook = ExitHooks.add(controller.dispose);
      await controller.initialize();
      await controller.setPopupWindowPolicy(wvw.WebviewPopupWindowPolicy.deny);

      final assets = _assetsDir();
      if (assets == null) {
        _registered = false;
        _registrationError =
            'The softphone files are missing from this installation.';

        return;
      }

      await controller.addVirtualHostNameMapping(
        _virtualHost,
        assets,
        wvw.WebviewHostResourceAccessKind.allow,
      );

      // Listening BEFORE navigating: the page posts as soon as it loads, and a
      // listener attached afterwards misses the first message.
      _messages = controller.webMessage.listen(_receive);
      _controller = controller;
    } catch (_) {
      _registered = false;
    } finally {
      _preparing = false;
    }
  }

  @override
  Future<void> setup(String token) async {
    if (_starting) return;
    _starting = true;

    try {
      // Idempotent, and the fallback for any caller that skips phase one.
      await prepare();
      final controller = _controller;
      if (controller == null) {
        _registered = false;

        return;
      }

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

  /// A call this device started and nothing has ended yet. An error while it
  /// is true is THAT call failing; one outside it is registration's business.
  bool _calling = false;

  @visibleForTesting
  void receive(dynamic raw) => _receive(raw);

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

        // Registration finishes AFTER setup() returns here, so the desk's one
        // sample was always taken too early. Telling it is the only way the
        // banner can stop being wrong.
        onRegistrationChanged?.call();
      case 'incoming':
        onIncoming?.call(
          VoiceIncoming(from: (message['from'] ?? '').toString()),
        );
      case 'tokenExpiring':
        // The bridge's own comment said "Dart owns the token lifecycle; it
        // refetches and sends setup again". This is the line that makes that
        // true; until it existed the message was sent into nothing and a
        // desktop desk went quiet an hour after launch.
        onTokenExpiring?.call();
      case 'error':
        if (!_calling) return;
        _calling = false;
        onCallFailed?.call(
          callFailureSentence(
            (message['message'] ?? '').toString(),
            code: (message['code'] as num?)?.toInt(),
          ),
        );
      case 'ended':
        _calling = false;
        // This used to `break` with a comment saying the Dial tab tracked its
        // own on-call state. That was true when CallTab owned the device; it
        // stopped being true the moment the device moved into a service the
        // whole app reads, and the comment outlived the design it described.
        //
        // The page has already stopped the audio. What was missing is telling
        // anybody, so the banner and `on_call` sat there until a reload.
        onEnded?.call();
    }
  }

  void _post(Map<String, Object?> message) {
    final controller = _controller;
    if (controller == null) return;

    unawaited(controller.postWebMessage(jsonEncode(message)));
  }

  @override
  Future<void> connect({required String to, String? callerId}) async {
    _calling = true;
    _post({'type': 'connect', 'to': to, 'callerId': callerId});
  }

  @override
  void hangUp() => _post({'type': 'hangup'});

  @override
  void sendDigits(String digits) => _post({'type': 'digits', 'digits': digits});

  @override
  void acceptIncoming() => _post({'type': 'accept'});

  @override
  void declineIncoming() => _post({'type': 'decline'});

  @override
  void dispose() {
    _messages?.cancel();
    _messages = null;
    onIncoming = null;
    onRegistrationChanged = null;
    onEnded = null;
    onCallFailed = null;

    final controller = _controller;
    _controller = null;
    ExitHooks.remove(_exitHook);
    _exitHook = null;
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
