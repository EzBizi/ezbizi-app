import 'dart:typed_data';

import 'package:ezbizi_client/core/call_alerts.dart';
import 'package:flutter_test/flutter_test.dart';

/// The ring tone is a tone.
///
/// ── Why this is worth a test of its own ──────────────────────────
///
/// Every behavioural test around this asserts that the desk ASKED for a ring.
/// A generator that produced four seconds of silence would pass all of them,
/// and the failure would reach Jose as "the call still does not ring" after
/// he had been told it was fixed. Existence is not function, which is the
/// mistake this module keeps making.
void main() {
  final bytes = SystemCallAlerts.ringToneBytes;

  test('it is a well-formed 16-bit mono PCM wav', () {
    String at(int i, int n) =>
        String.fromCharCodes(bytes.sublist(i, i + n));

    expect(at(0, 4), 'RIFF');
    expect(at(8, 4), 'WAVE');
    expect(at(12, 4), 'fmt ');
    expect(at(36, 4), 'data');

    final head = ByteData.sublistView(bytes);

    expect(head.getUint16(20, Endian.little), 1, reason: 'not PCM');
    expect(head.getUint16(22, Endian.little), 1, reason: 'not mono');
    expect(head.getUint16(34, Endian.little), 16, reason: 'not 16-bit');

    // The two lengths in the header have to agree with the file, or a player
    // reads past the end or stops early.
    expect(head.getUint32(40, Endian.little), bytes.length - 44);
    expect(head.getUint32(4, Endian.little), bytes.length - 8);
  });

  /// It rings TWICE, and that is what makes it a ringer.
  ///
  /// ── The report ───────────────────────────────────────────────────
  ///
  /// Jose: an inbound call on the web rang "as if I was the one calling".
  /// It did — this was 440 Hz + 480 Hz for two seconds then four of silence,
  /// which is North American RINGBACK, the sound a caller hears while
  /// waiting. Twilio supplies our outbound ringback with `ringTone: 'us'`,
  /// which is the same tone, so the two ends were identical by construction.
  ///
  /// The distinguishing property is the SHAPE, not the pitch: two short
  /// bursts with a gap between them inside one loop. No telephone network
  /// produces that, so it cannot be mistaken for a call you placed — and a
  /// test that only checked "there is a loud part and a quiet part" would
  /// have passed on the ringback it replaced.
  test('it rings twice per loop, which ringback never does', () {
    final rate = ByteData.sublistView(bytes).getUint32(24, Endian.little);
    final samples = Int16List.sublistView(bytes, 44);

    int at(int ms) => (rate * ms) ~/ 1000;

    expect(
      samples.length,
      at(3000),
      reason: 'the cadence is not three seconds, so the loop drifts',
    );

    int loudest(int fromMs, int toMs) {
      var peak = 0;
      for (var i = at(fromMs); i < at(toMs); i++) {
        final v = samples[i].abs();
        if (v > peak) peak = v;
      }

      return peak;
    }

    // Sampled inside each burst, past the 10 ms fade at its edges.
    final first = loudest(50, 350);
    final between = loudest(420, 580);
    final second = loudest(650, 950);
    final rest = loudest(1050, 3000);

    expect(first, greaterThan(3000), reason: 'the first ring is inaudible');
    expect(second, greaterThan(3000), reason: 'the second ring is inaudible');

    expect(
      between,
      0,
      reason: 'no gap between the two rings, so this is one long tone — '
          'which is the ringback it is supposed to differ from',
    );
    expect(rest, 0, reason: 'the rest between rings is not silent');

    // Not clipped: a sine at full scale wraps into a buzz, which is audible
    // and reads as a broken call rather than a ring.
    expect(first, lessThan(32000));
  });

  /// And the pitch moves inside a burst, which is the warble.
  ///
  /// A single steady tone would satisfy the shape test above. The warble is
  /// the other half of what makes this unmistakable, and it is the half that
  /// silently disappears if somebody simplifies the generator back to one
  /// frequency.
  test('the pitch alternates inside a ring', () {
    final rate = ByteData.sublistView(bytes).getUint32(24, Endian.little);
    final samples = Int16List.sublistView(bytes, 44);

    int at(int ms) => (rate * ms) ~/ 1000;

    // Zero crossings are a cheap frequency estimate: a 50 ms window of 660 Hz
    // has measurably fewer than one of 880 Hz.
    int crossings(int fromMs, int toMs) {
      var count = 0;
      for (var i = at(fromMs) + 1; i < at(toMs); i++) {
        if ((samples[i - 1] < 0) != (samples[i] < 0)) count++;
      }

      return count;
    }

    final low = crossings(100, 150);
    final high = crossings(150, 200);

    expect(
      low,
      isNot(closeTo(high, 3)),
      reason: 'both halves of the burst are the same pitch, so there is no '
          'warble and this is a plain beep',
    );
  });

  test('it starts and ends near zero so the loop does not click', () {
    final samples = Int16List.sublistView(bytes, 44);

    // The fade exists for this: a buffer that begins mid-wave clicks once
    // every loop forever, and that reads as a fault in the call.
    expect(samples.first.abs(), lessThan(200));
    expect(samples.last.abs(), lessThan(200));
  });

  test('the silent implementation records what it was asked for', () {
    final alerts = SilentCallAlerts();

    alerts.incoming('+13055550123');
    alerts.stop();

    expect(alerts.rang, ['+13055550123']);
    expect(alerts.stopped, 1);
  });
}
