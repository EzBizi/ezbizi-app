import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/a1leads_gbp_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A1Leads GBP — the window onto Rodrigo's system, and only that.
///
/// The first version of this screen drew EzBizi's own Reviews and Business
/// Profile as chips inside it. That was the wrong shape: ours live in
/// Marketing, read our own tables, and are what a Google reviewer is shown when
/// our API access arrives. This one shows A1 Leads' data and nothing of ours.
///
/// So the assertions are about what the screen says when it has no source yet,
/// which is the state it is in until the read-only grant is installed on the A1
/// Leads host — and about it never presenting that state as an empty business.
void main() {
  testWidgets('with no source it says so, rather than showing nothing', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.textContaining('A1 Leads is not connected yet'), findsOneWidget);
  });

  /// The distinction the whole screen turns on.
  ///
  /// A-1 Chimney has over thirteen thousand reviews. A tab that rendered an
  /// empty list here would say the opposite, and an empty frame would read as
  /// the app being broken. Both are worse than a sentence.
  testWidgets('it never reads as a business with no reviews', (tester) async {
    await _pump(tester);

    expect(
      find.textContaining('nothing is broken'),
      findsOneWidget,
      reason: 'an absent source and an empty business must not look the same',
    );
    expect(find.byType(ListView), findsNothing);
  });

  /// It points at where OUR data lives.
  ///
  /// Written down because the mistake this screen is a correction of was
  /// exactly this confusion: somebody reading this tab must not conclude that
  /// EzBizi's own Reviews and Business Profile moved or went away.
  testWidgets('it says our own modules are still in Marketing', (tester) async {
    await _pump(tester);

    expect(find.textContaining('are in Marketing'), findsOneWidget);
  });

  /// And it draws no chips of its own.
  ///
  /// The negative control for the shape: the previous version of this file
  /// asserted two ChoiceChips here. If chips ever come back, they are children
  /// of this module, and this module is not allowed any.
  testWidgets('it holds no chips, because it holds no modules', (tester) async {
    await _pump(tester);

    expect(find.byType(ChoiceChip), findsNothing);
  });
}

Future<void> _pump(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1400, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: A1LeadsGbpScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Future<http.Response> _handler(http.Request request) async {
  if (request.url.path.endsWith('/me')) {
    return _json({
      'data': {
        'id': 'u-1',
        'name': 'Jose Silva',
        'email': 'jose@example.com',
        'has_avatar': false,
        'role': {'id': 'role-1', 'name': 'Administrator'},
        'permissions': const ['a1leads_gbp.view'],
        'modules': [
          {
            'key': 'a1leads_gbp',
            'label': 'A1Leads GBP',
            'available': true,
            'reachable': true,
          },
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
      },
    });
  }

  return _json({'data': <dynamic>[]});
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
