import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/controlled_banner.dart';
import 'package:flutter/foundation.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Whether `MonitoringHeartbeat` actually STARTS when the shell mounts —
/// separate from `monitoring_heartbeat_test.dart`, which only ever exercises
/// the service directly and therefore could not have caught this.
///
/// ── The bug this pins ──────────────────────────────────────────────
///
/// `_HomeShellState` originally held:
///
///   late final MonitoringHeartbeat _heartbeat = MonitoringHeartbeat(widget.api);
///
/// A `late final` FIELD initializer runs on first READ, not at construction.
/// The only reads of `_heartbeat` were inside `_show()` (a nav tap) and
/// `dispose()`. So a session that lands on a deep link or the default module
/// and never taps the nav rail never read the field, never constructed
/// `MonitoringHeartbeat`, and its `Timer.periodic` never started — zero
/// beats, not even the keep-alive, for the entire time that session is open.
/// That contradicts `MonitoringHeartbeat`'s own doc comment, which says the
/// keep-alive is unconditional on ever having called `enter()`.
///
/// This test never taps anything. It mounts the shell, advances the fake
/// clock 60 seconds, and checks whether a heartbeat went out. Against the
/// buggy lazy-field wiring it does not, because nothing ever read
/// `_heartbeat` before that point.
void main() {
  testWidgets(
    'a session that never taps the nav still sends the 60s keep-alive beat',
    (tester) async {
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final heartbeats = <Map<String, dynamic>>[];

      final api = ApiClient(
        client: MockClient((request) async {
          if (request.url.path.endsWith('/me')) {
            return _json({'data': _me});
          }
          if (request.url.path.endsWith('/monitoring/heartbeat')) {
            heartbeats.add(jsonDecode(request.body) as Map<String, dynamic>);
            return http.Response('', 204);
          }
          return _json({'message': 'Nothing stubbed for this screen.'}, 500);
        }),
      );

      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));

      expect(
        session.signedIn,
        isTrue,
        reason: 'The /me stub did not produce a session.',
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: HomeShell(api: api, session: session, address: null),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        heartbeats,
        isEmpty,
        reason: 'No time has passed yet — a beat here would mean the '
            'keep-alive fires immediately rather than every 60s.',
      );

      // Nothing tapped. Just time passing — this is the whole point: a
      // deep-linked or default-landed session that never touches the nav
      // rail still has to be seen.
      await tester.pump(const Duration(seconds: 60));
      await tester.pump();

      expect(
        heartbeats,
        isNotEmpty,
        reason:
            'The 60s keep-alive never fired without a nav tap. A `late '
            'final` FIELD initializer on `_heartbeat` only constructs '
            'MonitoringHeartbeat on first READ — `_show()` and `dispose()` '
            'were the only reads — so a session that lands here and never '
            'taps the rail never constructs the service at all, and its '
            'Timer.periodic never starts.',
      );
    },
  );

  /// And the beat is what gives the availability icon a minute hand.
  ///
  /// ── The report ───────────────────────────────────────────────────
  ///
  /// Jose: "the status now syncs across devices, which is good, but only
  /// displays the change once you change tabs." Do Not Disturb set on the web
  /// reached the desktop's server state immediately; the desktop's ICON found
  /// out only when something else made it look.
  ///
  /// The hook that was supposed to cover it watches
  /// `AppLifecycleState.resumed`, which tracks whether the app is VISIBLE —
  /// and two windows side by side are both visible the whole time, so it never
  /// fired in the arrangement the report was about.
  ///
  /// ── Why this test lives here ─────────────────────────────────────
  ///
  /// The fix adds no timer: the shell hands the availability icon the beat
  /// this file already exists to prove happens. That makes the wiring the
  /// fragile part — one assignment in `_HomeShellState.initState` — and a test
  /// of either piece alone would pass with that line deleted.
  testWidgets('a beat makes the availability icon re-read the server', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var presenceReads = 0;

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/phone/presence/me')) {
          presenceReads++;
          return _json({
            'data': {'state': 'registered', 'has_line': false},
          });
        }
        if (request.url.path.endsWith('/me')) return _json({'data': _me});
        if (request.url.path.endsWith('/monitoring/heartbeat')) {
          return http.Response('', 204);
        }

        return _json({'message': 'Nothing stubbed for this screen.'}, 500);
      }),
    );

    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: HomeShell(api: api, session: session, address: null),
      ),
    );
    await tester.pumpAndSettle();

    final beforeTheBeat = presenceReads;
    expect(
      beforeTheBeat,
      greaterThan(0),
      reason: 'the icon never read presence at all, so this test cannot tell '
          'a second read from a first',
    );

    // Nothing touched. Only a minute passing.
    await tester.pump(const Duration(seconds: 60));
    await tester.pumpAndSettle();

    expect(
      presenceReads,
      greaterThan(beforeTheBeat),
      reason: 'a minute passed and nothing asked the server what this person '
          "is — so a colleague's change to their availability would sit "
          'unshown until they happened to change tabs',
    );
  });

  testWidgets(
    'the app itself says so while somebody is driving the machine',
    (tester) async {
      // The CALLER test for the controlled banner. remote_control_service_test
      // proves the published flag follows the native notice, and the banner is
      // a widget that renders a sentence — neither proves the SHELL ever joins
      // them, which is one `if (_remoteControl != null)` in its build method.
      //
      // It matters more than usual here. The native tray notice could only
      // ever appear while the window was HIDDEN, because win32_window.cpp
      // added its icon at WM_CLOSE — so for the whole time remote control has
      // shipped, somebody sitting in front of the OPEN app was told nothing
      // while being driven. This banner is the surface that does not depend on
      // the OS agreeing to show a balloon, and a test that it is actually
      // wired is the difference between a consent notice and an intention.
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // RemoteControlService is only constructed where capture is supported,
      // and `flutter test` reports android whatever the host really is.
      debugDefaultTargetPlatformOverride = TargetPlatform.windows;

      final api = ApiClient(
        client: MockClient((request) async {
          if (request.url.path.endsWith('/me')) return _json({'data': _me});
          if (request.url.path.endsWith('/monitoring/heartbeat')) {
            return http.Response('', 204);
          }
          if (request.url.path.endsWith('/monitoring/input-pending')) {
            // Being driven AND opted in — both, because either alone must
            // stay silent, which remote_control_service_test pins separately.
            return _json({
              'events': const <dynamic>[],
              'control_wanted': true,
              'indicator': true,
            });
          }
          return _json({'message': 'Nothing stubbed for this screen.'}, 500);
        }),
      );

      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: HomeShell(api: api, session: session, address: null),
        ),
      );
      await tester.pump();

      // Cleared as soon as the shell has mounted — the override is only needed
      // for the constructor's capability gate, and testWidgets asserts every
      // foundation debug variable is back to null before the body ends, which
      // addTearDown is too late to satisfy.
      debugDefaultTargetPlatformOverride = null;

      expect(find.text(ControlledBanner.message), findsNothing,
          reason: 'Nothing is claimed before the first poll has answered.');

      // Let the control poll run and answer.
      for (var i = 0; i < 8; i++) {
        await tester.pump(const Duration(seconds: 5));
      }

      expect(find.text(ControlledBanner.message), findsOneWidget,
          reason: 'The shell never rendered the banner. The service knows the '
              'machine is being controlled and nothing on screen says so — '
              'which is exactly the state the tray notice was already in.');
    },
  );

}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// A person reaching exactly Dashboard — the only screen this test needs the
/// shell to actually be able to draw.
final Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': [
    {
      'key': 'dashboard',
      'label': 'Dashboard',
      'available': true,
      'reachable': true,
      'parent': null,
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
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
