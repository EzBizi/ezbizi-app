import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management › Inbox (Jose, 2026-11-17): one thread per board job,
/// the client's answers included, and a reply from the branch line.
/// Invented people only.
void main() {
  testWidgets('the Inbox lists a client, opens the thread and replies', (
    tester,
  ) async {
    final posts = <Map<String, dynamic>>[];
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
        if (path.endsWith('/project-board/inbox/j-1')) {
          if (request.method == 'POST') {
            posts.add(jsonDecode(request.body) as Map<String, dynamic>);
            return _json({
              'data': {
                'id': 'm-3',
                'body': 'Wednesday works too.',
                'direction': 'outbound',
                'at': '2026-11-17T15:00:00Z',
              },
            });
          }
          return _json({
            'data': [
              {
                'id': 'm-1',
                'body': 'Can we come Tuesday 8-11?',
                'direction': 'outbound',
                'at': '2026-11-17T13:00:00Z',
              },
              {
                'id': 'm-2',
                'body': 'NO',
                'direction': 'inbound',
                'at': '2026-11-17T14:00:00Z',
              },
            ],
          });
        }
        if (path.endsWith('/project-board/inbox')) {
          return _json({
            'data': [
              {
                'job_id': 'j-1',
                'job_number': 'J-1001',
                'customer': 'Lena Park',
                'last_body': 'NO',
                'last_at': '2026-11-17T14:00:00Z',
                'last_direction': 'inbound',
                'waiting': true,
              },
            ],
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

    await tester.tap(find.text('Inbox'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('project-inbox')), findsOneWidget);
    expect(find.text('Lena Park · J-1001'), findsOneWidget);
    expect(find.text('Waiting'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('project-inbox-thread-j-1')));
    await tester.pumpAndSettle();
    expect(find.text('Can we come Tuesday 8-11?'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('project-inbox-box')),
      'Wednesday works too.',
    );
    await tester.tap(find.byKey(const Key('project-inbox-send')));
    await tester.pumpAndSettle();
    expect(posts.single, {'body': 'Wednesday works too.'});
    expect(find.text('Wednesday works too.'), findsOneWidget);

    await tester.pumpWidget(const SizedBox()); // stops the board's live poll
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
