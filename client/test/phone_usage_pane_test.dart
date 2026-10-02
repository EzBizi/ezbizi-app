import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/usage_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Phone › Settings › Cost, as tiles over a table (layout A, Mia, 2026-10-02):
/// the total and the three biggest costs, then where it went with each line's
/// share. The response is every row in the window, so it is counted here.
void main() {
  testWidgets('the tiles are the total and the three biggest costs', (
    tester,
  ) async {
    await _pump(tester);

    Finder tile(String key, String text) => find.descendant(
      of: find.byKey(Key('cost-tile-$key')),
      matching: find.text(text),
    );

    // The server's own total, not a sum of doubles.
    expect(tile('all', '\$100.00'), findsOneWidget);
    expect(tile('Calls', '\$60.00'), findsOneWidget);
    expect(tile('Calls', '60% of the total'), findsOneWidget);
    expect(tile('Transcription', '\$25.00'), findsOneWidget);
    expect(tile('Text messages', '\$10.00'), findsOneWidget);

    // The fourth is in the table, not a tile.
    expect(find.byKey(const Key('cost-tile-Phone numbers')), findsNothing);
    expect(find.text('Phone numbers'), findsOneWidget);

    // Two days of calls add up into one line, with what was counted.
    expect(find.text('30 calls · 75 minutes'), findsOneWidget);
  });

  testWidgets('a cost tile filters the table to that line', (tester) async {
    await _pump(tester);

    await tester.tap(find.byKey(const Key('cost-tile-Transcription')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('cost:Transcription')), findsOneWidget);
    expect(find.byKey(const ValueKey('cost:Calls')), findsNothing);
    expect(find.text('25%'), findsOneWidget);
  });
}

Map<String, Object?> _usage(
  String category,
  String price, {
  int count = 0,
  String? countUnit,
  String usage = '0',
  String? usageUnit,
}) => {
  'day': '2026-09-30',
  'category': category,
  'description': category,
  'usage': usage,
  'usage_unit': usageUnit,
  'count': count,
  'count_unit': countUnit,
  'price': price,
  'price_unit': 'USD',
};

Future<void> _pump(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/phone/usage')) {
        return _json({
          'data': [
            _usage(
              'Calls',
              '40.000000',
              count: 20,
              countUnit: 'calls',
              usage: '50',
              usageUnit: 'minutes',
            ),
            _usage(
              'Calls',
              '20.000000',
              count: 10,
              countUnit: 'calls',
              usage: '25',
              usageUnit: 'minutes',
            ),
            _usage('Transcription', '25.000000'),
            _usage('Text messages', '10.000000'),
            _usage('Phone numbers', '5.000000'),
          ],
          'totals': {'USD': '100.000000'},
        });
      }

      return _json({'data': _me});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: UsagePane(
          api: api,
          session: session,
          scopeType: 'company',
          scopeId: 'co-1',
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@ezbizi.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
