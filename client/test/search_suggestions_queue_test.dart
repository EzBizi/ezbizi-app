import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/suggestions_queue_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// SuggestionsQueueScreen's search bar (Jose, 2026-10-22: every list gets one).
void main() {
  testWidgets('typing narrows the rows to the ones that match', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, seen);

    expect(_inRail('A-1 Chimney'), findsWidgets);
    expect(_inRail('Everdoors'), findsWidgets);
    final before = seen.length;

    await tester.enterText(
      find.byKey(const Key('suggestions-queue-search')),
      'chimney',
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    expect(seen.length, greaterThan(before), reason: 'Typing never re-asked.');
    expect(
      seen.last.queryParameters['q'],
      'chimney',
      reason: 'The request after typing did not carry the search as q.',
    );

    expect(_inRail('A-1 Chimney'), findsWidgets);
    expect(
      _inRail('Everdoors'),
      findsNothing,
      reason: 'A row that does not contain "chimney" is still listed.',
    );
  });

  testWidgets('a search that matches nothing says so in one sentence', (
    tester,
  ) async {
    await _pump(tester, <Uri>[]);

    await tester.enterText(
      find.byKey(const Key('suggestions-queue-search')),
      'zzqx',
    );
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    expect(find.text('No suggestions match "zzqx".'), findsOneWidget);
    expect(_inRail('Everdoors'), findsNothing);
  });
}

const _me = {
  'id': 'staff-1',
  'name': 'Owner',
  'email': 'owner@ezbizi.test',
  'tier': 'lead_developer',
  'may_enter_without_approval': true,
  'recovery_code_count': 8,
};

const List<Map<String, Object?>> _rows = [
  {
    'id': 'a',
    'organization': 'A-1 Chimney',
    'organization_id': 'org-a',
    'author': 'Jose',
    'body': 'Let the board remember its filter.',
    'status': 'new',
    'decision_note': null,
    'decided_at': null,
    'created_at': '2026-09-02T10:00:00+00:00',
  },
  {
    'id': 'b',
    'organization': 'Everdoors',
    'organization_id': 'org-b',
    'author': 'Sam',
    'body': 'Bigger buttons on the map.',
    'status': 'new',
    'decision_note': null,
    'decided_at': null,
    'created_at': '2026-09-03T10:00:00+00:00',
  },
];

/// The first suggestion opens in the detail pane on its own, so its text is
/// on screen twice; look in the rail only.
Finder _inRail(String text) => find.descendant(
  of: find.byKey(const Key('suggestions-rail')),
  matching: find.text(text),
);

Future<void> _pump(WidgetTester tester, List<Uri> seen) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((http.Request request) async {
      final path = request.url.path;
      if (path.endsWith('/staff/me')) return _json({'data': _me});
      if (path.endsWith('/staff/suggestions') && request.method == 'GET') {
        seen.add(request.url);
        return _json({'data': _rows});
      }
      return _json({'message': 'unexpected ${request.method} $path'}, 404);
    }),
  );
  final staff = StaffSession(api);

  await tester.runAsync(() => staff.adopt('tok'));
  expect(staff.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SuggestionsQueueScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
