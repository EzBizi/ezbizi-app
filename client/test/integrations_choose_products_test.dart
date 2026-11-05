import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/email/consent_window.dart';
import 'package:ezbizi_client/screens/settings/integrations_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 183: "Choose what to connect" before the sign-in. Jose,
/// 2026-11-05: "it asks me what connections from facebook i want to make,
/// before proceeding to the connection".
void main() {
  for (final width in [1280.0, 390.0]) {
    testWidgets('the ticks reach the sign-in at $width px', (tester) async {
      final calls = await _pump(tester, width);

      await _tap(tester, 'family-manage:facebook_page');
      expect(find.text('Choose what to connect'), findsOneWidget);

      await _tap(tester, 'choose:ads');
      await _tap(tester, 'choose-continue');

      final start = calls.singleWhere((c) => c.$1 == 'POST');
      expect(start.$2, '/api/v1/social/facebook/start');
      expect(jsonDecode(start.$3), {
        'products': ['ads'],
      });

      await tester.pumpWidget(const SizedBox());
      expect(tester.takeException(), isNull);
    });

    testWidgets('unticking removes one product at $width px', (tester) async {
      final calls = await _pump(tester, width);

      await _tap(tester, 'family-manage:facebook_page');
      await _tap(tester, 'choose:pages');
      // A two-step: nothing is sent on the untick.
      expect(calls.where((c) => c.$1 == 'DELETE'), isEmpty);
      await _tap(tester, 'choose-remove-go:pages');
      await tester.enterText(find.byType(TextField).last, '123456');
      await tester.pump();
      await tester.tap(find.widgetWithText(FilledButton, 'Disconnect').last);
      await tester.pumpAndSettle();

      final delete = calls.singleWhere((c) => c.$1 == 'DELETE');
      expect(
        delete.$2,
        '/api/v1/social/providers/facebook_page/connections?product=pages',
      );
      // What Meta could not be asked shows, as a caution line.
      expect(find.byKey(const ValueKey('choose-problem')), findsOneWidget);
    });
  }

  testWidgets('WhatsApp stays when the family goes, and problems show', (
    tester,
  ) async {
    final calls = await _pump(tester, 1280);

    await _tap(tester, 'family-disconnect:facebook_page');
    expect(find.byKey(const ValueKey('family-stays:whatsapp')), findsOneWidget);
    await _tap(tester, 'family-confirm-go:facebook_page');
    expect(find.textContaining('WhatsApp stays connected.'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, '123456');
    await tester.pump();
    await tester.tap(find.widgetWithText(FilledButton, 'Disconnect').last);
    await tester.pumpAndSettle();

    final delete = calls.singleWhere((c) => c.$1 == 'DELETE');
    // The whole family, with no product: WhatsApp's own row is not in it.
    expect(delete.$2, '/api/v1/social/providers/facebook_page/connections');
    expect(find.byKey(const ValueKey('integrations-problem')), findsOneWidget);
  });
}

Future<void> _tap(WidgetTester tester, String key) async {
  final target = find.byKey(ValueKey(key));
  await tester.ensureVisible(target);
  await tester.tap(target);
  await tester.pumpAndSettle();
}

class _Window implements ConsentWindow {
  @override
  void goTo(String url) {}
  @override
  bool get closed => false;
  @override
  void close() {}
}

Map<String, dynamic> _product(
  String key,
  String label, {
  bool connected = false,
  String start = '/social/facebook/start',
}) => {
  'key': key,
  'label': label,
  'description': 'What $label lets EzBizi do.',
  'scopes': ['${key}_scope'],
  'provider': key == 'whatsapp' ? 'whatsapp' : 'facebook_page',
  'start_path': start,
  'connected': connected,
};

Future<List<(String, String, String)>> _pump(
  WidgetTester tester,
  double width,
) async {
  tester.view.physicalSize = Size(width, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final calls = <(String, String, String)>[];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      final full = request.url.hasQuery ? '$path?${request.url.query}' : path;
      if (request.method == 'DELETE') {
        calls.add(('DELETE', full, request.body));
        return _json({
          'data': {
            'disconnected': 1,
            'problems': ['Facebook still lists this access.'],
          },
        });
      }
      if (request.method == 'POST' && path.endsWith('/start')) {
        calls.add(('POST', path, request.body));
        return _json({
          'data': {'authorization_url': 'https://fb.example/x', 'flow': 'f-1'},
        });
      }
      if (path.endsWith('/status')) {
        return _json({
          'data': {'state': 'pending'},
        });
      }
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/social/connections')) {
        return _json({
          'data': [
            _row('fb-1', 'facebook_page', 'Facebook'),
            _row('wa-1', 'whatsapp', 'WhatsApp'),
          ],
        });
      }
      if (path.endsWith('/social/providers')) {
        return _json({
          'data': [
            {
              'value': 'facebook_page',
              'label': 'Facebook',
              'connects_by': 'sign_in',
              'start_path': '/social/facebook/start',
              'permission': 'marketing.edit',
              'products': [
                _product('pages', 'Pages and posting', connected: true),
                _product('messenger', 'Messenger'),
                _product('ads', 'Ad accounts'),
                _product(
                  'whatsapp',
                  'WhatsApp',
                  connected: true,
                  start: '/social/whatsapp/start',
                ),
              ],
            },
            {
              'value': 'whatsapp',
              'label': 'WhatsApp',
              'connects_by': 'sign_in',
              'start_path': '/social/whatsapp/start',
              'permission': 'marketing.edit',
            },
          ],
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: IntegrationsScreen(
          api: api,
          session: session,
          openConsent: () => _Window(),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return calls;
}

Map<String, dynamic> _row(String id, String provider, String label) => {
  'id': id,
  'provider': provider,
  'provider_label': label,
  'display_name': 'Brightside $label',
  'scope_type': 'organization',
  'scope_id': 'org-1',
  'status': 'connected',
  'last_checked_at': DateTime.now().toUtc().toIso8601String(),
};

const _me = {
  'id': 'u-1',
  'name': 'Dana',
  'email': 'dana@brightside.example',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['marketing.view', 'marketing.edit'],
  'modules': <dynamic>[],
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
  'organization': {
    'id': 'org-1',
    'name': 'Brightside',
    'franchise_label': 'Branch',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside',
    'path': ['Brightside'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
