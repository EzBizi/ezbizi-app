import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_presentation_bar.dart';
import 'package:ezbizi_client/screens/tasks/task_views_bar.dart';
import 'package:ezbizi_client/screens/tasks_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Regression coverage for the two chrome bars that sit above every board
/// view — `TaskPresentationBar` and `TaskViewsBar`.
///
/// ── Why this is a screen-level test, not a bar-level one ─────────
///
/// Task 10 already asserted "no horizontal scroller at 375px" against
/// `TaskKanbanView` in isolation. That assertion was always true and always
/// useless: `TaskKanbanView` does not contain either bar — they are rendered
/// by `TasksScreen._boardControls()`, a sibling above the presentation body,
/// not a descendant of it. A widget that cannot possibly hold the offending
/// scroller will never fail the test, no matter how badly the real screen
/// clips.
///
/// This test mounts the real `TasksScreen` with a board that has a saved
/// view (so `TaskViewsBar` renders at all) and asserts inside the actual
/// `key: 'task-board-controls'` container both bars share with whatever
/// gets added between them later — not inside either bar's own subtree,
/// which a future third bar slipped in between them would sit outside of.
void main() {
  testWidgets(
    'neither chrome bar scrolls horizontally at 375px, on the real screen',
    (tester) async {
      tester.view.physicalSize = const Size(375, 812);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      final api = ApiClient(
        client: MockClient((request) async {
          switch (request.url.path) {
            case '/api/v1/tasks/boards':
              return _json(<String, Object?>{
                'data': <Object?>[_board()],
                'meta': _directoryMeta,
              });
            case '/api/v1/tasks/folders':
              return _json(<String, Object?>{'data': <Object?>[]});
            case '/api/v1/tasks/boards/board-1/rows':
              return _json(<String, Object?>{
                'data': <Object?>[_task()],
                'meta': _rowsMeta,
              });
            case '/api/v1/tasks/boards/board-1/preference':
              return _json(<String, Object?>{
                'data': <String, Object?>{
                  'board_id': 'board-1',
                  'is_favorite': false,
                  'last_opened_at': null,
                },
              });
            case '/api/v1/tasks/boards/board-1/views':
              return _json(<String, Object?>{
                'data': <Object?>[_view()],
                'meta': <String, Object?>{
                  'capabilities': <String, Object?>{
                    'create_personal': true,
                    'create_shared': true,
                  },
                },
              });
            case '/api/v1/tasks/boards/board-1/participants':
              return _json(<String, Object?>{'data': <Object?>[]});
          }
          return _json(<String, Object?>{
            'message': 'Unexpected request.',
          }, 500);
        }),
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
      await tester.tap(find.text('Operations'));
      await tester.pumpAndSettle();

      // Both bars are actually on screen — otherwise the assertion below
      // would pass for the same vacuous reason Task 10's did.
      final chrome = find.byKey(const ValueKey('task-board-controls'));
      expect(chrome, findsOneWidget);
      expect(find.byType(TaskViewsBar), findsOneWidget);
      expect(find.byType(TaskPresentationBar), findsOneWidget);

      // Zero horizontal scrollers in the chrome container at this width
      // (Task 15 correction). The status-summary/actions strip — task
      // count, status pills, Filters, and the board-secondary-actions
      // overflow menu — was previously exempted here as "data", but a
      // variable number of status pills is exactly as much chrome as the
      // presentation tabs above it: none of it is wide because the
      // content demands it, all of it wraps instead of clipping behind an
      // un-affordanced scroll now. If a new offender is added to this
      // container, or any bar regains a horizontal scroll at this width,
      // this count moves off 0 and the test fails.
      final horizontalScrollers = find.descendant(
        of: chrome,
        matching: find.byWidgetPredicate(
          (widget) =>
              (widget is SingleChildScrollView &&
                  widget.scrollDirection == Axis.horizontal) ||
              (widget is ListView && widget.scrollDirection == Axis.horizontal),
        ),
      );
      expect(horizontalScrollers, findsNothing);

      // The five presentation tabs and the saved view are still reachable —
      // wrapping, not clipping.
      expect(find.byKey(const ValueKey('presentation-tab-table')), findsOneWidget);
      expect(find.byKey(const ValueKey('presentation-tab-workload')), findsOneWidget);
      expect(find.text('Main table'), findsOneWidget);
    },
  );
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
  'actions': <Object?>['create_task'],
  'groups': <Object?>[
    <String, Object?>{
      'id': 'group-1',
      'name': 'To do',
      'color': null,
      'position': 1,
      'order_version': 1,
      'archived': false,
      'actions': <Object?>[],
    },
  ],
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

const Map<String, Object?> _configuration = <String, Object?>{
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

const Map<String, Object?> _rowsMeta = <String, Object?>{
  'total': 1,
  'page': 1,
  'per_page': 50,
  'last_page': 1,
  'group_counts': <Object?>[],
  'status_summaries': <Object?>[
    <String, Object?>{'status_option_id': 'status-1', 'count': 1},
  ],
  'view_id': 'view-main',
  'view_version': 1,
  'configuration': _configuration,
};

Map<String, Object?> _view() => <String, Object?>{
  'id': 'view-main',
  'board_id': 'board-1',
  'owner_user_id': null,
  'visibility': 'shared',
  'name': 'Main table',
  'is_locked': false,
  'is_permanent_main': true,
  'configuration': _configuration,
  'version': 1,
  'position': 1,
  'preference': null,
  'actions': <Object?>['update', 'duplicate'],
};

const Map<String, Object?> _directoryMeta = <String, Object?>{
  'capabilities': <String, Object?>{
    'actions': <Object?>['create_board'],
  },
};
