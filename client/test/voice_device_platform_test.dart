import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:ezbizi_client/screens/phone/voice_device_io.dart' as io;
import 'package:ezbizi_client/screens/phone/voice_device_mobile.dart';
import 'package:ezbizi_client/screens/phone/voice_device_windows.dart';

/// Which engine carries call audio, per platform.
///
/// Twilio ships three different Voice SDKs and none of them covers everything
/// EzBizi runs on, so this is a switch rather than one implementation — and
/// picking wrong is the quietest failure in the module. A device that is
/// merely inert draws a perfect dial pad, registers nothing, and never rings;
/// there is no error, no log line, and nobody to complain because the caller
/// hears ringing and assumes nobody is in.
void main() {
  _bridgeContract();
  _surfaceContract();
  _webRingContract();

  tearDown(() => debugDefaultTargetPlatformOverride = null);

  /// Jose, 2026-09-29: Call did nothing. The page reported the failure and
  /// the device dropped it. The error text is Chromium's own, measured on his
  /// PC with no microphone connected.
  // Audit 11-27: `_starting` was never reset, so every token renewal returned
  // early and the Windows desk stopped ringing about an hour after launch.
  test('a second Windows setup (token renewal) is not swallowed', () async {
    final device = WindowsVoiceDevice();
    await device.setup('first');
    expect(device.starting, isFalse);
    await device.setup('renewed');
    expect(device.starting, isFalse);
  });

  test('a call the Windows page could not start is reported with a reason', () async {
    final device = WindowsVoiceDevice();
    final reasons = <String>[];
    device.onCallFailed = reasons.add;

    // No call placed: a page error is registration's business, not a call's.
    device.receive('{"type":"error","message":"device error"}');
    expect(reasons, isEmpty);

    await device.connect(to: '+13055550100');
    device.receive(
      '{"type":"error","message":"NotFoundError: Requested device not found","code":31402}',
    );

    expect(reasons, ['No microphone found. Connect a headset or microphone, then press Call again.']);
  });

  test('the reasons a call cannot start are sentences somebody can act on', () {
    expect(
      callFailureSentence('NotFoundError: Requested device not found'),
      startsWith('No microphone found'),
    );
    expect(
      callFailureSentence('Permission denied', code: 31401),
      startsWith('EzBizi is not allowed to use the microphone'),
    );
    expect(callFailureSentence('Something else'), 'The call could not start: Something else');
  });

  test('Windows gets the WebView2 device, because Twilio has no SDK for it', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.windows;

    expect(io.create(), isA<WindowsVoiceDevice>());
  });

  test('phones and macOS get the native SDKs', () {
    for (final platform in [
      TargetPlatform.android,
      TargetPlatform.iOS,
      TargetPlatform.macOS,
    ]) {
      debugDefaultTargetPlatformOverride = platform;

      expect(
        io.create(),
        isA<MobileVoiceDevice>(),
        reason: '$platform did not get the native engine',
      );
    }
  });

  /// Linux has neither a Twilio SDK nor WebView2. What matters is that it says
  /// so: `supported` false is what makes the Dial tab ring the person's own
  /// handset instead, and a device that quietly accepted calls it cannot place
  /// would take that fallback away.
  test('a platform with no engine says so rather than pretending', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.linux;

    final device = io.create();

    expect(device.supported, isFalse);
    expect(device.registered, isFalse);
    expect(device.platformView, isNull);
  });

  /// Every engine must answer this, and only Windows has anything to mount.
  test('only Windows asks the screen to mount something', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    expect(io.create().platformView, isNull);

    debugDefaultTargetPlatformOverride = TargetPlatform.windows;

    // Null until setup() builds the controller — but the getter exists, which
    // is what stops the Dial tab from having to know which device it holds.
    expect(() => io.create().platformView, returnsNormally);
  });
}

/// One ringtone on the web, and it is EzBizi's.
///
/// ── Why this is read off the file as well ────────────────────────
///
/// `voice_device_web.dart` is `dart:js_interop` throughout. It compiles only
/// for the web and does not exist to a VM test, so nothing here can construct
/// the device, let alone hear it. `flutter build web` proves it compiles; this
/// proves it still says what it must.
///
/// The Voice SDK rings for an incoming call by default, and [CallAlerts] rings
/// too, on every platform. On the web both sound at once — Jose heard the
/// EzBizi tone with what he described as the sound of calling somebody
/// underneath it, and only in a browser, which is the only place that SDK
/// runs.
///
/// ── The outgoing tone: this guard said the opposite until 2026-09-15 ──
///
/// It used to forbid silencing the OUTGOING tone, on the grounds that
/// outbound ringback on a browser leg was silent until `<Dial ringTone>` was
/// added, so switching the SDK tone off might restore a reported bug in a
/// place no test can hear. That was the right instinct and the wrong
/// conclusion, and it took measuring two things to settle rather than
/// arguing:
///
///   1. The silence being feared happened WHILE the SDK tone was present. If
///      that tone had been the audible ringback, outbound would not have been
///      silent — so it was never what `<Dial ringTone>` replaced, and
///      silencing it cannot bring the silence back.
///
///   2. Both clients really do play it. The desktop host page carries a CSP
///      with `media-src`, which is why its sounds are vendored beside it; the
///      web page carries no CSP at all, so the SDK loads its default tone
///      from Twilio's CDN. Neither is blocked, so both were playing a local
///      tone on top of the server's ringback.
///
/// So it is two sounds for one outgoing call, which is what Jose reported
/// after weeks of this: "1 ring for incoming, 1 for outgoing... the same type
/// for all of them." The one that survives is `<Dial ringTone>`, because
/// Twilio generates it on the server and it is therefore identical on every
/// platform by construction rather than by four clients agreeing.
///
/// A guard going red on the correct fix is the expected cost of pinning a
/// duplication. What it is owed is evidence rather than a better argument.
void _webRingContract() {
  final source = _code('lib/screens/phone/voice_device_web.dart');

  test('the SDK does not ring over the top of ours', () {
    expect(
      source,
      contains('_silenceOwnRing(device)'),
      reason: 'the browser rings twice for one incoming call',
    );

    expect(
      source,
      contains('incoming(false.toJS)'),
      reason: 'something is called and nothing is switched off',
    );
  });

  test('and the outgoing tone is silenced too', () {
    expect(
      RegExp(r'outgoing\(\s*false').hasMatch(source),
      isTrue,
      reason: 'the browser plays the SDK outgoing tone on top of the server '
          'ringback from <Dial ringTone>, which is two sounds for one '
          'outgoing call',
    );
  });
}

