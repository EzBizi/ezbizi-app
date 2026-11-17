import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management › Dashboard (Jose, 2026-11-17): what waits for parts,
/// the late parts, and the reminders, cleared or moved from here. Invented
/// people only.
void main() {
  testWidgets('the Dashboard counts, lists late parts, and clears a reminder', (
    tester,
  ) async {
    final patches = <Map<String, dynamic>>[];
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
        if (path.endsWith('/reminder')) {
          patches.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': <String, dynamic>{}});
        }
        if (path.endsWith('/project-board/dashboard')) {
          return _json({
            'data': {
              'waiting_items': 5,
              'waiting_jobs': 3,
              'late': [
                {
                  'id': 'p-1',
                  'item': 'Stainless cap',
                  'job_number': 'J-1001',
                  'customer': 'Lena Park',
                  'ordered_at': '2026-11-10T09:00:00Z',
                  'late': true,
                },
              ],
              'reminders': [
                {
                  'id': 'p-2',
                  'item': 'Mortar',
                  'job_number': 'J-1002',
                  'customer': 'Omar Diaz',
                  'remind_at': '2026-11-17T15:00:00Z',
                  'remind_note': 'Call the store',
                  'due': true,
                },
              ],
            },
          });
        }
        return _json({
          'data': <dynamic>[],
          'meta': {
            'total': 0,
            'last_page': 1,
            'counts': <String, dynamic>{},
            'places': <dynamic>[],
            'columns': <dynamic>[],
            'labels': <String, dynamic>{},
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

    await tester.tap(find.text('Dashboard'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('project-board-dashboard')), findsOneWidget);
    expect(find.text('Items waiting for parts'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('project-board-dashboard-items')),
        matching: find.text('5'),
      ),
      findsOneWidget,
    );
    expect(find.text('Stainless cap'), findsOneWidget);
    expect(find.textContaining('Call the store'), findsOneWidget);

    await tester.tap(
      find.byKey(const ValueKey('project-board-remind-clear-p-2')),
    );
    await tester.pumpAndSettle();
    expect(patches.single, {'remind_at': null});

    await tester.pumpWidget(const SizedBox()); // stops the board's live poll
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
