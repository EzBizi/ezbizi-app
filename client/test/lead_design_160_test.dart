import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_playback_bar.dart';
import 'package:ezbizi_client/widgets/lead_media.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:ezbizi_client/widgets/leads_hidden.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'support/fake_recording_player.dart';

/// Design 160: WhatsApp phone replies and media (A1), the Hidden tab and the
/// hide menu (B1), a merged ad form (C1). Every name and number is invented.
void main() {
  final calls = <http.Request>[];

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> me(List<String> permissions) => {
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
    'companies': [
      {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
    ],
    'franchises': [
      {'id': 'f-1', 'name': 'Connecticut', 'company_id': 'co-1'},
    ],
    'permissions': permissions,
  };

  const media = 'https://api.test/api/v1/leads/lead-1/media';

  Future<Session> pumpThread(
    WidgetTester tester, {
    List<Map<String, dynamic>> conversations = const [],
    Map<String, dynamic> lead = const {},
    List<String> permissions = const ['leads.view', 'leads.edit'],
    int mediaStatus = 200,
  }) async {
    calls.clear();
    final api = ApiClient(
      client: MockClient((request) async {
        calls.add(request);
        final path = request.url.path;
        if (path.endsWith('/me')) return json({'data': me(permissions)});
        if (path.contains('/media/')) {
          return http.Response.bytes([0x49, 0x44, 0x33, 1, 2], mediaStatus);
        }
        if (path.endsWith('/leads/lead-1') && request.method == 'GET') {
          return json({
            'data': {
              'conversations': conversations,
              'can_reply': false,
              ...lead,
            },
          });
        }
        if (path.endsWith('/hide')) {
          return json({
            'data': {'ok': true},
          });
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
              'contact_name': 'Dana Reyes',
              'phone': '+15550100142',
              'status': 'new',
            },
            onStatusChanged: (_) async {},
            session: session,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return session;
  }

  Map<String, dynamic> out(
    String id, {
    Object? flag,
    String? by,
    List<String>? att,
    String text = 'On my way',
  }) => {
    'id': id,
    'from': 'ADVERTISER',
    'channel': 'MESSAGE',
    'text': text,
    'occurred_at': '2026-10-28T12:43:00Z',
    'sent_from_phone': ?flag,
    'sent_by': ?by,
    'attachments': ?att,
  };

  group('A1 WhatsApp', () {
    testWidgets('a phone reply is marked by the flag', (tester) async {
      await pumpThread(tester, conversations: [out('c1', flag: true)]);
      expect(find.byKey(const ValueKey('sent-from-phone-c1')), findsOneWidget);
      expect(find.textContaining('Sent from phone ·'), findsOneWidget);
    });

    testWidgets('and by the sender string before the flag', (tester) async {
      await pumpThread(
        tester,
        conversations: [out('c1', by: 'Phone (WhatsApp Business app)')],
      );
      expect(find.byKey(const ValueKey('sent-from-phone-c1')), findsOneWidget);
    });

    testWidgets('an ordinary reply is not', (tester) async {
      await pumpThread(tester, conversations: [out('c1', by: 'Pat Lin')]);
      expect(find.byKey(const ValueKey('sent-from-phone-c1')), findsNothing);
    });

    testWidgets('each media kind draws its own widget', (tester) async {
      await pumpThread(
        tester,
        conversations: [
          {
            'id': 'c2',
            'from': 'CUSTOMER',
            'channel': 'MESSAGE',
            'text': 'Here it is',
            'occurred_at': '2026-10-28T12:40:00Z',
            'attachments': [
              '$media/a?kind=voice',
              '$media/b?kind=video',
              '$media/c?kind=document',
            ],
          },
          out('c3', att: ['$media/d?kind=image', '$media/e?kind=sticker']),
        ],
      );
      expect(find.byKey(const ValueKey('media-audio')), findsOneWidget);
      expect(find.byType(RecordingPlaybackBar), findsOneWidget);
      expect(find.byKey(const ValueKey('media-video')), findsOneWidget);
      expect(find.byKey(const ValueKey('media-document')), findsOneWidget);
      expect(find.text('Document'), findsOneWidget);
      expect(find.text('Here it is'), findsOneWidget, reason: 'the caption');
    });

    testWidgets('an image and a sticker draw thumbnails that open full size', (
      tester,
    ) async {
      var expired = 0;
      Widget one(String kind) => LeadMedia(
        url: '$media/d?kind=$kind',
        kind: kind,
        api: ApiClient(client: MockClient((_) async => http.Response('', 200))),
        player: FakeRecordingPlayer(),
        ink: Colors.black,
        onExpired: () async => expired++,
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: Column(children: [one('image'), one('sticker')]),
          ),
        ),
      );
      expect(find.byKey(const ValueKey('media-image')), findsOneWidget);
      expect(find.byKey(const ValueKey('media-sticker')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('media-image')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byKey(const ValueKey('media-full')), findsOneWidget);
    });

    testWidgets('an image that will not load re-reads once, then is gone', (
      tester,
    ) async {
      await pumpThread(
        tester,
        conversations: [
          out('c3', att: ['$media/d?kind=image']),
        ],
      );
      expect(find.text('No longer available from WhatsApp'), findsOneWidget);
      expect(
        calls.where((r) => r.url.path.endsWith('/leads/lead-1')).length,
        2,
        reason: 'the first read and one re-read',
      );
    });

    testWidgets('a refused voice note re-reads once, then says it is gone', (
      tester,
    ) async {
      await pumpThread(
        tester,
        mediaStatus: 403,
        conversations: [
          {
            'id': 'c2',
            'from': 'CUSTOMER',
            'channel': 'MESSAGE',
            'occurred_at': '2026-10-28T12:40:00Z',
            'attachments': ['$media/a?kind=voice'],
          },
        ],
      );
      final reads = calls
          .where((r) => r.url.path.endsWith('/leads/lead-1'))
          .length;
      await tester.tap(find.byTooltip('Play'));
      await tester.pumpAndSettle();
      expect(find.text('No longer available from WhatsApp'), findsOneWidget);
      expect(
        calls.where((r) => r.url.path.endsWith('/leads/lead-1')).length,
        reads + 1,
        reason: 'one re-read of the thread',
      );
      expect(calls.where((r) => r.url.path.contains('/media/')).length, 2);
    });
  });

  group('B1 hide menu', () {
    testWidgets('absent without the tick', (tester) async {
      await pumpThread(tester);
      expect(find.byKey(const ValueKey('lead-more')), findsNothing);
    });

    testWidgets('always hide posts the reason', (tester) async {
      await pumpThread(
        tester,
        permissions: const ['leads.view', 'leads.edit', leadsHiddenPermission],
      );
      await tester.tap(find.byKey(const ValueKey('lead-more')));
      await tester.pumpAndSettle();
      expect(find.text('Hide this conversation'), findsOneWidget);
      await tester.tap(find.text('Always hide this number…'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('always-hide-reason')),
        'Supplier, not a lead',
      );
      await tester.tap(find.byKey(const ValueKey('always-hide-save')));
      await tester.pumpAndSettle();
      final post = calls.singleWhere(
        (r) => r.url.path.endsWith('/lead-1/hide'),
      );
      expect(jsonDecode(post.body), {
        'always': true,
        'reason': 'Supplier, not a lead',
      });
    });
  });

  group('C1 form', () {
    testWidgets('answers and the system line', (tester) async {
      await pumpThread(
        tester,
        conversations: [
          {
            'id': 'c1',
            'from': 'CUSTOMER',
            'channel': 'MESSAGE',
            'text': 'Any openings?',
            'occurred_at': '2026-10-28T13:10:00Z',
          },
        ],
        lead: {
          'form_name': 'Free quote',
          'form_submitted_at': '2026-10-28T13:00:00Z',
          'form_answers': [
            {'label': 'ZIP', 'value': '62701'},
          ],
        },
      );
      expect(find.text('Form answers · Free quote'), findsOneWidget);
      expect(find.text('62701'), findsOneWidget);
      expect(
        find.text('Dana Reyes sent the form “Free quote”'),
        findsOneWidget,
      );
    });

    testWidgets('no name: the plain header and "sent a form"', (tester) async {
      await pumpThread(
        tester,
        conversations: [
          {
            'id': 'c1',
            'from': 'CUSTOMER',
            'channel': 'MESSAGE',
            'text': 'Hi',
            'occurred_at': '2026-10-28T13:10:00Z',
          },
        ],
        lead: {
          'form_name': null,
          'form_submitted_at': '2026-10-28T13:00:00Z',
          'form_answers': [
            {'label': 'ZIP', 'value': '62701'},
          ],
        },
      );
      expect(find.text('Form answers'), findsOneWidget);
      expect(find.text('Dana Reyes sent a form'), findsOneWidget);
    });

    testWidgets('absent fields draw nothing', (tester) async {
      await pumpThread(tester, conversations: [out('c1')]);
      expect(find.byKey(const ValueKey('form-answers')), findsNothing);
      expect(find.byKey(const ValueKey('form-system-line')), findsNothing);
    });
  });

  group('B1 Hidden tab', () {
    Future<void> pumpHidden(
      WidgetTester tester, {
      Size size = const Size(1200, 800),
    }) async {
      calls.clear();
      final api = ApiClient(
        client: MockClient((request) async {
          calls.add(request);
          if (request.url.path.endsWith('/leads/hidden')) {
            return json({
              'data': {
                'leads': [
                  {
                    'id': 'l-9',
                    'source': 'whatsapp',
                    'contact_name': 'Sam Ortiz',
                    'phone': '+15550100150',
                    'hidden_at': '2026-10-27T15:00:00Z',
                    'always': false,
                    'hidden_by': {'name': 'Pat Lin'},
                    'reason': null,
                  },
                ],
                'numbers': [
                  {
                    'id': 'n-3',
                    'digits': '(555) 010-0199',
                    'leads': 2,
                    'hidden_at': '2026-10-28T15:00:00Z',
                    'hidden_by': {'name': 'Pat Lin'},
                    'reason': 'Supplier, not a lead',
                  },
                ],
              },
            });
          }
          return json({'data': <String, dynamic>{}});
        }),
      );
      await tester.binding.setSurfaceSize(size);
      addTearDown(() => tester.binding.setSurfaceSize(null));
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(body: LeadsHidden(api: api)),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('lists both kinds, filters, unhides and stops hiding', (
      tester,
    ) async {
      await pumpHidden(tester);
      expect(find.text('Sam Ortiz'), findsOneWidget);
      expect(find.text('Supplier, not a lead'), findsOneWidget);
      expect(find.text('Pat Lin · Oct 28'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('hidden-chip-numbers')));
      await tester.pumpAndSettle();
      expect(find.text('Sam Ortiz'), findsNothing);
      await tester.tap(find.byKey(const ValueKey('hidden-chip-all')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('unhide-l-9')));
      await tester.pumpAndSettle();
      expect(
        calls.where(
          (r) => r.method == 'POST' && r.url.path.endsWith('/leads/l-9/unhide'),
        ),
        hasLength(1),
      );
      await tester.tap(find.byKey(const ValueKey('stop-hiding-n-3')));
      await tester.pumpAndSettle();
      expect(
        calls.where(
          (r) =>
              r.method == 'DELETE' &&
              r.url.path.endsWith('/leads/hidden-numbers/n-3'),
        ),
        hasLength(1),
      );
    });

    testWidgets('search sends q', (tester) async {
      await pumpHidden(tester);
      await tester.enterText(
        find.byKey(const ValueKey('hidden-search')),
        '0199',
      );
      await tester.pumpAndSettle();
      expect(calls.last.url.queryParameters['q'], '0199');
    });

    testWidgets('hide a number posts phone and reason', (tester) async {
      await pumpHidden(tester);
      await tester.tap(find.byKey(const ValueKey('hide-number-open')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('hide-number-phone')),
        '5550100177',
      );
      await tester.enterText(
        find.byKey(const ValueKey('hide-number-reason')),
        'Vendor',
      );
      await tester.tap(find.byKey(const ValueKey('hide-number-save')));
      await tester.pumpAndSettle();
      final post = calls.singleWhere(
        (r) => r.url.path.endsWith('/leads/hidden-numbers'),
      );
      expect(jsonDecode(post.body), {
        'phone': '5550100177',
        'reason': 'Vendor',
      });
    });

    testWidgets('on a phone the rows are cards', (tester) async {
      await pumpHidden(tester, size: const Size(390, 844));
      expect(find.byType(Card), findsNWidgets(2));
      expect(tester.takeException(), isNull);
    });
  });
}
