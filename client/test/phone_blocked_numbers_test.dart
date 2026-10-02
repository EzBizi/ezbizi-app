import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/blocked_numbers_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Blocked callers: the screen that puts a number on the list.
///
/// The webhook side is covered in PhoneBlockedCallerTest, which asserts on the
/// TwiML — the only thing that decides whether a phone rings. These are about
/// whether anybody can ever get a number onto that list, which no server test
/// can see.
void main() {
  /// The empty state, because that is the state it ships in.
  ///
  /// A list screen tested only with rows stubbed in cannot notice that there
  /// is no way to add the first one. Nobody is blocked on day one, so day one
  /// is the case that has to work.
  testWidgets('with nobody blocked there is still a way to block somebody', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.byKey(const ValueKey('blocked-number')), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Block'), findsOneWidget);

    // And it says what the button does, since "Block" alone does not say that
    // the caller stops reaching every line in the company.
    expect(find.textContaining('before any phone rings'), findsOneWidget);
  });

  /// What somebody types is not what the API accepts.
  ///
  /// The endpoint takes E.164 and refuses anything else, which is right. But a
  /// person blocking a nuisance caller has `(305) 555-0911` in front of them,
  /// and being told that is invalid is being told to reformat it by hand.
  testWidgets('a number typed the way people write it is sent as E.164', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];
    await _pump(tester, onPost: sent.add);

    await tester.enterText(
      find.byKey(const ValueKey('blocked-number')),
      '(305) 555-0911',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Block'));
    await tester.pumpAndSettle();

    expect(sent, isNotEmpty, reason: 'nothing was ever sent');
    expect(sent.first['e164'], '+13055550911');
    expect(sent.first['connection_id'], 'cx-1');
  });

  /// A number that is not a number does not reach the server at all.
  ///
  /// The control for the test above. Without it, a normaliser that returned
  /// whatever it was given would pass that one — and every bad value would
  /// come back as a 422 the person has to decode.
  testWidgets('something that is not a number is refused before sending', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];
    await _pump(tester, onPost: sent.add);

    await tester.enterText(
      find.byKey(const ValueKey('blocked-number')),
      'the guy who keeps calling',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Block'));
    await tester.pumpAndSettle();

    expect(sent, isEmpty);
    expect(find.textContaining('does not look like a phone number'), findsOneWidget);
  });

  /// Reading Phone is not permission to change who it answers.
  testWidgets('without phone.edit the block button is dead', (tester) async {
    await _pump(tester, permissions: const ['phone.view']);

    final button = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Block'),
    );

    expect(button.onPressed, isNull);
  });

  /// An existing block can be lifted from the same screen.
  ///
  /// A block with no way back is a customer you cannot reach again, and the
  /// only sign of it is a call that never comes.
  testWidgets('a blocked caller can be unblocked from the list', (
    tester,
  ) async {
    final deleted = <String>[];
    await _pump(tester, rows: [_row], onDelete: deleted.add);

    expect(find.text('+13055550911'), findsOneWidget);
    // Exact cells, not a substring: the reason field's own hint text is
    // 'Robocall, four times this week', so a contains-match finds the empty
    // form as readily as the row it is meant to be about. The reason and who
    // blocked it are their own columns now (layout A, Mia, 2026-10-02).
    expect(find.text('Robocall'), findsOneWidget);
    expect(find.text('Jose Silva'), findsOneWidget);

    await tester.tap(find.widgetWithText(TextButton, 'Unblock'));
    await tester.pumpAndSettle();

    expect(deleted, ['/phone/blocked-numbers/bn-1']);
  });

  // Layout A (Mia, 2026-10-02): the list comes back whole, so the tiles count
  // it here, and "Added this month" reads the real created_at.
  testWidgets('the this-month tile counts recent blocks and filters to them', (
    tester,
  ) async {
    final now = DateTime.now().toUtc();
    await _pump(
      tester,
      rows: [
        {..._row, 'created_at': now.toIso8601String()},
        {
          ..._row,
          'id': 'bn-2',
          'e164': '+13055550922',
          'created_at': DateTime.utc(now.year - 1, now.month, 2)
              .toIso8601String(),
        },
      ],
    );

    Finder count(String key, String text) => find.descendant(
      of: find.byKey(Key('blocked-tile-$key')),
      matching: find.text(text),
    );

    expect(count('all', '2'), findsOneWidget);
    expect(count('month', '1'), findsOneWidget);

    await tester.tap(find.byKey(const Key('blocked-tile-month')));
    await tester.pumpAndSettle();

    expect(find.text('+13055550911'), findsOneWidget);
    expect(find.text('+13055550922'), findsNothing);
  });
}

const _row = {
  'id': 'bn-1',
  'connection_id': 'cx-1',
  'e164': '+13055550911',
  'reason': 'Robocall',
  'blocked_by_name': 'Jose Silva',
  'created_at': '2026-09-09T12:00:00+00:00',
};

Future<void> _pump(
  WidgetTester tester, {
  List<Map<String, dynamic>> rows = const [],
  void Function(Map<String, dynamic> body)? onPost,
  void Function(String path)? onDelete,
  List<String> permissions = const ['phone.view', 'phone.edit'],
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (request.method == 'DELETE') {
        onDelete?.call(path.replaceFirst(RegExp(r'^.*/api/v1'), ''));

        return _json({'data': null});
      }

      if (request.method == 'POST' && path.endsWith('/phone/blocked-numbers')) {
        onPost?.call(jsonDecode(request.body) as Map<String, dynamic>);

        return _json({'data': _row}, 201);
      }

      if (path.endsWith('/phone/blocked-numbers')) {
        return _json({'data': rows});
      }

      if (path.endsWith('/phone/connections')) {
        return _json({
          'data': [
            {
              'id': 'cx-1',
              'scope_type': 'company',
              'scope_id': 'co-1',
              'scope_name': 'A-1 Chimney',
              'kind': 'subaccount',
              'status': 'connected',
            },
          ],
        });
      }

      return _json({'data': {..._me, 'permissions': permissions}});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: BlockedNumbersPane(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@ezbizi.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
