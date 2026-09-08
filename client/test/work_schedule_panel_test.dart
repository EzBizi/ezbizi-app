import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/widgets/work_schedule_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The weekday editor shared by Records and Your account (design 2026-09-08 §10.3).
void main() {
  testWidgets('seven rows, a day off switch, and Save returns the week', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    Map<String, dynamic>? saved;

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () async {
                saved = await showWorkSchedulePanel(
                  context,
                  title: 'Work schedule',
                  days: {
                    'monday': {'start': '08:00', 'end': '17:00'},
                    'tuesday': null,
                    'wednesday': null,
                    'thursday': null,
                    'friday': null,
                    'saturday': null,
                    'sunday': null,
                  },
                  formats: DisplayFormats.fallback,
                );
              },
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();

    expect(find.text('Mon'), findsOneWidget);
    expect(find.text('Sun'), findsOneWidget);
    expect(find.text('Day off'), findsNWidgets(6));

    // Switch Tuesday on: it starts as the ordinary working day.
    await tester.tap(find.byType(Switch).at(1));
    await tester.pumpAndSettle();
    expect(find.text('Day off'), findsNWidgets(5));

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(saved!['tuesday'], {'start': '08:00', 'end': '17:00'});
    expect(saved!['monday'], {'start': '08:00', 'end': '17:00'});
    expect(saved!['sunday'], isNull);
    expect(saved!.keys.length, 7);
  });

  testWidgets('Cancel returns null', (tester) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    Map<String, dynamic>? saved = {'sentinel': true};

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () async {
                saved = await showWorkSchedulePanel(
                  context,
                  title: 'Work schedule',
                  days: null,
                  formats: DisplayFormats.fallback,
                );
              },
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    expect(find.text('Day off'), findsNWidgets(7));

    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();

    expect(saved, isNull);
  });
}
