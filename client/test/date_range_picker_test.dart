import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/reports/report_dates.dart';
import 'package:ezbizi_client/widgets/date_range_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The dates a report covers, in words (UI queue 57; Jose, 2026-10-06: "Week
/// is confussing what week is it ? Etc i should see the range").
///
/// Today is Tuesday, October 6, 2026 throughout. Weeks run Monday to Sunday,
/// as the server counts them.
void main() {
  // UI-UX, 2026-10-30: a phone shows the dates whole, without the period's
  // name; "Custom" only where it fits.
  Future<void> phoneBar(
    WidgetTester tester,
    DateSpan span,
    double width,
  ) async {
    tester.view.physicalSize = Size(width, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Row(
            children: [
              Expanded(
                child: DateRangeBar(
                  span: span,
                  today: DateTime(2026, 10, 30),
                  formats: DisplayFormats.fallback,
                  open: false,
                  onToggle: () {},
                  expand: true,
                  phone: true,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  testWidgets('a phone drops the period name and keeps the dates whole', (
    tester,
  ) async {
    await phoneBar(
      tester,
      DateSpan(DateTime(2026, 10, 24), DateTime(2026, 10, 30)),
      390,
    );
    expect(find.byKey(const Key('date-range-title')), findsNothing);
    final words = tester.widget<Text>(
      find.byKey(const Key('date-range-words')),
    );
    expect(words.overflow, isNot(TextOverflow.ellipsis));

    // A custom range on a narrow phone: the dates alone.
    await phoneBar(
      tester,
      DateSpan(DateTime(2026, 10, 2), DateTime(2026, 10, 9)),
      390,
    );
    expect(find.byKey(const Key('date-range-title')), findsNothing);

    // Room enough: "Custom" comes back.
    await phoneBar(
      tester,
      DateSpan(DateTime(2026, 10, 2), DateTime(2026, 10, 9)),
      500,
    );
    expect(find.text('Custom'), findsOneWidget);
  });

  final today = DateTime(2026, 10, 6, 15, 30);
  const us = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
  const dayFirst = DisplayFormats(dateFormat: 'D/M/YYYY', timeFormat: '24h');

  DateSpan preset(String key) =>
      RangePreset.standard.firstWhere((p) => p.key == key).span(today);

  group('the quick choices mean these dates', () {
    test(
      'a running week is Monday to today, and last week Monday to Sunday',
      () {
        expect(
          preset('this_week'),
          DateSpan(DateTime(2026, 10, 5), DateTime(2026, 10, 6)),
        );
        expect(
          preset('last_week'),
          DateSpan(DateTime(2026, 9, 28), DateTime(2026, 10, 4)),
        );
      },
    );

    test('months are calendar months, the running one to today', () {
      expect(
        preset('this_month'),
        DateSpan(DateTime(2026, 10, 1), DateTime(2026, 10, 6)),
      );
      expect(
        preset('last_month'),
        DateSpan(DateTime(2026, 9, 1), DateTime(2026, 9, 30)),
      );
    });

    test('the last N days include today', () {
      expect(
        preset('last_7'),
        DateSpan(DateTime(2026, 9, 30), DateTime(2026, 10, 6)),
      );
      expect(preset('last_7').days, 7);
      expect(preset('last_30').days, 30);
    });

    test('dates that are a quick choice are called by its name', () {
      expect(
        spanTitle(
          DateSpan(DateTime(2026, 9, 28), DateTime(2026, 10, 4)),
          today,
        ),
        'Last week',
      );
      expect(
        spanTitle(
          DateSpan(DateTime(2026, 9, 14), DateTime(2026, 9, 25)),
          today,
        ),
        'Custom',
      );
      expect(
        spanTitle(DateSpan.single(DateTime(2026, 9, 14)), today),
        'One day',
      );
    });
  });

  group('the dates in words', () {
    test(
      'a week or less names its weekdays, and a running one ends "today"',
      () {
        expect(spanWords(preset('today'), today, us), 'Tue, Oct 6');
        expect(spanWords(preset('this_week'), today, us), 'Mon, Oct 5 – today');
        expect(
          spanWords(preset('last_week'), today, us),
          'Mon, Sep 28 – Sun, Oct 4',
        );
      },
    );

    test('longer than a week drops the weekdays', () {
      expect(spanWords(preset('last_month'), today, us), 'Sep 1 – Sep 30');
      expect(spanWords(preset('last_30'), today, us), 'Sep 7 – today');
    });

    test('the day comes first where the organization writes it first', () {
      expect(
        spanWords(preset('this_week'), today, dayFirst),
        'Mon, 5 Oct – today',
      );
    });

    test('the year is said only when it is not this one', () {
      final span = DateSpan(DateTime(2025, 12, 29), DateTime(2026, 1, 4));
      expect(spanWords(span, today, us), 'Mon, Dec 29, 2025 – Sun, Jan 4');
    });

    test('never the server\'s own form', () {
      expect(
        spanWords(preset('last_week'), today, us),
        isNot(contains('2026-')),
      );
    });
  });

  group('the picker', () {
    Future<List<DateSpan>> pump(WidgetTester tester, DateSpan initial) async {
      final applied = <DateSpan>[];
      tester.view.physicalSize = const Size(1400, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: SingleChildScrollView(
              child: DateRangePanel(
                initial: initial,
                today: today,
                formats: us,
                onApply: applied.add,
                onCancel: () {},
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      return applied;
    }

    Finder day(String key) => find.byKey(ValueKey('date-day-$key'));

    testWidgets('two clicks make a range, whichever comes first', (
      tester,
    ) async {
      final applied = await pump(tester, preset('today'));

      await tester.tap(day('2026-09-25'));
      await tester.pump();
      await tester.tap(day('2026-09-14'));
      await tester.pump();

      expect(
        find.text('12 days · Sep 14 – Sep 25', findRichText: true),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('date-range-apply')));
      expect(
        applied.single,
        DateSpan(DateTime(2026, 9, 14), DateTime(2026, 9, 25)),
      );
    });

    testWidgets('the months stay put under the pointer between two clicks', (
      tester,
    ) async {
      await pump(tester, preset('today'));
      expect(find.text('September 2026'), findsOneWidget);
      final aimed = tester.getCenter(day('2026-09-25'));

      await tester.tap(day('2026-09-14'));
      await tester.pump();

      // Where Sep 25 was is still Sep 25: the second click of a range hits the
      // day it was aimed at.
      expect(find.text('October 2026'), findsOneWidget);
      expect(find.text('August 2026'), findsNothing);
      expect(tester.getCenter(day('2026-09-25')), aimed);
    });

    testWidgets('one click is one date', (tester) async {
      final applied = await pump(tester, preset('this_week'));

      await tester.tap(day('2026-10-02'));
      await tester.pump();
      await tester.tap(find.byKey(const Key('date-range-apply')));

      expect(applied.single, DateSpan.single(DateTime(2026, 10, 2)));
    });

    testWidgets('each quick choice shows its dates before it is chosen', (
      tester,
    ) async {
      await pump(tester, preset('today'));

      expect(
        find.descendant(
          of: find.byKey(const ValueKey('date-preset-last_week')),
          matching: find.text('Mon, Sep 28 – Sun, Oct 4'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('more than 92 days is refused here, in words', (tester) async {
      final applied = await pump(tester, preset('today'));

      // Today, then back three months to July 5: ninety-four days.
      await tester.tap(day('2026-10-06'));
      await tester.pump();
      for (var i = 0; i < 3; i++) {
        await tester.tap(find.byTooltip('Earlier month').first);
        await tester.pump();
      }
      await tester.tap(day('2026-07-05'));
      await tester.pump();

      expect(find.byKey(const Key('date-range-problem')), findsOneWidget);
      expect(find.text('Choose 92 days or fewer. This is 94.'), findsOneWidget);
      await tester.tap(find.byKey(const Key('date-range-apply')));
      expect(applied, isEmpty);
    });

    testWidgets('days after today cannot be chosen', (tester) async {
      final applied = await pump(tester, preset('today'));

      await tester.tap(day('2026-10-09'));
      await tester.pump();
      await tester.tap(find.byKey(const Key('date-range-apply')));

      expect(applied.single, preset('today'));
    });
  });

  // Jose, 2026-11-16: "the date picker on mobile seems to be broken, like u
  // click a custom date or the preselected dates and nothing happens like it
  // stays on the picker menu". Reports on a phone: the app's top bar, the
  // tab picker, the dates, the report filling the rest, the bottom bar. The
  // panel opened in that column, 70% of the screen high, and its "Show N
  // days" landed under the bottom bar.
  group('on a phone', () {
    final monday = DateTime(2026, 11, 16);

    Future<List<DateSpan>> reports(WidgetTester tester) async {
      final changed = <DateSpan>[];
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      var span = DateSpan.single(monday);
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            appBar: AppBar(title: const Text('Reports')),
            bottomNavigationBar: NavigationBar(
              destinations: const [
                NavigationDestination(icon: Icon(Icons.home), label: 'Home'),
                NavigationDestination(icon: Icon(Icons.menu), label: 'More'),
              ],
            ),
            body: StatefulBuilder(
              builder: (context, setState) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 56, child: Text('Scoring ▾')),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                    // Keyed on the span, as Reports keys it.
                    child: ReportDates(
                      key: ValueKey(span),
                      inline: true,
                      initial: span,
                      today: monday,
                      formats: DisplayFormats.fallback,
                      onChanged: (s) {
                        changed.add(s);
                        setState(() => span = s);
                      },
                    ),
                  ),
                  const Expanded(child: Placeholder()),
                ],
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('date-range-button')));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.byKey(const Key('date-range-apply')), findsOneWidget);
      return changed;
    }

    testWidgets('a quick choice is one tap: the picker closes on its dates', (
      tester,
    ) async {
      final changed = await reports(tester);

      await tester.tap(find.byKey(const ValueKey('date-preset-last_7')));
      await tester.pumpAndSettle();

      final week = DateSpan(DateTime(2026, 11, 10), monday);
      expect(changed, [week]);
      expect(find.byKey(const Key('date-range-apply')), findsNothing);
      expect(
        tester.widget<Text>(find.byKey(const Key('date-range-words'))).data,
        spanWords(week, monday, DisplayFormats.fallback),
      );
    });

    testWidgets('Custom: two days on the calendar, then Show reaches it', (
      tester,
    ) async {
      final changed = await reports(tester);

      // Custom brings the calendar up from under the list.
      await tester.tap(find.byKey(const ValueKey('date-preset-custom')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('date-day-2026-11-03')));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('date-day-2026-11-10')));
      await tester.pump();

      // The button is on the screen, above the bottom bar, and a tap on it
      // lands on it.
      final apply = find.byKey(const Key('date-range-apply'));
      expect(
        find.descendant(of: apply, matching: find.text('Show 8 days')),
        findsOneWidget,
      );
      final hit = tester.hitTestOnBinding(tester.getCenter(apply));
      final button = tester.renderObject(apply);
      expect(hit.path.any((entry) => entry.target == button), isTrue);
      await tester.tap(apply);
      await tester.pumpAndSettle();

      expect(changed, [
        DateSpan(DateTime(2026, 11, 3), DateTime(2026, 11, 10)),
      ]);
      expect(apply, findsNothing);
    });

    testWidgets('Cancel closes it and leaves the dates as they were', (
      tester,
    ) async {
      final changed = await reports(tester);

      await tester.tap(find.byKey(const ValueKey('date-preset-yesterday')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('date-range-button')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();

      expect(changed, [DateSpan.single(DateTime(2026, 11, 15))]);
      expect(find.byKey(const Key('date-range-apply')), findsNothing);
    });
  });
}
