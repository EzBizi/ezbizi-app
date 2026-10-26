import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/booked_available_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Re-Scheduling › Rescheduling Availability (UI queue 147, 1 and 4).
void main() {
  testWidgets('rows sort over → open → full, with Company · Branch and chips', (
    tester,
  ) async {
    final log = _Log();
    await _pump(tester, log);

    // Tomorrow by default.
    final tomorrow = DateTime.now().add(const Duration(days: 1));
    expect(
      log.gets.first.queryParameters['date'],
      '${tomorrow.year}-${tomorrow.month.toString().padLeft(2, '0')}-'
      '${tomorrow.day.toString().padLeft(2, '0')}',
    );

    final order = [
      for (final id in ['a-full', 'a-open', 'a-over'])
        (id, tester.getTopLeft(find.byKey(ValueKey('booked-row-$id'))).dy),
    ]..sort((a, b) => a.$2.compareTo(b.$2));
    expect(order.map((e) => e.$1), ['a-over', 'a-open', 'a-full']);

    expect(find.text('Brightside · Springfield'), findsOneWidget);
    expect(find.text('12 / 10'), findsOneWidget);
    expect(find.text('2 over'), findsOneWidget);
    expect(find.text('4 open'), findsOneWidget);
    expect(find.text('Full'), findsOneWidget);
    // Summary: 1 area over, 4 open spots, 1 full.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('booked-stat-open')),
        matching: find.text('4'),
      ),
      findsOneWidget,
    );
    // Offer only where there are open spots.
    expect(find.byKey(const ValueKey('booked-offer-a-open')), findsOneWidget);
    expect(find.byKey(const ValueKey('booked-offer-a-over')), findsNothing);
    expect(find.byKey(const ValueKey('booked-offer-a-full')), findsNothing);
    // Window blocks by taken vs capacity.
    expect(
      find.byKey(const ValueKey('booked-window-a-over-08:00:00-bad')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('booked-window-a-open-08:00:00-ok')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('booked-window-a-full-08:00:00-neutral')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('booked-today')));
    await tester.pumpAndSettle();
    expect(log.gets.last.queryParameters['date'], '2026-10-26');
    await tester.tap(find.byKey(const Key('booked-day-next')));
    await tester.pumpAndSettle();
    expect(log.gets.last.queryParameters['date'], '2026-10-27');
  });

  testWidgets('no Offer button without scheduling.edit', (tester) async {
    await _pump(tester, _Log(), canEdit: false);
    expect(find.byKey(const ValueKey('booked-offer-a-open')), findsNothing);
  });

  testWidgets('search narrows by area or branch', (tester) async {
    await _pump(tester, _Log());
    await tester.enterText(
      find.byKey(const ValueKey('booked-available-search')),
      'riverton',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('booked-row-a-open')), findsOneWidget);
    expect(find.byKey(const ValueKey('booked-row-a-over')), findsNothing);
    expect(find.byKey(const ValueKey('booked-row-a-full')), findsNothing);
  });

  testWidgets('the overflow segment is painted, in the error tone', (
    tester,
  ) async {
    await _pump(tester, _Log());
    final track = tester.getSize(
      find.byKey(const ValueKey('booked-bar-a-over')),
    );
    final over = find.byKey(const ValueKey('booked-bar-a-over-over'));
    final booked = find.byKey(const ValueKey('booked-bar-a-over-booked'));
    expect(tester.getSize(over).height, track.height);
    expect(tester.getSize(over).height, greaterThan(0));
    expect(tester.getSize(booked).height, track.height);
    // 10 within, 2 over: the overflow is a sixth of the bar.
    expect(tester.getSize(over).width, closeTo(track.width / 6, 1));
    final ctx = tester.element(over);
    expect(
      tester.widget<ColoredBox>(over).color,
      ToneColours.of(ctx, Tone.bad).ink,
    );
    // A row with room draws no overflow.
    expect(find.byKey(const ValueKey('booked-bar-a-open-over')), findsNothing);
  });

  testWidgets('a row click lists that area\'s jobs for the day', (
    tester,
  ) async {
    final log = _Log();
    await _pump(tester, log);
    await tester.tap(find.text('North Springfield'));
    await tester.pumpAndSettle();
    final ask = log.gets.last;
    expect(ask.path, endsWith('/scheduling/overbooking/jobs'));
    expect(ask.queryParameters, {'date': log.day, 'area_id': 'a-over'});
    expect(find.textContaining('Jane Roe'), findsOneWidget);
  });

  testWidgets('preview → send: bodies, the max_sends cap, failures', (
    tester,
  ) async {
    final log = _Log();
    await _pump(tester, log);
    await tester.tap(find.byKey(const ValueKey('booked-offer-a-open')));
    await tester.pumpAndSettle();

    expect(log.posts.first.$1, endsWith('/offers/preview'));
    expect(log.posts.first.$2, {'area_id': 'a-open', 'date': log.day});
    expect(find.text('10am–12pm · 2 left'), findsOneWidget);
    expect(find.textContaining('Reply YES'), findsOneWidget);

    // max_sends 2: the first two ticked, the third cannot be ticked.
    bool ticked(String id) => tester
        .widget<CheckboxListTile>(find.byKey(ValueKey('booked-candidate-$id')))
        .value!;
    expect(ticked('j-1'), isTrue);
    expect(ticked('j-2'), isTrue);
    expect(ticked('j-3'), isFalse);
    await tester.tap(find.byKey(const ValueKey('booked-candidate-j-3')));
    await tester.pumpAndSettle();
    expect(ticked('j-3'), isFalse, reason: 'capped at max_sends');
    // Untick one, and the third may be ticked in its place.
    await tester.tap(find.byKey(const ValueKey('booked-candidate-j-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('booked-candidate-j-3')));
    await tester.pumpAndSettle();
    expect(ticked('j-3'), isTrue);
    expect(find.text('Send to 2'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('booked-offer-send')));
    await tester.pumpAndSettle();
    expect(log.posts.last.$1, endsWith('/offers/send'));
    expect(log.posts.last.$2, {
      'area_id': 'a-open',
      'date': log.day,
      'job_ids': ['j-2', 'j-3'],
    });
    expect(find.text('Sent 1 text.'), findsOneWidget);
    expect(find.text('Rae Moss: No mobile number on file'), findsOneWidget);
    expect(find.text('Replies land in Leads as "Reschedule".'), findsOneWidget);
  });

  testWidgets('outside the send hours the 422 sentence shows and Send is off', (
    tester,
  ) async {
    final log = _Log()..outOfHours = true;
    await _pump(tester, log);
    await tester.tap(find.byKey(const ValueKey('booked-offer-a-open')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('booked-offer-send')));
    await tester.pumpAndSettle();
    expect(find.text('Texts go out between 9:00 and 20:00.'), findsOneWidget);
    expect(
      tester
          .widget<ButtonStyleButton>(
            find.byKey(const ValueKey('booked-offer-send')),
          )
          .onPressed,
      isNull,
    );
  });

  test('the email link opens Booked vs available on its day', () {
    final link = DeepLinks.fromUri(
      Uri.parse(
        'https://app.ezbizi.com/scheduling/overbooking?date=2026-10-27',
      ),
    )!;
    expect(link.module, 'scheduling');
    expect(link.type, 'booked_vs_available');
    expect(link.date, '2026-10-27');
    expect(
      DeepLinks.fromUri(Uri.parse('https://app.ezbizi.com/scheduling')),
      isNull,
    );
    expect(
      DeepLinks.fromUri(
        Uri.parse('https://app.ezbizi.com/scheduling/overbooking?date=bad'),
      )!.date,
      isNull,
    );
  });

  testWidgets('a linked date opens the pane on that day', (tester) async {
    final log = _Log();
    tester.view.physicalSize = const Size(1200, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: BookedAvailablePane(
            api: log.api(),
            visible: true,
            canEdit: true,
            date: '2026-11-02',
            dateSerial: 1,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(
      log.gets.where((u) => u.path.endsWith('/fill')).single.queryParameters,
      {'date': '2026-11-02'},
    );
    expect(find.text('Mon Nov 2'), findsOneWidget);
  });

  testWidgets(
    'phone 390×844: cards, offer full screen with back, no overflow',
    (tester) async {
      final errors = <FlutterErrorDetails>[];
      final previous = FlutterError.onError;
      FlutterError.onError = errors.add;
      addTearDown(() => FlutterError.onError = previous);

      await _pump(tester, _Log(), size: const Size(390, 844));
      expect(find.text('AREA'), findsNothing, reason: 'cards, not a table');
      await tester.tap(find.byKey(const ValueKey('booked-offer-a-open')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('booked-offer-full')), findsOneWidget);
      expect(find.byKey(const ValueKey('booked-row-a-over')), findsNothing);
      await tester.tap(find.byKey(const Key('booked-offer-back')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('booked-row-a-over')), findsOneWidget);
      expect(
        errors,
        isEmpty,
        reason: errors.map((e) => e.exceptionAsString()).join(' | '),
      );
    },
  );
}

class _Log {
  final gets = <Uri>[];
  final posts = <(String, Map<String, dynamic>)>[];
  bool outOfHours = false;

  String get day =>
      gets.lastWhere((u) => u.path.endsWith('/fill')).queryParameters['date']!;

  ApiClient api() => ApiClient(
    client: MockClient((request) async {
      http.Response json(Object body, [int status = 200]) => http.Response(
        jsonEncode(body),
        status,
        headers: const {'content-type': 'application/json'},
      );
      final path = request.url.path;
      if (request.method == 'GET') {
        gets.add(request.url);
        if (path.endsWith('/overbooking/jobs')) {
          return json({
            'data': [
              {
                'time': '09:00',
                'service': 'Chimney Sweep',
                'status': 'Submitted',
                'customer': {'name': 'Jane Roe', 'phone': '5550100'},
              },
            ],
          });
        }
        return json({
          'data': {
            'date': request.url.queryParameters['date'],
            'today': '2026-10-26',
            'areas': _areas,
          },
        });
      }
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      posts.add((path, body));
      if (path.endsWith('/offers/preview')) {
        return json({
          'data': {
            'open': [
              {'start_time': '10:00', 'end_time': '12:00', 'remaining': 2},
            ],
            'candidates': [
              _candidate('j-1', 'Sam Ortiz'),
              _candidate('j-2', 'Pat Lin'),
              _candidate('j-3', 'Rae Moss'),
            ],
            'max_sends': 2,
            'message': 'Hi, we have an opening tomorrow. Reply YES to move.',
          },
        });
      }
      if (outOfHours) {
        return json({'message': 'Texts go out between 9:00 and 20:00.'}, 422);
      }
      return json({
        'data': {
          'sent': ['j-2'],
          'failed': [
            {'job_id': 'j-3', 'reason': 'No mobile number on file'},
          ],
        },
      });
    }),
  );
}

Map<String, dynamic> _candidate(String id, String name) => {
  'job_id': id,
  'scheduled_for': '2026-11-05 10:00:00',
  'customer_name': name,
  'phone': '(555) 010-0142',
};

Map<String, dynamic> _area(
  String id,
  String name,
  String branch,
  int capacity,
  int booked,
  int taken,
) => {
  'area_id': id,
  'name': name,
  'franchise_id': 'f-$branch',
  'franchise_name': branch,
  'company_id': 'c-1',
  'company_name': 'Brightside',
  'capacity': capacity,
  'booked': booked,
  'gap': capacity - booked,
  'fill_pct': capacity == 0 ? 0 : booked * 100 ~/ capacity,
  'windows': [
    {
      'start_time': '08:00:00',
      'end_time': '10:00:00',
      'capacity': 2,
      'taken': taken,
    },
  ],
};

final _areas = [
  _area('a-full', 'Hill Top', 'Hartford', 5, 5, 2),
  _area('a-open', 'Riverton East', 'Riverton', 10, 6, 1),
  _area('a-over', 'North Springfield', 'Springfield', 10, 12, 3),
];

Future<void> _pump(
  WidgetTester tester,
  _Log log, {
  Size size = const Size(1200, 1400),
  bool canEdit = true,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: BookedAvailablePane(
          api: log.api(),
          visible: true,
          canEdit: canEdit,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
