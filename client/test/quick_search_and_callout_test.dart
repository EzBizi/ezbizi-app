import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/widgets/callout.dart';
import 'package:ezbizi_client/widgets/quick_search.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('quick search finds a screen and a record; Enter opens', (
    tester,
  ) async {
    final opened = <String>[];
    final api = ApiClient(
      client: MockClient((r) async {
        final rows = r.url.path.endsWith('/jobs')
            ? [
                {'id': 'j1', 'title': 'Chimney sweep'},
              ]
            : <Map>[];
        return http.Response(jsonEncode({'data': rows}), 200);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: QuickSearch(
            api: api,
            screens: const [
              (
                module: 'jobs',
                record: null,
                label: 'Jobs',
                detail: 'Screen',
                icon: Icons.work_outline,
              ),
              (
                module: 'hr',
                record: null,
                label: 'Human Resources',
                detail: 'Screen',
                icon: Icons.badge_outlined,
              ),
            ],
            onOpen: (m, r) => opened.add('$m:$r'),
          ),
        ),
      ),
    );

    await tester.enterText(find.byKey(const Key('quick-search-field')), 'ch');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.text('Chimney sweep'), findsOneWidget);
    expect(find.text('Human Resources'), findsNothing);

    await tester.enterText(find.byKey(const Key('quick-search-field')), 'jo');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(ListTile, 'Jobs'), findsOneWidget);
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await tester.pumpAndSettle();
    expect(opened, ['jobs:null']);
  });

  // Jose's approval via the desk, 2026-11-18: the magnifier finds LEADS, by
  // name or by a phone typed any way, however old (Kelly Flynn, from 10-16,
  // never booked). A lead carries no `name`, so it drew as "#" and its id.
  testWidgets('a lead is found by its phone and reads as its person, under '
      'Leads', (tester) async {
    final asked = <String>[];
    final opened = <String>[];
    final api = ApiClient(
      client: MockClient((r) async {
        asked.add('${r.url.path}?${r.url.query}');
        final rows = r.url.path.endsWith('/leads')
            ? [
                {
                  'id': 'l-1',
                  'contact_name': 'Kelly Flynn',
                  'phone': '+12022538040',
                  'franchise': {'id': 'f-1', 'name': 'New Jersey'},
                  'received_at': '2026-10-16T19:16:28+00:00',
                },
              ]
            : r.url.path.endsWith('/customers')
            ? [
                {'id': 'c-1', 'name': 'Kelly Flynn', 'number': 'C-45759'},
              ]
            : <Map>[];
        return http.Response(jsonEncode({'data': rows}), 200);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: QuickSearch(
            api: api,
            screens: const [
              (
                module: 'customers',
                record: null,
                label: 'Customers',
                detail: 'Screen',
                icon: Icons.people_outline,
              ),
              (
                module: 'leads',
                record: null,
                label: 'Leads',
                detail: 'Screen',
                icon: Icons.inbox_outlined,
              ),
            ],
            onOpen: (m, r) => opened.add('$m:$r'),
          ),
        ),
      ),
    );

    await tester.enterText(
      find.byKey(const Key('quick-search-field')),
      '+1 202-253-8040',
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    expect(
      asked,
      contains('/api/v1/leads?search=%2B1+202-253-8040&per_page=5'),
      reason: 'the typed phone goes as typed; the server matches digits',
    );
    expect(
      find.byKey(const Key('quick-search-section-Customers')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('quick-search-section-Leads')), findsOneWidget);
    expect(find.textContaining('#'), findsNothing);
    expect(
      find.text('Lead · +1 (202) 253-8040 · New Jersey · Oct 16, 2026'),
      findsOneWidget,
    );
    // Customers, then Leads, whichever answered first.
    expect(
      tester
          .getTopLeft(find.byKey(const Key('quick-search-section-Customers')))
          .dy,
      lessThan(
        tester
            .getTopLeft(find.byKey(const Key('quick-search-section-Leads')))
            .dy,
      ),
    );

    await tester.tap(
      find.text('Lead · +1 (202) 253-8040 · New Jersey · Oct 16, 2026'),
    );
    await tester.pumpAndSettle();
    expect(opened, ['leads:l-1']);
  });

  testWidgets('a callout shows until Got it, then never again', (tester) async {
    final written = <String>[];
    Callouts.seen.value = const {};
    Callouts.ready = true;
    Callouts.onDismiss = written.add;
    addTearDown(() {
      Callouts.ready = false;
      Callouts.onDismiss = null;
    });

    Widget app() => const MaterialApp(
      home: Scaffold(
        body: Callout(id: 'tip', text: 'New: thing', child: Text('Filter')),
      ),
    );
    await tester.pumpWidget(app());
    await tester.pump();
    expect(find.text('New: thing'), findsOneWidget);

    await tester.tap(find.byKey(const Key('callout-tip-got-it')));
    await tester.pump();
    expect(find.text('New: thing'), findsNothing);
    expect(written, ['tip']);

    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(app());
    await tester.pump();
    expect(find.text('New: thing'), findsNothing);
  });
}
