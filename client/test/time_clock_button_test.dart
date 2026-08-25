import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Clock in / Clock out in the top bar (spec §8), not a sidebar tab.
void main() {
  testWidgets('the button is absent when show_button is false', (tester) async {
    await _pumpShell(
      tester,
      timeClock: _clock(showButton: false, organizationEnabled: false),
    );

    expect(_inAppBar('Clock In'), findsNothing);
    expect(_inAppBar('Clock Out'), findsNothing);
  });

  testWidgets('an old server with no time_clock key has no button', (
    tester,
  ) async {
    await _pumpShell(tester);

    expect(_inAppBar('Clock In'), findsNothing);
    expect(_inAppBar('Clock Out'), findsNothing);
  });

  testWidgets('Clock in sits in the AppBar before the theme control', (
    tester,
  ) async {
    await _pumpShell(tester, timeClock: _clock());

    expect(_inAppBar('Clock In'), findsOneWidget);
    expect(_inAppBar('Clock Out'), findsNothing);

    final clockX = tester.getTopLeft(_inAppBar('Clock In')).dx;
    final themeX = tester
        .getTopLeft(find.byTooltip('Matching your device — switch to light'))
        .dx;

    expect(
      clockX,
      lessThan(themeX),
      reason: 'The punch control is immediately before the theme IconButton.',
    );
  });

  testWidgets('an open shift labels the button Clock out', (tester) async {
    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-08-25T12:00:00Z'},
      ),
    );

    expect(_inAppBar('Clock Out'), findsOneWidget);
    expect(_inAppBar('Clock In'), findsNothing);
  });

  testWidgets('Clock In is the named green outline; Clock Out is the named coral', (
    tester,
  ) async {
    await _pumpShell(tester, timeClock: _clock());

    expect(
      find.descendant(of: find.byType(AppBar), matching: find.byIcon(Icons.login)),
      findsOneWidget,
    );
    expect(_appBarButtonColor(tester), const Color(0xFF0B8F5B));
    expect(_appBarButtonColor(tester), isNot(const Color(0xFFE07070)));

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-08-25T12:00:00Z'},
      ),
    );

    expect(
      find.descendant(of: find.byType(AppBar), matching: find.byIcon(Icons.logout)),
      findsOneWidget,
    );
    expect(_appBarButtonColor(tester), const Color(0xFFE07070));
  });

  testWidgets(
    'one tap posts clock-in, a second tap in flight is ignored, and the '
    'response flips the label without another /me',
    (tester) async {
      final release = Completer<http.Response>();
      var meCalls = 0;
      final posts = <String>[];

      await _pumpShell(
        tester,
        timeClock: _clock(),
        onRequest: (request) async {
          if (request.url.path.endsWith('/me')) {
            meCalls++;
            if (meCalls > 1) {
              fail('A successful punch must not refetch /me.');
            }
            return null;
          }

          if (request.method == 'POST' &&
              request.url.path.endsWith('/time-clock/clock-in')) {
            posts.add(request.url.path);
            return release.future;
          }

          return http.Response(
            jsonEncode({'message': 'Unexpected ${request.method} ${request.url.path}'}),
            500,
            headers: {'content-type': 'application/json'},
          );
        },
      );

      await tester.tap(_inAppBar('Clock In'));
      await tester.pump();

      expect(posts, ['/api/v1/time-clock/clock-in']);

      await tester.tap(_inAppBar('Clock In'));
      await tester.pump();

      expect(
        posts,
        ['/api/v1/time-clock/clock-in'],
        reason: 'In flight the button is disabled; a second tap must not post.',
      );

      release.complete(
        _json({
          'data': {
            'time_clock': _clock(
              open: {'id': 's-1', 'clocked_in_at': '2026-08-25T12:00:00Z'},
            ),
          },
        }),
      );
      await tester.pumpAndSettle();

      expect(_inAppBar('Clock Out'), findsOneWidget);
      expect(_inAppBar('Clock In'), findsNothing);
      expect(meCalls, 1);
    },
  );

  testWidgets('Clock out posts clock-out and the response restores Clock in', (
    tester,
  ) async {
    final posts = <String>[];

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-08-25T12:00:00Z'},
      ),
      onRequest: (request) async {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/time-clock/clock-out')) {
          posts.add(request.url.path);
          return _json({
            'data': {'time_clock': _clock()},
          });
        }
        return null;
      },
    );

    await tester.tap(_inAppBar('Clock Out'));
    await tester.pumpAndSettle();

    expect(posts, ['/api/v1/time-clock/clock-out']);
    expect(_inAppBar('Clock In'), findsOneWidget);
    expect(_inAppBar('Clock Out'), findsNothing);
  });

  testWidgets(
    'a refusal shows the server sentence under the AppBar, not a popup',
    (tester) async {
      await _pumpShell(
        tester,
        timeClock: _clock(),
        onRequest: (request) async {
          if (request.method == 'POST' &&
              request.url.path.endsWith('/time-clock/clock-in')) {
            return _json({
              'message': 'The given data was invalid.',
              'errors': {
                'time_clock': ['You are already clocked in.'],
              },
            }, 422);
          }
          return null;
        },
      );

      await tester.tap(_inAppBar('Clock In'));
      await tester.pumpAndSettle();

      expect(find.text('You are already clocked in.'), findsOneWidget);
      expect(find.byType(SnackBar), findsNothing);
      expect(find.byType(Dialog), findsNothing);
      expect(_inAppBar('Clock In'), findsOneWidget);
    },
  );

  testWidgets(
    'reachable time_clock is not a sidebar destination even when the button shows',
    (tester) async {
      await _pumpShell(
        tester,
        timeClock: _clock(),
        modules: [
          {
            'key': 'dashboard',
            'label': 'Dashboard',
            'available': true,
            'reachable': true,
          },
          {
            'key': 'time_clock',
            'label': 'Clock in',
            'available': true,
            'reachable': true,
          },
        ],
      );

      expect(_inAppBar('Clock In'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byType(NavigationSidebar),
          matching: find.text('Clock in'),
        ),
        findsNothing,
      );
    },
  );

  testWidgets('changing theme keeps the clock button', (tester) async {
    await _pumpShell(
      tester,
      timeClock: _clock(),
      onRequest: (request) async {
        if (request.method == 'PATCH' &&
            request.url.path.endsWith('/me/preferences')) {
          return _json({'data': {}});
        }
        return null;
      },
    );

    expect(_inAppBar('Clock In'), findsOneWidget);

    await tester.tap(find.byTooltip('Matching your device — switch to light'));
    await tester.pumpAndSettle();

    expect(
      _inAppBar('Clock In'),
      findsOneWidget,
      reason: 'withPreference must copy timeClock or a theme tap hides the button.',
    );
  });

  testWidgets('Clock in stays in the top bar on a 375-point window', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await _pumpShell(
      tester,
      timeClock: _clock(),
      size: const Size(375, 800),
      modules: [
        {
          'key': 'services',
          'label': 'Services',
          'available': true,
          'reachable': true,
        },
      ],
    );

    expect(tester.takeException(), isNull);
    expect(_inAppBar('Clock In'), findsOneWidget);
    expect(tester.getRect(_inAppBar('Clock In')).right, lessThanOrEqualTo(375));
    expect(find.byType(PopupMenuButton<String>), findsOneWidget);
  });
}

