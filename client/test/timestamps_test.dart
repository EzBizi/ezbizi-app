import 'package:ezbizi_client/core/timestamps.dart';
import 'package:flutter_test/flutter_test.dart';

/// Dates and clocks, written the way the organization asked (plan §6).
///
/// ── Why these exist ──────────────────────────────────────────────
///
/// The owner changed Time format from 12h to 24h and nothing happened. There
/// were no tests on formatStamp at all — it hardcoded `YYYY-MM-DD HH:MM` and
/// four screens called it, so every one of them ignored the setting in exactly
/// the same way and nothing could tell.
///
/// Timestamps are written WITHOUT a zone suffix throughout. `DateTime.parse`
/// reads those as local, so `toLocal()` inside formatStamp is a no-op and these
/// assertions hold on a machine in any zone. A 'Z' here would make the expected
/// hour a property of whoever is running the suite.
void main() {
  const american = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
  const european = DisplayFormats(dateFormat: 'D/M/YYYY', timeFormat: '24h');
  const iso = DisplayFormats(dateFormat: 'YYYY-MM-DD', timeFormat: '24h');

  group('time_format', () {
    test('24h is what the owner set, so 24h is what they get', () {
      // The literal complaint. This is the assertion that was missing.
      expect(
        formatStamp('2026-08-10T17:05:00', '', formats: iso),
        '2026-08-10 17:05',
      );
    });

    test('12h writes an unpadded hour and a suffix', () {
      expect(
        formatStamp('2026-08-10T17:05:00', '', formats: american),
        '8/10/2026 5:05 PM',
      );
    });

    test('midnight is 12 AM, not 0 AM', () {
      // `hour % 12` is 0 at both ends of the day, and both are wrong: the
      // clock face has no zero on it.
      expect(
        formatStamp('2026-08-10T00:30:00', '', formats: american),
        '8/10/2026 12:30 AM',
      );
    });

    test('noon is 12 PM, not 12 AM', () {
      expect(
        formatStamp('2026-08-10T12:00:00', '', formats: american),
        '8/10/2026 12:00 PM',
      );
    });

    test('one minute past the hour keeps its leading zero', () {
      // 5:5 PM is not a time.
      expect(
        formatStamp('2026-08-10T17:05:00', '', formats: american),
        contains('5:05 PM'),
      );
    });
  });

  group('date_format', () {
    test('M/D/YYYY puts the month first and pads neither part', () {
      // The enum string says how wide each field is, and it says one letter.
      expect(
        formatStamp('2026-08-03T09:00:00', '', formats: american),
        startsWith('8/3/2026'),
      );
    });

    test('D/M/YYYY is the same day, the other way round', () {
      // The pair that makes the setting matter: 8/3 and 3/8 are two different
      // days to two different readers, and only the setting says which.
      expect(
        formatStamp('2026-08-03T09:00:00', '', formats: european),
        startsWith('3/8/2026'),
      );
    });

    test('YYYY-MM-DD pads, because the enum string does', () {
      expect(
        formatStamp('2026-08-03T09:00:00', '', formats: iso),
        startsWith('2026-08-03'),
      );
    });
  });

  group('a value this build does not know', () {
    // Reachable for real: the server validates against its own registry, so an
    // option added there before this bundle ships is a value the client has
    // never heard of. It must not crash and must not quietly pick a side.
    const future = DisplayFormats(
      dateFormat: 'DD.MM.YYYY',
      timeFormat: 'decimal',
    );

    test('falls back to the unambiguous forms rather than to the default', () {
      // ISO and 24h, NOT the registry's own defaults. Falling back to
      // 'M/D/YYYY' and '12h' would look like a deliberate answer and hide the
      // mismatch behind a plausible date.
      expect(
        formatStamp('2026-08-03T17:05:00', '', formats: future),
        '2026-08-03 17:05',
      );
    });

    test('says so, rather than absorbing it', () {
      expect(future.dateFormatKnown, isFalse);
      expect(future.timeFormatKnown, isFalse);

      // What the settings screen prints under the row. It names the value, so
      // the reader can tell "the app is behind" from "my choice was ignored".
      final note = DisplayFormats.unsupportedNote('date_format', 'DD.MM.YYYY');

      expect(note, isNotNull);
      expect(note, contains('DD.MM.YYYY'));
      expect(note, contains('YYYY-MM-DD'));
    });

    test('a value it does know produces no note at all', () {
      for (final entry in DisplayFormats.known.entries) {
        for (final option in entry.value) {
          expect(
            DisplayFormats.unsupportedNote(entry.key, option),
            isNull,
            reason: '${entry.key} = $option is in the list this build draws',
          );
        }
      }
    });

    test('a key that is not a display setting is not this function\'s to judge', () {
      // `locale` is a real enum in the registry and nothing here formats from
      // it. Returning a warning would put a red line under a perfectly good
      // row on the settings screen.
      expect(DisplayFormats.unsupportedNote('locale', 'es'), isNull);
    });
  });

  group('nothing to show', () {
    test('null takes the caller\'s wording', () {
      // Never the same word twice: a job with no date reads "Not scheduled",
      // an account that has never signed in reads "never".
      expect(formatStamp(null, 'Not scheduled', formats: iso), 'Not scheduled');
      expect(formatStamp(null, 'never', formats: iso), 'never');
    });

    test('a string that is not a timestamp is not a timestamp', () {
      expect(formatStamp('soon', '—', formats: iso), '—');
    });

    test('a number is not a timestamp either', () {
      // The server sends ISO strings; anything else is a shape change, and
      // guessing at it would print a date nobody sent.
      expect(formatStamp(1786000000, '—', formats: iso), '—');
    });
  });

  group('fromJson', () {
    test('reads what /me sent', () {
      final formats = DisplayFormats.fromJson({
        'date_format': 'D/M/YYYY',
        'time_format': '24h',
      });

      expect(formats, european);
    });

    test('a missing block means a server older than this build', () {
      // Mid-deploy, for a few seconds. ISO for those seconds is better than
      // confidently showing 12-hour times to an organization that set 24.
      expect(DisplayFormats.fromJson(null), DisplayFormats.fallback);
      expect(DisplayFormats.fromJson(const {}), DisplayFormats.fallback);
    });

    test('the control plane states its absence rather than defaulting', () {
      // Staff screens span every tenant (§4.2), so no organization's setting
      // applies. Same values as the fallback, different claim.
      expect(DisplayFormats.platform.dateFormat, 'YYYY-MM-DD');
      expect(DisplayFormats.platform.timeFormat, '24h');
    });
  });

  group('formatWallClock', () {
    // What the settings screen's own time pickers use. They formatted through
    // MediaQuery.alwaysUse24HourFormatOf — the BROWSER's preference — so an
    // owner who set 24h still read "5:00 PM" from the screen where they had
    // just set it.
    test('honours the organization, not the machine', () {
      expect(american.formatWallClock('17:00'), '5:00 PM');
      expect(european.formatWallClock('17:00'), '17:00');
    });

    test('hands back anything it cannot read', () {
      // A stored time is the server's to validate. Swallowing a malformed one
      // into 00:00 would invent an opening time.
      expect(american.formatWallClock('not a time'), 'not a time');
      expect(american.formatWallClock('ab:cd'), 'ab:cd');
    });
  });
}
