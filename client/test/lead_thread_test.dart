import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_playback_bar.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_recording_player.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The thread beside (or behind) a lead, and the one state that matters.
///
/// Play is fetch-then-play: the recording is streamed through EzBizi with the
/// account's token and handed to the player as bytes, so there is no sound
/// until the whole file has arrived. A play button that sits silent for three
/// seconds on a twelve-minute call reads as broken, and the longest recordings
/// are the ones people most want to hear. The fetching state is not decoration.
void main() {
  _wordTests();
  _statusTests();
  _autoRefreshTests();

  /// The call row carries the same play bar Voicemail and Records use:
  /// fetch on the first press through the lead's own endpoint, then play,
  /// pause and scrub. Nothing plays before the bytes arrive.
  testWidgets('the play bar fetches the recording on the first press, then '
      'plays and scrubs', (tester) async {
    final gate = Completer<void>();
    final player = FakeRecordingPlayer();

    await _pump(tester, player: player, recordingGate: gate);

    expect(find.text('Call · 2:05'), findsOneWidget);
    expect(find.textContaining('They called'), findsOneWidget);
    expect(find.textContaining('PHONE_CALL'), findsNothing);
    expect(find.byKey(const Key('recording-play')), findsOneWidget);
    expect(find.byKey(const Key('recording-scrub')), findsOneWidget);

    await tester.tap(find.byKey(const Key('recording-play')));
    await tester.pump();

    expect(player.played, isEmpty, reason: 'nothing to play before the bytes');

    gate.complete();
    await tester.pumpAndSettle();

    expect(player.played, hasLength(1));
    expect(utf8.decode(player.played.single), 'ID3-fake-audio-bytes');
    expect(
      find.byTooltip('Pause'),
      findsOneWidget,
      reason: 'the app is making the sound, so it can pause it',
    );
  });

  testWidgets('a call shows its transcript, folded, labelled as one', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    expect(find.text('Transcript'), findsOneWidget);
    expect(find.text('Hi, my fireplace smokes when I light it.'), findsNothing);

    await tester.tap(find.text('Transcript'));
    await tester.pumpAndSettle();

    expect(find.text('Hi, my fireplace smokes when I light it.'), findsOneWidget);
  });

  testWidgets('a message row has no play bar; only a call with a recording '
      'does', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    expect(find.byType(RecordingPlaybackBar), findsOneWidget);
    expect(find.byKey(const ValueKey('recording-c-call')), findsOneWidget);
  });

  /// A refusal is shown, in the player's own words rather than ours.
  testWidgets("a recording that will not play says why, in the player's "
      'own words', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(refuses: 'This recording would not play.'),
      recordingGate: Completer()..complete(),
    );

    await tester.tap(find.byKey(const Key('recording-play')));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('This recording would not play.'),
      findsOneWidget,
    );
  });

  testWidgets('a reply goes out on Send and appears in the thread as ours', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    expect(find.byKey(const ValueKey('reply-box')), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('reply-box')),
      'See you Saturday.',
    );
    await tester.tap(find.byKey(const ValueKey('reply-send')));
    await tester.pumpAndSettle();

    expect(_sent, ['See you Saturday.']);
    expect(find.text('See you Saturday.'), findsOneWidget);
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('reply-box')))
          .controller!
          .text,
      isEmpty,
      reason: 'the box clears once Google has taken it',
    );
  });

  /// A draft, never a send: Suggest fills the box, the person presses Send.
  testWidgets('Suggest fills the box with the draft and sends nothing', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      suggestion: 'Hi, yes we can inspect it. Which day suits you?',
    );

    await tester.tap(find.byKey(const ValueKey('reply-suggest')));
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('reply-box')))
          .controller!
          .text,
      'Hi, yes we can inspect it. Which day suits you?',
    );
    expect(_sent, isEmpty, reason: 'a draft is not a send');
  });

  testWidgets('without the model there is no Suggest button', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    expect(find.byKey(const ValueKey('reply-suggest')), findsNothing);
    expect(find.byKey(const ValueKey('reply-box')), findsOneWidget);
  });

  testWidgets('a call lead has no reply box and says why', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      canReply: false,
      cannotReplyBecause:
          'Google only accepts replies to text enquiries. '
          'This one came in as a call, so it is answered by calling back.',
    );

    expect(find.byKey(const ValueKey('reply-box')), findsNothing);
    expect(find.textContaining('only accepts replies to text'), findsOneWidget);
  });

  // Jose, 2026-09-28: a number that is already a customer shows who they are
  // and what they have had done.
  testWidgets('a known customer is shown with their jobs, newest first', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      customer: {
        'id': 'cu-1',
        'number': 'C-0042',
        'name': 'Marcus Bell',
        'address': '12 Hillcrest Ct, Ridgefield, CT',
        'jobs_total': 2,
        'revenue_minor': 45000,
        'currency': 'USD',
        'jobs': [
          {'id': 'j-2', 'number': 'J-2', 'title': 'Flue repair', 'status': 'Pending', 'when': null},
          {'id': 'j-1', 'number': 'J-1', 'title': 'Chimney sweep', 'status': 'Done', 'when': null, 'amount_minor': 45000, 'currency': 'USD'},
        ],
      },
    );

    expect(find.textContaining('Existing customer · Marcus Bell · C-0042'), findsOneWidget);
    expect(find.text('12 Hillcrest Ct, Ridgefield, CT'), findsOneWidget);
    expect(find.textContaining('2 jobs'), findsOneWidget);
    expect(find.textContaining('Flue repair · Pending'), findsOneWidget);
    expect(find.textContaining('Chimney sweep · Done'), findsOneWidget);
  });

  test('a nameless lead from a known number is titled by the customer', () {
    expect(
      leadTitle({'phone': '+12155550101', 'customer': {'name': 'Marcus Bell'}}),
      'Marcus Bell',
    );
    expect(
      leadTitle({'contact_name': 'Marc', 'customer': {'name': 'Marcus Bell'}}),
      'Marc',
      reason: 'what the person gave Google still wins',
    );
  });

  // Android edge to edge: the note's last line sat under the gesture bar.
  testWidgets('the note under the thread clears the phone gesture bar', (
    tester,
  ) async {
    tester.view.padding = FakeViewPadding(
      bottom: 34 * tester.view.devicePixelRatio,
    );
    addTearDown(tester.view.resetPadding);
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      canReply: false,
      cannotReplyBecause: 'Answered by calling back.',
    );

    final screen = tester.view.physicalSize / tester.view.devicePixelRatio;
    expect(
      tester.getBottomLeft(find.text('Answered by calling back.')).dy,
      lessThanOrEqualTo(screen.height - 34),
    );
  });

  /// The screen used to WRITE that sentence itself, so every source that could
  /// not be replied to was described as a Google phone call. The first real
  /// Thumbtack enquiry was a message thread and was told exactly that
  /// (Jose, 2026-09-10). The reason comes from the channel that refused.
  testWidgets('the refusal shown is the one the server gave, whatever the '
      'source', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      canReply: false,
      cannotReplyBecause:
          'This is a test enquiry from Thumbtack, so there is nobody to reply to.',
    );

    expect(find.textContaining('nobody to reply to'), findsOneWidget);
    // The header still reads "Google Ads" because this fixture's lead came
    // from there. What must be gone is the REFUSAL written in Google's terms.
    expect(
      find.textContaining('only accepts replies to text'),
      findsNothing,
      reason: 'the provider named must be the one that refused',
    );
  });

  /// An older server sends no reason. The fallback names no provider, because
  /// the screen cannot know which one is right.
  testWidgets('with no reason given the screen names no provider', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      canReply: false,
    );

    expect(
      find.textContaining('cannot be replied to from here'),
      findsOneWidget,
    );
    expect(find.textContaining('only accepts replies to text'), findsNothing);
  });

  testWidgets('a refused reply stays in the box with the reason', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      refuseSend: 'Google refused the reply (HTTP 400). It was not sent.',
    );

    await tester.enterText(find.byKey(const ValueKey('reply-box')), 'Hello?');
    await tester.tap(find.byKey(const ValueKey('reply-send')));
    await tester.pumpAndSettle();

    expect(
      find.text('Google refused the reply (HTTP 400). It was not sent.'),
      findsOneWidget,
    );
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('reply-box')))
          .controller!
          .text,
      'Hello?',
    );
  });

  testWidgets('opening the thread marks the lead read, and a note saved in '
      'place shows who wrote it', (tester) async {
    var reads = 0;
    String? saved;

    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      onRead: () async => reads++,
      onNote: (text) async {
        saved = text;
        return {
          'id': 'lead-1',
          'contact_name': 'Marcus Bell',
          'phone': '+1 (555) 123-4567',
          'service': 'fireplace_chimney',
          'status': 'new',
          'note': text,
          'note_by': {'id': 'u-2', 'name': 'Maya'},
          'note_updated_at': DateTime.now().toUtc().toIso8601String(),
        };
      },
    );

    expect(reads, 1, reason: 'seen once, on opening');

    await tester.tap(find.byKey(const ValueKey('note-add')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('note-box')),
      'Wants Saturday.',
    );
    await tester.tap(find.byKey(const ValueKey('note-save')));
    await tester.pumpAndSettle();

    expect(saved, 'Wants Saturday.');
    expect(find.text('Wants Saturday.'), findsOneWidget);
    // On the label line, who then what: "Note · Maya · just now".
    expect(find.textContaining('Note · Maya · '), findsOneWidget);
    expect(find.byKey(const ValueKey('note-edit')), findsOneWidget);
  });

  testWidgets('messages read as a thread: theirs left, yours right, with who '
      'and when', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    expect(find.text('Is Saturday ok?'), findsOneWidget);
    expect(find.text('Yes, 10am works.'), findsOneWidget);
    expect(find.textContaining('They · '), findsOneWidget);
    expect(find.textContaining('You · '), findsOneWidget);
    expect(find.textContaining('CONSUMER'), findsNothing);
    expect(find.textContaining('ADVERTISER'), findsNothing);
  });
}

