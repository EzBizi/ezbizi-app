import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customer_experience/ekomi_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Customer Experience › eKomi (UI queue 165, option 1). Every name and
/// number here is invented.
void main() {
  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json'},
  );

  final calls = <String, Map<String, String>>{};
  final bodies = <String, Map<String, dynamic>>{};

  Future<void> pump(
    WidgetTester tester, {
    Size size = const Size(1200, 900),
  }) async {
    calls.clear();
    bodies.clear();
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        calls['${request.method} $path'] = request.url.queryParameters;
        if (request.body.isNotEmpty) {
          bodies['${request.method} $path'] =
              jsonDecode(request.body) as Map<String, dynamic>;
        }
        return switch ('${request.method} $path') {
          'GET /ekomi' => json({
            'data': [
              {
                'id': 'ek1',
                'name': 'Brightside',
                'shop_id': '81234',
                'status': 'connected',
                'settings': {
                  'auto_request': false,
                  'delay_hours': 24,
                  'start_date': null,
                },
              },
            ],
          }),
          'GET /ekomi/ek1/overview' => json({
            'data': {
              'snapshot': {
                'account_name': 'Brightside',
                'review_count': 397,
                'average': 5.0,
                'active': true,
                'certificate_url': 'https://example.test/cert',
              },
              'funnel': {
                'requested': 142,
                'delivered': 134,
                'opened': 87,
                'clicked': 47,
                'reviewed': 30,
                'bounced': 8,
                'rates': {
                  'delivered': 94,
                  'open': 65,
                  'click': 54,
                  'review': 21,
                  'bounce': 6,
                },
              },
            },
          }),
          'GET /ekomi/ek1/requests' => json({
            'data': [
              {
                'name': 'Dana Whitfield',
                'email': 'dana@brightside.example',
                'order_id': 'JOB-1042',
                'channel': 'email',
                'status': 'interrupted',
                'bounce_reason': 'mailbox full',
                'created_at': '2026-10-28T12:00:00Z',
              },
            ],
          }),
          'GET /reviews' => json({
            'data': [
              {
                'id': 'r1',
                'source': 'ekomi',
                'rating': 5,
                'reviewer_name': 'Pat Lorne',
                'comment': 'Quick and tidy',
              },
              {
                'id': 'r2',
                'source': 'google',
                'rating': 4,
                'reviewer_name': 'Sam Ortiz',
                'comment': 'Fine',
              },
            ],
          }),
          'PUT /ekomi/ek1/settings' => json({
            'data': {
              'auto_request': true,
              'delay_hours': 48,
              'start_date': '2026-11-02',
            },
          }),
          'GET /me' => json({
            'data': {
              'id': 'u',
              'name': 'M',
              'permissions': <String>[],
              'modules': <Object>[],
            },
          }),
          _ => json({'message': 'Unexpected $path'}, 500),
        };
      }),
    );
    final session = Session(api);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: EkomiView(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> tab(WidgetTester tester, String label) async {
    await tester.tap(find.text(label));
    await tester.pumpAndSettle();
  }

  testWidgets('overview: the snapshot and the funnel with rates', (
    tester,
  ) async {
    await pump(tester);
    expect(calls['GET /ekomi/ek1/overview']?.keys, containsAll(['from', 'to']));
    expect(find.text('397'), findsOneWidget);
    expect(find.text('5.0 ★'), findsOneWidget);
    expect(find.text('30 · 21%'), findsOneWidget);
    expect(find.text('8 · 6%'), findsOneWidget);
  });

  testWidgets('requests: filters go to the server; a bounce shows why', (
    tester,
  ) async {
    await pump(tester);
    await tab(tester, 'Requests');
    expect(find.text('Interrupted · mailbox full'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('ekomi-status-completed')));
    await tester.pumpAndSettle();
    expect(calls['GET /ekomi/ek1/requests']?['status'], 'completed');
  });

  testWidgets('reviews: only eKomi ones', (tester) async {
    await pump(tester);
    await tab(tester, 'Reviews');
    expect(calls['GET /reviews']?['source'], 'ekomi');
    expect(find.textContaining('Pat Lorne'), findsOneWidget);
    expect(find.textContaining('Sam Ortiz'), findsNothing);
  });

  testWidgets('settings: a blank password is not sent', (tester) async {
    await pump(tester);
    await tab(tester, 'Settings');
    await tester.tap(find.byKey(const ValueKey('ekomi-auto')));
    await tester.enterText(find.byKey(const ValueKey('ekomi-delay')), '48');
    await tester.tap(find.byKey(const ValueKey('ekomi-save')));
    await tester.pumpAndSettle();
    final sent = bodies['PUT /ekomi/ek1/settings']!;
    expect(sent.containsKey('password'), isFalse);
    expect(sent['auto_request'], isTrue);
    expect(sent['delay_hours'], 48);
    expect(find.text('Only jobs Done on or after 2026-11-02.'), findsOneWidget);
  });

  testWidgets('a phone: every tab fits', (tester) async {
    await pump(tester, size: const Size(375, 812));
    for (final t in ['Requests', 'Reviews', 'Settings']) {
      await tab(tester, t);
    }
    expect(tester.takeException(), isNull);
  });
}
