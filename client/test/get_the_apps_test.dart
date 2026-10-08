import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/widgets/get_the_apps.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Letting people know there's an app (UI queue 67, 1 and 4).
///
/// What these hold to: the Dashboard card asks only somebody on a computer
/// whom the server says has never used the phone app, and only until No
/// thanks; the phone app marks itself used; Get the apps is in the account
/// menu; a server that knows neither preference asks nobody.
void main() {
  testWidgets('the card asks once, and No thanks is kept', (tester) async {
    final server = _Server();
    final session = await _session(tester, server, _prefs());
    await tester.pumpWidget(_wrap(PhoneAppCard(session: session)));
    await tester.pumpAndSettle();

    final card = find.byKey(const Key('phone-app-card'));
    expect(card, findsOneWidget);
    expect(find.byKey(const Key('get-the-apps-qr')), findsOneWidget);
    expect(find.byKey(const Key('get-the-apps-ios')), findsOneWidget);
    expect(find.byKey(const Key('get-the-apps-android')), findsOneWidget);

    await tester.tap(find.byKey(const Key('phone-app-no-thanks')));
    await tester.pumpAndSettle();
    expect(server.saved, [
      {'apps.phone_card_dismissed': true},
    ]);
    expect(card, findsNothing);
  });

  testWidgets('in a narrow window the card drops the QR code and fits', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final session = await _session(tester, _Server(), _prefs());
    await tester.pumpWidget(_wrap(PhoneAppCard(session: session)));
    await tester.pumpAndSettle();

    // An overflow here would fail the test on its own.
    expect(find.byKey(const Key('phone-app-card')), findsOneWidget);
    expect(find.byKey(const Key('get-the-apps-qr')), findsNothing);
    expect(find.byKey(const Key('get-the-apps-ios')), findsOneWidget);
    expect(find.byKey(const Key('phone-app-no-thanks')), findsOneWidget);
  });

  testWidgets('nobody with the phone app is asked', (tester) async {
    final session = await _session(tester, _Server(), _prefs(used: true));
    await tester.pumpWidget(_wrap(PhoneAppCard(session: session)));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('phone-app-card')), findsNothing);
  });

  testWidgets('a server that does not know the preferences asks nobody', (
    tester,
  ) async {
    final session = await _session(tester, _Server(), {});
    await tester.pumpWidget(_wrap(PhoneAppCard(session: session)));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('phone-app-card')), findsNothing);
  });

  testWidgets('the phone app marks itself used, and nothing else does', (
    tester,
  ) async {
    final server = _Server();
    final session = await _session(tester, server, _prefs());

    markPhoneAppUsed(session, platform: 'windows');
    markPhoneAppUsed(session, platform: 'web');
    await tester.runAsync(() async {});
    expect(server.saved, isEmpty);

    markPhoneAppUsed(session, platform: 'android');
    await tester.runAsync(() => Future<void>.delayed(Duration.zero));
    expect(server.saved, [
      {'apps.phone_used': true},
    ]);

    // Once is enough: the next start finds it already said.
    markPhoneAppUsed(session, platform: 'ios');
    await tester.runAsync(() => Future<void>.delayed(Duration.zero));
    expect(server.saved, hasLength(1));
  });

  testWidgets('the banner is only for a phone\'s browser', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    addTearDown(() => debugDefaultTargetPlatformOverride = null);
    final session = await _session(tester, _Server(), _prefs());
    await tester.pumpWidget(_wrap(PhoneAppBanner(session: session)));
    await tester.pumpAndSettle();

    // The Android APP, not a browser: it is the app already.
    expect(find.byKey(const Key('phone-app-banner')), findsNothing);
    debugDefaultTargetPlatformOverride = null;
  });

  testWidgets('Get the apps is in the account menu, and the Dashboard shows '
      'the card', (tester) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final server = _Server();
    final session = await _session(tester, server, _prefs());
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: ListenableBuilder(
          listenable: session,
          builder: (_, _) => HomeShell(api: server.api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('phone-app-card')), findsOneWidget);

    await tester.tap(find.byType(PopupMenuButton<String>).last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('menu-get-the-apps')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('get-the-apps')), findsOneWidget);
  });
}

Map<String, dynamic> _prefs({bool used = false, bool dismissed = false}) => {
  'apps.phone_used': used,
  'apps.phone_card_dismissed': dismissed,
};

class _Server {
  final List<Map<String, dynamic>> saved = [];
  Map<String, dynamic> preferences = {};

  late final api = ApiClient(client: MockClient(handle));

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/me/preferences') && request.method == 'PATCH') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      final change = Map<String, dynamic>.from(body['preferences'] as Map);
      saved.add(change);
      preferences = {...preferences, ...change};
      return _json({'data': preferences});
    }
    if (path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-1',
          'name': 'Dana Reyes',
          'email': 'dana@example.com',
          'role': {'name': 'Manager'},
          'has_avatar': false,
          'permissions': ['dashboard.view'],
          'preferences': preferences,
          'organization': {
            'id': 'org-1',
            'name': 'Harbor Group',
            'franchise_label': 'Branch',
            'is_read_only': false,
          },
          'scope': {
            'type': 'organization',
            'id': 'org-1',
            'label': 'Harbor Group',
            'path': ['Harbor Group'],
          },
          'companies': <dynamic>[],
          'franchises': <dynamic>[],
          'modules': [
            {
              'key': 'dashboard',
              'label': 'Dashboard',
              'available': true,
              'reachable': true,
            },
          ],
        },
      });
    }
    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  }
}

Future<Session> _session(
  WidgetTester tester,
  _Server server,
  Map<String, dynamic> preferences,
) async {
  server.preferences = preferences;
  final session = Session(server.api);
  await tester.runAsync(() => session.adopt('tok'));
  return session;
}

Widget _wrap(Widget child) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(body: SingleChildScrollView(child: child)),
);

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
