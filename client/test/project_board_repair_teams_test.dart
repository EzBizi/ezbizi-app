import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-11-16: "it should already show the exisiting repair team -
/// contractors". The Settings card lists the teams the server finds, each
/// with Rename (the side panel) and Delete (confirmed inside the card, no
/// dialog), both sent through POST /project-board/repair-teams.
void main() {
  testWidgets('existing repair teams are listed with Rename and Delete', (
    tester,
  ) async {
    final posts = <Map<String, dynamic>>[];
    var teams = <String>['CONTRACTOR: OMER', 'REPAIR TEAM: ISRAEL'];
    List<Map<String, Object?>> list() => [
      for (final t in teams) {'name': t, 'phone': null, 'user_id': null},
    ];
    final api = ApiClient(
      client: MockClient((request) async {
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
        if (path.endsWith('/settings')) return _json({'data': <dynamic>[]});
        if (path.endsWith('/project-board/options')) {
          return _json({
            'data': {
              'statuses': ['Pending'],
              'tags': <String>[],
            },
          });
        }
        if (path.endsWith('/project-board/repair-teams')) {
          if (request.method == 'GET') {
            return _json({
              'data': {
                'list': list(),
                'tag_colours': {'REPAIR TEAM: ISRAEL': '#BE2C2C'},
              },
            });
          }
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          posts.add(body);
          teams = [
            for (final t in teams)
              if (t != body['name']) t,
            if (body['op'] != 'delete') '${body['to'] ?? body['name']}',
          ]..sort();
          return _json({
            'data': {
              'outcomes': {
                'Ohio': body['op'] == 'delete' ? 'deleted' : 'renamed',
              },
              'list': list(),
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
            'columns': [
              {'key': 'customer', 'label': 'Client', 'visible': true},
            ],
            'labels': <String, dynamic>{},
            'settings_scope': {'scope_type': 'company', 'scope_id': 'c-1'},
            'can_edit': true,
          },
        });
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1400, 3000);
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
    await tester.tap(find.text('Settings').first);
    await tester.pumpAndSettle();

    // The teams already there are listed before anyone adds one.
    for (final t in ['CONTRACTOR: OMER', 'REPAIR TEAM: ISRAEL']) {
      expect(
        find.byKey(ValueKey('project-board-repair-team:$t')),
        findsOneWidget,
      );
      expect(
        find.byKey(ValueKey('project-board-repair-team-rename:$t')),
        findsOneWidget,
      );
      expect(
        find.byKey(ValueKey('project-board-repair-team-remove:$t')),
        findsOneWidget,
      );
    }

    // Rename: the side panel, then the same POST as Add.
    const israel = ValueKey(
      'project-board-repair-team-rename:REPAIR TEAM: ISRAEL',
    );
    await tester.ensureVisible(find.byKey(israel));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(israel));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('project-board-repair-team-name')),
      'REPAIR TEAM: ISRAEL R',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('project-board-repair-team-save')));
    await tester.pumpAndSettle();
    expect(posts.single, {
      'op': 'update',
      'name': 'REPAIR TEAM: ISRAEL',
      'to': 'REPAIR TEAM: ISRAEL R',
      'phone': null,
      'user_id': null,
    });
    expect(
      find.byKey(
        const ValueKey('project-board-repair-team:REPAIR TEAM: ISRAEL R'),
      ),
      findsOneWidget,
    );

    // Delete asks inside the card first: nothing is sent until confirmed.
    const omer = 'CONTRACTOR: OMER';
    await tester.tap(
      find.byKey(const ValueKey('project-board-repair-team-remove:$omer')),
    );
    await tester.pumpAndSettle();
    expect(
      find.text('Delete $omer from every Workiz account?'),
      findsOneWidget,
    );
    expect(find.byType(Dialog), findsNothing);
    expect(posts, hasLength(1));
    // Keep backs out.
    await tester.tap(
      find.byKey(const ValueKey('project-board-repair-team-keep:$omer')),
    );
    await tester.pumpAndSettle();
    expect(find.text('Delete $omer from every Workiz account?'), findsNothing);
    await tester.tap(
      find.byKey(const ValueKey('project-board-repair-team-remove:$omer')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('project-board-repair-team-confirm:$omer')),
    );
    await tester.pumpAndSettle();
    expect(posts.last, {'op': 'delete', 'name': omer});
    expect(
      find.byKey(const ValueKey('project-board-repair-team:$omer')),
      findsNothing,
    );
    expect(find.text('Ohio: deleted'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
