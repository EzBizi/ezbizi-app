import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/flows_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// What a menu does when nobody presses a key.
///
/// Jose, 2026-09-28: the menu rang the line when it ended, and *"spam callers
/// are getting through that way"*.
void main() {
  _recordingTests();

  testWidgets('a menu can be set to end the call when nobody presses a key', (
    tester,
  ) async {
    final saved = <Map<String, dynamic>>[];
    await _pump(tester, onSave: saved.add);

    await tester.tap(find.widgetWithText(TextButton, 'Edit'));
    await tester.pumpAndSettle();

    // The stored values come back into the form, not the defaults.
    expect(find.text('7 seconds'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('flow-no-input')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('End the call').last);
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(saved, hasLength(1));
    expect(saved.single['no_input'], 'hangup');
    expect(saved.single['wait_seconds'], 7);
    expect(saved.single['replays'], 1);
  });

  // Layout A (Mia, 2026-10-02): the list is a table of name, the keys set
  // (named after where each goes), and the lines using it.
  testWidgets('the list names what each key does and flags an unused menu', (
    tester,
  ) async {
    await _pump(tester, onSave: (_) {}, extraFlow: true);

    expect(find.text('1 Message'), findsOneWidget);
    expect(find.text('On 1 line'), findsOneWidget);
    expect(find.text('1 Sales · 9 Repeat'), findsOneWidget);
    expect(find.text('Not used'), findsOneWidget);
  });
}

/// Jose, 2026-10-05: "text to speech or recorded". A recording uploaded in
/// the editor is saved with the menu, and can be swapped back for the text.
void _recordingTests() {
  testWidgets('a menu can play a recording instead of the text', (
    tester,
  ) async {
    final saved = <Map<String, dynamic>>[];
    await _pump(
      tester,
      onSave: saved.add,
      pickAudio: () async => const PickedBytes('greeting.mp3', [1, 2, 3]),
    );

    await tester.tap(find.widgetWithText(TextButton, 'Edit'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('flow-recording-upload')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Callers hear your recording'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(
      saved.single['prompt_audio'],
      '11111111-2222-3333-4444-555555555555.mp3',
    );
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required void Function(Map<String, dynamic>) onSave,
  bool extraFlow = false,
  Future<PickedBytes?> Function()? pickAudio,
}) async {
  tester.view.physicalSize = const Size(1100, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) return _json({'data': _me});

      if (path.endsWith('/phone/audio') && request.method == 'POST') {
        return _json({
          'data': {'name': '11111111-2222-3333-4444-555555555555.mp3'},
        }, 201);
      }

      if (path.endsWith('/phone/flows/f-1') && request.method == 'PATCH') {
        onSave(jsonDecode(request.body) as Map<String, dynamic>);
        return _json({'data': {}});
      }

      if (path.endsWith('/phone/flows')) {
        return _json({
          'data': [
            {
              'id': 'f-1',
              'connection_id': 'pc-1',
              'name': 'Main menu',
              'prompt': 'Press 1 for the office.',
              'wait_seconds': 7,
              'replays': 1,
              'no_input': 'ring',
              'options': [
                {'digit': '1', 'action': 'voicemail'},
              ],
              'number_ids': ['n-1'],
              'plays': true,
            },
            if (extraFlow)
              {
                'id': 'f-2',
                'connection_id': 'pc-1',
                'name': 'Garage menu',
                'prompt': 'Press 1 for sales.',
                'options': [
                  {
                    'digit': '1',
                    'action': 'ring_group',
                    'ring_group_id': 'g-1',
                  },
                  {'digit': '9', 'action': 'repeat'},
                ],
                'number_ids': <String>[],
                'plays': true,
              },
          ],
        });
      }

      if (path.endsWith('/phone/ring-groups')) {
        return _json({
          'data': [
            {'id': 'g-1', 'name': 'Sales'},
          ],
        });
      }

      if (path.endsWith('/phone/connections')) {
        return _json({
          'data': [
            {'id': 'pc-1', 'label': 'Main account'},
          ],
        });
      }

      return _json({'data': []});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FlowsPane(api: api, session: session, pickAudio: pickAudio),
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