final _sent = <String>[];

Future<void> _pump(
  WidgetTester tester, {
  required RecordingPlayer player,
  required Completer<void> recordingGate,
  bool canReply = true,
  String? cannotReplyBecause,
  String? refuseSend,
  String? suggestion,
  Map<String, dynamic>? customer,
  Future<void> Function()? onRead,
  Future<Map<String, dynamic>?> Function(String? note)? onNote,
}) async {
  _sent.clear();
  tester.view.physicalSize = const Size(600, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'POST' &&
          request.url.path.endsWith('/suggest-reply')) {
        return http.Response(
          jsonEncode({
            'data': {'text': suggestion},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.method == 'POST' && request.url.path.endsWith('/messages')) {
        final text = (jsonDecode(request.body) as Map)['text'] as String;
        if (refuseSend != null) {
          return http.Response(
            jsonEncode({'message': refuseSend}),
            502,
            headers: {'content-type': 'application/json'},
          );
        }
        _sent.add(text);
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'c-sent',
              'channel': 'MESSAGE',
              'from': 'ADVERTISER',
              'occurred_at': '2026-09-08T18:00:00Z',
              'duration_ms': null,
              'text': text,
              'attachments': <String>[],
              'has_recording': false,
            },
          }),
          201,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.url.path.endsWith('/recording')) {
        await recordingGate.future;
        return http.Response.bytes(
          utf8.encode('ID3-fake-audio-bytes'),
          200,
          headers: {'content-type': 'audio/mpeg'},
        );
      }

      if (request.url.path.endsWith('/leads/lead-1')) {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'lead-1',
              'can_reply': canReply,
              'cannot_reply_because': cannotReplyBecause,
              'can_suggest': suggestion != null,
              'customer': customer,
              'conversations': [
                {
                  'id': 'c-call',
                  'channel': 'PHONE_CALL',
                  'from': 'CONSUMER',
                  'occurred_at': '2026-09-06T10:00:00Z',
                  'duration_ms': 125000,
                  'text': null,
                  'transcript': 'Hi, my fireplace smokes when I light it.',
                  'attachments': <String>[],
                  'has_recording': true,
                },
                {
                  'id': 'c-in',
                  'channel': 'MESSAGE',
                  'from': 'CONSUMER',
                  'occurred_at': '2026-09-07T09:00:00Z',
                  'duration_ms': null,
                  'text': 'Is Saturday ok?',
                  'attachments': <String>[],
                  'has_recording': false,
                },
                {
                  'id': 'c-out',
                  'channel': 'MESSAGE',
                  'from': 'ADVERTISER',
                  'occurred_at': '2026-09-07T09:30:00Z',
                  'duration_ms': null,
                  'text': 'Yes, 10am works.',
                  'attachments': <String>[],
                  'has_recording': false,
                },
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        '{}',
        404,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadThread(
          api: api,
          player: player,
          lead: const {
            'id': 'lead-1',
            'contact_name': 'Marcus Bell',
            'phone': '+1 (555) 123-4567',
            'service': 'fireplace_chimney',
            'status': 'new',
            'franchise': {'id': 'f-1', 'name': 'Connecticut'},
          },
          onStatusChanged: (_) async {},
          onRead: onRead,
          onNote: onNote,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void _wordTests() {
  test('channels and sources read as words, never as wire values', () {
    expect(channelWord('PHONE_CALL'), 'Call');
    expect(channelWord('MESSAGE'), 'Text');
    expect(channelWord('SMS'), 'Text');
    expect(channelWord('LIVECHAT'), 'Chat');
    expect(channelWord('FORM'), 'Form');
    expect(sourceWord('local_services'), 'Google Ads');
    expect(sourceWord('website_chat'), 'Website chat');
    expect(sourceWord('website_form'), 'Website form');
  });

  /// WHICH OF THE 47 WEBSITES SENT IT.
  ///
  /// Jose, 2026-09-23: he switched the live chat off on one of A-1 Chimney's
  /// websites and kept receiving chats. Every one of them read "Website chat ·
  /// A-1 Chimney" — true, and no help at all in working out that the other 46
  /// sites were still sending. The chats were coming from a-1chimneysweep.com,
  /// ny.a-1chimney.com and chi.a-1chimney.com.
  test('a website enquiry names the website, not the company', () {
    final line = leadHeaderLine({
      'source': 'website_chat',
      'summary': 'ny.a-1chimney.com',
      'company': {'id': 'c1', 'name': 'A-1 Chimney'},
    });

    expect(line, 'Website chat · ny.a-1chimney.com');
  });

  /// The host beats the FRANCHISE too, which is the case that would otherwise
  /// look right: a franchise name is a place, so a reader would not notice it
  /// standing in for the one fact they are after.
  test('the host wins over a franchise name as well', () {
    final line = leadHeaderLine({
      'source': 'website_chat',
      'summary': 'chi.a-1chimney.com',
      'franchise': {'id': 'f1', 'name': 'Chicago'},
      'company': {'id': 'c1', 'name': 'A-1 Chimney'},
    });

    expect(line, 'Website chat · chi.a-1chimney.com');
  });

  /// And nothing else changes. `summary` carries something other than a host
  /// for Google and Thumbtack, so swapping it in everywhere would put the
  /// wrong word where the company name belongs.
  test('a lead from anywhere else still names the company', () {
    final line = leadHeaderLine({
      'source': 'local_services',
      'summary': 'whatever Google put here',
      'company': {'id': 'c1', 'name': 'A-1 Chimney'},
    });

    expect(line, 'Google Ads · A-1 Chimney');
  });

  /// A website lead with no host recorded falls back rather than losing the
  /// place entirely — older rows predate `summary` carrying the host.
  test('a website lead with no host still names the company', () {
    final line = leadHeaderLine({
      'source': 'website_chat',
      'company': {'id': 'c1', 'name': 'A-1 Chimney'},
    });

    expect(line, 'Website chat · A-1 Chimney');
  });
}


/// AN OPEN CONVERSATION RE-READS ITSELF.
///
/// Jose, 2026-09-16, after a live chat went unanswered for half an hour: "when
/// a new message arrives, it does not show it, unless you leave the tab and
/// come back in." The dispatcher was looking straight at the thread.
///
/// Its own client rather than the shared `_pump` below, because this needs the
/// server's answer to CHANGE between two reads and that helper returns a fixed
/// one. Bending the shared fixture to carry a second state would have made
/// every other test in this file depend on it.
void _autoRefreshTests() {
  testWidgets('a new message appears in an open thread on its own', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // What the server holds. The test moves it; nobody touches the screen.
    final conversations = <Map<String, dynamic>>[
      {
        'id': 'c-in',
        'channel': 'MESSAGE',
        'from': 'CONSUMER',
        'occurred_at': '2026-09-16T09:00:00Z',
        'duration_ms': null,
        'text': 'Is Saturday ok?',
        'attachments': <String>[],
        'has_recording': false,
      },
    ];

    var reads = 0;

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/leads/lead-1')) {
          reads++;
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'lead-1',
                'can_reply': true,
                'cannot_reply_because': null,
                'can_suggest': false,
                'conversations': conversations,
                'customer_matches': <Map<String, dynamic>>[],
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response(
          '{"data":{}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LeadThread(
            api: api,
            lead: const {
              'id': 'lead-1',
              'contact_name': 'Marcus Bell',
              'source': 'website_chat',
              'status': 'new',
            },
            onStatusChanged: (_) async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Is Saturday ok?'), findsOneWidget);
    expect(find.text('Are you still there?'), findsNothing);
    expect(reads, 1);

    // The customer sends another message.
    conversations.add({
      'id': 'c-in-2',
      'channel': 'MESSAGE',
      'from': 'CONSUMER',
      'occurred_at': '2026-09-16T09:05:00Z',
      'duration_ms': null,
      'text': 'Are you still there?',
      'attachments': <String>[],
      'has_recording': false,
    });

    // The control: three seconds in, nothing has been asked for. Without it
    // this passes on a widget that re-reads on every frame.
    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
    expect(reads, 1);
    expect(find.text('Are you still there?'), findsNothing);

    await tester.pump(const Duration(seconds: 8));
    await tester.pump();

    expect(reads, greaterThan(1));
    expect(
      find.text('Are you still there?'),
      findsOneWidget,
      reason: 'somebody is waiting in a live chat and the dispatcher is '
          'looking at this exact screen',
    );
  });

  /// The quiet pass must not blank the conversation it is refreshing.
  ///
  /// `_load()` sets `_loading` and draws a spinner, which is right when you
  /// open a thread and wrong ten seconds later: the messages a person is
  /// reading would flicker away four times a minute. Asserted by holding the
  /// second read in flight and looking at the screen while it hangs.
  testWidgets('the refresh never replaces the thread with a spinner', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final gate = Completer<void>();
    var reads = 0;

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/leads/lead-1')) {
          reads++;
          // The SECOND read hangs, which is when a spinner would show.
          if (reads > 1) await gate.future;
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'lead-1',
                'can_reply': true,
                'can_suggest': false,
                'conversations': [
                  {
                    'id': 'c-in',
                    'channel': 'MESSAGE',
                    'from': 'CONSUMER',
                    'occurred_at': '2026-09-16T09:00:00Z',
                    'duration_ms': null,
                    'text': 'Is Saturday ok?',
                    'attachments': <String>[],
                    'has_recording': false,
                  },
                ],
                'customer_matches': <Map<String, dynamic>>[],
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response(
          '{"data":{}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LeadThread(
            api: api,
            lead: const {
              'id': 'lead-1',
              'contact_name': 'Marcus Bell',
              'source': 'website_chat',
              'status': 'new',
            },
            onStatusChanged: (_) async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Is Saturday ok?'), findsOneWidget);

    // Tick into the refresh and hold it there, mid-flight.
    await tester.pump(const Duration(seconds: 11));
    await tester.pump();
    expect(reads, 2, reason: 'the refresh should be in flight');

    expect(
      find.text('Is Saturday ok?'),
      findsOneWidget,
      reason: 'the conversation must stay on screen while it refreshes',
    );

    gate.complete();
    await tester.pumpAndSettle();
    expect(find.text('Is Saturday ok?'), findsOneWidget);
  });
}

/// Design 28 (Jose, "as u recommend"): the status is a pill beside the name,
/// and changing it is a verb: Archive or Not a lead on a New lead, Back to New
/// on any other. It was a search field alone in the corner that read as one.
void _statusTests() {
  Future<List<String>> pump(
    WidgetTester tester, {
    String status = 'new',
    bool canChange = true,
    Size size = const Size(1200, 800),
  }) async {
    final asked = <String>[];
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {'id': 'lead-9', 'can_reply': false, 'conversations': []},
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: LeadThread(
            api: api,
            player: FakeRecordingPlayer(),
            lead: {
              'id': 'lead-9',
              'contact_name': 'Maria Lopez',
              'phone': '+1 (312) 555-0187',
              'status': status,
            },
            onStatusChanged: canChange ? (to) async => asked.add(to) : null,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    return asked;
  }

  testWidgets('a New lead shows a pill and offers Archive and Not a lead', (
    tester,
  ) async {
    final asked = await pump(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('thread-status')),
        matching: find.text('New'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('status-back-to-new')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('status-archive')));
    await tester.tap(find.byKey(const ValueKey('status-not-a-lead')));
    expect(asked, ['archived', 'not_a_lead']);
  });

  testWidgets('any other lead offers Back to New instead', (tester) async {
    final asked = await pump(tester, status: 'archived');

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('thread-status')),
        matching: find.text('Archived'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('status-archive')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('status-back-to-new')));
    expect(asked, ['new']);
  });

  testWidgets('somebody who may only read sees the status, and no moves', (
    tester,
  ) async {
    await pump(tester, canChange: false);

    expect(find.byKey(const ValueKey('thread-status')), findsOneWidget);
    expect(find.byKey(const ValueKey('status-archive')), findsNothing);
    expect(find.byKey(const ValueKey('status-back-to-new')), findsNothing);
  });

  testWidgets('on a phone the two moves share one row, the same size', (
    tester,
  ) async {
    await pump(tester, size: const Size(390, 844));

    final archive = tester.getRect(
      find.byKey(const ValueKey('status-archive')),
    );
    final notALead = tester.getRect(
      find.byKey(const ValueKey('status-not-a-lead')),
    );
    expect(archive.top, notALead.top);
    expect(archive.size, notALead.size);
    expect(tester.takeException(), isNull);
  });
}
