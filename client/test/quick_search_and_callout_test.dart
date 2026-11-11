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
    expect(find.text('Jobs'), findsOneWidget);
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await tester.pumpAndSettle();
    expect(opened, ['jobs:null']);
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
