import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// The end-of-call listener must declare NO parameters.
///
/// ── Why this is a source check and not a browser one ─────────────
///
/// The fault it guards is invisible to the type system and to `flutter
/// analyze`: `((JSAny? _) {...}).toJS` compiles, and then throws
/// `NoSuchMethodError: method not found: 'call$0'` the first time JavaScript
/// calls it with no arguments. twilio-voice emits `disconnect` with the call
/// and `cancel` and `reject` with nothing, so the handler worked for the one
/// event that matters least — a customer hanging up — and was dead for the two
/// that mean somebody else answered.
///
/// Catching it where it happens needs a real browser. There is no browser
/// runner in this suite and none in the release gate, so a `@TestOn('browser')`
/// test here would hang rather than fail, which is worse than no test. Reading
/// the source is the check that can actually run, and the shape it pins is the
/// whole fix.
void main() {
  test('the end-of-call handler takes no argument', () {
    final source = File(
      'lib/screens/phone/voice_device_web.dart',
    ).readAsStringSync();

    // From the signature, so the docblock above it — which discusses the
    // broken shape in prose — cannot satisfy or fail this.
    final start = source.indexOf('void _watchForEnd(');
    expect(start, isNot(-1), reason: '_watchForEnd is gone; this test is lost');

    final body = source.substring(start, source.indexOf('\n  }', start));

    expect(
      body,
      contains('        () {'),
      reason: 'the listener passed to call.on() must declare no parameters — '
          'twilio-voice emits cancel and reject with none, and a converted '
          'Dart closure THROWS on a missing argument rather than seeing null',
    );
  });
}
