import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const american = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');
  const european = DisplayFormats(dateFormat: 'D/M/YYYY', timeFormat: '24h');

  testWidgets('typing month, day and year stores an ISO day', (tester) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: american);

    await tester.enterText(find.byKey(const Key('calendar-day-month')), '09');
    await tester.enterText(find.byKey(const Key('calendar-day-day')), '01');
    await tester.enterText(find.byKey(const Key('calendar-day-year')), '2026');
    await tester.pump();

    expect(controller.text, '2026-09-01');
  });

  testWidgets('D/M/YYYY puts the day box first', (tester) async {
    final controller = TextEditingController();
    await _pump(tester, controller, formats: european);

    final labels = tester
        .widgetList<TextField>(find.byType(TextField))
        .map((f) => f.decoration?.labelText)
        .toList();

    expect(labels.take(3), ['Day', 'Month', 'Year']);
  });

  testWidgets('the same digits are two different days under two settings', (
    tester,
  ) async {
    final americanController = TextEditingController();
    await _pump(tester, americanController, formats: american);

    var boxes = find.byType(TextField);
    await tester.enterText(boxes.at(0), '8');
    await tester.enterText(boxes.at(1), '3');
    await tester.enterText(boxes.at(2), '2026');
    await tester.pump();
    expect(americanController.text, '2026-08-03');

    final europeanController = TextEditingController();
    await _pump(tester, europeanController, formats: european);

    boxes = find.byType(TextField);
    await tester.enterText(boxes.at(0), '8');
    await tester.enterText(boxes.at(1), '3');
    await tester.enterText(boxes.at(2), '2026');
    await tester.pump();
    expect(europeanController.text, '2026-03-08');
  });

  testWidgets('changing the setting reorders the boxes and keeps the day', (
    tester,
  ) async {
    final controller = TextEditingController(text: '2026-08-03');
    await _pump(tester, controller, formats: american);

    expect(
      tester
          .widgetList<TextField>(find.byType(TextField))
          .map((f) => f.decoration?.labelText)
          .take(3),
      ['Month', 'Day', 'Year'],
    );

    await _pump(tester, controller, formats: european);

    expect(
      tester
          .widgetList<TextField>(find.byType(TextField))
          .map((f) => f.decoration?.labelText)
          .take(3),
      ['Day', 'Month', 'Year'],
    );
    expect(controller.text, '2026-08-03');
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('calendar-day-month')))
          .controller!
          .text,
      '08',
    );
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('calendar-day-day')))
          .controller!
          .text,
      '03',
    );
  });

  testWidgets('the calendar under the boxes sets the three parts', (
    tester,
  ) async {
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

    await tester.enterText(find.byKey(const Key('calendar-day-month')), '02');
    await tester.enterText(find.byKey(const Key('calendar-day-day')), '31');
    await tester.enterText(find.byKey(const Key('calendar-day-year')), '2026');
    await tester.pump();

    expect(controller.text, isEmpty);
    expect(find.text('That day is not on the calendar.'), findsOneWidget);
  });

  testWidgets('a scheduled time keeps the hour next to the day', (tester) async {
    final controller = TextEditingController();
    await _pump(
      tester,
      controller,
      formats: european,
      withTime: true,
    );

    await tester.enterText(find.byKey(const Key('calendar-day-day')), '08');
    await tester.enterText(find.byKey(const Key('calendar-day-month')), '03');
    await tester.enterText(find.byKey(const Key('calendar-day-year')), '2026');
    await tester.enterText(find.byKey(const Key('calendar-day-hour')), '09');
    await tester.enterText(find.byKey(const Key('calendar-day-minute')), '00');
    await tester.pump();

    expect(controller.text, '2026-03-08 09:00');
  });

  testWidgets('three boxes and the calendar fit a 375-wide phone', (
    tester,
  ) async {
    final controller = TextEditingController(text: '2026-09-01');
    await tester.binding.setSurfaceSize(const Size(375, 812));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pump(tester, controller, formats: american);

    expect(tester.takeException(), isNull);
    expect(find.text('Next service on'), findsOneWidget);
    expect(find.byKey(const Key('calendar-day-pick')), findsOneWidget);
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
