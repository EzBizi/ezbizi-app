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
        .getTopLeft(find.byTooltip('Light — switch to dark'))
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
          if (request.url.path == '/api/v1/me') {
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
          // The shell saves the device's theme once (Jose 11-24).
          if (request.method == 'PATCH' &&
              request.url.path.endsWith('/me/preferences')) {
            return _json({'data': {}});
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

  // Jose 11-24: "get rid of the middle matching your device mode, just leave
  // dark and light mode".
  testWidgets('no theme chosen is saved once as the device shows, and the '
      'button flips light and dark only', (tester) async {
    final saved = <Object?>[];
    await _pumpShell(
      tester,
      onRequest: (request) async {
        if (request.method == 'PATCH' &&
            request.url.path.endsWith('/me/preferences')) {
          saved.add(
            (jsonDecode(request.body)['preferences']
                as Map)['appearance.theme'],
          );
          return _json({'data': {}});
        }
        return null;
      },
    );
    await tester.pumpAndSettle();
    expect(saved, ['light'], reason: 'the device (light in a test), kept');

    await tester.tap(find.byTooltip('Light — switch to dark'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Dark — switch to light'));
    await tester.pumpAndSettle();
    expect(saved, ['light', 'dark', 'light']);
  });

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
    // Jose 11-24: light and dark only. Never chosen reads as the device
    // (light in a test), shown as the sun.
    expect(find.byIcon(Icons.wb_sunny_outlined), findsOneWidget);
    expect(find.byIcon(Icons.contrast), findsNothing);

    await tester.tap(find.byTooltip('Light — switch to dark'));
    await tester.pumpAndSettle();

    expect(
      _inAppBar('Clock In'),
      findsOneWidget,
      reason: 'withPreference must copy timeClock or a theme tap hides the button.',
    );
  });

  testWidgets('an open shift breathes; Clock in is still', (tester) async {
    await _pumpShell(
      tester,
      motion: true,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-08-25T12:00:00Z'},
      ),
    );

    final lit = _pulseFill(tester);
    await tester.pump(const Duration(milliseconds: 800));

    expect(
      _pulseFill(tester),
      isNot(lit),
      reason: 'A running shift is the one thing the bar keeps saying.',
    );

    await _pumpShell(tester, motion: true, timeClock: _clock());

    final still = _pulseFill(tester);
    await tester.pump(const Duration(milliseconds: 800));

    expect(
      _pulseFill(tester),
      still,
      reason: 'Clock In has nothing happening to report, so it does not move.',
    );
    expect(still?.a, 0.0, reason: 'and it is not tinted either.');
  });

  testWidgets('less motion means no pulse at all', (tester) async {
    // `_pumpShell` settles, which is itself the assertion: a controller left
    // repeating never lets pumpAndSettle return.
    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-08-25T12:00:00Z'},
      ),
    );

    final resting = _pulseFill(tester);
    await tester.pump(const Duration(milliseconds: 800));

    expect(_pulseFill(tester), resting);
    expect(
      resting?.a,
      greaterThan(0),
      reason: 'Still, but the chip must still read as on the clock.',
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

    // The point of this test is that the punch control stays in the BAR at 375
    // rather than dropping into a menu — not that it carries a word. Since
    // 2026-09-10 it is icon-only on a phone (Jose: "make the clock out as an
    // icon instead only without the words"), which is what buys the room for
    // availability to stay beside it.
    //
    // Asserting the label would have been asserting the old implementation of
    // this test's own intent, and it went red on a change that satisfies it.
    expect(_inAppBarIcon(Icons.login), findsOneWidget);
    expect(
      tester.getRect(_inAppBarIcon(Icons.login)).right,
      lessThanOrEqualTo(375),
    );
    expect(_inAppBar('Clock In'), findsNothing);
    expect(find.byType(PopupMenuButton<String>), findsOneWidget);
  });

  testWidgets('the word comes back on a desktop', (tester) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await _pumpShell(
      tester,
      timeClock: _clock(),
      size: const Size(1280, 900),
      modules: [
        {
          'key': 'services',
          'label': 'Services',
          'available': true,
          'reachable': true,
        },
      ],
    );

    expect(_inAppBar('Clock In'), findsOneWidget);
  });
}

Finder _inAppBarIcon(IconData icon) => find.descendant(
  of: find.byType(AppBar),
  matching: find.byIcon(icon),
);

Finder _inAppBar(String label) => find.descendant(
  of: find.byType(AppBar),
  matching: find.text(label),
);

/// The breathing fill behind the punch chip.
Color? _pulseFill(WidgetTester tester) {
  final box = tester.widget<DecoratedBox>(find.byKey(const Key('clock-pulse')));

  return (box.decoration as BoxDecoration).color;
}

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
  bool motion = false,
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

      if (request.url.path == '/api/v1/me') {
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
      // Inside the MaterialApp, because WidgetsApp builds its own MediaQuery
      // from the view and an outer one would simply be replaced.
      //
      // Off for every test but the one about the pulse itself: an open shift
      // breathes for as long as it is open, so there is no frame after which
      // nothing is scheduled and `pumpAndSettle` never returns. Somebody who
      // has asked for less motion gets the still version, which is the state
      // the rest of these tests are about.
      home: Builder(
        builder: (context) => MediaQuery(
          data: MediaQuery.of(context).copyWith(disableAnimations: !motion),
          child: ListenableBuilder(
            listenable: session,
            builder: (_, _) => HomeShell(api: api, session: session),
          ),
        ),
      ),
    ),
  );

  if (motion) {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
  } else {
    await tester.pumpAndSettle();
  }
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
