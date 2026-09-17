import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/general_pane.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// General is a COMPANY screen.
///
/// ── What it used to be ───────────────────────────────────────────
///
/// It opened with a Line picker and everything under it belonged to whichever
/// line was chosen, so "what happens when somebody rings us" had to be set
/// once per number. Seven numbers meant setting it seven times, and the eighth
/// — bought next month — silently answered differently from all of them.
///
/// The failure is invisible from inside the app: the new line has a perfect
/// settings screen, all of it empty, and empty renders as "no menu, no hours,
/// no voicemail box" rather than as "not set up". Only the caller finds out.
///
/// So these assert the two halves that matter: what the screen SAVES TO, and
/// that a single company is stated rather than offered as a choice of one.
void main() {
  testWidgets('saving writes the company account, not a phone number', (
    tester,
  ) async {
    final seen = <http.Request>[];
    final bodies = <String>[];

    await _pump(tester, seen: seen, bodies: bodies);

    // SCROLLED TO, not merely made visible.
    //
    // The pane is a ListView and Save sits under the whole form, so it starts
    // below the fold at any realistic height. `ensureVisible` was enough until
    // the form grew by one switch: a ListView only builds children near the
    // viewport, so Save stopped existing as an element at all and the call
    // threw "Bad state: No element" rather than failing an assertion.
    //
    // `scrollUntilVisible` drags the list until the finder matches, so this
    // test measures what it is about — where the save goes — instead of how
    // tall the form happens to be that week. The assertions below are
    // unchanged; only the way the button is reached is.
    final save = find.widgetWithText(FilledButton, 'Save');
    await tester.scrollUntilVisible(save, 200, scrollable: find.byType(Scrollable).first);
    await tester.pumpAndSettle();
    await tester.tap(save);
    await tester.pumpAndSettle();

    final patched = seen.where((r) => r.method == 'PATCH').toList();

    expect(patched, isNotEmpty, reason: 'nothing was saved at all');
    expect(
      patched.first.url.path,
      contains('/phone/connections/'),
      reason: 'General still edits one line rather than the company',
    );
    expect(
      patched.first.url.path,
      isNot(contains('/phone/numbers/')),
      reason: 'the per-number editor is still what Save writes to',
    );

    // And it sends the company fields, not the line ones.
    expect(bodies.first, contains('default_ring_group_id'));
    expect(bodies.first, contains('default_record'));
  });

  /// One company is a fact, not a choice.
  testWidgets('a single company is stated rather than offered', (tester) async {
    await _pump(tester);

    expect(
      find.textContaining('every line A-1 Chimney has'),
      findsOneWidget,
      reason: 'the screen never said whose settings these are',
    );
    expect(
      find.widgetWithText(SearchPickerField<String>, 'Company'),
      findsNothing,
      reason: 'a dropdown with one entry is a decision nobody is making',
    );
    expect(
      find.widgetWithText(SearchPickerField<String>, 'Line'),
      findsNothing,
      reason: 'the line picker is what made this a per-number editor',
    );
  });

  /// Two companies means a picker, because now there is something to pick.
  testWidgets('more than one company gets a picker', (tester) async {
    await _pump(tester, companies: 2);

    expect(
      find.widgetWithText(SearchPickerField<String>, 'Company'),
      findsOneWidget,
    );
  });
}

Future<void> _pump(
  WidgetTester tester, {
  List<http.Request>? seen,
  List<String>? bodies,
  int companies = 1,
}) async {
  tester.view.physicalSize = const Size(1280, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      seen?.add(request);
      if (request.method == 'PATCH') bodies?.add(request.body);

      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});

      if (path.endsWith('/phone/connections') && request.method == 'GET') {
        return _json({
          'data': [
            _connection('cx-1', 'A-1 Chimney'),
            if (companies > 1) _connection('cx-2', 'EverDoors'),
          ],
        });
      }

      if (request.method == 'PATCH') {
        return _json({'data': _connection('cx-1', 'A-1 Chimney')});
      }

      return _json({'data': const []});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: GeneralPane(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> _connection(String id, String name) => {
  'id': id,
  'scope_type': 'company',
  'scope_id': 'co-1',
  'scope_name': name,
  'kind': 'subaccount',
  'status': 'connected',
  'require_call_tag': false,
  'default_flow_id': null,
  'default_hours_schedule_id': null,
  'default_ring_group_id': null,
  'default_voicemail_box_id': null,
  'default_fallback_number': null,
  'default_recording_notice': null,
  'default_record': false,
  'default_transcribe': false,
  'default_after_hours': 'voicemail',
  'default_after_hours_number': null,
};

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
