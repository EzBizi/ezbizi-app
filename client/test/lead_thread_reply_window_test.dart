import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'support/fake_recording_player.dart';

/// Meta's 24-hour reply window, said in words (Jose, 2026-11-09; design 186).
void main() {
  final calls = <http.Request>[];

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  const me = {
    'id': 'u-1',
    'name': 'Pat Lin',
    'email': 'pat@example.com',
    'role': {'name': 'Manager'},
    'has_avatar': false,
    'preferences': <String, dynamic>{},
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
    'companies': <dynamic>[],
    'franchises': <dynamic>[],
    'permissions': ['leads.view', 'leads.edit'],
  };

  Future<void> pump(WidgetTester tester, Map<String, dynamic> lead) async {
    calls.clear();
    final api = ApiClient(
      client: MockClient((request) async {
        calls.add(request);
        final path = request.url.path;
        if (path.endsWith('/me')) return json({'data': me});
        if (path.endsWith('/leads/lead-1') && request.method == 'GET') {
          return json({
            'data': {
              'conversations': [
                {
                  'id': 'c-1',
                  'from': 'CONSUMER',
                  'channel': 'MESSAGE',
                  'text': 'Do you sweep chimneys?',
                  'occurred_at': '2026-11-07T12:00:00Z',
                },
              ],
              ...lead,
            },
          });
        }
        if (path.endsWith('/whatsapp-template')) {
          return json({
            'data': {'id': 'c-2'},
          }, 201);
        }
        return json({'data': <String, dynamic>{}});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.binding.setSurfaceSize(const Size(1200, 1000));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LeadThread(
            api: api,
            player: FakeRecordingPlayer(),
            lead: const {
              'id': 'lead-1',
              'contact_name': 'Saman Shafqat',
              'status': 'new',
            },
            onStatusChanged: (_) async {},
            session: session,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('an open window says how long is left above the box', (
    tester,
  ) async {
    await pump(tester, {
      'can_reply': true,
      'reply_window_ends_at': DateTime.now()
          .add(const Duration(hours: 3, minutes: 20))
          .toUtc()
          .toIso8601String(),
    });

    expect(find.text('Reply window closes in 3h'), findsOneWidget);
  });

  testWidgets('a closed window says why and shuts the box', (tester) async {
    await pump(tester, {
      'can_reply': false,
      'cannot_reply_because':
          "Instagram only allows replies within 24 hours of the person's "
          'last message. Their last message was 2 days ago; you can reply '
          'again if they write back.',
      'reply_window_ends_at': DateTime.now()
          .subtract(const Duration(days: 1))
          .toUtc()
          .toIso8601String(),
    });

    expect(
      find.textContaining('Their last message was 2 days ago'),
      findsOneWidget,
    );
    final box = tester.widget<TextField>(
      find.byKey(const ValueKey('reply-box-closed')),
    );
    expect(box.enabled, isFalse);
    expect(box.decoration?.hintText, 'Reply window closed');
    expect(find.byKey(const ValueKey('send-template')), findsNothing);
  });

  testWidgets('a closed WhatsApp window sends an approved template', (
    tester,
  ) async {
    await pump(tester, {
      'can_reply': false,
      'cannot_reply_because':
          'WhatsApp only allows free replies within 24 hours.',
      'reply_window_ends_at': DateTime.now()
          .subtract(const Duration(days: 1))
          .toUtc()
          .toIso8601String(),
      'whatsapp_templates': [
        {
          'name': 'booking_link',
          'preview': 'Hi {{1}}, book here: {{2}}',
          'parameters': 2,
        },
      ],
    });

    await tester.tap(find.byKey(const ValueKey('send-template')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('template-pick')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('booking_link').last);
    await tester.pumpAndSettle();

    final send = find.byKey(const ValueKey('template-send'));
    expect(tester.widget<FilledButton>(send).onPressed, isNull);
    await tester.enterText(
      find.byKey(const ValueKey('template-value-1')),
      'Saman',
    );
    await tester.enterText(
      find.byKey(const ValueKey('template-value-2')),
      'https://ezbizi.com/b/1',
    );
    await tester.pump();
    expect(
      find.text('Hi Saman, book here: https://ezbizi.com/b/1'),
      findsOneWidget,
    );

    await tester.tap(send);
    await tester.pumpAndSettle();

    final posted = calls.singleWhere(
      (r) => r.url.path.endsWith('/whatsapp-template'),
    );
    expect(jsonDecode(posted.body), {
      'template': 'booking_link',
      'variables': ['Saman', 'https://ezbizi.com/b/1'],
    });
    expect(find.byKey(const ValueKey('template-pick')), findsNothing);
  });
}
