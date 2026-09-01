import 'dart:convert';

import 'package:ezbizi_client/screens/tasks/task_models.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('task client contracts', () {
    test(
      'parses a board directory entry and keeps stable status categories separate from labels',
      () {
        final board = TaskBoard.fromJson(_boardFixture());

        expect(board.id, 'board-1');
        expect(board.visibility, TaskBoardVisibility.scope);
        expect(board.isFavorite, isTrue);
        expect(board.statuses.single.name, 'Doing now');
        expect(board.statuses.single.color, '#ff9900');
        expect(board.statuses.single.category, TaskStatusCategory.active);
        expect(board.priorities.single.rank, TaskPriorityRank.urgent);
      },
    );

    test(
      'falls back for new enum values without confusing them with tenant labels',
      () {
        final json = _boardFixture();
        (json['statuses'] as List<Object?>).first = <String, Object?>{
          'id': 'status-2',
          'name': 'Tenant wording',
          'color': '#123456',
          'category': 'server_added_category',
          'archived': false,
        };
        json['visibility'] = 'server_added_visibility';

        final board = TaskBoard.fromJson(json);

        expect(board.visibility, TaskBoardVisibility.unknown);
        expect(board.rawVisibility, 'server_added_visibility');
        expect(board.statuses.single.category, TaskStatusCategory.unknown);
        expect(board.statuses.single.rawCategory, 'server_added_category');
        expect(board.statuses.single.name, 'Tenant wording');
      },
    );

    test(
      'parses all typed row values and retains an unknown column as read-only raw data',
      () {
        final board = TaskBoard.fromJson(_boardFixture(includeColumns: true));
        final page = TaskRowsPage.fromJson(_rowsFixture(), board: board);
        final row = page.rows.single;

        expect(row.values['column-text']?.value, 'Gate code');
        expect(row.values['column-number']?.value, 12.5);
        expect(row.values['column-checkbox']?.value, isTrue);
        expect(row.values['column-date']?.value, DateTime.utc(2026, 9, 10));
        expect(row.values['column-people']?.value, ['user-1', 'user-2']);
        expect(row.values['column-label']?.value, ['option-1']);
        expect(
          row.values['column-link']?.value,
          const TaskLinkValue(url: 'https://example.com/job', label: 'Job'),
        );
        expect(row.values['column-file']?.value, ['attachment-1']);

        final unknown = row.values['column-unknown']!;
        expect(unknown.kind, TaskColumnKind.unknown);
        expect(unknown.isReadOnly, isTrue);
        expect(unknown.rawValue, <String, Object?>{
          'start': '08:00',
          'end': '09:00',
        });
        expect(board.customColumns.last.rawType, 'timeline');
      },
    );

    test('parses an exact schema-one table view definition', () {
      final view = TaskViewDefinition.fromJson(_viewFixture());

      expect(view.visibility, TaskViewVisibility.shared);
      expect(view.version, 4);
      expect(view.configuration.schema, 1);
      expect(view.configuration.presentation, TaskPresentation.table);
      expect(view.configuration.filters.single.field, 'due_on');
      expect(view.configuration.sorts.single.direction, TaskSortDirection.desc);
      expect(view.configuration.columnWidths['task'], isNull);
      expect(view.preference?.density, TaskViewDensity.compact);
    });

    test(
      'a schema:1 configuration decodes with a table presentation and a null window',
      () {
        final configuration = TaskViewConfiguration.fromJson(
          _viewFixture()['configuration']! as Map<String, Object?>,
        );

        expect(configuration.schema, 1);
        expect(configuration.presentation, TaskPresentation.table);
        expect(configuration.rawPresentation, 'table');
        expect(configuration.window, isNull);
      },
    );

    test(
      'THE ONE RULE: an unknown presentation from the server degrades to table rather than throwing',
      () {
        final json = _schemaTwoConfiguration(
          presentation: 'server_added_presentation',
          window: <String, Object?>{'anything': 'goes'},
        );

        final configuration = TaskViewConfiguration.fromJson(json);

        expect(configuration.presentation, TaskPresentation.table);
        expect(configuration.rawPresentation, 'server_added_presentation');
        expect(configuration.window, isNull);
      },
    );

    test('a table window round-trips page and per_page', () {
      final json = _schemaTwoConfiguration(
        presentation: 'table',
        window: <String, Object?>{'page': 2, 'per_page': 25},
      );

      final configuration = TaskViewConfiguration.fromJson(json);
      final window = configuration.window;

      expect(window, isA<TaskTableWindow>());
      expect((window as TaskTableWindow).page, 2);
      expect(window.perPage, 25);
      expect(configuration.toJson()['window'], <String, Object?>{
        'page': 2,
        'per_page': 25,
      });
    });

    test('a kanban window round-trips bucket_field and buckets', () {
      final json = _schemaTwoConfiguration(
        presentation: 'kanban',
        window: <String, Object?>{
          'bucket_field': 'status',
          'buckets': <Object?>['status-1', 'status-2'],
        },
      );

      final configuration = TaskViewConfiguration.fromJson(json);
      final window = configuration.window;

      expect(window, isA<TaskKanbanWindow>());
      expect((window as TaskKanbanWindow).bucketField, 'status');
      expect(window.buckets, <String>['status-1', 'status-2']);
      expect(configuration.toJson()['window'], <String, Object?>{
        'bucket_field': 'status',
        'buckets': <String>['status-1', 'status-2'],
      });
    });

    test('a calendar window round-trips from and to', () {
      final json = _schemaTwoConfiguration(
        presentation: 'calendar',
        window: <String, Object?>{'from': '2026-09-01', 'to': '2026-09-30'},
      );

      final configuration = TaskViewConfiguration.fromJson(json);
      final window = configuration.window;

      expect(window, isA<TaskDateRangeWindow>());
      expect((window as TaskDateRangeWindow).from, DateTime.utc(2026, 9, 1));
      expect(window.to, DateTime.utc(2026, 9, 30));
      expect(configuration.toJson()['window'], <String, Object?>{
        'from': '2026-09-01',
        'to': '2026-09-30',
      });
    });

    test('a timeline window round-trips from and to', () {
      final json = _schemaTwoConfiguration(
        presentation: 'timeline',
        window: <String, Object?>{'from': '2026-09-01', 'to': '2026-09-14'},
      );

      final configuration = TaskViewConfiguration.fromJson(json);
      final window = configuration.window;

      expect(window, isA<TaskDateRangeWindow>());
      expect((window as TaskDateRangeWindow).from, DateTime.utc(2026, 9, 1));
      expect(window.to, DateTime.utc(2026, 9, 14));
    });

    test('a workload window round-trips from, to and unit', () {
      final json = _schemaTwoConfiguration(
        presentation: 'workload',
        window: <String, Object?>{
          'from': '2026-09-01',
          'to': '2026-09-14',
          'unit': 'week',
        },
      );

      final configuration = TaskViewConfiguration.fromJson(json);
      final window = configuration.window;

      expect(window, isA<TaskWorkloadWindow>());
      expect((window as TaskWorkloadWindow).from, DateTime.utc(2026, 9, 1));
      expect(window.to, DateTime.utc(2026, 9, 14));
      expect(window.unit, TaskWorkloadUnit.week);
      expect(configuration.toJson()['window'], <String, Object?>{
        'from': '2026-09-01',
        'to': '2026-09-14',
        'unit': 'week',
      });
    });

    test('meta.omitted parses undated, outside_window and unowned', () {
      final json = _rowsFixture();
      (json['meta']! as Map<String, Object?>)['omitted'] = <String, Object?>{
        'undated': 2,
        'outside_window': 3,
        'unowned': 1,
      };
      final board = TaskBoard.fromJson(_boardFixture());

      final page = TaskRowsPage.fromJson(json, board: board);

      expect(page.omitted.undated, 2);
      expect(page.omitted.outsideWindow, 3);
      expect(page.omitted.unowned, 1);
    });

    test('meta.omitted defaults to zero when the server omits the key', () {
      final board = TaskBoard.fromJson(_boardFixture());

      final page = TaskRowsPage.fromJson(_rowsFixture(), board: board);

      expect(page.omitted.undated, 0);
      expect(page.omitted.outsideWindow, 0);
      expect(page.omitted.unowned, 0);
    });

    test(
      'workload rows are per-owner units, not task rows, and never crash the parser',
      () {
        final json = <String, Object?>{
          'data': <Object?>[
            <String, Object?>{
              'owner_id': 'user-1',
              'owner_name': 'Taylor',
              'units': <Object?>[
                <String, Object?>{'date': '2026-09-01', 'count': 3},
                <String, Object?>{'date': '2026-09-02', 'count': 1},
              ],
            },
            <String, Object?>{
              'owner_id': null,
              'owner_name': null,
              'units': <Object?>[
                <String, Object?>{'date': '2026-09-01', 'count': 5},
              ],
            },
          ],
          'meta': <String, Object?>{
            'total': 0,
            'page': 1,
            'per_page': 50,
            'last_page': 1,
            'group_counts': <Object?>[],
            'status_summaries': <Object?>[],
            'omitted': <String, Object?>{
              'undated': 0,
              'outside_window': 0,
              'unowned': 1,
            },
            'view_id': 'view-1',
            'view_version': 1,
            'configuration': _schemaTwoConfiguration(
              presentation: 'workload',
              window: <String, Object?>{
                'from': '2026-09-01',
                'to': '2026-09-02',
                'unit': 'day',
              },
            ),
          },
        };
        final board = TaskBoard.fromJson(_boardFixture());

        final page = TaskRowsPage.fromJson(json, board: board);

        expect(page.rows, isEmpty);
        expect(page.workloadRows, hasLength(2));
        expect(page.workloadRows.first.ownerId, 'user-1');
        expect(page.workloadRows.first.units.first.count, 3);
        expect(page.workloadRows.last.ownerId, isNull);
        expect(page.workloadRows.last.units.single.count, 5);
      },
    );

    test(
      'blocked_by is absent on a table row and present as typed refs on a timeline row',
      () {
        final tableRow = TaskRow.fromJson(
          (_rowsFixture()['data']! as List<Object?>).single
              as Map<String, Object?>,
        );
        expect(tableRow.blockedBy, isNull);

        final timelineRowJson = Map<String, Object?>.from(
          (_rowsFixture()['data']! as List<Object?>).single
              as Map<String, Object?>,
        )..['blocked_by'] = <Object?>[
          <String, Object?>{
            'id': 'dependency-1',
            'task_id': 'task-0',
            'number': 'TASK-0',
            'title': 'Pour the slab',
          },
        ];
        final timelineRow = TaskRow.fromJson(timelineRowJson);

        expect(timelineRow.blockedBy, hasLength(1));
        expect(timelineRow.blockedBy!.single.taskId, 'task-0');
        expect(timelineRow.blockedBy!.single.title, 'Pour the slab');
      },
    );

    test(
      'custom_values arriving as a JSON array (not an object) is rejected, not silently tolerated',
      () {
        // Regression guard for a production incident: PHP's json_encode()
        // renders an empty PHP array as `[]`, never `{}` — so a task with
        // no custom column values used to reach the client as
        // `"custom_values":[]` and broke the whole Tasks screen. The fix
        // is server-side (TaskListQuery::present() and
        // TaskDetailsController::customValues() now emit `(object) $custom`
        // so an empty result always serializes as `{}`). This test does
        // NOT add client-side tolerance for the array shape — it locks in
        // that the client keeps rejecting it. `_expectMap` is the single
        // contract enforcement point; if the server contract regresses
        // again, this must fail loudly instead of rendering a task with
        // silently-dropped custom values.
        //
        // Round-trips through jsonEncode/jsonDecode (rather than a
        // hand-built Dart map literal) because that is the one difference
        // that let the real bug slip past every hand-built fixture in this
        // file: a literal `<String, Object?>{}` is already a Dart Map and
        // can never reproduce what the wire actually sent.
        final wire = jsonEncode(
          Map<String, Object?>.from(_minimalTaskJson())
            ..['custom_values'] = <Object?>[],
        );
        final json = jsonDecode(wire) as Map<String, Object?>;

        expect(
          () => TaskRow.fromJson(json),
          throwsA(
            isA<FormatException>().having(
              (error) => error.message,
              'message',
              'custom_values must be an object.',
            ),
          ),
        );
      },
    );

    test(
      'a task detail carries its recurrence declaration when the server sends one',
      () {
        final detailJson = Map<String, Object?>.from(_minimalTaskJson())
          ..['subtasks'] = <Object?>[]
          ..['record_links'] = <Object?>[]
          ..['recurrence'] = <String, Object?>{
            'mode': 'after_completion',
            'every': 2,
            'unit': 'week',
            'until': null,
            'count': 5,
          };

        final detail = TaskDetailSnapshot.fromJson(detailJson, etag: '"d"');

        expect(detail.recurrence, isNotNull);
        expect(detail.recurrence!.mode, TaskRecurrenceMode.afterCompletion);
        expect(detail.recurrence!.every, 2);
        expect(detail.recurrence!.unit, TaskRecurrenceUnit.week);
        expect(detail.recurrence!.until, isNull);
        expect(detail.recurrence!.count, 5);
      },
    );

    test('a task detail has no recurrence when the server sends none', () {
      final detailJson = Map<String, Object?>.from(_minimalTaskJson())
        ..['subtasks'] = <Object?>[]
        ..['record_links'] = <Object?>[];

      final detail = TaskDetailSnapshot.fromJson(detailJson, etag: '"d"');

      expect(detail.recurrence, isNull);
    });

    test(
      'parses typed partial task conflicts without requiring a full row',
      () {
        final conflict = TaskApiConflict.fromResponse(
          statusCode: 409,
          body: <String, Object?>{
            'message': 'That task changed while you were editing it.',
            'data': <String, Object?>{
              'id': 'task-1',
              'title': 'Server title',
              'collaborator_user_ids': <Object?>[],
              'due_on': '2026-09-11',
              'version': 8,
            },
            'meta': <String, Object?>{
              'current_version': 8,
              'conflicting_fields': <Object?>['title'],
            },
          },
        );

        expect(conflict.kind, TaskConflictKind.task);
        expect(conflict.currentVersion, 8);
        expect(conflict.currentTask?.version, 8);
        expect(conflict.currentTask?.field('title')?.value, 'Server title');
        expect(
          conflict.currentTask?.field('due_on')?.value,
          DateTime.utc(2026, 9, 11),
        );
        expect(
          conflict.currentTask?.field('collaborator_user_ids')?.value,
          <String>[],
        );
        expect(conflict.conflictingFields, ['title']);
      },
    );

    test(
      'keeps full board-row subtasks separate from compact detail subtasks',
      () {
        final boardRow = Map<String, Object?>.from(
          (_rowsFixture()['data'] as List<Object?>).single
              as Map<String, Object?>,
        );
        final fullSubtask = Map<String, Object?>.from(boardRow)
          ..['id'] = 'subtask-1'
          ..['parent_task_id'] = 'task-1'
          ..['title'] = 'Measure opening'
          ..['owner_user_id'] = 'user-2'
          ..['priority_option_id'] = 'priority-1'
          ..['due_on'] = '2026-09-12'
          ..['detail_version'] = 9
          ..['custom_values'] = <String, Object?>{'column-text': 'Keep me'}
          ..['subtasks'] = <Object?>[
            <String, Object?>{
              ...boardRow,
              'id': 'nested-1',
              'parent_task_id': 'subtask-1',
              'title': 'Nested server row',
              'subtasks': <Object?>[],
            },
          ];
        boardRow['subtasks'] = <Object?>[fullSubtask];

        final row = TaskRow.fromJson(
          boardRow,
          board: TaskBoard.fromJson(_boardFixture(includeColumns: true)),
        );
        final detailJson = Map<String, Object?>.from(boardRow)
          ..['subtasks'] = <Object?>[
            <String, Object?>{
              'id': 'subtask-1',
              'title': 'Measure opening',
              'status_option_id': 'status-1',
              'version': 2,
              'archived': false,
            },
          ]
          ..['record_links'] = <Object?>[];
        final detail = TaskDetailSnapshot.fromJson(
          detailJson,
          etag: '"detail"',
        );

        expect(row.subtasks.single.ownerUserId, 'user-2');
        expect(row.subtasks.single.priorityOptionId, 'priority-1');
        expect(row.subtasks.single.dueOn, DateTime.utc(2026, 9, 12));
        expect(row.subtasks.single.detailVersion, 9);
        expect(row.subtasks.single.values['column-text']?.value, 'Keep me');
        expect(row.subtasks.single.subtasks.single.title, 'Nested server row');
        expect(detail.subtasks.single.id, 'subtask-1');
        expect(detail.subtasks.single.title, 'Measure opening');
        expect(detail.subtasks.single.version, 2);
        expect(detail.subtasks.single.archived, isFalse);
      },
    );

    test('an unavailable record link retains no target identity or route', () {
      final link = TaskRecordLink.fromJson(<String, Object?>{
        'link_id': 'link-1',
        'available': false,
        'type': 'Customer',
      });

      expect(link.available, isFalse);
      expect(link.typeLabel, 'Customer');
      expect(link.recordId, isNull);
      expect(link.label, isNull);
      expect(link.subtitle, isNull);
      expect(link.route, isNull);
    });

    test(
      'rejects malformed known fields instead of silently coercing them',
      () {
        final json = _boardFixture();
        json['structure_version'] = '7';

        expect(() => TaskBoard.fromJson(json), throwsFormatException);
        expect(
          () => TaskRecordLink.fromJson(<String, Object?>{
            'link_id': 'link-1',
            'available': false,
            'type': 'Customer',
            'label': 'must not leak',
          }),
          throwsFormatException,
        );
      },
    );
    test('normalizes the pinned task identity out of layout mutations', () {
      final json = Map<String, Object?>.from(
        _viewFixture()['configuration']! as Map<String, Object?>,
      );
      json['hidden_columns'] = <Object?>['task', 'owner'];
      json['column_order'] = <Object?>['due_on', 'task', 'status'];
      json['column_widths'] = <String, Object?>{'task': 360, 'due_on': 180};

      final configuration = TaskViewConfiguration.fromJson(json);

      expect(configuration.hiddenColumns, <String>['owner']);
      expect(configuration.columnOrder, <String>['due_on', 'status']);
      expect(configuration.columnWidths, <String, int>{'due_on': 180});
      expect(configuration.toJson()['column_order'], <String>[
        'due_on',
        'status',
      ]);
    });
  });
}

