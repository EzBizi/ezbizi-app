import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/alerts_tab.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Communication › Alerts (UI queue 65, Jose: "2").
///
/// What these hold to: the tab lists what was sent and who has dismissed it;
/// New alert writes one in the side panel on a desk and above the list on a
/// phone; nothing is sent without a person and a message.
void main() {
  testWidgets('each sent alert says how many have dismissed it, and who', (
    tester,
  ) async {
    await _pump(tester, _Server(), const Size(1200, 900));

    String pill(String id) => tester
        .widget<StatusPill>(
          find.descendant(
            of: find.byKey(ValueKey('alert-sent-$id')),
            matching: find.byType(StatusPill),
          ),
        )
        .label;
    expect(pill('al-2'), 'Dismissed by 1 of 3');
    expect(pill('al-1'), 'Dismissed by all 2');
    expect(
      find.textContaining('To Marco Silva, Priya Nair and Lena Ortiz'),
      findsOneWidget,
    );

    expect(find.text('Not yet'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('alert-sent-al-2')));
    await tester.pumpAndSettle();
    expect(find.text('Not yet'), findsNWidgets(2));
    expect(find.textContaining(RegExp(r'^Dismissed [0-9]')), findsOneWidget);
  });

  testWidgets('New alert writes one in the side panel, then lists it', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, const Size(1200, 900));

    await tester.tap(find.byKey(const Key('alert-new')));
    await tester.pumpAndSettle();
    final send = find.byKey(const Key('alert-send'));
    bool ready() => tester.widget<ButtonStyleButton>(send).onPressed != null;
    expect(ready(), isFalse, reason: 'nobody chosen, nothing said');

    await tester.enterText(
      find.byKey(const Key('alert-message')),
      'Take van 7 today.',
    );
    await tester.pumpAndSettle();
    expect(ready(), isFalse, reason: 'said, but to nobody');

    await tester.enterText(find.byKey(const Key('alert-people-search')), 'pri');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('alert-person-u-2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('alert-to-u-2')), findsOneWidget);
    expect(ready(), isTrue);

    await tester.enterText(find.byKey(const Key('alert-message')), '  ');
    await tester.pumpAndSettle();
    expect(ready(), isFalse, reason: 'to somebody, but nothing said');
    await tester.enterText(
      find.byKey(const Key('alert-message')),
      'Take van 7 today.',
    );
    await tester.pumpAndSettle();
    expect(ready(), isTrue);
    await tester.tap(send);
    await tester.pumpAndSettle();

    expect(server.sent.single, {
      'user_ids': ['u-2'],
      'message': 'Take van 7 today.',
    });
    expect(find.byKey(const Key('alert-form')), findsNothing);
    expect(find.byKey(const ValueKey('alert-sent-al-3')), findsOneWidget);
  });

  testWidgets('a refusal says why and keeps the form', (tester) async {
    await _pump(tester, _Server(refuse: true), const Size(1200, 900));

    await tester.tap(find.byKey(const Key('alert-new')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('alert-people-search')),
      'lena',
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('alert-person-u-3')));
    await tester.enterText(find.byKey(const Key('alert-message')), 'Hello.');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('alert-send')));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'One or more of those people is not an active user here, so nothing '
        'was sent.',
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('alert-form')), findsOneWidget);
  });

  testWidgets('on a phone the form sits above the list', (tester) async {
    await _pump(tester, _Server(), const Size(390, 844));

    expect(find.byKey(const Key('alert-new')), findsNothing);
    expect(
      tester.getTopLeft(find.byKey(const Key('alert-form'))).dy,
      lessThan(
        tester.getTopLeft(find.byKey(const ValueKey('alert-sent-al-2'))).dy,
      ),
    );
  });

  testWidgets('nothing sent yet says what an alert does', (tester) async {
    await _pump(tester, _Server(empty: true), const Size(1200, 900));

    expect(find.textContaining('No alerts sent yet.'), findsOneWidget);
  });
}

class _Server {
  _Server({this.refuse = false, this.empty = false});

  final bool refuse;
  final bool empty;
  final List<Map<String, dynamic>> sent = [];

  late final List<Map<String, dynamic>> _alerts = empty
      ? []
      : [
          {
            'id': 'al-2',
            'message': 'Storm coming in: finish outdoor jobs by 3 PM.',
            'created_at': '2026-10-07T17:12:00Z',
            'recipients': [
              {
                'id': 'u-1',
                'name': 'Marco Silva',
                'dismissed_at': '2026-10-07T17:14:00Z',
              },
              {'id': 'u-2', 'name': 'Priya Nair', 'dismissed_at': null},
              {'id': 'u-3', 'name': 'Lena Ortiz', 'dismissed_at': null},
            ],
          },
          {
            'id': 'al-1',
            'message': 'Team meeting moved to 4:30.',
            'created_at': '2026-10-06T13:40:00Z',
            'recipients': [
              {
                'id': 'u-1',
                'name': 'Marco Silva',
                'dismissed_at': '2026-10-06T13:41:00Z',
              },
              {
                'id': 'u-2',
                'name': 'Priya Nair',
                'dismissed_at': '2026-10-06T14:00:00Z',
              },
            ],
          },
        ];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (request.method == 'GET' && path.endsWith('/alerts/recipients')) {
      return _json({
        'data': [
          {'id': 'u-1', 'name': 'Marco Silva'},
          {'id': 'u-2', 'name': 'Priya Nair'},
          {'id': 'u-3', 'name': 'Lena Ortiz'},
        ],
      });
    }
    if (request.method == 'GET' && path.endsWith('/alerts')) {
      return _json({'data': _alerts});
    }
    if (request.method == 'POST' && path.endsWith('/alerts')) {
      if (refuse) {
        return _json({
          'message':
              'One or more of those people is not an active user here, so '
              'nothing was sent.',
        }, 422);
      }
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      sent.add(body);
      _alerts.insert(0, {
        'id': 'al-3',
        'message': body['message'],
        'created_at': '2026-10-07T18:00:00Z',
        'recipients': [
          for (final id in body['user_ids'] as List)
            {'id': id, 'name': 'Priya Nair', 'dismissed_at': null},
        ],
      });
      return _json({
        'data': {'id': 'al-3', 'told': 1},
      }, 201);
    }
    return _json({'message': 'not stubbed'}, 500);
  }
}

Future<void> _pump(WidgetTester tester, _Server server, Size size) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: AlertsTab(api: ApiClient(client: MockClient(server.handle))),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
