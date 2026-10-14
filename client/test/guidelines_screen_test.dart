import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/guidelines_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a reader searches the cards and opens one to its steps', (
    tester,
  ) async {
    final posted = await _pump(tester, grants: ['guidelines.guidelines.view']);

    expect(find.text('Settings'), findsNothing);
    expect(find.byKey(const ValueKey('guidelines-add')), findsNothing);
    expect(find.text('Chimney fire'), findsOneWidget);
    expect(find.text('Upset customer'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('guidelines-search')),
      '911',
    );
    await tester.pumpAndSettle();
    expect(find.text('Upset customer'), findsNothing);

    await tester.tap(find.text('Chimney fire'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('guideline-read-g-1')), findsOneWidget);
    expect(find.text('Call 911'), findsOneWidget);
    expect(find.text('2'), findsOneWidget);
    expect(posted, isEmpty);
  });

  testWidgets('a manager adds a card in Settings, one step per line', (
    tester,
  ) async {
    final posted = await _pump(
      tester,
      grants: [
        'guidelines.guidelines.view',
        'guidelines.settings.view',
        'guidelines.manage',
      ],
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('guidelines-add')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('guidelines-title')),
      'Gas smell',
    );
    await tester.enterText(
      find.byKey(const ValueKey('guidelines-steps')),
      'Leave\n\nCall the gas company',
    );
    await tester.tap(find.byKey(const ValueKey('guidelines-save')));
    await tester.pumpAndSettle();

    expect(posted.single['title'], 'Gas smell');
    expect(posted.single['steps'], ['Leave', 'Call the gas company']);
  });
}

Future<List<Map<String, dynamic>>> _pump(
  WidgetTester tester, {
  required List<String> grants,
}) async {
  tester.view.physicalSize = const Size(1200, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final posted = <Map<String, dynamic>>[];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me(grants)});
      if (path.endsWith('/guidelines') && request.method == 'POST') {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        posted.add(body);
        return _json({
          'data': {...body, 'id': 'g-new', 'has_image': false},
        }, 201);
      }
      if (path.endsWith('/guidelines')) {
        return _json({
          'data': [
            {
              'id': 'g-1',
              'title': 'Chimney fire',
              'summary': 'Flames or roaring in the flue',
              'color': '#D93025',
              'steps': ['Call 911', 'Get everyone out'],
              'has_image': false,
            },
            {
              'id': 'g-2',
              'title': 'Upset customer',
              'summary': null,
              'color': null,
              'steps': ['Listen'],
              'has_image': false,
            },
          ],
        });
      }
      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: GuidelinesScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return posted;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _me(List<String> grants) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Office'},
  'permissions': ['guidelines.view', ...grants],
  'modules': [
    {
      'key': 'guidelines',
      'label': 'Guidelines',
      'available': true,
      'reachable': true,
    },
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