Map<String, Object?> _boardFixture({bool includeColumns = false}) =>
    <String, Object?>{
      'id': 'board-1',
      'name': 'Operations',
      'description': 'Field work',
      'visibility': 'scope',
      'company_id': 'company-1',
      'franchise_id': null,
      'folder_id': 'folder-1',
      'structure_version': 7,
      'archived': false,
      'is_favorite': true,
      'last_opened_at': '2026-08-27T12:30:00Z',
      'groups': <Object?>[
        <String, Object?>{'id': 'group-1', 'name': 'To do', 'color': '#579bfc'},
      ],
      'statuses': <Object?>[
        <String, Object?>{
          'id': 'status-1',
          'name': 'Doing now',
          'color': '#ff9900',
          'category': 'active',
          'archived': false,
        },
      ],
      'priorities': <Object?>[
        <String, Object?>{
          'id': 'priority-1',
          'name': 'Drop everything',
          'color': '#e2445c',
          'rank': 'urgent',
          'archived': false,
        },
      ],
      'core_columns': <Object?>[
        <String, Object?>{'core_key': 'task', 'display_label': 'Task'},
      ],
      'custom_columns': includeColumns ? _columnFixtures() : <Object?>[],
      'views': <Object?>[],
    };

List<Object?> _columnFixtures() => <Object?>[
  _column('column-text', 'text'),
  _column('column-number', 'number'),
  _column('column-checkbox', 'checkbox'),
  _column('column-date', 'date'),
  _column('column-people', 'people'),
  _column(
    'column-label',
    'label',
    options: <Object?>[
      <String, Object?>{
        'id': 'option-1',
        'name': 'Exterior',
        'color': '#00c875',
        'archived': false,
      },
    ],
  ),
  _column('column-link', 'link'),
  _column('column-file', 'file'),
  _column('column-unknown', 'timeline'),
];

