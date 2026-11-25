import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/soft_keyboard.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_columns.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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
    String? franchiseId,
    String? owner,
    int open = 0,
  }) => {
    'id': id,
    'kind': kind,
    'name': name,
    'visibility': 'main',
    'company_id': companyId,
    'company_name': companyName,
    'franchise_id': franchiseId,
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
  List<Map<String, Object?>> folders = [];
  var orgName = 'A1 Group';
  var companies = <Map<String, Object?>>[
    {'id': 'c-1', 'name': 'A-1 Chimney'},
    {'id': 'c-2', 'name': 'Northside Chimney'},
  ];
  Map<String, Object?> groupColours = {};
  var arrange = false;
  final patches = <Object?>[];
  final prefPatches = <Object?>[];

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
              'permissions': [
                'tasks.view',
                'tasks.edit',
                if (arrange) 'tasks.create_boards',
              ],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'scope': {'type': 'company', 'id': 'c-1'},
              'organization': {'id': 'org-1', 'name': orgName},
              'companies': companies,
              'franchises': [
                {'id': 'f-1', 'name': 'Boston', 'company_id': 'c-1'},
              ],
            },
          });
        }
        if (path == '/me/preferences') {
          prefPatches.add(jsonDecode(request.body));
          return json({'data': <String, dynamic>{}});
        }
        if (path == '/task-boards') {
          return json({'data': boards ?? fixture});
        }
        if (path == '/task-board-folders') {
          return json({'data': folders});
        }
        if (path == '/task-board-group-colours') {
          if (request.method == 'PATCH') {
            final b = jsonDecode(request.body) as Map;
            patches.add(b);
            groupColours = {...groupColours, '${b['group']}': b['colour']};
          }
          return json({'data': groupColours});
        }
        if (path.startsWith('/task-boards/') && request.method == 'PATCH') {
          final id = path.split('/').last;
          final body = jsonDecode(request.body) as Map;
          patches.add([id, body]);
          // Saved, as the server keeps it: the next list carries it.
          for (final b in boards ?? fixture) {
            if (b['id'] == id) b.addAll(Map<String, Object?>.from(body));
          }
          return json({'data': <String, dynamic>{}});
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
    expect(inTree('Personal Task Board'), findsOneWidget);
    expect(inTree('Spring push'), findsOneWidget);
    expect(inTree('A-1 Chimney'), findsNWidgets(2)); // the node and its board
    expect(inTree('Estimates follow-up'), findsOneWidget);
    expect(inTree('Boston'), findsOneWidget);
    // No "N open" (Jose 11-20, design 200: Monday shows none).
    expect(inTree('2 open'), findsNothing);

    double y(Finder f) => tester.getTopLeft(f).dy;
    // Jose 11-25: companies first, then what is shared with me of no company.
    expect(y(row('b-me')), lessThan(y(row('b-a1'))));
    // A company's own board, then its custom board, then its branches.
    expect(y(row('b-a1')), lessThan(y(row('b-cu'))));
    expect(y(row('b-cu')), lessThan(y(row('b-bos'))));
    expect(y(row('b-bos')), lessThan(y(row('b-ns'))));
    expect(y(row('b-ns')), lessThan(y(row('b-sh'))));
  });

  testWidgets('a custom board with franchise_id nests under its branch; '
      'without one it stays under its company', (tester) async {
    await pumpBoard(
      tester,
      boards: [
        for (final b in fixture)
          if (b['id'] == 'b-bos') {...b, 'franchise_id': 'f-1'} else b,
        board(
          'b-bc',
          'custom',
          'Boston crew',
          section: 'place',
          companyId: 'c-1',
          companyName: 'A-1 Chimney',
          franchiseId: 'f-1',
        ),
      ],
    );
    double y(Finder f) => tester.getTopLeft(f).dy;
    double x(Finder f) => tester.getTopLeft(f.first).dx;
    Finder label(String t) => inTree(t);
    // Under Boston, indented past it.
    expect(y(row('b-bc')), greaterThan(y(row('b-bos'))));
    expect(x(label('Boston crew')), greaterThan(x(label('Boston'))));
    // No franchise_id: at company level, before the branches.
    expect(y(row('b-cu')), lessThan(y(row('b-bos'))));
    expect(x(label('Estimates follow-up')), x(label('Boston')));
  });

  testWidgets("Everyone's boards appear only when the list holds them "
      '(tasks.view_all)', (tester) async {
    await pumpBoard(tester);
    expect(inTree("EVERYONE'S BOARDS"), findsNothing);

    await tester.pumpWidget(const SizedBox());
    await pumpBoard(tester, boards: [...fixture, everyone]);
    expect(inTree("EVERYONE'S BOARDS"), findsOneWidget);
    // Folded until opened (design 211: 130-odd boards on prod).
    expect(row('b-ben'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('tasks-fold-everyone')));
    await tester.pumpAndSettle();
    // One row per board; a person is a group only with two (Jose 11-25:
    // "Adnan Alvi · 1 board ▸ Adnan Alvi ... what is the point").
    expect(inTree('Ben Okafor · 1 board'), findsNothing);
    expect(inTree('Ben Okafor'), findsOneWidget);

    await tester.pumpWidget(const SizedBox());
    await pumpBoard(
      tester,
      boards: [
        ...fixture,
        everyone,
        board(
          'b-ben2',
          'user',
          'Ben 2',
          section: 'everyone',
          owner: 'Ben Okafor',
        ),
      ],
    );
    expect(
      find.byKey(const ValueKey('tasks-fold-person:Ben Okafor')),
      findsOneWidget,
    );
    expect(row('b-ben2'), findsOneWidget);
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

  testWidgets(
    'Jose 11-18: the company board comes first in Franchise boards, and '
    'Move up reorders, saved as a shared position',
    (tester) async {
      arrange = true;
      addTearDown(() => arrange = false);
      patches.clear();
      await pumpBoard(
        tester,
        boards: [
          board(
            'b-me',
            'user',
            'Maya Lopez',
            section: 'mine',
            owner: 'Maya Lopez',
          ),
          board(
            'b-ct',
            'franchise',
            'CT - Connecticut',
            section: 'shared',
            franchiseId: 'f-1',
          ),
          board(
            'b-dmv',
            'franchise',
            'DMV',
            section: 'shared',
            franchiseId: 'f-2',
          ),
          board(
            'b-a1',
            'company',
            'A-1 Chimney',
            section: 'shared',
            companyId: 'c-1',
          ),
        ],
      );
      double y(String id) => tester.getTopLeft(row(id)).dy;
      expect(y('b-a1'), lessThan(y('b-ct')), reason: 'the company board first');
      expect(y('b-ct'), lessThan(y('b-dmv')));

      await tester.tap(row('b-dmv'), buttons: kSecondaryButton);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('board-up-b-dmv')));
      await tester.pumpAndSettle();
      final positions = {
        for (final p in patches.whereType<List>())
          p[0]: (p[1] as Map)['position'],
      };
      expect(positions['b-dmv'], lessThan(positions['b-ct'] as int));
      // The first board cannot go up: its Move up is off, not a silent no-op.
      await tester.tap(row('b-a1'), buttons: kSecondaryButton);
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<MenuItemButton>(find.byKey(const ValueKey('board-up-b-a1')))
            .onPressed,
        isNull,
      );
      await tester.sendKeyEvent(LogicalKeyboardKey.escape);
      await tester.pumpAndSettle();
      // And the list shows it, after the reload (Jose 11-19: "seems to do nothing").
      expect(y('b-dmv'), lessThan(y('b-ct')));
    },
  );

  testWidgets('My Board is the first entry, above Shared with me, '
      'Companies and Everyone, even when filed in a folder (Jose 11-16)', (
    tester,
  ) async {
    folders = [
      {
        'id': 'fo-1',
        'parent_id': null,
        'name': 'Partners',
        'colour': '#e2445c',
        'position': 0,
      },
    ];
    addTearDown(() => folders = []);
    await pumpBoard(
      tester,
      boards: [
        board('b-mine2', 'custom', 'Aaa plan', section: 'mine', owner: 'Maya'),
        ...fixture.map(
          (b) => b['id'] == 'b-me' ? {...b, 'folder_id': 'fo-1'} : b,
        ),
        board('b-ev', 'user', 'Zed Ray', section: 'everyone', owner: 'Zed Ray'),
      ],
    );
    expect(inTree('Personal Task Board'), findsOneWidget);
    expect(row('b-me'), findsOneWidget, reason: 'not again in its folder');
    double y(Finder f) => tester.getTopLeft(f).dy;
    // Headers are drawn in capitals.
    Finder heading(String text) => find.descendant(
      of: tree,
      matching: find.byWidgetPredicate(
        (w) => w is Text && w.data?.toLowerCase() == text.toLowerCase(),
      ),
    );
    final first = y(row('b-me'));
    for (final below in [
      // Design 211: a custom board I own has its place like any other
      // (here, of no company: the organization's own), not a second list.
      find.byKey(const ValueKey('tasks-fold-org')),
      row('b-mine2'),
      heading('Shared with me'),
      heading("Everyone's boards"),
    ]) {
      expect(below, findsOneWidget);
      expect(first, lessThan(y(below)));
    }
  });

  testWidgets('Jose 11-25: the company is the top level: its branch boards '
      'shared or not (New York), its own folder boards, then other folders', (
    tester,
  ) async {
    folders = [
      {'id': 'fo-a1', 'parent_id': null, 'name': 'A1 Chimney', 'position': 0},
      {'id': 'fo-p', 'parent_id': null, 'name': 'Partners', 'position': 1},
    ];
    addTearDown(() => folders = []);
    Map<String, Object?> a1(
      String id,
      String kind,
      String name,
      String section,
    ) => board(
      id,
      kind,
      name,
      section: section,
      companyId: 'c-1',
      companyName: 'A-1 Chimney',
    );
    await pumpBoard(
      tester,
      boards: [
        a1('b-ct', 'franchise', 'CT - Connecticut', 'shared'),
        a1('b-ny', 'franchise', 'New York', 'place'),
        {
          ...board('b-op', 'custom', 'A1 - Operation', section: 'shared'),
          'folder_id': 'fo-a1',
        },
        {
          ...board('b-pa', 'custom', 'Ohio', section: 'shared'),
          'folder_id': 'fo-p',
        },
        board(
          'b-ev',
          'franchise',
          'Everdoors DMV',
          section: 'place',
          companyId: 'c-2',
          companyName: 'Everdoors',
        ),
      ],
    );
    double y(Finder f) => tester.getTopLeft(f).dy;
    final company = y(inTree('A-1 Chimney'));
    for (final id in ['b-ct', 'b-ny', 'b-op']) {
      expect(company, lessThan(y(row(id))), reason: id);
      expect(
        y(row(id)),
        lessThan(y(inTree('Everdoors'))),
        reason: '$id under A-1 Chimney',
      );
    }
    expect(
      inTree('A1 Chimney'),
      findsNothing,
      reason: "the company's folder is the company",
    );
    expect(inTree('Franchise boards'), findsNothing);
    // Design 211: no FOLDERS section; a folder of no company is under
    // Organization, below the companies, and one of one board is that board
    // with the folder's name beside it.
    expect(inTree('FOLDERS'), findsNothing);
    // The organization first, its own folders, then its companies (Jose
    // 11-25: the organization is "the highest one").
    final org = find.byKey(const ValueKey('tasks-fold-org'));
    expect(y(org), lessThan(y(row('b-pa'))));
    expect(y(row('b-pa')), lessThan(company));
    expect(company, lessThan(y(row('b-ev'))));
    // A folder of one board stays a folder (Jose 11-25, row 68).
    final partners = find.byKey(const ValueKey('tasks-fold-folder:fo-p'));
    expect(partners, findsOneWidget);
    expect(y(partners), lessThan(y(row('b-pa'))));
    expect(
      find.descendant(
        of: row('b-pa'),
        matching: find.textContaining('Partners'),
      ),
      findsNothing,
    );
    // The company's own board first, then its branches.
    expect(y(row('b-op')), lessThan(y(row('b-ct'))));
  });

  testWidgets('design 211: a board of a company is under its company even '
      'when filed in a folder of none; folders are plain; an empty folder is '
      'not drawn', (tester) async {
    folders = [
      {
        'id': 'fo-1',
        'parent_id': null,
        'name': 'Partners',
        'colour': '#e2445c',
        'position': 0,
      },
      {
        'id': 'fo-2',
        'parent_id': null,
        'name': 'Old stuff',
        'colour': '#e2445c',
        'position': 1,
      },
      {'id': 'fo-3', 'parent_id': null, 'name': 'Empty', 'position': 2},
    ];
    addTearDown(() => folders = []);
    await pumpBoard(
      tester,
      boards: [
        board(
          'b-a1',
          'company',
          'A-1 Chimney',
          section: 'place',
          companyId: 'c-1',
          companyName: 'A-1 Chimney',
        ),
        {
          ...board(
            'b-cu',
            'custom',
            'Estimates follow-up',
            section: 'place',
            companyId: 'c-1',
            companyName: 'A-1 Chimney',
          ),
          'folder_id': 'fo-1',
          'colour': '#00c875',
        },
        {
          ...board('b-o1', 'custom', 'Archive one', section: 'place'),
          'folder_id': 'fo-2',
        },
        {
          ...board('b-o2', 'custom', 'Archive two', section: 'place'),
          'folder_id': 'fo-2',
        },
      ],
    );
    expect(inTree('FOLDERS'), findsNothing);
    expect(row('b-cu'), findsOneWidget, reason: 'once, under its company');
    double y(Finder f) => tester.getTopLeft(f).dy;
    expect(y(row('b-a1')), lessThan(y(row('b-cu'))));
    // A board keeps its own colour.
    final icon = tester.widget<Icon>(
      find.descendant(of: row('b-cu'), matching: find.byType(Icon)).first,
    );
    expect(icon.color, EzBiziTheme.boardPalette[4]);
    // Partners holds nothing of no company and Empty holds nothing: neither
    // is drawn. Old stuff, of two boards, is a plain folder under Organization.
    expect(inTree('Partners'), findsNothing);
    expect(inTree('Empty'), findsNothing);
    final folder = find.byKey(const ValueKey('tasks-fold-folder:fo-2'));
    expect(folder, findsOneWidget);
    // The organization's own folder, above its companies.
    expect(y(folder), lessThan(y(row('b-a1'))));
    final label = tester.widget<Text>(
      find.descendant(of: folder, matching: find.text('Old stuff')),
    );
    expect(
      label.style?.color,
      isNot(const Color(0xFFE2445C)),
      reason: 'a folder wears no colour',
    );
  });

  testWidgets("a company group wears the organization's colour, and one who "
      'arranges boards changes it from its right-click menu', (tester) async {
    groupColours = {'company:Northside Chimney': '#00c875'};
    arrange = true;
    patches.clear();
    addTearDown(() {
      groupColours = {};
      arrange = false;
    });
    await pumpBoard(tester);
    // Northside has one board: its row is the company, with its colour.
    final tint = tester.widget<Icon>(
      find
          .descendant(
            of: row('b-ns'),
            matching: find.byWidgetPredicate(
              (w) => w is Icon && w.icon == Icons.circle,
            ),
          )
          .first,
    );
    expect(tint.color, EzBiziTheme.boardPalette[4]);
    // Every company has its dot (one look per level); no colour set: outline.
    Color? a1() => tester
        .widget<Icon>(
          find.byKey(const ValueKey('group-tint-company:A-1 Chimney')),
        )
        .color;
    expect(a1(), isNot(EzBiziTheme.boardPalette[4]));

    await tester.tap(
      find.byKey(const ValueKey('tasks-fold-company:A-1 Chimney')),
      buttons: kSecondaryButton,
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('group-colour-company:A-1 Chimney')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('group-colour-company:A-1 Chimney-#e2445c')),
    );
    await tester.pumpAndSettle();
    expect(patches.single, {
      'group': 'company:A-1 Chimney',
      'colour': '#e2445c',
    });
    expect(a1(), const Color(0xFFE2445C));
  });

  testWidgets('design 200: a star puts a board in Favorites, on top, saved '
      'as this person’s preference; no "N open"', (tester) async {
    prefPatches.clear();
    await pumpBoard(tester);
    expect(find.byKey(const ValueKey('tasks-fold-favorites')), findsNothing);
    expect(inTree('2 open'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('board-star-b-bos')).first);
    await tester.pumpAndSettle();
    expect(prefPatches.last, {
      'preferences': {
        'task_board.favorites': ['b-bos'],
      },
    });
    final favourites = find.byKey(const ValueKey('tasks-fold-favorites'));
    expect(favourites, findsOneWidget);
    // Boston twice: under Favorites, at the top, and in its place.
    expect(row('b-bos'), findsNWidgets(2));
    expect(
      tester.getTopLeft(row('b-bos').first).dy,
      lessThan(tester.getTopLeft(row('b-me')).dy),
    );

    await tester.tap(find.byKey(const ValueKey('board-star-b-bos')).first);
    await tester.pumpAndSettle();
    expect(prefPatches.last, {
      'preferences': {'task_board.favorites': <Object?>[]},
    });
    expect(favourites, findsNothing);
  });

  // Design 211 on Jose's own list (prod, 11-25): what he complained about,
  // measured on what he sees, not on invented boards.
  testWidgets('design 211 on the real Triplex list: company board first, '
      'Branches, folders inside their company, Templates, no FOLDERS', (
    tester,
  ) async {
    final prod =
        jsonDecode(
              File(
                'test/fixtures/task_board_tree_prod.json',
              ).readAsStringSync(),
            )
            as Map<String, dynamic>;
    final saved = companies;
    orgName = 'Triplex Group';
    companies = [
      {'id': 'c-a1', 'name': 'A-1 Chimney'},
      {'id': 'c-ev', 'name': 'Everdoors'},
      {'id': 'c-tx', 'name': 'Triplex Group'},
    ];
    folders = [
      for (final f in prod['folders'] as List) Map<String, Object?>.from(f),
    ];
    addTearDown(() {
      folders = [];
      companies = saved;
      orgName = 'A1 Group';
    });
    await pumpBoard(
      tester,
      size: const Size(1400, 2600),
      boards: [
        for (final b in prod['boards'] as List) Map<String, Object?>.from(b),
      ],
    );
    double y(Finder f) => tester.getTopLeft(f.first).dy;
    String id(String name) =>
        (prod['boards'] as List).firstWhere((b) => b['name'] == name)['id']
            as String;
    Finder board(String name) => row(id(name));

    expect(inTree('FOLDERS'), findsNothing);
    expect(inTree('Dark Theme · 1 board'), findsNothing);
    // A-1 Chimney first, its company board first inside it, then Branches,
    // then its Monday folders.
    final a1 = find.byKey(const ValueKey('tasks-fold-company:A-1 Chimney'));
    final everdoors = find.byKey(
      const ValueKey('tasks-fold-company:Everdoors'),
    );
    expect(y(a1), lessThan(y(everdoors)));
    expect(y(a1), lessThan(y(board('A1 - Operation'))));
    final branches = find.byKey(
      const ValueKey('tasks-fold-branches:A-1 Chimney'),
    );
    expect(y(board('A1 - Operation')), lessThan(y(branches)));
    expect(y(branches), lessThan(y(board('DMV'))));
    // Office Management and Marketing hold only people's boards, cut here.
    expect(y(board('TX- Texas')), lessThan(y(inTree('Field Management'))));
    expect(y(inTree('Field Management')), lessThan(y(everdoors)));
    // Everdoors: its Operation board first; Partners, of one board, is a
    // folder holding it.
    expect(y(everdoors), lessThan(y(board('Everdoors - Operation'))));
    expect(y(board('Everdoors - Operation')), lessThan(y(board('Yosef'))));
    final partners = find.byKey(
      const ValueKey('tasks-fold-folder:01a1d6b2-be35-73d2-9681-eac7a799977d'),
    );
    expect(y(everdoors), lessThan(y(partners)));
    expect(y(partners), lessThan(y(board('Yosef'))));
    expect(y(board('Everdoors - Brand')), greaterThan(y(everdoors)));
    // Triplex Group is the organization: the root, its own boards first,
    // then its companies, each level one indent step in (Jose 11-25).
    final org = find.byKey(const ValueKey('tasks-fold-org'));
    expect(
      find.descendant(of: org, matching: find.text('Triplex Group')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('tasks-fold-company:Triplex Group')),
      findsNothing,
    );
    expect(y(org), lessThan(y(board('Triplex Group - Files'))));
    expect(
      y(board('Triplex Group - Files')),
      lessThan(y(board('Reviews & Complaints'))),
    );
    expect(y(board('Reviews & Complaints')), lessThan(y(a1)));
    expect(y(board('Analytics Dashboard')), lessThan(y(a1)));
    double x(Finder f) => tester
        .getTopLeft(find.descendant(of: f, matching: find.byType(Text)).first)
        .dx;
    expect(x(org), lessThan(x(a1)));
    expect(x(a1), lessThan(x(board('A1 - Operation'))));
    expect(x(a1), lessThan(x(branches)));
    expect(x(branches), lessThan(x(board('DMV'))));
    // Same level, same x, whatever the row is (board, folder, company).
    expect(x(board('A1 - Operation')), x(branches));
    expect(x(a1), x(everdoors));
    expect(x(board('Triplex Group - Files')), x(a1));
    expect(x(board('DMV')) - x(branches), x(branches) - x(a1));
    expect(x(a1) - x(org), x(branches) - x(a1));
    expect(x(board('Reviews & Complaints')), x(board('A1 - Operation')) - 14);
    // Then Templates, then Everyone's boards one row each.
    final templates = inTree('TEMPLATES');
    expect(y(board('Everdoors - Brand')), lessThan(y(templates)));
    for (final t in [
      'New Brand Template',
      'New Franchise Template',
      'New Location Template',
    ]) {
      expect(board(t), findsOneWidget, reason: t);
      expect(y(templates), lessThan(y(board(t))), reason: t);
    }
    expect(inTree('Adnan Alvi · 1 board'), findsNothing);
    expect(
      row('ev-1'),
      findsNothing,
      reason: "Everyone's boards starts folded",
    );
    await tester.tap(find.byKey(const ValueKey('tasks-fold-everyone')));
    await tester.pumpAndSettle();
    expect(row('ev-1'), findsOneWidget);
    // Every board once (Favorites aside).
    for (final b in prod['boards'] as List) {
      expect(row('${b['id']}'), findsOneWidget, reason: '${b['name']}');
    }
    expect(tester.takeException(), isNull);
  });

  // Task Board 4ed2eea22: the server names each company's working board
  // (company_board) and each folder's company (company_id).
  testWidgets('company_board leads its company and an empty company board '
      'beside it is not listed; a folder goes by its company_id', (
    tester,
  ) async {
    folders = [
      {
        'id': 'fo-ops',
        'parent_id': null,
        'name': 'Field Ops',
        'company_id': 'c-1',
        'position': 0,
      },
    ];
    addTearDown(() => folders = []);
    Map<String, Object?> a1(String id, String kind, String name) => board(
      id,
      kind,
      name,
      section: 'place',
      companyId: 'c-1',
      companyName: 'A-1 Chimney',
    );
    await pumpBoard(
      tester,
      boards: [
        {...a1('b-empty', 'company', 'A-1 Chimney'), 'company_board': false},
        {...a1('b-zz', 'custom', 'Zz notes'), 'company_board': false},
        {...a1('b-op', 'custom', 'Zulu - Operation'), 'company_board': true},
        a1('b-bos', 'franchise', 'Boston'),
        {
          ...board('b-crew', 'custom', 'Crew list', section: 'place'),
          'folder_id': 'fo-ops',
        },
        {
          ...board('b-crew2', 'custom', 'Crew two', section: 'place'),
          'folder_id': 'fo-ops',
        },
      ],
    );
    double y(Finder f) => tester.getTopLeft(f).dy;
    expect(row('b-empty'), findsNothing);
    final company = y(
      find.byKey(const ValueKey('tasks-fold-company:A-1 Chimney')),
    );
    // Named Zulu, still first: it is the company's board.
    expect(company, lessThan(y(row('b-op'))));
    expect(y(row('b-op')), lessThan(y(row('b-zz'))));
    expect(y(row('b-zz')), lessThan(y(row('b-bos'))));
    // "Field Ops" is named for no company, but its company_id puts it, and
    // its boards of no company, under A-1 Chimney.
    final folder = find.byKey(const ValueKey('tasks-fold-folder:fo-ops'));
    expect(company, lessThan(y(folder)));
    expect(y(folder), lessThan(y(row('b-crew'))));
  });

  // Jose 11-25 on the picker: "It should follow your own permissions and
  // hierarchy". Everything reachable, a sub-company under its parent, and no
  // line about the Company · Branch picker.
  testWidgets('a sub-company sits one step under its parent company; the '
      'picker is not mentioned', (tester) async {
    final saved = companies;
    companies = [
      {'id': 'c-1', 'name': 'A-1 Chimney'},
      {'id': 'c-3', 'name': 'AngiSweep', 'parent_company_id': 'c-1'},
      {'id': 'c-2', 'name': 'Northside Chimney'},
    ];
    addTearDown(() => companies = saved);
    Map<String, Object?> of(
      String id,
      String kind,
      String name,
      String co,
      String coName,
    ) => board(
      id,
      kind,
      name,
      section: 'place',
      companyId: co,
      companyName: coName,
    );
    await pumpBoard(
      tester,
      boards: [
        of('b-a1', 'company', 'A-1 Chimney', 'c-1', 'A-1 Chimney'),
        of('b-bos', 'franchise', 'Boston', 'c-1', 'A-1 Chimney'),
        of('b-as', 'company', 'AngiSweep', 'c-3', 'AngiSweep'),
        of('b-as2', 'franchise', 'Texas', 'c-3', 'AngiSweep'),
        of('b-ns', 'company', 'Northside Chimney', 'c-2', 'Northside Chimney'),
      ],
    );
    expect(find.byKey(const ValueKey('tasks-boards-in-view')), findsNothing);
    expect(inTree('Company · Branch picker'), findsNothing);
    final a1 = find.byKey(const ValueKey('tasks-fold-company:A-1 Chimney'));
    final angi = find.byKey(const ValueKey('tasks-fold-company:AngiSweep'));
    double y(Finder f) => tester.getTopLeft(f).dy;
    double x(Finder f) => tester
        .getTopLeft(find.descendant(of: f, matching: find.byType(Text)).first)
        .dx;
    // Under A-1 Chimney, after its own boards, before the next company.
    expect(y(a1), lessThan(y(row('b-bos'))));
    expect(y(row('b-bos')), lessThan(y(angi)));
    expect(y(angi), lessThan(y(row('b-as2'))));
    expect(y(row('b-as2')), lessThan(y(row('b-ns'))));
    // One step in, and its boards one more.
    expect(x(angi) - x(a1), x(row('b-a1')) - x(a1));
    expect(x(row('b-as')) - x(angi), x(row('b-a1')) - x(a1));
  });

  // Mobile 11-25: on a phone a finger drag on the tree picked a board up
  // instead of scrolling, and a drop is a production write. Touch gets no
  // row drag (a long press opens the row's menu); a mouse still drags.
  for (final touch in [true, false]) {
    testWidgets(
      'one who arranges boards: ${touch ? 'a finger scrolls' : 'a mouse drags'}',
      (tester) async {
        final platform = hasSoftKeyboard;
        hasSoftKeyboard = () => touch;
        arrange = true;
        addTearDown(() {
          hasSoftKeyboard = platform;
          arrange = false;
        });
        await pumpBoard(tester);
        expect(
          find.byKey(const ValueKey('tasks-board-drag-b-bos')),
          touch ? findsNothing : findsOneWidget,
        );
      },
    );
  }

  // Jose 11-25, row 68: colour dots, not grey; a line where a reorder lands,
  // a fill only when the drop goes into a folder.
  testWidgets('row 68: dots in colour; reorder shows a line, into a folder '
      'shows a fill', (tester) async {
    final platform = hasSoftKeyboard;
    hasSoftKeyboard = () => false;
    arrange = true;
    folders = [
      {'id': 'fo-x', 'parent_id': null, 'name': 'Archive', 'position': 0},
    ];
    addTearDown(() {
      hasSoftKeyboard = platform;
      arrange = false;
      folders = [];
    });
    await pumpBoard(
      tester,
      boards: [
        ...fixture,
        {
          ...board('b-x1', 'custom', 'Old one', section: 'place'),
          'folder_id': 'fo-x',
        },
      ],
    );
    final dot = tester.widget<Icon>(
      find.byKey(const ValueKey('group-tint-company:A-1 Chimney')),
    );
    expect(EzBiziTheme.boardPalette.sublist(1), contains(dot.color));

    Future<void> hold(String from, Finder over, String look) async {
      final g = await tester.startGesture(
        tester.getCenter(find.byKey(ValueKey('tasks-board-drag-$from'))),
        kind: PointerDeviceKind.mouse,
      );
      await tester.pump();
      await g.moveTo(tester.getCenter(over) + const Offset(0, 1));
      await tester.pump();
      await g.moveTo(tester.getCenter(over));
      await tester.pump();
      expect(find.byKey(ValueKey(look)), findsOneWidget, reason: look);
      expect(
        find.byKey(
          ValueKey(
            look == 'tasks-drop-line' ? 'tasks-drop-into' : 'tasks-drop-line',
          ),
        ),
        findsNothing,
      );
      await g.cancel();
      await tester.pumpAndSettle();
    }

    await hold('b-cu', row('b-a1'), 'tasks-drop-line');
    await hold(
      'b-cu',
      find.byKey(const ValueKey('tasks-fold-folder:fo-x')),
      'tasks-drop-into',
    );
  });

  // Row 68 (desk relay of Jose 11-25): brand colours, a personal folder
  // under Everyone's boards, one folder icon, the line at the landing level.
  testWidgets('row 68: brand colours, personal folders, one folder icon, an '
      'indented line', (tester) async {
    final platform = hasSoftKeyboard;
    hasSoftKeyboard = () => false;
    arrange = true;
    folders = [
      {
        'id': 'fo-dark',
        'parent_id': null,
        'name': 'Dark Theme',
        'position': 0,
        'owner': {'id': 'u-d', 'name': 'Dieuve Ray'},
      },
      {'id': 'fo-m', 'parent_id': null, 'name': 'Marketing', 'position': 1},
    ];
    addTearDown(() {
      hasSoftKeyboard = platform;
      arrange = false;
      folders = [];
    });
    Map<String, Object?> a1(String id, String kind, String name) => {
      ...board(
        id,
        kind,
        name,
        section: 'place',
        companyId: 'c-1',
        companyName: 'A-1 Chimney',
      ),
      'company_colour': '#e2445c',
      'organization_colour': '#0086c0',
    };
    await pumpBoard(
      tester,
      boards: [
        a1('b-a1', 'company', 'A-1 Chimney'),
        a1('b-bos', 'franchise', 'Boston'),
        a1('b-ct', 'franchise', 'Hartford'),
        {
          ...board('b-an', 'custom', 'Analytics Dashboard', section: 'mine'),
          'folder_id': 'fo-dark',
        },
        {
          ...board('b-ro', 'custom', 'Rodrigo Mena', section: 'place'),
          'folder_id': 'fo-m',
        },
      ],
    );
    Color? dot(String key) =>
        tester.widget<Icon>(find.byKey(ValueKey('group-tint-$key'))).color;
    expect(dot('company:A-1 Chimney'), const Color(0xFFE2445C));
    final org = find.byKey(const ValueKey('tasks-fold-org'));
    final orgDot = tester.widget<Icon>(
      find
          .descendant(
            of: org,
            matching: find.byWidgetPredicate(
              (w) => w is Icon && w.icon == Icons.circle,
            ),
          )
          .first,
    );
    expect(orgDot.color, const Color(0xFF0086C0));

    // Dark Theme is Dieuve's: under Everyone's boards, not the organization.
    expect(
      find.byKey(const ValueKey('tasks-fold-folder:fo-dark')),
      findsNothing,
    );
    expect(row('b-an'), findsNothing, reason: "Everyone's starts folded");
    await tester.tap(find.byKey(const ValueKey('tasks-fold-everyone')));
    await tester.pumpAndSettle();
    double y(Finder f) => tester.getTopLeft(f).dy;
    expect(y(find.text("EVERYONE'S BOARDS")), lessThan(y(row('b-an'))));

    // One folder icon: Branches draws the same one as a real folder.
    IconData? folderIcon(Finder row) => tester
        .widgetList<Icon>(find.descendant(of: row, matching: find.byType(Icon)))
        .map((i) => i.icon)
        .firstWhere(
          (i) => i != Icons.arrow_drop_down && i != Icons.arrow_right,
        );
    expect(
      folderIcon(find.byKey(const ValueKey('tasks-fold-branches:A-1 Chimney'))),
      folderIcon(find.byKey(const ValueKey('tasks-fold-folder:fo-m'))),
    );

    // The insertion line starts where the target row's icon does.
    final g = await tester.startGesture(
      tester.getCenter(find.byKey(const ValueKey('tasks-board-drag-b-ct'))),
      kind: PointerDeviceKind.mouse,
    );
    await tester.pump();
    await g.moveTo(tester.getCenter(row('b-bos')) + const Offset(0, 1));
    await tester.pump();
    await g.moveTo(tester.getCenter(row('b-bos')));
    await tester.pump();
    final line = find.byKey(const ValueKey('tasks-drop-line'));
    expect(line, findsOneWidget);
    final icon = find
        .descendant(of: row('b-bos'), matching: find.byType(Icon))
        .first;
    expect(tester.getTopLeft(line).dx, closeTo(tester.getTopLeft(icon).dx, 1));
    await g.cancel();
    await tester.pumpAndSettle();
  });
}