Finder _inAppBar(String label) => find.descendant(
  of: find.byType(AppBar),
  matching: find.text(label),
);

Color? _appBarButtonColor(WidgetTester tester) {
  final button = tester.widget<OutlinedButton>(
    find.descendant(
      of: find.byType(AppBar),
      matching: find.byType(OutlinedButton),
    ),
  );
  return button.style?.foregroundColor?.resolve(const <WidgetState>{});
}

Future<void> _pumpShell(
  WidgetTester tester, {
  Map<String, Object?>? timeClock,
  List<Map<String, Object?>>? modules,
  Future<http.Response?> Function(http.Request request)? onRequest,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  var meCalls = 0;
  final api = ApiClient(
    client: MockClient((request) async {
      if (onRequest != null) {
        final handled = await onRequest(request);
        if (handled != null) return handled;
      }

      if (request.url.path.endsWith('/me')) {
        meCalls++;
        if (meCalls > 1) {
          fail('Unexpected extra /me after the session was adopted.');
        }
        return _json({'data': _me(timeClock: timeClock, modules: modules)});
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
}) => {
  'organization_enabled': organizationEnabled,
  'can_clock': canClock,
  'show_button': showButton,
  'open': open,
};

Map<String, Object?> _me({
  Map<String, Object?>? timeClock,
  List<Map<String, Object?>>? modules,
}) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['time_clock.view'],
  'modules':
      modules ??
      [
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
