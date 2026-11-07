import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management (Jose, 2026-11-06): the table is drawn from the
/// company's settings the server sends, the Franchise filter is sent, and
/// the Settings sub-tab saves the settings it edits. Invented people only.
void main() {
  testWidgets('columns, labels, the franchise filter and Settings', (
    tester,
  ) async {
    final requests = <http.Request>[];
    final api = ApiClient(
      client: MockClient((request) async {
        requests.add(request);
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              'id': 'u-1',
              'name': 'Dana',
              'email': 'dana@brightside.example',
              'permissions': [
                'project_board.view',
                'jobs.view',
                'organizations.edit',
              ],
              'modules': <dynamic>[],
              'organization': {'id': 'org-1', 'name': 'Brightside'},
              'scope': {
                'type': 'organization',
                'id': null,
                'label': 'Brightside',
                'path': ['Brightside'],
              },
            },
          });
        }
        if (path.endsWith('/settings') && request.method == 'GET') {
          return _json({
            'data': [
              {
                'key': 'project_board.tabs',
                'value': {
                  'unscheduled': {
                    'statuses': ['Pending'],
                    'scheduled': false,
                  },
                },
              },
              {
                'key': 'project_board.part_tags',
                'value': ['WAITING PARTS'],
              },
            ],
          });
        }
        if (request.method == 'PATCH') return _json({'data': {}});
        return _json({
          'data': [
            {
              'id': 'j-1',
              'number': 'J-1001',
              'workiz_id': '7001',
              'customer': 'Lena Park',
              'franchise': 'Ohio',
              'status': 'Pending',
              'part_status': 'WAITING PARTS',
              'required': 'Crown Repair',
            },
          ],
          'meta': {
            'total': 1,
            'last_page': 1,
            'counts': {'unscheduled': 1},
            'franchises': [
              {'id': 'f-1', 'name': 'Ohio', 'company': 'Brightside'},
            ],
            'columns': [
              {'key': 'customer', 'label': 'Client', 'visible': true},
              {'key': 'number', 'label': 'EzBizi ID', 'visible': false},
              {
                'key': 'part_status',
                'label': 'Parts',
                'visible': true,
                'as_label': true,
              },
              {'key': 'required', 'label': 'Required', 'visible': true},
            ],
            'labels': {
              'part_status': {'WAITING PARTS': 'caution'},
            },
            'settings_scope': {'scope_type': 'company', 'scope_id': 'c-1'},
          },
        });
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1600, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ProjectBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // The company's names and order; a hidden column is not drawn.
    expect(find.text('CLIENT'), findsOneWidget);
    expect(find.text('Lena Park'), findsOneWidget);
    expect(find.text('J-1001'), findsNothing);
    final pill = tester.widget<StatusPill>(
      find.widgetWithText(StatusPill, 'WAITING PARTS'),
    );
    expect(pill.tone, Tone.caution);

    await tester.tap(find.byKey(const Key('project-board-franchise')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Brightside · Ohio').last);
    await tester.pumpAndSettle();
    expect(requests.last.url.queryParameters['franchise_id'], 'f-1');

    await tester.enterText(
      find.byKey(const Key('project-board-search')),
      'lena',
    );
    await tester.pump(const Duration(milliseconds: 401));
    await tester.pumpAndSettle();
    expect(requests.last.url.queryParameters['q'], 'lena');
    expect(requests.last.url.queryParameters['franchise_id'], 'f-1');

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('project-board-statuses:unscheduled')),
      'Pending, On Hold',
    );
    await tester.ensureVisible(find.byKey(const Key('project-board-save')));
    await tester.tap(find.byKey(const Key('project-board-save')));
    await tester.pumpAndSettle();

    final tabs = requests.firstWhere(
      (r) => r.method == 'PATCH' && r.url.path.endsWith('project_board.tabs'),
    );
    expect(tabs.url.queryParameters['scope_type'], 'company');
    expect((jsonDecode(tabs.body)['value'] as Map)['unscheduled']['statuses'], [
      'Pending',
      'On Hold',
    ]);
    expect(find.text('Project Management settings saved.'), findsOneWidget);
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
