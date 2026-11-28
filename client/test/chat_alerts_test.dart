import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/os_notifications.dart';
import 'package:ezbizi_client/screens/phone/chat/chat_alerts.dart';
import 'package:ezbizi_client/screens/phone/chat/chat_tab.dart';
import 'package:ezbizi_client/screens/phone/chat/chat_thread.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Chat's OS notifications (Jose, 2026-11-27: "just like whatsapp"): a new
/// message from someone else, in a chat not muted and not on screen.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late List<String> shown;
  var rev = 'r0';
  var chats = <Map<String, Object?>>[];

  Map<String, Object?> chat(
    String id,
    String lastId, {
    int unread = 1,
    bool mine = false,
    bool muted = false,
    String type = 'direct',
    String name = 'Ana',
  }) => {
    'id': id,
    'type': type,
    'name': name,
    'unread': unread,
    'muted': muted,
    'last': {
      'id': lastId,
      'kind': 'text',
      'mine': mine,
      'sender_name': 'Ana',
      'text': 'Van is back',
    },
  };

  late ChatAlerts alerts;
  setUp(() async {
    shown = [];
    OsNotifications.shownForTest = shown;
    ChatAlerts.visibleChat = null;
    rev = 'r0';
    chats = [chat('c1', 'm1', unread: 0)];
    final api = ApiClient(
      client: MockClient((r) async {
        final body = r.url.path.endsWith('/chat/pulse')
            ? {
                'data': {'rev': rev},
              }
            : {'data': chats};
        return http.Response(
          jsonEncode(body),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    alerts = ChatAlerts(every: const Duration(hours: 1))..start(api);
    // start() reads once at once: what is already there, before the test moves.
    await Future<void>.delayed(const Duration(milliseconds: 50));
  });
  tearDown(() {
    alerts.stop();
    OsNotifications.shownForTest = null;
  });

  Future<void> beat(String next, List<Map<String, Object?>> list) async {
    rev = next;
    chats = list;
    await alerts.poll();
  }

  test('what is there at sign-in makes no notification', () async {
    await alerts.poll();
    expect(shown, isEmpty);
  });

  test('a new message from someone else shows, titled by the sender', () async {
    await alerts.poll();
    await beat('r1', [chat('c1', 'm2')]);
    expect(shown, ['Ana']);
    // The same pulse again shows nothing new.
    await beat('r1', [chat('c1', 'm2')]);
    expect(shown, ['Ana']);
  });

  test('mine, muted, and the chat on screen stay quiet', () async {
    await alerts.poll();
    await beat('r1', [chat('c1', 'm2', mine: true)]);
    await beat('r2', [chat('c1', 'm3', muted: true)]);
    ChatAlerts.visibleChat = 'c1';
    await beat('r3', [chat('c1', 'm4')]);
    expect(shown, isEmpty);
  });

  test('a group is titled by the group', () async {
    await alerts.poll();
    await beat('r1', [
      chat('c1', 'm1', unread: 0),
      chat('g1', 'm9', type: 'group', name: 'Dispatch'),
    ]);
    expect(shown, ['Dispatch']);
  });

  test('an @mention is highlighted, the longest name first', () {
    final theme = ThemeData();
    final spans = richText(
      'ask @Ana Maria and @Ana',
      theme,
      mentions: const ['Ana', 'Ana Maria'],
    ).cast<TextSpan>();
    final bold = [
      for (final s in spans)
        if (s.style?.fontWeight == FontWeight.w600) s.text,
    ];
    expect(bold, ['@Ana Maria', '@Ana']);
    // Without mentions, an @ is plain text.
    expect(
      richText(
        'ask @Ana',
        theme,
      ).cast<TextSpan>().every((s) => s.style == null),
      isTrue,
    );
  });

  test('a notification payload names its chat', () {
    expect(ChatTab.chatOf('chat:c-1'), 'c-1');
    expect(ChatTab.chatOf('0199-row-id'), isNull);
    expect(ChatTab.chatOf(null), isNull);
    // Answer on Android's call screen (11-27): the chat, and the call to join.
    expect(ChatTab.chatOf('chat:c-1?call=k-9'), 'c-1');
    expect(ChatTab.callOf('chat:c-1?call=k-9'), 'k-9');
    expect(ChatTab.callOf('chat:c-1'), isNull);
  });
}
