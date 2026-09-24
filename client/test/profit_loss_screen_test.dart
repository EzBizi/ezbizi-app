import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/profit_loss_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Which base the tab opens on.
///
/// Shaped like A-1's three bases on 2026-09-24: the first two have tables and
/// no weeks in them, the third is the P&L. The tab opened blank on the first.
void main() {
  testWidgets('opens on the first base that has weeks, not the first base', (
    tester,
  ) async {
    await _pump(tester, weeksIn: 'c');

    expect(find.text('Base C'), findsOneWidget);
    expect(find.text('NJ'), findsOneWidget);
    expect(find.text('6 Jul - 12 Jul'), findsOneWidget);
  });

  testWidgets('with weeks in no base it lands on the first and says why', (
    tester,
  ) async {
    await _pump(tester, weeksIn: null);

    expect(find.text('Base A'), findsOneWidget);
    expect(
      find.text('No weekly profit and loss in this table.'),
      findsOneWidget,
    );
  });
}

Future<void> _pump(WidgetTester tester, {required String? weeksIn}) async {
  tester.view.physicalSize = const Size(1400, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/reports/profit-loss/sources')) {
        return _json({
          'sources': [
            {
              'id': 'a',
              'name': 'Base A',
              'tables': ['A1 Chimney NJ'],
            },
            {
              'id': 'b',
              'name': 'Base B',
              'tables': ['Refunds'],
            },
            {
              'id': 'c',
              'name': 'Base C',
              'tables': ['NJ', 'CT'],
            },
          ],
        });
      }

      if (request.url.path.endsWith('/reports/profit-loss')) {
        final has = request.url.queryParameters['connection'] == weeksIn;

        return _json({
          'weeks': has ? ['6 Jul - 12 Jul'] : [],
          // A week the screen can draw, so the test sees the base it chose
          // rather than a spinner.
          'week': has ? {'week': '6 Jul - 12 Jul', 'complete': false} : null,
        });
      }

      return _json({'message': 'Nothing stubbed.'}, 500);
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
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
