import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/availability_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Availability — "When can we come out?", laid out like A1 Tools (Jose,
/// 2026-09-25).
///
/// The colour IS the answer on this screen, so the chips are asserted on the
/// fill they paint, taken from [ToneColours] — green 3+, yellow 2, red 1, grey
/// full — not only on the words. A chip that read "1 Spot Left" in green would
/// pass every text assertion and tell a dispatcher the opposite of the truth.
void main() {
  testWidgets(
    'a zip answers with the first day and colours each window by what is left',
    (tester) async {
      await _pump(
        tester,
        onRequest: (r) => r.url.path.endsWith('/scheduling/availability')
            ? _json({'data': _dallas})
            : null,
      );

      await tester.enterText(
        find.byKey(const Key('availability-search')),
        '75201',
      );
      await tester.tap(find.text('Check'));
      await tester.pumpAndSettle();

      expect(find.text('Monday, October 5'), findsOneWidget);
      expect(find.text('Mon, Oct 12'), findsOneWidget);
      expect(find.text('TEXAS · DALLAS'), findsOneWidget);

      final context = tester.element(find.byType(AvailabilityPane));
      void expectFill(String text, Tone tone) {
        final box = tester.widget<Container>(
          find
              .ancestor(of: find.text(text), matching: find.byType(Container))
              .first,
        );
        expect(
          (box.decoration! as BoxDecoration).color,
          ToneColours.of(context, tone).fill,
          reason: '"$text" painted the wrong colour.',
        );
      }

      expectFill('7 AM – 11 AM  ·  3 Spots Left', Tone.ok);
      expectFill('11 AM – 2 PM  ·  2 Spots Left', Tone.caution);
      expectFill('2 PM – 5 PM  ·  1 Spot Left', Tone.bad);
      expectFill('7 AM – 11 AM  ·  Full', Tone.neutral);

      expect(
        find.text('2 more booked that day with no time slot'),
        findsOneWidget,
      );
    },
  );

  /// "We do not cover that" is the answer A1 Tools gave, and dispatchers turned
  /// away jobs A-1 could have done. An uncovered zip says to check Workiz.
  testWidgets(
    'a zip nobody covers says to check Workiz, never "we do not cover"',
    (tester) async {
      await _pump(
        tester,
        onRequest: (r) => r.url.path.endsWith('/scheduling/availability')
            ? _json({
                'data': {'areas': const []},
              })
            : null,
      );

      await tester.enterText(
        find.byKey(const Key('availability-search')),
        '99999',
      );
      await tester.tap(find.text('Check'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('check availability on Workiz'),
        findsOneWidget,
      );
      expect(find.textContaining('cover'), findsNothing);
    },
  );

  testWidgets(
    'an area nobody has set up says to check Workiz, not closed or full',
    (tester) async {
      await _pump(
        tester,
        onRequest: (r) => r.url.path.endsWith('/scheduling/availability')
            ? _json({
                'data': {
                  'areas': [
                    {
                      'area_name': 'Austin',
                      'franchise_name': 'Texas',
                      'configured': false,
                      'first': null,
                      'after': const [],
                    },
                  ],
                },
              })
            : null,
      );

      await tester.enterText(
        find.byKey(const Key('availability-search')),
        '78701',
      );
      await tester.tap(find.text('Check'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('check availability on Workiz'),
        findsOneWidget,
      );
      expect(find.text('Full'), findsNothing);
    },
  );

  /// Search by Area: one chip per franchise that HAS areas — the server's
  /// list, because A-1 carries empty duplicate franchises the session knows.
  testWidgets(
    'search by area offers franchise chips and asks for the one tapped',
    (tester) async {
      String? asked;

      await _pump(
        tester,
        onRequest: (r) {
          if (r.url.path.endsWith('/availability/franchises')) {
            return _json({
              'data': [
                {'id': 'fr-tx', 'name': 'Texas'},
                {'id': 'fr-il', 'name': 'Illinois'},
              ],
            });
          }
          if (r.url.path.endsWith('/scheduling/availability')) {
            asked = r.url.queryParameters['franchise_id'];
            return _json({'data': _dallas});
          }
          return null;
        },
      );

      await tester.tap(find.text('Search by Area'));
      await tester.pumpAndSettle();

      expect(find.widgetWithText(ChoiceChip, 'Illinois'), findsOneWidget);

      await tester.tap(find.widgetWithText(ChoiceChip, 'Texas'));
      await tester.pumpAndSettle();

      expect(asked, 'fr-tx');
      expect(find.text('Monday, October 5'), findsOneWidget);
    },
  );

  /// "All areas" (Jose, 2026-09-28) asks for every area in view.
  testWidgets('all areas asks for every area rather than a franchise', (
    tester,
  ) async {
    Map<String, String>? asked;

    await _pump(
      tester,
      onRequest: (r) {
        if (r.url.path.endsWith('/availability/franchises')) {
          return _json({
            'data': [
              {'id': 'fr-tx', 'name': 'Texas'},
            ],
          });
        }
        if (r.url.path.endsWith('/scheduling/availability')) {
          asked = r.url.queryParameters;
          return _json({'data': _dallas});
        }
        return null;
      },
    );

    await tester.tap(find.text('Search by Area'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, 'All areas'));
    await tester.pumpAndSettle();

    expect(asked, {'all': '1'});
    expect(find.text('Monday, October 5'), findsOneWidget);
  });

  /// At phone width all three used to read "Search by…", "Search by…",
  /// "Calendar…": nobody could tell the first two apart.
  testWidgets(
    'at phone width the three modes say what they are, at one height',
    (tester) async {
      await _pump(tester, width: 375, onRequest: (_) => null);

      const labels = ['Search by Zip Code', 'Search by Area', 'Calendar View'];
      for (final label in labels) {
        expect(
          tester
              .renderObject<RenderParagraph>(find.text(label))
              .didExceedMaxLines,
          isFalse,
          reason: '$label is cut short',
        );
      }
      final heights = {
        for (final label in labels)
          tester
              .getSize(
                find.ancestor(
                  of: find.text(label),
                  matching: find.byWidgetPredicate(
                    (w) => w is ButtonStyleButton,
                  ),
                ),
              )
              .height,
      };
      expect(heights, hasLength(1));
    },
  );

  /// At phone width the chips stack instead of squeezing. An overflow paints
  /// stripes and still passes a test, so the errors are caught and counted.
  testWidgets('a phone-width screen draws the answer without overflowing', (
    tester,
  ) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(
      tester,
      width: 375,
      onRequest: (r) => r.url.path.endsWith('/scheduling/availability')
          ? _json({'data': _dallas})
          : null,
    );

    await tester.enterText(
      find.byKey(const Key('availability-search')),
      '75201',
    );
    await tester.tap(find.text('Check'));
    await tester.pumpAndSettle();

    expect(find.text('Monday, October 5'), findsOneWidget);
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
}

const _dallas = {
  'basis': 'remaining',
  'areas': [
    {
      'area_id': 'a-dallas',
      'area_name': 'Dallas',
      'franchise_name': 'Texas',
      'configured': true,
      'first': {
        'date': '2026-10-05',
        'no_time': 2,
        'windows': [
          {
            'start_time': '07:00',
            'end_time': '11:00',
            'capacity': 3,
            'taken': 0,
            'remaining': 3,
          },
          {
            'start_time': '11:00',
            'end_time': '14:00',
            'capacity': 3,
            'taken': 1,
            'remaining': 2,
          },
          {
            'start_time': '14:00',
            'end_time': '17:00',
            'capacity': 2,
            'taken': 1,
            'remaining': 1,
          },
        ],
      },
      'after': [
        {
          'date': '2026-10-12',
          'no_time': 0,
          'windows': [
            {
              'start_time': '07:00',
              'end_time': '11:00',
              'capacity': 3,
              'taken': 3,
              'remaining': 0,
            },
            {
              'start_time': '11:00',
              'end_time': '14:00',
              'capacity': 3,
              'taken': 0,
              'remaining': 3,
            },
          ],
        },
      ],
    },
  ],
};

Future<void> _pump(
  WidgetTester tester, {
  required http.Response? Function(http.Request) onRequest,
  double width = 1280,
}) async {
  tester.view.physicalSize = Size(width, 1100);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest(request);
      if (override != null) return override;
      if (request.url.path.endsWith('/me')) return _json({'data': _me});
      return _json({'data': const []});
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: AvailabilityPane(api: api, session: session, visible: true),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['scheduling.view', 'scheduling.availability.view'],
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-tx', 'name': 'Texas', 'company_id': 'co-1'},
  ],
};
