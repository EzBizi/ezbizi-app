import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/lock_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The route that replaces the shell while `lock` is true (design 2026-09-08 §10.2).
void main() {
  testWidgets('shows the sentence, today, Clock In and Sign out', (tester) async {
    await _pump(tester);

    expect(find.text('Clock in to start your day.'), findsOneWidget);
    expect(find.textContaining('08:00'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Clock In'), findsOneWidget);
    expect(find.text('Sign out'), findsOneWidget);
  });

  testWidgets('a refused punch shows the server sentence', (tester) async {
    await _pump(
      tester,
      onRequest: (r) async => r.url.path == '/api/v1/time-clock/clock-in'
          ? http.Response(
              jsonEncode({
                'message': 'Clock in is switched off for this organization.',
              }),
              422,
              headers: {'content-type': 'application/json'},
            )
          : null,
    );

    await tester.tap(find.widgetWithText(FilledButton, 'Clock In'));
    await tester.pump();
    await tester.pump();

    expect(
      find.text('Clock in is switched off for this organization.'),
      findsOneWidget,
    );
  });

  testWidgets('a successful punch writes lock false onto the session', (
    tester,
  ) async {
    final session = await _pump(
      tester,
      onRequest: (r) async => r.url.path == '/api/v1/time-clock/clock-in'
          ? http.Response(
              jsonEncode({
                'data': {
                  'time_clock': {
                    'organization_enabled': true,
                    'can_clock': true,
                    'show_button': true,
                    'open': {
                      'id': 's-1',
                      'clocked_in_at': '2026-09-08T14:00:00Z',
                    },
                    'lock': false,
                  },
                },
              }),
              200,
              headers: {'content-type': 'application/json'},
            )
          : null,
    );

    expect(session.me!.timeClock.lock, isTrue);

    await tester.tap(find.widgetWithText(FilledButton, 'Clock In'));
    await tester.pump();
    await tester.pump();

    expect(session.me!.timeClock.lock, isFalse);
  });
}

Future<Session> _pump(
  WidgetTester tester, {
  Future<http.Response?> Function(http.Request)? onRequest,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      final handled = await onRequest?.call(request);
      if (handled != null) return handled;
      if (request.url.path == '/api/v1/me') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'u-1',
              'name': 'Jose Silva',
              'email': 'jose@example.com',
              'has_avatar': false,
              'role': {'id': 'r', 'name': 'Tech'},
              'permissions': ['time_clock.view', 'time_clock.lock_screen'],
              'modules': <Map<String, Object?>>[],
              'preferences': <String, dynamic>{},
              'organization': {
                'id': 'o',
                'name': 'Everdoors',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'scope': {
                'type': 'organization',
                'id': 'o',
                'label': 'Everdoors',
                'path': ['Everdoors'],
              },
              'companies': <Map<String, Object?>>[],
              'franchises': <Map<String, Object?>>[],
              'time_clock': {
                'organization_enabled': true,
                'can_clock': true,
                'show_button': true,
                'open': null,
                'lock': true,
                'today': {'start': '08:00', 'end': '17:00'},
              },
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response(
        '{"message":"unexpected"}',
        500,
        headers: {'content-type': 'application/json'},
      );
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => LockScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pump();
  return session;
}
