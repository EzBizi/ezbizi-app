import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/extensions_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Extensions screen Jose asked for on 2026-09-17.
///
/// Its subject is the DIGITS — a short number somebody published to customers
/// — which is why it lists every one of them rather than every person.
void main() {
  testWidgets('it lists what each extension reaches', (tester) async {
    await _pump(tester);

    expect(find.text('0102'), findsOneWidget);
    expect(find.text('Alice Nolan'), findsOneWidget);

    // The TEAM row is the one that matters: it is invisible to the Users tab's
    // endpoint, so a screen built on that would show the person and silently
    // omit this.
    expect(find.text('0300'), findsOneWidget);
    expect(find.text('Appointments'), findsOneWidget);
  });

  /// Digits whose target was deleted are shown as broken, not hidden.
  ///
  /// They are still published to customers. Hiding them would make the one
  /// screen that could show somebody a dead extension the screen that does
  /// not.
  testWidgets('an extension that reaches nothing says so', (tester) async {
    await _pump(tester);

    expect(find.text('0404'), findsOneWidget);
    expect(find.text('Reaches nothing'), findsOneWidget);
  });

  // Layout A (Mia, 2026-10-02): the directory comes back whole, so the tiles
  // count it here. The orphan counts in the total only: it reaches nobody.
  testWidgets('the Teams tile counts teams and filters to them', (
    tester,
  ) async {
    await _pump(tester);

    Finder count(String key, String text) => find.descendant(
      of: find.byKey(Key('extensions-tile-$key')),
      matching: find.text(text),
    );

    expect(count('all', '3'), findsOneWidget);
    expect(count('user', '1'), findsOneWidget);
    expect(count('ring_group', '1'), findsOneWidget);

    await tester.tap(find.byKey(const Key('extensions-tile-ring_group')));
    await tester.pumpAndSettle();

    expect(find.text('0300'), findsOneWidget);
    expect(find.text('0102'), findsNothing);
    expect(find.text('0404'), findsNothing);
  });

  /// Adding one sends the digits, the kind and the target together.
  testWidgets('adding an extension posts what the server needs', (
    tester,
  ) async {
    final posted = <Map<String, dynamic>>[];
    await _pump(tester, onPost: posted.add);

    await tester.enterText(find.byKey(const Key('extension-digits')), '0500');
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('extension-kind')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('A team').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('extension-target')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Appointments').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('extension-add')));
    await tester.pumpAndSettle();

    expect(posted, hasLength(1));
    expect(posted.first['extension'], '0500');
    expect(posted.first['target_kind'], 'ring_group');
    expect(posted.first['target_id'], 'g-1');
  });

  /// CHANGING THE KIND CLEARS THE TARGET.
  ///
  /// A person's id is not a team's. Keeping it would leave Add enabled against
  /// a target the list no longer shows, and the failure would arrive from the
  /// server as a validation error about a choice nobody could see.
  testWidgets('switching between a person and a team clears the choice', (
    tester,
  ) async {
    await _pump(tester);

    await tester.enterText(find.byKey(const Key('extension-digits')), '0500');
    await tester.tap(find.byKey(const Key('extension-target')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Alice Nolan').last);
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<FilledButton>(find.byKey(const Key('extension-add')))
          .onPressed,
      isNotNull,
    );

    await tester.tap(find.byKey(const Key('extension-kind')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('A team').last);
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<FilledButton>(find.byKey(const Key('extension-add')))
          .onPressed,
      isNull,
      reason: 'the person chosen before the switch was still selected',
    );
  });

  /// A refusal arrives as the server's own sentence.
  ///
  /// These name who already holds the digits, which is the thing somebody
  /// needs in order to pick different ones — "could not save" is not.
  testWidgets('a refusal shows the reason the server gave', (tester) async {
    await _pump(tester, refuse: true);

    await tester.enterText(find.byKey(const Key('extension-digits')), '0101');
    await tester.tap(find.byKey(const Key('extension-target')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Alice Nolan').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('extension-add')));
    await tester.pumpAndSettle();

    expect(
      find.text('Alice Nolan already has extension 0101.'),
      findsOneWidget,
    );
  });
}

Future<void> _pump(
  WidgetTester tester, {
  void Function(Map<String, dynamic>)? onPost,
  bool refuse = false,
}) async {
  tester.view.physicalSize = const Size(1100, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});

      if (path.endsWith('/phone/extensions/directory') &&
          request.method == 'POST') {
        onPost?.call(jsonDecode(request.body) as Map<String, dynamic>);

        if (refuse) {
          return _json({
            'message': 'The given data was invalid.',
            'errors': {
              'extension': ['Alice Nolan already has extension 0101.'],
            },
          }, 422);
        }

        return _json({'data': {}}, 201);
      }

      if (path.endsWith('/phone/extensions/directory')) {
        return _json({
          'connection_id': 'pc-1',
          'data': [
            {
              'id': 'e-1',

              // NOT `0101`, which is the add field's hint text — a fixture
              // value the screen can also produce from somewhere else makes
              // `findsOneWidget` fail for a reason that has nothing to do with
              // the list.
              'extension': '0102',
              'target_kind': 'user',
              'target_id': 'u-1',
              'target_name': 'Alice Nolan',
            },
            {
              'id': 'e-2',
              'extension': '0300',
              'target_kind': 'ring_group',
              'target_id': 'g-1',
              'target_name': 'Appointments',
            },
            {
              // Its team was deleted; the digits outlived it.
              'id': 'e-3',
              'extension': '0404',
              'target_kind': null,
              'target_id': null,
              'target_name': null,
            },
          ],
          'people': [
            {'id': 'u-1', 'name': 'Alice Nolan'},
          ],
          'ring_groups': [
            {'id': 'g-1', 'name': 'Appointments'},
          ],
        });
      }

      return _json({'message': 'Unexpected $path'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ExtensionsPane(api: api, session: session),
      ),
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
  'id': 'u-9',
  'name': 'Adeline Ruiz',
  'email': 'adeline@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
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
