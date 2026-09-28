import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/reports_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Communication › Reports.
void main() {
  // A by-day report has no labels, and PHP sends an empty array as `[]`. Read
  // as a map, that threw after the answer arrived: the spinner never stopped
  // and the day rows never drew. It was the report the tab opens on.
  testWidgets('a report whose labels come back as [] still draws its rows', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/phone/reports/run')) {
          return _json({
            'data': {
              'totals': {'calls': 3, 'missed': 1},
              'rows': [
                {'bucket': '2026-09-27', 'calls': 3, 'missed': 1},
              ],
              'labels': <Object>[],
            },
          });
        }
        if (path.endsWith('/phone/reports')) {
          return _json({'data': <Object>[], 'meta': <String, Object>{}});
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportsTab(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('2026-09-27'), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
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
  'companies': [
    {'id': 'co-1', 'name': 'Connecticut', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
