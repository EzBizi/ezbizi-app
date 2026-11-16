import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:ezbizi_client/screens/task_board_live.dart';
import 'package:ezbizi_client/screens/task_board_plan_views.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// An open board follows changes made elsewhere (Jose, 2026-11-16: a status
/// changed in Monday showed only after leaving the tab and coming back).
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

  Map<String, dynamic> task(int i, {String? note, String? title}) => {
    'id': 't$i',
    'board_id': 'b1',
    'group_id': 'g1',
    'parent_task_id': null,
    'title': title ?? 'Task $i',
    'status': 'not_started',
    'owner': null,
    'due_on': null,
    'about': null,
    'updates_count': 0,
    'created_by': null,
    'values': {'c1': note ?? 'note $i'},
    'created_at': '2026-11-01T10:00:00+00:00',
    'updated_at': '2026-11-01T10:00:00+00:00',
  };

  /// A board of [count] tasks; [changes] is what the next changes read says.
  ({ApiClient api, List<String> paths}) fakeServer(
    int count,
    Map<String, dynamic> Function() changes,
  ) {
    final paths = <String>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        paths.add(path);
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
              'cursor': '2026-11-16T10:00:00+00:00',
              'kind': 'company',
              'name': 'Big',
              'can_edit': true,
              'statuses': const [
                {'key': 'not_started', 'label': 'Not started'},
                {'key': 'done', 'label': 'Done'},
              ],
              'columns': const [
                {
                  'id': 'c1',
                  'name': 'Note',
                  'type': 'text',
                  'position': 1,
                  'settings': null,
                },
              ],
              'groups': [
                {'id': 'g1', 'name': 'Open', 'colour': '#579BFC'},
              ],
              'tasks': [for (var i = 0; i < count; i++) task(i)],
            },
          });
        }
        if (path == '/task-boards/b1/changes') return json({'data': changes()});
        return json({'data': <dynamic>[]});
      }),
    );
    return (api: api, paths: paths);
  }

  Future<void> open(WidgetTester tester, ApiClient api) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
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

  Map<String, dynamic> quiet() => {
    'cursor': '2026-11-16T10:00:00+00:00',
    'tasks': <dynamic>[],
    'deleted': <dynamic>[],
    'groups': <dynamic>[],
  };

  testWidgets('a change made elsewhere shows without a reload, scroll kept', (
    tester,
  ) async {
    var next = quiet();
    final server = fakeServer(200, () => next);
    await open(tester, server.api);
    expect(find.byKey(const ValueKey('task-t0')), findsOneWidget);

    final table = find.byType(CustomScrollView).first;
    await tester.drag(table, const Offset(0, -1500));
    await tester.pumpAndSettle();
    final scroll = tester
        .state<ScrollableState>(
          find.descendant(of: table, matching: find.byType(Scrollable)).first,
        )
        .position;
    final offset = scroll.pixels;
    expect(offset, greaterThan(0));
    final shown = [
      for (var i = 0; i < 200; i++)
        if (find.byKey(ValueKey('task-t$i')).evaluate().isNotEmpty) i,
    ];
    final target = shown[shown.length ~/ 2];

    next = {
      ...quiet(),
      'cursor': '2026-11-16T10:00:10+00:00',
      'tasks': [
        {
          ...task(target, title: 'Renamed in Monday'),
          'updated_at': '2026-11-16T10:00:05+00:00',
        },
      ],
    };
    await tester.pump(const Duration(seconds: 11));
    await tester.pumpAndSettle();

    expect(find.text('Renamed in Monday'), findsOneWidget);
    expect(scroll.pixels, offset);
    expect(server.paths.where((p) => p == '/task-boards/b1').length, 1);
    expect(server.paths, contains('/task-boards/b1/changes'));
  });

  testWidgets('an open cell keeps what was typed when the value changes '
      'elsewhere, and says so', (tester) async {
    var next = quiet();
    final server = fakeServer(3, () => next);
    await open(tester, server.api);

    final cell = find.descendant(
      of: find.byKey(const ValueKey('task-t0')),
      matching: find.byKey(const ValueKey('column-cell-edit-c1')),
    );
    await tester.tap(cell);
    await tester.pumpAndSettle();
    final field = find.descendant(
      of: find.byKey(const ValueKey('column-cell-edit-c1')),
      matching: find.byType(TextField),
    );
    await tester.enterText(field, 'mine');
    await tester.pump();

    next = {
      ...quiet(),
      'cursor': '2026-11-16T10:00:10+00:00',
      'tasks': [
        {...task(0, note: 'theirs'), 'updated_at': '2026-11-16T10:00:05+00:00'},
      ],
    };
    await tester.pump(const Duration(seconds: 11));
    await tester.pump();

    expect(tester.widget<TextField>(field).controller!.text, 'mine');
    expect(find.byKey(const Key('cell-changed-elsewhere')), findsOneWidget);
  });

  testWidgets('an overdue timeline pill wears the error colour', (
    tester,
  ) async {
    final now = DateTime(2026, 11, 16, 12);
    Widget pill({required bool done}) => MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SizedBox(
          width: 200,
          child: TimelinePill(
            value: const {'from': '2026-11-01', 'to': '2026-11-10'},
            formats: DisplayFormats.fallback,
            now: now,
            done: done,
          ),
        ),
      ),
    );
    Color fill(WidgetTester tester) => (tester.widget<Container>(
      find.descendant(
        of: find.byKey(const Key('timeline-elapsed')),
        matching: find.byType(Container),
      ),
    )).color!;
    await tester.pumpWidget(pill(done: false));
    final scheme = Theme.of(
      tester.element(find.byType(TimelinePill)),
    ).colorScheme;
    expect(fill(tester), scheme.error);

    await tester.pumpWidget(pill(done: true));
    expect(fill(tester), scheme.primary);

    // The Timeline view's bar too.
    final today = DateTime.now();
    final end = formatCalendarDay(today.subtract(const Duration(days: 2)));
    final start = formatCalendarDay(today.subtract(const Duration(days: 5)));
    Widget timeline({required bool done}) => MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TaskBoardTimeline(
          groups: const [
            {'id': 'g1', 'name': 'Open'},
          ],
          tasksIn: (_) => [
            {
              'id': 't1',
              'title': 'Late',
              'values': {
                'c1': {'from': start, 'to': end},
              },
            },
          ],
          columnId: 'c1',
          canEdit: false,
          colourOf: (_) => Colors.green,
          isDone: (_) => done,
          onOpen: (_) {},
          onSetRange: (_, _, _) async {},
        ),
      ),
    );
    Color bar(WidgetTester tester) =>
        ((tester.widget<Container>(
                  find
                      .descendant(
                        of: find.byKey(const ValueKey('plan-bar-t1')),
                        matching: find.byType(Container),
                      )
                      .first,
                )).decoration!
                as BoxDecoration)
            .color!;
    await tester.pumpWidget(timeline(done: false));
    expect(bar(tester), scheme.error);
    await tester.pumpWidget(timeline(done: true));
    expect(bar(tester), Colors.green);
  });

  test('a paged board takes new tasks at the top, never later pages', () {
    Map<String, dynamic> row(String id, {String? parent, String? created}) => {
      'id': id,
      'group_id': 'g1',
      'parent_task_id': parent,
      'created_at': created ?? '2026-11-01T00:00:00+00:00',
      'updated_at': '2026-11-16T10:00:05+00:00',
    };
    final board = {
      'groups': [
        {'id': 'g1'},
      ],
      'tasks': [row('a'), row('b')],
      'group_totals': {
        'g1': {'count': 120, 'shown': 2, 'has_more': true, 'footers': {}},
      },
    };
    final merged = mergeBoardChanges(
      board,
      {
        'tasks': [
          row('old-unloaded'),
          row('new', created: '2026-11-16T10:00:03+00:00'),
          row('sub', parent: 'a'),
        ],
        'deleted': ['b'],
        'groups': [
          {'id': 'g1'},
        ],
        'group_totals': {
          'g1': {'count': 120, 'footers': {}},
        },
      },
      '2026-11-16T10:00:00+00:00',
      adopt: (_, t) => t,
    );
    final ids = [for (final t in merged.board!['tasks'] as List) t['id']];
    expect(ids, ['new', 'a', 'sub']);
    final totals = (merged.board!['group_totals'] as Map)['g1'] as Map;
    expect(totals['count'], 120);
    expect(totals['shown'], 2);
    expect(totals['has_more'], isTrue);

    // A cell open for editing: replaced in place, nothing added or removed.
    final held = mergeBoardChanges(
      board,
      {
        'tasks': [row('new', created: '2026-11-16T10:00:03+00:00')],
        'deleted': ['b'],
      },
      '2026-11-16T10:00:00+00:00',
      adopt: (_, t) => t,
      hold: true,
    );
    expect(held.held, isTrue);
    expect(held.board, isNull);
  });
}
