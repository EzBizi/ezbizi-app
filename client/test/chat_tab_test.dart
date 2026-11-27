import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/chat/chat_tab.dart';
import 'package:ezbizi_client/screens/phone/chat/chat_thread.dart';
import 'package:ezbizi_client/screens/phone_screen.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Chat: EzBizi's own WhatsApp between EzBizi accounts (Jose, 2026-11-25).
void main() {
  testWidgets('Chat is a Communication tab for whoever holds phone.chat.view', (
    tester,
  ) async {
    final fake = _Fake();
    await _pump(tester, fake, screen: true);
    final labels = tester.widget<TabBarLike>(find.byType(TabBarLike)).labels;
    expect(labels.map((l) => l.$1), contains('Chat'));
  });

  testWidgets(
    'desktop: list and thread side by side, a message sends with Enter-free Send and shows ticks',
    (tester) async {
      final fake = _Fake();
      await _pump(tester, fake);

      // WhatsApp's two panes: the list, and a welcome until a chat is open.
      expect(find.byKey(const ValueKey('chat-search')), findsOneWidget);
      expect(find.text('EzBizi Chat'), findsOneWidget);
      expect(find.byKey(const ValueKey('chat-unread-c-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('ticks-read')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('chat-row-c-1')));
      await tester.pumpAndSettle();
      expect(find.byType(ChatThread), findsOneWidget);
      expect(_inThread('Is the van back?'), findsOneWidget);
      expect(_inThread('online'), findsOneWidget);
      // The list is still drawn beside it.
      expect(find.byKey(const ValueKey('chat-row-c-1')), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('chat-input')),
        'Ten minutes',
      );
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('chat-send')));
      await tester.pumpAndSettle();

      expect(fake.sent.single['body'], 'Ten minutes');
      expect(_inThread('Ten minutes'), findsOneWidget);
      expect(find.byKey(const ValueKey('ticks-sent')), findsOneWidget);
    },
  );

  testWidgets('phone: one pane at a time, back returns to the list in place', (
    tester,
  ) async {
    final fake = _Fake();
    await _pump(tester, fake, size: const Size(390, 800));

    expect(find.byKey(const ValueKey('chat-new')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('chat-row-c-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('chat-row-c-1')), findsNothing);
    expect(find.text('Is the van back?'), findsOneWidget);

    await tester.tap(find.byTooltip('Back'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('chat-row-c-1')), findsOneWidget);
  });

  // Design 213 B1: a technician's hands are busy. On a phone the thread
  // offers one-tap replies and a hold-to-talk mic; a desk keeps its plain
  // composer.
  for (final phone in [true, false]) {
    testWidgets(
      'quick replies ${phone ? 'send in one tap on a phone' : 'stay off a desk'}',
      (tester) async {
        final fake = _Fake();
        await _pump(
          tester,
          fake,
          size: phone ? const Size(390, 800) : const Size(1280, 900),
        );
        await tester.tap(find.byKey(const ValueKey('chat-row-c-1')));
        await tester.pumpAndSettle();
        final onMyWay = find.byKey(const ValueKey('chat-quick-On my way'));
        expect(onMyWay, phone ? findsOneWidget : findsNothing);
        expect(find.byKey(const ValueKey('chat-mic-hold')), findsOneWidget);
        if (!phone) return;
        await tester.tap(onMyWay);
        await tester.pumpAndSettle();
        expect(fake.sent.last['body'], 'On my way');
        // Typing hides them; the field is the message.
        await tester.enterText(find.byKey(const ValueKey('chat-input')), 'Hi');
        await tester.pump();
        expect(onMyWay, findsNothing);
      },
    );
  }

  // Design 213 B1 (Chat 9820ac23a): a chat tied to a job says which, and
  // the Jobs chip keeps only those.
  for (final tied in [true, false]) {
    testWidgets(
      'a chat ${tied ? 'tied to a job shows it and stays' : 'of no job leaves'} '
      'under the Jobs chip',
      (tester) async {
        final fake = _Fake();
        fake.chats.first['job'] = tied
            ? {'id': 'j-1', 'number': 'JOB-05112', 'city': 'Hartford'}
            : null;
        await _pump(tester, fake, size: const Size(390, 800));
        expect(
          find.text('JOB-05112 · Hartford'),
          tied ? findsOneWidget : findsNothing,
        );
        await tester.tap(find.byKey(const ValueKey('chat-filter-jobs')));
        await tester.pumpAndSettle();
        expect(
          find.byKey(const ValueKey('chat-row-c-1')),
          tied ? findsOneWidget : findsNothing,
        );
      },
    );
  }

  testWidgets('Link to job: search, pick, and the chat says its job', (
    tester,
  ) async {
    final fake = _Fake()..jobsView = true;
    await _pump(tester, fake, size: const Size(390, 800));
    await tester.tap(find.byKey(const ValueKey('chat-row-c-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Menu').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('chat-menu-job')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('chat-job-picker')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('chat-job-j-1')));
    await tester.pumpAndSettle();
    expect(fake.jobLinks, ['j-1']);
    await tester.tap(find.byTooltip('Back'));
    await tester.pumpAndSettle();
    expect(find.text('JOB-05112 · Hartford'), findsOneWidget);
  });

  testWidgets('without jobs.view there is no Link to job', (tester) async {
    final fake = _Fake();
    await _pump(tester, fake, size: const Size(390, 800));
    await tester.tap(find.byKey(const ValueKey('chat-row-c-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Menu').last);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('chat-menu-job')), findsNothing);
  });

  testWidgets('a reply carries reply_to_id and the quote is drawn', (
    tester,
  ) async {
    final fake = _Fake();
    await _pump(tester, fake);
    await tester.tap(find.byKey(const ValueKey('chat-row-c-1')));
    await tester.pumpAndSettle();

    await tester.longPress(_inThread('Is the van back?'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Reply').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('chat-input')), 'Yes');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-send')));
    await tester.pumpAndSettle();

    expect(fake.sent.single['reply_to_id'], 'm-1');
  });

  testWidgets('a new group picks people, then a name, and opens', (
    tester,
  ) async {
    final fake = _Fake();
    await _pump(tester, fake);

    await tester.tap(find.byKey(const ValueKey('chat-new-desk')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('chat-new-group')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('chat-person-u-3')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('chat-group-next')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('chat-group-name')),
      'NJ crew',
    );
    await tester.tap(find.byKey(const ValueKey('chat-group-create')));
    await tester.pumpAndSettle();

    expect(fake.groups.single, {
      'name': 'NJ crew',
      'user_ids': ['u-3'],
    });
  });
}

Finder _inThread(String text) =>
    find.descendant(of: find.byType(ChatThread), matching: find.text(text));

class _Fake {
  final sent = <Map<String, dynamic>>[];
  final groups = <Map<String, dynamic>>[];
  final jobLinks = <Object?>[];
  var jobsView = false;

  final chats = <Map<String, Object?>>[
    {
      'id': 'c-1',
      'type': 'direct',
      'name': 'Ana Ruiz',
      'user_id': 'u-2',
      'member_count': 2,
      'member_names': <String>[],
      'online': true,
      'last_seen': null,
      'last': {
        'id': 'm-1',
        'kind': 'text',
        'text': 'Is the van back?',
        'mine': false,
        'sender_name': 'Ana Ruiz',
        'sent_at': DateTime.now().toUtc().toIso8601String(),
        'deleted': false,
        'status': null,
      },
      'unread': 1,
      'marked_unread': false,
      'pinned': false,
      'archived': false,
      'muted': false,
    },
    {
      'id': 'c-2',
      'type': 'direct',
      'name': 'Eve Long',
      'user_id': 'u-3',
      'member_count': 2,
      'member_names': <String>[],
      'online': false,
      'last_seen': null,
      'last': {
        'id': 'm-9',
        'kind': 'text',
        'text': 'Done',
        'mine': true,
        'sender_name': 'You',
        'sent_at': DateTime.now().toUtc().toIso8601String(),
        'deleted': false,
        'status': 'read',
      },
      'unread': 0,
      'marked_unread': false,
      'pinned': false,
      'archived': false,
      'muted': false,
    },
  ];

  Map<String, Object?> message(String id, String body, {bool mine = false}) => {
    'id': id,
    'chat_id': 'c-1',
    'kind': 'text',
    'body': body,
    'mine': mine,
    'sender_id': mine ? 'u-1' : 'u-2',
    'sender_name': mine ? 'Jose Silva' : 'Ana Ruiz',
    'sent_at': DateTime.now().toUtc().toIso8601String(),
    'edited': false,
    'deleted': false,
    'forwarded': false,
    'meta': null,
    'starred': false,
    'reactions': <Object>[],
    'status': mine ? 'sent' : null,
    'reply_to': null,
    'attachment': null,
  };

  late final thread = <Map<String, Object?>>[
    message('m-1', 'Is the van back?'),
  ];

  Future<http.Response> handle(http.Request request) async {
    if (request.url.path.endsWith('/jobs')) {
      return _json({
        'data': [
          {
            'id': 'j-1',
            'number': 'JOB-05112',
            'title': 'Chimney liner',
            'status_label': 'Scheduled',
            'customer': {'name': 'Pat Lee'},
          },
        ],
      });
    }
    if (request.url.path.endsWith('/chat/chats/c-1/job')) {
      final job = (jsonDecode(request.body) as Map)['job_id'];
      jobLinks.add(job);
      chats.first['job'] = job == null
          ? null
          : {'id': job, 'number': 'JOB-05112', 'city': 'Hartford'};
      return _json({'data': chats.first});
    }
    final path = request.url.path;
    final method = request.method;
    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          'permissions': [
            'phone.view',
            'phone.chat.view',
            if (jobsView) 'jobs.view',
          ],
        },
      });
    }
    if (path.endsWith('/chat/pulse')) {
      return _json({
        'data': {'rev': 'r1', 'typing': <String, Object>{}},
      });
    }
    if (path.endsWith('/chat/contacts')) {
      return _json({
        'data': [
          {
            'id': 'u-2',
            'name': 'Ana Ruiz',
            'email': 'ana@x.test',
            'online': true,
          },
          {
            'id': 'u-3',
            'name': 'Eve Long',
            'email': 'eve@x.test',
            'online': false,
          },
        ],
      });
    }
    if (path.endsWith('/chat/chats') && method == 'GET') {
      return _json({'data': chats});
    }
    if (path.endsWith('/chat/chats') && method == 'POST') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      groups.add(body);
      chats.add({
        ...chats.first,
        'id': 'c-9',
        'type': 'group',
        'name': body['name'],
        'user_id': null,
      });
      return _json({
        'data': {'id': 'c-9', 'type': 'group', 'name': body['name']},
      }, 201);
    }
    if (path.endsWith('/chat/chats/c-1/messages') && method == 'GET') {
      return _json({'data': thread, 'has_more': false});
    }
    if (path.endsWith('/chat/chats/c-9/messages') && method == 'GET') {
      return _json({'data': <Object>[], 'has_more': false});
    }
    if (path.endsWith('/chat/chats/c-1/messages') && method == 'POST') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      sent.add(body);
      final row = message(
        'm-${thread.length + 1}',
        '${body['body']}',
        mine: true,
      );
      thread.add(row);
      return _json({'data': row}, 201);
    }
    if (path.contains('/chat/chats/') && method == 'GET') {
      return _json({
        'data': {...chats.first, 'members': <Object>[], 'i_am_admin': false},
      });
    }
    if (path.contains('/users/') && path.endsWith('/avatar')) {
      return http.Response('', 404);
    }
    return _json({'message': 'Unexpected $method $path'}, 500);
  }
}

Future<void> _pump(
  WidgetTester tester,
  _Fake fake, {
  Size size = const Size(1280, 900),
  bool screen = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(fake.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: screen
            ? PhoneScreen(api: api, session: session)
            : ChatTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  // The pulse timer is periodic; stop the tree so the test can end.
  addTearDown(() => tester.pumpWidget(const SizedBox()));
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Staff'},
  'modules': [
    {
      'key': 'phone',
      'label': 'Communication',
      'available': true,
      'reachable': true,
    },
  ],
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
    {'id': 'co-1', 'name': 'Connecticut', 'parent_company_id': null},
  ],
};
