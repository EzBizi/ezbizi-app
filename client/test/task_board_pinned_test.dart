import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Task Board hands-on QA 11-16 (docs/reviews/task-board-handson-
/// 2026-11-16.md), the last three: the Task column stays put when the table
/// scrolls sideways (#26), a narrow person heading ends in an ellipsis with
/// its name on hover (#27), and the bulk bar floats instead of pushing the
/// rows down (#28).
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbpin');
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
    'due_on': null,
    'about': null,
    'updates_count': 0,
    'created_by': null,
    'values': {'c-x7': 'far right'},
  };

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
            'columns': [
              {
                'id': 'c-rev',
                'name': 'Reviewer',
                'type': 'person',
                'position': 0,
                'hidden': false,
                'settings': <String, dynamic>{},
              },
              for (var i = 1; i < 8; i++)
                {
                  'id': 'c-x$i',
                  'name': 'Notes $i',
                  'type': 'text',
                  'position': i,
                  'hidden': false,
                  'settings': <String, dynamic>{},
                },
            ],
            'groups': [
              {'id': 'g1', 'name': 'Open Tasks', 'colour': '#579BFC'},
            ],
            'tasks': [
              task('t1', 'Sweep the Oak Park flue'),
              task('t2', 'Order a cap'),
              task('t3', 'Call the Hendersons back'),
            ],
          },
        });
      }
      return json({'data': <dynamic>[]});
    }),
  );

  Future<void> pumpBoard(WidgetTester tester, {double width = 1000}) async {
    tester.view.physicalSize = Size(width, 800);
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
    await tester.pumpAndSettle();
  }

  testWidgets('#26: scrolled sideways, the Task column and its heading stay '
      'put while the other cells move under them', (tester) async {
    await pumpBoard(tester);
    final title = find.text('Sweep the Oak Park flue');
    final heading = find.text('TASK');
    final far = find.text('far right').first;
    expect(title, findsOneWidget);
    final titleAt = tester.getTopLeft(title);
    final headingAt = tester.getTopLeft(heading);
    final farAt = tester.getTopLeft(far);

    await tester.drag(
      find.byKey(const Key('tasks-table-scroll')),
      const Offset(-500, 0),
    );
    await tester.pumpAndSettle();

    // The far cell came in from the right; the task did not leave.
    expect(tester.getTopLeft(far).dx, lessThan(farAt.dx - 200));
    expect(tester.getTopLeft(title), titleAt);
    expect(tester.getTopLeft(heading), headingAt);
    // Built once, not a pinned copy and a scrolled one.
    expect(title, findsOneWidget);
    // The pinned cell is the one a click reaches, not a cell under it.
    expect(title.hitTestable(), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('Jose 11-18: a visible sideways bar, and shift-wheel scrolls '
      'sideways', (tester) async {
    await pumpBoard(tester);
    expect(find.byKey(const Key('tasks-sideways-bar')), findsOneWidget);
    final far = find.text('far right').first;
    final farAt = tester.getTopLeft(far);
    final at = tester.getCenter(find.text('Sweep the Oak Park flue'));
    await tester.sendKeyDownEvent(LogicalKeyboardKey.shiftLeft);
    final pointer = TestPointer(1, PointerDeviceKind.mouse);
    await tester.sendEventToBinding(pointer.hover(at));
    await tester.sendEventToBinding(pointer.scroll(const Offset(0, 400)));
    await tester.sendKeyUpEvent(LogicalKeyboardKey.shiftLeft);
    await tester.pumpAndSettle();
    expect(tester.getTopLeft(far).dx, lessThan(farAt.dx - 200));
  });

  testWidgets('#26: a cell scrolled under the pinned Task column cannot be '
      'clicked through it', (tester) async {
    await pumpBoard(tester);
    final first = find.text('far right').first;
    await tester.drag(
      find.byKey(const Key('tasks-table-scroll')),
      const Offset(-2000, 0),
    );
    await tester.pumpAndSettle();
    // The leftmost scrolling cell (the Status column's) slid under the pin.
    final status = find.text('Not started').first;
    final pin = tester.getRect(find.text('Sweep the Oak Park flue'));
    expect(tester.getTopLeft(status).dx, lessThan(pin.right));
    expect(status.hitTestable(), findsNothing);
    expect(first.hitTestable(), findsOneWidget);
  });

  testWidgets('#27: an 80 px person heading ends in an ellipsis and names '
      'itself on hover', (tester) async {
    await pumpBoard(tester, width: 1400);
    final heading = find.text('REVIEWER');
    expect(heading, findsOneWidget);
    final paragraph = tester.renderObject<RenderParagraph>(heading);
    // It is cut at this width; the question is how.
    expect(paragraph.didExceedMaxLines, isTrue);
    expect(paragraph.overflow, TextOverflow.ellipsis);
    expect(
      find.ancestor(
        of: heading,
        matching: find.byWidgetPredicate(
          (w) => w is Tooltip && w.message == 'Reviewer',
        ),
      ),
      findsOneWidget,
    );
  });

  testWidgets('#28: ticking a row floats the bulk bar at the foot; no row '
      'moves under the pointer', (tester) async {
    await pumpBoard(tester, width: 1400);
    final row = find.byKey(const ValueKey('task-t2'));
    final before = tester.getTopLeft(row);

    await tester.tap(find.byKey(const ValueKey('task-pick-t1')));
    await tester.pumpAndSettle();

    final bar = find.byKey(const Key('task-bulk-bar'));
    expect(bar, findsOneWidget);
    expect(tester.getTopLeft(row), before);
    // At the bottom of the table, over it, not above the rows.
    expect(tester.getRect(bar).bottom, greaterThan(800 - 40));
    expect(tester.getRect(bar).top, greaterThan(before.dy));
    expect(
      find.byKey(const ValueKey('task-bulk-delete')).hitTestable(),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });
}
