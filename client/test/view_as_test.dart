import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// "View as" (design 205, Jose 2026-11-23): developers who are Administrators
/// see the app as a role or a person, with a strip that says so on every
/// screen. Every name invented.
void main() {
  late List<http.Request> calls;
  late bool viewing;

  Future<void> pump(
    WidgetTester tester, {
    required bool available,
    double width = 1400,
  }) async {
    calls = [];
    viewing = false;
    tester.view.physicalSize = Size(width, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((r) async {
        calls.add(r);
        final p = r.url.path;
        if (p.endsWith('/me')) return _json({'data': _me(available, viewing)});
        if (p.endsWith('/view-as/options')) {
          return _json({
            'data': {
              'roles': [
                {'id': 'r-tech', 'name': 'Technician'},
              ],
              'people': [
                {
                  'id': 'u-9',
                  'name': 'Nick Frias',
                  'email': 'nick@example.com',
                  'role_name': 'Technician',
                },
              ],
              'places': [
                {
                  'type': 'franchise',
                  'id': 'f-1',
                  'label': 'Akron',
                  'path': 'Everdoors › Akron',
                },
              ],
            },
          });
        }
        if (p.endsWith('/view-as') && r.method == 'POST') {
          viewing = true;
          return _json({'data': _me(available, true)});
        }
        if (p.endsWith('/view-as') && r.method == 'DELETE') {
          viewing = false;
          return _json({'data': _me(available, false)});
        }
        return _json({'message': 'Nothing stubbed.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: ListenableBuilder(
          listenable: session,
          builder: (_, _) => HomeShell(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('nobody without the grant sees the icon', (tester) async {
    await pump(tester, available: false);
    expect(find.byKey(const Key('shell-view-as')), findsNothing);
    expect(find.byKey(const ValueKey('viewing-as-strip')), findsNothing);
  });

  testWidgets('a role at a place: start, the strip says who, Exit ends it', (
    tester,
  ) async {
    await pump(tester, available: true);

    await tester.tap(find.byKey(const Key('shell-view-as')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('view-as-panel')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('view-as-role-r-tech')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('view-as-start')));
    await tester.pumpAndSettle();

    final post = calls.lastWhere(
      (r) => r.method == 'POST' && r.url.path.endsWith('/view-as'),
    );
    expect(jsonDecode(post.body), {
      'role_id': 'r-tech',
      'scope_type': 'franchise',
      'scope_id': 'f-1',
    });
    expect(find.byKey(const ValueKey('view-as-panel')), findsNothing);
    expect(find.byKey(const ValueKey('viewing-as-strip')), findsOneWidget);
    expect(find.textContaining('Technician at Akron'), findsOneWidget);
    // Writes are done as them (Roles & Permissions): the strip says so.
    expect(find.textContaining('changes you make are real'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('viewing-as-exit')));
    await tester.pumpAndSettle();
    expect(
      calls.where(
        (r) => r.method == 'DELETE' && r.url.path.endsWith('/view-as'),
      ),
      hasLength(1),
    );
    expect(find.byKey(const ValueKey('viewing-as-strip')), findsNothing);
  });

  testWidgets('a person; on a phone the strip fits with Exit', (tester) async {
    await pump(tester, available: true, width: 375);

    await tester.tap(find.byKey(const Key('shell-view-as')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Person'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('view-as-person-u-9')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('view-as-start')));
    await tester.pumpAndSettle();

    final post = calls.lastWhere(
      (r) => r.method == 'POST' && r.url.path.endsWith('/view-as'),
    );
    expect(jsonDecode(post.body), {'user_id': 'u-9'});
    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('viewing-as-exit')), findsOneWidget);
  });
}

Map<String, dynamic> _me(bool available, bool viewing) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': viewing ? 'Technician' : 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': [
    {
      'key': 'dashboard',
      'label': 'Dashboard',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'view_as_available': available && !viewing,
  'viewing_as': viewing
      ? {
          'kind': 'role',
          'label': 'Technician at Akron',
          'expires_at': '2026-11-23T20:00:00Z',
          'real': {'id': 'u-1', 'name': 'Jose Silva'},
        }
      : null,
  'time_clock': {'show_button': false, 'open': null},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
