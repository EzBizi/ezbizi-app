import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:ezbizi_client/screens/phone/records_tab.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_recording_player.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Records — layout 4: 25/75 list and detail, stack below 600, play control.
void main() {
  _blockingTests();

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
    expect(find.byKey(const Key('recording-play')), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the list'));

    expect(find.byKey(const Key('phone-records-list')), findsOneWidget);
    expect(find.byKey(const Key('phone-records-pane')), findsNothing);
    expect(find.byKey(const Key('recording-play')), findsNothing);
  });

  testWidgets('the player is on a recorded call and not on SMS', (
    tester,
  ) async {
    await _pump(tester, size: const Size(900, 800));

    await _tap(tester, find.byKey(const Key('phone-record-ca-1')));
    expect(find.byKey(const Key('recording-play')), findsOneWidget);

    await _tap(tester, find.widgetWithText(ChoiceChip, 'SMS'));
    expect(find.textContaining('Thursday morning still good?'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('phone-thread-+12035550199')));
    expect(find.byKey(const Key('recording-play')), findsNothing);
    expect(find.textContaining('Thursday morning still good?'), findsWidgets);
  });

  testWidgets('Play fetches the recording and asks the player to play', (
    tester,
  ) async {
    final player = FakeRecordingPlayer();
    final seen = <http.Request>[];
    addTearDown(player.dispose);

    await _pump(
      tester,
      size: const Size(900, 800),
      player: player,
      seen: seen,
    );

    await _tap(tester, find.byKey(const Key('phone-record-ca-1')));

    expect(find.byKey(const Key('recording-play')), findsOneWidget);
    expect(find.text('The recording is playing.'), findsNothing);
    expect(player.played, isEmpty);
    expect(
      seen.where((r) => r.url.path.endsWith('/phone/calls/ca-1/recording')),
      isEmpty,
    );

    await _tap(tester, find.byKey(const Key('recording-play')));

    expect(
      seen.any(
        (r) =>
            r.method == 'GET' &&
            r.url.path.endsWith('/phone/calls/ca-1/recording'),
      ),
      isTrue,
    );
    expect(player.played, isNotEmpty);

    // The transport says it, rather than a line of prose underneath. Pause is
    // only offered while something is playing, so its presence IS the claim —
    // and unlike the old sentence it is a control that can be acted on.
    expect(find.byTooltip('Pause'), findsOneWidget);
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

/// Blocking the caller from the call you are already looking at.
///
/// The settings screen has the same list and a box to type a number into, and
/// nobody copies a number out of a call log by hand when the call is open in
/// front of them. This is the entry point that gets used; the other one is the
/// one you go to when you want to undo it.
void _blockingTests() {
  testWidgets('the open call offers to block whoever rang', (tester) async {
    final seen = <http.Request>[];
    await _pump(tester, seen: seen);

    // The detail is empty until a call is opened, which is also the only
    // place the button belongs.
    await _tap(tester, find.byKey(const Key('phone-record-ca-1')));
    await _tap(tester, find.byKey(const Key('block-caller-ca-1')));

    final posted = seen
        .where((r) => r.method == 'POST' && r.url.path.endsWith('/phone/blocked-numbers'))
        .toList();

    expect(posted, hasLength(1), reason: 'nothing was sent');

    final body = jsonDecode(posted.first.body) as Map<String, dynamic>;

    // The CALLER, not the line they rang. Sending to_e164 would block the
    // company's own number and take the line off the air.
    expect(body['e164'], '+12035550199');
    expect(body['connection_id'], 'pc-1');

    // And it says so afterwards, rather than leaving a button that looks
    // like it did nothing.
    expect(find.textContaining('is blocked'), findsOneWidget);
  });

  /// Not on a call the company placed.
  ///
  /// Blocking the number you dialled OUT to blocks a customer from ever
  /// reaching you, from a button that reads as being about this one call.
  testWidgets('an outbound call offers no such thing', (tester) async {
    await _pump(tester, calls: [
      {..._call, 'id': 'ca-2', 'direction': 'outbound'},
    ]);

    await _tap(tester, find.byKey(const Key('phone-record-ca-2')));

    // The call IS open — the recording transport proves it — and the block
    // button is still not there. Without this the test would pass for a
    // detail pane that failed to open at all.
    expect(find.byKey(const Key('recording-play')), findsOneWidget);
    expect(find.byKey(const Key('block-caller-ca-2')), findsNothing);
  });
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

    if (path.endsWith('/phone/blocked-numbers') && request.method == 'POST') {
      return _json({'data': {'id': 'bn-1'}}, 201);
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
