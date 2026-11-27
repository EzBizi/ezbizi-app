import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/hr/training_card.dart';
import 'package:ezbizi_client/widgets/tag.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Training card on an HR record — desktop 3 and mobile 6, Jose's picks
/// of 2026-09-09, against the shape of `GET /training/records?user=`.
void main() {
  testWidgets('desktop: three counts first, the lapsed one boxed, then the '
      'rows most urgent first, and the ownership line', (tester) async {
    await _pump(tester, _recordsHandler, width: 1400);

    // Is anything wrong with this person, before what they have done.
    expect(find.byKey(TrainingCard.countsKey), findsOneWidget);
    final strip = find.descendant(
      of: find.byKey(TrainingCard.countsKey),
      matching: find.byType(Text),
    );
    final cells = tester.widgetList<Text>(strip).map((t) => t.data).toList();
    expect(cells, ['2', 'Needs a pass', '4', 'Outstanding', '2', 'Passed']);

    // `last_screen` is a 0-indexed high-water mark: 3 means the fourth screen
    // was reached, and 0 means the first was — started, not "0 of 6".
    expect(find.text('screen 4 of 9'), findsOneWidget);
    expect(find.text('screen 1 of 6'), findsOneWidget);
    expect(find.textContaining('screen 0 of'), findsNothing);

    // `passed` is a tri-state: null is never attempted, and must not read as
    // "Not passed" — that is a claim about a person, on their HR record.
    expect(find.text('Not passed'), findsOneWidget);
    expect(
      tester.getTopLeft(find.text('Fall arrest')).dy,
      greaterThan(tester.getTopLeft(find.text('Flue sizing')).dy),
    );

    // The three cells PARTITION the rows — every state counted once. A strip
    // that skipped a state read all zeros above red rows (UI/UX, 2026-09-09),
    // and this is what fails if a sixth state arrives and nobody adds it.
    final figures = [cells[0], cells[2], cells[4]].map((c) => int.parse(c!));
    expect(figures.fold(0, (a, b) => a + b), _records.length);
    expect(find.byType(Tag), findsNWidgets(_records.length));

    // The first cell never borrows Training's word: "retaking" means a pass
    // that lapsed, and somebody who never passed is not retaking anything.
    expect(cells, isNot(contains('Due for retaking')));
    expect(find.text('Not passed'), findsOneWidget);

    // Lapsed above passed, whatever order the server listed them in.
    expect(
      tester.getTopLeft(find.text('Ladder safety')).dy,
      lessThan(tester.getTopLeft(find.text('Chimney sweep basics')).dy),
    );
    expect(find.text('lapsed 2026-03-12'), findsOneWidget);
    // Design 190: score, completed and retake-by are their own columns.
    for (final heading in [
      'Course',
      'Status',
      'Score',
      'Completed',
      'Retake by',
    ]) {
      expect(find.text(heading), findsOneWidget);
    }
    expect(find.text('92%'), findsOneWidget);
    expect(find.text('2027-06-04'), findsOneWidget);
    expect(find.text('2026-08-01'), findsOneWidget);
    expect(
      tester.getTopLeft(find.text('2027-06-04')).dx,
      tester.getTopLeft(find.text('lapsed 2026-03-12')).dx,
    );

    // Never that word for a state: it is the dashboard's, for a different
    // table. The one place it appears is the footnote naming that panel.
    expect(
      tester.widgetList<Tag>(find.byType(Tag)).map((t) => t.label),
      everyElement(isNot(contains('ertification'))),
    );
    expect(find.textContaining('certification'), findsOneWidget);
    expect(
      find.textContaining('is ours to re-assign', findRichText: true),
      findsOneWidget,
    );
  });

  testWidgets('phone: no counts strip, a card per row, lapsed first', (
    tester,
  ) async {
    await _pump(tester, _recordsHandler, width: 390);

    expect(find.byKey(TrainingCard.countsKey), findsNothing);
    expect(find.text('Ladder safety'), findsOneWidget);
    expect(
      tester.getTopLeft(find.text('Ladder safety')).dy,
      lessThan(tester.getTopLeft(find.text('Gas appliance awareness')).dy),
    );
    expect(
      tester.getTopLeft(find.text('Gas appliance awareness')).dy,
      lessThan(tester.getTopLeft(find.text('Chimney sweep basics')).dy),
    );
    expect(
      find.textContaining('is ours to re-assign', findRichText: true),
      findsOneWidget,
    );
  });

  testWidgets('placed in a narrow slot on a wide window, it is the phone '
      'layout — the card asks its own width, not the window’s', (tester) async {
    tester.view.physicalSize = const Size(1400, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ListView(
            children: [
              Center(
                child: SizedBox(
                  width: 320,
                  child: TrainingCard(
                    api: ApiClient(client: MockClient(_recordsHandler)),
                    userId: 'u-2',
                    formats: DisplayFormats.fallback,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(TrainingCard.countsKey), findsNothing);
    expect(find.text('Ladder safety'), findsOneWidget);
  });

  testWidgets('refused: a lock and the server’s sentence, verbatim, and no '
      'counts', (tester) async {
    const sentence =
        'You hold the permission to watch training, but you are not assigned '
        'anywhere yet, so there is nobody else whose training records are '
        'yours to see. An administrator can assign you to a part of the '
        'business.';

    await _pump(
      tester,
      (_) async => http.Response(jsonEncode({'message': sentence}), 403),
      width: 1400,
    );

    expect(find.byIcon(Icons.lock_outline), findsOneWidget);
    expect(find.text(sentence), findsOneWidget);
    expect(find.byKey(TrainingCard.countsKey), findsNothing);
    expect(find.textContaining('Due for retaking'), findsNothing);
  });

  testWidgets('nothing assigned is said, not left as an empty card', (
    tester,
  ) async {
    await _pump(
      tester,
      (_) async => http.Response(jsonEncode({'user': null, 'data': []}), 200),
      width: 1400,
    );

    expect(find.textContaining('Nothing has been assigned'), findsOneWidget);
    expect(find.byKey(TrainingCard.countsKey), findsNothing);
  });

  testWidgets('the request names the person', (tester) async {
    Uri? asked;

    await _pump(tester, (request) async {
      asked = request.url;
      return http.Response(jsonEncode({'user': null, 'data': []}), 200);
    }, width: 1400);

    expect(asked?.path, endsWith('/training/records'));
    expect(asked?.queryParameters['user'], 'u-2');
  });
}

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  required double width,
}) async {
  tester.view.physicalSize = Size(width, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ListView(
          children: [
            TrainingCard(
              api: ApiClient(client: MockClient(handler)),
              userId: 'u-2',
              formats: DisplayFormats.fallback,
            ),
          ],
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The endpoint's rows, deliberately NOT most urgent first — the card sorts.
/// All five states are in it, so the strip's partition is checked in full.
Future<http.Response> _recordsHandler(http.Request request) async =>
    http.Response(
      jsonEncode({
        'user': {'id': 'u-2', 'name': 'Bea Okafor'},
        'data': _records,
      }),
      200,
    );

const _records = [
  {
    'type': 'test',
    'id': 't-1',
    'title': 'Chimney sweep basics',
    'assigned': true,
    'score': 92,
    'passed': true,
    'expires_on': '2027-06-04',
    'due_for_retaking': false,
  },
  {
    'type': 'guide',
    'id': 'g-1',
    'title': 'Gas appliance awareness',
    'assigned': true,
    'last_screen': null,
    'screen_count': 6,
    'completed_at': null,
  },
  {
    'type': 'test',
    'id': 't-2',
    'title': 'Ladder safety',
    'assigned': true,
    'score': 88,
    'passed': true,
    'expires_on': '2026-03-12',
    'due_for_retaking': true,
  },
  {
    'type': 'guide',
    'id': 'g-2',
    'title': 'Customer handling',
    'assigned': true,
    'last_screen': 3,
    'screen_count': 9,
    'completed_at': null,
  },
  {
    'type': 'guide',
    'id': 'g-3',
    'title': 'Fire safety awareness',
    'assigned': true,
    'last_screen': 5,
    'screen_count': 5,
    'completed_at': '2026-08-01T14:00:00+00:00',
  },
  {
    'type': 'test',
    'id': 't-3',
    'title': 'Fall arrest',
    'assigned': true,
    'score': null,
    'passed': null,
    'expires_on': null,
    'due_for_retaking': false,
  },
  {
    'type': 'guide',
    'id': 'g-4',
    'title': 'Flue liners',
    'assigned': true,
    'last_screen': 0,
    'screen_count': 6,
    'completed_at': null,
  },
  {
    'type': 'test',
    'id': 't-4',
    'title': 'Flue sizing',
    'assigned': true,
    'score': 40,
    'passed': false,
    'expires_on': null,
    'due_for_retaking': false,
  },
];
