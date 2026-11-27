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

/// A big board builds only the rows on screen: a Column of every row froze
/// the desktop app (Jose, 2026-11-16, 169 tasks; NW has 24,713).
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbl');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  const count = 2000;

  ApiClient fakeServer() => ApiClient(
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
            'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
            'organization': {
              'id': 'org-1',
              'name': 'A-1',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'companies': <dynamic>[],
            'franchises': <dynamic>[],
          },
        });
      }
      if (path == '/task-boards') {
        return json({
          'data': [
            {
              'id': 'b1',
              'kind': 'company',
              'name': 'Big',
              'open_for_me': 0,
              'can_edit': true,
            },
          ],
        });
      }
      if (path == '/task-boards/b1') {
        return json({
          'data': {
            'id': 'b1',
            'kind': 'company',
            'name': 'Big',
            'can_edit': true,
            'statuses': const [
              {'key': 'not_started', 'label': 'Not started'},
              {'key': 'done', 'label': 'Done'},
            ],
            'groups': [
              {'id': 'g1', 'name': 'Open', 'colour': '#579BFC'},
              {'id': 'g2', 'name': 'Later', 'colour': '#FDAB3D'},
            ],
            'tasks': [
              for (var i = 0; i < count; i++)
                {
                  'id': 't$i',
                  'board_id': 'b1',
                  'group_id': i < count / 2 ? 'g1' : 'g2',
                  'title': 'Task $i',
                  'status': 'not_started',
                  'owner': null,
                  'due_on': null,
                  'about': null,
                  'updates_count': 0,
                  'created_by': null,
                },
            ],
          },
        });
      }
      return json({'data': <dynamic>[]});
    }),
  );

  int rowsBuilt(WidgetTester tester) => tester
      .widgetList(find.byWidgetPredicate((w) {
        final k = w.key;
        return k is ValueKey<String> &&
            RegExp(r'^task-t\d+$').hasMatch(k.value);
      }, skipOffstage: false))
      .length;

  testWidgets('2,000 tasks build a viewport of rows, also while scrolling', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = fakeServer();
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
    // The ~0.4 MB reply decodes on another isolate: real time, not fake.
    for (var i = 0; i < 100; i++) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 50)),
      );
      await tester.pump();
      if (find.byKey(const ValueKey('task-t0')).evaluate().isNotEmpty) break;
    }
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('task-t0')), findsOneWidget);
    // 900 px of 38 px rows is ~24, plus the cache extent either side.
    final first = rowsBuilt(tester);
    expect(first, greaterThan(5));
    expect(first, lessThan(80), reason: 'built $first of $count rows');

    final table = find.byType(CustomScrollView).first;
    for (var i = 0; i < 6; i++) {
      await tester.drag(table, const Offset(0, -3000));
      await tester.pump();
      final now = rowsBuilt(tester);
      expect(now, lessThan(80), reason: 'scroll $i built $now rows');
    }
    // It really moved: the first rows are gone and later ones are built.
    expect(find.byKey(const ValueKey('task-t0')), findsNothing);
    expect(rowsBuilt(tester), greaterThan(5));
  });
}
