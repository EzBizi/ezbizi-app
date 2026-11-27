import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/staff_accounts_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The staff accounts screen, and the one thing on it that has to be visible.
///
/// §4.2's audit exemption used to be the Lead Developer TIER, and this screen
/// said so in the create form — "is exempt from the ordinary audit trail" —
/// while the server keyed it on `tier === lead_developer`. It is now a flag on
/// one account, so both the sentence and the list had to change: an exemption
/// nobody can see on a screen is one nobody reviews, which is the whole reason
/// §4.2 asks for it to be deliberate rather than implied.
void main() {
  // Jose, 2026-11-11: "remove the option to not audit, but leave me Jose as not
  // audited, but also remove the tag." The exemption stays on the server; the
  // screen neither shows it nor offers to change it.
  testWidgets('no account carries a Not audited tag', (tester) async {
    await _pump(tester, _handler());

    expect(find.text('Owner'), findsOneWidget);
    expect(find.text('Not audited'), findsNothing);
  });

  testWidgets('the tier description no longer promises an exemption', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await tester.tap(find.text('Add staff'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Lead Developer').last);
    await tester.pumpAndSettle();

    expect(
      find.textContaining('NOT exempt from the audit trail'),
      findsOneWidget,
    );
    expect(
      find.textContaining('is exempt from the ordinary audit trail'),
      findsNothing,
    );
  });

  testWidgets('no row offers to grant or revoke the audit exemption', (
    tester,
  ) async {
    await _pump(tester, _handler());

    // Your own row (the exempt one) has nothing left to offer, so no menu.
    expect(find.byType(PopupMenuButton<String>), findsOneWidget);

    await tester.tap(find.byType(PopupMenuButton<String>).last);
    await tester.pumpAndSettle();

    expect(find.text('Re-enroll authenticator'), findsOneWidget);
    expect(find.text('Exempt from the audit trail'), findsNothing);
    expect(find.text('Put back in the audit trail'), findsNothing);
  });

  testWidgets('a Developer row offers promotion', (tester) async {
    await _pump(tester, _handler(secondTier: 'developer'));

    await tester.tap(find.byType(PopupMenuButton<String>).last);
    await tester.pumpAndSettle();

    expect(find.text('Make Lead Developer'), findsOneWidget);
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

Map<String, dynamic> _account({
  required String id,
  required String name,
  required String email,
  bool exempt = false,
  String tier = 'lead_developer',
}) => {
  'id': id,
  'name': name,
  'email': email,
  'tier': tier,
  'is_active': true,
  'audit_exempt': exempt,
  'totp_confirmed': true,
  'totp_pending': false,
  'recovery_code_count': 8,
  'last_login_at': null,
  'linked_users': <String>[],
  'created_at': '2026-08-01T00:00:00+00:00',
};

MockClientHandler _handler({
  void Function(Map<String, dynamic>)? onPatch,
  String? refuseWith,
  String secondTier = 'lead_developer',
}) {
  return (http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/staff/me')) {
      return _json({'data': _me});
    }

    if (path.endsWith('/staff/accounts') && request.method == 'GET') {
      return _json({
        'data': [
          _account(
            id: 'staff-1',
            name: 'Owner',
            email: 'owner@ezbizi.test',
            exempt: true,
          ),
          _account(
            id: 'staff-2',
            name: 'Second Lead',
            email: 'second@ezbizi.test',
            tier: secondTier,
          ),
        ],
      });
    }

    if (request.method == 'PATCH') {
      onPatch?.call(jsonDecode(request.body) as Map<String, dynamic>);

      if (refuseWith != null) {
        return _json({'message': refuseWith}, status: 422);
      }

      return _json({
        'data': _account(
          id: 'staff-1',
          name: 'Owner',
          email: 'owner@ezbizi.test',
        ),
      });
    }

    return _json({
      'message': 'unexpected ${request.method} $path',
    }, status: 404);
  };
}

http.Response _json(Map<String, dynamic> body, {int status = 200}) =>
    http.Response(
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
      home: Scaffold(body: StaffAccountsScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}
