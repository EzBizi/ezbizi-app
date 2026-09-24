import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ai_assistance_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('the AI tab shows the answers the server sends, without markup', (
    tester,
  ) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    expect(find.text('A1 Chimney Bot'), findsOneWidget);
    // Only the tab this person was granted.
    expect(find.text('Library'), findsNothing);

    await tester.tap(find.text('NFPA 211'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Flue sizing'));
    await tester.pumpAndSettle();

    expect(find.text('Flue sizing for a fireplace'), findsOneWidget);
  });

  testWidgets('Library without manage has no Add answer', (tester) async {
    await _pump(tester, grants: ['ai_assistance.library.view']);

    expect(find.text('Finance options'), findsOneWidget);
    expect(find.text('Lenders'), findsOneWidget);
    expect(find.text('Whole organization · Staff only'), findsOneWidget);
    expect(find.text('Add answer'), findsNothing);
  });

  testWidgets('Library with manage offers Add answer', (tester) async {
    await _pump(
      tester,
      grants: ['ai_assistance.library.view', 'ai_assistance.manage'],
    );

    expect(find.text('Add answer'), findsOneWidget);
  });

  test('WhatsApp markers go, a link keeps its underscores', () {
    expect(plainAnswer('*Bold* text'), 'Bold text');
    expect(plainAnswer('_A footer line._'), 'A footer line.');
    expect(
      plainAnswer('https://x.test/?utm_medium=web_pre_qual'),
      'https://x.test/?utm_medium=web_pre_qual',
    );
  });
}

Future<void> _pump(WidgetTester tester, {required List<String> grants}) async {
  tester.view.physicalSize = const Size(1200, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me(grants)});
      if (path.endsWith('/ai-assistance/menu')) {
        return _json({
          'data': {
            'name': 'A1 Chimney Bot',
            'welcome': 'Welcome.',
            'sections': [
              {
                'section': 'NFPA 211',
                'entries': [
                  {
                    'id': 'e-1',
                    'title': 'Flue sizing',
                    'description': 'Against the opening',
                    'body': '*Flue sizing for a fireplace*',
                  },
                ],
              },
            ],
          },
        });
      }
      if (path.endsWith('/ai-assistance/entries')) {
        return _json({
          'data': [
            {
              'id': 'e-2',
              'section': 'Finance options',
              'title': 'Lenders',
              'description': null,
              'body': 'Acorn first.',
              'audience': 'staff',
              'position': 0,
              'company_id': null,
              'franchise_id': null,
            },
          ],
        });
      }
      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: AiAssistanceScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _me(List<String> grants) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Office'},
  'permissions': ['ai_assistance.view', ...grants],
  'modules': [
    {
      'key': 'ai_assistance',
      'label': 'AI Assistance',
      'available': true,
      'reachable': true,
    },
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
