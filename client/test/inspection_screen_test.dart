import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/inspection_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Inspection's three chips, and which ones a role actually sees.
///
/// The destinations themselves are Task 8 and Task 6. This file defends the
/// strip: the owner's order, honest empty states rather than blank panes, and
/// chips the person cannot use not being drawn.
void main() {
  testWidgets('the three chips are drawn in the owner’s order', (tester) async {
    await _pump(tester, [
      'inspection.view',
      'inspection.create',
      'inspection.edit',
    ]);

    expect(_chips(tester), [
      'New Inspection',
      'Generated Reports',
      'Builder',
    ]);

    expect(
      find.text(
        'Search for a client by name, or a job by its number, to start.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a role with only view sees Generated Reports, not the others', (
    tester,
  ) async {
    await _pump(tester, ['inspection.view']);

    expect(_chips(tester), ['Generated Reports']);
    expect(find.widgetWithText(ChoiceChip, 'New Inspection'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Builder'), findsNothing);
    expect(
      find.text('Finished inspections show here. None have been submitted yet.'),
      findsOneWidget,
    );
  });

  testWidgets('Builder is not drawn without edit', (tester) async {
    await _pump(tester, ['inspection.view', 'inspection.create']);

    expect(_chips(tester), ['New Inspection', 'Generated Reports']);
    expect(find.widgetWithText(ChoiceChip, 'Builder'), findsNothing);
  });
}

Future<void> _pump(WidgetTester tester, List<String> permissions) async {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return http.Response(
          '{"data":${_me(permissions)}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response('{"message":"not stubbed"}', 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: InspectionScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

List<String> _chips(WidgetTester tester) => tester
    .widgetList<ChoiceChip>(find.byType(ChoiceChip))
    .map((chip) => (chip.label as Text).data!)
    .toList();

String _me(List<String> permissions) {
  final quoted = permissions.map((p) => '"$p"').join(',');
  return '''
{
  "id": "u-1",
  "name": "Jose Silva",
  "email": "jose@example.com",
  "has_avatar": false,
  "role": {"id": "role-1", "name": "Technician"},
  "permissions": [$quoted],
  "modules": [
    {"key": "inspection", "label": "Inspection", "available": true, "reachable": true}
  ],
  "preferences": {},
  "organization": {
    "id": "org-1",
    "name": "Everdoors",
    "franchise_label": "Franchise",
    "is_read_only": false
  },
  "scope": {
    "type": "organization",
    "id": "org-1",
    "label": "Everdoors",
    "path": ["Everdoors"]
  },
  "companies": [],
  "franchises": [
    {"id": "fr-1", "name": "Texas", "company_id": "co-1"}
  ]
}
''';
}