Map<String, Object?> _column(
  String id,
  String type, {
  List<Object?> options = const [],
}) => <String, Object?>{
  'id': id,
  'name': id,
  'type': type,
  'number_suffix': type == 'number' ? ' ft' : null,
  'archived': false,
  'options': options,
};

Map<String, Object?> _rowsFixture() => <String, Object?>{
  'data': <Object?>[
    <String, Object?>{
      'id': 'task-1',
      'board_id': 'board-1',
      'group_id': 'group-1',
      'parent_task_id': null,
      'number': 'TASK-1',
      'title': 'Install north door',
      'owner_user_id': 'user-1',
      'collaborator_user_ids': <Object?>['user-2'],
      'status_option_id': 'status-1',
      'priority_option_id': 'priority-1',
      'start_on': '2026-09-09',
      'due_on': '2026-09-10',
      'position': 1024,
      'version': 3,
      'detail_version': 5,
      'custom_values': <String, Object?>{
        'column-text': 'Gate code',
        'column-number': 12.5,
        'column-checkbox': true,
        'column-date': '2026-09-10',
        'column-people': <Object?>['user-1', 'user-2'],
        'column-label': <Object?>['option-1'],
        'column-link': <String, Object?>{
          'url': 'https://example.com/job',
          'label': 'Job',
        },
        'column-file': <Object?>['attachment-1'],
        'column-unknown': <String, Object?>{'start': '08:00', 'end': '09:00'},
      },
      'group_by_value': 'group-1',
      'subtasks': <Object?>[],
    },
  ],
  'meta': <String, Object?>{
    'total': 1,
    'page': 1,
    'per_page': 50,
    'last_page': 1,
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
    'view_id': 'view-1',
    'view_version': 4,
    'configuration': _viewFixture()['configuration'],
  },
};

