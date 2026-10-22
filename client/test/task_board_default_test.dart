import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Which board Task Board opens on: the one this person last opened in this
/// place, else their own board, never another person's personal board.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  // The server sorts personal boards by name: another person's first.
  const boards = [
    {
      'id': 'b-adeline',
      'kind': 'user',
      'name': 'Adeline Pierrelouis-Aghaji',
      'section': 'everyone',
      'owner': {'id': 'u-adeline', 'name': 'Adeline Pierrelouis-Aghaji'},
      'open_for_me': 0,
      'can_edit': false,
    },
    {
      'id': 'b-me',
      'kind': 'user',
      'name': 'Maya Lopez',
      'section': 'mine',
      'owner': {'id': 'me', 'name': 'Maya Lopez'},
      'open_for_me': 0,
      'can_edit': true,
    },
    {
      'id': 'b-co',
      'kind': 'company',
      'name': 'Northside Chimney',
      'section': 'place',
      'company_id': 'c-1',
      'company_name': 'Northside Chimney',
      'open_for_me': 0,
      'can_edit': true,
    },
  ];

  late List<String> opened;

  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbdef');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  Future<void> pumpBoard(
    WidgetTester tester, {
    String scope = 'company',
  }) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    opened = [];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'me',
              'name': 'Maya Lopez',
              'email': 'maya@example.test',
              'permissions': ['tasks.view', 'tasks.edit'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'scope': {'type': scope, 'id': '$scope-1'},
              'organization': {'id': 'org-1', 'name': 'Northside'},
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path == '/task-boards') return json({'data': boards});
        if (path.startsWith('/task-boards/')) {
          final id = path.split('/').last;
          opened.add(id);
          return json({
            'data': {
              'id': id,
              'kind': 'user',
              'name': id,
              'can_edit': true,
              'statuses': <dynamic>[],
              'groups': <dynamic>[],
              'tasks': <dynamic>[],
            },
          });
        }
        return json({'data': <dynamic>[]});
      }),
    );
    final session = Session(api);
    await session.adopt('tok');
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TaskBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets("opens on My board, never another person's board sorted first", (
    tester,
  ) async {
    await pumpBoard(tester);
    expect(opened, ['b-me']);
  });

  testWidgets('restores the board last opened in this place', (tester) async {
    await pumpBoard(tester);
    await tester.tap(find.byKey(const ValueKey('tasks-boards')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Northside Chimney').last);
    await tester.pumpAndSettle();
    expect(opened.last, 'b-co');

    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester);
    expect(opened, ['b-co']);
  });

  testWidgets('falls back to My board when the remembered board is gone or '
      'was opened in another place', (tester) async {
    await pumpBoard(tester);
    await tester.tap(find.byKey(const ValueKey('tasks-boards')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Northside Chimney').last);
    await tester.pumpAndSettle();

    // Another place: what was opened here is not remembered there.
    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester, scope: 'franchise');
    expect(opened, ['b-me']);

    // A remembered board that is no longer in the list.
    final dir = store.debugStoreDirOverride!;
    for (final f in dir.listSync().whereType<File>()) {
      f.writeAsStringSync('b-gone');
    }
    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester);
    expect(opened, ['b-me']);
  });
}
