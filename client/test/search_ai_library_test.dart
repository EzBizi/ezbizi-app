import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ai_assistance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// AI Assistance › Library: the search bar keeps the answers whose title,
/// section or audience matches, and drops the sections left empty.
void main() {
  Map<String, Object?> entry(String id, String section, String title) => {
    'id': id,
    'section': section,
    'title': title,
    'description': null,
    'body': 'Body of $title.',
    'audience': 'staff',
    'position': 0,
    'company_id': null,
    'franchise_id': null,
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1200, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': [
              entry('e-1', 'Finance options', 'Lenders'),
              entry('e-2', 'NFPA 211', 'Flue sizing'),
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LibraryTab(api: api, session: Session(api), canEdit: false),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  const field = Key('ai-library-search');

  testWidgets('typing keeps the matching answer and its section only', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text('Lenders'), findsOneWidget);
    expect(find.text('Flue sizing'), findsOneWidget);

    await tester.enterText(find.byKey(field), 'flue');
    await tester.pumpAndSettle();

    expect(find.text('Flue sizing'), findsOneWidget);
    expect(find.text('NFPA 211'), findsOneWidget);
    expect(find.text('Lenders'), findsNothing);
    expect(find.text('Finance options'), findsNothing);
  });

  testWidgets('a section name finds its answers', (tester) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'finance');
    await tester.pumpAndSettle();

    expect(find.text('Lenders'), findsOneWidget);
    expect(find.text('Flue sizing'), findsNothing);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'zebra');
    await tester.pumpAndSettle();

    expect(find.text('No answers match "zebra".'), findsOneWidget);
    expect(find.text('Lenders'), findsNothing);
    expect(find.text('Flue sizing'), findsNothing);
  });
}
