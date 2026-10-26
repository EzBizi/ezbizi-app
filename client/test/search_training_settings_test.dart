import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training/settings_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Training › Settings: one search field over both lists (they sit under one
/// header), filtered on screen and sent as q to both index endpoints.
void main() {
  testWidgets('the settings search filters guides and tests and sends q', (
    tester,
  ) async {
    final seen = <Uri>[];
    await _pump(tester, seen);

    expect(find.text('Ladder Safety'), findsOneWidget);
    expect(find.text('Chimney Basics'), findsOneWidget);
    expect(find.text('Ladder Quiz'), findsOneWidget);
    expect(find.text('Roof Quiz'), findsOneWidget);

    final requestsBefore = seen.length;
    await tester.enterText(
      find.byKey(const Key('training-settings-search')),
      'ladder',
    );
    await tester.pump();

    expect(find.text('Ladder Safety'), findsOneWidget);
    expect(find.text('Chimney Basics'), findsNothing);
    expect(find.text('Ladder Quiz'), findsOneWidget);
    expect(find.text('Roof Quiz'), findsNothing);

    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    final later = seen.skip(requestsBefore).toList();
    final guides = later
        .where((u) => u.path.endsWith('/training/guides'))
        .toList();
    final tests = later
        .where((u) => u.path.endsWith('/training/tests'))
        .toList();
    expect(guides, isNotEmpty);
    expect(tests, isNotEmpty);
    expect(guides.last.queryParameters['q'], 'ladder');
    expect(tests.last.queryParameters['q'], 'ladder');

    // Matches a guide but no test: the tests section says so in one sentence.
    await tester.enterText(
      find.byKey(const Key('training-settings-search')),
      'chimney',
    );
    await tester.pump();
    expect(find.text('Chimney Basics'), findsOneWidget);
    expect(find.text('No tests match "chimney".'), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('training-settings-search')),
      'zzz',
    );
    await tester.pump();
    expect(find.text('No guides match "zzz".'), findsOneWidget);
    expect(find.text('No tests match "zzz".'), findsOneWidget);
    expect(find.text('Ladder Safety'), findsNothing);
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();
  });
}

Map<String, dynamic> _row(String id, String title, String countKey) => {
  'id': id,
  'title': title,
  'status': 'draft',
  countKey: 1,
};

Future<void> _pump(WidgetTester tester, List<Uri> seen) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      seen.add(request.url);
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': ['training.view', 'training.manage'],
          },
        });
      }
      // The server ignores q today; the client filter is what the test sees.
      if (path.endsWith('/training/guides')) {
        return _json({
          'data': [
            _row('g-1', 'Ladder Safety', 'screen_count'),
            _row('g-2', 'Chimney Basics', 'screen_count'),
          ],
          'meta': {'total': 2},
        });
      }
      if (path.endsWith('/training/tests')) {
        return _json({
          'data': [
            _row('t-1', 'Ladder Quiz', 'question_count'),
            _row('t-2', 'Roof Quiz', 'question_count'),
          ],
          'meta': {'total': 2},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TrainingSettingsTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
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
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {
      'key': 'training',
      'label': 'Training',
      'available': true,
      'reachable': true,
    },
  ],
};
