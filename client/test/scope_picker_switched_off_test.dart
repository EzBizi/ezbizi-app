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
    final session = await _pump(tester, canView: true);

    expect(find.text('Brightside Group'), findsOneWidget);
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

  /// Jose, 2026-11-10: "switched off companies are switched off and thus
  /// should not show up anywhere on the app". Every picker reads the view.
  testWidgets('the view offers no switched-off company, sub or place', (
    tester,
  ) async {
    final me = (await _pump(tester, canView: true)).me!;

    expect(me.companiesInView.map((c) => c.id), ['on']);
    expect(me.franchisesInView.map((f) => f.id), ['f1']);
    // Names of records already filed there still resolve from reach.
    expect(me.companies.map((c) => c.id), containsAll(['off', 'off-sub']));
    expect(me.franchises, hasLength(4));
    // A record already on a switched-off place keeps it in its own picker.
    expect(me.franchisesKeeping(me.franchisesInView, 'f4').map((f) => f.id), [
      'f1',
      'f4',
    ]);
    expect(
      me.companiesKeeping(me.companiesInView, 'off-sub').map((c) => c.id),
      ['on', 'off-sub'],
    );
    expect(me.companiesKeeping(me.companiesInView, null), hasLength(1));
  });

  /// Jose, 2026-10-16: "people who are assigned to a-1chimney are seeing
  /// Triplex Group on the picker, they shouldn't, access is only downwards".
  testWidgets(
    'somebody assigned to a company is not offered the organization',
    (tester) async {
      await _pump(tester, canView: false);

      expect(find.text('Brightside Group'), findsNothing);
      expect(find.text('Brightside Home'), findsOneWidget);
    },
  );
}

Future<Session> _pump(WidgetTester tester, {required bool canView}) async {
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
                  'organization': {
                    'id': 'org',
                    'name': 'Brightside Group',
                    'can_view': canView,
                  },
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
  return session;
}
