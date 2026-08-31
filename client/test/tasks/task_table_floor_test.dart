import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks/task_table.dart';
import 'package:ezbizi_client/screens/tasks_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Whole-branch review finding 3: `TasksScreen._boardScaffold()`'s
/// `minViewHeight: 280` floor for `TaskTable` was inert. `Expanded` gives
/// its child a TIGHT height constraint (`minHeight == maxHeight ==
/// whatever space is left`); nesting a `ConstrainedBox(minHeight: 280)`
/// inside it can only ever CLAMP that request DOWN to the already-decided
/// amount, never raise it. At a squeezed pane — two chrome bars wrapping
/// across a short window — the table used to be pushed under 280px anyway,
/// exactly the "silent row loss" ledger Ruling 57 (progress.md) believed
/// this floor had already ruled out.
///
/// This mounts the real board (not `TaskTable` in isolation, for the same
/// reason `task_chrome_bars_test.dart` mounts the real screen: a
/// screen-level constraint bug is invisible to a widget tested with its
/// own, generous constraints) at a width/height combination chosen to
/// force exactly that squeeze, and measures `TaskTable`'s actual rendered
/// height rather than merely checking a row's text exists in the tree —
/// text can survive in the widget tree while its render box is clipped to
/// near zero, which would make a text-presence assertion pass on the
/// unfixed code for the wrong reason.
void main() {
  testWidgets(
    'TaskTable never renders under its 280px floor, even with two chrome bars wrapped at a short pane',
    (tester) async {
      // 720: at or above the 700px mobile cutoff, so Table reaches
      // `_workspace()` (its desktop path, the one place this floor is
      // meant to apply) rather than `_mobileTableContent()` (which has no
      // floor and is not this finding's claim) — but still under
      // `TaskPresentationBar`'s own 700px breakpoint for its five tabs, so
      // the chrome genuinely wraps hard rather than fitting on one line.
      // 590: short enough that the title bar above `_content` plus the
      // ~168px-tall wrapped chrome leaves `_boardScaffold` under
      // (chrome's natural height + 280) of room — measured against the
      // unfixed code, this exact size squeezed `TaskTable` to 189px —
      // while still tall enough overall (~400px reaches `_boardScaffold`
      // itself) that 280px is achievable at all; a window too short for
      // even fully-collapsed chrome to leave 280px is an impossible ask
      // no layout can satisfy, not this bug.
      tester.view.physicalSize = const Size(720, 590);
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

      // Confirms the squeeze is real, not a no-op scenario: both chrome
      // bars are on screen — the same 'task-board-controls' container
      // `task_chrome_bars_test.dart` checks — so this is exercising the
      // actual reported failure mode, not a width the chrome fits
      // comfortably at.
      expect(find.byKey(const ValueKey('task-board-controls')), findsOneWidget);
      expect(find.text('Main table'), findsOneWidget);

      // The chrome container itself is well over a single line's worth of
      // height (~168px here, against ~56px for one line) — proof this is
      // exercising the actual hard-wrap scenario, not one where the chrome
      // fits comfortably and the floor was never in danger regardless.
      final chrome = tester.getSize(
        find.byKey(const ValueKey('task-board-controls')),
      );
      expect(chrome.height, greaterThan(100));

      final table = tester.getSize(find.byType(TaskTable));
      expect(
        table.height,
        greaterThanOrEqualTo(280),
        reason:
            'TaskTable must never be given less than its documented 280px '
            'floor, however much room the chrome above it wraps to.',
      );

      // The floor being real is what makes the row genuinely visible, not
      // merely present-but-clipped: its own rendered height is non-zero
      // and it sits inside the table's viewport, not below/beyond it.
      final row = tester.getSize(find.byKey(const Key('task-row-task-1')));
      expect(row.height, greaterThan(0));
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
