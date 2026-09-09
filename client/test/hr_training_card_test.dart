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
    await _pump(tester, _records, width: 1400);

    // Is anything wrong with this person, before what they have done.
    expect(find.byKey(TrainingCard.countsKey), findsOneWidget);
    final strip = find.descendant(
      of: find.byKey(TrainingCard.countsKey),
      matching: find.byType(Text),
    );
    expect(
      tester.widgetList<Text>(strip).map((t) => t.data).toList(),
      ['1', 'Due for retaking', '2', 'Passed', '2', 'Not started'],
    );

    // Lapsed above passed, whatever order the server listed them in.
    expect(
      tester.getTopLeft(find.text('Ladder safety')).dy,
      lessThan(tester.getTopLeft(find.text('Chimney sweep basics')).dy),
    );
    expect(find.text('lapsed 2026-03-12'), findsOneWidget);
    expect(find.text('92% · runs out 2027-06-04'), findsOneWidget);
    expect(find.text('completed 2026-08-01'), findsOneWidget);
    expect(find.text('screen 3 of 9'), findsOneWidget);

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
    await _pump(tester, _records, width: 390);

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
Future<http.Response> _records(http.Request request) async => http.Response(
  jsonEncode({
    'user': {'id': 'u-2', 'name': 'Bea Okafor'},
    'data': [
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
    ],
  }),
  200,
);
