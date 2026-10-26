import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/client_versions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// ClientVersionsScreen's search bar (Jose, 2026-10-22: every list gets one).
void main() {
  testWidgets('typing narrows the rows to the ones that match', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, seen);

    expect(find.text('macOS'), findsWidgets);
    expect(find.text('Windows'), findsWidgets);

    await tester.enterText(
      find.byKey(const Key('client-versions-search')),
      'mac',
    );
    await tester.pump();

    expect(find.text('macOS'), findsWidgets);
    expect(
      find.text('Windows'),
      findsNothing,
      reason: 'A row that does not contain "mac" is still listed.',
    );
  });

  testWidgets('a search that matches nothing says so in one sentence', (
    tester,
  ) async {
    await _pump(tester, <Uri>[]);

    await tester.enterText(
      find.byKey(const Key('client-versions-search')),
      'zzqx',
    );
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    expect(find.text('No platforms match "zzqx".'), findsOneWidget);
    expect(find.text('Windows'), findsNothing);
  });
}

const _me = {
  'id': 'staff-1',
  'name': 'Owner',
  'email': 'owner@ezbizi.test',
  'tier': 'lead_developer',
  'may_enter_without_approval': true,
  'recovery_code_count': 8,
};

const List<Map<String, Object?>> _rows = [
  {
    'platform': 'windows',
    'minimum_version': '0.0.0030',
    'is_enforced': true,
    'download_url': null,
    'note': null,
    'changed_by': null,
    'changed_at': null,
    'takes_download_url': true,
  },
  {
    'platform': 'macos',
    'minimum_version': null,
    'is_enforced': false,
    'download_url': null,
    'note': null,
    'changed_by': null,
    'changed_at': null,
    'takes_download_url': true,
  },
  {
    'platform': 'linux',
    'minimum_version': null,
    'is_enforced': false,
    'download_url': null,
    'note': null,
    'changed_by': null,
    'changed_at': null,
    'takes_download_url': true,
  },
];

Future<void> _pump(WidgetTester tester, List<Uri> seen) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((http.Request request) async {
      final path = request.url.path;
      if (path.endsWith('/staff/me')) return _json({'data': _me});
      if (path.endsWith('/staff/client-versions') && request.method == 'GET') {
        seen.add(request.url);
        return _json({'data': _rows});
      }
      return _json({'message': 'unexpected ${request.method} $path'}, 404);
    }),
  );
  final staff = StaffSession(api);

  await tester.runAsync(() => staff.adopt('tok'));
  expect(staff.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ClientVersionsScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
