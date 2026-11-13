import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:ezbizi_client/screens/task_board_plan_views.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 178 (Jose, 2026-11-03): Calendar and Timeline views, number
/// formats, Formula columns and group footers.
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
  // A day in this month's grid that is not today.
  final other = DateTime(now.year, now.month, today.day == 1 ? 2 : 1);

  late List<String> patches;

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
    Size size = const Size(1400, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    patches = [];
    final tasks = [
      task(
        't1',
        due: planIso(today),
        values: {
          'c-cost': 1200,
          'c-mat': 340,
          'c-time': {
            'from': planIso(today),
            'to': planIso(today.add(const Duration(days: 4))),
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
        if (path == '/task-boards/b1') {
          return json({
            'data': {
              'id': 'b1',
              'kind': 'custom',
              'name': 'Ohio projects',
              'can_edit': true,
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
                {'id': 'g1', 'name': 'Scheduled', 'colour': '#00C875'},
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

  test(
    'a formula works out + − × ÷ and brackets, and refuses what it cannot',
    () {
      expect(evalFormula('{a} + {b} * 2', {'a': 1200, 'b': 340}), 1880);
      expect(evalFormula('({a} + {b}) * 2', {'a': 1, 'b': '2'}), 6);
      expect(evalFormula('-{a} / 4', {'a': 10}), -2.5);
      expect(evalFormula('{a} / {b}', {'a': 1, 'b': 0}), isNull);
      expect(evalFormula('{a} +', {'a': 1}), isNull);
      expect(
        evalFormula('{a}', const {}),
        0,
        reason: 'an empty value counts 0',
      );
    },
  );

  test(
    'a formula speaks Monday: IF, VALUE, CONCATENATE, FLOOR, MOD, & and =',
    () {
      // Ohio PM's Total Cost (Monday audit 11-13).
      const total = 'IF({paid}, (VALUE({price}) * 0.1) + {cost}, {cost})';
      expect(
        evalFormula(total, {'paid': true, 'price': '200', 'cost': 50}),
        70,
      );
      expect(
        evalFormula(total, {'paid': false, 'price': '200', 'cost': 50}),
        50,
      );
      expect(
        evalFormula('CONCATENATE(FLOOR({m} / 60), "h ", MOD({m}, 60), "m")', {
          'm': 135,
        }),
        '2h 15m',
      );
      expect(evalFormula('{a} & "-" & {b}', {'a': 'OH', 'b': 7}), 'OH-7');
      expect(evalFormula('{a} = "Done"', {'a': 'Done'}), true);
      expect(evalFormula('ROUND({a}, 1)', {'a': 2.345}), 2.3);
      expect(evalFormula('MOD({a}, 0)', {'a': 1}), isNull);
      expect(evalFormula('EVAL(1)', const {}), isNull);
    },
  );

  testWidgets('the table shows currency, a formula and a group footer sum', (
    tester,
  ) async {
    await pumpBoard(tester);
    expect(find.text(r'$1,200.00'), findsOneWidget);
    expect(
      find.text(r'$1,540.00'),
      findsOneWidget,
      reason: 'Total Cost = Job Cost + Materials',
    );
    expect(find.byKey(const ValueKey('column-footer-c-cost')), findsOneWidget);
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('column-footer-c-cost')))
          .data,
      r'$6,000.00',
    );
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('column-footer-c-total')))
          .data,
      r'$7,490.00',
    );
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('column-footer-c-mat')))
          .data,
      '',
      reason: 'Materials asked for no footer',
    );
  });

  testWidgets(
    'Calendar puts a task on its day, keeps the undated in a tray, and a drag moves the date',
    (tester) async {
      await pumpBoard(tester);
      await tester.tap(find.text('Calendar'));
      await tester.pumpAndSettle();

      final day = find.byKey(ValueKey('plan-day-${planIso(today)}'));
      expect(
        find.descendant(of: day, matching: find.text('Task t1')),
        findsOneWidget,
      );
      expect(find.text('No date (1)'), findsOneWidget);

      final from = tester.getCenter(find.byKey(const ValueKey('plan-task-t1')));
      final to = tester.getCenter(
        find.byKey(ValueKey('plan-day-${planIso(other)}')),
      );
      final g = await tester.startGesture(from);
      await tester.pump(const Duration(milliseconds: 50));
      await g.moveTo(to);
      await tester.pump();
      await g.up();
      await tester.pumpAndSettle();
      expect(patches, [
        jsonEncode([
          "t1",
          {"due_on": planIso(other)},
        ]),
      ]);
    },
  );

  testWidgets(
    'Timeline draws the range as a bar and a drag moves it by whole days',
    (tester) async {
      await pumpBoard(tester);
      await tester.tap(find.text('Timeline'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('plan-today')), findsOneWidget);
      final bar = find.byKey(const ValueKey('plan-bar-t1'));
      expect(bar, findsOneWidget);
      expect(
        find.byKey(const ValueKey('plan-bar-t2')),
        findsNothing,
        reason: 't2 has no range: no bar',
      );

      // Month scale is 14 px a day: 3 days to the right.
      await tester.timedDrag(
        bar,
        const Offset(42, 0),
        const Duration(milliseconds: 300),
      );
      await tester.pumpAndSettle();
      expect(patches, [
        jsonEncode([
          't1',
          {
            'values': {
              'c-time': {
                'from': planIso(today.add(const Duration(days: 3))),
                'to': planIso(today.add(const Duration(days: 7))),
              },
            },
          },
        ]),
      ]);
    },
  );

  testWidgets(
    'on a phone the calendar is an agenda with Move date on each task',
    (tester) async {
      await pumpBoard(tester, size: const Size(400, 800));
      await tester.tap(find.text('Calendar'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('plan-agenda')), findsOneWidget);
      expect(find.textContaining('Today ·'), findsOneWidget);
      expect(find.text('No date (1)'), findsOneWidget);
      expect(find.byKey(const ValueKey('plan-move-t1')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('plan-move-t1')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('plan-move-day-t1')), findsOneWidget);
    },
  );
}
