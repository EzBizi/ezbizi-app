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
  /// UI queue 91 (Jose, 2026-10-14: "The AI tab should just be the ask bar
  /// at the bottom, sort of like here where i can ask you stuff and the reply
  /// goes on top"): a conversation, the topics gone to Library.
  testWidgets('the AI tab is a conversation: the ask bar at the foot, the '
      "Library's topics as first questions", (tester) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    // Only the tab this person was granted.
    expect(find.text('Library'), findsNothing);
    expect(find.byKey(const ValueKey('assistant-hello')), findsOneWidget);
    expect(find.byKey(const Key('assistant-ask')), findsOneWidget);
    // The topics tree is not here any more.
    expect(find.byKey(const Key('topic-NFPA 211')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('assistant-starter-NFPA 211')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('assistant-turn-0')), findsOneWidget);
  });

  testWidgets("a question and its answer stack above the bar: AI's words, "
      'then the Library entries behind them, one tap from reading', (
    tester,
  ) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    await tester.enterText(find.byKey(const Key('assistant-ask')), 'flue size');
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();

    expect(find.text('flue size'), findsOneWidget);
    expect(find.byKey(const Key('assistant-ai-answer')), findsOneWidget);
    expect(find.text('Answer by AI'), findsOneWidget);
    expect(
      find.text('Size the round flue at 1/12 of the opening.'),
      findsOneWidget,
    );
    // The bar is empty again, ready for the next question.
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('assistant-ask')))
          .controller!
          .text,
      '',
    );

    await tester.tap(find.byKey(const ValueKey('assistant-source-e-1')));
    await tester.pumpAndSettle();
    expect(find.byType(SidePanel), findsOneWidget);
    expect(find.text('Round flue: 1/12'), findsOneWidget);
    expect(find.text('Edit in Library'), findsNothing);
  });

  testWidgets('a second question goes under the first', (tester) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    for (final q in ['flue size', 'chi']) {
      await tester.enterText(find.byKey(const Key('assistant-ask')), q);
      await tester.tap(find.byKey(const Key('assistant-send')));
      await tester.pumpAndSettle();
    }
    final first = tester.getTopLeft(
      find.byKey(const ValueKey('assistant-turn-0')),
    );
    final second = tester.getTopLeft(
      find.byKey(const ValueKey('assistant-turn-1')),
    );
    expect(second.dy, greaterThan(first.dy));
  });

  testWidgets('a branch is a card with Call and Map under its answer', (
    tester,
  ) async {
    await _pump(tester, grants: ['ai_assistance.ai.view']);

    await tester.enterText(find.byKey(const Key('assistant-ask')), 'chi');
    await tester.tap(find.byKey(const Key('assistant-send')));
    await tester.pumpAndSettle();

    final card = find.byKey(const Key('branch-A1 Chimney - CHI'));
    expect(
      find.descendant(
        of: card,
        matching: find.text('Serves Chicago, Illinois'),
      ),
      findsOneWidget,
    );
    for (final action in ['Call', 'Map', 'Copy']) {
      expect(
        find.descendant(of: card, matching: find.text(action)),
        findsOneWidget,
      );
    }
  });

  testWidgets("Edit in Library opens the Library's own panel", (tester) async {
    await _pump(
      tester,
      grants: ['ai_assistance.ai.view', 'ai_assistance.manage'],
    );

    await tester.enterText(find.byKey(const Key('assistant-ask')), 'flue size');
    await tester.tap(find.byKey(const Key('assistant-send')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('assistant-source-e-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Edit in Library'));
    await tester.pumpAndSettle();

    expect(find.byType(SidePanel), findsOneWidget);
  });

  testWidgets(
    'on a phone the conversation fills the tab, the bar at its foot',
    (tester) async {
      await _pump(
        tester,
        grants: ['ai_assistance.ai.view'],
        size: const Size(390, 900),
      );

      await tester.enterText(
        find.byKey(const Key('assistant-ask')),
        'flue size',
      );
      await tester.tap(find.byKey(const Key('assistant-send')));
      await tester.pumpAndSettle();
      final bar = tester.getTopLeft(find.byKey(const Key('assistant-ask')));
      final answer = tester.getTopLeft(
        find.byKey(const Key('assistant-ai-answer')),
      );
      expect(bar.dy, greaterThan(answer.dy));
    },
  );

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

  /// The topics live in Library now (UI queue 91, 7): anybody reads an
  /// answer there; an editor edits it.
  testWidgets('Library: anybody reads an answer, and only an editor edits', (
    tester,
  ) async {
    await _pump(tester, grants: ['ai_assistance.library.view']);

    await tester.tap(find.text('Lenders'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('library-read-e-2')), findsOneWidget);
    expect(find.text('Acorn first.'), findsOneWidget);
  });

  test('WhatsApp markers go, a link keeps its underscores', () {
    expect(plainAnswer('*Bold* text'), 'Bold text');
    expect(plainAnswer('_A footer line._'), 'A footer line.');
    expect(
      plainAnswer('https://x.test/?utm_medium=web_pre_qual'),
      'https://x.test/?utm_medium=web_pre_qual',
    );
  });

  /// Grok AI, 2026-10-14: a question the Library cannot answer is read from
  /// the app as the asker, and says so; lines and blank lines stay.
  testWidgets('an answer read from the app says so, keeps its lines, and '
      'shows no Library chips', (tester) async {
    await _pump(
      tester,
      grants: ['ai_assistance.ai.view'],
      answer: {
        'reply': '*Tomorrow*\n\n4 jobs booked\n2 in Maple Grove',
        'entry_ids': <Object?>[],
        'from_app': true,
      },
    );

    await tester.enterText(
      find.byKey(const Key('assistant-ask')),
      'How many jobs do we have for tomorrow',
    );
    await tester.tap(find.byKey(const Key('assistant-send')));
    await tester.pumpAndSettle();

    expect(find.text('From EzBizi, read with your access'), findsOneWidget);
    expect(find.byKey(const Key('assistant-answer')), findsNothing);
    final words = tester.widget<SelectableText>(
      find.descendant(
        of: find.byKey(const Key('assistant-ai-answer')),
        matching: find.byType(SelectableText),
      ),
    );
    expect(
      words.textSpan!.toPlainText(),
      'Tomorrow\n\n4 jobs booked\n2 in Maple Grove',
    );
  });

  testWidgets('"could not answer" only when the answer is refused', (
    tester,
  ) async {
    await _pump(
      tester,
      grants: ['ai_assistance.ai.view'],
      answerStatus: 503,
      noMatches: true,
    );

    await tester.enterText(find.byKey(const Key('assistant-ask')), 'zzz qqq');
    await tester.tap(find.byKey(const Key('assistant-send')));
    await tester.pumpAndSettle();
    expect(find.textContaining('could not answer'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<String> grants,
  Size size = const Size(1200, 1400),
  Map<String, Object?>? answer,
  int answerStatus = 200,
  bool noMatches = false,
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
        if (answerStatus != 200) {
          return _json({
            'message': 'AI could not answer just now.',
          }, answerStatus);
        }
        return _json({
          'data':
              answer ??
              {
                'reply': 'Size the round flue at 1/12 of the opening.',
                'entry_ids': ['e-1'],
              },
        });
      }
      if (noMatches && path.endsWith('/ai-assistance/ask')) {
        return _json({
          'data': {'answers': <Object?>[], 'locations': <Object?>[]},
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
