import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/training/dashboard_list.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Training › Dashboard: `GET /training/dashboard` answers every row at once,
/// so the search filters on screen, over title and kind.
void main() {
  testWidgets('typing in the dashboard search narrows the rows', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('Ladder Safety'), findsOneWidget);
    expect(find.text('Chimney Basics'), findsOneWidget);
    expect(find.text('Roof Quiz'), findsOneWidget);

    final search = find.byKey(const Key('training-dashboard-list-search'));

    await tester.enterText(search, 'ladder');
    await tester.pump();
    expect(find.text('Ladder Safety'), findsOneWidget);
    expect(find.text('Chimney Basics'), findsNothing);
    expect(find.text('Roof Quiz'), findsNothing);

    // Kind is searchable too: "test" keeps only the test row.
    await tester.enterText(search, 'test');
    await tester.pump();
    expect(find.text('Roof Quiz'), findsOneWidget);
    expect(find.text('Ladder Safety'), findsNothing);
    expect(find.text('Chimney Basics'), findsNothing);

    await tester.enterText(search, 'zzz');
    await tester.pump();
    expect(find.text('No guide or test matches "zzz".'), findsOneWidget);
    expect(find.text('Ladder Safety'), findsNothing);

    await tester.enterText(search, '');
    await tester.pump();
    expect(find.text('Ladder Safety'), findsOneWidget);
    expect(find.text('Chimney Basics'), findsOneWidget);
    expect(find.text('Roof Quiz'), findsOneWidget);
  });

  testWidgets(
    'the search sits in the header at 40px, as tall as its neighbours',
    (tester) async {
      await _pump(tester);
      final size = tester.getSize(
        find.byKey(const Key('training-dashboard-list-search')),
      );
      expect(size.height, 40);
    },
  );
}

Future<void> _pump(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': const ['training.monitor'],
          },
        });
      }
      if (path.endsWith('/training/dashboard')) {
        return _json({
          'data': [
            _row('g-1', 'Ladder Safety', 'guide'),
            _row('g-2', 'Chimney Basics', 'guide'),
            _row('t-1', 'Roof Quiz', 'test'),
          ],
          'summary': {
            'people_assigned': 3,
            'people_overdue': 0,
            'people_due_for_retaking': 0,
          },
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
        body: TrainingDashboard(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _row(String id, String title, String kind) => {
  'id': id,
  'title': title,
  'kind': kind,
  'assigned': 1,
  'passed': 0,
  'in_progress': 0,
  'overdue': 0,
  'retaking': 0,
};

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
