import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/porting_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-10-05: port in "showing pin codes and verification as well".
/// The form sends the current carrier's account and the PIN with the numbers.
void main() {
  testWidgets('a port-in sends the carrier account and the PIN', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final posted = <Map<String, dynamic>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/phone/ports') && request.method == 'POST') {
          posted.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': {}}, 201);
        }
        if (path.endsWith('/phone/connections')) {
          return _json({
            'data': [
              {'id': 'pc-1', 'label': 'Main account'},
            ],
          });
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: PortingPane(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('New port-in'));
    await tester.pumpAndSettle();

    Future<void> type(Finder field, String text) async {
      await tester.ensureVisible(field);
      await tester.enterText(field, text);
    }

    await type(find.widgetWithText(TextField, 'Numbers'), '+19544538774');
    await type(find.byKey(const ValueKey('port-customer-name')), 'A-1 Chimney');
    await type(find.byKey(const ValueKey('port-account-number')), '55501234');
    await type(find.byKey(const ValueKey('port-pin')), '4321');
    await type(
      find.byKey(const ValueKey('port-representative')),
      'Jose Demasi',
    );
    await type(
      find.byKey(const ValueKey('port-representative-email')),
      'jose@example.com',
    );
    await type(find.widgetWithText(TextField, 'Street'), '101 Spear St');
    await type(find.widgetWithText(TextField, 'City'), 'Miami');
    await type(find.widgetWithText(TextField, 'ST'), 'FL');
    await type(find.widgetWithText(TextField, 'ZIP'), '33101');
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.text('Submit port-in'));
    await tester.tap(find.text('Submit port-in'));
    await tester.pumpAndSettle();

    expect(posted, hasLength(1));
    expect(posted.single['pin'], '4321');
    final carrier = posted.single['losing_carrier'] as Map<String, dynamic>;
    expect(carrier['customer_name'], 'A-1 Chimney');
    expect(carrier['account_number'], '55501234');
    expect(carrier['authorized_representative_email'], 'jose@example.com');
    expect((carrier['address'] as Map)['zip'], '33101');
  });
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-9',
  'name': 'Adeline Ruiz',
  'email': 'adeline@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit', 'phone.settings.view'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
