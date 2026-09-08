import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/widgets_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management → Widgets.
///
/// The screen exists so nobody has to type colours into a WordPress shortcode.
/// What is worth asserting is that it shows what the server sent, that a site
/// following the primary SAYS so rather than presenting borrowed values as its
/// own, and that a viewer cannot change any of it.
void main() {
  Future<void> pump(
    WidgetTester tester, {
    required List<String> permissions,
    bool own = false,
    String accent = '#aa0000',
    String onAccent = '#ffffff',
  }) async {
    final client = MockClient((request) async {
      // Matched on the WHOLE path. ApiClient prefixes /api/v1, and a suffix
      // match once let a typo'd path fall through to a fixture that happened
      // to end the same way.
      final path = request.url.path;
      Map<String, Object?> body;

      if (path == '/api/v1/me') {
        body = {
          'data': {
            'id': 'u-1',
            'name': 'Jose Silva',
            'email': 'jose@example.com',
            'has_avatar': false,
            'role': {'id': 'role-1', 'name': 'Administrator'},
            'permissions': permissions,
            'modules': [
              {
                'key': 'web_management',
                'label': 'Web Management',
                'available': true,
                'reachable': true,
              },
            ],
            'preferences': <String, dynamic>{},
            'organization': {
              'id': 'org-1',
              'name': 'A-1 Chimney Group',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'scope': null,
          },
        };
      } else if (path == '/api/v1/web/sites') {
        body = {
          'data': [
            {
              'id': 'site-1',
              'host': 'a-1chimney.com',
              'label': null,
              'is_primary': true,
              'public_key': 'pk_x',
              'company': {'id': 'c1', 'name': 'A-1'},
              'franchise': null,
            },
          ],
        };
      } else if (path == '/api/v1/web/sites/site-1/widgets') {
        body = {
          'own': own,
          'config': {
            'chat': {
              'enabled': true,
              'title': 'Talk to A-1',
              'intro': 'We answer fast.',
              'button': 'Chat',
              'placeholder': 'Type here',
              'position': 'bottom-right',
            },
            'form': {
              'enabled': true,
              'button': 'Send it',
              'thanks': 'Got it.',
              'fields': [
                {'label': 'Name', 'type': 'text', 'required': true},
                {'label': 'What needs doing?', 'type': 'textarea'},
              ],
            },
            'style': {
              'accent': accent,
              'on_accent': onAccent,
              'text': '#111111',
              'background': '#ffffff',
              'font': 'inherit',
              'radius': 12,
            },
          },
          'inherited': own
              ? {
                  'style': {'accent': '#123456'},
                }
              : <String, Object?>{},
          'field_types': const ['text', 'textarea', 'email', 'tel', 'select'],
        };
      } else {
        return http.Response('{}', 404,
            headers: {'content-type': 'application/json'});
      }

      return http.Response(
        jsonEncode(body),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final api = ApiClient(client: client);
    final session = Session(api);

    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1280, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: WidgetsTab(api: api, session: session)),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('the widget settings come from the server, not the shortcode', (
    tester,
  ) async {
    await pump(tester, permissions: ['web_management.view', 'web_management.edit']);

    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsOneWidget);

    expect(find.widgetWithText(TextField, '#aa0000'), findsOneWidget);

    // The form's questions are editable rows, not a comma-separated string.
    expect(find.widgetWithText(TextField, 'What needs doing?'), findsOneWidget);
    expect(find.text('Add a question'), findsOneWidget);
  });

  /// A branch site showing the primary's colours as if somebody had typed them
  /// here would have people "changing" a value that was never theirs.
  testWidgets('a site following the primary says so', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      own: false,
    );

    expect(find.textContaining('Following the primary website'), findsOneWidget);
    expect(find.text('Follow the primary again'), findsNothing);
  });

  testWidgets('a site dressing itself can go back to following', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      own: true,
    );

    expect(find.textContaining('Following the primary website'), findsNothing);

    expect(find.text('Follow the primary again'), findsOneWidget);
  });

  /// The two colours exist to be legible against each other and nothing else
  /// checked that they were. It warns; it must never block, because the site
  /// belongs to the customer and their brand may genuinely be low contrast.
  testWidgets('an unreadable colour pair is called out, not refused', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      accent: '#ffff00',
      onAccent: '#ffffff',
    );

    // 1.07:1 — the number is in the sentence, so it says what is wrong.
    expect(find.textContaining('1.1:1'), findsOneWidget);

    // And Save is still there. A warning, not a gate.
    expect(find.text('Save'), findsOneWidget);
  });

  testWidgets('a legible pair says nothing at all', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      accent: '#0b5cad',
      onAccent: '#ffffff',
    );

    expect(find.textContaining(':1 against each other'), findsNothing);
  });

  testWidgets('view alone can look and cannot change', (tester) async {
    await pump(tester, permissions: ['web_management.view']);

    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsOneWidget);
    expect(find.text('Save'), findsNothing);
    expect(find.text('Add a question'), findsNothing);

    final field = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Talk to A-1'),
    );

    expect(field.enabled, isFalse);
  });
}
