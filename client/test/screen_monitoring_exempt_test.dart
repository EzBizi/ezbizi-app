import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The client half of the SECURITY exemption (Monitoring): the Lead
/// Developer's own device still beats (online) and reports specs, but never
/// captures, live-views or is remote-driven. `/me` carries
/// `screen_monitoring_exempt`; the shell reads it to construct the three
/// capture services only when it is false, and the heartbeat omits the
/// `screenshot` capability when it is true (that half is pinned in
/// monitoring_heartbeat_test.dart).
///
/// ── Non-vacuity ────────────────────────────────────────────────────
///
/// The wiring test runs the SAME mount twice, exempt and not, and asserts the
/// capture services' background polls (`/monitoring/live-wanted`,
/// `/monitoring/input-pending`) are absent for the exempt session and PRESENT
/// for the ordinary one. A shell that never constructed them, or always did,
/// would flip one half red.
void main() {
  group('Me.fromJson parses screen_monitoring_exempt', () {
    test('true when the server sends true', () {
      final me = Me.fromJson(_meJson(exempt: true));
      expect(me.screenMonitoringExempt, isTrue);
    });

    test('false when the server sends false', () {
      final me = Me.fromJson(_meJson(exempt: false));
      expect(me.screenMonitoringExempt, isFalse);
    });

    test('false when the key is absent (an older server)', () {
      final json = _meJson()..remove('screen_monitoring_exempt');
      final me = Me.fromJson(json);
      expect(
        me.screenMonitoringExempt,
        isFalse,
        reason: 'An absent key must read as not exempt — the safe default, and '
            'the same state as anyone who is not the Lead Developer.',
      );
    });
  });

  group('the shell constructs capture services only when not exempt', () {
    Future<Set<String>> monitoringPathsAfterMount(
      WidgetTester tester, {
      required bool exempt,
    }) async {
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // Windows: the capture-capable platform the three services gate on. With
      // this set, an ORDINARY session's LiveViewService/RemoteControlService
      // DO poll — which is what makes the exempt assertion meaningful: it is
      // the exemption, not a non-capturing platform, that keeps them silent.
      // Reset before the body returns — the test framework verifies this debug
      // var is unset once the test finishes, so a tearDown would be too late.
      debugDefaultTargetPlatformOverride = TargetPlatform.windows;

      final paths = <String>{};

      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;
          if (path.endsWith('/me')) {
            return _json({'data': _meJson(exempt: exempt)});
          }
          if (path.contains('/monitoring/')) {
            paths.add(path);
            if (path.endsWith('/live-wanted')) {
              return _json({'wanted': false});
            }
            if (path.endsWith('/input-pending')) {
              return _json({
                'events': <dynamic>[],
                'control_wanted': false,
                'indicator': false,
              });
            }
            return http.Response('', 204);
          }
          return _json({'message': 'Nothing stubbed for this screen.'}, 500);
        }),
      );

      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));
      expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: HomeShell(api: api, session: session, address: null),
        ),
      );
      await tester.pumpAndSettle();

      // Past the heartbeat's unconditional 60s keep-alive (so a beat is
      // guaranteed regardless of nav), and many times over the capture
      // services' 1-2s poll cadence.
      await tester.pump(const Duration(seconds: 61));
      await tester.pump();

      // Unmount so HomeShell.dispose() cancels every pending timer, then clear
      // the platform override — both before the framework verifies invariants.
      await tester.pumpWidget(const SizedBox());
      await tester.pump();
      debugDefaultTargetPlatformOverride = null;

      return paths;
    }

    testWidgets('exempt: no capture polls, only the heartbeat', (tester) async {
      final paths = await monitoringPathsAfterMount(tester, exempt: true);

      expect(
        paths.any((p) => p.endsWith('/monitoring/live-wanted')),
        isFalse,
        reason: 'An exempt session must not construct LiveViewService, so it '
            'never polls live-wanted.',
      );
      expect(
        paths.any((p) => p.endsWith('/monitoring/input-pending')),
        isFalse,
        reason: 'An exempt session must not construct RemoteControlService, so '
            'it never polls input-pending.',
      );
      expect(
        paths.any((p) => p.endsWith('/monitoring/heartbeat')),
        isTrue,
        reason: 'The heartbeat still runs — an exempt device stays online and '
            'reports specs.',
      );
    });

    testWidgets('not exempt: the capture services poll', (tester) async {
      final paths = await monitoringPathsAfterMount(tester, exempt: false);

      expect(
        paths.any((p) => p.endsWith('/monitoring/live-wanted')),
        isTrue,
        reason: 'An ordinary session constructs LiveViewService, which polls '
            'live-wanted — the control that proves the exempt case is not '
            'vacuously green.',
      );
      expect(
        paths.any((p) => p.endsWith('/monitoring/input-pending')),
        isTrue,
        reason: 'An ordinary session constructs RemoteControlService, which '
            'polls input-pending.',
      );
    });
  });
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// A person reaching exactly Dashboard — the only screen the shell needs to be
/// able to draw for this test.
Map<String, dynamic> _meJson({bool exempt = false}) => {
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
  'screen_monitoring_exempt': exempt,
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
