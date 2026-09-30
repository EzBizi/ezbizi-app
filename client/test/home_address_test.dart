import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/identity_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-09-30: "the Home Address field for users both on human
/// resources and your account should follow the same pattern of a full field
/// address that works as a lookup and then the Main Address - Unit - City -
/// State - Country - Zip". Your account's half; HR's is in hr_screen_test.
void main() {
  testWidgets('the lookup fills the parts, the country too, and they save', (
    tester,
  ) async {
    Map<String, dynamic>? saved;
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me') && request.method == 'PATCH') {
          saved = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'changed': true});
        }
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/me/profile')) {
          return _json({
            'first_name': 'Ana',
            'last_name': 'Ruiz',
            'phone': '+13055550100',
            'address': null,
            'address_line1': null,
            'address_line2': null,
            'city': null,
            'state': null,
            'postal_code': null,
            'country': null,
            'gender': 'Female',
            'date_of_birth': '1990-01-02',
            'has_avatar': true,
          });
        }
        if (path.endsWith('/address-lookup')) {
          return _json({
            'data': [
              {
                'label': '827 NW 115th St, Miami, FL 33168',
                'address_line1': '827 NW 115th St',
                'city': 'Miami',
                'state': 'FL',
                'postal_code': '33168',
                'country': 'United States',
              },
            ],
          });
        }
        return _json({'message': 'Nothing stubbed.'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(1200, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SingleChildScrollView(
            child: IdentityCard(api: api, session: session),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Country'), findsOneWidget);
    expect(find.text('Home address'), findsNothing, reason: 'one box no more');

    // The lookup is the first box of the address: type, then pick.
    final search = find.widgetWithText(TextField, 'Find an address');
    await tester.enterText(search, '827 NW 115th');
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();
    await tester.tap(find.text('827 NW 115th St, Miami, FL 33168').last);
    await tester.pumpAndSettle();

    String box(String label) => tester
        .widget<TextField>(find.widgetWithText(TextField, label))
        .controller!
        .text;
    expect(box('Street'), '827 NW 115th St');
    expect(box('City'), 'Miami');
    expect(box('Country'), 'United States');

    await tester.enterText(
      find.widgetWithText(TextField, 'Suite, unit, floor'),
      'Unit 4',
    );
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(saved?['address_line1'], '827 NW 115th St');
    expect(saved?['address_line2'], 'Unit 4');
    expect(saved?['city'], 'Miami');
    expect(saved?['state'], 'FL');
    expect(saved?['postal_code'], '33168');
    expect(saved?['country'], 'United States');
    expect(
      saved?.containsKey('address'),
      isFalse,
      reason: 'the server composes the one line from the parts',
    );
  });
}

const _me = {
  'id': 'u-1',
  'name': 'Ana Ruiz',
  'email': 'ana@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': true,
  'permissions': <String>[],
  'modules': <dynamic>[],
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
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
