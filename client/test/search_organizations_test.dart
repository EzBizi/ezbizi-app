import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/organizations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// OrganizationsScreen's search bar (Jose, 2026-10-22: every list gets one).
void main() {
  testWidgets('typing narrows the rows to the ones that match', (tester) async {
    final seen = <Uri>[];
    await _pump(tester, seen);

    expect(find.text('Bright Plumbing'), findsWidgets);
    expect(find.text('Acme Chimney'), findsWidgets);

    await tester.enterText(
      find.byKey(const Key('organizations-search')),
      'plumb',
    );
    await tester.pump();

    expect(find.text('Bright Plumbing'), findsWidgets);
    expect(
      find.text('Acme Chimney'),
      findsNothing,
      reason: 'A row that does not contain "plumb" is still listed.',
    );
  });

  // UI queue 168: a table on a desk, and the words agree with the counts.
  testWidgets('a wide screen lists one table row per organization', (
    tester,
  ) async {
    await _pump(tester, <Uri>[]);
    expect(find.text('BRANCHES'), findsOneWidget);
    expect(find.byKey(const ValueKey('organization-row-org-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('organization-row-org-2')), findsOneWidget);
  });

  testWidgets('a phone says "1 company", "2 companies", "0 branches"', (
    tester,
  ) async {
    await _pump(tester, <Uri>[], size: const Size(375, 812));
    expect(find.text('BRANCHES'), findsNothing);
    expect(find.text('3 users · 1 company · 0 branches'), findsOneWidget);
    expect(find.text('5 users · 2 companies · 1 branch'), findsOneWidget);
  });

  testWidgets('a search that matches nothing says so in one sentence', (
    tester,
  ) async {
    await _pump(tester, <Uri>[]);

    await tester.enterText(
      find.byKey(const Key('organizations-search')),
      'zzqx',
    );
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    expect(find.text('No organizations match "zzqx".'), findsOneWidget);
    expect(find.text('Acme Chimney'), findsNothing);
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
    'id': 'org-1',
    'name': 'Acme Chimney',
    'counts': {'users': 3, 'companies': 1, 'franchises': 0},
    'is_read_only': false,
    'is_demo': false,
  },
  {
    'id': 'org-2',
    'name': 'Bright Plumbing',
    'counts': {'users': 5, 'companies': 2, 'franchises': 1},
    'is_read_only': false,
    'is_demo': false,
  },
];

Future<void> _pump(
  WidgetTester tester,
  List<Uri> seen, {
  Size size = const Size(1600, 2200),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((http.Request request) async {
      final path = request.url.path;
      if (path.endsWith('/staff/me')) return _json({'data': _me});
      if (path.endsWith('/staff/organizations') && request.method == 'GET') {
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
      home: Scaffold(body: OrganizationsScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
