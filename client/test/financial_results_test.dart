import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/financials/financial_results.dart';
import 'package:ezbizi_client/screens/financials_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A 1×1 PNG, so Image.memory has something real to decode.
final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
);

const _all = [
  'financials.view',
  'financial_results.view',
  'financial_results.create',
  'financial_results.edit',
  'financial_results.delete',
  'financial_results.approve',
];

final _rows = [
  {
    'id': 'r1',
    'company_id': 'co-1',
    'franchise_id': 'f-1',
    'place': 'NJ',
    'source': 'ACE',
    'date': '2026-10-20',
    'area': 'Bergen',
    'calls': 12,
    'jobs': 5,
    'cost_cents': 45000,
    'sales_cents': 210050,
    'profit_cents': 165050,
    'has_image': true,
    'channel': 'whatsapp',
    'status': 'pending',
    'approved_at': null,
  },
  {
    'id': 'r2',
    'company_id': 'co-1',
    'franchise_id': null,
    'place': 'Everdoors',
    'source': 'Angi',
    'date': '2026-10-19',
    'area': 'Essex',
    'calls': 3,
    'jobs': 1,
    'cost_cents': 1000,
    'sales_cents': 5000,
    'profit_cents': 4000,
    'has_image': false,
    'channel': 'app',
    'status': 'approved',
    'approved_at': '2026-10-20T10:00:00Z',
  },
];

