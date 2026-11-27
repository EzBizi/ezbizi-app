import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/development_screen.dart';
import 'package:ezbizi_client/screens/staff/releases_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('Development asks for the authenticator, not a fresh sign-in', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('Your control-plane session has expired'), findsNothing);
    expect(find.textContaining('Sign in again to renew it'), findsNothing);
    expect(find.text('Enter your authenticator code'), findsOneWidget);
    expect(find.text('Continue'), findsOneWidget);
    expect(find.text('Organizations'), findsNothing);
  });

  testWidgets('a valid code opens the control plane', (tester) async {
    await _pump(tester);

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Authenticator code'),
      '123456',
    );
    await tester.tap(find.text('Continue'));
    await tester.pumpAndSettle();

    expect(find.text('Enter your authenticator code'), findsNothing);
    expect(find.text('Health'), findsOneWidget);
    expect(find.text('Continue'), findsNothing);
    // Development opens on Releases (Jose 11-12: "have it be the default tab").
    expect(find.byType(ReleasesScreen), findsOneWidget);
  });
}

Future<void> _pump(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  String? staffToken;

  http.Response handle(http.Request request) {
    final path = request.url.path;

    if (path.contains('/staff/me')) {
      if (staffToken == null) {
        return http.Response(
          jsonEncode({'message': 'Unauthenticated.'}),
          401,
          headers: const {'content-type': 'application/json'},
        );
      }
      return http.Response(
        jsonEncode({
          'data': {
            'id': 's-1',
            'name': 'Jose',
            'email': 'jose@ezbizi.test',
            'tier': 'lead_developer',
            'may_enter_without_approval': true,
            'recovery_code_count': 0,
          },
        }),
        200,
        headers: const {'content-type': 'application/json'},
      );
    }

    if (path.endsWith('/me') && request.method == 'GET') {
      return http.Response(
        jsonEncode({
          'data': {
            ..._me,
            'permissions': <String>[],
          },
        }),
        200,
        headers: const {'content-type': 'application/json'},
      );
    }

    if (path.contains('/me/development/unlock') && request.method == 'POST') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      if (body['code'] == '123456') {
        staffToken = 'staff-tok';
        return http.Response(
          jsonEncode({
            'status': 'authenticated',
            'token': 'staff-tok',
            'staff': {
              'id': 's-1',
              'name': 'Jose',
              'email': 'jose@ezbizi.test',
              'tier': 'lead_developer',
            },
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }
      return http.Response(
        jsonEncode({'message': 'That code is not right. Try again.'}),
        401,
        headers: const {'content-type': 'application/json'},
      );
    }

    if (path.contains('/staff/organizations')) {
      return http.Response(
        jsonEncode({
          'data': [
            {
              'id': 'org-1',
              'name': 'Alpha',
              'is_active': true,
              'is_read_only': false,
              'is_demo': false,
              'counts': {
                'companies': 1,
                'franchises': 1,
                'users': 1,
              },
            },
          ],
        }),
        200,
        headers: const {'content-type': 'application/json'},
      );
    }

    return http.Response(
      jsonEncode({'message': 'Nothing stubbed for $path.'}),
      500,
      headers: const {'content-type': 'application/json'},
    );
  }

  final tenantApi = ApiClient(client: MockClient((r) async => handle(r)));
  final staffApi = ApiClient(client: MockClient((r) async => handle(r)));
  final session = Session(tenantApi);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: DevelopmentScreen(session: session, staffApi: staffApi),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@ezbizi.test',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Alpha',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Alpha',
    'path': ['Alpha'],
  },
  'companies': <Map<String, dynamic>>[],
  'franchises': <Map<String, dynamic>>[],
  'modules': <Map<String, dynamic>>[],
  'development': {
    'tier': 'lead_developer',
    'staff_name': 'Jose',
    'focused_at': 'https://dev.ezbizi.com',
  },
};
