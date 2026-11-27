import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_automate.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Board › Automate reads the Monday-era triggers and actions the server
/// runs (task.column_changed, task.date_arrived, task.shift_date,
/// task.set_value, task.assign add) as sentences, in the condition shapes
/// MondayAutomations writes.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  final rules = <String, Map<String, dynamic>>{
    'r1': {
      'id': 'r1',
      'name': 'r1',
      'trigger_key': 'task.column_changed',
      'is_active': true,
      'conditions': {
        'all': [
          {'field': 'board_id', 'op': 'eq', 'value': 'b-1'},
          {'field': 'column_id', 'op': 'eq', 'value': 'c-rem'},
          {'field': 'value', 'op': 'changed_to', 'value': 'Done'},
        ],
      },
      'actions': [
        {
          'type': 'task.shift_date',
          'column_id': 'c-date',
          'amount': 1,
          'unit': 'weeks',
        },
      ],
    },
    'r2': {
      'id': 'r2',
      'name': 'r2',
      'trigger_key': 'task.date_arrived',
      'is_active': true,
      'conditions': {
        'all': [
          {'field': 'board_id', 'op': 'eq', 'value': 'b-1'},
          {'field': 'column_id', 'op': 'eq', 'value': 'due'},
          {'field': 'days_until', 'op': 'eq', 'value': 1},
        ],
      },
      'actions': [
        {'type': 'task.set_value', 'column_id': 'c-rem', 'value': 'Reminder'},
      ],
    },
    'r3': {
      'id': 'r3',
      'name': 'r3',
      'trigger_key': 'task.created',
      'is_active': true,
      'conditions': {
        'all': [
          {'field': 'board_id', 'op': 'eq', 'value': 'b-1'},
        ],
      },
      'actions': [
        {'type': 'task.assign', 'user_field': 'user:u-2', 'add': true},
      ],
    },
  };

  testWidgets('Monday-era rules read as sentences', (tester) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Omar Feld',
              'email': 'omar@example.test',
              'permissions': ['tasks.view', 'automations.view'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Northside',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path == '/automations') {
          return json({
            'data': [
              for (final r in rules.values)
                {'id': r['id'], 'trigger_key': r['trigger_key']},
            ],
          });
        }
        final id = path.split('/').last;
        return json({'data': rules[id]});
      }),
    );
    final session = Session(api);
    await session.adopt('tok');
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: BoardAutomatePanel(
            api: api,
            session: session,
            board: const {
              'id': 'b-1',
              'name': 'My board',
              'my_role': 'owner',
              'columns': [
                {'id': 'c-rem', 'name': 'Reminder', 'type': 'status'},
                {'id': 'c-date', 'name': 'Task Date', 'type': 'date'},
              ],
              'assignable': [
                {'id': 'u-2', 'name': 'Tobin Marsh'},
              ],
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.text('When Reminder changes to Done, push Task Date by 1 weeks'),
      findsOneWidget,
    );
    expect(
      find.text('The day before Due arrives, set Reminder to Reminder'),
      findsOneWidget,
    );
    expect(
      find.text('When a task is created, add Tobin Marsh to it'),
      findsOneWidget,
    );
  });
}
