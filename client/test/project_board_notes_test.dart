import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/job_detail_panel.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-11-17: "Clicking Notes should open only the notes sidebar,
/// nothing else." Invented people only.
void main() {
  testWidgets('clicking Notes opens the notes alone, not the job', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              'id': 'u-1',
              'name': 'Dana',
              'email': 'dana@brightside.example',
              'permissions': ['project_board.view', 'jobs.view', 'jobs.edit'],
              'modules': <dynamic>[],
              'organization': {'id': 'org-1', 'name': 'Brightside'},
            },
          });
        }
        if (path.endsWith('/settings')) return _json({'data': <dynamic>[]});
        if (path.endsWith('/jobs/j-1')) {
          return _json({
            'data': {
              'id': 'j-1',
              'notes': [
                {
                  'id': 'n-1',
                  'body': 'Call before 9, the gate is locked.',
                  'author': {'name': 'Dana'},
                  'created_at': '2026-11-16T14:00:00Z',
                },
              ],
            },
          });
        }
        return _json({
          'data': [
            {
              'id': 'j-1',
              'number': 'J-1001',
              'customer': 'Lena Park',
              'status': 'Pending',
              'notes': 'Call before 9, the gate is locked.',
              'ready_missing': <String>[],
            },
          ],
          'meta': {
            'total': 1,
            'last_page': 1,
            'counts': {'unscheduled': 1},
            'places': <dynamic>[],
            'columns': [
              {'key': 'customer', 'label': 'Client', 'visible': true},
              {'key': 'notes', 'label': 'Notes', 'visible': true},
            ],
            'labels': <String, dynamic>{},
            'totals': {'paid': 0, 'due': 0, 'total': 0, 'currency': 'USD'},
            'can_edit': true,
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
        home: Scaffold(body: ProjectBoardScreen(api: api, session: session)),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('project-board-notes:j-1')));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('project-board-notes-panel')),
      findsOneWidget,
    );
    expect(find.text('Lena Park · J-1001'), findsOneWidget);
    expect(find.byType(JobDetailPanel), findsNothing, reason: 'Notes only.');

    await tester.pumpWidget(const SizedBox()); // stops the board's live poll
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
