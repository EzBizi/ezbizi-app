import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:ezbizi_client/screens/phone/records_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_recording_player.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Records — tiles over a table (layout A), the detail beside it once a row
/// is open, the detail alone below 600, play control.
void main() {
  _blockingTests();
  _tileTests();

  // Desk 11-13: every open pulled the whole history (2.27 MB of texts).
  testWidgets('Records asks a page at a time and Load older asks the next', (
    tester,
  ) async {
    final seen = <http.Request>[];
    await _pump(
      tester,
      seen: seen,
      calls: [
        for (var i = 0; i < 200; i++) {..._call, 'id': 'ca-p$i'},
      ],
    );
    List<String> pages() => [
      for (final r in seen)
        if (r.url.path.endsWith('/phone/calls') ||
            r.url.path.endsWith('/phone/messages'))
          '${r.url.path.split('/').last}:${r.url.queryParameters['per_page']}:${r.url.queryParameters['page']}',
    ];
    expect(pages(), ['calls:200:1', 'messages:200:1']);

    await tester.scrollUntilVisible(
      find.byKey(const Key('records-load-older')),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await _tap(tester, find.byKey(const Key('records-load-older')));
    expect(pages().skip(2), ['calls:200:2', 'messages:200:2']);
  });

  testWidgets('Records is not Coming soon', (tester) async {
    await _pump(tester, calls: const [], messages: const []);

    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('There are no calls or messages yet.'), findsOneWidget);
  });

  // Layout A (Mia, 2026-10-02): the table is the whole width until a row is
  // opened, then the detail sits beside it. Was: a 25/75 split from the start.
  testWidgets('at 900 the table is full width until a call opens beside it', (
    tester,
  ) async {
    await _pump(tester, size: const Size(900, 800));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byKey(const Key('phone-records-list')), findsOneWidget);
    expect(find.byKey(const Key('phone-records-pane')), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Calls'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Voicemail'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'SMS'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('phone-record-ca-1')));

    final list = tester.getRect(find.byKey(const Key('phone-records-list')));
    final pane = tester.getRect(find.byKey(const Key('phone-records-pane')));
    expect(pane.left, greaterThanOrEqualTo(list.right));
    expect(pane.width, closeTo(list.width * 2 / 3, 12));
    expect(find.byTooltip('Back to the list'), findsNothing);

    await _tap(tester, find.byTooltip('Close'));
    expect(find.byKey(const Key('phone-records-pane')), findsNothing);
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

    await _pump(tester, size: const Size(900, 800), player: player, seen: seen);

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
    // Counted by row key: the tiles above the table are InkWells too, so
    // counting InkWells (as this did before layout A) counts the tiles.
    expect(
      find
          .byWidgetPredicate(
            (w) =>
                w.key is ValueKey<String> &&
                (w.key! as ValueKey<String>).value.startsWith('phone-thread-'),
          )
          .evaluate()
          .length,
      2,
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

/// The tiles count the whole list and filter it (layout A, Mia 2026-10-02).
void _tileTests() {
  testWidgets('a Records tile counts its calls and lists only those', (
    tester,
  ) async {
    await _pump(
      tester,
      calls: [
        // Answered: completed, nothing went wrong.
        {..._call, 'tag': 'Quote', 'tag_color': '#2E7D32'},
        // Missed: inbound, the dial reached nobody.
        {
          ..._call,
          'id': 'ca-miss',
          'status': 'completed',
          'error_code': 'dial:no-answer',
          'duration_seconds': 18,
          'recording_sid': null,
        },
        // Voicemail: also no-answer, but the message is what matters.
        {
          ..._call,
          'id': 'ca-vm',
          'status': 'no-answer',
          'voicemail': true,
          'recording_sid': null,
        },
        // Didn't connect: one WE placed that nobody picked up — not a
        // callback owed, so not Missed.
        {
          ..._call,
          'id': 'ca-out',
          'direction': 'outbound',
          'from_e164': '+12035550142',
          'to_e164': '+13055550188',
          'status': 'busy',
          'error_code': 'dial:busy',
          'duration_seconds': 0,
          'recording_sid': null,
        },
      ],
    );

    Finder count(String key) => find.descendant(
      of: find.byKey(Key('records-tile-$key')),
      matching: find.byType(Text),
    );
    String tileText(String key) => count(
      key,
    ).evaluate().map((e) => (e.widget as Text).data ?? '').join('|');

    // Voicemails are in the Calls view now, so the Voicemail tile counts rows
    // it can actually list.
    expect(tileText('all'), contains('|4|'));
    expect(tileText('answered'), contains('|1|'));
    expect(tileText('missed'), contains('|1|'));
    expect(tileText('voicemail'), contains('|1|'));
    expect(tileText('failed'), contains('|1|'));
    expect(tileText('untagged'), contains('|3|'));

    await _tap(tester, find.byKey(const Key('records-tile-missed')));
    expect(find.byKey(const Key('phone-record-ca-miss')), findsOneWidget);
    expect(find.byKey(const Key('phone-record-ca-1')), findsNothing);
    expect(find.byKey(const Key('phone-record-ca-out')), findsNothing);
    expect(find.text('No answer.'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('records-tile-failed')));
    expect(find.byKey(const Key('phone-record-ca-out')), findsOneWidget);
    expect(find.byKey(const Key('phone-record-ca-miss')), findsNothing);
    expect(find.text("Didn't connect"), findsWidgets);
    expect(find.text('The number was busy.'), findsOneWidget);

    // Tapping the picked tile again is everything.
    await _tap(tester, find.byKey(const Key('records-tile-failed')));
    expect(find.byKey(const Key('phone-record-ca-1')), findsOneWidget);
    expect(find.byKey(const Key('phone-record-ca-vm')), findsOneWidget);
  });

  testWidgets('a call row says who by customer name, over the number', (
    tester,
  ) async {
    await _pump(
      tester,
      calls: [
        {
          ..._call,
          'customer_name': 'Dana Whitfield',
          'answered_by_name': 'Andy',
        },
      ],
    );

    expect(find.text('Dana Whitfield'), findsOneWidget);
    expect(find.textContaining('Inbound · '), findsOneWidget);
    expect(find.text('Andy'), findsOneWidget);
    expect(find.text('CT main'), findsOneWidget);
    expect(find.text('Answered'), findsWidgets);
  });

  /// Jose, 2026-10-09: rows read only "+1 (305) 724-2199" with "+(" in the
  /// circle. The server's name for the number leads, the number sits under
  /// it, and a nameless number gets a phone icon rather than punctuation.
  testWidgets('a thread is named by whoever has the number', (tester) async {
    await _pump(
      tester,
      initialFilter: RecordsFilter.sms,
      showFilters: false,
      messages: [
        {..._sms, 'customer_id': null, 'peer_name': 'Jose Demasi'},
        {
          ..._sms,
          'id': 'm-unknown',
          'from_e164': '+13055550188',
          'customer_id': null,
        },
      ],
    );

    expect(find.text('Jose Demasi'), findsOneWidget);
    expect(find.text('JD'), findsOneWidget);
    expect(find.text('+(', findRichText: true), findsNothing);
    expect(find.byIcon(Icons.phone_outlined), findsWidgets);
  });

  testWidgets('Messages tiles: waiting on us, answered, today — and filter', (
    tester,
  ) async {
    final now = DateTime.now().toUtc().toIso8601String();

    await _pump(
      tester,
      initialFilter: RecordsFilter.sms,
      showFilters: false,
      messages: [
        // They wrote last, today: waiting on us.
        {..._sms, 'created_at': now, 'customer_name': 'Dana Whitfield'},
        // We wrote last, long ago: answered.
        {
          ..._sms,
          'id': 'm-out',
          'direction': 'outbound',
          'from_e164': '+12035550142',
          'to_e164': '+13055550188',
          'body': 'On our way',
          'customer_id': null,
        },
      ],
    );

    String tileText(String key) => find
        .descendant(
          of: find.byKey(Key('messages-tile-$key')),
          matching: find.byType(Text),
        )
        .evaluate()
        .map((e) => (e.widget as Text).data ?? '')
        .join('|');

    expect(find.byType(ChoiceChip), findsNothing);
    expect(tileText('all'), contains('|2|'));
    expect(tileText('waiting'), contains('|1|'));
    expect(tileText('answered'), contains('|1|'));
    expect(tileText('today'), contains('|1|'));

    // The customer's name where the server has one.
    expect(find.text('Dana Whitfield'), findsOneWidget);

    await _tap(tester, find.byKey(const Key('messages-tile-waiting')));
    expect(find.byKey(const Key('phone-thread-+12035550199')), findsOneWidget);
    expect(find.byKey(const Key('phone-thread-+13055550188')), findsNothing);

    await _tap(tester, find.byKey(const Key('messages-tile-answered')));
    expect(find.byKey(const Key('phone-thread-+13055550188')), findsOneWidget);
    expect(find.byKey(const Key('phone-thread-+12035550199')), findsNothing);

    // Opening a conversation still works from the table.
    await _tap(tester, find.byKey(const Key('phone-thread-+13055550188')));
    expect(find.byKey(const Key('phone-records-pane')), findsOneWidget);
    expect(find.textContaining('On our way'), findsWidgets);
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
        .where(
          (r) =>
              r.method == 'POST' &&
              r.url.path.endsWith('/phone/blocked-numbers'),
        )
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
    await _pump(
      tester,
      calls: [
        {..._call, 'id': 'ca-2', 'direction': 'outbound'},
      ],
    );

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
  RecordsFilter initialFilter = RecordsFilter.calls,
  bool showFilters = true,
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
        body: RecordsTab(
          api: api,
          session: session,
          player: player,
          initialFilter: initialFilter,
          showFilters: showFilters,
        ),
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
      return _json({
        'data': {'id': 'bn-1'},
      }, 201);
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
