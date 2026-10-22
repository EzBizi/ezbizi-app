import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/roles_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Roles & permissions: the search bar filters the role list by name.
///
/// Rows are found by their `role-row:` keys, not their names: the first role
/// is also open in the page beside the list, so its name is on screen twice.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> role(String id, String name) => {
    'id': id,
    'name': name,
    'is_administrator': false,
    'user_count': 1,
    'permissions': <String>[],
    'inboxes': <String>[],
    'may_edit': true,
    'may_delete': true,
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final roles = [role('r-2', 'Lead Manager'), role('r-3', 'Technician')];

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'u-1',
              'name': 'Jose Silva',
              'email': 'jose@example.com',
              'has_avatar': false,
              'permissions': ['roles.view', 'roles.create', 'roles.edit'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Everdoors',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/roles/catalogue')) {
          return json({'modules': <dynamic>[]});
        }
        return json({'data': roles});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: RolesScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('typing keeps the matching role and drops the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.byKey(const ValueKey('role-row:r-2')), findsOneWidget);
    expect(find.byKey(const ValueKey('role-row:r-3')), findsOneWidget);

    await tester.enterText(find.byKey(const Key('roles-list-search')), 'tech');
    await tester.pump();

    expect(find.byKey(const ValueKey('role-row:r-3')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('role-row:r-3')),
        matching: find.text('Technician'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('role-row:r-2')), findsNothing);
  });

  testWidgets('a term matching no role says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(const Key('roles-list-search')), 'zzz');
    await tester.pump();

    expect(find.text('No roles match "zzz".'), findsOneWidget);
    expect(find.byKey(const ValueKey('role-row:r-2')), findsNothing);
    expect(find.byKey(const ValueKey('role-row:r-3')), findsNothing);
  });
}
