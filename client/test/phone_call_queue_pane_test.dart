import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/ring_groups_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Choosing to hold callers, and what gets written down when you do.
///
/// ── What this is for ─────────────────────────────────────────────
///
/// Jose asked for RingCentral's call queue: "if everyone is on calls, it
/// leaves callers waiting on a quee, so they don't go straight to voicemail",
/// with "you are 3rd in the quee" said out loud.
///
/// The TwiML half is covered in `tests/Feature/Phone/PhoneCallQueueTest.php`.
/// This is the half a person touches, and it is the half that usually goes
/// untested: a setting nobody can reach is indistinguishable from one that
/// does not exist, and the queue settings are drawn only when the queue option
/// is the one selected.
void main() {
  testWidgets('choosing a queue reveals what the caller will hear', (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.text('Edit').first);
    await tester.pumpAndSettle();

    // Not there for the other three answers — this is the control. Without
    // it the test passes on a pane that draws the queue settings always.
    expect(find.text('Say their place in the line'), findsNothing);

    await tester.tap(find.text('Hold the caller in a queue'));
    await tester.pumpAndSettle();

    expect(find.text('Say their place in the line'), findsOneWidget);
    expect(find.text('Hold for 5 minutes before taking a voicemail'), findsOneWidget);
  });

  /// And the estimate is off until somebody asks for it.
  ///
  /// It is computed from how long the people currently waiting have waited,
  /// which on a queue of two is a guess. Defaulting it on would have every
  /// caller told a number the moment the feature ships.
  testWidgets('the wait estimate is off by default', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('Edit').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Hold the caller in a queue'));
    await tester.pumpAndSettle();

    final position = tester.widget<SwitchListTile>(
      find.ancestor(
        of: find.text('Say their place in the line'),
        matching: find.byType(SwitchListTile),
      ),
    );
    final estimate = tester.widget<SwitchListTile>(
      find.ancestor(
        of: find.text('Say how long the wait is likely to be'),
        matching: find.byType(SwitchListTile),
      ),
    );

    expect(position.value, isTrue);
    expect(estimate.value, isFalse);
  });

  /// Saving sends the queue settings, and saving something else does not.
  ///
  /// The second half is the point. A hold time left over from ten seconds
  /// spent looking at the queue option is not a decision, and writing it back
  /// on every save makes the stored value read as one.
  testWidgets('queue settings are sent only when the queue is chosen', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];
    await _pump(tester, sent: sent);

    await tester.tap(find.text('Edit').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Hold the caller in a queue'));
    await tester.pumpAndSettle();
    // Scrolled to: the tiles over the list push the editor further down
    // (tiles over a table, Mia 2026-10-02).
    await tester.ensureVisible(find.text('Save'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(sent, isNotEmpty);
    expect(sent.last['overflow'], 'queue');
    expect(sent.last['queue_max_seconds'], 300);
    expect(sent.last['queue_announce_position'], true);

    sent.clear();

    await tester.tap(find.text('Edit').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Take a voicemail'));
    await tester.pumpAndSettle();
    // Scrolled to: the tiles over the list push the editor further down
    // (tiles over a table, Mia 2026-10-02).
    await tester.ensureVisible(find.text('Save'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(sent, isNotEmpty);
    expect(
      sent.last.containsKey('queue_max_seconds'),
      isFalse,
      reason: 'a group that takes voicemails wrote down a hold time, which '
          'reads afterwards as somebody having chosen one',
    );
  });
}

Future<void> _pump(WidgetTester tester, {List<Map<String, dynamic>>? sent}) async {
  tester.view.physicalSize = const Size(1200, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (request.method == 'PATCH' || request.method == 'POST') {
        if (path.contains('/phone/ring-groups')) {
          sent?.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': _group});
        }
      }

      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/phone/ring-groups')) return _json({'data': [_group]});
      if (path.endsWith('/phone/members')) {
        return _json({
          'data': {'people': <dynamic>[], 'roles': <dynamic>[]},
        });
      }
      if (path.endsWith('/phone/numbers')) return _json({'data': <dynamic>[]});
      if (path.endsWith('/phone/connections')) {
        return _json({
          'data': [
            {'id': 'pc-1', 'label': 'Main', 'scope_type': 'organization'},
          ],
        });
      }

      return _json({'data': <dynamic>[]});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: RingGroupsPane(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

const _group = {
  'id': 'rg-1',
  'connection_id': 'pc-1',
  'name': 'Front desk',
  'mode': 'simultaneous',
  'timeout_seconds': 30,
  'member_user_ids': <String>[],
  'member_role_ids': <String>[],
  'overflow': 'voicemail',
  'overflow_group_id': null,
  'overflow_number': null,
  'queue_max_seconds': 300,
  'queue_announce_position': true,
  'queue_announce_wait': false,
  'queue_hold_music_url': null,
  'members': <dynamic>[],
  'members_measured_at': '2026-09-14T17:00:00+00:00',
};

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {
      'key': 'phone',
      'label': 'Communication',
      'available': true,
      'reachable': true,
      'parent': null,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {'id': 'org-1', 'name': 'Everdoors'},
};
