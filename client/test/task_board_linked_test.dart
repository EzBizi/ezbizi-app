import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose 11-17: one Linked column on every board; a link opens its task,
/// what a task is about is plain text, and one box finds a task to link.
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
  final searched = <String>[];

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
      task('t2', values: {'c-cost': 4800, 'c-mat': 1150})
        ..['about'] = {'type': 'customer', 'id': 'cu1', 'label': 'Catherine Gilbert'},
      task('t3')
        ..['links'] = [
          {'id': 'x9', 'title': 'Chimney sweep', 'board_id': 'b2', 'board_name': 'NJ'},
        ],
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
        if (path == '/task-search') {
          searched.add(request.url.queryParameters['q']!);
          return json({
            'data': [
              {'id': 'x9', 'title': 'Chimney sweep', 'board_id': 'b2', 'board_name': 'NJ'},
            ],
            'records': [
              {'type': 'lead', 'id': 'l1', 'label': 'Ann Chimney', 'detail': '(732) 555-0101'},
            ],
          });
        }
        if (path == '/tasks/t1/links' && request.method == 'POST') {
          patches.add(request.body);
          return json({'data': <String, dynamic>{}}, 201);
        }
        if (path == '/tasks/t1/links') return json({'data': <dynamic>[]});
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
              'builtin_columns': {'about': {'label': 'Summary', 'visible': true}},
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

  testWidgets('the Linked column: one name, a link, plain about, + Link', (
    tester,
  ) async {
    await pumpBoard(tester);
    expect(find.text('LINKED'), findsWidgets);
    expect(find.text('SUMMARY'), findsNothing, reason: 'the board name is on hover only');

    // A link: its title and its board as a chip.
    expect(find.text('Chimney sweep'), findsOneWidget);
    expect(find.text('NJ'), findsOneWidget);
    // What a task is about opens it, with its type beside it (Jose 11-17).
    expect(find.text('Customer'), findsOneWidget);
    DeepLinks.pending.value = null;
    await tester.tap(find.text('Catherine Gilbert'));
    await tester.pump();
    expect(DeepLinks.pending.value?.module, 'customers');
    expect(DeepLinks.pending.value?.id, 'cu1');
    DeepLinks.pending.value = null;
    // Nothing yet: a quiet + Link.
    expect(find.text('+ Link'), findsOneWidget);
  });

  testWidgets('one box finds a task on any board and links it', (tester) async {
    await pumpBoard(tester);
    await tester.tap(find.text('+ Link'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-link-board')), findsNothing);
    await tester.enterText(find.byKey(const Key('task-link-search')), 'chim');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(searched, ['chim']);
    expect(find.text('NJ'), findsWidgets);
    await tester.tap(find.byKey(const ValueKey('task-link-pick-x9')));
    await tester.pumpAndSettle();
    expect(patches, [jsonEncode({'linked_task_id': 'x9'})]);
  });

  testWidgets('the same box links a lead, which the task is then about', (
    tester,
  ) async {
    await pumpBoard(tester);
    await tester.tap(find.text('+ Link'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('task-link-search')), '5550101');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    expect(find.text('Lead · (732) 555-0101'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('task-link-record-lead-l1')));
    await tester.pumpAndSettle();
    expect(
      patches.last,
      jsonEncode([
        't1',
        {
          'about': {'type': 'lead', 'id': 'l1'},
        },
      ]),
    );
    expect(find.byKey(const Key('task-link-about')), findsOneWidget);
  });
}
