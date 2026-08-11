import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The main dashboard (plan §18 Phase 4).
///
/// ── The second surface, which is where a panel stops being honest ─
///
/// Every widget is drawn twice in this product: on the screen it was written
/// for, and here, by a renderer that knows nothing about it. That is the whole
/// value of a registry — one computation, two surfaces — and it is also the
/// exact place a panel's carefully-worded label goes missing.
///
/// It went missing. HR's starters-and-leavers reports joining over a rolling
/// window and "no longer active" as an all-time count, because nothing records
/// the DAY somebody was deactivated. The HR tab labels the two rows apart; this
/// screen drew `title` and `total` and nothing else, so the same figure read
/// "Joined — 1" and any reader would take it for all time.
void main() {
  testWidgets('a row counted over a window carries the window here too', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('Joined in the last 30 days'), findsOneWidget);

    // And the row the app cannot date does NOT inherit it. This is the failure
    // that looks like success: a plausible number under a period it was never
    // counted over.
    expect(find.text('No longer active'), findsOneWidget);
    expect(find.textContaining('No longer active in the last'), findsNothing);
  });

  /// The window is the server's answer, not this screen's guess. A renderer
  /// that decided for itself would keep working until a third row arrived.
  testWidgets('a widget that says nothing about a period gets a bare title', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('scheduled'), findsOneWidget);
    expect(find.textContaining('scheduled in the last'), findsNothing);
  });
}

// ── driving the screen ─────────────────────────────────────────────

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1400, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: DashboardScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

/// A dashboard somebody composed: one widget that reports over a window and one
/// that does not.
MockClientHandler _handler() {
  final panels = [
    {
      'key': 'jobs.by_status',
      'label': 'Jobs by status',
      'options': <String, dynamic>{},
      'data': {
        'rows': [
          {'status': 'scheduled', 'total': 2},
        ],
      },
    },
    {
      'key': 'hr.starters_and_leavers',
      'label': 'Starting and leaving',
      'options': <String, dynamic>{},
      'data': {
        'rows': [
          {'title': 'Joined', 'total': 1},
          {'title': 'No longer active', 'total': 0},
        ],
        'total': 1,
        'days': 30,
        'period_applies_to': ['Joined'],
        'people': <Map<String, Object?>>[],
        'more_people': 0,
      },
    },
  ];

  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/dashboard')) return _json({'panels': panels});

    if (path.endsWith('/dashboard/catalogue')) {
      return _json({
        'widgets': [
          for (final panel in panels)
            {
              'key': panel['key'],
              'label': panel['label'],
              'options': <String, dynamic>{},
            },
        ],
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
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
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['hr.view', 'jobs.view'],
  'modules': [
    {'key': 'hr', 'label': 'Human Resources', 'available': true, 'reachable': true},
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
