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
  testWidgets('shows the sentence, today, Clock In and Sign out', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('Clock in to start your day.'), findsOneWidget);
    expect(find.textContaining('08:00'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Clock In'), findsOneWidget);
    expect(find.text('Sign out'), findsOneWidget);
  });

  /// UI queue 36, Jose's 1 + 4 (2026-09-30: "show the app logo, etc."):
  /// the app's mark, the date, the organization, and the shift written in the
  /// organization's own clock - it read a raw 08:00 - 17:00.
  testWidgets('the lock screen carries the app, the day and the shift in the '
      'organization clock', (tester) async {
    await _pump(
      tester,
      display: const {'date_format': 'M/D/YYYY', 'time_format': '12h'},
      // Spanish, so it cannot be the device's day in English.
      todayLabel: 'jueves 24 septiembre',
    );

    expect(find.byKey(const ValueKey('lock-app-icon')), findsOneWidget);
    // The organization's day, from the server, not the device's clock.
    expect(find.text('jueves 24 septiembre'), findsOneWidget);
    expect(find.byKey(EzBiziTheme.wordmarkKey), findsOneWidget);
    expect(find.text('Today 8:00 AM – 5:00 PM'), findsOneWidget);
    expect(find.text('Everdoors'), findsOneWidget);
    expect(find.byKey(const ValueKey('lock-card')), findsOneWidget);
  });

  testWidgets('on a phone Clock In sits at the foot', (tester) async {
    await _pump(tester, size: const Size(390, 844));

    expect(find.byKey(const ValueKey('lock-card')), findsNothing);
    // No label from the server: no day, rather than the device's.
    expect(find.byKey(const ValueKey('lock-day')), findsNothing);
    final button = tester.getRect(
      find.widgetWithText(FilledButton, 'Clock In'),
    );
    expect(button.bottom, greaterThan(844 * 0.7));
    expect(tester.takeException(), isNull);
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
  testWidgets('no skip button without may_skip', (tester) async {
    await _pump(tester);
    expect(find.text('Continue without clocking in'), findsNothing);
  });

  testWidgets('may_skip shows Continue without clocking in, which lets go', (
    tester,
  ) async {
    final posted = <String>[];
    final session = await _pump(
      tester,
      maySkip: true,
      onRequest: (r) async {
        if (r.url.path != '/api/v1/time-clock/skip-lock') return null;
        posted.add(r.method);
        return http.Response(
          jsonEncode({
            'data': {
              'time_clock': {
                'organization_enabled': true,
                'can_clock': true,
                'show_button': true,
                'open': null,
                'lock': false,
                'may_skip': true,
              },
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      },
    );

    expect(session.me!.timeClock.lock, isTrue);
    expect(find.widgetWithText(FilledButton, 'Clock In'), findsOneWidget);

    await tester.tap(
      find.widgetWithText(OutlinedButton, 'Continue without clocking in'),
    );
    await tester.pump();
    await tester.pump();

    expect(posted, ['POST']);
    expect(session.me!.timeClock.lock, isFalse);
    expect(session.me!.timeClock.open, isNull);
  });

  testWidgets('the lock screen polls and lets go when the server says so', (
    tester,
  ) async {
    // The shell's 60-second poll does not run here — the shell is not mounted.
    // Without one of its own the ONLY way out is clocking in from this window,
    // so a shift opened elsewhere, or the organization switching Lock screen
    // off, leaves the machine locked on an answer that is no longer true.
    final polls = <String>[];

    final session = await _pump(
      tester,
      onRequest: (r) async {
        if (r.url.path != '/api/v1/time-clock/me') return null;
        polls.add(r.url.path);

        return http.Response(
          jsonEncode({
            'data': {
              'time_clock': {
                'organization_enabled': true,
                'can_clock': true,
                'show_button': true,
                'open': {'id': 's-9', 'clocked_in_at': '2026-09-09T14:00:00Z'},
                'lock': false,
              },
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      },
    );

    expect(session.me!.timeClock.lock, isTrue);
    expect(polls, isEmpty);

    await tester.pump(const Duration(seconds: 61));
    await tester.pump();

    expect(polls, ['/api/v1/time-clock/me']);
    expect(session.me!.timeClock.lock, isFalse);
  });
}

Future<Session> _pump(
  WidgetTester tester, {
  Future<http.Response?> Function(http.Request)? onRequest,
  Map<String, Object?>? display,
  Size? size,
  String? todayLabel,
  bool maySkip = false,
}) async {
  if (size != null) {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
  }
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
              'display': ?display,
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
                'today_label': ?todayLabel,
                'may_skip': maySkip,
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
