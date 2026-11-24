import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/hr.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/hr/invite_links_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Invite links polish (desk, 2026-11-23). Every name invented.
void main() {
  tearDown(() => DeepLinks.pending.value = null);

  testWidgets('who joined opens their HR record; dates are formatted', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    const h = {'content-type': 'application/json'};
    final api = ApiClient(
      client: MockClient(
        (r) async => http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'l1',
                'url': 'https://example.test/join?link=abc',
                'open': true,
                'uses': 1,
                'max_uses': 5,
                'expires_at': '2026-12-07T12:00:00Z',
                'revoked_at': null,
                'role': {'name': 'Technician'},
                'scope_type': 'franchise',
                'scope_id': 'f1',
                'created_by': 'Dana Reyes',
                'joined': [
                  {
                    'user_id': 'u-9',
                    'name': 'Omar Field',
                    'joined_at': '2026-11-24T15:00:00Z',
                  },
                ],
              },
            ],
          }),
          200,
          headers: h,
        ),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: InviteLinksPanel(api: api, options: AssignmentOptions.none),
        ),
      ),
    );
    await tester.pumpAndSettle();

    String day(String iso) =>
        DisplayFormats.fallback.formatDate(DateTime.parse(iso).toLocal());
    expect(
      find.textContaining('expires ${day('2026-12-07T12:00:00Z')}'),
      findsOneWidget,
    );
    expect(find.text('joined ${day('2026-11-24T15:00:00Z')}'), findsOneWidget);
    expect(find.textContaining('Used 1 of 5'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('invite-link-joined-u-9')));
    await tester.pumpAndSettle();

    final link = DeepLinks.pending.value;
    expect(link?.module, 'hr');
    expect(link?.type, 'person');
    expect(link?.id, 'u-9');
  });
}
