import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/address.dart';
import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/tasks_screen.dart';
import 'package:ezbizi_client/screens/tasks/my_tasks_view.dart';
import 'package:ezbizi_client/screens/tasks/task_api.dart';
import 'package:ezbizi_client/screens/tasks/task_filters_panel.dart';
import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:ezbizi_client/screens/tasks/task_quick_search.dart';
import 'package:ezbizi_client/screens/tasks/task_views_bar.dart';
import 'package:ezbizi_client/widgets/notification_bell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test(
    'saved-view lifecycle uses typed version and capability contracts',
    () async {
      final requests = <http.Request>[];
      var version = 1;
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            final path = request.url.path;
            if (request.method == 'GET') {
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  _view(name: 'Main', locked: true, permanent: true),
                ],
                'meta': <String, Object?>{
                  'capabilities': <String, Object?>{
                    'create_personal': true,
                    'create_shared': false,
                  },
                },
              });
            }
            if (path.endsWith('/duplicate')) {
              return _json(201, <String, Object?>{
                'data': _view(
                  id: 'view-copy',
                  name: 'My dispatch',
                  visibility: 'personal',
                  locked: false,
                ),
              });
            }
            if (path.endsWith('/preference')) {
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  'view_id': 'view-1',
                  'collapsed_group_ids': <Object?>['group-1'],
                  'density': 'comfortable',
                  'is_last_active': true,
                  'scroll_left': 30,
                  'scroll_top': 40,
                },
              });
            }
            if (request.method == 'DELETE') return http.Response('', 204);
            version++;
            return _json(
              request.method == 'POST' ? 201 : 200,
              <String, Object?>{
                'data': _view(
                  name: request.method == 'POST' ? 'Mine' : 'Mine updated',
                  visibility: 'personal',
                  locked: false,
                  version: version,
                ),
                'meta': <String, Object?>{'structure_version': 8},
              },
            );
          }),
        ),
      );

      final listed = await api.views('board-1');
      final created = await api.createView(
        'board-1',
        name: 'Mine',
        visibility: TaskViewVisibility.personal,
        configuration: TaskViewConfiguration.empty,
      );
      final updated = await api.updateView(
        'board-1',
        'view-1',
        expectedViewVersion: 2,
        changes: const <String, Object?>{'name': 'Mine updated'},
      );
      final copied = await api.duplicateView(
        'board-1',
        'view-1',
        name: 'My dispatch',
        expectedViewVersion: 1,
      );
      final preference = await api.saveViewPreference(
        'board-1',
        'view-1',
        const TaskViewPreferenceMutation(
          collapsedGroupIds: <String>['group-1'],
          density: TaskViewDensity.comfortable,
          isLastActive: true,
          scrollLeft: 30,
          scrollTop: 40,
        ),
      );
      final deleted = await api.deleteView(
        'board-1',
        'view-1',
        expectedViewVersion: 3,
      );

      expect(listed.value?.views.single.isPermanentMain, isTrue);
      expect(listed.value?.canCreatePersonal, isTrue);
      expect(listed.value?.canCreateShared, isFalse);
      expect(created.value?.definition.configuration.search, isEmpty);
      expect(updated.value?.structureVersion, 8);
      expect(copied.value?.visibility, TaskViewVisibility.personal);
      expect(preference.value?.density, TaskViewDensity.comfortable);
      expect(deleted.value, isTrue);
      expect(jsonDecode(requests[1].body), <String, Object?>{
        'name': 'Mine',
        'visibility': 'personal',
        'is_locked': false,
        'configuration': _configuration(),
      });
      expect(jsonDecode(requests[2].body), <String, Object?>{
        'expected_view_version': 2,
        'name': 'Mine updated',
      });
      expect(jsonDecode(requests[3].body), <String, Object?>{
        'name': 'My dispatch',
        'expected_view_version': 1,
      });
      expect(jsonDecode(requests[4].body), <String, Object?>{
        'collapsed_group_ids': <Object?>['group-1'],
        'density': 'comfortable',
        'is_last_active': true,
        'scroll_left': 30,
        'scroll_top': 40,
      });
      expect(jsonDecode(requests[5].body), <String, Object?>{
        'expected_view_version': 3,
      });
    },
  );

  test(
    'board, My Tasks and detail conditional reads keep separate ETags',
    () async {
      final requests = <http.Request>[];
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            requests.add(request);
            if (request.url.path == '/api/v1/tasks/boards/board-1') {
              return _json(200, <String, Object?>{'data': _board()});
            }
            expect(request.headers['if-none-match'], isNotEmpty);
            return http.Response(
              '',
              304,
              headers: <String, String>{
                'etag': request.headers['if-none-match']!,
              },
            );
          }),
        ),
      );
      await api.board('board-1');

      final board = await api.boardRows('board-1', etag: '"board-etag"');
      final mine = await api.myTasks(etag: '"mine-etag"');
      final detail = await api.taskDetail(
        'board-1',
        'task-1',
        etag: '"detail-etag"',
      );

      expect(board, isA<TaskApiNotModified<TaskRowsPage>>());
      expect(mine, isA<TaskApiNotModified<TaskMyTasksPage>>());
      expect(detail, isA<TaskApiNotModified<TaskDetailSnapshot>>());
      expect(requests[1].headers['if-none-match'], '"board-etag"');
      expect(requests[2].headers['if-none-match'], '"mine-etag"');
      expect(requests[3].headers['if-none-match'], '"detail-etag"');
    },
  );

  test('saved-view conflict returns the current typed server view', () async {
    final api = TaskApi(
      ApiClient(
        client: MockClient(
          (_) async => _json(409, <String, Object?>{
            'message': 'This saved view changed after you opened it.',
            'data': _view(name: 'Server view', version: 4),
            'meta': <String, Object?>{'current_view_version': 4},
          }),
        ),
      ),
    );

    final result = await api.updateView(
      'board-1',
      'view-1',
      expectedViewVersion: 2,
      changes: const <String, Object?>{'name': 'Mine'},
    );

    expect(result, isA<TaskApiViewConflict<TaskViewMutationResult>>());
    final conflict = result as TaskApiViewConflict<TaskViewMutationResult>;
    expect(conflict.currentVersion, 4);
    expect(conflict.currentView.name, 'Server view');
  });

  test(
    'My Tasks and quick search decode only the server-visible result shape',
    () async {
      var call = 0;
      final api = TaskApi(
        ApiClient(
          client: MockClient((request) async {
            call++;
            if (call == 1) {
              return _json(
                200,
                <String, Object?>{
                  'data': <Object?>[
                    <String, Object?>{
                      ..._task(),
                      'board': <String, Object?>{
                        'id': 'board-1',
                        'name': 'Visible board',
                      },
                    },
                  ],
                  'meta': <String, Object?>{
                    'total': 1,
                    'page': 1,
                    'per_page': 50,
                    'last_page': 1,
                  },
                },
                headers: const <String, String>{'etag': '"mine-1"'},
              );
            }
            expect(request.url.queryParameters, <String, String>{
              'q': 'TASK-1',
            });
            return _json(200, <String, Object?>{
              'data': <Object?>[
                <String, Object?>{
                  'board_id': 'board-1',
                  'board_name': 'Visible board',
                  'task_id': 'task-1',
                  'number': 'TASK-1',
                  'title': 'Visible work',
                  'deep_link': '/tasks/boards/board-1/tasks/task-1',
                },
              ],
            });
          }),
        ),
      );

      final mine = await api.myTasks();
      final search = await api.quickSearch('TASK-1');

      expect(mine.value?.etag, '"mine-1"');
      expect(mine.value?.items.single.boardName, 'Visible board');
      expect(search.value?.single.title, 'Visible work');
      expect(
        search.value?.single.deepLink,
        '/tasks/boards/board-1/tasks/task-1',
      );
    },
  );

  testWidgets('filter panel changes the complete server-owned view state', (
    tester,
  ) async {
    var configuration = TaskViewConfiguration.empty;
    await tester.pumpWidget(
      _app(
        TaskFiltersPanel(
          board: TaskBoard.fromJson(_boardWithColumns()),
          configuration: configuration,
          people: const <TaskParticipant>[
            TaskParticipant(
              id: 'user-1',
              name: 'Taylor',
              email: 'taylor@example.com',
            ),
          ],
          onChanged: (next) => configuration = next,
          onClose: () {},
        ),
      ),
    );

    await tester.enterText(find.byKey(const Key('task-view-search')), 'doors');
    await tester.pump();
    await tester.tap(find.byKey(const Key('task-person-filter')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Taylor').last);
    await tester.pump();
    await tester.tap(find.byKey(const Key('task-sort-field')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Due date').last);
    await tester.pump();
    await tester.tap(find.byKey(const Key('task-sort-direction')));
    await tester.tap(find.byKey(const Key('task-hide-owner')));
    await tester.tap(find.byKey(const Key('task-column-owner-down')));
    await tester.tap(find.byKey(const Key('task-width-owner-increase')));
    await tester.tap(find.byKey(const Key('task-group-by')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Status').last);

    expect(configuration.search, 'doors');
    expect(configuration.filters.single.toJson(), <String, Object?>{
      'field': 'owner',
      'operator': 'contains_any',
      'value': <Object?>['user-1'],
    });
    expect(configuration.sorts.single.toJson(), <String, Object?>{
      'field': 'due_on',
      'direction': 'desc',
    });
    expect(configuration.hiddenColumns, contains('owner'));
    expect(configuration.columnOrder.indexOf('owner'), 1);
    expect(configuration.columnWidths['owner'], 204);
    expect(configuration.groupBy, 'status');
    expect(find.byKey(const Key('task-hide-task')), findsNothing);
    expect(find.byKey(const Key('task-column-task-down')), findsNothing);
    expect(find.byKey(const Key('task-width-task-increase')), findsNothing);
  });

  testWidgets('open filter panel follows a newly selected view', (
    tester,
  ) async {
    final first = TaskViewConfiguration.empty.copyWith(
      filters: const <TaskFilter>[
        TaskFilter(
          field: 'owner',
          operator: 'contains_any',
          value: <String>['user-1'],
        ),
      ],
      sorts: <TaskSort>[
        TaskSort.fromJson(const <String, Object?>{
          'field': 'due_on',
          'direction': 'asc',
        }),
      ],
      groupBy: 'status',
    );
    final second = TaskViewConfiguration.empty.copyWith(
      filters: const <TaskFilter>[
        TaskFilter(
          field: 'owner',
          operator: 'contains_any',
          value: <String>['user-2'],
        ),
      ],
      sorts: <TaskSort>[
        TaskSort.fromJson(const <String, Object?>{
          'field': 'priority',
          'direction': 'desc',
        }),
      ],
      groupBy: 'owner',
    );
    Widget panel(TaskViewConfiguration configuration) => _app(
      TaskFiltersPanel(
        board: TaskBoard.fromJson(_boardWithColumns()),
        configuration: configuration,
        people: const <TaskParticipant>[
          TaskParticipant(
            id: 'user-1',
            name: 'Taylor',
            email: 'taylor@example.com',
          ),
          TaskParticipant(
            id: 'user-2',
            name: 'Jordan',
            email: 'jordan@example.com',
          ),
        ],
        onChanged: (_) {},
        onClose: () {},
      ),
    );

    await tester.pumpWidget(panel(first));
    expect(find.text('Taylor'), findsOneWidget);
    expect(find.text('Due date'), findsWidgets);
    await tester.pumpWidget(panel(second));
    await tester.pump();

    expect(find.text('Jordan'), findsOneWidget);
    expect(find.text('Taylor'), findsNothing);
    expect(find.text('Priority'), findsWidgets);
  });

  testWidgets('quick search ignores an older response that arrives last', (
    tester,
  ) async {
    final oldResponse = Completer<http.Response>();
    final newResponse = Completer<http.Response>();
    final api = TaskApi(
      ApiClient(
        client: MockClient((request) {
          return request.url.queryParameters['q'] == 'old'
              ? oldResponse.future
              : newResponse.future;
        }),
      ),
    );
    await tester.pumpWidget(
      _app(
        TaskQuickSearch(
          api: api,
          debounce: const Duration(milliseconds: 20),
          onOpen: (_) {},
        ),
      ),
    );

    await tester.enterText(find.bySemanticsLabel('Search all tasks'), 'old');
    await tester.pump(const Duration(milliseconds: 25));
    await tester.enterText(find.bySemanticsLabel('Search all tasks'), 'new');
    await tester.pump(const Duration(milliseconds: 25));
    newResponse.complete(_searchResponse('New result'));
    await tester.pump();
    oldResponse.complete(_searchResponse('Old result'));
    await tester.pump();

    expect(find.text('New result'), findsOneWidget);
    expect(find.text('Old result'), findsNothing);
  });

  testWidgets('views bar and My Tasks expose real controls and server rows', (
    tester,
  ) async {
    var selected = '';
    var action = '';
    final myPage = TaskMyTasksPage.fromJson(<String, Object?>{
      'data': <Object?>[
        <String, Object?>{
          ..._task(),
          'board': <String, Object?>{'id': 'board-1', 'name': 'Visible board'},
        },
      ],
      'meta': <String, Object?>{
        'total': 1,
        'page': 1,
        'per_page': 50,
        'last_page': 1,
      },
    }, etag: '"mine-1"');
    await tester.pumpWidget(
      _app(
        Column(
          children: [
            TaskViewsBar(
              views: <TaskViewDefinition>[
                TaskViewDefinition.fromJson(
                  _view(
                    name: 'Main',
                    locked: true,
                    permanent: true,
                    actions: const <String>['duplicate'],
                  ),
                ),
                TaskViewDefinition.fromJson(
                  _view(
                    id: 'view-2',
                    name: 'My dispatch',
                    visibility: 'personal',
                    locked: false,
                    actions: const <String>['update', 'delete'],
                  ),
                ),
              ],
              activeViewId: 'view-1',
              canCreatePersonal: true,
              dirty: false,
              onSelected: (view) => selected = view.id,
              onCreate: () => action = 'create',
              onSave: (_) => action = 'save',
              onDuplicate: (_) => action = 'duplicate',
              onDelete: (_) => action = 'delete',
            ),
            Expanded(
              child: MyTasksView(
                page: myPage,
                onOpen: (item) => selected = item.task.id,
                onLoadMore: () => action = 'more',
              ),
            ),
          ],
        ),
      ),
    );

    expect(find.text('Main'), findsOneWidget);
    expect(find.byTooltip('Locked shared view'), findsOneWidget);
    expect(find.textContaining('Visible board'), findsOneWidget);
    expect(find.text('Visible work'), findsOneWidget);
    await tester.tap(find.text('My dispatch'));
    await tester.tap(find.byTooltip('New saved view'));
    await tester.tap(find.text('Visible work'));

    expect(action, 'create');
    expect(selected, 'task-1');
  });

  testWidgets('My Tasks offers load more through the server last page', (
    tester,
  ) async {
    var loads = 0;
    final page = TaskMyTasksPage.fromJson(<String, Object?>{
      'data': <Object?>[
        <String, Object?>{
          ..._task(),
          'board': <String, Object?>{'id': 'board-1', 'name': 'Visible board'},
        },
      ],
      'meta': <String, Object?>{
        'total': 2,
        'page': 1,
        'per_page': 1,
        'last_page': 2,
      },
    }, etag: '"mine-1"');
    await tester.pumpWidget(
      _app(MyTasksView(page: page, onOpen: (_) {}, onLoadMore: () => loads++)),
    );

    await tester.tap(find.text('Load more tasks'));

    expect(loads, 1);
  });

  testWidgets(
    'My Tasks loads through lastPage and polls loaded pages by ETag',
    (tester) async {
      final conditionalEtags = <int, String?>{};
      final api = ApiClient(
        client: MockClient((request) async {
          if (request.url.path == '/api/v1/tasks/boards') {
            return _json(200, <String, Object?>{
              'data': <Object?>[_boardWithColumns()],
            });
          }
          if (request.url.path == '/api/v1/tasks/my') {
            final page = int.parse(request.url.queryParameters['page']!);
            final conditional = request.headers['if-none-match'];
            if (conditional != null) {
              conditionalEtags[page] = conditional;
              return http.Response(
                '',
                304,
                headers: <String, String>{'etag': conditional},
              );
            }
            return _json(
              200,
              <String, Object?>{
                'data': <Object?>[
                  <String, Object?>{
                    ..._taskWithTitle(
                      page == 1 ? 'First assignment' : 'Second assignment',
                    ),
                    'id': 'task-$page',
                    'number': 'TASK-$page',
                    'board': <String, Object?>{
                      'id': 'board-1',
                      'name': 'Operations',
                    },
                  },
                ],
                'meta': <String, Object?>{
                  'total': 2,
                  'page': page,
                  'per_page': 1,
                  'last_page': 2,
                },
              },
              headers: <String, String>{'etag': '"mine-$page"'},
            );
          }
          return _json(500, <String, Object?>{'message': 'Unexpected request'});
        }),
      );
      await tester.pumpWidget(
        _app(
          TasksScreen(
            api: api,
            session: Session(api),
            pollInterval: const Duration(milliseconds: 200),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      await tester.tap(find.text('My Tasks'));
      await tester.pump();
      await tester.pump();
      expect(find.text('First assignment'), findsOneWidget);

      await tester.tap(find.text('Load more tasks'));
      await tester.pump();
      await tester.pump();
      expect(find.text('First assignment'), findsOneWidget);
      expect(find.text('Second assignment'), findsOneWidget);

      await tester.pump(const Duration(milliseconds: 220));
      await tester.pump();
      expect(conditionalEtags, <int, String?>{1: '"mine-1"', 2: '"mine-2"'});
    },
  );

  testWidgets('read-only capability hides personal view mutation actions', (
    tester,
  ) async {
    await tester.pumpWidget(
      _app(
        TaskViewsBar(
          views: <TaskViewDefinition>[
            TaskViewDefinition.fromJson(
              _view(
                name: 'Mine',
                visibility: 'personal',
                locked: false,
                permanent: false,
                actions: const <String>[],
              ),
            ),
          ],
          activeViewId: 'view-1',
          canCreatePersonal: false,
          dirty: true,
          onSelected: (_) {},
          onCreate: () {},
          onSave: (_) {},
          onDuplicate: (_) {},
          onDelete: (_) {},
        ),
      ),
    );

    await tester.tap(find.byTooltip('Saved view actions'));
    await tester.pumpAndSettle();

    expect(find.text('Save changes'), findsNothing);
    expect(find.text('Duplicate view'), findsNothing);
    expect(find.text('Delete view'), findsNothing);
  });

  testWidgets('successful notification navigation emits a typed Task intent', (
    tester,
  ) async {
    TaskAddressIntent? opened;
    var reads = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'POST') {
          reads++;
          return _json(200, <String, Object?>{'data': null});
        }
        return _json(200, <String, Object?>{
          'data': <Object?>[
            <String, Object?>{
              'id': 'notification-1',
              'title': 'Task changed',
              'body': null,
              'source': 'task',
              'read_at': null,
              'meta': <String, Object?>{
                'route': '/tasks/boards/board-1/tasks/task-1',
              },
            },
          ],
          'meta': <String, Object?>{'unread': 1},
        });
      }),
    );
    await tester.pumpWidget(
      _app(NotificationBell(api: api, onOpenTask: (intent) => opened = intent)),
    );
    await tester.pump();
    await tester.tap(find.byTooltip('1 unread'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Task changed'));
    await tester.pumpAndSettle();

    expect(reads, 1);
    expect(
      opened,
      const TaskAddressIntent(boardId: 'board-1', taskId: 'task-1'),
    );
  });

  testWidgets(
    'polling preserves a dirty editor and reports the remote change',
    (tester) async {
      var rowReads = 0;
      final rowHeaders = <String?>[];
      final api = ApiClient(
        client: MockClient((request) async {
          switch (request.url.path) {
            case '/api/v1/tasks/boards':
              return _json(200, <String, Object?>{
                'data': <Object?>[_boardWithColumns()],
              });
            case '/api/v1/tasks/boards/board-1/rows':
              rowReads++;
              rowHeaders.add(request.headers['if-none-match']);
              return _rowsResponse(
                title: rowReads == 1 ? 'Original title' : 'Remote title',
                etag: rowReads == 1 ? '"board-1"' : '"board-2"',
              );
            case '/api/v1/tasks/boards/board-1/views':
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  _view(
                    name: 'Main',
                    locked: true,
                    permanent: true,
                    actions: const <String>['duplicate'],
                  ),
                ],
                'meta': _viewCapabilities(),
              });
            case '/api/v1/tasks/boards/board-1/participants':
              return _json(200, <String, Object?>{'data': <Object?>[]});
          }
          return _json(500, <String, Object?>{'message': 'Unexpected request'});
        }),
      );
      await tester.pumpWidget(
        _app(
          TasksScreen(
            api: api,
            session: Session(api),
            pollInterval: const Duration(milliseconds: 200),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      await tester.tap(find.text('Operations'));
      await tester.pump();
      await tester.pump();
      await tester.pump();

      expect(find.text('1 task'), findsOneWidget);
      expect(find.text('Main'), findsOneWidget);
      expect(find.bySemanticsLabel('Search all tasks'), findsWidgets);
      final titleField = find.descendant(
        of: find.byKey(const Key('task-row-task-1')),
        matching: find.byType(TextField),
      );
      await tester.enterText(titleField, 'Unsaved draft');
      await tester.pump(const Duration(milliseconds: 220));
      await tester.pump();

      expect(rowHeaders, contains('"board-1"'));
      expect(find.text('Unsaved draft'), findsOneWidget);
      expect(find.text('Remote title'), findsNothing);
      expect(
        find.text('This task or saved view changed while you were editing it.'),
        findsOneWidget,
      );
    },
  );

  testWidgets('polling waits until every identified editor is clean', (
    tester,
  ) async {
    var rowReads = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        switch (request.url.path) {
          case '/api/v1/tasks/boards':
            return _json(200, <String, Object?>{
              'data': <Object?>[_boardWithColumns()],
            });
          case '/api/v1/tasks/boards/board-1/rows':
            rowReads++;
            return _rowsResponse(
              title: rowReads == 1 ? 'Original title' : 'Remote title',
              etag: '"board-$rowReads"',
            );
          case '/api/v1/tasks/boards/board-1/views':
            return _json(200, <String, Object?>{
              'data': <Object?>[_view(name: 'Main', permanent: true)],
              'meta': _viewCapabilities(),
            });
          case '/api/v1/tasks/boards/board-1/participants':
            return _json(200, <String, Object?>{'data': <Object?>[]});
        }
        return _json(500, <String, Object?>{'message': 'Unexpected request'});
      }),
    );
    await tester.pumpWidget(
      _app(
        TasksScreen(
          api: api,
          session: Session(api),
          pollInterval: const Duration(milliseconds: 200),
        ),
      ),
    );
    await tester.pump();
    await tester.pump();
    await tester.tap(find.text('Operations'));
    await tester.pump();
    await tester.pump();
    await tester.pump();

    final dueField = find.byType(TextField).last;
    final titleField = find.descendant(
      of: find.byKey(const Key('task-row-task-1')),
      matching: find.byType(TextField),
    );
    await tester.enterText(dueField, '2026-10-10');
    await tester.enterText(titleField, 'Temporary title');
    await tester.enterText(titleField, 'Original title');
    await tester.pump(const Duration(milliseconds: 220));
    await tester.pump();

    expect(find.text('Remote title'), findsNothing);
    expect(find.text('2026-10-10'), findsOneWidget);
    expect(
      find.text('This task or saved view changed while you were editing it.'),
      findsOneWidget,
    );
  });

  testWidgets(
    '304 preserves state and later polling revocation evicts task caches',
    (tester) async {
      var rowReads = 0;
      var detailReads = 0;
      String? boardConditional;
      String? detailConditional;
      String? cleanConfigurationOverride;
      final api = ApiClient(
        client: MockClient((request) async {
          switch (request.url.path) {
            case '/api/v1/tasks/boards':
              return _json(200, <String, Object?>{
                'data': <Object?>[_boardWithColumns()],
              });
            case '/api/v1/tasks/boards/board-1/rows':
              rowReads++;
              if (rowReads == 1) {
                return _rowsResponse(title: 'Still visible', etag: '"board-1"');
              }
              boardConditional = request.headers['if-none-match'];
              cleanConfigurationOverride =
                  request.url.queryParameters['configuration'];
              if (rowReads == 2) {
                return http.Response(
                  '',
                  304,
                  headers: const {'etag': '"board-1"'},
                );
              }
              return _json(404, <String, Object?>{
                'message': 'This task board is unavailable.',
              });
            case '/api/v1/tasks/boards/board-1/views':
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  _view(
                    name: 'Main',
                    locked: true,
                    permanent: true,
                    actions: const <String>['duplicate'],
                  ),
                ],
                'meta': _viewCapabilities(),
              });
            case '/api/v1/tasks/boards/board-1/participants':
              return _json(200, <String, Object?>{'data': <Object?>[]});
            case '/api/v1/tasks/boards/board-1/tasks/task-1':
              detailReads++;
              if (detailReads == 1) {
                return _json(
                  200,
                  <String, Object?>{'data': _taskWithTitle('Still visible')},
                  headers: const {'etag': '"detail-1"'},
                );
              }
              detailConditional = request.headers['if-none-match'];
              return http.Response(
                '',
                304,
                headers: const {'etag': '"detail-1"'},
              );
          }
          return _json(500, <String, Object?>{'message': 'Unexpected request'});
        }),
      );
      await tester.pumpWidget(
        _app(
          TasksScreen(
            api: api,
            session: Session(api),
            pollInterval: const Duration(milliseconds: 200),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      await tester.tap(find.text('Operations'));
      await tester.pump();
      await tester.pump();
      await tester.tap(find.byKey(const Key('task-row-task-1')));
      await tester.pump();
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 20));
      expect(detailReads, 1);
      expect(find.text('Description'), findsOneWidget);
      await tester.pump(const Duration(milliseconds: 220));
      await tester.pump(const Duration(milliseconds: 20));
      await tester.pump(const Duration(milliseconds: 20));

      expect(boardConditional, '"board-1"');
      expect(cleanConfigurationOverride, isNull);
      expect(detailConditional, '"detail-1"');
      expect(find.text('Still visible'), findsWidgets);

      await tester.pump(const Duration(milliseconds: 220));
      await tester.pump();
      expect(find.text('This task board is unavailable.'), findsOneWidget);
      expect(find.text('Still visible'), findsNothing);
    },
  );

  testWidgets('shared view delete refetches the board structure version', (
    tester,
  ) async {
    var boardShowReads = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        switch (request.url.path) {
          case '/api/v1/tasks/boards':
            return _json(200, <String, Object?>{
              'data': <Object?>[_boardWithColumns()],
            });
          case '/api/v1/tasks/boards/board-1':
            boardShowReads++;
            return _json(200, <String, Object?>{
              'data': <String, Object?>{
                ..._boardWithColumns(),
                'structure_version': 8,
              },
            });
          case '/api/v1/tasks/boards/board-1/rows':
            return _rowsResponse(title: 'Visible work', etag: '"board-1"');
          case '/api/v1/tasks/boards/board-1/views':
            return _json(200, <String, Object?>{
              'data': <Object?>[
                _view(
                  name: 'Shared dispatch',
                  locked: false,
                  actions: const <String>['update', 'duplicate', 'delete'],
                ),
                _view(
                  id: 'view-2',
                  name: 'Main',
                  locked: false,
                  permanent: true,
                  actions: const <String>['update', 'duplicate'],
                ),
              ],
              'meta': _viewCapabilities(),
            });
          case '/api/v1/tasks/boards/board-1/participants':
            return _json(200, <String, Object?>{'data': <Object?>[]});
          case '/api/v1/tasks/boards/board-1/views/view-1':
            return http.Response('', 204);
          case '/api/v1/tasks/boards/board-1/views/view-2/preference':
            return _json(200, <String, Object?>{
              'data': <String, Object?>{
                'view_id': 'view-2',
                'collapsed_group_ids': <Object?>[],
                'density': 'compact',
                'is_last_active': true,
                'scroll_left': 0,
                'scroll_top': 0,
              },
            });
        }
        return _json(500, <String, Object?>{'message': 'Unexpected request'});
      }),
    );
    await tester.pumpWidget(
      _app(
        TasksScreen(
          api: api,
          session: Session(api),
          pollInterval: const Duration(days: 1),
        ),
      ),
    );
    await tester.pump();
    await tester.pump();
    await tester.tap(find.text('Operations'));
    await tester.pump();
    await tester.pump();
    await tester.pump();
    await tester.tap(find.byTooltip('Saved view actions'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete view'));
    await tester.pump();
    await tester.pump();

    expect(boardShowReads, 1);
  });

  testWidgets(
    'page navigation drops stale view responses and omits clean configuration',
    (tester) async {
      tester.view.physicalSize = const Size(1400, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      final stalePage = Completer<http.Response>();
      String? pageTwoConfiguration;
      var initialRowsRead = false;
      final api = ApiClient(
        client: MockClient((request) async {
          switch (request.url.path) {
            case '/api/v1/tasks/boards':
              return _json(200, <String, Object?>{
                'data': <Object?>[_boardWithColumns()],
              });
            case '/api/v1/tasks/boards/board-1/rows':
              final page = request.url.queryParameters['page'];
              final view = request.url.queryParameters['view_id'];
              if (!initialRowsRead) {
                initialRowsRead = true;
                return _pagedRowsResponse(
                  title: 'View one page one',
                  page: 1,
                  lastPage: 2,
                  viewId: 'view-1',
                );
              }
              if (page == '2' && view == 'view-1') {
                pageTwoConfiguration =
                    request.url.queryParameters['configuration'];
                return stalePage.future;
              }
              if (page == '1' && view == 'view-2') {
                return _pagedRowsResponse(
                  title: 'View two page one',
                  page: 1,
                  lastPage: 2,
                  viewId: 'view-2',
                );
              }
            case '/api/v1/tasks/boards/board-1/views':
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  _view(name: 'Main', locked: false),
                  _view(id: 'view-2', name: 'Other', locked: false),
                ],
                'meta': _viewCapabilities(),
              });
            case '/api/v1/tasks/boards/board-1/participants':
              return _json(200, <String, Object?>{'data': <Object?>[]});
            case '/api/v1/tasks/boards/board-1/views/view-2/preference':
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  'view_id': 'view-2',
                  'collapsed_group_ids': <Object?>[],
                  'density': 'compact',
                  'is_last_active': true,
                  'scroll_left': 0,
                  'scroll_top': 0,
                },
              });
          }
          return _json(500, <String, Object?>{'message': 'Unexpected request'});
        }),
      );
      await tester.pumpWidget(
        _app(
          TasksScreen(
            api: api,
            session: Session(api),
            pollInterval: const Duration(days: 1),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      await tester.tap(find.text('Operations'));
      await tester.pump();
      await tester.pump();
      await tester.pump();

      await tester.tap(find.text('Next'));
      await tester.pump();
      await tester.tap(find.text('Other'));
      await tester.pump();
      await tester.pump();
      expect(find.text('View two page one'), findsOneWidget);

      stalePage.complete(
        _pagedRowsResponse(
          title: 'Stale view one page two',
          page: 2,
          lastPage: 2,
          viewId: 'view-1',
        ),
      );
      await tester.pump();
      await tester.pump();

      expect(pageTwoConfiguration, isNull);
      expect(find.text('View two page one'), findsOneWidget);
      expect(find.text('Stale view one page two'), findsNothing);
      expect(find.text('Page 1 of 2'), findsOneWidget);
    },
  );

  testWidgets(
    'Refresh board reloads its authoritative structure before shared actions',
    (tester) async {
      tester.view.physicalSize = const Size(1400, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var rowReads = 0;
      var boardShowReads = 0;
      int? deletedAtStructureVersion;
      final api = ApiClient(
        client: MockClient((request) async {
          switch (request.url.path) {
            case '/api/v1/tasks/boards':
              return _json(200, <String, Object?>{
                'data': <Object?>[_boardWithColumns()],
              });
            case '/api/v1/tasks/boards/board-1':
              boardShowReads++;
              return _json(200, <String, Object?>{
                'data': <String, Object?>{
                  ..._boardWithColumns(),
                  'structure_version': 11,
                },
              });
            case '/api/v1/tasks/boards/board-1/rows':
              rowReads++;
              return _rowsResponse(
                title: switch (rowReads) {
                  1 => 'Original title',
                  2 => 'Remote title',
                  _ => 'Reloaded title',
                },
                etag: '"board-$rowReads"',
              );
            case '/api/v1/tasks/boards/board-1/views':
              return _json(200, <String, Object?>{
                'data': <Object?>[
                  _view(
                    name: 'Shared dispatch',
                    locked: false,
                    actions: const <String>['delete'],
                  ),
                ],
                'meta': _viewCapabilities(),
              });
            case '/api/v1/tasks/boards/board-1/participants':
              return _json(200, <String, Object?>{'data': <Object?>[]});
            case '/api/v1/tasks/boards/board-1/views/view-1':
              deletedAtStructureVersion =
                  (jsonDecode(request.body)
                          as Map<String, dynamic>)['expected_structure_version']
                      as int?;
              return _json(409, <String, Object?>{
                'message': 'The board structure changed.',
                'meta': <String, Object?>{'current_structure_version': 11},
              });
          }
          return _json(500, <String, Object?>{'message': 'Unexpected request'});
        }),
      );
      await tester.pumpWidget(
        _app(
          TasksScreen(
            api: api,
            session: Session(api),
            pollInterval: const Duration(days: 1),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();
      await tester.tap(find.text('Operations'));
      await tester.pump();
      await tester.pump();
      await tester.pump();

      final title = find.descendant(
        of: find.byKey(const Key('task-row-task-1')),
        matching: find.byType(TextField),
      );
      await tester.enterText(title, 'Local draft');
      await tester.tap(find.byTooltip('Refresh tasks'));
      await tester.pump();
      await tester.pump();
      expect(find.text('Refresh board'), findsOneWidget);

      await tester.tap(find.text('Refresh board'));
      await tester.pump();
      await tester.pump();
      await tester.pump();
      await tester.tap(find.byTooltip('Saved view actions'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Delete view'));
      await tester.pump();

      expect(boardShowReads, 1);
      expect(deletedAtStructureVersion, 11);
    },
  );

  testWidgets('participant reach loss evicts the loaded board', (tester) async {
    final api = ApiClient(
      client: MockClient((request) async {
        switch (request.url.path) {
          case '/api/v1/tasks/boards':
            return _json(200, <String, Object?>{
              'data': <Object?>[_boardWithColumns()],
            });
          case '/api/v1/tasks/boards/board-1/rows':
            return _rowsResponse(title: 'Private assignment', etag: '"b1"');
          case '/api/v1/tasks/boards/board-1/views':
            return _json(200, <String, Object?>{
              'data': <Object?>[_view(name: 'Main', permanent: true)],
              'meta': _viewCapabilities(),
            });
          case '/api/v1/tasks/boards/board-1/participants':
            return _json(404, <String, Object?>{
              'message': 'This task board is unavailable.',
            });
        }
        return _json(500, <String, Object?>{'message': 'Unexpected request'});
      }),
    );
    await tester.pumpWidget(
      _app(
        TasksScreen(
          api: api,
          session: Session(api),
          pollInterval: const Duration(days: 1),
        ),
      ),
    );
    await tester.pump();
    await tester.pump();
    await tester.tap(find.text('Operations'));
    await tester.pump();
    await tester.pump();
    await tester.pump();

    expect(find.text('This task board is unavailable.'), findsOneWidget);
    expect(find.text('Private assignment'), findsNothing);
  });

  testWidgets('an old My Tasks open cannot resurrect state after revocation', (
    tester,
  ) async {
    final boardOpen = Completer<http.Response>();
    var myReads = 0;
    var rowReads = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        switch (request.url.path) {
          case '/api/v1/tasks/boards':
            return _json(200, <String, Object?>{
              'data': <Object?>[_boardWithColumns()],
            });
          case '/api/v1/tasks/my':
            myReads++;
            if (myReads == 1) {
              return _json(
                200,
                <String, Object?>{
                  'data': <Object?>[
                    <String, Object?>{
                      ..._taskWithTitle('Private assignment'),
                      'board': <String, Object?>{
                        'id': 'board-1',
                        'name': 'Operations',
                      },
                    },
                  ],
                  'meta': <String, Object?>{
                    'total': 1,
                    'page': 1,
                    'per_page': 50,
                    'last_page': 1,
                  },
                },
                headers: const <String, String>{'etag': '"mine-1"'},
              );
            }
            return _json(404, <String, Object?>{
              'message': 'Tasks are unavailable.',
            });
          case '/api/v1/tasks/boards/board-1':
            return boardOpen.future;
          case '/api/v1/tasks/boards/board-1/rows':
            rowReads++;
            return _rowsResponse(title: 'Resurrected', etag: '"b1"');
        }
        return _json(500, <String, Object?>{'message': 'Unexpected request'});
      }),
    );
    await tester.pumpWidget(
      _app(
        TasksScreen(
          api: api,
          session: Session(api),
          pollInterval: const Duration(milliseconds: 200),
        ),
      ),
    );
    await tester.pump();
    await tester.pump();
    await tester.tap(find.text('My Tasks'));
    await tester.pump();
    await tester.pump();
    await tester.tap(find.text('Private assignment'));
    await tester.pump(const Duration(milliseconds: 220));
    await tester.pump();
    expect(find.text('This task board is unavailable.'), findsOneWidget);

    boardOpen.complete(
      _json(200, <String, Object?>{'data': _boardWithColumns()}),
    );
    await tester.pump();
    await tester.pump();

    expect(rowReads, 0);
    expect(find.text('This task board is unavailable.'), findsOneWidget);
  });
}

Widget _app(Widget child) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(body: child),
);

http.Response _json(
  int status,
  Map<String, Object?> body, {
  Map<String, String> headers = const <String, String>{},
}) => http.Response(
  jsonEncode(body),
  status,
  headers: <String, String>{'content-type': 'application/json', ...headers},
);

Map<String, Object?> _view({
  String id = 'view-1',
  String name = 'Main',
  String visibility = 'shared',
  bool locked = true,
  bool permanent = false,
  int version = 1,
  List<String> actions = const <String>[],
}) => <String, Object?>{
  'id': id,
  'board_id': 'board-1',
  'owner_user_id': visibility == 'personal' ? 'user-1' : null,
  'visibility': visibility,
  'name': name,
  'is_locked': locked,
  'is_permanent_main': permanent,
  'configuration': _configuration(),
  'version': version,
  'position': 0,
  'preference': null,
  'actions': actions,
};

Map<String, Object?> _configuration() => <String, Object?>{
  'schema': 1,
  'presentation': 'table',
  'filters': <Object?>[],
  'sorts': <Object?>[],
  'hidden_columns': <Object?>[],
  'column_order': <Object?>[
    'owner',
    'status',
    'priority',
    'start_on',
    'due_on',
  ],
  'column_widths': <String, Object?>{},
  'group_by': null,
  'search': '',
};

Map<String, Object?> _viewCapabilities({
  bool createPersonal = true,
  bool createShared = true,
}) => <String, Object?>{
  'capabilities': <String, Object?>{
    'create_personal': createPersonal,
    'create_shared': createShared,
  },
};

Map<String, Object?> _board() => <String, Object?>{
  'id': 'board-1',
  'name': 'Operations',
  'description': null,
  'visibility': 'scope',
  'company_id': 'company-1',
  'franchise_id': null,
  'folder_id': null,
  'structure_version': 7,
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

Map<String, Object?> _boardWithColumns() => <String, Object?>{
  ..._board(),
  'groups': <Object?>[
    <String, Object?>{
      'id': 'group-1',
      'name': 'To do',
      'color': null,
      'position': 0,
      'order_version': 1,
      'archived': false,
    },
  ],
  'statuses': <Object?>[
    <String, Object?>{
      'id': 'status-1',
      'name': 'Working',
      'color': null,
      'category': 'active',
      'archived': false,
    },
  ],
  'core_columns': <Object?>[
    <String, Object?>{'core_key': 'task', 'display_label': 'Task'},
    <String, Object?>{'core_key': 'owner', 'display_label': 'Person'},
    <String, Object?>{'core_key': 'status', 'display_label': 'Status'},
    <String, Object?>{'core_key': 'due_on', 'display_label': 'Due date'},
  ],
};

http.Response _searchResponse(String title) => _json(200, <String, Object?>{
  'data': <Object?>[
    <String, Object?>{
      'board_id': 'board-1',
      'board_name': 'Visible board',
      'task_id': 'task-1',
      'number': 'TASK-1',
      'title': title,
      'deep_link': '/tasks/boards/board-1/tasks/task-1',
    },
  ],
});

http.Response _rowsResponse({required String title, required String etag}) =>
    _pagedRowsResponse(title: title, page: 1, lastPage: 1, etag: etag);

http.Response _pagedRowsResponse({
  required String title,
  required int page,
  required int lastPage,
  String viewId = 'view-1',
  String etag = '"board-page"',
}) => _json(
  200,
  <String, Object?>{
    'data': <Object?>[_taskWithTitle(title)],
    'meta': <String, Object?>{
      'total': 1,
      'page': page,
      'per_page': 50,
      'last_page': lastPage,
      'group_counts': <Object?>[
        <String, Object?>{
          'group_id': 'group-1',
          'group_by_value': 'group-1',
          'count': 1,
        },
      ],
      'status_summaries': <Object?>[
        <String, Object?>{'status_option_id': 'status-1', 'count': 1},
      ],
      'view_id': viewId,
      'view_version': 1,
      'configuration': _configuration(),
    },
  },
  headers: <String, String>{'etag': etag},
);

Map<String, Object?> _taskWithTitle(String title) => <String, Object?>{
  ..._task(),
  'title': title,
  'status_option_id': 'status-1',
  'record_links': <Object?>[],
};

Map<String, Object?> _task() => <String, Object?>{
  'id': 'task-1',
  'board_id': 'board-1',
  'group_id': 'group-1',
  'parent_task_id': null,
  'number': 'TASK-1',
  'title': 'Visible work',
  'owner_user_id': 'user-1',
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
};
