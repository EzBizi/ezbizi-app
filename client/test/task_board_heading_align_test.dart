import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_pinned.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose 11-16: "there seems to be some sort of Missalignment between the
/// top row column divide and the rest". The headings and every row draw
/// their dividers at the same x: each cell's left edge, read off the boxes
/// that paint, under the real board at several widths, sorts, hidden
/// columns and sideways scrolls.
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbal');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> task(String id, String title) => {
    'id': id,
    'board_id': 'b-1',
    'group_id': 'g1',
    'parent_task_id': null,
    'title': title,
    'status': 'not_started',
    'owner': null,
    'due_on': '2026-11-10',
    'about': null,
    'updates_count': 2,
    'created_by': null,
    'values': {'c-x1': 'Needs review', 'c-st': 'a'},
  };

  ApiClient fakeServer({bool hidden = false}) => ApiClient(
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
            'preferences': {
              'help.tours_seen': {'tasks': 99},
            },
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
              'id': 'b-1',
              'kind': 'franchise',
              'name': 'Lakeview',
              'open_for_me': 1,
              'can_edit': true,
            },
          ],
        });
      }
      if (path == '/task-boards/b-1') {
        return json({
          'data': {
            'id': 'b-1',
            'kind': 'franchise',
            'name': 'Lakeview',
            'can_edit': true,
            'statuses': const [
              {'key': 'not_started', 'label': 'Not started'},
              {'key': 'done', 'label': 'Done'},
            ],
            if (hidden)
              'builtin_columns': {
                'owner': {'visible': false},
                'created': {'visible': true},
              },
            'columns': [
              {
                'id': 'c-st',
                'name': 'Stage',
                'type': 'status',
                'position': 0,
                'hidden': false,
                'settings': {
                  'options': [
                    {'id': 'a', 'label': 'Open', 'colour': '#00C875'},
                  ],
                },
              },
              {
                'id': 'c-x1',
                'name': 'Text',
                'type': 'text',
                'position': 1,
                'hidden': false,
                'settings': <String, dynamic>{},
              },
              {
                'id': 'c-gone',
                'name': 'Gone',
                'type': 'text',
                'position': 2,
                'hidden': hidden,
                'settings': <String, dynamic>{},
              },
            ],
            'groups': [
              {'id': 'g1', 'name': 'Open Tasks', 'colour': '#579BFC'},
            ],
            'tasks': [
              task('t1', 'Sweep the Oak Park flue'),
              task('t2', 'Order a cap'),
            ],
          },
        });
      }
      return json({'data': <dynamic>[]});
    }),
  );

  Future<void> pumpBoard(
    WidgetTester tester, {
    double width = 1400,
    bool hidden = false,
  }) async {
    tester.view.physicalSize = Size(width, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = fakeServer(hidden: hidden);
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

  /// The global left and right edge of every cell in a table line: where
  /// its divider is drawn.
  List<(double, double)> edges(WidgetTester tester, Finder line) {
    final row = tester.renderObject<RenderPinnedRow>(line);
    final out = <(double, double)>[];
    row.visitChildren((c) {
      final box = c as RenderBox;
      final left = box.localToGlobal(Offset.zero).dx;
      out.add((left, left + box.size.width));
    });
    return out;
  }

  Finder headingLine() => find
      .ancestor(of: find.text('TASK'), matching: find.byType(PinnedRow))
      .first;

  Finder bodyLine(String id) => find
      .descendant(
        of: find.byKey(ValueKey('task-$id')),
        matching: find.byType(PinnedRow),
      )
      .first;

  void expectAligned(WidgetTester tester, String why) {
    final head = edges(tester, headingLine());
    for (final id in ['t1', 't2']) {
      final body = edges(tester, bodyLine(id));
      expect(body.length, head.length, reason: '$why: same cells ($id)');
      for (var i = 0; i < head.length; i++) {
        expect(
          body[i].$1,
          moreOrLessEquals(head[i].$1, epsilon: 0.01),
          reason: '$why: cell $i left edge, $id vs heading',
        );
        expect(
          body[i].$2,
          moreOrLessEquals(head[i].$2, epsilon: 0.01),
          reason: '$why: cell $i right edge, $id vs heading',
        );
      }
    }
  }

  testWidgets('heading dividers sit over the rows\' at the default widths', (
    tester,
  ) async {
    await pumpBoard(tester);
    expectAligned(tester, 'default');
    expect(tester.takeException(), isNull);
  });

  testWidgets('heading dividers follow a dragged column, during and after '
      'the drag', (tester) async {
    await pumpBoard(tester);
    final handle = find.byKey(const ValueKey('column-resize-c:c-x1'));
    final gesture = await tester.startGesture(tester.getCenter(handle));
    await gesture.moveBy(const Offset(20, 0));
    await gesture.moveBy(const Offset(17.3, 0));
    await tester.pump();
    expectAligned(tester, 'mid-drag');
    await gesture.up();
    await tester.pumpAndSettle();
    expectAligned(tester, 'after drag');

    await tester.drag(
      find.byKey(const ValueKey('column-resize-task')),
      const Offset(41.6, 0),
    );
    await tester.pumpAndSettle();
    expectAligned(tester, 'task dragged');
    expect(tester.takeException(), isNull);
  });

  testWidgets('heading dividers stay put with a sort active', (tester) async {
    await pumpBoard(tester);
    await tester.tap(find.byKey(const ValueKey('sort-due')));
    await tester.pumpAndSettle();
    expectAligned(tester, 'sorted by due');
    expect(tester.takeException(), isNull);
  });

  testWidgets('heading dividers match with a column hidden', (tester) async {
    await pumpBoard(tester, hidden: true);
    expect(find.text('OWNER'), findsNothing);
    expect(find.text('GONE'), findsNothing);
    expectAligned(tester, 'hidden');
    expect(tester.takeException(), isNull);
  });

  testWidgets('heading dividers match scrolled sideways', (tester) async {
    await pumpBoard(tester, width: 900);
    await tester.drag(
      find.byKey(const Key('tasks-table-scroll')),
      const Offset(-300, 0),
    );
    await tester.pumpAndSettle();
    expectAligned(tester, 'scrolled');
    expect(tester.takeException(), isNull);
  });
}
