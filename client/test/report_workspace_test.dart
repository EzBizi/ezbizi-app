import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/report_workspace.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Reports workspace (takeover row 10, UI queue 157: options 1 + 4).
void main() {
  testWidgets('the list groups tables and daily texts, and search narrows it', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('TABLES'), findsOneWidget);
    expect(find.text('DAILY TEXTS'), findsOneWidget);
    expect(find.text('Job assignments'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('workspace-search')), 'ppc');
    await tester.pump();
    expect(find.text('Period'), findsNothing);
    expect(find.text('Marketing PPC'), findsOneWidget);
  });

  testWidgets(
    'a period opens with its preview, steps, Approve, PDF and Excel',
    (tester) async {
      await _pump(tester);
      await tester.tap(find.byKey(const ValueKey('workspace-report-period')));
      await tester.pumpAndSettle();

      expect(find.text('Created'), findsOneWidget);
      expect(find.text('HQ · whole organization'), findsOneWidget);
      expect(find.byKey(const ValueKey('workspace-approve')), findsOneWidget);
      expect(find.byKey(const ValueKey('workspace-pdf')), findsOneWidget);
      expect(find.byKey(const ValueKey('workspace-xlsx')), findsOneWidget);
    },
  );

  testWidgets('Excel is hidden without reports.export', (tester) async {
    await _pump(tester, permissions: const ['reports.view']);
    await tester.tap(find.byKey(const ValueKey('workspace-report-period')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('workspace-pdf')), findsOneWidget);
    expect(find.byKey(const ValueKey('workspace-xlsx')), findsNothing);
  });

  testWidgets('a daily text shows as sent, with Send now and its rules', (
    tester,
  ) async {
    await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('workspace-report-ppc')));
    await tester.pumpAndSettle();

    expect(find.text('PPC text body'), findsOneWidget);
    expect(find.byKey(const ValueKey('workspace-send')), findsOneWidget);
    expect(find.text('WhatsApp: PPC'), findsOneWidget);
    expect(find.byKey(const ValueKey('workspace-pdf')), findsNothing);
  });

  testWidgets(
    'on a phone the list comes first, then the report with its actions',
    (tester) async {
      await _pump(tester, width: 390);

      expect(find.byKey(const ValueKey('workspace-list')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('workspace-report-ppc')));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('workspace-list')), findsNothing);
      expect(
        find.byKey(const ValueKey('workspace-phone-report')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('workspace-actions')), findsOneWidget);
    },
  );
}

Future<void> _pump(
  WidgetTester tester, {
  List<String> permissions = const [
    'reports.view',
    'reports.export',
    'reports.workspace.view',
  ],
  double width = 1280,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      if (path.endsWith('/reports/period')) {
        return _json({
          'data': {
            'name': 'Company',
            'sheets': {
              'Created': [
                ['Branch', 'Source', 'Jobs'],
                ['F1', 'Google', 3],
              ],
            },
          },
        });
      }
      if (path.endsWith('/reports/batches/week')) {
        return _json({
          'data': {
            'id': 'b-1',
            'starts_on': '2026-10-19',
            'ends_on': '2026-10-25',
            'revision': 1,
            'step': 'draft',
            'can_approve': true,
          },
        });
      }
      if (path.endsWith('/reports/texts/ppc')) {
        return _json({
          'data': {
            'kind': 'ppc',
            'text': 'PPC text body',
            'rules': [
              {
                'id': 'a-1',
                'name': 'WhatsApp: PPC',
                'active': true,
                'schedule': {'kind': 'daily', 'time': '09:00'},
                'to': ['Jose'],
              },
            ],
          },
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ReportWorkspace(
          api: api,
          session: session,
          now: () => DateTime(2026, 10, 29),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Alpha',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Alpha',
    'path': ['Alpha'],
  },
  'companies': [
    {'id': 'c-1', 'name': 'Alpha Co', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'f-1', 'name': 'F1', 'company_id': 'c-1'},
  ],
};
