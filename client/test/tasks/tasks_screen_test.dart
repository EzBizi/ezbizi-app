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
        (_) async => _json(<String, Object?>{
          'data': <Object?>[],
          'meta': _directoryMeta,
        }),
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

  testWidgets(
    'the access-refusal sentence has non-zero horizontal padding',
    (tester) async {
      tester.view.physicalSize = const Size(375, 812);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final api = ApiClient(
        client: MockClient((request) async {
          if (request.url.path == '/api/v1/tasks/boards') {
            return _json(<String, Object?>{
              'message': 'Tasks are not available for this view.',
            }, 403);
          }
          return _json(<String, Object?>{'message': 'Unexpected request'}, 500);
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

      // The server's own sentence — house rule 4 — not a client-written
      // fallback (see the "server sentence vs. fallback" tests below).
      final sentence = find.text('Tasks are not available for this view.');
      expect(sentence, findsOneWidget);

      final padding = tester.widget<Padding>(
        find.ancestor(of: sentence, matching: find.byType(Padding)).first,
      );
      expect(padding.padding.horizontal, greaterThan(0));

      // Not stranded below a void either — it renders directly under the
      // title bar's chip row, the same top-aligned placement every sibling
      // screen uses, rather than centered in the remaining height.
      final sentenceTop = tester.getTopLeft(sentence).dy;
      expect(sentenceTop, lessThan(300));
    },
  );

  testWidgets(
    'an eviction carrying a server message renders that message verbatim',
    (tester) async {
      final api = ApiClient(
        client: MockClient((request) async {
          if (request.url.path == '/api/v1/tasks/boards') {
            return _json(<String, Object?>{
              'message': 'Your access to this board was removed.',
            }, 403);
          }
          return _json(<String, Object?>{'message': 'Unexpected request'}, 500);
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

      expect(
        find.text('Your access to this board was removed.'),
        findsOneWidget,
      );
      expect(find.text('This task board is unavailable.'), findsNothing);
    },
  );

  testWidgets(
    'an eviction with no server message falls back to the client sentence',
    (tester) async {
      // `ApiResponse.message` always synthesizes a fallback string for a
      // response with no `message` field (`'Something went wrong (HTTP
      // …)'`), so the network path never actually produces a null message.
      // The only real `null` comes from a handful of local UI decisions —
      // quick search discovering its result is gone is one of them
      // (`onUnavailable: () => _evict(null)` in `_titleBar`) — so this
      // drives that path end-to-end rather than faking the eviction.
      final api = ApiClient(
        client: MockClient((request) async {
          if (request.url.path == '/api/v1/tasks/boards') {
            return _json(<String, Object?>{'data': <Object?>[], 'meta': _directoryMeta});
          }
          if (request.url.path == '/api/v1/tasks/folders') {
            return _json(<String, Object?>{'data': <Object?>[]});
          }
          if (request.url.path == '/api/v1/tasks/search') {
            return _json(<String, Object?>{
              'message': 'Search is not available for this view.',
            }, 403);
          }
          return _json(<String, Object?>{'message': 'Unexpected request'}, 500);
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

      await tester.enterText(
        find.bySemanticsLabel('Search all tasks'),
        'liner',
      );
      await tester.pump(const Duration(milliseconds: 350));
      await tester.pumpAndSettle();

      // The 403's own sentence is discarded by design at this call site —
      // `_evict(null)`, not `_evict(result.message)` — so the client's
      // fallback renders, not the server's "Search is not available…"
      expect(find.text('This task board is unavailable.'), findsOneWidget);
      expect(
        find.text('Search is not available for this view.'),
        findsNothing,
      );
    },
  );

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

  testWidgets('mobile detail replaces an access-lost governed link in place', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(600, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    var detailReads = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        switch (request.url.path) {
          case '/api/v1/tasks/boards':
            return _json(<String, Object?>{
              'data': <Object?>[_board()],
              'meta': _directoryMeta,
            });
          case '/api/v1/tasks/boards/board-1/rows':
            return _json(<String, Object?>{
              'data': <Object?>[_task()],
              'meta': _rowsMeta(),
            });
          case '/api/v1/tasks/boards/board-1/tasks/task-1':
            detailReads++;
            return _json(<String, Object?>{
              'data': _task(
                recordLinks: detailReads == 1
                    ? <Object?>[_availableLink()]
                    : <Object?>[_unavailableLink()],
              ),
            });
          case '/api/v1/tasks/task-1/record-links/link-1':
            return _json(<String, Object?>{
              'data': <String, Object?>{'removed': true},
            });
        }
        return _json(<String, Object?>{'message': 'Unexpected request'}, 500);
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
    await tester.tap(find.text('Inspect the constrained layout'));
    await tester.pumpAndSettle();

    expect(find.text('Visible customer'), findsOneWidget);
    await tester.tap(find.byTooltip('Unlink customer'));
    await tester.pumpAndSettle();

    expect(find.text('Customer unavailable'), findsOneWidget);
    expect(find.text('Visible customer'), findsNothing);
    expect(find.text('C-100'), findsNothing);
  });

  testWidgets(
    'mobile linked-record navigation closes detail before forwarding',
    (tester) async {
      tester.view.physicalSize = const Size(600, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      String? opened;
      final api = ApiClient(
        client: MockClient((request) async {
          switch (request.url.path) {
            case '/api/v1/tasks/boards':
              return _json(<String, Object?>{
                'data': <Object?>[_board()],
                'meta': _directoryMeta,
              });
            case '/api/v1/tasks/boards/board-1/rows':
              return _json(<String, Object?>{
                'data': <Object?>[_task()],
                'meta': _rowsMeta(),
              });
            case '/api/v1/tasks/boards/board-1/tasks/task-1':
              return _json(<String, Object?>{
                'data': _task(recordLinks: <Object?>[_availableLink()]),
              });
          }
          return _json(<String, Object?>{'message': 'Unexpected request'}, 500);
        }),
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: TasksScreen(
              api: api,
              session: Session(api),
              onOpenRecordRoute: (route) => opened = route,
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Operations'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Inspect the constrained layout'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Visible customer'));
      await tester.pumpAndSettle();

      expect(opened, '/customers/customer-1');
      expect(find.byTooltip('Back to board'), findsNothing);
    },
  );

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
              'meta': _directoryMeta,
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
  'groups': <Object?>[
    <String, Object?>{
      'id': 'group-1',
      'name': 'To do',
      'color': null,
      'position': 1,
      'order_version': 1,
      'archived': false,
    },
  ],
  'statuses': <Object?>[],
  'priorities': <Object?>[],
  'core_columns': <Object?>[],
  'custom_columns': <Object?>[],
  'views': <Object?>[],
};

Map<String, Object?> _task({List<Object?> recordLinks = const <Object?>[]}) =>
    <String, Object?>{
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
      'record_links': recordLinks,
    };

Map<String, Object?> _availableLink() => <String, Object?>{
  'link_id': 'link-1',
  'available': true,
  'type': 'customer',
  'id': 'customer-1',
  'label': 'Visible customer',
  'subtitle': 'C-100',
  'route': '/customers/customer-1',
};

Map<String, Object?> _unavailableLink() => <String, Object?>{
  'link_id': 'link-1',
  'available': false,
  'type': 'Customer',
};

Map<String, Object?> _rowsMeta() => <String, Object?>{
  'total': 1,
  'page': 1,
  'per_page': 50,
  'last_page': 1,
  'group_counts': <Object?>[],
  'status_summaries': <Object?>[],
  'view_id': 'view-1',
  'view_version': 1,
  'configuration': _configuration(),
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

const Map<String, Object?> _directoryMeta = <String, Object?>{
  'capabilities': <String, Object?>{
    'actions': <Object?>['create_board'],
  },
};
