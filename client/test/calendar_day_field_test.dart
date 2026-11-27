import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const american = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
  const european = DisplayFormats(dateFormat: 'D/M/YYYY', timeFormat: '24h');
  const iso = DisplayFormats(dateFormat: 'YYYY-MM-DD', timeFormat: '24h');
  final box = find.byKey(const Key('calendar-day-text'));

  Future<void> leave(WidgetTester tester) async {
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pump();
  }

  /// One field, not three boxes (Jose, 2026-11-03: "1 - yes").
  testWidgets('a typed day is stored as ISO and shown the org way', (
    tester,
  ) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: american);

    expect(find.byType(TextField), findsOneWidget);
    await tester.enterText(box, '09/01/2026');
    await tester.pump();
    expect(controller.text, '2026-09-01');

    await leave(tester);
    expect(find.widgetWithText(TextField, '9/1/2026'), findsOneWidget);
  });

  testWidgets('the hint is the organization pattern', (tester) async {
    await _pump(tester, TextEditingController(), formats: european);
    expect(find.text('DD/MM/YYYY'), findsOneWidget);

    await _pump(tester, TextEditingController(), formats: iso, withTime: true);
    expect(find.text('YYYY-MM-DD 09:00'), findsOneWidget);
  });

  testWidgets('the same digits are two different days under two settings', (
    tester,
  ) async {
    final us = TextEditingController();
    await _pump(tester, us, formats: american);
    await tester.enterText(box, '3/8/2026');
    await tester.pump();
    expect(us.text, '2026-03-08');

    final eu = TextEditingController();
    await _pump(tester, eu, formats: european);
    await tester.enterText(box, '3/8/2026');
    await tester.pump();
    expect(eu.text, '2026-08-03');
  });

  testWidgets('changing the setting rewrites the field and keeps the day', (
    tester,
  ) async {
    final controller = TextEditingController(text: '2026-03-08');
    await _pump(tester, controller, formats: american);
    expect(find.widgetWithText(TextField, '3/8/2026'), findsOneWidget);

    await _pump(tester, controller, formats: european);
    expect(find.widgetWithText(TextField, '8/3/2026'), findsOneWidget);
    expect(controller.text, '2026-03-08');
  });

  testWidgets('the calendar under the field sets the day', (tester) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: american);

    await tester.tap(find.byKey(const Key('calendar-day-pick')));
    await tester.pumpAndSettle();
    expect(find.byType(CalendarDatePicker), findsOneWidget);

    await tester.tap(find.text('15'));
    await tester.pumpAndSettle();

    final parsed = parseCalendarDay(controller.text);
    expect(parsed, isNotNull);
    expect(parsed!.day, 15);
    expect(find.byType(CalendarDatePicker), findsNothing);
  });

  testWidgets('a day the calendar does not have is not stored', (tester) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: american);

    await tester.enterText(box, '02/31/2026');
    await tester.pump();
    await leave(tester);

    expect(controller.text, isEmpty);
    expect(find.text('Type it as MM/DD/YYYY, or pick a day.'), findsOneWidget);
  });

  testWidgets('a scheduled time keeps the hour next to the day', (tester) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: european, withTime: true);

    await tester.enterText(box, '08/03/2026 09:00');
    await tester.pump();

    expect(controller.text, '2026-03-08 09:00');
  });

  /// Jose, 2026-09-30, booking a lead: 16:00 PM must not book a job with no
  /// time at all.
  testWidgets('16 in a 12-hour field is 4 PM, and is stored', (tester) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: american, withTime: true);

    await tester.enterText(box, '9/30/2026 16:00');
    await tester.pump();
    expect(controller.text, '2026-09-30 16:00');

    await leave(tester);
    expect(find.widgetWithText(TextField, '9/30/2026 4:00 PM'), findsOneWidget);
  });

  testWidgets('PM is read', (tester) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: american, withTime: true);

    await tester.enterText(box, '9/30/2026 2:15 pm');
    await tester.pump();
    expect(controller.text, '2026-09-30 14:15');
  });

  testWidgets('an hour no clock has is not stored, and says so', (
    tester,
  ) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: american, withTime: true);

    await tester.enterText(box, '9/30/2026 25:00');
    await tester.pump();
    await leave(tester);

    expect(controller.text, isEmpty);
    expect(
      find.text('Type it as MM/DD/YYYY 9:00 AM, or pick a day.'),
      findsOneWidget,
    );
  });

  /// A job keeps the seconds it was booked with until somebody changes it.
  testWidgets('a click in and out leaves a stored time as it was', (
    tester,
  ) async {
    final controller = TextEditingController(text: '2026-09-24 09:43:33');
    await _pump(tester, controller, formats: european, withTime: true);

    await tester.tap(box);
    await tester.pump();
    await leave(tester);
    expect(controller.text, '2026-09-24 09:43:33');

    // A real change still writes, at the field's own minute precision.
    await tester.enterText(box, '25/9/2026 09:43');
    await tester.pump();
    await leave(tester);
    expect(controller.text, '2026-09-25 09:43');
  });

  testWidgets('the field and the calendar fit a 375-wide phone', (
    tester,
  ) async {
    final controller = TextEditingController(text: '2026-09-01');
    await tester.binding.setSurfaceSize(const Size(375, 812));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pump(tester, controller, formats: american);
    await tester.tap(find.byKey(const Key('calendar-day-pick')));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('Next service on'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  TextEditingController controller, {
  required DisplayFormats formats,
  bool withTime = false,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: Padding(
          padding: const EdgeInsets.all(16),
          child: CalendarDayField(
            controller: controller,
            label: 'Next service on',
            formats: formats,
            withTime: withTime,
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
