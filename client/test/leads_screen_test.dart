import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:http/http.dart' as http;

/// Leads — enquiries from connected advertising.
///
/// The case worth a test is the empty one. "No leads" and "nothing connected"
/// draw the same empty list and mean opposite things: a quiet week, or an
/// enquiry sitting unanswered because nothing is fetching. A screen that says
/// the wrong one is the exact failure this module was built to stop, and it is
/// invisible in a screenshot.
void main() {
  testWidgets('with nothing connected the empty state says so, rather than '
      'reporting a quiet week', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    expect(find.text('No advertising account connected'), findsOneWidget);
    expect(
      find.textContaining('this is not a quiet week'),
      findsOneWidget,
      reason:
          'An empty list with no connection must not read as "no enquiries". '
          'It means nothing is fetching.',
    );
    expect(find.text('No enquiries yet'), findsNothing);
  });

  testWidgets('with an account connected and no leads it says the week was '
      'quiet, not that something is broken', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [
        {
          'provider': 'google_ads',
          'display_name': '123-456-7890',
          'status': 'connected',
        },
      ],
    );

    expect(find.text('No enquiries yet'), findsOneWidget);
    expect(find.text('No advertising account connected'), findsNothing);
  });

  testWidgets('a lead with no name is titled by the number somebody would '
      'call back', (tester) async {
    await _pump(
      tester,
      leads: const [
        {
          'id': 'lead-1',
          'contact_name': null,
          'phone': '+1 (555) 123-4567',
          'category': 'chimney_sweep',
          'status': 'new',
          'received_at': null,
        },
      ],
      connections: const [
        {
          'provider': 'google_ads',
          'display_name': '123-456-7890',
          'status': 'connected',
        },
      ],
    );

    expect(find.text('+1 (555) 123-4567'), findsWidgets);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> leads,
  required List<Map<String, dynamic>> connections,
}) async {
  tester.view.physicalSize = const Size(1200, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/leads')) {
        return http.Response(
          jsonEncode({
            'data': leads,
            'meta': {
              'total': leads.length,
              'per_page': 50,
              'current_page': 1,
              'last_page': 1,
              'connections': connections,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response('{}', 200, headers: {'content-type': 'application/json'});
    }),
  );

  final session = Session(api);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: LeadsScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}
