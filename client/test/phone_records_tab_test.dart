import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:ezbizi_client/screens/phone/records_tab.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Records — layout 4: 25/75 list and detail, stack below 600, play control.
void main() {
  testWidgets('Records is not Coming soon', (tester) async {
    await _pump(tester, calls: const [], messages: const []);

    expect(find.byType(ComingSoonScreen), findsNothing);
    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('There are no calls or messages yet.'), findsOneWidget);
  });

  testWidgets('at 900 the list is a quarter and the detail three quarters', (
    tester,
  ) async {
    await _pump(tester, size: const Size(900, 800));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byKey(const Key('phone-records-list')), findsOneWidget);
    expect(find.byKey(const Key('phone-records-pane')), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Calls'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Voicemail'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'SMS'), findsOneWidget);

    final list = tester.getRect(find.byKey(const Key('phone-records-list')));
    final pane = tester.getRect(find.byKey(const Key('phone-records-pane')));
    expect(pane.width, closeTo(list.width * 3, 12));
    expect(find.byTooltip('Back to the list'), findsNothing);
  });

  testWidgets('at 375 the detail is the whole body, with Back', (tester) async {
    await _pump(tester, size: const Size(375, 800));

    expect(find.byKey(const Key('phone-records-list')), findsOneWidget);
    expect(find.byKey(const Key('phone-records-pane')), findsNothing);
    expect(find.textContaining('Inbound'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('phone-record-ca-1')));

    expect(find.byKey(const Key('phone-records-pane')), findsOneWidget);
    expect(find.byKey(const Key('phone-records-list')), findsNothing);
    expect(find.byTooltip('Back to the list'), findsOneWidget);
    expect(find.text('Play recording'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the list'));

    expect(find.byKey(const Key('phone-records-list')), findsOneWidget);
    expect(find.byKey(const Key('phone-records-pane')), findsNothing);
    expect(find.text('Play recording'), findsNothing);
  });

  testWidgets('Play recording is on a recorded call and not on SMS', (
    tester,
  ) async {
    await _pump(tester, size: const Size(900, 800));

    await _tap(tester, find.byKey(const Key('phone-record-ca-1')));
    expect(find.text('Play recording'), findsOneWidget);

    await _tap(tester, find.widgetWithText(ChoiceChip, 'SMS'));
    expect(find.textContaining('Thursday morning still good?'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('phone-thread-+12035550199')));
    expect(find.text('Play recording'), findsNothing);
    expect(find.textContaining('Thursday morning still good?'), findsWidgets);
  });

  testWidgets('Play fetches the recording and asks the player to play', (
    tester,
  ) async {
    final player = _FakePlayer();
    final seen = <http.Request>[];
    addTearDown(player.dispose);

    await _pump(
      tester,
      size: const Size(900, 800),
      player: player,
      seen: seen,
    );

    await _tap(tester, find.byKey(const Key('phone-record-ca-1')));

    expect(find.text('Play recording'), findsOneWidget);
    expect(find.text('The recording is playing.'), findsNothing);
    expect(player.played, isEmpty);
    expect(
      seen.where((r) => r.url.path.endsWith('/phone/calls/ca-1/recording')),
      isEmpty,
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Play recording'));

    expect(
      seen.any(
        (r) =>
            r.method == 'GET' &&
            r.url.path.endsWith('/phone/calls/ca-1/recording'),
      ),
      isTrue,
    );
    expect(player.played, isNotEmpty);
    expect(find.text('The recording is playing.'), findsOneWidget);
  });

  /// Two texts from one person are one conversation, not two strangers.
  ///
  /// The list used to be one row per MESSAGE, each headed with the same
  /// number — so somebody who sent two texts appeared twice, and the thing you
  /// open the screen to read, what has been said back and forth, was nowhere.
  testWidgets('texts from the same number are one thread, not two rows', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(1280, 900),
      messages: const [
        _sms,
        {
          'id': 'm-2',
          'number_id': 'n-1',
          'connection_id': 'pc-1',
          'direction': 'inbound',
          'from_e164': '+12035550199',
          'to_e164': '+12035550142',
          'body': 'Why are you calling',
          'created_at': '2026-08-23T16:09:00+00:00',
        },
        {
          'id': 'm-3',
          'number_id': 'n-1',
          'connection_id': 'pc-1',
          'direction': 'outbound',
          'from_e164': '+12035550142',
          'to_e164': '+13055550188',
          'body': 'On our way',
          'created_at': '2026-08-23T16:20:00+00:00',
        },
      ],
    );

    await _tap(tester, find.widgetWithText(ChoiceChip, 'SMS'));

    // Two people, three messages: two rows, not three.
    expect(find.byKey(const Key('phone-thread-+12035550199')), findsOneWidget);
    expect(find.byKey(const Key('phone-thread-+13055550188')), findsOneWidget);
    expect(
      find.byType(InkWell).evaluate().length,
      lessThan(6),
      reason: 'the list still has a row per message rather than per person',
    );

    // The row shows the LAST thing said, not the first.
    expect(find.textContaining('Why are you calling'), findsOneWidget);

    // Opening it shows both sides of the conversation.
    await _tap(tester, find.byKey(const Key('phone-thread-+12035550199')));
    expect(find.textContaining('Thursday morning still good?'), findsWidgets);
    expect(find.textContaining('Why are you calling'), findsWidgets);
  });

  /// Ours reads as ours.
  testWidgets('a thread says which side said the last thing', (tester) async {
    await _pump(
      tester,
      size: const Size(1280, 900),
      messages: const [
        {
          'id': 'm-9',
          'number_id': 'n-1',
          'connection_id': 'pc-1',
          'direction': 'outbound',
          'from_e164': '+12035550142',
          'to_e164': '+13055550188',
          'body': 'On our way',
          'created_at': '2026-08-23T16:20:00+00:00',
        },
      ],
    );

    await _tap(tester, find.widgetWithText(ChoiceChip, 'SMS'));

    expect(
      find.textContaining('You: On our way'),
      findsOneWidget,
      reason: 'the last line reads as something the customer said',
    );
  });
}

