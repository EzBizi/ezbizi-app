import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/profit_loss_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Profit and loss: the search narrows the categories table.
void main() {
  testWidgets('the search narrows the categories', (tester) async {
    tester.view.physicalSize = const Size(1400, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    http.Response json(Object body) => http.Response(
      jsonEncode(body),
      200,
      headers: const {'content-type': 'application/json'},
    );

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/reports/profit-loss/sources')) {
          return json({
            'sources': [
              {
                'id': 'c',
                'name': 'Base C',
                'tables': ['NJ'],
              },
            ],
          });
        }
        return json({
          'weeks': ['6 Jul - 12 Jul'],
          'week': {
            'week': '6 Jul - 12 Jul',
            'complete': true,
            'income': 12480.5,
            'costs': 900,
            'net': 11580.5,
            'categories': {
              'Jobs income': {'total': 12480.5, 'lines': 14},
              'Fuel': {'total': 900, 'lines': 1},
            },
          },
        });
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ProfitLossScreen(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final table = find.byKey(const ValueKey('pl-categories'));
    Finder inTable(String text) =>
        find.descendant(of: table, matching: find.text(text));
    expect(inTable('Fuel'), findsWidgets);
    expect(inTable('Jobs income'), findsWidgets);

    await tester.enterText(find.byKey(const Key('profit-loss-search')), 'FUEL');
    await tester.pump();
    expect(inTable('Fuel'), findsWidgets);
    expect(inTable('Jobs income'), findsNothing);

    await tester.enterText(find.byKey(const Key('profit-loss-search')), 'zzz');
    await tester.pump();
    expect(find.text('No categories match "zzz".'), findsOneWidget);
    expect(table, findsNothing);
  });
}
