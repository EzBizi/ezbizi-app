import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_phone_lines.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

final _put = <Map<String, dynamic>>[];

void main() {
  // Jose, 2026-11-17: whose call it is comes from the number it came in on.
  testWidgets('each RingCentral number gets a company, sent as its place', (
    tester,
  ) async {
    await _pump(tester);
    expect(
      find.text('RingCentral · Everdoors Connecticut (637)'),
      findsOneWidget,
    );
    expect(find.text('ED - MA - GBP'), findsOneWidget);
    expect(find.text('EverDoors (all branches)'), findsNothing);

    await tester.tap(
      find.byKey(const ValueKey('phone-line-place-+17813855201')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('scope-choice-company:co-2')));
    await tester.pumpAndSettle();

    expect(_put.single, {'number': '+17813855201', 'place': 'company:co-2'});
    expect(find.text('Saved.'), findsOneWidget);
  });

  testWidgets('search finds a line by name or by digits', (tester) async {
    await _pump(tester);
    await tester.enterText(
      find.byKey(const ValueKey('phone-lines-search')),
      'premier',
    );
    await tester.pump();
    expect(find.text('ED - MA - GBP'), findsNothing);
    expect(find.text('Premier - CT'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('phone-lines-search')),
      '(781) 385',
    );
    await tester.pump();
    expect(find.text('ED - MA - GBP'), findsOneWidget);
    expect(find.text('Premier - CT'), findsNothing);
  });
}

Future<void> _pump(WidgetTester tester) async {
  _put.clear();
  tester.view.physicalSize = const Size(1200, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': ['leads.manage'],
          },
        });
      }
      if (path.endsWith('/leads/phone-lines') && request.method == 'GET') {
        return _json({
          'data': {
            'accounts': [
              {
                'id': 'c-2',
                'name': 'RingCentral · Everdoors Connecticut (637)',
                'numbers': [
                  {
                    'number': '+17813855201',
                    'label': 'ED - MA - GBP',
                    'place': null,
                  },
                  {
                    'number': '+12035550100',
                    'label': 'Premier - CT',
                    'place': null,
                  },
                ],
              },
            ],
          },
        });
      }
      if (path.endsWith('/leads/phone-lines') && request.method == 'PUT') {
        final body = (jsonDecode(request.body) as Map).cast<String, dynamic>();
        _put.add(body);
        return _json({'data': body});
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
        body: LeadsPhoneLines(api: api, session: session),
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

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@ezbizi.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
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
    {'id': 'co-1', 'name': 'A-1 Chimney', 'is_active': true},
    {'id': 'co-2', 'name': 'EverDoors', 'is_active': true},
  ],
  'franchises': <Map<String, Object?>>[],
};
