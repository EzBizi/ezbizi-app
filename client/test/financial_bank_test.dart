import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/screens/financials/financial_bank.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

Me _me(List<String> permissions) => Me.fromJson({
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
  'companies': <Map>[],
  'franchises': <Map>[],
  'modules': <Map>[],
  'permissions': permissions,
});

Future<List<http.Request>> _pump(
  WidgetTester tester, {
  List<Map> banks = const [],
  bool canConnect = true,
  int lastPage = 1,
  int failPage2 = 0,
}) async {
  tester.view.physicalSize = const Size(1200, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final seen = <http.Request>[];
  var matched = false;
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );
  final api = ApiClient(
    client: MockClient((request) async {
      seen.add(request);
      final path = request.url.path;
      if (path.endsWith('/financials/bank')) {
        return json({
          'data': {'ready': true, 'can_connect': canConnect, 'banks': banks},
        });
      }
      if (path.endsWith('/candidates')) {
        return json({
          'data': {
            'match': matched ? {'type': 'invoice', 'id': 'i7', 'label': 'Invoice INV-7 · Ann', 'by': 'person'} : null,
            'candidates': matched
                ? <Map>[]
                : [
                    {'type': 'invoice', 'id': 'i7', 'label': 'Invoice INV-7', 'detail': 'Ann · sent', 'amount_cents': 50000, 'date': '2026-11-10'},
                  ],
          },
        });
      }
      if (path.endsWith('/match')) {
        matched = request.method == 'POST';
        return json({'data': {'match': null}});
      }
      if (path.endsWith('/financials/bank/transactions')) {
        if (request.url.queryParameters['page'] == '2' && failPage2-- > 0) {
          return http.Response('{"message":"Server error"}', 500, headers: const {'content-type': 'application/json'});
        }
        final q = request.url.queryParameters['search'] ?? '';
        final rows = [
          {
            'id': 't1',
            'date': '2026-11-20',
            'name': 'HOME DEPOT #123',
            'merchant_name': 'Home Depot',
            'amount_cents': 1234,
            'category': 'GENERAL_MERCHANDISE',
            'pending': false,
            'account': {'id': 'a1', 'name': 'Checking', 'mask': '0000'},
          },
          {
            'id': 't2',
            'date': '2026-11-19',
            'name': 'Customer payment',
            'merchant_name': null,
            'amount_cents': -50000,
            'category': 'INCOME',
            'pending': false,
            'account': {'id': 'a1', 'name': 'Checking', 'mask': '0000'},
          },
        ].where((t) => q.isEmpty || '${t['name']}'.toLowerCase().contains(q.toLowerCase())).toList();
        return json({
          'data': rows,
          'meta': {'page': 1, 'last_page': lastPage, 'total': rows.length},
        });
      }
      return json({'data': null});
    }),
  );
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: FinancialBankTab(
          api: api,
          me: _me(['financials.view', 'financials.bank.view', 'financials.edit']),
          openConsent: () => null,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return seen;
}

const _bank = {
  'id': 'c1',
  'name': 'First Platypus Bank',
  'status': 'connected',
  'status_reason': null,
  'synced_at': '2026-11-24T20:00:00Z',
  'history': 'HISTORICAL_UPDATE_COMPLETE',
  'accounts': [
    {
      'id': 'a1',
      'name': 'Checking',
      'mask': '0000',
      'type': 'depository',
      'subtype': 'checking',
      'current_cents': 110000,
      'available_cents': 100000,
      'limit_cents': null,
      'currency': 'USD',
    },
  ],
};

void main() {
  test('amounts read like a statement: money out minus, money in plus', () {
    expect(bankAmount(1234), r'−$12.34');
    expect(bankAmount(-50000), r'+$500.00');
  });

  testWidgets('no bank yet offers to connect one', (tester) async {
    await _pump(tester);
    expect(find.text('No bank connected'), findsOneWidget);
    expect(find.text('Connect a bank'), findsOneWidget);
  });

  testWidgets('a bank shows its accounts, balances and searchable transactions', (tester) async {
    final seen = await _pump(tester, banks: [_bank]);
    expect(find.text('First Platypus Bank'), findsOneWidget);
    expect(find.text(r'$1,100.00'), findsOneWidget);
    expect(find.text(r'−$12.34'), findsOneWidget);
    expect(find.text(r'+$500.00'), findsOneWidget);
    expect(find.text('Refresh'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('financials-bank-search')).first, 'depot');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(seen.last.url.queryParameters['search'], 'depot');
    expect(find.text('Home Depot'), findsOneWidget);
    expect(find.text('Customer payment'), findsNothing);
  });

  testWidgets('a bank that wants a sign-in says why and offers Reconnect', (tester) async {
    await _pump(tester, banks: [
      {..._bank, 'status': 'broken', 'status_reason': 'The bank asks you to sign in again.'},
    ]);
    expect(find.text('Reconnect'), findsOneWidget);
    expect(find.text('The bank asks you to sign in again.'), findsOneWidget);
  });

  testWidgets('a reader sees the bank but no buttons that change it', (tester) async {
    await _pump(tester, banks: [_bank], canConnect: false);
    expect(find.text('First Platypus Bank'), findsOneWidget);
    expect(find.text('Refresh'), findsNothing);
    expect(find.text('Connect a bank'), findsNothing);
  });

  testWidgets('money in opens what it could be for, and matching posts the choice', (tester) async {
    final seen = await _pump(tester, banks: [_bank]);
    await tester.tap(find.text('Customer payment'));
    await tester.pumpAndSettle();
    expect(find.text('What was this for?'), findsOneWidget);
    expect(find.text('Invoice INV-7'), findsOneWidget);

    await tester.tap(find.byKey(const Key('financials-bank-match-i7')));
    await tester.pumpAndSettle();
    final post = seen.lastWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/financials/bank/transactions/t2/match'));
    expect(jsonDecode(post.body), {'type': 'invoice', 'id': 'i7'});
    expect(find.text('Invoice INV-7 · Ann'), findsOneWidget);
    expect(find.text('Unmatch'), findsOneWidget);
  });

  testWidgets('money out is not offered for matching', (tester) async {
    await _pump(tester, banks: [_bank]);
    await tester.tap(find.text('Home Depot'));
    await tester.pumpAndSettle();
    expect(find.text('What was this for?'), findsNothing);
  });

  testWidgets('a next page that fails is asked for again, not skipped', (tester) async {
    final seen = await _pump(tester, banks: [_bank], lastPage: 3, failPage2: 1);
    Future<void> more() async {
      await tester.ensureVisible(find.byKey(const Key('financials-bank-more'), skipOffstage: false));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('financials-bank-more')));
      await tester.pumpAndSettle();
    }

    List<String?> pages() => seen.where((r) => r.url.path.endsWith('/transactions')).map((r) => r.url.queryParameters['page']).toList();
    await more();
    expect(find.text('Home Depot'), findsOneWidget, reason: 'the rows already shown stay');
    await more();
    expect(pages(), ['1', '2', '2']);
  });
}
