import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Mobile review 2026-11-13: on a phone Project Management › Settings drew
/// each column as a drag handle, a "Shown" switch and "Show as coloured
/// label", with the name squeezed to nothing, so the rows could not be told
/// apart. Below 600 px the name takes its own line above the controls.
void main() {
  testWidgets('on a phone each column row shows its name', (tester) async {
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
          return _json({
            'data': {'outcomes': {}, 'list': <dynamic>[]},
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
              {'key': 'required', 'label': 'Required', 'visible': true},
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

    tester.view.physicalSize = const Size(390, 3000);
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
    // The tab strip scrolls on a phone: bring Settings into view first.
    await tester.ensureVisible(find.text('Settings').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Settings').first);
    await tester.pumpAndSettle();

    final row = find.byKey(const ValueKey('project-board-column:customer'));
    expect(row, findsOneWidget);
    final name = find.descendant(of: row, matching: find.byType(TextFormField));
    // Wide enough to read the name, and above the controls, not beside them.
    expect(tester.getSize(name).width, greaterThan(200));
    final shown = find.descendant(of: row, matching: find.text('Shown'));
    expect(
      tester.getRect(shown).top,
      greaterThanOrEqualTo(tester.getRect(name).bottom),
    );
    expect(find.text('Client'), findsWidgets);
    expect(tester.takeException(), isNull);
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
