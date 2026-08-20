import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Coming-soon sidebar tabs: granted, named, not blank.
void main() {
  testWidgets('Web Management names itself and is not a blank page', (
    tester,
  ) async {
    await _pumpDestination(tester, 'web_management', 'Web Management');

    expect(find.text('Coming soon'), findsOneWidget);
    expect(find.textContaining('public website'), findsOneWidget);
  });

  testWidgets('Phone names itself and is not a blank page', (tester) async {
    await _pumpDestination(tester, 'phone', 'Phone');

    expect(find.text('Coming soon'), findsOneWidget);
    expect(find.textContaining('calls'), findsOneWidget);
  });

  testWidgets('a granted Web Management tab is in the sidebar and opens', (
    tester,
  ) async {
    await _pumpShell(tester, 'web_management', 'Web Management');

    final inSidebar = find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.text('Web Management'),
    );

    expect(inSidebar, findsOneWidget);

    await tester.tap(inSidebar);
    await tester.pumpAndSettle();

    expect(find.byType(ComingSoonScreen), findsOneWidget);
    expect(find.text('Coming soon'), findsOneWidget);
  });

  testWidgets('a granted Phone tab is in the sidebar and opens', (
    tester,
  ) async {
    await _pumpShell(tester, 'phone', 'Phone');

    final inSidebar = find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.text('Phone'),
    );

    expect(inSidebar, findsOneWidget);

    await tester.tap(inSidebar);
    await tester.pumpAndSettle();

    expect(find.text('Coming soon'), findsOneWidget);
    expect(find.textContaining('without leaving EzBizi'), findsOneWidget);
  });

  testWidgets('Fleet Management names itself and is not a blank page', (
    tester,
  ) async {
    await _pumpDestination(tester, 'fleet_management', 'Fleet Management');

    expect(find.text('Coming soon'), findsOneWidget);
    expect(find.textContaining('vehicles'), findsOneWidget);
  });

  testWidgets('a granted Fleet Management tab is in the sidebar and opens', (
    tester,
  ) async {
    await _pumpShell(tester, 'fleet_management', 'Fleet Management');

    final inSidebar = find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.text('Fleet Management'),
    );

    expect(inSidebar, findsOneWidget);

    await tester.tap(inSidebar);
    await tester.pumpAndSettle();

    expect(find.text('Coming soon'), findsOneWidget);
    expect(find.textContaining('vehicles'), findsOneWidget);
  });

  testWidgets('Coming soon lays out on a 375-point window without overflowing', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(
          body: ComingSoonScreen(
            title: 'Phone',
            what: 'Calling is not here yet.',
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('Coming soon'), findsOneWidget);
    expect(tester.getRect(find.text('Phone')).right, lessThanOrEqualTo(375));
  });
}

Future<void> _pumpDestination(
  WidgetTester tester,
  String key,
  String label,
) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await _pumpShell(tester, key, label);

  final inSidebar = find.descendant(
    of: find.byType(NavigationSidebar),
    matching: find.text(label),
  );

  await tester.tap(inSidebar);
  await tester.pumpAndSettle();
}

Future<void> _pumpShell(
  WidgetTester tester,
  String key,
  String label,
) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient((request) async {
    if (request.url.path.endsWith('/me')) {
      return http.Response(
        jsonEncode({
          'data': _me(key, label),
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    return http.Response(
      jsonEncode({'message': 'Unexpected ${request.method} ${request.url.path}'}),
      500,
      headers: {'content-type': 'application/json'},
    );
  }));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> _me(String key, String label) => {
      'id': 'u-1',
      'name': 'Jose Silva',
      'email': 'jose@example.com',
      'has_avatar': false,
      'role': {'id': 'role-1', 'name': 'Administrator'},
      'permissions': ['$key.view'],
      'modules': [
        {
          'key': key,
          'label': label,
          'available': true,
          'reachable': true,
        },
      ],
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
      'companies': <Map<String, Object?>>[],
      'franchises': <Map<String, Object?>>[],
    };
