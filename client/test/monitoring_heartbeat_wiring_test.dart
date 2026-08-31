import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
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
