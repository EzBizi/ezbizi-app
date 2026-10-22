import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/impersonations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// ImpersonationsScreen's search bar (Jose, 2026-10-22: every list gets one).
void main() {
  testWidgets('typing narrows the rows to the ones that match', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, seen);

    expect(find.text('Bright Plumbing'), findsWidgets);
    expect(find.text('Acme Chimney'), findsWidgets);
    final before = seen.length;

    await tester.enterText(
      find.byKey(const Key('impersonations-search')),
      'plumb',
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    expect(seen.length, greaterThan(before), reason: 'Typing never re-asked.');
    expect(
      seen.last.queryParameters['q'],
      'plumb',
      reason: 'The request after typing did not carry the search as q.',
    );

    expect(find.text('Bright Plumbing'), findsWidgets);
    expect(
      find.text('Acme Chimney'),
      findsNothing,
      reason: 'A row that does not contain "plumb" is still listed.',
    );
  });

  testWidgets('a search that matches nothing says so in one sentence', (
    tester,
  ) async {
    await _pump(tester, <Uri>[]);

    await tester.enterText(
      find.byKey(const Key('impersonations-search')),
      'zzqx',
    );
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    expect(find.text('No sessions match "zzqx".'), findsOneWidget);
    expect(find.text('Acme Chimney'), findsNothing);
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
    'id': 'imp-1',
    'organization': {'name': 'Acme Chimney'},
    'staff': {'id': 'staff-1', 'name': 'Owner', 'email': 'owner@ezbizi.test'},
    'is_active': false,
    'client_approved': false,
    'reason': 'Ticket 41: invoice totals',
    'started_at': '2026-10-01T10:00:00+00:00',
    'ended_at': '2026-10-01T10:20:00+00:00',
    'expires_at': null,
  },
  {
    'id': 'imp-2',
    'organization': {'name': 'Bright Plumbing'},
    'staff': {'id': 'staff-1', 'name': 'Owner', 'email': 'owner@ezbizi.test'},
    'is_active': false,
    'client_approved': true,
    'reason': 'Ticket 42: missing jobs',
    'started_at': '2026-10-02T10:00:00+00:00',
    'ended_at': '2026-10-02T10:20:00+00:00',
    'expires_at': null,
  },
];

Future<void> _pump(WidgetTester tester, List<Uri> seen) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((http.Request request) async {
      final path = request.url.path;
      if (path.endsWith('/staff/me')) return _json({'data': _me});
      if (path.endsWith('/staff/impersonations') && request.method == 'GET') {
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
      home: Scaffold(body: ImpersonationsScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
