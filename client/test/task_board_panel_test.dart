import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:ezbizi_client/widgets/range_calendar.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The task panel (Jose, 2026-11-16): "there doesnt seem to be a save
/// button … all fields are different sizes, all pickers the same it's a
/// mess". Option A of docs/previews/task-panel.html: every change saves
/// itself and the header says so; one form, every box one width and height;
/// the timeline a range box that opens its calendar only when tapped.
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbp');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  /// Every request, GETs too, in the order sent.
  late List<(String, String, Object?)> log;
  late Map<String, dynamic> task;

  /// The next task PATCH is refused with this message.
  String? refuseNext;

  http.Response json(Object body, [int code = 200]) => http.Response(
    jsonEncode(body),
    code,
    headers: const {'content-type': 'application/json'},
  );

  ApiClient fakeServer() {
    log = [];
    refuseNext = null;
    task = {
      'id': 't1',
      'board_id': 'b-1',
      'group_id': 'g1',
      'parent_task_id': null,
      'title': 'Video retake',
      // Open: a done task folds away under its group.
      'status_id': 's-new',
      'owner': {'id': 'u-1', 'name': 'Jose Luis Demasi'},
      'owners': [
        {'id': 'u-1', 'name': 'Jose Luis Demasi'},
      ],
      'created_by': {'type': 'user', 'id': 'u-2', 'label': 'Shoval Ohayon'},
      'created_at': '2026-11-06T19:54:00+00:00',
      'due_on': '2099-11-16',
      'about': null,
      'updates_count': 0,
      'values': {
        'c-time': {'from': '2099-11-10', 'to': '2099-11-16'},
        'c-text': null,
        'c-cat': null,
      },
    };

    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        final body = request.body.isEmpty ? null : jsonDecode(request.body);
        log.add((request.method, path, body));
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Jose Luis Demasi',
              'email': 'jose@example.test',
              'permissions': ['tasks.view', 'tasks.edit'],
              'modules': <dynamic>[],
              'preferences': {
                'help.tours_seen': {'tasks': 99},
              },
              'organization': {
                'id': 'org-1',
                'name': 'Northside',
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
                'company_id': 'c-1',
                'company_name': 'Northside Chimney',
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
              'builtin_columns': {
                'creator': {'label': 'Owner', 'visible': true},
                'owner': {'label': 'Assigned', 'visible': true},
                'status': {'label': 'Status', 'visible': true},
                'due': {'label': 'Due', 'visible': true},
              },
              'assignable': [
                {'id': 'u-1', 'name': 'Jose Luis Demasi'},
                {'id': 'u-2', 'name': 'Shoval Ohayon'},
              ],
              'statuses': [
                {
                  'id': 's-new',
                  'name': 'Not started',
                  'colour': '#8E9BA3',
                  'is_done': false,
                  'position': 0,
                },
                {
                  'id': 's-done',
                  'name': 'Done - Pending Review',
                  'colour': '#2F9A4C',
                  'is_done': true,
                  'position': 1,
                },
              ],
              'columns': [
                {
                  'id': 'c-time',
                  'name': 'Timeline',
                  'type': 'timeline',
                  'position': 0,
                  'hidden': false,
                  'settings': <String, dynamic>{},
                },
                {
                  'id': 'c-text',
                  'name': 'Text',
                  'type': 'text',
                  'position': 1,
                  'hidden': false,
                  'settings': <String, dynamic>{},
                },
                {
                  'id': 'c-cat',
                  'name': 'Category',
                  'type': 'dropdown',
                  'position': 2,
                  'hidden': false,
                  'settings': {
                    'options': [
                      {'id': 'o-1', 'label': 'Video', 'colour': '#579BFC'},
                    ],
                  },
                },
              ],
              'groups': [
                {
                  'id': 'g1',
                  'name': 'Open Tasks',
                  'colour': '#579BFC',
                  'position': 0,
                },
              ],
              'tasks': [task],
            },
          });
        }
        if (path == '/tasks/t1' && request.method == 'PATCH') {
          // On the wire long enough for "Saving…" to be seen.
          await Future<void>.delayed(const Duration(milliseconds: 200));
          if (refuseNext case final String why) {
            refuseNext = null;
            return json({'message': why}, 422);
          }
          final m = (body as Map).cast<String, dynamic>();
          if (m['title'] is String) task['title'] = m['title'];
          if (m['values'] is Map) {
            task['values'] = {
              ...(task['values'] as Map),
              ...(m['values'] as Map),
            };
          }
          return json({'data': task});
        }
        if (path == '/tasks/t1') {
          return json({
            'data': {...task, 'updates': <dynamic>[]},
          });
        }
        if (path == '/tasks/t1/reminders' && request.method == 'GET') {
          return json({
            'data': [
              {
                'id': 'r1',
                'remind_at': '2099-01-05T14:30:00Z',
                'note': 'Call the supplier',
                'sent_at': null,
              },
              {
                'id': 'r0',
                'remind_at': '2026-01-05T14:30:00Z',
                'note': 'Rung already',
                'sent_at': '2026-01-05T14:30:10Z',
              },
            ],
          });
        }
        return json({'data': <dynamic>[]});
      }),
    );
  }

  Future<void> pump(WidgetTester tester, {double width = 1700}) async {
    tester.view.physicalSize = Size(width, 1400);
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
    final open = find.byKey(const ValueKey('task-open-t1'));
    if (open.evaluate().isNotEmpty) {
      await tester.ensureVisible(open);
      await tester.pumpAndSettle();
      await tester.tap(open);
    } else {
      await tester.tap(find.text('Video retake').first); // a phone card
    }
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-panel-title')), findsOneWidget);
  }

  List<(String, String, Object?)> patches() => [
    for (final e in log)
      if (e.$1 == 'PATCH') e,
  ];

  /// Past the server's 200 ms, and every frame after. Twice: a docked
  /// panel (design 200) saves in the frame that removes it, so its save's
  /// 200 ms start a frame after the close.
  Future<void> settle(WidgetTester tester) async {
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
  }

  Finder title() => find.byKey(const Key('task-panel-title'));

  String? saveState(WidgetTester tester) {
    final pill = find.byKey(const Key('task-panel-save-state'));
    if (pill.evaluate().isEmpty) return null;
    return tester
        .widgetList<Text>(
          find.descendant(of: pill, matching: find.byType(Text)),
        )
        .map((t) => t.data)
        .join();
  }

  testWidgets('a title typed and the panel closed is saved, before the '
      'board reads again', (tester) async {
    await pump(tester);
    await tester.enterText(title(), 'Video for rescheduling');
    // No Enter, no pause: straight to the close button.
    await tester.tap(find.byTooltip('Close Lakeview'));
    await settle(tester);

    expect(patches().single.$3, {'title': 'Video for rescheduling'});
    final saved = log.indexWhere((e) => e.$1 == 'PATCH');
    final reread = log.lastIndexWhere((e) => e.$2 == '/task-boards/b-1');
    expect(reread, greaterThan(saved));
    expect(find.text('Video for rescheduling'), findsWidgets);
  });

  testWidgets('a pause in typing saves, and the header says Saving… then '
      'Saved ✓', (tester) async {
    await pump(tester);
    expect(saveState(tester), isNull);

    await tester.enterText(title(), 'Video, rescheduled');
    await tester.pump(const Duration(milliseconds: 950));
    expect(saveState(tester), 'Saving…');
    await settle(tester);
    expect(patches().single.$3, {'title': 'Video, rescheduled'});
    expect(saveState(tester), 'Saved ✓');

    // Enter after the pause sends nothing new: one change, one save.
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await settle(tester);
    expect(patches(), hasLength(1));
  });

  testWidgets('a failed save says so, keeps what was typed, and Retry '
      'saves it', (tester) async {
    await pump(tester);
    refuseNext = 'No connection.';
    await tester.enterText(title(), 'Video v2');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await settle(tester);

    expect(saveState(tester), 'Not saved');
    expect(find.byKey(const Key('task-panel-error')), findsOneWidget);
    expect(find.textContaining('No connection.'), findsOneWidget);
    expect(tester.widget<TextField>(title()).controller!.text, 'Video v2');

    await tester.tap(find.byKey(const Key('task-panel-retry')));
    await settle(tester);
    expect(patches().last.$3, {'title': 'Video v2'});
    expect(saveState(tester), 'Saved ✓');
    expect(find.byKey(const Key('task-panel-error')), findsNothing);
  });

  for (final width in [1700.0, 390.0]) {
    testWidgets('every field box is one width and one height ($width)', (
      tester,
    ) async {
      await pump(tester, width: width);
      final boxes = [
        'task-panel-title',
        'task-panel-status',
        'task-panel-creator',
        'task-panel-owner',
        'task-panel-owner-add',
        'task-panel-due',
      ].map((k) => find.byKey(Key(k)));
      final columns = [
        'c-time',
        'c-text',
        'c-cat',
      ].map((c) => find.byKey(ValueKey('column-editor-$c')));
      final rects = [
        for (final f in [...boxes, ...columns]) tester.getRect(f),
      ];
      expect(rects, hasLength(9));
      expect({for (final r in rects) r.width}, hasLength(1));
      expect({for (final r in rects) r.height}, {44.0});
      expect({for (final r in rects) r.left}, hasLength(1));
      // On a phone the label sits above its box; on a desktop beside it.
      final label = tester.getRect(find.text('Timeline').last);
      final box = tester.getRect(
        find.byKey(const ValueKey('column-editor-c-time')),
      );
      if (width < 600) {
        expect(label.bottom, lessThanOrEqualTo(box.top));
      } else {
        expect(label.right, lessThanOrEqualTo(box.left));
      }
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('the timeline is a range box until tapped; two days picked '
      'save the range', (tester) async {
    await pump(tester);
    final box = find.byKey(const ValueKey('column-editor-c-time'));
    expect(find.byType(RangeCalendar), findsNothing);
    expect(
      find.descendant(of: box, matching: find.text('Nov 10 – Nov 16, 2099')),
      findsOneWidget,
    );

    await tester.tap(box);
    await settle(tester);
    expect(find.byType(RangeCalendar), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('range-day-2099-11-12')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('range-day-2099-11-20')));
    await settle(tester);
    expect(patches().last.$3, {
      'values': {
        'c-time': {'from': '2099-11-12', 'to': '2099-11-20'},
      },
    });
    await tester.tap(find.byKey(const Key('range-done')));
    await settle(tester);
    expect(find.byType(RangeCalendar), findsNothing);
  });

  testWidgets('a text column saves after a pause, and what is still typed '
      'saves when the panel closes', (tester) async {
    await pump(tester);
    final text = find.byKey(const ValueKey('column-editor-c-text'));
    await tester.enterText(text, 'Script ready');
    await tester.pump(const Duration(seconds: 1));
    await settle(tester);
    expect(patches().last.$3, {
      'values': {'c-text': 'Script ready'},
    });
    // The saved value coming back keeps the box, and the words in it.
    await tester.enterText(text, 'Script ready, needs a voice-over');
    await tester.tap(find.byTooltip('Close Lakeview'));
    await settle(tester);
    expect(patches().last.$3, {
      'values': {'c-text': 'Script ready, needs a voice-over'},
    });
    expect(patches(), hasLength(2));
  });

  // Design 200 (Jose 11-20, Monday side by side).
  Border? shadeBorder(WidgetTester tester) {
    final box = tester.widget<DecoratedBox>(
      find
          .descendant(
            of: find.byKey(const ValueKey('task-shade-t1')),
            matching: find.byType(DecoratedBox),
          )
          .first,
    );
    return (box.decoration as BoxDecoration).border as Border?;
  }

  testWidgets('design 200: a clicked task turns blue and its panel docks '
      'beside the live board; ✕ clears both', (tester) async {
    await pump(tester);

    expect(find.byKey(const Key('task-dock')), findsOneWidget);
    expect(shadeBorder(tester), isNotNull, reason: 'selected: a blue edge');
    // No scrim: the board stays live beside the panel.
    expect(find.byKey(const ValueKey('task-t1')).hitTestable(), findsOneWidget);

    await tester.tap(find.byTooltip('Close Lakeview'));
    await settle(tester);
    expect(find.byKey(const Key('task-dock')), findsNothing);
    expect(shadeBorder(tester), isNull);
  });

  testWidgets('the Updates cell opens the thread alone; the row opens the '
      'whole form (Jose 11-23)', (tester) async {
    for (final width in [1700.0, 1000.0]) {
      await pump(tester, width: width);
      if (width > 1100) {
        await tester.tap(find.byTooltip('Close Lakeview'));
      } else {
        Navigator.of(tester.element(title())).pop();
      }
      await settle(tester);
      final cell = find.byKey(const ValueKey('task-updates-cell-t1'));
      // At 1000 px the column is scrolled off to the right: its tap, direct.
      if (width > 1100) {
        await tester.ensureVisible(cell);
        await tester.tap(cell);
      } else {
        tester.widget<InkWell>(cell).onTap!();
      }
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('task-panel-updates-only')), findsOneWidget);
      expect(find.text('Post update'), findsOneWidget);
      expect(title(), findsNothing, reason: 'no form fields ($width)');
      expect(find.byKey(const Key('task-activity')), findsNothing);
    }
  });

  testWidgets('Updates sits right after Status on every board (Jose 11-23)', (
    tester,
  ) async {
    await pump(tester);
    final status = tester.getRect(find.byKey(const ValueKey('task-status-t1')));
    final updates = tester.getRect(
      find.byKey(const ValueKey('task-updates-cell-t1')),
    );
    expect(updates.left, greaterThan(status.left));
    expect(updates.left - status.right, lessThan(40), reason: 'next cell');
  });

  testWidgets('every row has the Monday grey rule above it (Jose 11-23, '
      'row 37)', (tester) async {
    await pump(tester);
    final box = tester.widget<DecoratedBox>(
      find
          .ancestor(
            of: find.byKey(const ValueKey('task-t1')),
            matching: find.byType(DecoratedBox),
          )
          .first,
    );
    final top = ((box.decoration as BoxDecoration).border! as Border).top;
    expect(top.color, const Color(0xFFD0D4E4));
  });

  testWidgets('clicking the name edits it in place; Enter saves, Esc '
      'cancels (row 40, Chaim)', (tester) async {
    await pump(tester, width: 1700);
    await tester.tap(find.byTooltip('Close Lakeview'));
    await settle(tester);
    final name = find.byKey(const ValueKey('task-name-t1'));
    await tester.tap(name);
    await tester.pump();
    final field = find.byKey(const ValueKey('task-rename-t1'));
    expect(field, findsOneWidget);
    expect(find.byTooltip('Close Lakeview'), findsNothing, reason: 'no panel');

    await tester.enterText(field, 'Gutter guard');
    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pump();
    expect(field, findsNothing);
    expect(log.where((e) => e.$1 == 'PATCH'), isEmpty, reason: 'Esc');

    await tester.tap(name);
    await tester.pump();
    await tester.enterText(field, 'Gutter guard');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(
      log.where((e) => e.$1 == 'PATCH' && e.$2 == '/tasks/t1').map((e) => e.$3),
      [
        {'title': 'Gutter guard'},
      ],
    );
  });

  testWidgets('the board and its panel are in Figtree, the Monday font '
      '(row 42)', (tester) async {
    await pump(tester);
    for (final k in ['task-name-t1', 'task-panel-title']) {
      final at = tester.element(find.byKey(Key(k)).first);
      expect(
        Theme.of(at).textTheme.bodyMedium?.fontFamily,
        'Figtree',
        reason: k,
      );
    }
  });

  testWidgets('a cancelled drag leaves the row at full colour (Jose 11-23, '
      'row 39)', (tester) async {
    await pump(tester);
    final handle = find.byKey(const ValueKey('task-drag-t1'));
    await tester.ensureVisible(handle);
    final g = await tester.startGesture(tester.getCenter(handle));
    await g.moveBy(const Offset(0, 30));
    await tester.pump(const Duration(milliseconds: 50));
    await g.moveBy(const Offset(0, -30)); // back over its own place
    await tester.pump();
    final shade = find.byKey(const ValueKey('task-shade-t1'));
    expect((tester.widget(shade) as dynamic).ghost, isTrue, reason: 'lifted');
    await g.up();
    await tester.pumpAndSettle();
    expect((tester.widget(shade) as dynamic).ghost, isFalse);
  });

  testWidgets('design 200: under 1100 px the panel still opens over the '
      'board', (tester) async {
    await pump(tester, width: 1000);
    expect(find.byKey(const Key('task-dock')), findsNothing);
    expect(find.byKey(const Key('task-panel-title')), findsOneWidget);
  });

  testWidgets('design 200: dragging a group folds every group, and they open '
      'again on release', (tester) async {
    await pump(tester);
    await tester.tap(find.byTooltip('Close Lakeview'));
    await settle(tester);
    expect(find.byKey(const ValueKey('task-t1')), findsOneWidget);

    final handle = find.byKey(const ValueKey('group-drag-g1'));
    final g = await tester.startGesture(tester.getCenter(handle));
    await tester.pump(const Duration(milliseconds: 50));
    await g.moveBy(const Offset(0, 40));
    await tester.pump();
    expect(find.byKey(const ValueKey('task-t1')), findsNothing);

    await g.up();
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('task-t1')), findsOneWidget);
  });

  testWidgets('a group header counts the tasks it shows: a search that hides '
      'them all reads 0 (Jose 11-20, CT › Company Info)', (tester) async {
    await pump(tester);
    await tester.tap(find.byTooltip('Close Lakeview'));
    await settle(tester);
    expect(find.text('1 task'), findsOneWidget);

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('tasks-search')),
        matching: find.byType(TextField),
      ),
      'nothing like this',
    );
    await tester.pumpAndSettle();
    expect(find.text('1 task'), findsNothing);
    expect(find.text('0 tasks'), findsOneWidget);
  });

  testWidgets('Remind me: the pending reminder is listed, a new one posts '
      'its time, and one is removed (Shoval 11-20)', (tester) async {
    await pump(tester);
    expect(find.textContaining('Call the supplier'), findsOneWidget);
    expect(find.textContaining('Rung already'), findsNothing);

    final remind = find.byKey(const ValueKey('task-remind-me'));
    await tester.ensureVisible(remind);
    await tester.tap(remind);
    await tester.pumpAndSettle();
    await tester.enterText(
      find.descendant(
        of: find.byKey(const ValueKey('task-remind-when')),
        matching: find.byType(TextField),
      ),
      '2099-01-06 09:30',
    );
    final save = find.byKey(const ValueKey('task-remind-save'));
    await tester.ensureVisible(save);
    await tester.tap(save);
    await tester.pumpAndSettle();
    final posted = log.lastWhere(
      (e) => e.$1 == 'POST' && e.$2 == '/tasks/t1/reminders',
    );
    expect((posted.$3 as Map)['remind_at'], startsWith('2099-01-0'));

    final remove = find.byKey(const ValueKey('task-reminder-remove-r1'));
    await tester.ensureVisible(remove);
    await tester.tap(remove);
    await tester.pumpAndSettle();
    expect(
      log.any((e) => e.$1 == 'DELETE' && e.$2 == '/tasks/t1/reminders/r1'),
      isTrue,
    );
  });
}
