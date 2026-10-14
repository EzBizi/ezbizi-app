import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/sales_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  _paging();
  testWidgets('desktop: KPI strip totals open deals and sold ones are filtered out', (tester) async {
    final puts = <Map<String, dynamic>>[];
    await _pump(tester, const Size(1400, 900), puts);

    expect(find.text('\$15,650'), findsOneWidget, reason: 'pipeline = 13,500 + 2,150 open');
    expect(find.text('Artyom Popov'), findsOneWidget);
    expect(find.text('Ricardo Murph'), findsNothing, reason: 'Sold is not in the Open filter');

    await tester.tap(find.widgetWithText(ChoiceChip, 'Sold'));
    await tester.pumpAndSettle();
    expect(find.text('Ricardo Murph'), findsOneWidget);
  });

  testWidgets('changing the stage PUTs it', (tester) async {
    final puts = <Map<String, dynamic>>[];
    await _pump(tester, const Size(1400, 900), puts);

    await tester.tap(find.descendant(of: find.byType(DataTable), matching: find.text('Call back')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sold').last);
    await tester.pumpAndSettle();

    expect(puts, [
      {'stage': 'sold'},
    ]);
  });

  testWidgets('mobile: cards with call and text', (tester) async {
    await _pump(tester, const Size(390, 844), []);

    expect(find.byType(DataTable), findsNothing);
    await tester.tap(find.text('Artyom Popov'));
    await tester.pumpAndSettle();
    expect(find.text('Call'), findsOneWidget);
    expect(find.text('Text'), findsOneWidget);
  });
}

void _paging() {
  testWidgets('a long list draws 50 rows and offers the rest', (tester) async {
    await _pump(tester, const Size(1400, 900), [], extra: 60);

    expect(find.textContaining('Bulk '), findsNWidgets(48), reason: '2 open seeded deals + 48 bulk = 50');
    await tester.scrollUntilVisible(find.text('Show more (12 left)'), 500, scrollable: find.byType(Scrollable).first);
    await tester.tap(find.text('Show more (12 left)'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Bulk '), findsNWidgets(60));
  });
}

Future<void> _pump(WidgetTester tester, Size size, List<Map<String, dynamic>> puts, {int extra = 0}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Jay',
            'email': 'jay@example.com',
            'has_avatar': false,
            'role': {'id': 'role-1', 'name': 'Sales'},
            'permissions': ['sales.view', 'sales.manage'],
            'modules': [
              {'key': 'sales', 'label': 'Sales', 'available': true, 'reachable': true},
            ],
            'preferences': <String, dynamic>{},
            'organization': {'id': 'org-1', 'name': 'A-1', 'franchise_label': 'Franchise', 'is_read_only': false},
            'scope': {'type': 'organization', 'id': 'org-1', 'label': 'A-1', 'path': ['A-1']},
            'companies': <Map<String, Object?>>[],
            'franchises': <Map<String, Object?>>[],
          },
        });
      }
      if (request.method == 'PUT') {
        puts.add((jsonDecode(request.body) as Map).cast<String, dynamic>());
        return _json({'data': {}});
      }
      return _json({
        'data': [
          _deal('j1', 'Artyom Popov', 1350000, 'follow_up', next: '2000-01-01'),
          _deal('j2', 'Mike Modabber', 215000, 'call_back'),
          _deal('j3', 'Ricardo Murph', 415000, 'sold'),
          for (var i = 0; i < extra; i++) _deal('x$i', 'Bulk $i', 100, 'follow_up'),
        ],
      });
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SalesScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> _deal(String id, String name, int quote, String stage, {String? next}) => {
      'job_id': id,
      'job_number': 'JOB-$id',
      'lead_date': '2026-07-22',
      'name': name,
      'phone': '6092403123',
      'email': null,
      'state': 'NJ',
      'quote_minor': quote,
      'currency': 'USD',
      'job_status': 'done pending approval',
      'stage': stage,
      'urgency': null,
      'next_follow_up': next,
      'notes': null,
      'sold_at': null,
    };

http.Response _json(Object body, [int status = 200]) =>
    http.Response(jsonEncode(body), status, headers: const {'content-type': 'application/json'});