http.Response _json(Object? body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

Future<List<http.BaseRequest>> _pump(
  WidgetTester tester, {
  List<String> permissions = _all,
  Size size = const Size(1500, 1000),
  Future<PickedBytes?> Function()? pick,
  bool host = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final seen = <http.BaseRequest>[];
  final api = ApiClient(
    client: MockClient((request) async {
      seen.add(request);
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Jose',
            'email': 'jose@example.com',
            'role': {'name': 'Administrator'},
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
              {
                'id': 'f-1',
                'name': 'NJ',
                'company_id': 'co-1',
                'is_active': true,
              },
            ],
            'modules': [
              {
                'key': 'financials',
                'label': 'Financials',
                'available': true,
                'reachable': true,
              },
              {
                'key': 'financial_results',
                'label': 'Lead source results',
                'parent': 'financials',
                'available': true,
                'reachable': true,
              },
            ],
            'permissions': permissions,
          },
        });
      }
      if (path.endsWith('/financials/results/read')) {
        return _json({
          'data': {
            'source': 'ACE',
            'rows': [
              {
                'date': '2026-10-21',
                'area': 'Bergen',
                'calls': 4,
                'jobs': 2,
                'cost_cents': 10000,
                'sales_cents': 30000,
                'profit_cents': 20000,
              },
              {
                'date': '2026-10-21',
                'area': 'Wrong',
                'calls': 1,
                'jobs': 0,
                'cost_cents': 0,
                'sales_cents': 0,
                'profit_cents': 0,
              },
            ],
            'image_token': 'tok-123',
          },
        });
      }
      if (path.endsWith('/image')) {
        return http.Response.bytes(
          _png,
          200,
          headers: const {'content-type': 'image/png'},
        );
      }
      if (path.endsWith('/financials/results') && request.method == 'GET') {
        return _json({'data': _rows});
      }
      if (request.method == 'DELETE') return _json(null, 204);
      if (request.method == 'POST' || request.method == 'PATCH') {
        return _json({'data': _rows.first}, 201);
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: host
            ? FinancialsScreen(api: api, session: session)
            : FinancialResultsTab(
                api: api,
                me: session.me!,
                pick: pick,
                now: DateTime(2026, 10, 27),
              ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return seen;
}

Iterable<http.Request> _calls(List<http.BaseRequest> seen, String method) =>
    seen.whereType<http.Request>().where(
      (r) => r.method == method && !r.url.path.endsWith('/me'),
    );

Future<void> _pick(WidgetTester tester, String key, String label) async {
  await tester.tap(find.byKey(ValueKey(key)));
  await tester.pumpAndSettle();
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('Financials draws Lead source results as a tab', (tester) async {
    await _pump(tester, host: true);
    expect(find.text('Lead source results'), findsWidgets);
  });

  testWidgets('the list shows each field, and pending rows are marked', (
    tester,
  ) async {
    await _pump(tester);
    for (final t in [
      '2026-10-20',
      'NJ',
      'ACE',
      'Bergen',
      '12',
      r'$450.00',
      r'$2,100.50',
      r'$1,650.50',
      'WhatsApp',
      'App',
      'Pending',
      'Approved',
    ]) {
      expect(find.text(t), findsWidgets, reason: t);
    }
    final pending = tester.widget<Material>(
      find.byKey(const ValueKey('result-row-r1')),
    );
    final approved = tester.widget<Material>(
      find.byKey(const ValueKey('result-row-r2')),
    );
    expect(pending.color, isNot(approved.color));
    expect(approved.color, Colors.transparent);
  });

  testWidgets('the filters send the right query', (tester) async {
    final seen = await _pump(tester);
    await _pick(tester, 'results-status', 'Pending');
    await _pick(tester, 'results-source', 'ACE');
    await _pick(tester, 'results-range', 'This month');
    final q = _calls(seen, 'GET').last.url.queryParameters;
    expect(q, {
      'status': 'pending',
      'source': 'ACE',
      'from': '2026-10-01',
      'to': '2026-10-27',
    });
  });

  testWidgets('Approve shows only with approve, and calls the endpoint', (
    tester,
  ) async {
    var seen = await _pump(
      tester,
      permissions: _all.where((p) => !p.endsWith('.approve')).toList(),
    );
    expect(find.byKey(const ValueKey('result-approve-r1')), findsNothing);

    seen = await _pump(tester);
    expect(find.byKey(const ValueKey('result-approve-r1')), findsOneWidget);
    expect(find.byKey(const ValueKey('result-approve-r2')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('result-approve-r1')));
    await tester.pumpAndSettle();
    expect(
      _calls(seen, 'POST').map((r) => r.url.path),
      contains(endsWith('/financials/results/r1/approve')),
    );
  });

  testWidgets('add posts the figures in cents', (tester) async {
    final seen = await _pump(tester);
    await tester.tap(find.byKey(const Key('results-add')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('result-source')), 'ACE');
    await tester.enterText(find.byKey(const Key('result-date')), '2026-10-22');
    await tester.enterText(find.byKey(const Key('result-area')), 'Bergen');
    await tester.enterText(find.byKey(const Key('result-calls')), '7');
    await tester.enterText(find.byKey(const Key('result-jobs')), '3');
    await tester.enterText(find.byKey(const Key('result-cost')), '120.50');
    await tester.enterText(find.byKey(const Key('result-sales')), '1,000');
    await tester.tap(find.byKey(const Key('result-panel-save')));
    await tester.pumpAndSettle();
    final body =
        jsonDecode(_calls(seen, 'POST').single.body) as Map<String, dynamic>;
    expect(body, {
      'franchise_id': null,
      'company_id': null,
      'source': 'ACE',
      'date': '2026-10-22',
      'area': 'Bergen',
      'calls': 7,
      'jobs': 3,
      'cost_cents': 12050,
      'sales_cents': 100000,
    });
  });

  testWidgets('read from photo shows the rows to check, and saves each with '
      'the image token', (tester) async {
    final seen = await _pump(
      tester,
      pick: () async => PickedBytes('ace.png', _png),
    );
    await tester.tap(find.byKey(const Key('results-read')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('review-image')), findsOneWidget);
    expect(find.byKey(const ValueKey('review-row-0')), findsOneWidget);
    expect(find.byKey(const ValueKey('review-row-1')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('review-remove-1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('review-calls-0')), '5');
    await tester.tap(find.byKey(const Key('review-save')));
    await tester.pumpAndSettle();
    final posts = _calls(
      seen,
      'POST',
    ).where((r) => r.url.path.endsWith('/financials/results')).toList();
    expect(posts, hasLength(1));
    final body = jsonDecode(posts.single.body) as Map<String, dynamic>;
    expect(body['image_token'], 'tok-123');
    expect(body['source'], 'ACE');
    expect(body['calls'], 5);
    expect(body['cost_cents'], 10000);
    expect(body['profit_cents'], 20000);
    expect(find.byKey(const Key('review-save')), findsNothing);
  });

  testWidgets('a row with a photo shows it in the editor', (tester) async {
    final seen = await _pump(tester);
    await tester.tap(find.text('Bergen'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('result-image')), findsOneWidget);
    expect(
      seen.map((r) => r.url.path),
      contains(endsWith('/financials/results/r1/image')),
    );
  });

  testWidgets('delete asks twice, then deletes', (tester) async {
    final seen = await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('result-delete-r2')));
    await tester.pumpAndSettle();
    expect(_calls(seen, 'DELETE'), isEmpty);
    await tester.tap(find.byKey(const ValueKey('result-delete-confirm-r2')));
    await tester.pumpAndSettle();
    expect(
      _calls(seen, 'DELETE').single.url.path,
      endsWith('/financials/results/r2'),
    );
  });

  testWidgets('a phone draws cards without overflow, the editor full screen', (
    tester,
  ) async {
    await _pump(tester, size: const Size(390, 844));
    expect(tester.takeException(), isNull);
    expect(find.byType(Card), findsNWidgets(2));
    await tester.tap(find.text('ACE · 2026-10-20'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(
      tester.getSize(find.byKey(const Key('result-source'))).width,
      greaterThan(300),
    );
  });
}
