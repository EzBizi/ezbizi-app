import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/token_store.dart';
import 'package:ezbizi_client/main_ezchat.dart';
import 'package:ezbizi_client/screens/login_screen.dart';
import 'package:ezbizi_client/widgets/error_note.dart';
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
        if (p.endsWith('/auth/forgot')) {
          return _json({
            'message':
                'If that address has an account, a reset link is on its way.',
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

  // Design 213 B1: Chats · You along the bottom, out of the way in a chat.
  testWidgets('signed in: Chats and You along the bottom', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((r) async {
        final p = r.url.path;
        if (p.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': const ['phone.chat.view'],
            },
          });
        }
        if (p.endsWith('/chat/pulse')) {
          return _json({
            'data': {'rev': 'r', 'typing': <String, Object>{}},
          });
        }
        if (p.endsWith('/chat/calls')) {
          return _json({
            'data': [
              {
                'id': 'k1',
                'kind': 'call',
                'mine': false,
                'sent_at': '2026-11-25T14:02:00Z',
                'meta': {'status': 'missed', 'video': false},
                'chat': {'id': 'c-1', 'type': 'direct', 'name': 'Dispatch'},
              },
            ],
          });
        }
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    // adopt() keeps the token; the sign-in tests after this one need none.
    addTearDown(TokenStore.clear);
    await tester.runAsync(() => session.adopt('tok'));
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.ezChat(Brightness.light),
        home: EzChatHome(api: api, session: session),
      ),
    );
    await tester.pump();
    expect(find.byKey(const ValueKey('ezchat-bar')), findsOneWidget);
    await tester.tap(find.text('Calls'));
    await tester.pump();
    await tester.pump();
    expect(find.byKey(const ValueKey('ezchat-calls')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('ezchat-call-k1')),
      findsOneWidget,
      reason: 'the call from GET chat/calls',
    );
    expect(find.textContaining('Missed'), findsOneWidget);
    await tester.tap(find.text('You'));
    await tester.pump();
    expect(find.byKey(const ValueKey('ezchat-you')), findsOneWidget);
    expect(find.byKey(const ValueKey('ezchat-sign-out')), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  /// Audit 11-27: EzChat's sign-out revokes the server token, not only the
  /// local copy (the push withdrawal is PushRegistration.unregister's, tested
  /// with it).
  test('sign-out tells the server', () async {
    final posts = <String>[];
    final api = ApiClient(
      client: MockClient((r) async {
        if (r.method == 'POST') posts.add(r.url.path);
        return _json({'message': 'ok'});
      }),
    );
    addTearDown(TokenStore.clear);
    await Session(api).logOut();
    expect(posts.where((p) => p.endsWith('/auth/logout')), hasLength(1));
  });

  /// Jose 11-27: EzChat signs in with email and password only; it says so,
  /// and the server hands back a chat-only token.
  testWidgets('the EzChat sign-in asks for a chat-only token', (tester) async {
    LoginScreen.chatOnly = true;
    addTearDown(() => LoginScreen.chatOnly = false);
    Object? body;
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((r) async {
        if (r.url.path.endsWith('/auth/login')) {
          body = jsonDecode(r.body);
          return _json({'message': 'no'}, 401);
        }
        return _json({'message': 'no'}, 404);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: LoginScreen(
          api: api,
          session: Session(api),
          onCreateAccount: () {},
        ),
      ),
    );
    await tester.enterText(find.byType(TextFormField).first, 'bar@example.com');
    await tester.enterText(find.byType(TextFormField).at(1), 'pw-123456');
    await tester.tap(find.byType(FilledButton).first);
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 100)),
    );
    await tester.pump();
    expect((body as Map?)?['app'], 'ezchat');
  });

  /// The Android pass of EzChat (2026-11-25): "a reset link is on its way"
  /// showed in the red error box. It is good news and reads as a note.
  testWidgets('a sent reset link is a note, not an error', (tester) async {
    await pump(tester);
    await tester.tap(find.text('Forgot password?'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, 'bar@example.com');
    await tester.tap(find.text('Send link'));
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 200)),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('login-notice')), findsOneWidget);
    expect(find.textContaining('reset link is on its way'), findsOneWidget);
    expect(find.byType(ErrorNote), findsNothing);
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
