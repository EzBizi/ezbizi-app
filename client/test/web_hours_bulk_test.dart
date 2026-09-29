import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/web/hours_bulk.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Hours on several websites (design 26, Jose's pick 2).
///
/// The form writes nothing: it sends the server the days asked for, as
/// actions, and draws the server's answer. So the assertions are about the
/// request body and about reading the review and results back.
void main() {
  const sites = [
    {'id': 'site-a', 'host': 'a.com', 'is_primary': true},
    {'id': 'site-b', 'host': 'b.com', 'is_primary': false},
  ];

  Map<String, Object?> day(
    String name, {
    Map<String, Object?>? was,
    Map<String, Object?>? requested,
    Map<String, Object?>? fin,
    bool changed = false,
    bool adjusted = false,
    String? error,
  }) => {
    'day': name,
    'action': requested == null ? 'keep' : 'set',
    'existing': was,
    'requested': requested,
    'converted': null,
    'final': fin ?? was,
    'changed': changed,
    'adjusted': adjusted,
    'error': error,
  };

  const nineToSix = {'closed': false, 'open': '09:00', 'close': '18:00'};

  final review = {
    'token': 'tok',
    'places': [
      {
        'site_id': 'site-a',
        'host': 'a.com',
        'timezone': 'America/New_York',
        'status': 'change',
        'reasons': [],
        'days': [
          day(
            'Mon',
            was: nineToSix,
            requested: {'open': '05:00', 'close': null},
            fin: {'closed': false, 'open': '06:00', 'close': '18:00'},
            changed: true,
            adjusted: true,
          ),
          day('Tue', was: nineToSix),
        ],
      },
      {
        'site_id': 'site-b',
        'host': 'b.com',
        'timezone': 'America/Chicago',
        'status': 'blocked',
        'reasons': ['Sat: Closed now, so opening it needs both times.'],
        'days': [
          day(
            'Sat',
            was: {'closed': true, 'open': '00:00', 'close': '00:00'},
            requested: {'open': '05:00', 'close': null},
            error: 'Closed now, so opening it needs both times.',
          ),
        ],
      },
    ],
    'summary': {'change': 1, 'blocked': 1},
  };

  /// @return every body posted, keyed by path.
  Future<Map<String, Map<String, dynamic>>> pump(
    WidgetTester tester, {
    Size size = const Size(1200, 1000),
    http.Response Function()? apply,
    List<Map<String, Object?>> sites = sites,
    VoidCallback? onClose,
    Map<String, Object?>? reviewed,
  }) async {
    final posted = <String, Map<String, dynamic>>{};
    http.Response json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

    final client = MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');
      if (request.method == 'POST') {
        posted[path] = jsonDecode(request.body) as Map<String, dynamic>;
      }

      return switch (path) {
        '/web/sites' => json({'data': sites}),
        '/web/hours/review' => json({'data': reviewed ?? review}),
        '/web/hours/apply' =>
          apply?.call() ??
              json({
                'data': {
                  'results': [
                    {
                      'site_id': 'site-a',
                      'host': 'a.com',
                      'status': 'changed',
                      'reason': null,
                    },
                  ],
                  'summary': {'changed': 1},
                },
              }),
        _ => json({}, 404),
      };
    });

    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // The Scaffold is this test's: the pane draws in the SEO tab and brings
    // none of its own.
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: HoursBulkPane(
            api: ApiClient(client: client),
            formats: const DisplayFormats(
              dateFormat: 'M/D/YYYY',
              timeFormat: '12h',
            ),
            startWith: 'site-a',
            onClose: onClose ?? () {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    return posted;
  }

  Future<void> tap(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pumpAndSettle();
    await tester.tap(finder);
    await tester.pumpAndSettle();
  }

  Finder segment(int day, String label) => find.descendant(
    of: find.byKey(Key('hours-bulk-act-$day')),
    matching: find.text(label),
  );

  testWidgets('only the days asked for are sent, each as an action', (
    tester,
  ) async {
    final posted = await pump(tester);

    // At the bar's right edge, not adrift in the middle of it.
    expect(
      tester.getRect(find.byKey(const Key('hours-bulk-review'))).right,
      closeTo(1200 - 16, 1),
    );

    await tap(tester, find.widgetWithText(FilterChip, 'b.com'));
    await tap(tester, segment(5, 'Closed all day'));
    await tap(tester, segment(4, 'Set hours'));
    // Friday: the opening stays Keep; the closing is picked (9:00, the default).
    await tap(tester, find.byKey(const Key('hours-bulk-close-4')));
    await tap(tester, find.text('OK'));
    // Wednesday on Set hours with both edges on Keep asks for nothing.
    await tap(tester, segment(2, 'Set hours'));

    await tap(tester, find.byKey(const Key('hours-bulk-review')));

    final body = posted['/web/hours/review']!;
    expect((body['site_ids'] as List).toSet(), {'site-a', 'site-b'});
    expect(body['destinations'], ['website']);
    expect(body['source_timezone'], isNull);
    expect(body['not_before'], isNull);
    expect(body['schedule'], {
      'friday': {
        'action': 'set_hours',
        'open': {'action': 'keep'},
        'close': {'action': 'set', 'time': '09:00'},
      },
      'saturday': {'action': 'closed'},
    });
  });

  testWidgets('the review names each website, and why one is not written', (
    tester,
  ) async {
    await pump(tester);
    await tap(tester, segment(5, 'Closed all day'));
    await tap(tester, find.byKey(const Key('hours-bulk-review')));

    // One tab per website, with its count, as drawn.
    expect(find.text('a.com · 1'), findsOneWidget);
    expect(find.text('b.com · blocked'), findsOneWidget);
    expect(find.text('opening moved'), findsOneWidget);
    expect(find.text('6:00 AM – 6:00 PM'), findsOneWidget);
    // Only the website that will change counts toward the button.
    expect(find.text('Update 1 website'), findsOneWidget);

    await tap(tester, find.text('b.com · blocked'));
    expect(
      find.text('Nothing is written to this website until these are fixed:'),
      findsOneWidget,
    );
    expect(
      find.text('Sat: Closed now, so opening it needs both times.'),
      findsOneWidget,
    );
  });

  /// Jose, 2026-09-29, on forty-nine websites: "it seems like it was designed
  /// for phone but on desktop you should just be able to see all the
  /// websites". The strip scrolled sideways, which a mouse cannot do.
  testWidgets('on a desktop every website is on show, changes first', (
    tester,
  ) async {
    Map<String, Object?> place(String host, String status) => {
      'site_id': 'site-$host',
      'host': host,
      'timezone': 'America/Chicago',
      'status': status,
      'reasons': status == 'blocked' ? ['No time zone set.'] : [],
      'days': [
        day(
          'Mon',
          was: nineToSix,
          requested: status == 'change' ? {'open': '08:00', 'close': null} : null,
          fin: status == 'change'
              ? {'closed': false, 'open': '08:00', 'close': '18:00'}
              : null,
          changed: status == 'change',
        ),
      ],
    };

    await pump(
      tester,
      reviewed: {
        'token': 'tok',
        // Alphabetical, as the server sends them: the two that matter last.
        'places': [
          for (var i = 10; i < 30; i++) place('site$i.a-1chimney.com', 'unchanged'),
          place('y.com', 'blocked'),
          place('z.com', 'change'),
        ],
        'summary': {'change': 1, 'unchanged': 20, 'blocked': 1},
      },
    );
    await tap(tester, segment(5, 'Closed all day'));
    await tap(tester, find.byKey(const Key('hours-bulk-review')));

    // Every chip is inside the window, the last one included.
    final screen = Offset.zero & tester.view.physicalSize;
    for (final label in [
      'z.com · 1',
      'y.com · blocked',
      'site10.a-1chimney.com',
      'site29.a-1chimney.com',
    ]) {
      final chip = tester.getRect(find.widgetWithText(ChoiceChip, label));
      expect(
        screen.contains(chip.topLeft) && screen.contains(chip.bottomRight),
        isTrue,
        reason: '$label is drawn at $chip, outside the window.',
      );
    }

    // The one that changes leads, then the one not written, then the rest.
    double x(String label) =>
        tester.getTopLeft(find.widgetWithText(ChoiceChip, label)).dx;
    double y(String label) =>
        tester.getTopLeft(find.widgetWithText(ChoiceChip, label)).dy;
    expect(y('z.com · 1'), y('y.com · blocked'));
    expect(x('z.com · 1'), lessThan(x('y.com · blocked')));
    expect(x('y.com · blocked'), lessThan(x('site10.a-1chimney.com')));
    // And it is the one open.
    expect(find.text('8:00 AM – 6:00 PM'), findsOneWidget);
  });

  testWidgets('the results list every website that was ticked', (tester) async {
    final posted = await pump(tester);
    await tap(tester, find.widgetWithText(FilterChip, 'b.com'));
    await tap(tester, segment(5, 'Closed all day'));
    await tap(tester, find.byKey(const Key('hours-bulk-review')));
    await tap(tester, find.byKey(const Key('hours-bulk-apply')));

    // Apply sends the review's token and nothing else.
    expect(posted['/web/hours/apply'], {'token': 'tok'});
    expect(find.text('Changed'), findsOneWidget);
    // b.com was blocked, so apply never touched it; it is still listed.
    expect(find.text('b.com'), findsOneWidget);
    expect(find.text('Not written'), findsOneWidget);
  });

  testWidgets('an expired review goes back to the form with the reason', (
    tester,
  ) async {
    await pump(
      tester,
      apply: () => http.Response(
        jsonEncode({
          'message':
              'That review has expired or is not yours. Review the changes again.',
        }),
        409,
        headers: {'content-type': 'application/json'},
      ),
    );
    await tap(tester, segment(5, 'Closed all day'));
    await tap(tester, find.byKey(const Key('hours-bulk-review')));
    await tap(tester, find.byKey(const Key('hours-bulk-apply')));

    expect(
      find.text(
        'That review has expired or is not yours. Review the changes again.',
      ),
      findsOneWidget,
    );
    // The form is kept, Saturday still closed, ready to review again.
    expect(find.byKey(const Key('hours-bulk-review')), findsOneWidget);
    expect(
      tester
          .widget<SegmentedButton<Object>>(
            find.byKey(const Key('hours-bulk-act-5')),
          )
          .selected
          .single
          .toString(),
      endsWith('closed'),
    );
  });

  testWidgets('on a phone every day is a card and nothing overflows', (
    tester,
  ) async {
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

    await pump(tester, size: const Size(360, 780));
    await tap(tester, segment(0, 'Hours'));
    await tap(tester, find.byKey(const Key('hours-bulk-open-0')));
    await tap(tester, find.text('OK'));
    await tap(tester, find.byKey(const Key('hours-bulk-review')));

    FlutterError.onError = previous;

    // The review on a phone is one card per website, no tabs.
    expect(
      find.byKey(const ValueKey('hours-bulk-place-site-a')),
      findsOneWidget,
    );
    expect(overflows, isEmpty);
  });

  // Jose, the day it shipped as a pushed page: "u broke the containment rule".
  testWidgets('it is a pane in the tab, with its own way back', (tester) async {
    var closed = false;
    await pump(tester, onClose: () => closed = true);

    expect(find.byType(Scaffold), findsOneWidget);
    expect(find.byType(AppBar), findsNothing);

    await tap(tester, find.byTooltip('Back to the website'));
    expect(closed, isTrue);
  });

  // "a1chimney has like 50 websites u cant expect a person to click 50 times"
  testWidgets('Select all picks every website, and a search narrows it', (
    tester,
  ) async {
    final many = [
      for (var i = 0; i < 11; i++)
        {'id': 'chim-$i', 'host': 'c$i.a-1chimney.com', 'is_primary': i == 0},
      for (var i = 0; i < 3; i++)
        {'id': 'roof-$i', 'host': 'r$i.roofirst.com', 'is_primary': false},
    ];
    final posted = await pump(tester, sites: many);

    await tap(tester, find.byKey(const Key('hours-bulk-select-all')));
    expect(find.text('14 of 14 selected'), findsOneWidget);

    await tap(tester, find.byKey(const Key('hours-bulk-clear')));
    expect(find.text('0 of 14 selected'), findsOneWidget);

    // One brand: find it, then select what is shown and nothing else.
    await tester.enterText(find.byKey(const Key('hours-bulk-find')), 'roof');
    await tester.pumpAndSettle();
    expect(find.text('Select the 3 shown'), findsOneWidget);
    await tap(tester, find.byKey(const Key('hours-bulk-select-all')));
    expect(find.text('3 of 14 selected'), findsOneWidget);

    await tap(tester, segment(5, 'Closed all day'));
    await tap(tester, find.byKey(const Key('hours-bulk-review')));
    expect((posted['/web/hours/review']!['site_ids'] as List).toSet(), {
      'roof-0',
      'roof-1',
      'roof-2',
    });
  });
}
