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
      expect(view.configuration.presentation, TaskViewPresentation.table);
      expect(view.configuration.filters.single.field, 'due_on');
      expect(view.configuration.sorts.single.direction, TaskSortDirection.desc);
      expect(view.configuration.columnWidths['task'], 320);
      expect(view.preference?.density, TaskViewDensity.compact);
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
      'parses compact task-detail subtasks without requiring row-only fields',
      () {
        final json = Map<String, Object?>.from(
          (_rowsFixture()['data'] as List<Object?>).single
              as Map<String, Object?>,
        );
        json['subtasks'] = <Object?>[
          <String, Object?>{
            'id': 'subtask-1',
            'title': 'Measure opening',
            'status_option_id': 'status-1',
            'version': 2,
            'archived': false,
          },
        ];

        final task = TaskRow.fromJson(json);

        expect(task.subtasks.single.id, 'subtask-1');
        expect(task.subtasks.single.title, 'Measure opening');
        expect(task.subtasks.single.version, 2);
        expect(task.subtasks.single.archived, isFalse);
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
