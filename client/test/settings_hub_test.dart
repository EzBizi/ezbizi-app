import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/settings_hub.dart';
import 'package:ezbizi_client/screens/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-10-15: every module's settings can be found in Settings, behind
/// the same grant as that module's own Settings tab (UI queue 99).
void main() {
  testWidgets('the index lists a module only with its grant, and opens its '
      'settings in place', (tester) async {
    await _pump(
      tester,
      modules: [
        _module('field', 'Field'),
        _module('work_hours', 'Work Hours'),
        // Reachable, but this role lacks work_hours.settings.view.
        _module('ai_assistance', 'AI Assistance', reachable: false),
      ],
      permissions: ['organizations.settings.view', 'field.settings.view'],
    );

    expect(find.byKey(const ValueKey('settings-hub-item-all')), findsOne);
    expect(find.byKey(const ValueKey('settings-hub-item-field')), findsOne);
    expect(
      find.byKey(const ValueKey('settings-hub-item-work_hours')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('settings-hub-item-ai_assistance')),
      findsNothing,
    );

    await tester.tap(find.byKey(const ValueKey('settings-hub-item-field')));
    await tester.pumpAndSettle();

    final field = tester.widget<SettingsScreen>(
      find.descendant(
        of: find.byKey(const ValueKey('settings-hub-field')),
        matching: find.byType(SettingsScreen),
      ),
    );
    expect(field.onlyGroup, 'field');
  });

  // 2026-10-16: the report goals, set behind reports.settings.
  testWidgets('Reports lists its goals with the grant, and saves dollars and '
      'percents', (tester) async {
    Map<String, dynamic>? put;
    final keys = [
      {'key': 'avg_sale', 'label': 'Average sale', 'red_when': 'below'},
      {'key': 'cancel_rate', 'label': 'Cancel rate', 'red_when': 'above'},
    ];
    await _pump(
      tester,
      modules: [_module('reports', 'Reports')],
      permissions: ['organizations.settings.view', 'reports.settings'],
      handle: (request) async {
        if (!request.url.path.endsWith('/reports/goals')) return null;
        if (request.method == 'PUT') {
          put = jsonDecode(request.body) as Map<String, dynamic>;
        }
        return _json({
          'data': {
            'keys': keys,
            'goals': [
              {'franchise_id': null, 'key': 'avg_sale', 'value': 1500},
            ],
          },
        });
      },
    );

    await tester.tap(find.byKey(const ValueKey('settings-hub-item-reports')));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('report-goal-avg_sale')))
          .controller!
          .text,
      '1500',
    );
    await tester.enterText(
      find.byKey(const ValueKey('report-goal-cancel_rate')),
      '20',
    );
    await tester.tap(find.byKey(const Key('report-goals-save')));
    await tester.pumpAndSettle();

    final sent = (put!['goals'] as List).cast<Map>();
    expect(sent.firstWhere((g) => g['key'] == 'cancel_rate')['value'], 0.2);
    expect(sent.firstWhere((g) => g['key'] == 'avg_sale')['value'], 1500);
    expect(sent.every((g) => g['franchise_id'] == null), isTrue);
  });

  testWidgets('without reports.settings there is no Reports entry', (
    tester,
  ) async {
    await _pump(
      tester,
      modules: [_module('reports', 'Reports')],
      permissions: ['organizations.settings.view', 'reports.view'],
    );
    expect(
      find.byKey(const ValueKey('settings-hub-item-reports')),
      findsNothing,
    );
  });

  testWidgets('narrow, the index is a Jump to chip row', (tester) async {
    await _pump(
      tester,
      width: 500,
      modules: [_module('field', 'Field')],
      permissions: ['organizations.settings.view', 'field.settings.view'],
    );

    expect(find.byKey(const ValueKey('settings-hub-index')), findsNothing);
    expect(find.byKey(const ValueKey('settings-hub-chip-field')), findsOne);
  });
}

Map<String, dynamic> _module(
  String key,
  String label, {
  bool reachable = true,
}) => {
  'key': key,
  'label': label,
  'available': true,
  'reachable': reachable,
  'parent': null,
};

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> modules,
  required List<String> permissions,
  double width = 1400,
  Future<http.Response?> Function(http.Request request)? handle,
}) async {
  tester.view.physicalSize = Size(width, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final handled = await handle?.call(request);
      if (handled != null) return handled;
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana',
            'email': 'dana@brightside.example',
            'role': {'name': 'Dispatcher'},
            'has_avatar': false,
            'preferences': <String, dynamic>{},
            'permissions': permissions,
            'modules': modules,
            'organization': {
              'id': 'org-1',
              'name': 'Brightside Home Services',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'scope': {
              'type': 'organization',
              'id': 'org-1',
              'label': 'Brightside Home Services',
              'path': ['Brightside Home Services'],
            },
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
        body: SettingsHub(api: api, session: session),
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
