import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/jobs/project_management_assignments.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/pm_server.dart';

/// Project Management › Assignments (design 143, Jose's correction): the
/// payment rule's Task Board cards, from GET jobs/project-management/cards,
/// grouped by each board's Technician column. Every name invented.
void main() {
  Map<String, dynamic> board({bool canEdit = true}) => {
    'id': 'b-1',
    'name': 'Northgate board',
    'franchise_id': 'fr-1',
    'can_edit': canEdit,
    'statuses': [
      {'id': 's-1', 'name': 'Not started', 'colour': null, 'is_done': false},
    ],
    'columns': [
      {
        'id': 'c-tech',
        'name': 'technician',
        'type': 'dropdown',
        'position': 1,
        'settings': {
          'options': [
            {'id': 'o-sam', 'label': 'Sam Ortiz'},
            {'id': 'o-pat', 'label': 'Pat Lin'},
          ],
        },
      },
    ],
    'builtin_columns': <String, dynamic>{},
    'assignable': <Object>[],
  };
  Map<String, dynamic> task(String id, String title, [String? tech]) => {
    'id': id,
    'board_id': 'b-1',
    'title': title,
    'status_id': 's-1',
    'values': {'c-tech': ?tech},
    'job': {'id': 'job-$id', 'number': '10$id'},
    'franchise': {'id': 'fr-1', 'name': 'Main'},
  };
  Map<String, dynamic> cards({bool canEdit = true}) => {
    'data': {
      'boards': [board(canEdit: canEdit)],
      'tasks': [
        task('t-1', 'Job 101 · Ada Park'),
        task('t-2', 'Job 102 · Ben Roy', 'o-sam'),
        task('t-3', 'Job 103 · Cy Dunn', 'o-sam'),
      ],
    },
  };

  Future<PmServer> open(
    WidgetTester tester,
    Size size, {
    bool served = true,
    bool canEdit = true,
  }) async {
    final server = PmServer(
      meOverride: const {
        'companies': [
          {'id': 'co-1', 'name': 'Company A', 'parent_company_id': null},
        ],
        'franchises': [
          {'id': 'fr-1', 'name': 'Main', 'company_id': 'co-1'},
        ],
      },
    );
    if (served) server.cards = cards(canEdit: canEdit);
    await server.pump(tester, size: size);
    await tester.ensureVisible(find.text('Assignments').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Assignments').first);
    await tester.pumpAndSettle();
    return server;
  }

  Finder col(String name) => find.byKey(ValueKey('pm-assign-col-$name'));
  Finder card(String id) => find.byKey(ValueKey('pm-assign-card-$id'));
  Finder techOf(String id) => find.descendant(
    of: find.byKey(ValueKey('pm-assign-tech-$id')),
    matching: find.text('—'),
  );

  test('cards group by the Technician column, any case, To assign first', () {
    final data = cards()['data'] as Map;
    final groups = pmGroupCards(
      [
        for (final t in data['tasks'] as List)
          (t as Map).cast<String, dynamic>(),
      ],
      {'b-1': board()},
    );
    expect(
      [for (final g in groups) (g.$1, g.$2.length)],
      [('To assign', 1), ('Sam Ortiz', 2)],
    );
  });

  testWidgets('a server without the endpoint: one sentence', (tester) async {
    await open(tester, const Size(1440, 1000), served: false);
    expect(find.text(ProjectManagementAssignments.notYet), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('columns, the branch on each card, and q', (tester) async {
    final server = await open(tester, const Size(1440, 1000));
    expect(col('To assign'), findsOneWidget);
    expect(col('Sam Ortiz'), findsOneWidget);
    expect(
      find.descendant(of: col('Sam Ortiz'), matching: card('t-2')),
      findsOneWidget,
    );
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('pm-assign-branch-t-2')))
          .data,
      'Company A · Main',
    );
    expect(
      find.descendant(of: card('t-2'), matching: find.text('Not started')),
      findsOneWidget,
    );

    await tester.enterText(
      find.byKey(const ValueKey('pm-assign-search')),
      'Ben',
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(server.get('/jobs/project-management/cards').last.queryParameters, {
      'q': 'Ben',
    });
  });

  Future<void> pickPat(WidgetTester tester) async {
    await tester.tap(techOf('t-1'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('column-option-c-tech-o-pat')));
    await tester.pumpAndSettle();
  }

  testWidgets('setting Technician PATCHes the task and moves the card', (
    tester,
  ) async {
    final server = await open(tester, const Size(1440, 1000));
    await pickPat(tester);
    final write = server.writes().single;
    expect(write.method, 'PATCH');
    expect(write.uri.path, endsWith('/tasks/t-1'));
    expect(write.body, {
      'values': {'c-tech': 'o-pat'},
    });
    expect(
      find.descendant(of: col('Pat Lin'), matching: card('t-1')),
      findsOneWidget,
    );
  });

  testWidgets('a refused edit goes back, with the server sentence', (
    tester,
  ) async {
    final server = await open(tester, const Size(1440, 1000));
    server.taskRefusal = 'That technician is not on this board.';
    await pickPat(tester);
    expect(
      find.descendant(of: col('To assign'), matching: card('t-1')),
      findsOneWidget,
    );
    expect(col('Pat Lin'), findsNothing);
    expect(find.text('That technician is not on this board.'), findsOneWidget);
  });

  testWidgets('a view-only board writes nothing', (tester) async {
    final server = await open(tester, const Size(1440, 1000), canEdit: false);
    await tester.tap(techOf('t-1'));
    await tester.pumpAndSettle();
    final option = find.byKey(const ValueKey('column-option-c-tech-o-pat'));
    if (option.evaluate().isNotEmpty) {
      await tester.tap(option);
      await tester.pumpAndSettle();
    }
    expect(server.writes(), isEmpty);
    expect(
      find.descendant(of: col('To assign'), matching: card('t-1')),
      findsOneWidget,
    );
  });

  testWidgets('a phone shows one column at a time, chosen by a picker', (
    tester,
  ) async {
    await open(tester, const Size(390, 900));
    expect(
      find.byKey(const ValueKey('pm-assign-column-picker')),
      findsOneWidget,
    );
    expect(col('To assign'), findsOneWidget);
    expect(col('Sam Ortiz'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the job number opens the job timeline beside the board', (
    tester,
  ) async {
    final server = await open(tester, const Size(1440, 1000));
    await tester.tap(find.byKey(const ValueKey('pm-assign-job-t-2')));
    await tester.pumpAndSettle();
    expect(
      server.get('/jobs/project-management').last.queryParametersAll['ids[]'],
      ['job-t-2'],
    );
    expect(find.byKey(const ValueKey('pm-timeline-close')), findsWidgets);
  });

  testWidgets('a saved Technician reads the open job timeline again', (
    tester,
  ) async {
    final server = await open(tester, const Size(1440, 1000));
    await tester.tap(find.byKey(const ValueKey('pm-assign-job-t-1')));
    await tester.pumpAndSettle();
    final before = server.get('/jobs/project-management').length;
    await pickPat(tester);
    expect(server.get('/jobs/project-management').length, before + 1);
  });

  test(
    'a refused value reads errors["values.<column>"], as Task Board does',
    () {
      // Task Board's _setValue and its panel show ApiResponse.reason too.
      const res = ApiResponse(
        statusCode: 422,
        body: {
          'message': 'The given data was invalid.',
          'errors': {
            'values.c-tech': ['Pat Lin is not on this job branch.'],
          },
        },
      );
      expect(res.reason, 'Pat Lin is not on this job branch.');
    },
  );
}
