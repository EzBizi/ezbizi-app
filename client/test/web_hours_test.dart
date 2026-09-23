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

  /// The shape of the grid, which is what "feels like its floating" was about.
  ///
  /// Nothing here measured layout before: this file is a format/parse round
  /// trip, and `web_management_test.dart` asserts the grid EXISTS rather than
  /// how it sits. So the defect Jose reported was invisible to the suite in
  /// both directions — it could neither be caught nor be proved fixed.
  ///
  /// The cause was two row heights. An OPEN day drew two `TextButton`s, each
  /// carrying Material's 48dp minimum tap target; a CLOSED day drew one line
  /// of text at roughly half that. Seven rows alternating between two heights,
  /// with no rule between them, is a column with no grid for the eye.
  group('the grid sits still', () {
    Future<void> pump(WidgetTester tester, {required double width}) async {
      tester.view.physicalSize = Size(width, 1400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: HoursField(
                controller: TextEditingController(
                  text: 'Mon-Fri 8:00 AM - 5:00 PM',
                ),
                enabled: true,
                label: 'Hours of operation',
                shortcode: '[ezbizi_hours]',
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('an open day and a closed day are the same height', (
      tester,
    ) async {
      await pump(tester, width: 900);

      // Monday is open, Sunday is closed — the two shapes that used to differ.
      final monday = tester.getSize(
        find.ancestor(
          of: find.text('Monday'),
          matching: find.byType(Container),
        ).first,
      );
      final sunday = tester.getSize(
        find.ancestor(
          of: find.text('Sunday'),
          matching: find.byType(Container),
        ).first,
      );

      expect(
        monday.height,
        sunday.height,
        reason: 'an open day and a closed day drew at different heights, '
            'which is what made the column look unanchored',
      );
    });

    /*
     * The same comparison at phone width, which is where it can come back.
     *
     * Copied from Phone's client/test/phone_hours_pane_test.dart (418ad958),
     * which runs its comparison at 1280 AND 375. Mine ran at 900 only, and 900
     * is the width where nothing wraps — so it could only ever have proved the
     * easy half.
     *
     * 375 is the interesting one because the times are in a `Wrap`. When the
     * Wrap takes a second line the open row grows, and a MINIMUM height floors
     * a row without capping it — so the two states can diverge again at exactly
     * the width where the original overflow lived, and the desk-width test
     * would still be green.
     */
    testWidgets('an open day and a closed day match at phone width too', (
      tester,
    ) async {
      await pump(tester, width: 375);

      final monday = tester.getSize(
        find.ancestor(
          of: find.text('Monday'),
          matching: find.byType(Container),
        ).first,
      );
      final sunday = tester.getSize(
        find.ancestor(
          of: find.text('Sunday'),
          matching: find.byType(Container),
        ).first,
      );

      expect(
        monday.height,
        sunday.height,
        reason: 'the rows match at 900 but not at 375 — the Wrap takes a '
            'second line and the open day grows, so the defect Jose reported '
            'is still there on a phone',
      );
    });

    /*
     * ── The half the height comparison above cannot see ──
     *
     * The defect Jose originally reported at 375 WAS an overflow: the plain
     * Row ran 78 pixels past the right edge, into space nobody can see or tap.
     * Every test around it compares HEIGHTS, and a row that overflows
     * sideways is exactly as tall as one that fits — `getSize` returns what
     * the box was GIVEN, not what it drew.
     *
     * So the guard for the original defect could not fail on the original
     * defect. It would have caught the Wrap's second line, which is the bug
     * the fix introduced, and been green for the bug the fix was for.
     *
     * A Flutter overflow fails nothing by itself: it paints yellow stripes and
     * carries on. The only way to see one is to take the error over.
     *
     * Marketing found this hole after I closed the same one on the widget
     * cards (f38b29a3) and checked their own suites rather than agreeing with
     * me — theirs had no `FlutterError.onError` anywhere either.
     */
    testWidgets('nothing runs off the edge at phone width', (tester) async {
      final overflows = <String>[];
      final previous = FlutterError.onError;
      FlutterError.onError = (details) {
        if ('${details.exception}'.contains('overflowed')) {
          overflows.add('${details.exception}');

          return;
        }

        previous?.call(details);
      };
      addTearDown(() => FlutterError.onError = previous);

      await pump(tester, width: 375);

      expect(
        overflows,
        isEmpty,
        reason: 'the hours grid ran off the edge at 375 — the defect Jose '
            'reported on 2026-09-08, which every height comparison here is '
            'blind to',
      );

      // The control, in the same test: heights still equal. Without it, a
      // "fix" that stopped the overflow by letting the open row wrap would
      // pass this and quietly restore the other half of the defect.
      final monday = tester.getSize(
        find.ancestor(
          of: find.text('Monday'),
          matching: find.byType(Container),
        ).first,
      );
      final sunday = tester.getSize(
        find.ancestor(
          of: find.text('Sunday'),
          matching: find.byType(Container),
        ).first,
      );

      expect(monday.height, sunday.height);
    });

    testWidgets('every one of the seven rows is the same height', (
      tester,
    ) async {
      await pump(tester, width: 900);

      final heights = <double>{
        for (final day in weekdayLongNames)
          tester.getSize(
            find.ancestor(
              of: find.text(day),
              matching: find.byType(Container),
            ).first,
          ).height,
      };

      expect(
        heights.length,
        1,
        reason: 'the seven rows drew at ${heights.length} different heights',
      );
    });

    /// The regression this change introduced and the suite caught.
    ///
    /// The first attempt used a fixed height and a `Row`. A Row cannot wrap, so
    /// at phone width the two times and their separator ran 78 pixels past the
    /// edge — content nobody can see or tap. A minimum height plus a `Wrap` is
    /// what makes narrow safe, and this is the assertion that says so.
    testWidgets('at 375 the times wrap instead of running off the edge', (
      tester,
    ) async {
      await pump(tester, width: 375);

      expect(tester.takeException(), isNull);
    });
  });

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
