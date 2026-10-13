import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reviews_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:http/http.dart' as http;

/// Reviews — the screen's job is to keep drafting and publishing apart.
///
/// Publishing puts words on Google under the business's name, permanently. The
/// cases below are the ones where a slip would be expensive and silent: a
/// Publish button that is live before anything is drafted, an empty state that
/// blames the business for having no reviews when nothing is connected, and a
/// published reply that looks editable.
void main() {
  testWidgets('with nothing connected the empty state blames the connection, '
      'not the business', (tester) async {
    await _pump(tester, reviews: const [], connections: const []);

    expect(find.text('No Business Profile connected'), findsOneWidget);
    expect(
      find.textContaining('this is not a business without reviews'),
      findsOneWidget,
    );
  });

  testWidgets('with a profile connected and the unanswered filter on, an empty '
      'list means everything is answered', (tester) async {
    await _pump(tester, reviews: const [], connections: _connected);

    expect(find.text('Everything has been answered'), findsOneWidget);
    expect(find.text('No Business Profile connected'), findsNothing);
  });

  // The emulator's width. The chip, the rating picker and Fetch new sat in one
  // Row there and overflowed it by 60px. An overflow paints stripes and passes,
  // so the only way to see it is to catch what Flutter reports.
  testWidgets('the filter bar fits a phone', (tester) async {
    final complaints = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) =>
        complaints.add(details.exceptionAsString());
    addTearDown(() => FlutterError.onError = previous);

    await _pump(
      tester,
      reviews: [_review(reply: null)],
      connections: _connected,
      size: const Size(411, 900),
    );
    FlutterError.onError = previous;

    expect(find.text('Fetch new'), findsOneWidget);
    expect(complaints.where((c) => c.contains('overflowed')), isEmpty);
  });

  testWidgets('Publish is not offered until something is drafted',
      (tester) async {
    await _pump(
      tester,
      reviews: [_review(reply: null)],
      connections: _connected,
    );

    await tester.tap(find.text('Write a reply'));
    await tester.pumpAndSettle();

    final publish = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Publish'),
    );

    expect(
      publish.onPressed,
      isNull,
      reason:
          'Publishing an empty reply would put nothing, or worse something '
          'half-written, on Google under the business name.',
    );
  });

  /// Jose, 2026-10-12, AI item 11: AI drafts, a person edits and publishes.
  testWidgets('Draft with AI fills the box, marked as AI until edited',
      (tester) async {
    await _pump(
      tester,
      reviews: [_review(reply: null)],
      connections: _connected,
    );
    await tester.tap(find.text('Write a reply'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Draft with AI'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Thank you, Dana!'), findsOneWidget);
    expect(find.text('Draft by AI'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'Thank you so much, Dana!');
    await tester.pumpAndSettle();
    expect(find.text('Draft by AI'), findsNothing);
  });

  testWidgets('a published reply is shown as published and offers no editor',
      (tester) async {
    await _pump(
      tester,
      reviews: [
        _review(reply: {
          'id': 'r1',
          'body': 'Sorry about that — we have spoken to the crew.',
          'status': 'published',
          'drafted_by': 'person',
          'published_at': '2026-08-26T10:00:00Z',
          'failure_reason': null,
          'editable': false,
        }),
      ],
      connections: _connected,
      onlyUnanswered: false,
    );

    expect(find.text('Your published reply'), findsOneWidget);
    expect(find.text('Write a reply'), findsNothing);
    expect(find.text('Edit draft'), findsNothing);
  });

  testWidgets('a review Google sent no rating for does not show as nought stars',
      (tester) async {
    await _pump(
      tester,
      reviews: [_review(rating: null)],
      connections: _connected,
    );

    expect(find.text('no rating'), findsOneWidget);
    expect(find.byIcon(Icons.star), findsNothing);
  });
}

const _connected = [
  {
    'provider': 'google_business_profile',
    'display_name': 'A-1 Chimney',
    'status': 'connected',
  },
];

Map<String, dynamic> _review({
  Map<String, dynamic>? reply,
  int? rating = 2,
}) =>
    {
      'id': 'rev-1',
      'reviewer_name': 'Dana Whitfield',
      'reviewer_photo_url': null,
      'rating': rating,
      'comment': 'Turned up late.',
      'reviewed_at': '2026-08-25T09:00:00Z',
      'edited_at': null,
      'was_edited': false,
      'company': null,
      'franchise': null,
      'reply': reply,
    };

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> reviews,
  required List<Map<String, dynamic>> connections,
  bool onlyUnanswered = true,
  Size size = const Size(1200, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/reviews')) {
        return http.Response(
          jsonEncode({
            'data': reviews,
            'meta': {
              'total': reviews.length,
              'per_page': 50,
              'current_page': 1,
              'last_page': 1,
              'unanswered': reviews.where((r) => r['reply'] == null).length,
              'connections': connections,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/reply/suggest')) {
        return http.Response(
          jsonEncode({
            'data': {'body': 'Thank you, Dana!'},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response('{}', 200,
          headers: {'content-type': 'application/json'});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ReviewsScreen(api: api, session: Session(api))),
    ),
  );
  await tester.pumpAndSettle();

  if (!onlyUnanswered) {
    await tester.tap(find.textContaining('Unanswered'));
    await tester.pumpAndSettle();
  }
}