class _FakePlayer implements RecordingPlayer {
  @override
  bool get playsInApp => true;

  final played = <List<int>>[];

  @override
  Future<void> play(List<int> bytes) async {
    played.add(bytes);
  }

  @override
  void stop() {}

  @override
  void dispose() {}
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder,
    matching: find.byType(Scrollable),
  );
  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }
  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  Size size = const Size(1280, 900),
  List<Map<String, Object?>>? calls,
  List<Map<String, Object?>>? messages,
  RecordingPlayer? player,
  List<http.Request>? seen,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final inner = _handler(
    calls: calls ?? const [_call],
    messages: messages ?? const [_sms],
  );
  final api = ApiClient(
    client: MockClient((request) async {
      seen?.add(request);
      return inner(request);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: RecordsTab(api: api, session: session, player: player),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  required List<Map<String, Object?>> calls,
  required List<Map<String, Object?>> messages,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({'data': _me});
    }

    if (path.endsWith('/phone/calls') && request.method == 'GET') {
      return _json({'data': calls});
    }

    if (path.contains('/phone/calls/') && path.endsWith('/recording')) {
      return http.Response.bytes(
        const [0, 1, 2],
        200,
        headers: {'content-type': 'audio/mpeg'},
      );
    }

    if (path.endsWith('/phone/messages') && request.method == 'GET') {
      return _json({'data': messages});
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'n-1',
            'connection_id': 'pc-1',
            'e164': '+12035550142',
            'label': 'CT main',
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

const _call = {
  'id': 'ca-1',
  'number_id': 'n-1',
  'connection_id': 'pc-1',
  'direction': 'inbound',
  'from_e164': '+12035550199',
  'to_e164': '+12035550142',
  'status': 'completed',
  'duration_seconds': 252,
  'voicemail': false,
  'recording_sid': 'REaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  'customer_id': 'c-1',
  'created_at': '2026-08-24T09:14:00+00:00',
};

const _sms = {
  'id': 'm-1',
  'number_id': 'n-1',
  'connection_id': 'pc-1',
  'direction': 'inbound',
  'from_e164': '+12035550199',
  'to_e164': '+12035550142',
  'body': 'Thursday morning still good?',
  'customer_id': 'c-1',
  'created_at': '2026-08-23T16:02:00+00:00',
};

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
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
  'companies': [
    {'id': 'co-1', 'name': 'Connecticut', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
