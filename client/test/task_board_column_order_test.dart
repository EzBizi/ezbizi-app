import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose 11-17: drag or move columns left and right, one order for the
/// board; picked rows wear a tint.
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbp');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);

  late List<String> patches;
  List<String> order = const [];

  Map<String, dynamic> task(String id, {String? due, Map? values}) => {
    'id': id,
    'board_id': 'b1',
    'group_id': 'g1',
    'title': 'Task $id',
    'status': 'not_started',
    'status_id': 's1',
    'owner': null,
    'due_on': due,
    'about': null,
    'updates_count': 0,
    'created_by': null,
    'values': values ?? {},
  };

  Future<void> pumpBoard(
    WidgetTester tester, {
    Size size = const Size(2600, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    patches = [];
    final tasks = [
      task(
        't1',
        due: formatCalendarDay(today),
        values: {
          'c-cost': 1200,
          'c-mat': 340,
          'c-time': {
            'from': formatCalendarDay(today),
            'to': formatCalendarDay(today.add(const Duration(days: 4))),
          },
        },
      ),
      task('t2', values: {'c-cost': 4800, 'c-mat': 1150}),
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
                'id': 'b1',
                'kind': 'custom',
                'name': 'Ohio projects',
                'can_edit': true,
              },
            ],
          });
        }
        if (path == '/task-boards/b1' && request.method == 'PATCH') {
          patches.add(request.body);
          return json({'data': <String, dynamic>{}});
        }
        if (path == '/task-boards/b1') {
          return json({
            'data': {
              'id': 'b1',
              'kind': 'custom',
              'name': 'Ohio projects',
              'can_edit': true,
              'column_order': order,
              'statuses': const [
                {
                  'id': 's1',
                  'name': 'Not started',
                  'colour': '#579BFC',
                  'is_done': false,
                },
              ],
              'columns': const [
                {
                  'id': 'c-cost',
                  'name': 'Job Cost',
                  'type': 'number',
                  'position': 1,
                  'settings': {'format': 'currency', 'footer': 'sum'},
                },
                {
                  'id': 'c-mat',
                  'name': 'Materials',
                  'type': 'number',
                  'position': 2,
                  'settings': {'format': 'currency'},
                },
                {
                  'id': 'c-total',
                  'name': 'Total Cost',
                  'type': 'formula',
                  'position': 3,
                  'settings': {
                    'expression': '[Job Cost] + [Materials]',
                    'format': 'currency',
                    'footer': 'sum',
                  },
                },
                {
                  'id': 'c-time',
                  'name': 'Timeline',
                  'type': 'timeline',
                  'position': 4,
                  'settings': null,
                },
              ],
              'groups': [
                {'id': 'g1', 'name': 'Customer Relations - Natalie', 'colour': '#00C875', 'purpose': 'Every refund starts here.'},
              ],
              'tasks': tasks,
            },
          });
        }
        final m = RegExp(r'^/tasks/(t\d)$').firstMatch(path);
        if (m != null && request.method == 'PATCH') {
          final body = jsonDecode(request.body) as Map;
          patches.add(jsonEncode([m[1], body]));
          final t = tasks.firstWhere((x) => x['id'] == m[1]);
          if (body['due_on'] != null) t['due_on'] = body['due_on'];
          return json({'data': t});
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

  double x(WidgetTester tester, String key) =>
      tester.getTopLeft(find.byKey(ValueKey(key))).dx;

  testWidgets('the saved order draws the columns, and Move right saves a new one', (
    tester,
  ) async {
    order = [
      'c:c-cost', 'status', 'owner', 'due', 'c:c-mat', //
      'c:c-total', 'c:c-time', 'about', 'updates',
    ];
    await pumpBoard(tester);
    expect(
      x(tester, 'column-drag-c:c-cost') < x(tester, 'column-drag-status'),
      isTrue,
      reason: 'Job Cost was saved before Status',
    );
    expect(
      x(tester, 'column-drag-c:c-cost') < x(tester, 'column-drag-owner'),
      isTrue,
    );

    await tester.tap(find.byKey(const ValueKey('builtin-head-owner')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('column-move-right-owner')));
    await tester.pumpAndSettle();
    final sent = (jsonDecode(patches.single) as Map)['column_order'] as List;
    expect(sent.take(5), ['c:c-cost', 'status', 'due', 'owner', 'c:c-mat']);
    order = const [];
  });

  testWidgets('dragging a heading onto another puts it there', (tester) async {
    await pumpBoard(tester);
    final g = await tester.startGesture(
      tester.getCenter(find.byKey(const ValueKey('column-drag-c:c-mat'))),
    );
    await tester.pump(const Duration(milliseconds: 50));
    await g.moveBy(const Offset(-20, 0));
    await tester.pump();
    await g.moveTo(
      tester.getCenter(find.byKey(const ValueKey('column-drag-owner'))),
    );
    await tester.pump();
    await g.up();
    await tester.pumpAndSettle();
    final sent = (jsonDecode(patches.single) as Map)['column_order'] as List;
    expect(sent.indexOf('c:c-mat'), sent.indexOf('owner') - 1);
    expect(
      x(tester, 'column-drag-c:c-mat') < x(tester, 'column-drag-owner'),
      isTrue,
    );
  });

  testWidgets('a role group says what it is for under its name', (tester) async {
    await pumpBoard(tester);
    expect(find.byKey(const ValueKey('group-purpose-g1')), findsOneWidget);
    expect(find.text('Every refund starts here.'), findsOneWidget);
  });

  testWidgets('a picked row wears a tint across the whole row', (tester) async {
    await pumpBoard(tester);
    expect(find.byKey(const ValueKey('task-picked-t1')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('task-pick-t1')));
    await tester.pumpAndSettle();
    final tint = find.byKey(const ValueKey('task-picked-t1'));
    expect(tint, findsOneWidget);
    // It spans the row, the pinned Task cell included.
    final row = tester.getRect(find.byKey(const ValueKey('task-t1')));
    expect(tester.getRect(tint).left, row.left);
    expect(tester.getRect(tint).width, row.width);
    expect(find.byKey(const ValueKey('task-picked-t2')), findsNothing);
  });
}
