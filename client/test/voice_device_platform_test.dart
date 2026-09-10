import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

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

  tearDown(() => debugDefaultTargetPlatformOverride = null);

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
  final file = File('lib/screens/phone/voice_view_io.dart').readAsStringSync();

  // COMMENTS STRIPPED, because the docblock quotes the defect it is warning
  // about — `SizedBox(width: 0, height: 0)` is written out up there, and a
  // guard that fires on the explanation forbids explaining.
  final source = file
      .split('\n')
      .where((line) => !line.trimLeft().startsWith('//'))
      .join('\n');

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
