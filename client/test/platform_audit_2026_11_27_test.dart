import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/token_store.dart';
import 'package:ezbizi_client/screens/login_screen.dart';
import 'package:ezbizi_client/widgets/sms_codes_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The platform findings of the 2026-11-27 audit that had no module owner:
/// sign-in, the session, and the API client underneath every screen.
void main() {
  http.Response json(Object body, int status) => http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json'},
  );

  Future<void> launchWith(Map<String, String> keychain) async {
    FlutterSecureStorage.setMockInitialValues(Map.of(keychain));
    TokenStore.debugReset();
    await TokenStore.init();
  }

  // ── sign-in ────────────────────────────────────────────────────

  testWidgets('choosing a code-protected organization asks for the code', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient((request) async {
        if (!request.url.path.endsWith('/auth/login')) return json({}, 404);
        return json({
          'status': 'choose_organization',
          'choices': [
            {
              'user_id': 'u-1',
              'organization_name': 'Alpha',
              'needs_code': false,
            },
            {
              'user_id': 'u-2',
              'organization_name': 'Bravo',
              'needs_code': true,
            },
          ],
        }, 200);
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
    await tester.enterText(
      find.widgetWithText(TextField, 'Email address'),
      'dana@example.com',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Password'),
      'a-password',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
    await tester.pumpAndSettle();

    expect(find.text('Choose an organization'), findsOneWidget);

    await tester.tap(find.text('Bravo'));
    await tester.pumpAndSettle();

    expect(
      find.widgetWithText(TextField, 'Authenticator code'),
      findsOneWidget,
      reason: 'the picker stayed up and the code step never appeared',
    );
  });

  // ── text codes card ────────────────────────────────────────────

  Future<void> pumpSmsCard(
    WidgetTester tester, {
    required Map<String, Object?> state,
    required http.Response Function(http.Request) onWrite,
  }) async {
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.method == 'GET' &&
            request.url.path.endsWith('/me/sms-codes')) {
          return json({'data': state}, 200);
        }
        if (request.url.path.contains('/me/sms-codes')) return onWrite(request);
        return json({'data': _me}, 200);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(900, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: SmsCodesCard(api: api, session: session),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('a refused confirm stays on screen after the reload', (
    tester,
  ) async {
    await pumpSmsCard(
      tester,
      state: {
        'available': true,
        'number': '••• ••• 0199',
        'consented': true,
        'confirmed': false,
        'pending': true,
      },
      onWrite: (_) => json({'message': 'That code did not match.'}, 422),
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Code from the message'),
      '000000',
    );
    await tester.tap(find.text('Confirm my number'));
    await tester.pumpAndSettle();

    expect(find.textContaining('That code did not match.'), findsOneWidget);
  });

  testWidgets('a failed turn-off does not say it is off', (tester) async {
    await pumpSmsCard(
      tester,
      state: {
        'available': true,
        'number': '••• ••• 0199',
        'consented': true,
        'confirmed': true,
        'pending': false,
      },
      onWrite: (_) => json({'message': 'Something went wrong.'}, 500),
    );

    await tester.tap(find.text('Turn off text codes'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Turned off'), findsNothing);
    expect(find.textContaining('Something went wrong.'), findsOneWidget);
  });

  // ── links ──────────────────────────────────────────────────────

  test('mailto and tel are never paths inside the app', () {
    expect(DeepLinks.appPath('mailto:dana@example.com'), isNull);
    expect(DeepLinks.appPath('tel:+13055550199'), isNull);
    expect(
      DeepLinks.appPath(
        'https://app.ezbizi.com/app/#/customer_experience/complaints/c-1',
      ),
      '/customer_experience/complaints/c-1',
    );
  });

  // ── session ────────────────────────────────────────────────────

  test('signing out revokes the token on the server', () async {
    await launchWith({});
    final revoked = <String?>[];
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/auth/logout')) {
          revoked.add(request.headers['Authorization']);
          return json({'message': 'Signed out.'}, 200);
        }
        return json({'data': _me}, 200);
      }),
    );
    final session = Session(api);
    expect(await session.adopt('tok-1'), isTrue);

    session.signOut();
    await Future<void>.delayed(Duration.zero);

    expect(revoked, ['Bearer tok-1']);
    expect(session.signedIn, isFalse);
  });

  test(
    'an unreachable server at launch keeps the session and says so',
    () async {
      await launchWith({'ezbizi.token': 'tok-kept'});
      var up = false;
      final api = ApiClient(
        client: MockClient((request) async {
          if (!up) throw const SocketExceptionLike();
          return json({'data': _me}, 200);
        }),
      );
      final session = Session(api);

      await session.restore();

      expect(session.signedIn, isFalse);
      expect(session.waitingForServer, isTrue);
      expect(
        TokenStore.tenantToken,
        'tok-kept',
        reason: 'the kept token was thrown away',
      );

      up = true;
      await session.restore();

      expect(session.signedIn, isTrue);
      expect(session.waitingForServer, isFalse);
    },
  );

  test('a /me asked before a preference change cannot undo it', () async {
    await launchWith({});
    final slowMe = Completer<http.Response>();
    var meCalls = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/me')) {
          meCalls++;
          if (meCalls == 2) return slowMe.future; // the in-flight one
          return json({'data': _me}, 200);
        }
        return json({'data': <String, Object?>{}}, 200);
      }),
    );
    final session = Session(api);
    await session.adopt('tok-1');

    final stale = session.refresh();
    await session.setPreference('theme', 'dark');
    slowMe.complete(json({'data': _me}, 200)); // answered before the change
    await stale;

    expect(session.me!.preferences['theme'], 'dark');
  });

  test(
    'a staff session survives a server error and ends only on 401',
    () async {
      await launchWith({'ezbizi.staff_token': 'staff-tok'});
      var status = 502;
      final api = ApiClient(
        client: MockClient((request) async {
          if (status != 200) {
            return http.Response('<html>Bad gateway</html>', status);
          }
          return json({
            'data': {
              'id': 's-1',
              'name': 'Jose',
              'email': 'jose@ezbizi.test',
              'tier': 'lead',
              'may_enter_without_approval': true,
              'recovery_code_count': 8,
            },
          }, 200);
        }),
      );
      final staff = StaffSession(api);

      await staff.restore();
      expect(
        TokenStore.staffToken,
        'staff-tok',
        reason: 'a 502 deleted the staff token',
      );
      expect(api.hasToken, isTrue);

      status = 401;
      await staff.refresh();
      expect(api.hasToken, isFalse);
    },
  );

  // ── API client ─────────────────────────────────────────────────

  test('a wrong typed password is not a dead session', () async {
    var ended = 0;
    final api =
        ApiClient(
            client: MockClient(
              (request) async => json({
                'message':
                    'That password does not match, so nothing has been deleted.',
                'code': 'invalid_credentials',
              }, 401),
            ),
          )
          ..setToken('tok-1')
          ..onUnauthorized = () => ended++;

    final response = await api.deleteWithBody('/me', {
      'password': 'x',
      'confirm': 'DELETE',
    });

    expect(response.statusCode, 401);
    expect(ended, 0, reason: 'a mistyped password signed somebody out');

    final lapsed =
        ApiClient(
            client: MockClient(
              (request) async => json({'message': 'Unauthenticated.'}, 401),
            ),
          )
          ..setToken('tok-2')
          ..onUnauthorized = () => ended++;
    await lapsed.get('/me');
    expect(ended, 1);
  });

  test('an upload refused by a 502 page keeps its status', () async {
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response('<html>502 Bad Gateway</html>', 502),
      ),
    );

    final response = await api.postFile('/attachments', 'file', 'a.pdf', [
      1,
      2,
      3,
    ]);

    expect(response.statusCode, 502);
    expect(response.message, isNot(contains('Check your connection')));
  });
}

/// What a dropped connection looks like to the http client.
class SocketExceptionLike implements Exception {
  const SocketExceptionLike();
}

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@ezbizi.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': <String>[],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'EzBizi',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'EzBizi',
    'path': ['EzBizi'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
