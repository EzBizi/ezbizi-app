import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The countdown before a scheduled shift ends (design 2026-09-08 §10.1).
void main() {
  testWidgets('the reminder appears at end minus warning, not a minute earlier', (
    tester,
  ) async {
    final end = DateTime.now().toUtc().add(const Duration(minutes: 10));

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
        scheduledEndAt: end.toIso8601String(),
        warningMinutes: 5,
        extensionMinutes: 30,
      ),
    );

    await tester.pump(const Duration(minutes: 4));
    expect(find.text('Your shift is ending'), findsNothing);

    await tester.pump(const Duration(minutes: 1, seconds: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsOneWidget);
    expect(find.text('Extend 30 min'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Clock Out'), findsOneWidget);
  });

  // client_issues, 2026-10-16: "Null check operator used on a null value" at
  // MaterialLocalizations.of ← TimeOfDay.format ← shift_reminder.dart, in
  // Scheduling and Work Hours on Windows. The dialog's builder formatted the
  // end time with the CAPTURED context of the screen that opened it; when
  // that screen went away and the dialog rebuilt, the lookup failed.
  testWidgets('the reminder survives the screen that opened it going away', (tester) async {
    final end = DateTime.now().toUtc().add(const Duration(minutes: 3));
    final showShell = ValueNotifier(true);
    final dark = ValueNotifier(false);

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
        scheduledEndAt: end.toIso8601String(),
        warningMinutes: 5,
      ),
      showShell: showShell,
      dark: dark,
    );
    await tester.pump(const Duration(seconds: 2));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsOneWidget);

    showShell.value = false; // the opening screen is replaced
    await tester.pump();
    dark.value = true; // and the dialog rebuilds
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text('Your shift is ending'), findsOneWidget);
  });

  testWidgets('Extend posts, applies the new end, and the dialog closes', (
    tester,
  ) async {
    final end = DateTime.now().toUtc().add(const Duration(minutes: 6));
    final posts = <String>[];

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
        scheduledEndAt: end.toIso8601String(),
      ),
      onRequest: (request) async {
        if (request.method == 'POST' &&
            request.url.path.startsWith('/api/v1/time-clock')) {
          posts.add(request.url.path);
          return _json({
            'data': {
              'time_clock': _clock(
                open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
                scheduledEndAt: end
                    .add(const Duration(minutes: 30))
                    .toIso8601String(),
              ),
            },
          });
        }
        return null;
      },
    );

    await tester.pump(const Duration(minutes: 1, seconds: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsOneWidget);

    await tester.tap(find.text('Extend 30 min'));
    await tester.pump();
    await tester.pump();

    expect(posts, ['/api/v1/time-clock/extend']);
    expect(find.text('Your shift is ending'), findsNothing);
  });

  testWidgets('the dialog shows once per end value', (tester) async {
    final end = DateTime.now().toUtc().add(const Duration(minutes: 6));

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
        scheduledEndAt: end.toIso8601String(),
      ),
      onRequest: (request) async {
        // The poll answers with the same end.
        if (request.url.path == '/api/v1/time-clock/me') {
          return _json({
            'data': {
              'time_clock': _clock(
                open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
                scheduledEndAt: end.toIso8601String(),
              ),
            },
          });
        }
        return null;
      },
    );

    await tester.pump(const Duration(minutes: 1, seconds: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsOneWidget);

    // The dialog's own Not now: the tab's walkthrough offer has one too.
    await tester.tap(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.widgetWithText(TextButton, 'Not now'),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(minutes: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsNothing);
  });

  testWidgets('no open shift means no timer and no dialog', (tester) async {
    await _pumpShell(
      tester,
      timeClock: _clock(
        scheduledEndAt: DateTime.now()
            .toUtc()
            .add(const Duration(minutes: 6))
            .toIso8601String(),
      ),
    );

    await tester.pump(const Duration(minutes: 2));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsNothing);
  });
}

Future<void> _pumpShell(
  WidgetTester tester, {
  Map<String, Object?>? timeClock,
  Future<http.Response?> Function(http.Request request)? onRequest,
  ValueNotifier<bool>? showShell,
  ValueNotifier<bool>? dark,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (onRequest != null) {
        final handled = await onRequest(request);
        if (handled != null) return handled;
      }

      if (request.url.path == '/api/v1/me') {
        return _json({'data': _me(timeClock: timeClock)});
      }

      // The minute poll, and anything else nobody stubbed: the same block
      // back, so a test that does not care about the poll is not failed by it.
      if (request.url.path == '/api/v1/time-clock/me') {
        return _json({
          'data': {'time_clock': timeClock},
        });
      }

      return http.Response(
        jsonEncode({
          'message': 'Unexpected ${request.method} ${request.url.path}',
        }),
        500,
        headers: {'content-type': 'application/json'},
      );
    }),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  final shown = showShell ?? ValueNotifier(true);
  final isDark = dark ?? ValueNotifier(false);
  await tester.pumpWidget(
    ListenableBuilder(
      listenable: isDark,
      builder: (_, _) => MaterialApp(
        theme: isDark.value ? EzBiziTheme.dark() : EzBiziTheme.light(),
        home: Builder(
          builder: (context) => MediaQuery(
            data: MediaQuery.of(context).copyWith(disableAnimations: true),
            child: ListenableBuilder(
              listenable: Listenable.merge([session, shown]),
              builder: (_, _) => shown.value
                  ? HomeShell(api: api, session: session)
                  : const SizedBox(),
            ),
          ),
        ),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _clock({
  bool showButton = true,
  bool organizationEnabled = true,
  bool canClock = true,
  Map<String, Object?>? open,
  String? scheduledEndAt,
  int warningMinutes = 5,
  int extensionMinutes = 30,
}) => {
  'organization_enabled': organizationEnabled,
  'can_clock': canClock,
  'show_button': showButton,
  'open': open,
  'scheduled_end_at': scheduledEndAt,
  'warning_minutes': warningMinutes,
  'extension_minutes': extensionMinutes,
};

Map<String, Object?> _me({Map<String, Object?>? timeClock}) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['time_clock.view'],
  'modules': [
    {
      'key': 'dashboard',
      'label': 'Dashboard',
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
  'time_clock': ?timeClock,
};
