import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/voicemail_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-10-05: voicemail greetings "text to speech or recorded".
void main() {
  testWidgets('a voicemail greeting can be a recording', (tester) async {
    tester.view.physicalSize = const Size(1200, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final saved = <Map<String, dynamic>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/phone/audio')) {
          return _json({
            'data': {
              'name': '11111111-2222-3333-4444-555555555555.mp3',
              'url': 'https://app.test/api/v1/phone/audio/x.mp3?signature=s',
            },
          }, 201);
        }
        if (path.endsWith('/phone/voicemail-boxes/b-1') &&
            request.method == 'PATCH') {
          saved.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': {}});
        }
        if (path.endsWith('/phone/voicemail-boxes')) {
          return _json({
            'data': [
              {
                'id': 'b-1',
                'connection_id': 'pc-1',
                'name': 'Main mailbox',
                'greeting_mode': 'tts',
                'greeting_text': 'Leave a message.',
                'spoken_greeting': 'Leave a message.',
                'max_seconds': 300,
                'transcribe': false,
                'notify_emails': <String>[],
                'line_count': 1,
              },
            ],
          });
        }
        if (path.endsWith('/phone/members')) {
          return _json({
            'data': {
              'people': [
                {'id': 'u-1', 'name': 'Ana Ruiz'},
              ],
              'roles': <Object>[],
            },
          });
        }
        if (path.endsWith('/phone/ring-groups')) {
          return _json({
            'data': [
              {'id': 'g-1', 'name': 'Front desk'},
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
        return _json({'data': <Object>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: VoicemailPane(
            api: api,
            session: session,
            pickAudio: () async => const PickedBytes('hello.mp3', [1, 2, 3]),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(TextButton, 'Edit'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(
      find.byKey(const ValueKey('voicemail-recording-upload')),
    );
    await tester.tap(find.byKey(const ValueKey('voicemail-recording-upload')));
    await tester.pumpAndSettle();

    expect(
      find.text('Callers hear your recording, not the text.'),
      findsOneWidget,
    );

    // And it belongs to a team: their missed calls leave messages here.
    await tester.ensureVisible(find.byKey(const ValueKey('voicemail-owner-type')));
    await tester.tap(find.byKey(const ValueKey('voicemail-owner-type')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('A team').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('voicemail-owner')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Front desk').last);
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(saved.single['owner_type'], 'ring_group');
    expect(saved.single['owner_id'], 'g-1');
    expect(saved.single['greeting_mode'], 'audio');
    expect(saved.single['greeting_audio_url'], contains('signature='));
  });
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
  'permissions': ['phone.view', 'phone.edit', 'phone.settings.view'],
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
