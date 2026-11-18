import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose 11-17: notes edit where you can read them; a date is a month, not
/// a sheet to the bottom of the screen.
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
          'c-wz': '800021103',
          'c-ez': 'JOB-60857',
          'c-cost': 1200,
          'c-notes': 'Left a voicemail about the chimney liner quote',
          'c-long': 'Line one\nLine two',
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
                  'id': 'c-date',
                  'name': 'Date Received',
                  'type': 'date',
                  'position': 5,
                  'settings': null,
                },
                {
                  'id': 'c-notes',
                  'name': 'Notes',
                  'type': 'text',
                  'position': 6,
                  'settings': null,
                },
                {
                  'id': 'c-long',
                  'name': 'Details',
                  'type': 'long_text',
                  'position': 7,
                  'settings': null,
                },
                {
                  'id': 'c-wz',
                  'name': 'Workiz Job ID',
                  'type': 'text',
                  'position': 8,
                  'settings': null,
                },
                {
                  'id': 'c-ez',
                  'name': 'EzBizi Job #',
                  'type': 'text',
                  'position': 9,
                  'settings': null,
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
        if (path == '/tasks/t1/same-caller') {
          return json({
            'data': {
              'id': 't2',
              'title': 'Complaint: late tech',
              'board_id': 'b1',
              'board_name': 'Ohio projects',
            },
          });
        }
        if (path.endsWith('/same-caller')) return json({'data': null});
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

  Rect card(WidgetTester tester) =>
      tester.getRect(find.byKey(const Key('cell-card')));

  testWidgets(
    'Jose 11-18: EzBizi Job # | Workiz Job ID | Name | the rest, pinned with the name',
    (tester) async {
      await pumpBoard(tester);
      double x(String t) => tester.getTopLeft(find.text(t).first).dx;
      expect(x('JOB-60857'), lessThan(x('800021103')));
      expect(x('800021103'), lessThan(x('Task t1')));
      expect(
        x('Task t1'),
        lessThan(x('Left a voicemail about the chimney liner quote')),
      );
    },
  );

  testWidgets('a date cell opens a month, not a sheet to the bottom', (
    tester,
  ) async {
    await pumpBoard(tester);
    await tester.tap(
      find.byKey(const ValueKey('column-cell-edit-c-date')).first,
    );
    await tester.pumpAndSettle();
    final cal = find.byKey(const Key('cell-date-calendar'));
    expect(cal, findsOneWidget);
    final box = card(tester);
    expect(box.height, lessThan(420));
    expect(box.width, lessThan(360));
    await tester.tap(find.byKey(const Key('cell-date-today')));
    await tester.pumpAndSettle();
    expect(cal, findsNothing, reason: 'a pick closes it');
    final now = DateTime.now();
    expect(
      patches.last,
      contains(
        '${now.year}-${'${now.month}'.padLeft(2, '0')}-${'${now.day}'.padLeft(2, '0')}',
      ),
    );
  });

  testWidgets('notes edit in a card wide and tall enough to read', (
    tester,
  ) async {
    await pumpBoard(tester);
    await tester.tap(
      find.byKey(const ValueKey('column-cell-edit-c-notes')).first,
    );
    await tester.pumpAndSettle();
    expect(card(tester).width, greaterThanOrEqualTo(320));
    expect(
      find.text('Left a voicemail about the chimney liner quote'),
      findsWidgets,
    );
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pumpAndSettle();

    await tester.tap(
      find.byKey(const ValueKey('column-cell-edit-c-long')).first,
    );
    await tester.pumpAndSettle();
    expect(card(tester).height, greaterThanOrEqualTo(140));
    await tester.enterText(find.byType(EditableText).last, 'One\nTwo');
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    await tester.pumpAndSettle();
    expect(patches.last, contains(r'One\nTwo'));
  });

  testWidgets('a phone another open task has says so and opens it', (
    tester,
  ) async {
    await pumpBoard(tester);
    await tester.tap(
      find.byKey(const ValueKey('column-cell-edit-c-notes')).first,
    );
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(EditableText).last, '(732) 555-0101');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(
      find.textContaining('An open task for this caller already exists'),
      findsOneWidget,
    );
    expect(find.textContaining('Complaint: late tech'), findsOneWidget);
    expect(find.text('Open it'), findsOneWidget);
  });
}