Map<String, Object?> _schemaTwoConfiguration({
  required String presentation,
  Map<String, Object?>? window,
}) => <String, Object?>{
  'schema': 2,
  'presentation': presentation,
  'filters': <Object?>[],
  'sorts': <Object?>[],
  'hidden_columns': <Object?>[],
  'column_order': <Object?>['task'],
  'column_widths': <String, Object?>{},
  'group_by': null,
  'search': '',
  'window': window,
};

Map<String, Object?> _minimalTaskJson() => <String, Object?>{
  'id': 'task-1',
  'board_id': 'board-1',
  'group_id': null,
  'parent_task_id': null,
  'number': 'TASK-1',
  'title': 'Install north door',
  'owner_user_id': null,
  'collaborator_user_ids': <Object?>[],
  'status_option_id': 'status-1',
  'priority_option_id': null,
  'start_on': null,
  'due_on': null,
  'position': 1,
  'version': 1,
  'detail_version': 1,
  'custom_values': <String, Object?>{},
  'group_by_value': null,
};

Map<String, Object?> _viewFixture() => <String, Object?>{
  'id': 'view-1',
  'board_id': 'board-1',
  'owner_user_id': null,
  'visibility': 'shared',
  'name': 'Main table',
  'is_locked': true,
  'is_permanent_main': true,
  'configuration': <String, Object?>{
    'schema': 1,
    'presentation': 'table',
    'filters': <Object?>[
      <String, Object?>{
        'field': 'due_on',
        'operator': 'between',
        'value': <Object?>['2026-09-01', '2026-09-30'],
      },
    ],
    'sorts': <Object?>[
      <String, Object?>{'field': 'due_on', 'direction': 'desc'},
    ],
    'hidden_columns': <Object?>['owner'],
    'column_order': <Object?>['task', 'status', 'due_on'],
    'column_widths': <String, Object?>{'task': 320},
    'group_by': 'status',
    'search': 'door',
  },
  'version': 4,
  'position': 0,
  'preference': <String, Object?>{
    'view_id': 'view-1',
    'collapsed_group_ids': <Object?>['group-1'],
    'density': 'compact',
    'is_last_active': true,
    'scroll_left': 30,
    'scroll_top': 40,
  },
};
