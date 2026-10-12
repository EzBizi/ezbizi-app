import 'dart:convert';

import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/field_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Field (docs/superpowers/specs/2026-09-25-field-module-design.md): one tab
/// per grant, and the SOS button only for somebody who is tracked.
void main() {
  testWidgets('a tab nobody granted does not appear', (tester) async {
    await _pump(tester, grants: ['field.dashboard.view', 'field.route.view']);

    expect(find.text('Dashboard'), findsOneWidget);
    expect(find.text('Route'), findsOneWidget);
    expect(find.text('Map'), findsNothing);
    expect(find.text('Settings'), findsNothing);
  });

  testWidgets('the dashboard shows the counts and an open alert', (
    tester,
  ) async {
    await _pump(tester, grants: ['field.dashboard.view', 'field.manage']);

    expect(find.text('Reporting now'), findsOneWidget);
    expect(find.text('3'), findsOneWidget);
    expect(find.text('SOS · Bea Okafor'), findsOneWidget);
    expect(find.text('Acknowledge'), findsOneWidget);
    expect(
      find.widgetWithText(FilledButton, 'SOS'),
      findsNothing,
      reason: 'The viewer is not tracked, so they get no SOS button.',
    );
  });

  testWidgets('the six tiles are one size and fill the row', (tester) async {
    await _pump(tester, grants: ['field.dashboard.view']);

    final tiles = find.ancestor(
      of: find.text('Reporting now'),
      matching: find.byType(Card),
    );
    final sizes = {
      for (final label in [
        'Tracked',
        'Reporting now',
        'At a job',
        'Stopped too long',
        'Lost signal',
        'Open alerts',
      ])
        tester.getSize(
          find
              .ancestor(of: find.text(label), matching: find.byType(Card))
              .first,
        ),
    };
    expect(sizes, hasLength(1));
    // 1400 wide minus 16 padding each side: six columns, 8 between.
    expect(tester.getSize(tiles.first).width, closeTo((1368 - 40) / 6, 0.5));
  });

  testWidgets('a tapped alert notification opens that alert', (tester) async {
    await _pump(tester, grants: ['field.dashboard.view', 'field.manage']);
    bool selected() => tester
        .widget<ListTile>(
          find.ancestor(
            of: find.text('SOS · Bea Okafor'),
            matching: find.byType(ListTile),
          ),
        )
        .selected;
    expect(selected(), isFalse);

    DeepLinks.open(
      const DeepLink(module: 'email', type: 'email_message', id: 'm-1'),
    );
    await tester.pumpAndSettle();
    expect(selected(), isFalse);
    expect(DeepLinks.pending.value?.module, 'email', reason: 'Not ours: left.');

    DeepLinks.open(
      const DeepLink(module: 'field', type: 'field_alert', id: 'a-1'),
    );
    await tester.pumpAndSettle();
    expect(selected(), isTrue);
    expect(DeepLinks.pending.value, isNull, reason: 'Taken, so opened once.');
  });

  testWidgets('a tracked person gets the SOS button', (tester) async {
    await _pump(tester, grants: ['field.dashboard.view'], tracked: true);

    expect(find.widgetWithText(FilledButton, 'SOS'), findsOneWidget);
    expect(find.text('Acknowledge'), findsNothing);

    // One tap arms it inline; it never opens a dialog, and Cancel disarms.
    await tester.tap(find.widgetWithText(FilledButton, 'SOS'));
    await tester.pump();
    expect(find.text('Tap again to send SOS'), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);

    await tester.tap(find.text('Cancel'));
    await tester.pump();
    expect(find.widgetWithText(FilledButton, 'SOS'), findsOneWidget);
  });

  testWidgets('an armed SOS falls back by itself', (tester) async {
    await _pump(tester, grants: ['field.dashboard.view'], tracked: true);

    await tester.tap(find.widgetWithText(FilledButton, 'SOS'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 6));

    expect(find.text('Tap again to send SOS'), findsNothing);
  });

  testWidgets('with no tab granted it says what to ask for', (tester) async {
    await _pump(tester, grants: []);

    expect(find.textContaining('No part of Field'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<String> grants,
  bool tracked = false,
}) async {
  tester.view.physicalSize = const Size(1400, 1200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/v1/me')) {
        return _json({
          'data': _me(['field.view', ...grants]),
        });
      }
      if (path.endsWith('/field/dashboard')) {
        return _json({
          'data': {
            'counts': {
              'tracked': 5,
              'reporting': 3,
              'at_job': 1,
              'stopped': 0,
              'silent': 0,
              'open_alerts': 1,
            },
            'alerts': [
              {
                'id': 'a-1',
                'kind': 'sos',
                'user': {'id': 'u-2', 'name': 'Bea Okafor'},
                'lat': 40.0,
                'lng': -75.0,
                'created_at': '2026-09-25T14:00:00Z',
                'acknowledged_at': null,
              },
            ],
            'me': {
              'tracked': tracked,
              'should_track': tracked,
              'mode': 'clocked_in',
              'reason': tracked
                  ? 'Your location is being shared with your managers.'
                  : 'Your role is not tracked.',
            },
            'maps_configured': false,
          },
        });
      }

      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FieldScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

Map<String, Object?> _me(List<String> permissions) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Dispatcher'},
  'permissions': permissions,
  'modules': [
    {'key': 'field', 'label': 'Field', 'available': true, 'reachable': true},
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
