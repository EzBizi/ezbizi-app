import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/rescheduling_pane.dart';
import 'package:ezbizi_client/screens/scheduling_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Re-Scheduling holds Rescheduling Availability (Booked vs
/// available) and AI Rescheduling Suggestion; Overbooking is overbooked days
/// only (Jose, 2026-10-26).
void main() {
  const both = [
    'scheduling.view',
    'scheduling.overbooking.view',
    'scheduling.rescheduling.view',
  ];

  tearDown(() => DeepLinks.pending.value = null);

  testWidgets('Re-Scheduling draws both sub-tabs, Availability first', (
    tester,
  ) async {
    final gets = await _pump(tester, both);
    await tester.tap(find.text('Re-Scheduling'));
    await tester.pumpAndSettle();

    expect(find.text('Rescheduling Availability'), findsOneWidget);
    expect(find.text('AI Rescheduling Suggestion'), findsOneWidget);
    expect(find.byKey(const ValueKey('tour-booked-day')), findsOneWidget);
    expect(gets.any((u) => u.path.endsWith('/overbooking/fill')), isTrue);

    await tester.tap(find.text('AI Rescheduling Suggestion'));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('tour-booked-day')).hitTestable(),
      findsNothing,
    );
    expect(find.byType(ReschedulingPane), findsOneWidget);
  });

  testWidgets('Overbooking has no segment: overbooked days only', (
    tester,
  ) async {
    final gets = await _pump(tester, both);
    await tester.tap(find.text('Overbooking').first);
    await tester.pumpAndSettle();

    expect(find.text('Booked vs available'), findsNothing);
    expect(find.text('Overbooked days'), findsNothing);
    expect(
      find.byKey(const ValueKey('tour-scheduling-overbooking-segments')),
      findsNothing,
    );
    expect(find.text('Rescheduling Availability'), findsNothing);
    expect(gets.any((u) => u.path.endsWith('/overbooking/fill')), isFalse);
  });

  testWidgets(
    'the old /scheduling/overbooking?date= link opens Re-Scheduling > '
    'Rescheduling Availability on that day',
    (tester) async {
      DeepLinks.open(
        DeepLinks.fromUri(
          Uri.parse(
            'https://app.ezbizi.com/scheduling/overbooking?date=2026-11-02',
          ),
        )!,
      );
      final gets = await _pump(tester, both);

      expect(find.text('Rescheduling Availability'), findsOneWidget);
      expect(
        gets
            .where((u) => u.path.endsWith('/overbooking/fill'))
            .single
            .queryParameters,
        {'date': '2026-11-02'},
      );
      expect(find.text('Mon Nov 2'), findsOneWidget);
    },
  );

  testWidgets(
    'without scheduling.overbooking.view the Availability sub-tab is hidden',
    (tester) async {
      final gets = await _pump(tester, const [
        'scheduling.view',
        'scheduling.rescheduling.view',
      ]);

      expect(find.text('Re-Scheduling'), findsOneWidget);
      expect(find.text('Rescheduling Availability'), findsNothing);
      expect(find.text('AI Rescheduling Suggestion'), findsNothing);
      expect(find.byType(ReschedulingPane), findsOneWidget);
      expect(gets.any((u) => u.path.endsWith('/overbooking/fill')), isFalse);
    },
  );

  testWidgets('phone 390x844: both sub-tabs, no overflow', (tester) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    DeepLinks.open(
      DeepLinks.fromUri(
        Uri.parse(
          'https://app.ezbizi.com/scheduling/overbooking?date=2026-11-02',
        ),
      )!,
    );
    await _pump(tester, both, size: const Size(390, 844));
    expect(find.text('Rescheduling Availability'), findsOneWidget);
    await tester.ensureVisible(find.text('AI Rescheduling Suggestion'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('AI Rescheduling Suggestion'));
    await tester.pumpAndSettle();
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
}

Future<List<Uri>> _pump(
  WidgetTester tester,
  List<String> permissions, {
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final gets = <Uri>[];

  final api = ApiClient(
    client: MockClient((request) async {
      gets.add(request.url);
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      if (path.endsWith('/overbooking/fill')) {
        return _json({
          'data': {
            'date': request.url.queryParameters['date'],
            'today': '2026-10-26',
            'areas': const [],
          },
        });
      }
      if (path.endsWith('/scheduling/overbooking')) {
        return _json({'data': null});
      }
      return _json({'data': const [], 'pattern': null});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SchedulingScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return gets;
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
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
