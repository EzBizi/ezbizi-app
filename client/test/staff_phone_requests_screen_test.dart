import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/phone_requests_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Development › Phone requests — §4 gate 2 from EzBizi's side.
///
/// Approving is per company on purpose: a request naming several is often
/// answered "yes to that one, not the other", and one status on the whole
/// request would force a decline — which starts a week's cooling-off.
void main() {
  testWidgets('a request shows what was asked and who asked it', (tester) async {
    await _pump(tester, _handler());

    expect(find.text('A1 Group'), findsOneWidget);
    expect(find.text('Waiting'), findsOneWidget);
    expect(
      find.textContaining('100 to 500 calls a month'),
      findsOneWidget,
    );
    expect(find.textContaining('calls and texting'), findsOneWidget);
    expect(find.textContaining('Booking sweeps'), findsOneWidget);
  });

  testWidgets('approving every company sends them all and needs no note', (
    tester,
  ) async {
    Map<String, dynamic>? decided;

    await _pump(tester, _handler(onDecide: (body) => decided = body));

    await tester.tap(find.text('Answer this'));
    await tester.pumpAndSettle();

    // Everything is ticked to begin with — the common answer is yes, and the
    // work of the screen is noticing the exception.
    expect(find.text('Approve'), findsOneWidget);

    await tester.tap(find.text('Approve'));
    await tester.pumpAndSettle();

    expect(decided?['approved_company_ids'], ['co-chimney', 'co-ever']);
    expect(decided?['note'], isNull);
  });

  testWidgets('refusing one company requires a sentence the customer will read', (
    tester,
  ) async {
    Map<String, dynamic>? decided;

    await _pump(tester, _handler(onDecide: (body) => decided = body));

    await tester.tap(find.text('Answer this'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Everdoors'));
    await tester.pumpAndSettle();

    // The button says what it will do, and warns about the cooling-off.
    expect(find.text('Approve 1 of 2'), findsOneWidget);
    expect(
      find.textContaining('cannot be asked for again for a week'),
      findsOneWidget,
    );

    await tester.tap(find.text('Approve 1 of 2'));
    await tester.pumpAndSettle();

    // Refused with no reason — nothing was sent.
    expect(decided, isNull);
    expect(find.textContaining('Say why, in a sentence'), findsOneWidget);

    await tester.enterText(
      find.byType(TextField).last,
      'Everdoors first needs its own registered business address.',
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Approve 1 of 2'));
    await tester.pumpAndSettle();

    expect(decided?['approved_company_ids'], ['co-chimney']);
    expect(
      decided?['note'],
      'Everdoors first needs its own registered business address.',
    );
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

MockClientHandler _handler({void Function(Map<String, dynamic>)? onDecide}) {
  return (http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/staff/me')) {
      return _json({'data': _me});
    }

    if (path.endsWith('/staff/phone-requests') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'req-1',
            'organization': 'A1 Group',
            'organization_id': 'org-1',
            'status': 'pending',
            'expected_volume': 'from_100',
            'expected_volume_label': '100 to 500 calls a month',
            'wants_sms': true,
            'purpose': 'Booking sweeps and calling customers back.',
            'requested_by': 'Jose',
            'requested_at': '2026-08-27T10:00:00Z',
            'companies': [
              {
                'company_id': 'co-chimney',
                'name': 'A-1 Chimney',
                'approved': null,
              },
              {'company_id': 'co-ever', 'name': 'Everdoors', 'approved': null},
            ],
          },
        ],
      });
    }

    if (path.contains('/staff/phone-requests/') && request.method == 'POST') {
      onDecide?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'id': 'req-1', 'status': 'approved', 'companies': []}});
    }

    return _json({'data': <String, Object?>{}});
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final staff = StaffSession(api);

  await tester.runAsync(() => staff.adopt('tok'));

  expect(
    staff.signedIn,
    isTrue,
    reason: 'The /staff/me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: PhoneRequestsScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}
