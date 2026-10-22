import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ai_assistance/whatsapp_groups_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// AI Assistance › WhatsApp groups: the search bar keeps the groups whose
/// name, report or members match. Every name invented.
void main() {
  Map<String, Object?> group(
    String id,
    String purpose,
    String subject,
    String member,
  ) => {
    'id': id,
    'purpose': purpose,
    'subject': subject,
    'on_whatsapp': false,
    'invite_link': null,
    'members': [
      {'id': 'u-$id', 'name': member, 'has_phone': true},
    ],
  };

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1300, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': [
              group('g-1', 'financial', 'Harbor Financial Reports', 'Ana Ruiz'),
              group('g-2', 'messages', 'Harbor Messages', 'Ben Okafor'),
            ],
            'purposes': [
              {'key': 'financial', 'label': 'Financial reports'},
              {'key': 'messages', 'label': 'Messages'},
            ],
            'people': <Object>[],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: WhatsAppGroupsTab(api: api)),
      ),
    );
    await tester.pumpAndSettle();
  }

  const field = Key('whatsapp-groups-search');
  const one = ValueKey('whatsapp-group-g-1');
  const two = ValueKey('whatsapp-group-g-2');

  testWidgets('typing keeps the matching group and hides the other', (
    tester,
  ) async {
    await pump(tester);
    expect(find.byKey(one), findsOneWidget);
    expect(find.byKey(two), findsOneWidget);

    await tester.enterText(find.byKey(field), 'financial');
    await tester.pumpAndSettle();

    expect(find.byKey(one), findsOneWidget);
    expect(find.byKey(two), findsNothing);
  });

  testWidgets('a member name finds the group', (tester) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'okafor');
    await tester.pumpAndSettle();

    expect(find.byKey(one), findsNothing);
    expect(find.byKey(two), findsOneWidget);
  });

  testWidgets('a term matching nothing says so in one sentence', (
    tester,
  ) async {
    await pump(tester);

    await tester.enterText(find.byKey(field), 'zebra');
    await tester.pumpAndSettle();

    expect(find.text('No groups match "zebra".'), findsOneWidget);
    expect(find.byKey(one), findsNothing);
    expect(find.byKey(two), findsNothing);
  });
}
