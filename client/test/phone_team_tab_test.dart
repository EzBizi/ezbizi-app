import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/team_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Communication › Users: tiles over the editable table (Mia, 2026-10-02).
void main() {
  testWidgets('the tiles count everybody and filter the table', (
    tester,
  ) async {
    await _pump(tester);

    String countOn(String key) => tester
        .widgetList<Text>(
          find.descendant(
            of: find.byKey(Key('users-tile-$key')),
            matching: find.byType(Text),
          ),
        )
        .elementAt(1)
        .data!;

    expect(countOn('all'), '3');
    expect(countOn('ext'), '2');
    expect(countOn('noext'), '1');
    expect(countOn('line'), '1');

    await tester.tap(find.byKey(const Key('users-tile-noext')));
    await tester.pumpAndSettle();

    expect(find.text('Sasha Solano'), findsOneWidget);
    expect(find.text('Andy Ocampo'), findsNothing);
    expect(find.text('Tiara Marañon'), findsNothing);

    await tester.tap(find.byKey(const Key('users-tile-line')));
    await tester.pumpAndSettle();

    expect(find.text('Andy Ocampo'), findsOneWidget);
    expect(find.text('Sasha Solano'), findsNothing);
  });

  /// The tiles read what is saved, so typing an extension into a row under
  /// "No extension" does not whisk the row away mid-edit; Save still sends
  /// only the person who changed.
  testWidgets('a row being edited stays put, and Save sends only it', (
    tester,
  ) async {
    final puts = <Map<String, dynamic>>[];
    await _pump(tester, onPut: puts.add);

    await tester.tap(find.byKey(const Key('users-tile-noext')));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('extension:u-3')), '0105');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('extension:u-3')), findsOneWidget);

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(puts, hasLength(1));
    expect(puts.single['user_id'], 'u-3');
    expect(puts.single['extension'], '0105');
  });
}

Future<void> _pump(
  WidgetTester tester, {
  void Function(Map<String, dynamic>)? onPut,
}) async {
  tester.view.physicalSize = const Size(1100, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});

      if (path.endsWith('/phone/extensions') && request.method == 'PUT') {
        onPut?.call(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': {}});
      }

      if (path.endsWith('/phone/extensions')) {
        return _json({
          'connection_id': 'pc-1',
          'data': [
            {
              'user_id': 'u-1',
              'name': 'Andy Ocampo',
              'extension': '0101',
              'number_id': 'n-1',
            },
            {
              'user_id': 'u-2',
              'name': 'Tiara Marañon',
              'extension': '0102',
              'number_id': null,
            },
            {
              'user_id': 'u-3',
              'name': 'Sasha Solano',
              'extension': null,
              'number_id': null,
            },
          ],
          'numbers': [
            {'id': 'n-1', 'e164': '+12035550101', 'label': 'Hamden'},
          ],
        });
      }

      return _json({'message': 'Unexpected $path'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: TeamTab(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-9',
  'name': 'Adeline Ruiz',
  'email': 'adeline@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
