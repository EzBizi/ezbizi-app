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
  // Mia, 2026-10-02: an area set up closed every day said "check Workiz";
  // a holiday between openings was simply missing.
  testWidgets('an area closed every day says Closed, not check Workiz', (
    tester,
  ) async {
    await _pump(
      tester,
      onRequest: (r) => r.url.path.endsWith('/scheduling/availability')
          ? _json({
              'data': {
                'basis': 'remaining',
                'areas': [
                  {
                    'area_id': 'a-sa',
                    'area_name': 'San Antonio',
                    'franchise_name': 'Nationwide',
                    'configured': true,
                    'first': null,
                    'after': const [],
                    'closed': const [],
                    'none_because': 'closed',
                  },
                ],
              },
            })
          : null,
    );

    await tester.enterText(
      find.byKey(const Key('availability-search')),
      '78201',
    );
    await tester.tap(find.text('Check'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('availability-closed-all')), findsOneWidget);
    expect(find.textContaining('Workiz'), findsNothing);
  });

  testWidgets('a closed date sits in the list where it falls', (tester) async {
    final area = Map<String, dynamic>.from(
      (_dallas['areas'] as List).first as Map,
    );
    final after = (area['after'] as List).cast<Map<String, dynamic>>();
    area['closed'] = [
      {'date': after.first['date'], 'reason': 'date'},
    ];
    area['after'] = after.skip(1).toList();

    await _pump(
      tester,
      onRequest: (r) => r.url.path.endsWith('/scheduling/availability')
          ? _json({
              'data': {
                ..._dallas,
                'areas': [area],
              },
            })
          : null,
    );

    await tester.enterText(
      find.byKey(const Key('availability-search')),
      '75201',
    );
    await tester.tap(find.text('Check'));
    await tester.pumpAndSettle();

    expect(
      find.byKey(ValueKey('availability-closed-${after.first['date']}')),
      findsOneWidget,
    );
    expect(find.text('Closed that day'), findsOneWidget);
  });

  // Mia, 2026-10-02: a customer writes "86 Jerusalem hill Trumbull", not a
  // zip. The pane asks by address and says which address the zip came from.
  // Jose, 2026-11-06: picking from the list must check right away, map and all.
  testWidgets(
    'picking a suggestion runs the check and its suggestion at once',
    (tester) async {
      final asked = <String>[];
      await _pump(
        tester,
        onRequest: (r) {
          if (r.url.path.endsWith('/scheduling/suggest')) {
            return _json({
              'data': [
                {
                  'type': 'zip',
                  'label': '06611',
                  'detail': 'Trumbull · CT',
                  'area_id': 'a-1',
                  'area_name': 'CT',
                },
              ],
            });
          }
          if (r.url.path.endsWith('/scheduling/availability')) {
            asked.add('availability ${r.url.query}');
            return _json({'data': _dallas});
          }
          if (r.url.path.endsWith('/scheduling/availability/suggestion')) {
            asked.add('suggestion ${r.url.query}');
            return _json({'data': null});
          }
          return null;
        },
      );

      await tester.enterText(
        find.byKey(const Key('availability-search')),
        '0661',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('06611'));
      await tester.pumpAndSettle();

      expect(asked, ['availability zip=06611', 'suggestion zip=06611']);

      // The customer cannot take that day and asks for the 12th: the 12th's
      // own suggestion (Jose, 2026-11-06).
      await tester.tap(
        find.byKey(const ValueKey('availability-day-2026-10-12')),
      );
      await tester.pumpAndSettle();
      expect(asked.last, 'suggestion zip=06611&date=2026-10-12');
    },
  );

  // Jose, 2026-11-09: the branch's office, or that there is none, on every answer.
  testWidgets('each area names its office, or says none is on file', (
    tester,
  ) async {
    await _pump(
      tester,
      onRequest: (r) {
        if (!r.url.path.endsWith('/scheduling/availability')) return null;
        final area = (_dallas['areas'] as List).first as Map;
        return _json({
          'data': {
            ..._dallas,
            'areas': [
              {
                ...area,
                'office': {
                  'line': '11836 Judd Ct, Dallas, TX 75243',
                  'maps_url':
                      'https://www.google.com/maps/search/?api=1&query=x',
                },
              },
              {...area, 'area_id': 'a-2', 'area_name': 'Plano'},
            ],
          },
        });
      },
    );
    await tester.enterText(
      find.byKey(const Key('availability-search')),
      '75243',
    );
    await tester.tap(find.text('Check'));
    await tester.pumpAndSettle();

    expect(
      find.text('Our office: 11836 Judd Ct, Dallas, TX 75243'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('availability-office-copy')), findsOneWidget);
    expect(find.byKey(const Key('availability-office-maps')), findsOneWidget);
    expect(find.text('No address on file for this branch'), findsOneWidget);
  });

  testWidgets('an address is asked as an address and shows where it landed', (
    tester,
  ) async {
    Uri? asked;
    await _pump(
      tester,
      onRequest: (r) {
        if (!r.url.path.endsWith('/scheduling/availability')) return null;
        asked = r.url;
        return _json({
          'data': {
            ..._dallas,
            'resolved': {
              'address': '86 Jerusalem Hill Rd, Trumbull, CT 06611, USA',
              'zip': '06611',
              'exact': true,
            },
          },
        });
      },
    );

    await tester.enterText(
      find.byKey(const Key('availability-search')),
      '86 Jerusalem hill Trumbull',
    );
    await tester.tap(find.text('Check'));
    await tester.pumpAndSettle();

    expect(asked?.queryParameters['address'], '86 Jerusalem hill Trumbull');
    expect(
      find.text('86 Jerusalem Hill Rd, Trumbull, CT 06611, USA — zip 06611'),
      findsOneWidget,
    );
  });

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

  /// Jose, 2026-10-05: on a call for DMV, "another dispatcher books something
  /// else on DMV, i still see the same availability". The answer asks again
  /// by itself, and a refresh that fails leaves the last answer up.
  testWidgets('the answer refreshes itself while it is open', (tester) async {
    var calls = 0;
    await _pump(
      tester,
      onRequest: (r) {
        if (!r.url.path.endsWith('/scheduling/availability')) return null;
        calls++;
        if (calls == 3) return _json({'message': 'Down'}, 500);
        // From the second answer on, 7–11 has gone: somebody booked it.
        final dallas = jsonDecode(jsonEncode(_dallas)) as Map<String, dynamic>;
        if (calls > 1) {
          final windows =
              (dallas['areas'] as List)[0]['first']['windows'] as List;
          windows[0]['taken'] = 3;
          windows[0]['remaining'] = 0;
        }
        return _json({'data': dallas});
      },
    );

    await tester.enterText(
      find.byKey(const Key('availability-search')),
      '75201',
    );
    await tester.tap(find.text('Check'));
    await tester.pumpAndSettle();
    expect(find.text('7 AM – 11 AM  ·  3 Spots Left'), findsOneWidget);

    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(calls, 2, reason: 'Asked again without anybody touching it.');
    expect(find.text('7 AM – 11 AM  ·  3 Spots Left'), findsNothing);
    expect(find.text('7 AM – 11 AM  ·  Full'), findsNWidgets(2));

    await tester.pump(const Duration(seconds: 31));
    await tester.pumpAndSettle();
    expect(calls, 3);
    expect(
      find.text('7 AM – 11 AM  ·  Full'),
      findsNWidgets(2),
      reason: 'A failed refresh keeps the answer.',
    );
    expect(find.text('Down'), findsNothing);
  });

  /// Jovanni, 2026-10-01: "pls improve the zip code validation". A typo is
  /// said to be one, not cut to five digits and searched, and not reported
  /// as a zip no area covers. Zip+4 and a 4-digit New England zip still ask.
  testWidgets('a number that is not a zip says so and is never searched', (
    tester,
  ) async {
    final asked = <String>[];
    await _pump(
      tester,
      onRequest: (r) {
        if (r.url.path.endsWith('/scheduling/availability')) {
          asked.add(r.url.queryParameters['zip'] ?? '');
          return _json({'data': _dallas});
        }
        return null;
      },
    );

    for (final typo in ['123', '123456', '75201-12']) {
      await tester.enterText(
        find.byKey(const Key('availability-search')),
        typo,
      );
      await tester.tap(find.text('Check'));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('That is not a zip code'),
        findsOneWidget,
        reason: '"$typo" is not a zip.',
      );
    }
    expect(asked, isEmpty);

    for (final zip in ['75201', '75201-1234', '6810']) {
      await tester.enterText(find.byKey(const Key('availability-search')), zip);
      await tester.tap(find.text('Check'));
      await tester.pumpAndSettle();
    }
    expect(asked, ['75201', '75201-1234', '6810']);
    expect(find.textContaining('That is not a zip code'), findsNothing);
  });

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

  /// A side panel is narrow on a wide screen, so the width of the pane, not
  /// the window's, picks the labels.
  testWidgets('beside a lead the modes are one word each', (tester) async {
    await _pump(tester, onRequest: (_) => null, paneWidth: 440);

    expect(find.text('Zip'), findsOneWidget);
    expect(find.text('Area'), findsOneWidget);
    expect(find.text('Calendar'), findsOneWidget);
    expect(find.text('Search by zip code'), findsNothing);
  });

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

      // One picker, not a chip per branch (deep dive 2026-11-02).
      await tester.tap(find.byKey(const ValueKey('availability-area-picker')));
      await tester.pumpAndSettle();
      expect(find.text('Illinois'), findsOneWidget);

      // Named with its company from /me (branch_options.dart); Illinois is
      // outside /me, so it keeps the server's name.
      await tester.tap(find.text('Everdoors · Texas'));
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
    await tester.tap(find.byKey(const ValueKey('availability-area-picker')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All areas'));
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

      // One word each when narrow (Jose, 2026-09-30: "just put Zip - Area -
      // Calendar"); the long labels wrapped onto two lines apiece.
      const labels = ['Zip', 'Area', 'Calendar'];
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
  double? paneWidth,
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
        body: Align(
          alignment: Alignment.topRight,
          child: SizedBox(
            width: paneWidth ?? width,
            child: AvailabilityPane(api: api, session: session, visible: true),
          ),
        ),
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
