import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/activity_screen.dart';
import 'package:ezbizi_client/screens/settings_shell.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings › Activity (2026-10-16).
void main() {
  // UI queue 110: a row opens to what changed, before and after; a shape
  // that is not before/after still shows, as name and value.
  testWidgets('a row opens what changed', (tester) async {
    await _pump(tester, ['audit.view'], <Uri>[], shell: false);

    await tester.tap(find.byKey(const ValueKey('activity-row:e-1')));
    await tester.pumpAndSettle();

    expect(find.text('What changed'), findsOneWidget);
    expect(find.text('new  →  follow_up'), findsOneWidget);
    expect(find.byKey(const ValueKey('value:tags')), findsOneWidget);
    expect(find.text('phone'), findsOneWidget);
  });

  // UI queue 110: a sentence, never the server's code.
  test('an action code reads as words, verb first', () {
    expect(activityAction('customer.created'), 'created customer');
    expect(
      activityAction('contract.version_published'),
      'published contract version',
    );
    expect(activityAction('report.run'), 'ran report');
    expect(activityAction('web.hours.'), 'web hours');
    expect(
      activitySentence({
        'actor': 'Dana Reyes <dana@brightside.example>',
        'action': 'lead.created',
        'target': 'Pat Lin',
      }, 'People'),
      'Dana Reyes created lead · Pat Lin',
    );
    expect(
      activitySentence({
        'actor': 'system',
        'source': 'system',
        'action': 'job.created',
      }, 'Automations'),
      'An automation created job',
    );
  });

  testWidgets('lists who did what, and a source chip asks the server', (
    tester,
  ) async {
    final asked = <Uri>[];
    await _pump(tester, ['audit.view'], asked, shell: false);

    // Wide: who in its own column, then what (UI queue 111).
    expect(find.text('Dana'), findsOneWidget);
    expect(find.text('created lead · Ann Smith'), findsOneWidget);

    // A Who picker since UI queue 111.
    _pick(tester, 'activity-who', 'system');
    await tester.pumpAndSettle();

    expect(asked.last.queryParameters['source'], 'system');
    expect(asked.last.queryParameters['window'], '7d');

    // One person rather than a source.
    _pick(tester, 'activity-who', 'actor:u-9');
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['actor_id'], 'u-9');
    expect(asked.last.queryParameters.containsKey('source'), isFalse);
  });

  // UI queue 111: module and search narrow the list on the server.
  testWidgets('module and search ask the server', (tester) async {
    final asked = <Uri>[];
    await _pump(tester, ['audit.view'], asked, shell: false);

    _pick(tester, 'activity-module', 'lead');
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['module'], 'lead');

    await tester.enterText(
      find.byKey(const ValueKey('activity-search')),
      'Ann',
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(asked.last.queryParameters['q'], 'Ann');
  });

  // UI queue 111: narrow, one sentence per row, opened in a side panel.
  testWidgets('narrow, a row is a sentence and opens in a panel', (
    tester,
  ) async {
    await _pump(tester, ['audit.view'], <Uri>[], shell: false);
    tester.view.physicalSize = const Size(600, 1000);
    await tester.pumpAndSettle();

    expect(find.text('Dana created lead · Ann Smith'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('activity-row:e-1')));
    await tester.pumpAndSettle();
    expect(find.text('What changed'), findsOneWidget);
    expect(find.byKey(const ValueKey('activity-close')), findsNothing);
  });

  // UI queue 111: wide, a table by day, and a row opens beside it.
  testWidgets('wide, the row opens beside the table', (tester) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await _pump(tester, ['audit.view'], <Uri>[], shell: false);

    expect(find.text('WHERE'), findsOneWidget);
    expect(find.text('MODULE'), findsOneWidget);
    expect(find.text('Springfield'), findsOneWidget);
    expect(find.text('created lead · Ann Smith'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('activity-row:e-1')));
    await tester.pumpAndSettle();
    expect(find.text('new  →  follow_up'), findsOneWidget);
    expect(find.byKey(const ValueKey('activity-close')), findsOneWidget);
  });

  testWidgets('the tab is drawn only for audit.view', (tester) async {
    await _pump(tester, ['organizations.settings.view'], [], shell: true);
    expect(find.text('Activity'), findsNothing);

    await _pump(tester, [
      'organizations.settings.view',
      'audit.view',
    ], [], shell: true);
    expect(find.text('Activity'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  List<String> permissions,
  List<Uri> asked, {
  required bool shell,
}) async {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana',
            'email': 'dana@example.com',
            'role': {'name': 'Auditor'},
            'has_avatar': false,
            'preferences': <String, dynamic>{},
            'permissions': permissions,
            'modules': <dynamic>[],
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
          },
        });
      }
      if (request.url.path.endsWith('/audit-events/e-1')) {
        return _json({
          'data': {
            'id': 'e-1',
            'occurred_at': '2026-10-16T14:00:00Z',
            'source': 'user',
            'actor': 'Dana',
            'action': 'lead.created',
            'target': 'Ann Smith',
            'changes': {
              'status': {'before': 'new', 'after': 'follow_up'},
              'tags': ['vip'],
            },
            'metadata': {'via': 'phone'},
          },
        });
      }
      if (request.url.path.endsWith('/audit-events')) {
        asked.add(request.url);
        return _json({
          'data': [
            {
              'id': 'e-1',
              'occurred_at': '2026-10-16T14:00:00Z',
              'source': 'user',
              'actor': 'Dana',
              'action': 'lead.created',
              'target': 'Ann Smith',
              'where': 'Springfield',
            },
          ],
          'meta': {
            'more': false,
            'actors': [
              {'id': 'u-9', 'label': 'Dana'},
            ],
          },
        });
      }
      return _json({'data': const <dynamic>[]});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: shell
            ? SettingsShell(api: api, session: session)
            : ActivityScreen(api: api, session: session),
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

void _pick(WidgetTester tester, String key, String? value) {
  tester
      .widget<SearchPickerField<String?>>(find.byKey(ValueKey(key)))
      .onChanged!(value);
}
