import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/releases_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Development tab's Releases screen: the release in flight with its
/// percentage and stages, the recent ones under it, asked again every ten
/// seconds.
void main() {
  testWidgets('shows the release in flight, then follows it as it moves', (
    tester,
  ) async {
    var calls = 0;
    var gate = 85;
    var failed = false;

    await _pump(tester, (request) async {
      final path = request.url.path;
      if (path.endsWith('/staff/me')) return _json({'data': _me});
      if (path.endsWith('/staff/release-status')) {
        calls++;
        return _json({
          'current': _release(
            '0.0.0559',
            state: failed ? 'failed' : 'running',
            percent: failed ? 30 : 58,
            stages: [
              _stage('precheck', 'Precheck', 'done', 100, '✓ precheck'),
              _stage(
                'server_tests',
                'Server tests',
                failed ? 'failed' : 'running',
                gate,
                failed ? '✗ backend tests failed' : 'phpunit running',
              ),
              _stage('web', 'Deploy web', 'pending', 0, null),
            ],
          ),
          'recent': [
            _release('0.0.0558', state: 'done', percent: 100, stages: []),
          ],
        });
      }
      return _json({'message': 'unexpected $path'}, status: 404);
    });

    expect(calls, 1);
    expect(find.text('v0.0.0559'), findsOneWidget);
    expect(find.text('58%'), findsOneWidget);
    expect(find.text('In progress'), findsOneWidget);
    expect(find.text('Server tests · 85%'), findsOneWidget);
    expect(find.text('Deploy web'), findsOneWidget);
    // The stage running spins; the finished one is ticked.
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('release-stage-server_tests')),
        matching: find.byType(CircularProgressIndicator),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('release-stage-precheck')),
        matching: find.byIcon(Icons.check_circle),
      ),
      findsOneWidget,
    );
    expect(find.text('20m 0s elapsed · phpunit running'), findsOneWidget);
    expect(find.textContaining('v0.0.0558'), findsOneWidget);

    // Ten seconds on, it asks again and shows what changed.
    gate = 91;
    failed = true;
    await tester.pump(const Duration(seconds: 10));
    await tester.pump(const Duration(milliseconds: 50));

    expect(calls, 2);
    expect(find.text('Failed'), findsWidgets);
    expect(find.text('✗ backend tests failed'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('release-stage-server_tests')),
        matching: find.byIcon(Icons.cancel),
      ),
      findsOneWidget,
    );

    // Leaving stops the polling.
    await tester.pumpWidget(const SizedBox());
    await tester.pump(const Duration(seconds: 30));
    expect(calls, 2);
  });

  testWidgets('says so when no release has reported', (tester) async {
    await _pump(tester, (request) async {
      if (request.url.path.endsWith('/staff/me')) return _json({'data': _me});
      return _json({'current': null, 'recent': []});
    });

    expect(find.text('No release has reported yet.'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}

const _me = {
  'id': 'staff-1',
  'name': 'Owner',
  'email': 'owner@ezbizi.test',
  'tier': 'developer',
  'may_enter_without_approval': true,
  'recovery_code_count': 8,
};

Map<String, dynamic> _release(
  String version, {
  required String state,
  required int percent,
  required List<Map<String, dynamic>> stages,
}) => {
  'version': version,
  'sha': 'c' * 40,
  'state': state,
  'finished': state != 'running',
  'failed': state == 'failed',
  'percent': percent,
  'stage': 'server_tests',
  'message': 'phpunit running',
  'started_at': '2026-11-11T14:40:00Z',
  'updated_at': '2026-11-11T14:58:00Z',
  'elapsed_seconds': 1200,
  'stages': stages,
};

Map<String, dynamic> _stage(
  String key,
  String label,
  String state,
  int percent,
  String? message,
) => {
  'key': key,
  'label': label,
  'state': state,
  'percent': percent,
  'message': message,
  'at': null,
};

http.Response _json(Map<String, dynamic> body, {int status = 200}) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1280, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final staff = StaffSession(api);
  await tester.runAsync(() => staff.adopt('tok'));
  expect(staff.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ReleasesScreen(staff: staff)),
    ),
  );
  // Not pumpAndSettle: the running stage spins forever.
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
}
