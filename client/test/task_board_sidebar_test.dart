import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 152 (Jose, 2026-10-27, 1 and 4): the boards as a tree down the
/// left, the same tree full screen on a phone, and formatted numbers.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  // GET /task-boards as TaskBoardsController::index writes it.
  Map<String, Object?> board(
    String id,
    String kind,
    String name, {
    required String section,
    String? companyId,
    String? companyName,
    String? owner,
    int open = 0,
  }) => {
    'id': id,
    'kind': kind,
    'name': name,
    'visibility': 'main',
    'company_id': companyId,
    'company_name': companyName,
    'open_for_me': open,
    'can_edit': true,
    'my_role': section == 'mine' ? 'owner' : null,
    'can_manage': false,
    'section': section,
    'owner': owner == null
        ? null
        : {'id': section == 'mine' ? 'me' : 'u-$owner', 'name': owner},
  };

  final fixture = [
    board('b-me', 'user', 'Maya Lopez', section: 'mine', owner: 'Maya Lopez'),
    board(
      'b-sh',
      'custom',
      'Spring push',
      section: 'shared',
      owner: 'Dana Reyes',
    ),
    board(
      'b-a1',
      'company',
      'A-1 Chimney',
      section: 'place',
      companyId: 'c-1',
      companyName: 'A-1 Chimney',
    ),
    board(
      'b-cu',
      'custom',
      'Estimates follow-up',
      section: 'place',
      companyId: 'c-1',
      companyName: 'A-1 Chimney',
    ),
    board(
      'b-bos',
      'franchise',
      'Boston',
      section: 'place',
      companyId: 'c-1',
      companyName: 'A-1 Chimney',
      open: 2,
    ),
    board(
      'b-ns',
      'company',
      'Northside Chimney',
      section: 'place',
      companyId: 'c-2',
      companyName: 'Northside Chimney',
    ),
  ];
  final everyone = board(
    'b-ben',
    'user',
    'Ben Okafor',
    section: 'everyone',
    owner: 'Ben Okafor',
  );

  late List<String> opened;

  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbtree');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  Future<void> pumpBoard(
    WidgetTester tester, {
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
              'scope': {'type': 'company', 'id': 'c-1'},
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
          return json({'data': boards ?? fixture});
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

  final tree = find.byKey(const ValueKey('tasks-boards'));
  Finder inTree(String text) =>
      find.descendant(of: tree, matching: find.text(text));
  Finder row(String id) => find.byKey(ValueKey('tasks-board-$id'));

  testWidgets('the tree: my board, shared, companies with their boards and '
      'branches, in that order; no Other boards picker', (tester) async {
    await pumpBoard(tester);
    expect(find.text('Other boards'), findsNothing);
    expect(inTree('My board'), findsOneWidget);
    expect(inTree('Spring push · from Dana Reyes'), findsOneWidget);
    expect(inTree('A-1 Chimney'), findsNWidgets(2)); // the node and its board
    expect(inTree('Estimates follow-up'), findsOneWidget);
    expect(inTree('Boston'), findsOneWidget);
    expect(inTree('2 open'), findsOneWidget);

    double y(Finder f) => tester.getTopLeft(f).dy;
    expect(y(row('b-me')), lessThan(y(row('b-sh'))));
    expect(y(row('b-sh')), lessThan(y(row('b-a1'))));
    // A company's own board, then its custom board, then its branches.
    expect(y(row('b-a1')), lessThan(y(row('b-cu'))));
    expect(y(row('b-cu')), lessThan(y(row('b-bos'))));
    expect(y(row('b-bos')), lessThan(y(row('b-ns'))));
  });

  testWidgets("Everyone's boards appear only when the list holds them "
      '(tasks.view_all)', (tester) async {
    await pumpBoard(tester);
    expect(inTree("EVERYONE'S BOARDS"), findsNothing);

    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester, boards: [...fixture, everyone]);
    expect(inTree("EVERYONE'S BOARDS"), findsOneWidget);
    expect(inTree('Ben Okafor · 1 board'), findsOneWidget);
    expect(inTree("Ben Okafor's board"), findsOneWidget);
  });

  testWidgets('search filters the tree, and a branch found says its company', (
    tester,
  ) async {
    await pumpBoard(tester, boards: [...fixture, everyone]);
    await tester.enterText(
      find.byKey(const ValueKey('tasks-boards-search')),
      'bos',
    );
    await tester.pumpAndSettle();
    expect(inTree('A-1 Chimney · Boston'), findsOneWidget);
    expect(row('b-ns'), findsNothing);
    expect(row('b-me'), findsNothing);
    expect(row('b-ben'), findsNothing);

    // An owner's name finds their boards.
    await tester.enterText(
      find.byKey(const ValueKey('tasks-boards-search')),
      'okafor',
    );
    await tester.pumpAndSettle();
    expect(row('b-ben'), findsOneWidget);
    expect(row('b-bos'), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('tasks-boards-search')),
      'zzz',
    );
    await tester.pumpAndSettle();
    expect(inTree('No board matches "zzz".'), findsOneWidget);
  });

  testWidgets('a folded group, the collapsed strip and the dragged width '
      'are remembered', (tester) async {
    await pumpBoard(tester);
    await tester.tap(
      find.byKey(const ValueKey('tasks-fold-company:A-1 Chimney')),
    );
    await tester.pumpAndSettle();
    expect(row('b-bos'), findsNothing);
    expect(row('b-ns'), findsOneWidget);

    final width = tester.getSize(tree).width;
    await tester.drag(
      find.byKey(const Key('tasks-boards-edge')),
      const Offset(60, 0),
    );
    await tester.pumpAndSettle();
    expect(tester.getSize(tree).width, closeTo(width + 60, 1));

    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester);
    expect(row('b-bos'), findsNothing);
    expect(row('b-ns'), findsOneWidget);
    expect(tester.getSize(tree).width, closeTo(width + 60, 1));

    await tester.tap(find.byKey(const Key('tasks-boards-collapse')));
    await tester.pumpAndSettle();
    expect(row('b-ns'), findsNothing);
    expect(tester.getSize(tree).width, 40);

    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester);
    expect(tester.getSize(tree).width, 40);
    await tester.tap(find.byKey(const Key('tasks-boards-expand')));
    await tester.pumpAndSettle();
    expect(row('b-ns'), findsOneWidget);
  });

  testWidgets('selecting a board in the tree loads it', (tester) async {
    await pumpBoard(tester);
    expect(opened, ['b-me']);
    await tester.tap(row('b-bos'));
    await tester.pumpAndSettle();
    expect(opened.last, 'b-bos');
  });

  testWidgets('a phone at 390x844 has no sidebar; the title opens the tree '
      'full screen with search, without overflow', (tester) async {
    await pumpBoard(tester, size: const Size(390, 844));
    expect(find.byKey(const Key('tasks-boards-edge')), findsNothing);
    expect(row('b-bos'), findsNothing);

    await tester.tap(tree);
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(DropdownButton<int>), findsNothing);
    expect(find.byKey(const ValueKey('tasks-boards-search')), findsOneWidget);
    expect(tester.getRect(row('b-bos')).right, lessThanOrEqualTo(390));

    await tester.tap(row('b-bos'));
    await tester.pumpAndSettle();
    expect(opened.last, 'b-bos');
    expect(row('b-bos'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  group('phone cells', () {
    test('a US number reads (404) 555-0142', () {
      expect(formatCellPhone('+14045550142'), '(404) 555-0142');
    });
    test('another country reads + and grouped digits', () {
      expect(formatCellPhone('+442079460958'), '+44 20 7946 0958');
    });
    test('anything else passes through', () {
      for (final raw in ['call me', '555-0142', '+1404', '+1 404 555', '']) {
        expect(formatCellPhone(raw), raw);
      }
    });
  });

  group('number cells', () {
    test('thousands separators', () {
      expect(formatCellNumber(1234567, null), '1,234,567');
      expect(formatCellNumber(999, null), '999');
    });
    test('the unit, before a currency and after anything else', () {
      expect(formatCellNumber(12450, r'$'), r'$12,450');
      expect(formatCellNumber(1200, 'ft'), '1,200 ft');
    });
    test('decimals are kept', () {
      expect(formatCellNumber(1234.5, null), '1,234.5');
      expect(formatCellNumber('0.25', 'kg'), '0.25 kg');
    });
    test('negative', () {
      expect(formatCellNumber(-1200, r'$'), r'-$1,200');
      expect(formatCellNumber(-5000.75, 'ft'), '-5,000.75 ft');
    });
  });
}
