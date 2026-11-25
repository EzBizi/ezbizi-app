import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main_ezchat.dart';
import 'package:ezbizi_client/screens/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// EzChat, the chat companion app (Jose, 2026-11-25): sign in, then Chat.
void main() {
  setUp(() => EzBiziTheme.isEzChat = true);
  tearDown(() => EzBiziTheme.isEzChat = false);

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((r) async {
        final p = r.url.path;
        if (p.endsWith('/me')) {
          return _json({
            'data': {..._me, 'permissions': const <String>[]},
          });
        }
        if (p.endsWith('/chat/chats')) return _json({'data': <Object>[]});
        if (p.endsWith('/chat/contacts')) return _json({'data': <Object>[]});
        if (p.endsWith('/chat/pulse')) {
          return _json({
            'data': {'rev': 'r', 'typing': <String, Object>{}},
          });
        }
        if (p.endsWith('/chat/calls/ringing')) {
          return _json({'data': <Object>[]});
        }
        return _json({'message': 'no'}, 404);
      }),
    );
    await tester.pumpWidget(EzChatApp(api: api));
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 200)),
    );
    await tester.pump();
    addTearDown(() => tester.pumpWidget(const SizedBox()));
  }

  testWidgets('signed out: the EzChat sign-in, green, with no sign-up', (
    tester,
  ) async {
    await pump(tester);
    expect(find.byType(LoginScreen), findsOneWidget);
    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(app.title, 'EzChat');
    expect(app.theme!.colorScheme.primary, const Color(0xFF15803D));
  });

  testWidgets('a wordmark that says EzChat', (tester) async {
    await pump(tester);
    final mark = tester.widget<RichText>(find.byKey(EzBiziTheme.wordmarkKey));
    expect(mark.text.toPlainText(), 'EzChat');
  });
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Bar Shoval',
  'email': 'bar@example.com',
  'has_avatar': false,
  'role': {'id': 'r', 'name': 'Tech'},
  'modules': <Object>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'o',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'o',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': <Object>[],
};
