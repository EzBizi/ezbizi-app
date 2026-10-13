import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/ai_assistance_screen.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
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

  testWidgets('Ask shows what the server found', (tester) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    await tester.enterText(find.byType(TextField), 'flue size');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    // A single answer arrives open, so it reads without a second tap.
    expect(find.text('Round flue: 1/12'), findsOneWidget);
    expect(find.text('A1 Chimney - CHI'), findsOneWidget);
  });

  /// Jose, 2026-10-12: the AI tab answers in its own words, marked as AI.
  testWidgets('Ask also shows an answer by AI under the matches', (
    tester,
  ) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    await tester.enterText(find.byType(TextField), 'flue size');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('assistant-ai-answer')), findsOneWidget);
    expect(find.text('Answer by AI'), findsOneWidget);
    expect(
      find.text('Size the round flue at 1/12 of the opening.'),
      findsOneWidget,
    );
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

    // A side panel like every other form, not a dialog over the list.
    await tester.tap(find.text('Add answer'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byType(SidePanel), findsOneWidget);
  });

  // UI queue 61: the WhatsApp groups the reports go to. The tab's own grant,
  // and manage, which every endpoint behind the tab asks for.
  testWidgets('WhatsApp groups needs its own grant and manage', (tester) async {
    for (final grants in [
      ['ai_assistance.whatsapp_groups.view'],
      ['ai_assistance.manage'],
    ]) {
      await _pump(tester, grants: ['ai_assistance.library.view', ...grants]);
      expect(
        find.widgetWithText(ChoiceChip, 'WhatsApp groups'),
        findsNothing,
        reason: 'only $grants',
      );
    }

    await _pump(
      tester,
      grants: [
        'ai_assistance.library.view',
        'ai_assistance.whatsapp_groups.view',
        'ai_assistance.manage',
      ],
    );
    expect(find.widgetWithText(ChoiceChip, 'WhatsApp groups'), findsOneWidget);
  });

  // ── the layout Jose picked (UI queue 40: 1 and 4) ─────────────────

  testWidgets('topics on the left open an answer in the middle, with where '
      'it came from, its footnote and the rest of its section', (tester) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    // Two answers: the section unfolds rather than opening one.
    await tester.tap(find.byKey(const Key('topic-About Harbor')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('assistant-answer')), findsNothing);

    await tester.tap(find.byKey(const Key('answer-e-3')));
    await tester.pumpAndSettle();

    expect(
      find.text('From the Library · About Harbor › Who we are'),
      findsOneWidget,
    );
    expect(find.text('We sweep every chimney.'), findsOneWidget);
    expect(find.text('Our own words.'), findsOneWidget);

    // The rest of the section, one tap away.
    await tester.tap(find.widgetWithText(OutlinedButton, 'Our warranty'));
    await tester.pumpAndSettle();
    expect(find.text('Two years.'), findsOneWidget);
    expect(find.text('Edit in Library'), findsNothing);
  });

  testWidgets('a branch is a card with Call and Map, under the question '
      'asked today', (tester) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    await tester.enterText(find.byType(TextField), 'chi');
    await tester.tap(find.widgetWithText(FilledButton, 'Ask'));
    await tester.pumpAndSettle();

    final card = find.byKey(const Key('branch-A1 Chimney - CHI'));
    expect(
      find.descendant(of: card, matching: find.text('Serves Chicago, Illinois')),
      findsOneWidget,
    );
    for (final action in ['Call', 'Map', 'Copy']) {
      expect(
        find.descendant(of: card, matching: find.text(action)),
        findsOneWidget,
      );
    }
    expect(
      find.text('1 answer for “chi”, opened. 1 branch matched.'),
      findsOneWidget,
    );

    // Asked today: tapping it asks again.
    expect(find.byKey(const Key('asked-chi')), findsOneWidget);
    await tester.enterText(find.byType(TextField), '');
    await tester.tap(find.byKey(const Key('asked-chi')));
    await tester.pumpAndSettle();
    expect(
      tester.widget<TextField>(find.byType(TextField)).controller!.text,
      'chi',
    );
  });

  testWidgets("Edit in Library opens the Library's own panel", (tester) async {
    await _pump(
      tester,
      grants: ['ai_assistance.ai.view', 'ai_assistance.manage'],
    );

    await tester.tap(find.byKey(const Key('topic-NFPA 211')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit in Library'));
    await tester.pumpAndSettle();

    expect(find.byType(SidePanel), findsOneWidget);
  });

  testWidgets('on a phone each step is a page with a way back', (tester) async {
    await _pump(
      tester,
      grants: ['ai_assistance.ai.view'],
      size: const Size(390, 900),
    );

    expect(find.text('2 topics · Who we are, Our warranty…'), findsOneWidget);

    await tester.tap(find.byKey(const Key('topic-About Harbor')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('topic-NFPA 211')), findsNothing);

    await tester.tap(find.byKey(const Key('answer-e-4')));
    await tester.pumpAndSettle();
    expect(find.text('Two years.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('assistant-back')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('assistant-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('topic-NFPA 211')), findsOneWidget);
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

Future<void> _pump(
  WidgetTester tester, {
  required List<String> grants,
  Size size = const Size(1200, 1400),
}) async {
  tester.view.physicalSize = size;
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
              {
                'section': 'About Harbor',
                'entries': [
                  {
                    'id': 'e-3',
                    'title': 'Who we are',
                    'description': 'Since 1984',
                    'body': 'We sweep *every* chimney.\n_Our own words._',
                  },
                  {
                    'id': 'e-4',
                    'title': 'Our warranty',
                    'description': null,
                    'body': 'Two years.',
                  },
                ],
              },
            ],
          },
        });
      }
      if (path.endsWith('/ai-assistance/answer')) {
        return _json({
          'data': {
            'reply': 'Size the round flue at 1/12 of the opening.',
            'entry_ids': ['e-1'],
          },
        });
      }
      if (path.endsWith('/ai-assistance/ask')) {
        return _json({
          'data': {
            'answers': [
              {
                'id': 'e-1',
                'title': 'Flue sizing',
                'description': null,
                'body': 'Round flue: 1/12',
              },
            ],
            'locations': [
              {
                'company_name': 'A1 Chimney - CHI',
                'phone': '(312) 555-0100',
                'address': '1 Lake St, Chicago, IL 60601',
                'service_area': 'Chicago, Illinois',
                'maps_url': 'https://maps.example/chi',
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
            // The AI tab's Flue sizing, as the Library lists it.
            {
              'id': 'e-1',
              'section': 'NFPA 211',
              'title': 'Flue sizing',
              'description': 'Against the opening',
              'body': '*Flue sizing for a fireplace*',
              'audience': 'public',
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
