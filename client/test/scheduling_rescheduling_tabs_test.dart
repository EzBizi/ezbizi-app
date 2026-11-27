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

/// Scheduling › Re-Scheduling (Jose, 2026-11-09): one tab, sub-tabs
/// Overbooking, Underbooking (booked vs available) and AI suggestions, all on
/// `scheduling.rescheduling.view`. There is no top-level Overbooking tab.
void main() {
  const grant = ['scheduling.view', 'scheduling.rescheduling.view'];

  tearDown(() => DeepLinks.pending.value = null);

  testWidgets(
    'one Re-Scheduling tab: Overbooking, Underbooking, AI suggestions',
    (tester) async {
      final gets = await _pump(tester, grant);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('tour-scheduling-tabs')),
          matching: find.text('Overbooking'),
        ),
        findsNothing,
        reason: 'no top-level tab',
      );
      await tester.tap(find.text('Re-Scheduling'));
      await tester.pumpAndSettle();

      final sub = find.byKey(
        const ValueKey('tour-scheduling-rescheduling-segments'),
      );
      for (final label in ['Overbooking', 'Underbooking', 'AI suggestions']) {
        expect(
          find.descendant(of: sub, matching: find.text(label)),
          findsOneWidget,
        );
      }
      expect(
        gets.any((u) => u.path.endsWith('/overbooking/fill')),
        isTrue,
        reason:
            'Overbooking is the same per-day list, in Over mode (2026-11-12)',
      );

      await tester.tap(find.text('Underbooking'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('tour-booked-day')), findsOneWidget);
      expect(gets.any((u) => u.path.endsWith('/overbooking/fill')), isTrue);

      await tester.tap(find.text('AI suggestions'));
      await tester.pumpAndSettle();
      expect(find.byType(ReschedulingPane), findsOneWidget);
    },
  );

  testWidgets(
    'the old /scheduling/overbooking?date= link opens Re-Scheduling > '
    'Underbooking on that day',
    (tester) async {
      DeepLinks.open(
        DeepLinks.fromUri(
          Uri.parse(
            'https://app.ezbizi.com/scheduling/overbooking?date=2026-11-02',
          ),
        )!,
      );
      final gets = await _pump(tester, grant);

      expect(
        gets
            .where((u) => u.path.endsWith('/overbooking/fill'))
            .map((u) => u.queryParameters['date']),
        contains('2026-11-02'),
      );
      expect(find.text('Mon Nov 2'), findsOneWidget);
    },
  );

  testWidgets('without the grant there is no Re-Scheduling', (tester) async {
    final gets = await _pump(tester, const [
      'scheduling.view',
      'scheduling.availability.view',
    ]);

    expect(find.text('Re-Scheduling'), findsNothing);
    expect(gets.any((u) => u.path.contains('/overbooking')), isFalse);
  });

  testWidgets('phone 390x844: every sub-tab, no overflow', (tester) async {
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
    await _pump(tester, grant, size: const Size(390, 844));
    for (final label in ['Overbooking', 'AI suggestions']) {
      await tester.ensureVisible(find.text(label));
      await tester.pumpAndSettle();
      await tester.tap(find.text(label));
      await tester.pumpAndSettle();
    }
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
