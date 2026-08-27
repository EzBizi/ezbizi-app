import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('opens an inline exact-view board pane instead of a modal', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient(
        (_) async => http.Response(
          jsonEncode(<String, Object?>{'data': <Object?>[]}),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TasksScreen(api: api, session: Session(api)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('New board'));
    await tester.pump();

    expect(find.text('New board'), findsOneWidget);
    expect(
      find.text('This board will be filed in the view you are looking at.'),
      findsOneWidget,
    );
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('detail separator is keyboard operable', (tester) async {
    var delta = 0.0;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TaskDetailDivider(onResize: (value) => delta += value),
        ),
      ),
    );
    await tester.tap(find.bySemanticsLabel('Resize task details'));
    await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
    expect(delta, 24);
  });

  testWidgets('keeps loaded detail usable when shell provides exactly 750px', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final api = ApiClient(
      client: MockClient((request) async {
        switch (request.url.path) {
          case '/api/v1/tasks/boards':
            return _json(<String, Object?>{
              'data': <Object?>[_board()],
            });
          case '/api/v1/tasks/boards/board-1/rows':
            return _json(<String, Object?>{
              'data': <Object?>[_task()],
              'meta': <String, Object?>{
                'total': 1,
                'page': 1,
                'per_page': 50,
                'last_page': 1,
                'group_counts': <Object?>[],
                'status_summaries': <Object?>[],
                'view_id': 'view-1',
                'view_version': 1,
                'configuration': _configuration(),
              },
            });
          case '/api/v1/tasks/boards/board-1/tasks/task-1':
            return _json(<String, Object?>{'data': _task()});
        }
        return _json(<String, Object?>{'message': 'Unexpected request'}, 500);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Align(
            alignment: Alignment.topLeft,
            child: SizedBox(
              width: 750,
              child: TasksScreen(api: api, session: Session(api)),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Operations'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('task-row-task-1')));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Back to board'), findsOneWidget);
    expect(find.text('Description'), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.tap(find.byTooltip('Back to board'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Show details'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Back to board'), findsOneWidget);
    expect(find.text('Description'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: const <String, String>{'content-type': 'application/json'},
    );

Map<String, Object?> _board() => <String, Object?>{
  'id': 'board-1',
  'name': 'Operations',
  'description': null,
  'visibility': 'scope',
  'company_id': 'company-1',
  'franchise_id': null,
  'folder_id': null,
  'structure_version': 1,
  'archived': false,
  'is_favorite': false,
  'last_opened_at': null,
  'groups': <Object?>[],
  'statuses': <Object?>[],
  'priorities': <Object?>[],
  'core_columns': <Object?>[],
  'custom_columns': <Object?>[],
  'views': <Object?>[],
};

Map<String, Object?> _task() => <String, Object?>{
  'id': 'task-1',
  'board_id': 'board-1',
  'group_id': 'group-1',
  'parent_task_id': null,
  'number': 'TASK-1',
  'title': 'Inspect the constrained layout',
  'owner_user_id': null,
  'collaborator_user_ids': <Object?>[],
  'status_option_id': null,
  'priority_option_id': null,
  'start_on': null,
  'due_on': null,
  'position': 1,
  'version': 1,
  'detail_version': 1,
  'custom_values': <String, Object?>{},
  'group_by_value': 'group-1',
  'subtasks': <Object?>[],
  'record_links': <Object?>[],
};

Map<String, Object?> _configuration() => <String, Object?>{
  'schema': 1,
  'presentation': 'table',
  'filters': <Object?>[],
  'sorts': <Object?>[],
  'hidden_columns': <Object?>[],
  'column_order': <Object?>['task'],
  'column_widths': <String, Object?>{},
  'group_by': null,
  'search': '',
};
