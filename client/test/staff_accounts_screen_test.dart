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
  testWidgets('the account outside the audit trail is labelled as such', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('Owner'), findsOneWidget);
    expect(find.text('Second Lead'), findsOneWidget);

    // Exactly one, on the row that holds it — not on the other Lead Developer,
    // which is the distinction the whole fix is about.
    expect(find.text('Not audited'), findsOneWidget);

    final tagged = find.ancestor(
      of: find.text('Not audited'),
      matching: find.byType(Card),
    );

    expect(
      find.descendant(of: tagged, matching: find.text('Owner')),
      findsOneWidget,
      reason: 'The exemption was shown against the wrong account.',
    );
  });

  testWidgets('the tier description no longer promises an exemption', (
    tester,
  ) async {
    await _pump(tester, _handler());

    await tester.tap(find.text('Add staff'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Lead Developer').last);
    await tester.pumpAndSettle();

    // The tier grants reach and staff management. It does not take the account
    // out of the audit trail, and the form must not say it does.
    expect(
      find.textContaining('NOT exempt from the audit trail'),
      findsOneWidget,
    );
    expect(
      find.textContaining('is exempt from the ordinary audit trail'),
      findsNothing,
    );
  });

  testWidgets('granting and revoking it is one control, offered on your own row', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pump(tester, _handler(onPatch: sent.add));

    // The exempt account is the signed-in one, so this menu is on the "You" row
    // — deliberately, because the owner granting it to their own account is the
    // case it exists for.
    await tester.tap(find.byType(PopupMenuButton<String>).first);
    await tester.pumpAndSettle();

    expect(find.text('Put back in the audit trail'), findsOneWidget);

    await tester.tap(find.text('Put back in the audit trail'));
    await tester.pumpAndSettle();

    expect(sent, [
      {'audit_exempt': false},
    ]);
  });

  testWidgets('the servers refusal is shown rather than reworded', (
    tester,
  ) async {
    const refusal =
        'The audit exemption is held by owner@ezbizi.test, and it belongs to '
        'one account at a time. Revoke it there first.';

    await _pump(tester, _handler(refuseWith: refusal));

    // The second row: the other Lead Developer, who cannot also hold it.
    await tester.tap(find.byType(PopupMenuButton<String>).last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('Exempt from the audit trail'));
    await tester.pumpAndSettle();

    // The server's refusal names who holds it, which is the answer to "why
    // can't I?" — rewording it here would lose that.
    expect(find.text(refusal), findsOneWidget);
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
}) => {
  'id': id,
  'name': name,
  'email': email,
  'tier': 'lead_developer',
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
        'data': _account(id: 'staff-1', name: 'Owner', email: 'owner@ezbizi.test'),
      });
    }

    return _json({'message': 'unexpected ${request.method} $path'}, status: 404);
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
