import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/disconnect_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The one panel every integration Disconnect goes through (Jose,
/// 2026-10-21): what stops working, then a 6-digit authenticator code.
void main() {
  testWidgets('lists what stops, waits for six digits, sends the code', (
    tester,
  ) async {
    final sent = <http.Request>[];
    ApiResponse? result;
    final api = _api(sent, refuse: false);

    await _open(tester, api, (r) => result = r);

    // Features first, each marked by severity, above the counts.
    final breaks = find.byKey(const ValueKey('disconnect-feature-0'));
    final degrades = find.byKey(const ValueKey('disconnect-feature-1'));
    expect(
      find.descendant(
        of: breaks,
        matching: find.text('Jobs › Workiz: jobs stop mirroring to Workiz'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: breaks, matching: find.text('Stops')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: degrades, matching: find.text('Limited')),
      findsOneWidget,
    );
    final counts = find.byKey(
      const ValueKey('disconnect-dependent-automations'),
    );
    expect(
      tester.getTopLeft(degrades).dy,
      lessThan(tester.getTopLeft(counts).dy),
      reason: 'features sit above the counts',
    );
    expect(find.byKey(const Key('disconnect-no-dependents')), findsNothing);

    expect(find.textContaining('2 active automations'), findsOneWidget);
    expect(find.text('Push to Workiz, Names it'), findsOneWidget);
    expect(
      find.textContaining('1 scheduled post will not publish'),
      findsOneWidget,
    );

    FilledButton confirm() => tester.widget<FilledButton>(
      find.byKey(const Key('disconnect-confirm')),
    );
    expect(confirm().onPressed, isNull, reason: 'no code, no Disconnect');

    await tester.enterText(find.byKey(const Key('disconnect-code')), '12345');
    await tester.pump();
    expect(confirm().onPressed, isNull, reason: 'five digits is not a code');

    await tester.enterText(find.byKey(const Key('disconnect-code')), '123456');
    await tester.pump();
    expect(confirm().onPressed, isNotNull);

    await tester.tap(find.byKey(const Key('disconnect-confirm')));
    await tester.pumpAndSettle();

    final delete = sent.singleWhere((r) => r.method == 'DELETE');
    expect(jsonDecode(delete.body), {'code': '123456'});
    expect(result?.ok, isTrue);
    expect(find.byType(DisconnectPanel), findsNothing);
  });

  testWidgets('a refused code is said inline and the panel stays open', (
    tester,
  ) async {
    final sent = <http.Request>[];
    ApiResponse? result;

    await _open(tester, _api(sent, refuse: true), (r) => result = r);

    await tester.enterText(find.byKey(const Key('disconnect-code')), '654321');
    await tester.pump();
    await tester.tap(find.byKey(const Key('disconnect-confirm')));
    await tester.pumpAndSettle();

    expect(find.text('That code did not verify.'), findsOneWidget);
    expect(find.byType(DisconnectPanel), findsOneWidget);
    expect(result, isNull);
  });
}

ApiClient _api(List<http.Request> sent, {required bool refuse}) => ApiClient(
  client: MockClient((request) async {
    sent.add(request);
    if (request.url.path.endsWith('/dependents')) {
      return _json({
        'data': {
          'features': [
            {
              'feature': 'Jobs › Workiz: jobs stop mirroring to Workiz',
              'severity': 'breaks',
            },
            {
              'feature': 'Reports: Workiz job reports stop updating',
              'severity': 'degrades',
            },
          ],
          'dependents': [
            {
              'key': 'automations',
              'label': '2 active automations',
              'count': 2,
              'names': ['Push to Workiz', 'Names it'],
            },
            {
              'key': 'scheduled_posts',
              'label': '1 scheduled post will not publish',
              'count': 1,
            },
          ],
        },
      });
    }
    if (request.method == 'DELETE') {
      return refuse
          ? _json({
              'message': 'That code did not verify.',
              'code': 'authenticator_code_invalid',
              'errors': {
                'code': ['That code did not verify.'],
              },
            }, 422)
          : _json({
              'data': {'id': 'w-1', 'problems': []},
            });
    }
    return _json({'data': {}});
  }),
);

Future<void> _open(
  WidgetTester tester,
  ApiClient api,
  void Function(ApiResponse?) done,
) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () async => done(
              await confirmDisconnect(
                context,
                api: api,
                title: 'Disconnect Workiz?',
                explanation: 'EzBizi forgets this account.',
                dependentsPath: '/integrations/social/w-1/dependents',
                perform: (code) => api.deleteWithBody(
                  '/social/connections/w-1',
                  {'code': code},
                ),
              ),
            ),
            child: const Text('open'),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('open'));
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
