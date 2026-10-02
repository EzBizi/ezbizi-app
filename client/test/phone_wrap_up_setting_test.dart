import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/tags_pane.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// After-call wrap-up: the control that turns it on.
///
/// The server side is covered in PhoneWrapUpTest, which asserts on who the
/// ring group would actually dial. This is the other half of the same
/// question: whether anybody can ever set it. A company-wide rule with no way
/// to choose it is a feature that ships switched off forever.
///
/// It lives on the Tags screen because that is the moment it is for — the
/// wrap-up is the time the tag gets picked in, and a company that insists on
/// a tag while giving nobody a second to choose one has built a dialogue
/// people dismiss.
void main() {
  _tagTiles();

  testWidgets('the current setting is what the company actually has', (
    tester,
  ) async {
    await _pump(tester, wrapUpSeconds: 60);

    final dropdown = tester.widget<SearchPickerField<int>>(
      find.byKey(const ValueKey('wrap-up-seconds')),
    );

    expect(dropdown.value, 60);

    // And it says what that means in seconds, rather than only in a menu
    // somebody has to open to find out.
    expect(find.textContaining('60 seconds after they hang up'), findsOneWidget);
  });

  testWidgets('choosing a length saves it to the company', (tester) async {
    final patched = <Map<String, dynamic>>[];
    await _pump(tester, onPatch: patched.add);

    await tester.tap(find.byKey(const ValueKey('wrap-up-seconds')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('30 seconds').last);
    await tester.pumpAndSettle();

    expect(patched, hasLength(1), reason: 'nothing was saved');
    expect(patched.first['wrap_up_seconds'], 30);
  });

  /// Off is a real choice and the one every company starts on.
  ///
  /// The control for the test above: a screen that always showed a duration
  /// would make it look mandatory, and there would be no way back once
  /// somebody had tried it.
  testWidgets('with none set the screen says the next call comes straight in', (
    tester,
  ) async {
    await _pump(tester, wrapUpSeconds: 0);

    final dropdown = tester.widget<SearchPickerField<int>>(
      find.byKey(const ValueKey('wrap-up-seconds')),
    );

    expect(dropdown.value, 0);
    expect(
      find.textContaining('the moment this one ends'),
      findsOneWidget,
    );
  });

  testWidgets('without phone.edit the setting cannot be changed', (
    tester,
  ) async {
    await _pump(tester, permissions: const ['phone.view']);

    final dropdown = tester.widget<SearchPickerField<int>>(
      find.byKey(const ValueKey('wrap-up-seconds')),
    );

    expect(dropdown.onChanged, isNull);
  });
}

// Layout A (Mia, 2026-10-02), the table under these settings: the list comes
// back whole, so the tiles count it here, and the share is of every tagged
// call, retired tags included.
void _tagTiles() {
  testWidgets('the Retired tile counts retired tags and filters to them', (
    tester,
  ) async {
    await _pump(
      tester,
      tags: [
        {'id': 'tg-1', 'name': 'Booked', 'is_active': true, 'call_count': 3},
        {'id': 'tg-2', 'name': 'Quote', 'is_active': true, 'call_count': 0},
        {'id': 'tg-3', 'name': 'Old', 'is_active': false, 'call_count': 1},
      ],
    );

    Finder count(String key, String text) => find.descendant(
      of: find.byKey(Key('tags-tile-$key')),
      matching: find.text(text),
    );

    expect(count('all', '3'), findsOneWidget);
    expect(count('on', '2'), findsOneWidget);
    expect(count('off', '1'), findsOneWidget);
    expect(find.text('75%'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tags-tile-off')));
    await tester.pumpAndSettle();

    expect(find.text('Old'), findsOneWidget);
    expect(find.text('Booked'), findsNothing);
    expect(find.widgetWithText(TextButton, 'Bring back'), findsOneWidget);
    expect(find.text('25%'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  int wrapUpSeconds = 0,
  List<Map<String, Object?>>? tags,
  void Function(Map<String, dynamic> body)? onPatch,
  List<String> permissions = const ['phone.view', 'phone.edit'],
}) async {
  tester.view.physicalSize = const Size(1280, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  var current = wrapUpSeconds;

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (request.method == 'PATCH' && path.contains('/phone/connections/')) {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        onPatch?.call(body);
        current = (body['wrap_up_seconds'] as num?)?.toInt() ?? current;

        return _json({
          'data': {'id': 'cx-1', 'require_call_tag': false, 'wrap_up_seconds': current},
        });
      }

      if (path.endsWith('/phone/connections')) {
        return _json({
          'data': [
            {
              'id': 'cx-1',
              'scope_type': 'company',
              'scope_id': 'co-1',
              'kind': 'subaccount',
              'status': 'connected',
              'require_call_tag': false,
              'wrap_up_seconds': current,
            },
          ],
        });
      }

      if (path.endsWith('/phone/tags')) {
        return _json({
          'data': tags ?? [
            {
              'id': 'tg-1',
              'name': 'Booked',
              'is_active': true,
              'color': '#3366FF',
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
      home: Scaffold(body: TagsPane(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
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
