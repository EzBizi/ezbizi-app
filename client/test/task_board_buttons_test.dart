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

/// Task Board's [My Board] [<Company> Board] [picker] (Jose, 2026-10-26):
/// the company button is named after, and opens, the board of the company
/// this person is placed in, worked out from /me and the board list.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, Object?> board(
    String id,
    String kind,
    String name, {
    String section = 'place',
    String? companyId,
    String? companyName,
  }) => {
    'id': id,
    'kind': kind,
    'name': name,
    'section': section,
    'company_id': companyId,
    'company_name': companyName,
    if (kind == 'user') 'owner': {'id': 'me', 'name': 'Maya Lopez'},
    'open_for_me': 0,
    'can_edit': true,
  };

  final standard = [
    board('b-me', 'user', 'Maya Lopez', section: 'mine'),
    board(
      'b-a1',
      'company',
      'A-1 Chimney',
      companyId: 'c-1',
      companyName: 'A-1 Chimney',
    ),
    board(
      'b-other',
      'company',
      'Northside Chimney',
      companyId: 'c-2',
      companyName: 'Northside Chimney',
    ),
    board(
      'b-fr',
      'franchise',
      'Boston',
      companyId: 'c-1',
      companyName: 'A-1 Chimney',
    ),
  ];

  late List<String> opened;

  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbbtn');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  Future<void> pumpBoard(
    WidgetTester tester, {
    String scope = 'company',
    String scopeId = 'c-1',
    List<Map<String, Object?>>? boards,
    Size size = const Size(1400, 900),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    opened = [];
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
              'scope': {'type': scope, 'id': scopeId},
              'organization': {'id': 'org-1', 'name': 'A1 Group'},
              'companies': [
                {'id': 'c-1', 'name': 'A-1 Chimney'},
                {'id': 'c-2', 'name': 'Northside Chimney'},
              ],
              'franchises': [
                {'id': 'f-1', 'name': 'Boston', 'company_id': 'c-1'},
              ],
            },
          });
        }
        if (path == '/task-boards') {
          return json({'data': boards ?? standard});
        }
        if (path.startsWith('/task-boards/')) {
          final id = path.split('/').last;
          opened.add(id);
          return json({
            'data': {
              'id': id,
              'kind': 'company',
              'name': id,
              'can_edit': true,
              'statuses': <dynamic>[],
              'groups': <dynamic>[],
              'tasks': <dynamic>[],
            },
          });
        }
        return json({'data': <dynamic>[]});
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

  final quick = find.byKey(const Key('tasks-quick-boards'));
  Finder inQuick(String text) =>
      find.descendant(of: quick, matching: find.text(text));
  Set<int> lit(WidgetTester tester) =>
      tester.widget<SegmentedButton<int>>(quick).selected;

  testWidgets('the company button is named after the placement, and the '
      'highlight follows the board in view', (tester) async {
    await pumpBoard(tester);
    expect(inQuick('Personal Task Board'), findsOneWidget);
    expect(inQuick('A-1 Chimney Board'), findsOneWidget);
    expect(inQuick('Northside Chimney Board'), findsNothing);
    expect(opened, ['b-me']);
    expect(lit(tester), {0});

    await tester.tap(inQuick('A-1 Chimney Board'));
    await tester.pumpAndSettle();
    expect(opened.last, 'b-a1');
    expect(lit(tester), {1});

    // Another board, from the tree: no button lit.
    await tester.tap(find.byKey(const ValueKey('tasks-board-b-other')));
    await tester.pumpAndSettle();
    expect(opened.last, 'b-other');
    expect(lit(tester), isEmpty);

    await tester.tap(inQuick('Personal Task Board'));
    await tester.pumpAndSettle();
    expect(opened.last, 'b-me');
    expect(lit(tester), {0});
  });

  testWidgets('a branch placement gives its company board', (tester) async {
    await pumpBoard(tester, scope: 'franchise', scopeId: 'f-1');
    expect(inQuick('A-1 Chimney Board'), findsOneWidget);
    await tester.tap(inQuick('A-1 Chimney Board'));
    await tester.pumpAndSettle();
    expect(opened.last, 'b-a1');
  });

  testWidgets('at the organization the button is hidden, or is the '
      'organization board when it keeps one', (tester) async {
    await pumpBoard(tester, scope: 'organization', scopeId: 'org-1');
    expect(inQuick('Personal Task Board'), findsOneWidget);
    expect(inQuick('A-1 Chimney Board'), findsNothing);
    expect(inQuick('Northside Chimney Board'), findsNothing);

    await tester.pumpWidget(const SizedBox());
    await pumpBoard(
      tester,
      scope: 'organization',
      scopeId: 'org-1',
      boards: [...standard, board('b-org', 'organization', 'A1 Group')],
    );
    expect(inQuick('A1 Group Board'), findsOneWidget);
    await tester.tap(inQuick('A1 Group Board'));
    await tester.pumpAndSettle();
    expect(opened.last, 'b-org');
  });

  testWidgets('a company without a board has no button', (tester) async {
    await pumpBoard(tester, scopeId: 'c-2', boards: [standard[0], standard[1]]);
    expect(inQuick('Personal Task Board'), findsOneWidget);
    expect(inQuick('Northside Chimney Board'), findsNothing);
    expect(inQuick('A-1 Chimney Board'), findsNothing);
  });

  testWidgets('the picker still lists every board and searches', (
    tester,
  ) async {
    final many = [
      ...standard,
      for (var i = 0; i < 6; i++)
        board(
          'b-x$i',
          'franchise',
          'Branch $i',
          companyId: 'c-2',
          companyName: 'Northside Chimney',
        ),
    ];
    await pumpBoard(tester, boards: many);
    for (final name in ['Personal Task Board', 'A-1 Chimney', 'Boston', 'Branch 5']) {
      expect(find.text(name), findsWidgets, reason: name);
    }
    await tester.enterText(
      find.byKey(const ValueKey('tasks-boards-search')),
      'Branch 3',
    );
    await tester.pumpAndSettle();
    expect(find.text('Branch 3'), findsWidgets);
    expect(find.text('Branch 4'), findsNothing);
  });

  testWidgets('the remembered board still opens first', (tester) async {
    await pumpBoard(tester);
    await tester.tap(inQuick('A-1 Chimney Board'));
    await tester.pumpAndSettle();
    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester);
    expect(opened, ['b-a1']);
    expect(lit(tester), {1});
  });

  testWidgets('a phone at 390x844 fits all three without overflow', (
    tester,
  ) async {
    await pumpBoard(
      tester,
      size: const Size(390, 844),
      boards: [
        standard[0],
        board(
          'b-a1',
          'company',
          'x',
          companyId: 'c-1',
          companyName: 'A-1 Chimney and Masonry of Greater Boston',
        ),
      ],
    );
    expect(tester.takeException(), isNull);
    final picker = find.byKey(const ValueKey('tasks-boards'));
    for (final f in [quick, picker]) {
      expect(f, findsOneWidget);
      expect(tester.getRect(f).right, lessThanOrEqualTo(390));
    }
  });
}
