import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// The desktop softphone must not ring on top of EzBizi's own ringer.
///
/// ── The report ───────────────────────────────────────────────────
///
/// Jose, on the desktop app: "it was ringing twice, with the new ring that u
/// put for fixing web, and the old ringing from ezbizi desktop app."
///
/// Two ringtones for one call. The Twilio SDK rings for an incoming call and
/// [CallAlerts] rings too, and the desktop bridge hands the SDK a vendored
/// `incoming.mp3` so both played. The WEB build already turned the SDK's
/// incoming ring off for exactly this reason; the desktop was never given the
/// same treatment.
///
/// It was audible before and nobody reported it, because two similar tones
/// overlapping sounds like one slightly odd tone. Making ours a distinct
/// double burst is what turned an unnoticed overlap into an obvious one — so
/// this is a defect my ringer exposed rather than one it created.
///
/// ── Why a source check ───────────────────────────────────────────
///
/// `softphone_bridge.js` runs inside a WebView2 on Windows. No suite in this
/// repo executes it: the Dart tests do not load it and the PHP tests have
/// never heard of it. The only gate between an edit here and somebody's ears
/// is a person launching the desktop app on Windows — which is the same
/// position the flowchart gallery was in when a one-word collision killed the
/// whole script and it shipped looking like a rendering choice.
///
/// So this reads the file. It is anchored on the CODE — the guarded call and
/// the toggle it makes — and deliberately not on any sentence, because the
/// docblock above that call discusses ringing at length and a scan aimed at
/// prose cannot fail.
void main() {
  test('the desktop bridge silences the SDK ring and keeps ours', () {
    final source = File(
      'assets/softphone/softphone_bridge.js',
    ).readAsStringSync();

    expect(
      source,
      contains('d.audio.incoming(false)'),
      reason: 'nothing turns the SDK incoming ring off, so the desktop rings '
          'twice for one call',
    );

    // Declared is not called. Without this the helper can sit in the file
    // doing nothing, which is the shape the bug had before it existed at all.
    //
    // Line by line, and the line must not be commented. `contains()` alone
    // passed when I sabotaged this by commenting the call out — the comment
    // still contains the string, so the guard was reading dead code as live
    // code. A source scan cannot tell the two apart unless it is asked to.
    final calls = source
        .split('\n')
        .map((line) => line.trim())
        .where((line) => line.startsWith('silenceOwnRing(device);'));

    expect(
      calls,
      isNotEmpty,
      reason: 'the helper exists but setup() never calls it — or the call is '
          'commented out, which is the same thing to a caller',
    );

    // And ONLY the incoming ring. Outbound ringback on the desktop is carried
    // by <Dial ringTone>, and the disconnect and DTMF tones have nothing in
    // CallAlerts duplicating them — silencing those would trade two ringtones
    // for a phone that gives no feedback at all.
    expect(
      source,
      contains("outgoing: 'sounds/outgoing.mp3'"),
      reason: 'the outbound tone was removed along with the incoming one',
    );
    expect(
      source,
      contains("disconnect: 'sounds/disconnect.mp3'"),
      reason: 'the hang-up tone was removed along with the incoming one',
    );
  });
}
