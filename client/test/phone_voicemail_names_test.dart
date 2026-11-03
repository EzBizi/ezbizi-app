import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/voicemails_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'support/fake_recording_player.dart';

/// Jose, 2026-10-09: all 148 voicemails showed only the number, and the
/// "turn on transcription" sentence repeated under every one of them.
void main() {
  testWidgets('a voicemail names its caller and the transcript note is said once', (
    tester,
  ) async {
    await _pump(tester, [
      {
        'id': 'c-1',
        'from_e164': '+15614761114',
        'voicemail': true,
        'dealt_at': null,
        'voicemail_transcript': null,
        'caller_name': 'Dana Lead',
        'caller_kind': 'lead',
        'created_at': '2026-10-08T19:03:51+00:00',
      },
      {
        'id': 'c-2',
        'from_e164': '+15613365352',
        'voicemail': true,
        'dealt_at': null,
        'voicemail_transcript': null,
        'created_at': '2026-10-08T18:00:00+00:00',
      },
    ]);

    // One meta line: name · number · time.
    expect(find.textContaining('Dana Lead', findRichText: true), findsOneWidget);
    expect(
      find.textContaining('+1 (561) 476-1114', findRichText: true),
      findsOneWidget,
    );
    expect(find.byKey(const Key('voicemail-transcript-note')), findsOneWidget);
    expect(find.textContaining('Write down voicemail'), findsOneWidget);
    expect(find.text('No transcript'), findsNWidgets(2));
  });

  testWidgets('no note when every voicemail has a transcript', (tester) async {
    await _pump(tester, [
      {
        'id': 'c-3',
        'from_e164': '+13055550100',
        'voicemail': true,
        'dealt_at': null,
        'voicemail_transcript': 'Please ring me back.',
        'created_at': '2026-09-08T10:00:00+00:00',
      },
    ]);

    expect(find.byKey(const Key('voicemail-transcript-note')), findsNothing);
  });
}

Future<void> _pump(WidgetTester tester, List<Map<String, Object?>> rows) async {
  tester.view.physicalSize = const Size(1100, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/phone/voicemail-boxes')) return _json({'data': []});
      if (path.endsWith('/phone/calls')) return _json({'data': rows});
      return _json({'data': []});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: VoicemailsPane(
          api: api,
          session: session,
          player: FakeRecordingPlayer(),
        ),
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
