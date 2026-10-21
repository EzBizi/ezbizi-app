import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/report_goals_settings.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-10-20: "reports still says Main Main Main Main". Two companies
/// with a branch called Main each must be told apart by their company.
void main() {
  testWidgets('each branch is named with its company', (tester) async {
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/me')) return _json({'data': _me});
        if (request.url.path.endsWith('/reports/goals')) {
          return _json({
            'data': {'keys': <dynamic>[], 'goals': <dynamic>[]},
          });
        }
        return _json({'message': 'not stubbed'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ReportGoalsSettings(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final picker = tester.widget<SearchPickerField<String?>>(
      find.byKey(const ValueKey('report-goals-branch')),
    );
    expect(picker.options.map((o) => o.label), [
      'Every branch',
      'Main · Brightside Garage Doors',
      'Main · Brightside Home Services',
    ]);
  });
}

const _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@brightside.example',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': <String>[],
  'modules': <dynamic>[],
  'companies': [
    {'id': 'co-h', 'name': 'Brightside Home Services'},
    {'id': 'co-g', 'name': 'Brightside Garage Doors'},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Main', 'company_id': 'co-h'},
    {'id': 'fr-2', 'name': 'Main', 'company_id': 'co-g'},
  ],
  'organization': {
    'id': 'org-1',
    'name': 'Brightside',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside',
    'path': ['Brightside'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
