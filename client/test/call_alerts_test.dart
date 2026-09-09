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

  test('two seconds ring and four are silent', () {
    final rate = ByteData.sublistView(bytes).getUint32(24, Endian.little);
    final samples = Int16List.sublistView(bytes, 44);

    expect(
      samples.length,
      rate * 6,
      reason: 'the cadence is not six seconds, so the loop drifts',
    );

    int loudest(int from, int to) {
      var peak = 0;
      for (var i = from; i < to; i++) {
        final v = samples[i].abs();
        if (v > peak) peak = v;
      }

      return peak;
    }

    // Sampled inside the tone, past the 10ms fade at its start.
    final ringing = loudest(rate ~/ 2, rate * 2 - rate ~/ 2);
    final quiet = loudest(rate * 2 + rate ~/ 2, rate * 6);

    expect(ringing, greaterThan(3000), reason: 'the "ring" is inaudible');
    expect(quiet, 0, reason: 'the gap between rings is not silent');

    // And not clipped: two summed sines at full scale wrap around into a
    // buzz, which is audible and reads as a broken call rather than a ring.
    expect(ringing, lessThan(32000));
  });

  test('it starts and ends near zero so the loop does not click', () {
    final samples = Int16List.sublistView(bytes, 44);

    // The fade exists for this: a buffer that begins mid-wave clicks once
    // every six seconds forever, and that reads as a fault in the call.
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