/// A source file with its comments stripped.
///
/// The docblocks in these files quote the very patterns the guards forbid —
/// `SizedBox(width: 0, height: 0)` is written out in one of them — and a guard
/// that fires on the explanation forbids explaining.
String _code(String path) => File(path)
    .readAsStringSync()
    .split('\n')
    .where((line) => !line.trimLeft().startsWith('//'))
    .join('\n');

/// The size of the surface, which is not a cosmetic choice.
///
/// ── Why this is read off the file too ────────────────────────────
///
/// `buildVoiceDeviceView` only builds the Webview for an INITIALISED
/// `WebviewController`, and there is no WebView2 in a Flutter test to
/// initialise one — the branch that matters cannot be pumped. So this reads
/// the source, exactly as [_bridgeContract] does and for the same reason.
///
/// What it is defending: the surface was `SizedBox(0, 0)`, and
/// `webview_windows` makes the surface visible unconditionally
/// (`webview.cc:140`) while applying `put_Bounds` only from `SetSurfaceSize`,
/// which early-returns unless BOTH dimensions exceed zero (`webview.cc:381`,
/// read in the pinned 0.4.0). Zero therefore means visible-and-never-bounded:
/// a top-level window keeping WebView2's default rect, invisible, with no
/// `WS_EX_TRANSPARENT` — so it hit-tests and eats every click inside it.
///
/// Bar lost the left half of an ultrawide to it. On an ordinary monitor the
/// unbounded rect lands off-screen, which is why it shipped: the defect was
/// always there and the display decided whether it hurt. That is precisely
/// the shape no reviewer and no widget test catches, and why the one-pixel
/// box needs something that fails rather than a comment asking nicely.
void _surfaceContract() {
  final source = _code('lib/screens/phone/voice_view_io.dart');

  test('the WebView2 surface is never zero-sized', () {
    expect(
      RegExp(r'width:\s*0\s*[,)]').hasMatch(source),
      isFalse,
      reason: 'a zero-width surface is never bounded, so it keeps WebView2 '
          "default rect as a click-eating window over the user's desktop",
    );

    expect(
      RegExp(r'height:\s*0\s*[,)]').hasMatch(source),
      isFalse,
      reason: 'the same, for height — SetSurfaceSize requires BOTH',
    );
  });

  /// The control. Without it, deleting the whole surface passes the two
  /// assertions above perfectly, and the phone registers but hears nobody.
  test('and it is still mounted, hidden rather than removed', () {
    expect(source, contains('wvw.Webview('));
    expect(
      source,
      contains('Opacity('),
      reason: 'one visible pixel in the corner of every desk in the company',
    );
    expect(
      source,
      isNot(contains('Offstage(')),
      reason: 'Offstage unmounts the platform view: no microphone prompt, and '
          'a phone that registers and cannot hear anybody',
    );
  });
}

/// The half of the Windows softphone that is JavaScript.
///
/// It cannot be exercised from a Flutter test — there is no WebView2 here —
/// and its failure is silent by construction: the page loads, reports itself
/// loaded, and then ignores every message the app sends it. Dart waits ten
/// seconds, gives up, and says the network is blocking the connection.
///
/// So this reads the file. It is a crude check and it is the one that would
/// have caught the bug that took the desktop phone out for days.
void _bridgeContract() {
  // Relative: `flutter test` runs from the package root.
  final bridge = File('assets/softphone/softphone_bridge.js').readAsStringSync();

  test('the bridge listens on chrome.webview, not on window', () {
    expect(
      bridge,
      contains('window.chrome.webview.addEventListener'),
      reason: 'host messages arrive on chrome.webview; a window listener '
          'never fires and the page silently ignores the token',
    );

    expect(
      RegExp(r"^\s*window\.addEventListener\(\s*'message'", multiLine: true)
          .hasMatch(bridge),
      isFalse,
      reason: 'this is the browser frame-to-frame channel, not the WebView2 '
          'host channel — it was here for days and heard nothing',
    );
  });

  test('the bridge accepts an already-parsed message object', () {
    // webview_windows posts with PostWebMessageAsJson (webview.cc:714), so
    // WebView2 hands the page an object. A bare JSON.parse throws on it, and
    // the throw was being swallowed — the same silence by a second route.
    expect(
      bridge,
      contains("typeof raw === 'string' ? JSON.parse(raw) : raw"),
      reason: 'event.data is an object, not a string',
    );
  });

  test('the listener is attached before the page says it loaded', () {
    // Dart gates the token on 'loaded'. Announcing it first is a token that
    // arrives at a page with nothing listening, dropped with no retry.
    expect(
      bridge.indexOf('window.chrome.webview.addEventListener'),
      lessThan(bridge.indexOf("send({ type: 'loaded' })")),
    );
  });
}
