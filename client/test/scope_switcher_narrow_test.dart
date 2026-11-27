import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/scope_switcher.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Phone review 2026-11-13, item 2: the view name was a fixed 300 px, so in
/// a phone's top bar it ran under the Clock Out chip. It ellipsises in the
/// room it is given.
void main() {
  testWidgets('a long view name fits the room it is given', (tester) async {
    final api = ApiClient(
      client: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'data': {
              'id': 'u-1',
              'name': 'Pat',
              'email': 'pat@example.com',
              'role': {'name': 'Administrator'},
              'has_avatar': false,
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'org-1',
                'name': 'Northwind',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'scope': {
                'type': 'organization',
                'id': 'org-1',
                'label': 'Northwind Garage Doors and Chimney Services',
                'path': ['Northwind Garage Doors and Chimney Services'],
              },
              'companies': [
                {'id': 'co-1', 'name': 'Northwind', 'parent_company_id': null},
                {'id': 'co-2', 'name': 'Southwind', 'parent_company_id': null},
              ],
            },
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    expect(ScopeSwitcher.hasChoices(session.me!), isTrue);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Center(
            child: SizedBox(
              width: 140,
              child: Row(
                children: [
                  Flexible(
                    child: ScopeSwitcher(api: api, session: session),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pump();
    expect(tester.takeException(), isNull);
    expect(
      tester.getSize(find.byType(ScopeSwitcher)).width,
      lessThanOrEqualTo(140),
    );
  });
}
