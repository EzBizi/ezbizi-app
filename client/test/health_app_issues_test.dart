import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/health_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Health's "App issues" card — what the apps reported going wrong
/// (2026-10-15), beside the five subsystems.
void main() {
  http.Response json(Object body) => http.Response(jsonEncode(body), 200,
      headers: const {'content-type': 'application/json'});

  const health = {
    'backups': {'ok': true, 'age_hours': 2, 'verified': true, 'offsite': true},
    'peer': {'ok': true, 'host': 'web box'},
    'certificate': {'ok': true, 'days_remaining': 60},
    'mail': {'delivers': true, 'missing': <String>[], 'transport': 'smtp', 'from': 'a@b.test'},
    'outbox': {'ok': true, 'oldest_minutes': 0, 'failed_jobs': 0},
  };

  Future<void> pump(WidgetTester tester, Map<String, dynamic> issues) async {
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/staff/me')) {
        return json({'data': {'id': 's', 'name': 'Owner', 'email': 'o@e.test',
          'tier': 'lead_developer', 'may_enter_without_approval': true, 'recovery_code_count': 8}});
      }
      if (path.endsWith('/staff/health/client-issues')) return json(issues);
      if (path.endsWith('/staff/health')) return json(health);
      return http.Response('{}', 404);
    }));
    final staff = StaffSession(api);
    await tester.runAsync(() => staff.adopt('tok'));

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: HealthScreen(staff: staff)),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('a freeze shows with where it happened, and opens to its detail', (tester) async {
    await pump(tester, {
      'days': 7,
      'problems': [
        {
          'fingerprint': 'f1', 'kind': 'freeze',
          'message': 'The app did not respond for 4200 ms',
          'total': 3, 'people': 1, 'modules': ['scheduling'], 'platforms': ['windows'],
          'versions': ['0.0.0425'], 'last_seen_at': '2026-10-15 18:50:00',
          'detail': 'module: scheduling\nnative calls in flight: none',
        },
      ],
    });

    final card = find.byKey(const Key('health-app-issues'));
    expect(card, findsOneWidget);
    expect(find.textContaining('Freeze · The app did not respond for 4200 ms'), findsOneWidget);
    expect(find.textContaining('in scheduling'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('health-issue-f1')));
    await tester.pumpAndSettle();
    expect(find.textContaining('native calls in flight: none'), findsOneWidget);
  });

  testWidgets('nothing reported reads as healthy', (tester) async {
    await pump(tester, {'days': 7, 'problems': <dynamic>[]});
    expect(find.text('No errors, crashes or freezes reported in the last 7 days.'), findsOneWidget);
  });
}
