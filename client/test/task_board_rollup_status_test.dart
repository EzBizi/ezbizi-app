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

/// Jose, 2026-11-02: a branch's row on the company board showed its status as
/// a raw id, and the column headings scrolled away under 347 rows.
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbs');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  late List<Object?> patches;

  Map<String, dynamic> task(String id, {bool branch = false}) => {
    'id': id,
    'board_id': branch ? 'b-br' : 'b-co',
    'group_id': branch ? 'g-br' : 'g1',
    'title': 'Task $id',
    'status': 'working',
    'status_id': branch ? 's-br-w' : 's-co-ns',
    'status_name': branch ? 'Working on it' : 'Not started',
    'owner': null,
    'due_on': null,
    'about': null,
    'updates_count': 0,
    'created_by': null,
    if (branch)
      'rolled_up_from': {
        'board_id': 'b-br',
        'board_name': 'DMV',
        'group_name': 'Open Tasks',
      },
  };

  Future<void> pumpBoard(WidgetTester tester, int count) async {
    tester.view.physicalSize = const Size(1400, 700);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    patches = [];
    final tasks = [
      task('t0', branch: true),
      for (var i = 1; i < count; i++) task('t$i'),
    ];
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
                'id': 'b-co',
                'kind': 'company',
                'name': 'A-1',
                'can_edit': true,
              },
            ],
          });
        }
        if (path == '/task-boards/b-co') {
          return json({
            'data': {
              'id': 'b-co',
              'kind': 'company',
              'name': 'A-1',
              'can_edit': true,
              'statuses': const [
                {'id': 's-co-ns', 'name': 'Not started', 'is_done': false},
                {'id': 's-co-w', 'name': 'Working on it', 'is_done': false},
              ],
              'groups': [
                {'id': 'g1', 'name': 'Open Tasks', 'colour': '#579BFC'},
              ],
              'tasks': tasks,
            },
          });
        }
        if (path == '/tasks/t0' && request.method == 'PATCH') {
          patches.add(jsonDecode(request.body));
          // The reply is the branch's own row, its own status id again.
          return json({
            'data': {
              ...task('t0', branch: true)..remove('rolled_up_from'),
              'status_id': 's-br-ns',
              'status_name': 'Not started',
            },
          });
        }
        return json({'message': 'Not found.'}, 404);
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

  testWidgets('a branch row shows its status by name and writes it by name', (
    tester,
  ) async {
    await pumpBoard(tester, 2);
    final cell = find.byKey(const ValueKey('task-status-t0'));
    expect(
      find.descendant(of: cell, matching: find.text('Working on it')),
      findsWidgets,
    );
    expect(find.textContaining('s-br-'), findsNothing);

    await tester.tap(cell);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('task-status-t0-s-co-ns')).last);
    await tester.pumpAndSettle();
    expect(patches, [
      {'status': 'Not started'},
    ]);
    expect(
      find.descendant(of: cell, matching: find.text('Not started')),
      findsWidgets,
    );
    expect(find.textContaining('s-br-'), findsNothing);
  });

  testWidgets(
    'the group heading and column headings stay up while rows scroll',
    (tester) async {
      await pumpBoard(tester, 60);
      final top = find.byKey(const ValueKey('task-group-top-g1'));
      final before = tester.getTopLeft(top).dy;
      await tester.dragFrom(const Offset(700, 500), const Offset(0, -900));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('task-t1')).hitTestable(), findsNothing);
      expect(top.hitTestable(), findsOneWidget);
      expect(tester.getTopLeft(top).dy, lessThanOrEqualTo(before));
      expect(
        find.descendant(of: top, matching: find.text('STATUS')),
        findsWidgets,
      );
    },
  );
}
