import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/widgets/scope_picker_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-10-15: "if a company is switched off, it should not appear on
/// the company picker". Nor its Sub Companies, nor its places.
void main() {
  testWidgets('switched-off companies, their subs and places are not offered', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient(
        (request) async => !request.url.path.endsWith('/me')
            ? http.Response('{}', 404)
            : http.Response(
                jsonEncode({
                  'data': {
                    'id': 'u',
                    'name': 'Dana',
                    'email': 'dana@brightside.example',
                    'organization': {'id': 'org', 'name': 'Brightside Group'},
                    'scope': {
                      'type': 'organization',
                      'id': null,
                      'label': 'Brightside Group',
                      'path': ['Brightside Group'],
                    },
                    'companies': [
                      {
                        'id': 'on',
                        'name': 'Brightside Home',
                        'parent_company_id': null,
                        'is_active': true,
                      },
                      {
                        'id': 'off',
                        'name': 'Old Sweep Co',
                        'parent_company_id': null,
                        'is_active': false,
                      },
                      {
                        'id': 'off-sub',
                        'name': 'Old Sweep East',
                        'parent_company_id': 'off',
                        'is_active': true,
                      },
                    ],
                    'franchises': [
                      {
                        'id': 'f1',
                        'name': 'Springfield',
                        'company_id': 'on',
                        'is_active': true,
                      },
                      {
                        'id': 'f2',
                        'name': 'Closed shop',
                        'company_id': 'on',
                        'is_active': false,
                      },
                      {
                        'id': 'f3',
                        'name': 'Old Sweep North',
                        'company_id': 'off',
                        'is_active': true,
                      },
                      {
                        'id': 'f4',
                        'name': 'Old Sweep East branch',
                        'company_id': 'off-sub',
                        'is_active': true,
                      },
                    ],
                  },
                }),
                200,
              ),
      ),
    );
    final session = Session(api);
    await session.adopt('token');
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ScopePickerPanel(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Brightside Home'), findsOneWidget);
    expect(find.text('Springfield'), findsOneWidget);
    for (final hidden in [
      'Old Sweep Co',
      'Old Sweep East',
      'Closed shop',
      'Old Sweep North',
      'Old Sweep East branch',
    ]) {
      expect(find.text(hidden), findsNothing, reason: hidden);
    }
    // Still in reach: Settings › Organization lists them to switch back on.
    expect(session.me!.companies, hasLength(3));
  });
}
