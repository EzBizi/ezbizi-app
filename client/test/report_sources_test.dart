import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/reports/scoring_report.dart';
import 'package:ezbizi_client/widgets/open_destination.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Sources across Reports (UI-UX review of d1a6760d1): Scoring reads the
/// same filtered accounts, so it carries the same strip and panel, and the
/// panel's "Manage connections" really goes to Settings › Integrations.
void main() {
  final accounts = [
    {
      'id': 'w1',
      'name': 'NJ',
      'place': 'A-1 Chimney · New Jersey',
      'included': true,
    },
    {
      'id': 'w2',
      'name': 'CT',
      'place': 'Everdoors · Connecticut',
      'included': false,
    },
  ];

  Future<List<String>> pump(WidgetTester tester) async {
    final opened = <String>[];
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {'people': [], 'workiz_accounts': accounts},
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: OpenDestination(
          open: opened.add,
          child: Scaffold(
            body: ScoringReport(
              api: api,
              formats: DisplayFormats.fallback,
              now: () => DateTime(2026, 10, 30),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return opened;
  }

  testWidgets(
    'Scoring says which accounts are left out, and Change opens Sources',
    (tester) async {
      await pump(tester);

      expect(find.byKey(const Key('call-report-left-out')), findsOneWidget);
      expect(find.text('1 Workiz account left out'), findsOneWidget);

      await tester.tap(find.text('Change'));
      await tester.pumpAndSettle();
      expect(
        find.widgetWithText(CheckboxListTile, 'Everdoors · Connecticut'),
        findsOneWidget,
      );
    },
  );

  testWidgets('Manage connections opens Settings on Integrations', (
    tester,
  ) async {
    final opened = await pump(tester);
    await tester.tap(find.text('Change'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('sources-manage')));
    await tester.pumpAndSettle();

    expect(opened, ['settings']);
    final link = DeepLinks.take('settings');
    expect(link?.type, 'tab');
    expect(link?.id, 'integrations');
  });
}
