import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/phone_requests_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// PhoneRequestsScreen's search bar (Jose, 2026-10-22: every list gets one).
void main() {
  testWidgets('typing narrows the rows to the ones that match', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, seen);

    expect(find.text('Bright Plumbing'), findsWidgets);
    expect(find.text('A1 Group'), findsWidgets);
    final before = seen.length;

    await tester.enterText(
      find.byKey(const Key('phone-requests-search')),
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
      find.text('A1 Group'),
      findsNothing,
      reason: 'A row that does not contain "plumb" is still listed.',
    );
  });

  testWidgets('a search that matches nothing says so in one sentence', (
    tester,
  ) async {
    await _pump(tester, <Uri>[]);

    await tester.enterText(
      find.byKey(const Key('phone-requests-search')),
      'zzqx',
    );
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    expect(find.text('No requests match "zzqx".'), findsOneWidget);
    expect(find.text('A1 Group'), findsNothing);
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
    'id': 'req-1',
    'organization': 'A1 Group',
    'status': 'pending',
    'expected_volume_label': '100 to 500 calls a month',
    'wants_sms': true,
    'purpose': 'Booking sweeps.',
    'requested_by': 'Jose',
    'companies': [
      {'company_id': 'co-1', 'name': 'A-1 Chimney', 'approved': null},
    ],
  },
  {
    'id': 'req-2',
    'organization': 'Bright Plumbing',
    'status': 'pending',
    'expected_volume_label': 'Under 100 calls a month',
    'wants_sms': false,
    'purpose': 'Calling customers back.',
    'requested_by': 'Sam',
    'companies': [
      {'company_id': 'co-2', 'name': 'Bright North', 'approved': null},
    ],
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
      if (path.endsWith('/staff/phone-requests') && request.method == 'GET') {
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
      home: Scaffold(body: PhoneRequestsScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
