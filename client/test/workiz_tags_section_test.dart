import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/workiz_tags_section.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 191: the Workiz tags matrix, its drift and the change log.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  Future<List<String>> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1280, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final posted = <String>[];
    final now = DateTime.now().toUtc().toIso8601String();

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'POST') {
          posted.add(request.url.path);
          return json({
            'data': {'approved': 2},
          });
        }
        return json({
          'data': {
            'accounts': [
              {'id': 'a1', 'name': 'Chimney'},
              {'id': 'a2', 'name': 'Vents'},
            ],
            'baseline_set': true,
            'rows': [
              {
                'key': 'paid',
                'standard': {'name': 'Paid', 'color': '#2e9d5b'},
                'in_standard': true,
                'differs': true,
                'accounts': {
                  'a1': {'id': '1', 'name': 'Paid', 'color': '#2e9d5b'},
                  'a2': {'id': '2', 'name': 'Paid ✓', 'color': '#2e9d5b'},
                },
              },
              {
                'key': 'deposit',
                'standard': {'name': 'Deposit', 'color': '#2f6fd6'},
                'in_standard': true,
                'differs': true,
                'accounts': {
                  'a1': {'id': '3', 'name': 'Deposit', 'color': '#2f6fd6'},
                  'a2': null,
                },
              },
              {
                'key': 'estimated',
                'standard': {'name': 'Estimated', 'color': '#d68a00'},
                'in_standard': true,
                'differs': false,
                'accounts': {
                  'a1': {'id': '4', 'name': 'Estimated', 'color': '#d68a00'},
                  'a2': {'id': '5', 'name': 'Estimated', 'color': '#d68a00'},
                },
              },
            ],
            'changes': [
              {
                'account': 'Vents',
                'change': 'renamed',
                'summary': '"Paid" renamed to "Paid ✓"',
                'occurred_at': now,
              },
              {
                'account': 'Vents',
                'change': 'deleted',
                'summary': '"Deposit" deleted',
                'occurred_at': now,
              },
            ],
          },
        });
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ListView(children: [WorkizTagsSection(api: api)]),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return posted;
  }

  testWidgets('drift is flagged, warned about and filterable', (tester) async {
    await pump(tester);

    expect(find.text('2 tag changes in Workiz this week'), findsOneWidget);
    expect(
      find.byKey(const Key('workiz-tag-missing:deposit:a2')),
      findsOneWidget,
    );
    expect(find.text('Paid ✓'), findsOneWidget);
    expect(find.text('Deleted'), findsOneWidget);
    expect(find.text('Renamed'), findsOneWidget);

    // Estimated matches everywhere, so "only differences" hides it.
    expect(find.byKey(const Key('workiz-tag:estimated:a1')), findsOneWidget);
    await tester.tap(find.byKey(const Key('workiz-tags-only-differences')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('workiz-tag:estimated:a1')), findsNothing);
    expect(find.byKey(const Key('workiz-tag:paid:a2')), findsOneWidget);

    await tester.enterText(find.byKey(const Key('workiz-tags-search')), 'depo');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('workiz-tag:paid:a2')), findsNothing);
    expect(
      find.byKey(const Key('workiz-tag-missing:deposit:a2')),
      findsOneWidget,
    );
  });

  testWidgets('approve confirms in a side panel before it posts', (
    tester,
  ) async {
    final posted = await pump(tester);

    await tester.tap(find.byKey(const Key('workiz-tags-approve')));
    await tester.pumpAndSettle();
    expect(posted, isEmpty);

    await tester.tap(find.byKey(const Key('workiz-tags-approve-confirm')));
    await tester.pumpAndSettle();
    expect(posted.single, endsWith('/workiz/tags/approve'));
  });
}
