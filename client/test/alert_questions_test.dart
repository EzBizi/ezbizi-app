import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/phone/alerts_tab.dart';
import 'package:ezbizi_client/widgets/alert_popup.dart';
import 'package:ezbizi_client/widgets/notification_bell.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Questions (Jose, 2026-11-18; UI queue 200, 1 + 4). An alert can ask: the
/// pop-up offers its choices and/or a reply box; Later leaves it open, in the
/// bell's Questions tab; the asker sees each answer under Sent.
void main() {
  const question = {
    'alert_id': 'q-1',
    'context': 'Google Ads leads are landing as "Website".',
    'choices': ['Google PPC', 'Google Ads · Search', 'Keep "Website"'],
    'allow_text': true,
  };
  Map<String, dynamic> popup() => {
    'id': 'n-1',
    'title': 'Which job source should PPC leads get in Workiz?',
    'sender': 'Jose Silva',
    'created_at': '2026-11-18T19:14:00Z',
    'question': question,
  };

  group('the pop-up', () {
    testWidgets('a choice and words go to the reply route, and it closes '
        'without a read call', (tester) async {
      final calls = <String>[];
      final popups = await _pumpPopup(tester, (request) async {
        calls.add('${request.method} ${request.url.path} ${request.body}');
        return _json({
          'data': {'id': 'r-1'},
        }, 201);
      });
      popups.show(_context(tester), [
        popup(),
      ], formats: DisplayFormats.fallback);
      await tester.pumpAndSettle();

      expect(find.text('Question from Jose Silva'), findsOneWidget);
      expect(find.byKey(const Key('question-context')), findsOneWidget);
      final send = find.byKey(const Key('question-send'));
      expect(tester.widget<FilledButton>(send).onPressed, isNull);

      await tester.tap(find.byKey(const ValueKey('question-choice-1')));
      await tester.enterText(
        find.byKey(const Key('question-text')),
        'We use that already',
      );
      await tester.pump();
      await tester.tap(send);
      await tester.pumpAndSettle();

      expect(calls, hasLength(1));
      expect(calls.single, startsWith('POST /api/v1/alerts/q-1/reply'));
      expect(jsonDecode(calls.single.split('reply ').last), {
        'choice': 'Google Ads · Search',
        'text': 'We use that already',
      });
      expect(find.byKey(const Key('alert-popup')), findsNothing);
    });

    testWidgets('Later dismisses it and leaves the question open', (
      tester,
    ) async {
      final calls = <String>[];
      final popups = await _pumpPopup(tester, (request) async {
        calls.add('${request.method} ${request.url.path}');
        return _json({'data': {}});
      });
      popups.show(_context(tester), [
        popup(),
      ], formats: DisplayFormats.fallback);
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('alert-later')));
      await tester.pumpAndSettle();
      expect(calls, ['POST /api/v1/notifications/n-1/read']);
      expect(find.byKey(const Key('alert-popup')), findsNothing);
    });

    testWidgets('a refusal says why and keeps the pop-up', (tester) async {
      final popups = await _pumpPopup(
        tester,
        (request) async =>
            _json({'message': 'That is not one of the choices.'}, 422),
      );
      popups.show(_context(tester), [
        popup(),
      ], formats: DisplayFormats.fallback);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('question-choice-0')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('question-send')));
      await tester.pumpAndSettle();

      expect(find.text('That is not one of the choices.'), findsOneWidget);
      expect(find.byKey(const Key('alert-popup')), findsOneWidget);
    });

    testWidgets('already answered elsewhere (409) closes it', (tester) async {
      final popups = await _pumpPopup(
        tester,
        (request) async => _json({'message': 'Already answered.'}, 409),
      );
      popups.show(_context(tester), [
        popup(),
      ], formats: DisplayFormats.fallback);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('question-choice-2')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('question-send')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('alert-popup')), findsNothing);
    });
  });

  group('Communication › Alerts', () {
    testWidgets('a question needs choices or a reply box, and sends them', (
      tester,
    ) async {
      final sent = <Map<String, dynamic>>[];
      await _pumpTab(tester, (request) async {
        final path = request.url.path;
        if (path.endsWith('/alerts/recipients')) {
          return _json({
            'data': [
              {'id': 'u-1', 'name': 'Bar Cohen'},
            ],
          });
        }
        if (request.method == 'POST') {
          sent.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({
            'data': {'id': 'q-9'},
          }, 201);
        }
        return _json({'data': <Object>[]});
      });

      await tester.tap(find.byKey(const Key('alert-new')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Question'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('alert-people-search')).last,
        'Bar',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('alert-person-u-1')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('alert-message')),
        'Which job source should PPC leads get?',
      );
      await tester.pump();

      final send = find.byKey(const Key('alert-send'));
      bool ready() => tester.widget<ButtonStyleButton>(send).onPressed != null;
      expect(ready(), isFalse, reason: 'nothing to answer with yet');
      expect(find.text('Add a choice or let them reply.'), findsOneWidget);

      for (final c in ['Google PPC', 'Keep "Website"']) {
        await tester.enterText(find.byKey(const Key('alert-choice-input')), c);
        await tester.testTextInput.receiveAction(TextInputAction.done);
        await tester.pump();
      }
      expect(find.byKey(const ValueKey('alert-choice-1')), findsOneWidget);
      expect(ready(), isTrue);

      await tester.enterText(
        find.byKey(const Key('alert-context')),
        'Leads land as Website.',
      );
      await tester.tap(find.byKey(const Key('alert-allow-text')));
      await tester.pump();
      expect(find.text('Send question'), findsOneWidget);
      await tester.tap(send);
      await tester.pumpAndSettle();

      expect(sent.single, {
        'user_ids': ['u-1'],
        'message': 'Which job source should PPC leads get?',
        'kind': 'question',
        'context': 'Leads land as Website.',
        'choices': ['Google PPC', 'Keep "Website"'],
        'allow_text': true,
      });
    });

    testWidgets('a sent question says how many answered, and what each said', (
      tester,
    ) async {
      await _pumpTab(tester, (request) async {
        final path = request.url.path;
        if (path.endsWith('/alerts/q-1/replies')) {
          return _json({
            'data': {
              'id': 'q-1',
              'choices': ['Google PPC', 'Google Ads · Search'],
              'replies': [
                {
                  'user_id': 'u-1',
                  'name': 'Bar Cohen',
                  'choice': 'Google Ads · Search',
                  'text': 'We use that already',
                  'created_at': '2026-11-18T19:20:00Z',
                },
              ],
            },
          });
        }
        return _json({
          'data': [
            {
              'id': 'q-1',
              'kind': 'question',
              'message': 'Which job source should PPC leads get?',
              'answered': 1,
              'created_at': '2026-11-18T19:14:00Z',
              'recipients': [
                {'id': 'u-1', 'name': 'Bar Cohen', 'dismissed_at': null},
                {'id': 'u-2', 'name': 'Mia Lopez', 'dismissed_at': null},
              ],
            },
          ],
        });
      });

      expect(
        tester
            .widget<StatusPill>(
              find.descendant(
                of: find.byKey(const ValueKey('alert-sent-q-1')),
                matching: find.byType(StatusPill),
              ),
            )
            .label,
        'Answered by 1 of 2',
      );
      await tester.tap(find.byKey(const ValueKey('alert-sent-q-1')));
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('alert-answer-q-1-u-1')))
            .data,
        '(b) Google Ads · Search: We use that already',
      );
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('alert-answer-q-1-u-2')))
            .data,
        'Not answered yet',
      );
    });
  });

  testWidgets('the bell counts unanswered questions and answers them in its '
      'Questions tab', (tester) async {
    var answered = false;
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/alerts/questions')) {
          return _json({
            'data': [
              {
                'id': 'q-1',
                'message': 'Can you cover Saturday?',
                'context': null,
                'choices': ['Yes', 'No'],
                'allow_text': false,
                'sender': 'Mia Lopez',
                'created_at': '2026-11-18T16:02:00Z',
                'my_reply': answered
                    ? {
                        'choice': 'Yes',
                        'text': null,
                        'created_at': '2026-11-18T19:00:00Z',
                      }
                    : null,
              },
            ],
            'meta': {'unanswered': answered ? 0 : 1},
          });
        }
        if (path.endsWith('/alerts/q-1/reply')) {
          answered = true;
          return _json({
            'data': {'id': 'r-1'},
          }, 201);
        }
        return _json({
          'data': <Object>[],
          'meta': {'unread': 2},
        });
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          appBar: AppBar(actions: [NotificationBell(api: api)]),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Two unread notifications and one question waiting.
    expect(find.text('3'), findsOneWidget);
    expect(find.byTooltip('2 unread, 1 question'), findsOneWidget);

    await tester.tap(find.byType(NotificationBell));
    await tester.pumpAndSettle();
    final tab = find.text('Questions · 1');
    await tester.ensureVisible(tab);
    await tester.pumpAndSettle();
    await tester.tap(tab);
    await tester.pumpAndSettle();
    expect(find.text('Can you cover Saturday?'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('question-choice-0')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('question-send')));
    await tester.pumpAndSettle();

    expect(find.text('You: (a) Yes'), findsOneWidget);
    expect(find.byKey(const Key('question-send')), findsNothing);
  });
}

BuildContext _context(WidgetTester tester) =>
    tester.element(find.text('Underneath'));

Future<AlertPopups> _pumpPopup(
  WidgetTester tester,
  MockClientHandler handler,
) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: const Scaffold(body: Center(child: Text('Underneath'))),
    ),
  );
  return AlertPopups(ApiClient(client: MockClient(handler)));
}

Future<void> _pumpTab(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1200, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: AlertsTab(api: ApiClient(client: MockClient(handler))),
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
