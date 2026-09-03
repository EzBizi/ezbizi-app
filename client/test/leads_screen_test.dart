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

  /// §4: a control that is offered must be a control that works.
  ///
  /// This shipped drawing Connect for anybody who could open the tab. Somebody
  /// holding `leads.view` and not `leads.edit` pressed it and got a 403 — the
  /// server check was right, the screen simply never asked.
  testWidgets('Connect is not offered to somebody who cannot connect',
      (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view'],
    );

    expect(find.text('Connect Google Ads'), findsNothing);
    expect(
      find.textContaining('Somebody who can manage Leads'),
      findsOneWidget,
      reason:
          'An absent button with no explanation reads as a broken tab to the '
          'person least able to tell the difference.',
    );
  });

  testWidgets('Connect is offered to somebody who can connect', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view', 'leads.edit'],
    );

    expect(find.text('Connect Google Ads'), findsOneWidget);
  });

  // ── the two sources, since Thumbtack arrived ──────────────────

  testWidgets('each source gets its own tab, so one empty list cannot speak '
      'for the other', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    expect(find.text('Google Leads'), findsOneWidget);
    expect(find.text('Thumbtack Leads'), findsOneWidget);
  });

  testWidgets('an unconnected Thumbtack offers to connect and says what '
      'connecting is for', (tester) async {
    await _pump(tester, leads: const [], connections: const []);

    await tester.tap(find.text('Thumbtack Leads'));
    await tester.pumpAndSettle();

    expect(find.text('No Thumbtack account connected'), findsOneWidget);
    expect(find.text('Connect Thumbtack'), findsOneWidget);

    // Google's empty state must not bleed through the tab: its sentence is
    // about advertising and would be wrong here.
    expect(find.text('No advertising account connected'), findsNothing);
  });

  testWidgets('a connected Thumbtack says enquiries are not fetched yet, '
      'rather than showing an empty list', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [
        {
          'provider': 'thumbtack',
          'display_name': 'A-1 Chimney',
          'status': 'connected',
        },
      ],
    );

    await tester.tap(find.text('Thumbtack Leads'));
    await tester.pumpAndSettle();

    // The distinction the whole module exists for. An empty list here would
    // tell somebody their week was quiet while their Thumbtack enquiries are
    // arriving perfectly well in Thumbtack's own app.
    expect(find.text('Connected — enquiries not fetched yet'), findsOneWidget);
    expect(find.text('No enquiries yet'), findsNothing);
    expect(find.textContaining('not missing'), findsOneWidget);
  });

  testWidgets('without leads.edit the Thumbtack tab names who can connect '
      'instead of offering a button that would be refused', (tester) async {
    await _pump(
      tester,
      leads: const [],
      connections: const [],
      permissions: const ['leads.view'],
    );

    await tester.tap(find.text('Thumbtack Leads'));
    await tester.pumpAndSettle();

    expect(find.text('Connect Thumbtack'), findsNothing);
    expect(
      find.textContaining('Somebody who can manage Leads'),
      findsOneWidget,
    );
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> leads,
  required List<Map<String, dynamic>> connections,
  List<String> permissions = const ['leads.view', 'leads.edit'],
}) async {
  tester.view.physicalSize = const Size(1200, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return http.Response(
          jsonEncode({
            'data': {..._me, 'permissions': permissions},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

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
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: LeadsScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
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
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {'key': 'leads', 'label': 'Leads', 'available': true, 'reachable': true},
  ],
};
