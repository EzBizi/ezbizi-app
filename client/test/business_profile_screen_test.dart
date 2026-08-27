import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/business_profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Business Profile — the listings the public sees.
///
/// The case worth a test is the one nobody would notice going wrong: a listing
/// Google has stopped showing looks exactly like a healthy one in a list of
/// names. If it is not marked, the screen is lying by omission, and the way
/// anybody finds out is a customer saying they could not find the business.
void main() {
  testWidgets('a listing Google is not showing is marked, not just listed',
      (tester) async {
    await _pump(
      tester,
      locations: const [
        {
          'id': 'loc-1',
          'title': 'A-1 Chimney Hallandale',
          'address': '800 SE 4th Ave, Hallandale Beach, FL, 33009',
          'primary_category': 'Chimney sweep',
          'verification_state': 'VERIFIED',
          'is_live': true,
        },
        {
          'id': 'loc-2',
          'title': 'A-1 Chimney Aventura',
          'address': '1 Aventura Blvd',
          'primary_category': 'Chimney sweep',
          'verification_state': 'SUSPENDED',
          'is_live': false,
        },
      ],
      notLive: 1,
      connections: const [
        {'provider': 'google_business_profile', 'display_name': 'A-1', 'status': 'connected'},
      ],
    );

    // Google's own word, shown as-is — SUSPENDED and UNVERIFIED need different
    // fixes, so collapsing them to "problem" would help nobody.
    expect(find.textContaining('SUSPENDED'), findsOneWidget);
    expect(find.text('Not showing (1)'), findsOneWidget);
    expect(find.text('A-1 Chimney Hallandale'), findsOneWidget);
  });

  testWidgets('with nothing connected the empty state offers to connect',
      (tester) async {
    await _pump(tester, locations: const [], notLive: 0, connections: const []);

    expect(find.text('No Business Profile connected'), findsOneWidget);
    expect(find.text('Connect Business Profile'), findsOneWidget);
  });

  testWidgets('connected with every listing showing does not read as a fault',
      (tester) async {
    await _pump(
      tester,
      locations: const [],
      notLive: 0,
      connections: const [
        {'provider': 'google_business_profile', 'display_name': 'A-1', 'status': 'connected'},
      ],
    );

    expect(find.text('No Business Profile connected'), findsNothing);
    expect(find.text('No listings yet'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> locations,
  required int notLive,
  required List<Map<String, dynamic>> connections,
}) async {
  tester.view.physicalSize = const Size(1200, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.contains('/business-profile/locations')) {
        return http.Response(
          jsonEncode({
            'data': locations,
            'meta': {
              'total': locations.length,
              'not_live': notLive,
              'connections': connections,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response('{}', 200,
          headers: {'content-type': 'application/json'});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: BusinessProfileScreen(api: api, session: Session(api)),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
