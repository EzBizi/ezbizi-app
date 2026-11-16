import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_recovery.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const _conditions = {
  'all': [
    {
      'op': 'not_in',
      'field': 'status',
      'value': ['completed', 'lost'],
    },
  ],
};

final _patched = <Map<String, dynamic>>[];

void main() {
  // 11-12: an actions-only PATCH wiped a rule's conditions.
  testWidgets('editing the text sends the conditions with it', (tester) async {
    await _pump(tester);
    expect(find.text('Hi Jane, thank you for calling A-1 Chimney!'), findsOneWidget);
    expect(find.text('3 sent in the last 7 days'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('recovery-text-r-1')),
      'Call us back, {{thank_you}}',
    );
    await tester.pumpAndSettle(const Duration(milliseconds: 500));
    await tester.tap(find.byKey(const ValueKey('recovery-save-r-1')));
    await tester.pumpAndSettle();

    expect(_patched.single['conditions'], _conditions);
    expect(_patched.single['actions'], [
      {'type': 'reply', 'message': 'Call us back, {{thank_you}}'},
    ]);
    expect(find.text('Saved.'), findsOneWidget);
  });

  testWidgets('Company · Branch shows the scope and sends only the scope', (
    tester,
  ) async {
    await _pump(tester);
    expect(find.text('A-1 Chimney (all branches)'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('recovery-place-r-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('scope-choice-company:co-2')));
    await tester.pumpAndSettle();

    expect(_patched.single, {'scope_type': 'company', 'scope_id': 'co-2'});
    expect(find.text('EverDoors (all branches)'), findsOneWidget);
  });

  testWidgets('the switch sends is_active and nothing else', (tester) async {
    await _pump(tester);
    await tester.tap(find.byKey(const ValueKey('recovery-switch-r-1')));
    await tester.pumpAndSettle();

    expect(_patched.single, {'is_active': false});
  });
}

Future<void> _pump(WidgetTester tester) async {
  _patched.clear();
  tester.view.physicalSize = const Size(1200, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': ['automations.view', 'automations.edit'],
          },
        });
      }
      if (path.endsWith('/leads/recovery')) {
        return _json({
          'data': {
            'rules': [
              {
                'id': 'r-1',
                'name': 'Not booked - after a call',
                'trigger_key': 'lead.call_not_booked',
                'is_active': true,
                'delay_minutes': 120,
                'version': 1,
                'conditions': _conditions,
                'actions': [
                  {'type': 'reply', 'message': '{{thank_you}} for calling'},
                ],
                'message': '{{thank_you}} for calling',
                'sent_7d': 3,
                'place': 'company:co-1',
              },
            ],
            'texts': <Object>[],
            'variables': ['thank_you', 'company_name'],
            'companies': [
              {'id': 'co-1', 'name': 'A-1 Chimney'},
            ],
          },
        });
      }
      if (path.endsWith('/leads/recovery/preview')) {
        return _json({
          'data': {
            'text': 'Hi Jane, thank you for calling A-1 Chimney!',
            'company': 'A-1 Chimney',
            'sms_from': '+12035550100',
          },
        });
      }
      if (request.method == 'PATCH') {
        final body = (jsonDecode(request.body) as Map).cast<String, dynamic>();
        _patched.add(body);
        return _json({
          'data': {
            'id': 'r-1',
            'is_active': body['is_active'] ?? true,
            'version': body.containsKey('actions') ? 2 : 1,
            'conditions': _conditions,
            'actions':
                body['actions'] ??
                [
                  {'type': 'reply', 'message': '{{thank_you}} for calling'},
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
        body: LeadsRecovery(api: api, session: session),
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
  'email': 'jose@ezbizi.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'is_active': true},
    {'id': 'co-2', 'name': 'EverDoors', 'is_active': true},
  ],
  'franchises': <Map<String, Object?>>[],
};
