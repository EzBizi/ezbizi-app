import 'package:ezbizi_client/screens/web/hours.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Hours of operation, which is stored as a STRING and edited as a grid.
///
/// The value goes into somebody's website footer through `[ezbizi_hours]`, so
/// it stays a plain string and the grid is an input aid over it. That makes
/// format/parse a round trip, and the round trip is the whole risk: a string
/// this file wrote and cannot read back means reopening the editor silently
/// discards what the grid was showing.
void main() {
  const nine = TimeOfDay(hour: 9, minute: 0);
  const five = TimeOfDay(hour: 17, minute: 0);
  const eight = TimeOfDay(hour: 8, minute: 0);
  const one = TimeOfDay(hour: 13, minute: 0);

  DayHours open(TimeOfDay a, TimeOfDay b) =>
      DayHours(closed: false, open: a, close: b);

  group('formatting', () {
    test('collapses consecutive days that share hours', () {
      final week = [
        for (var i = 0; i < 5; i++) open(eight, five),
        open(nine, one),
        const DayHours.shut(),
      ];

      expect(
        formatWeek(week),
        'Mon–Fri 8:00 AM – 5:00 PM · Sat 9:00 AM – 1:00 PM · Sun closed',
      );
    });

    test('does not collapse days that only look adjacent', () {
      final week = [
        open(eight, five),
        const DayHours.shut(),
        open(eight, five),
        open(eight, five),
        open(eight, five),
        const DayHours.shut(),
        const DayHours.shut(),
      ];

      // Mon and Wed share hours but Tue does not, so they stay separate.
      expect(
        formatWeek(week),
        'Mon 8:00 AM – 5:00 PM · Tue closed · Wed–Fri 8:00 AM – 5:00 PM · '
        'Sat–Sun closed',
      );
    });

    test('a week that is entirely one thing is one span', () {
      expect(
        formatWeek(List.filled(7, const DayHours.shut())),
        'Mon–Sun closed',
      );
    });

    test('noon and midnight do not become 0:00', () {
      expect(formatTime(const TimeOfDay(hour: 12, minute: 0)), '12:00 PM');
      expect(formatTime(const TimeOfDay(hour: 0, minute: 30)), '12:30 AM');
    });
  });

  group('round trip', () {
    test('every week this file writes, it can read back', () {
      final weeks = <List<DayHours>>[
        defaultWeek(),
        List.filled(7, const DayHours.shut()),
        [for (var i = 0; i < 7; i++) open(eight, five)],
        [
          open(const TimeOfDay(hour: 0, minute: 0), const TimeOfDay(hour: 12, minute: 0)),
          ...List.filled(6, const DayHours.shut()),
        ],
      ];

      for (final week in weeks) {
        final text = formatWeek(week);
        expect(parseWeek(text), week, reason: 'did not survive: $text');
      }
    });
  });

  group('parsing refuses what it did not write', () {
    test('a sentence a person typed is not a grid', () {
      expect(parseWeek('By appointment, November to March'), isNull);
      expect(parseWeek('Mon-Fri 8-5'), isNull);
      expect(parseWeek(''), isNull);
      expect(parseWeek('   '), isNull);
    });

    test('a week that does not cover every day is refused', () {
      expect(parseWeek('Mon–Fri 8:00 AM – 5:00 PM'), isNull);
    });

    test('a day named twice is refused', () {
      expect(
        parseWeek('Mon–Wed 8:00 AM – 5:00 PM · Wed–Sun closed'),
        isNull,
      );
    });

    test('a backwards span is refused', () {
      expect(parseWeek('Fri–Mon 8:00 AM – 5:00 PM · Sat–Sun closed'), isNull);
    });

    test('an impossible clock time is refused', () {
      expect(
        parseWeek('Mon–Sun 13:00 PM – 5:00 PM'),
        isNull,
        reason: '13 is not a 12-hour clock hour',
      );
      expect(parseWeek('Mon–Sun 8:75 AM – 5:00 PM'), isNull);
    });
  });
}
