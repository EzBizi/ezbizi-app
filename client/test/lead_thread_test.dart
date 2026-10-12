import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/recording_playback_bar.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:ezbizi_client/widgets/lead_thread.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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

  /// Jose, 2026-09-30, on a phone: "a lot of wasted space and u can barely
  /// see the message window". The details fold behind one control, the
  /// source is one line with the number, the viewing note is one line, and
  /// the conversation keeps the screen.
  // "Archiv-e": once Completed made the moves four, a phone's one line beside
  // Book broke the longer words in half; at five (Lost) the segments shrank
  // the long words to about 7px. A phone has one Status picker now.
  testWidgets('on a phone the status is one picker beside Book', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      size: const Size(360, 780),
    );

    expect(
      find.byWidgetPredicate((w) => w is SearchPickerField<String>),
      findsOneWidget,
    );
    expect(find.byType(SegmentedButton<String>), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('on a phone the details fold away and the header stays short', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      size: const Size(390, 844),
      viewers: const ['Adeline Pierrelouis-Aghaji', 'Rodrigo Mena'],
      details: {
        'account': 'A1 Chimney - Cleveland',
        'google_status': 'Active',
        'name': 'Dave Malkowski',
        'address': '215 Lakeview Dr Michigan City Indiana',
        'zip': '46360',
      },
    );

    expect(find.byKey(const ValueKey('lead-details')), findsNothing);
    expect(
      tester.getSize(find.byKey(const ValueKey('thread-header'))).height,
      lessThan(844 * 0.3),
      reason: 'the header took two thirds of a phone',
    );
    // The test font is too wide for the name to fit a phone's line; it must
    // still get all the room up to Details. Beside a Spacer it got half and
    // wrapped with the other half empty.
    expect(
      tester.getRect(find.byKey(const ValueKey('thread-details-toggle'))).left -
          tester.getRect(find.byKey(const ValueKey('thread-status'))).right,
      lessThan(1),
      reason: 'the name wrapped with room left beside it',
    );
    expect(tester.takeException(), isNull);

    await tester.tap(find.byKey(const ValueKey('thread-details-toggle')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('lead-details')), findsOneWidget);
    expect(find.text('A1 Chimney - Cleveland'), findsOneWidget);
  });

  /// Jose, 2026-10-08: a website booking waits for a dispatcher's answer.
  testWidgets('a website booking shows its window and can be approved', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      booking: const {
        'date': '2026-10-11',
        'start_time': '08:00',
        'end_time': '11:00',
        'status': 'pending',
        'decided_by': null,
      },
    );

    expect(
      find.text('Requested: Sunday, Oct 11 · 8:00 AM - 11:00 AM'),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('booking-approve')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('booking-approve')), findsNothing);
    expect(find.text('Approved by Mia'), findsOneWidget);
  });

  /// Jose, 2026-09-30: chimney prices went to a garage-door lead. The header
  /// tags the company the lead belongs to.
  testWidgets('the header tags the company the lead belongs to', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      company: const {'id': 'co-2', 'name': 'Harbor Garage Doors'},
    );

    final tag = tester.widget<CompanyTag>(
      find.byKey(const ValueKey('thread-company')),
    );
    expect(tag.name, 'Harbor Garage Doors');
  });

  /// The call row carries the same play bar Voicemail and Records use:
  /// fetch on the first press through the lead's own endpoint, then play,
  /// pause and scrub. Nothing plays before the bytes arrive.
  testWidgets('the play bar fetches the recording on the first press, then '
      'plays and scrubs', (tester) async {
    final gate = Completer<void>();
    final player = FakeRecordingPlayer();

    await _pump(tester, player: player, recordingGate: gate);

    expect(find.text('Customer called · 2:05'), findsOneWidget);
    expect(find.textContaining('Customer called'), findsOneWidget);
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

  testWidgets('a long transcript opens with Show all, and a call waiting its '
      'turn says Transcribing', (tester) async {
    final long = List.filled(40, 'The flue is cracked and water comes in.').join(' ');
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      callTranscript: long,
    );
    final text = find.byWidgetPredicate((w) => w is Text && w.data == long);
    expect(tester.widget<Text>(text).maxLines, 4);
    await tester.tap(find.byKey(const ValueKey('transcript-toggle-c-call')));
    await tester.pumpAndSettle();
    expect(find.byWidgetPredicate((w) => w is SelectableText && w.data == long), findsOneWidget);
  });

  /// Jose, 2026-10-12: "IVR Menu: / Dispatcher: / Customer:", the labels bold.
  testWidgets('a transcript divided by speaker shows each label in bold', (
    tester,
  ) async {
    const labelled =
        'IVR menu: Press 1 for service.\n\nDispatcher: A1 Chimney, this is Ana.'
        '\n\nCustomer: I saw you are hiring.';
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      callTranscript: labelled,
    );
    final text = tester.widget<Text>(
      find.byWidgetPredicate(
        (w) => w is Text && w.textSpan?.toPlainText() == labelled,
      ),
    );
    final bold = <String>[];
    text.textSpan!.visitChildren((span) {
      if (span is TextSpan && span.style?.fontWeight == FontWeight.w700) {
        bold.add(span.text!);
      }
      return true;
    });
    expect(bold, ['IVR menu: ', 'Dispatcher: ', 'Customer: ']);
  });

  // The dispatchers, via Jose, 2026-09-29: copy and paste.
  /// Jose, 2026-10-01: the website visitor sees "Lenin is typing…", and the
  /// dispatcher sees the visitor typing. Only on a live website chat.
  testWidgets('a website chat shows the visitor typing and says when we type', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      source: 'website_chat',
      visitorTyping: true,
    );

    expect(find.text('Marcus is typing…'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('reply-box')), 'On my way');
    await tester.enterText(find.byKey(const ValueKey('reply-box')), 'On my way!');
    await tester.pump();
    expect(_typingPosts, ['/api/v1/leads/lead-1/typing'],
        reason: 'one signal, not one per keystroke');
  });

  test('a lead with no name, or a number for one, reads as Customer', () {
    expect(typingLine('Marcus Hill'), 'Marcus is typing…');
    expect(typingLine(null), 'Customer is typing…');
    expect(typingLine('+1 (914) 325-8137'), 'Customer is typing…');
  });

  testWidgets('a text lead never says it is typing', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    await tester.enterText(find.byKey(const ValueKey('reply-box')), 'Hello');
    await tester.pump();
    expect(_typingPosts, isEmpty);
  });

  /// Toula, 2026-10-01: "images should open in it not out of the page".
  testWidgets('a photo opens in the thread, and the browser is only offered', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      conversations: [
        {
          'id': 'c-photo',
          'channel': 'LIVECHAT',
          'from': 'CONSUMER',
          'occurred_at': '2026-10-01T10:00:00Z',
          'duration_ms': null,
          'text': 'Here is the crack',
          'attachments': <String>['https://app.ezbizi.com/api/v1/web/form/files/a.jpg'],
          'has_recording': false,
        },
      ],
    );

    expect(find.byKey(const ValueKey('attachment-outside-1')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('attachment-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('attachment-outside-1')), findsOneWidget,
        reason: 'opened in place, with the browser as an option');

    await tester.tap(find.byKey(const ValueKey('attachment-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('attachment-outside-1')), findsNothing);
  });

  testWidgets('a message copies in one press', (tester) async {
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') {
        copied = (call.arguments as Map)['text'] as String?;
      }
      return null;
    });
    addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));

    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );
    await tester.tap(find.byKey(const ValueKey('copy-c-in')));
    await tester.pump();

    expect(copied, 'Is Saturday ok?');
    expect(find.byWidgetPredicate((w) => w is SelectableText && w.data == 'Is Saturday ok?'), findsOneWidget,
        reason: 'part of a message can be selected too');
  });

  // Jose, 2026-09-29: who sent what, and who else has the lead open.
  testWidgets('replies name who sent them, and others viewing are shown', (
    tester,
  ) async {
    Map<String, dynamic> msg(String id, String from, {String? by, bool auto = false}) => {
      'id': id,
      'channel': 'MESSAGE',
      'from': from,
      'occurred_at': '2026-09-28T12:00:00Z',
      'duration_ms': null,
      'text': 'Text $id',
      'attachments': <String>[],
      'has_recording': false,
      'sent_by': by,
      'automatic': auto,
    };
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      conversations: [
        msg('a', 'CONSUMER'),
        msg('b', 'ADVERTISER', by: 'Tanya Ruiz'),
        msg('c', 'ADVERTISER', auto: true),
      ],
      viewers: ['Tanya Ruiz', 'Marco Diaz'],
    );

    expect(find.textContaining('Tanya Ruiz · '), findsOneWidget);
    expect(find.textContaining('Automation · '), findsOneWidget);
    expect(find.text('Tanya Ruiz and Marco Diaz are viewing this lead'), findsOneWidget);
  });

  // The dispatchers, via Jose, 2026-09-29: opening a lead showed the FIRST
  // message; it must open on the newest, like a chat.
  testWidgets('a long thread opens on its newest message', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      conversations: [
        for (var i = 0; i < 60; i++)
          {
            'id': 'm-$i',
            'channel': 'MESSAGE',
            'from': i.isEven ? 'CONSUMER' : 'ADVERTISER',
            'occurred_at': DateTime.utc(2026, 9, 28, 12, i).toIso8601String(),
            'duration_ms': null,
            'text': 'Message $i',
            'attachments': <String>[],
            'has_recording': false,
          },
      ],
    );

    expect(find.text('Message 59'), findsOneWidget, reason: 'the newest is on screen');
    expect(find.text('Message 0'), findsNothing, reason: 'the oldest is not');
    expect(
      tester.getTopLeft(find.text('Message 59')).dy,
      greaterThan(tester.getTopLeft(find.text('Message 58')).dy),
      reason: 'newest at the bottom, as the conversation happened',
    );
  });

  testWidgets('a call waiting its turn says Transcribing', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      callTranscript: null,
      transcriptPending: true,
    );
    expect(find.byKey(const ValueKey('transcript-pending-c-call')), findsOneWidget);
  });

  testWidgets('a call shows its transcript under the recording', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    // Shown straight away under the recording, labelled as a transcript.
    expect(find.text('Transcript'), findsOneWidget);
    expect(find.text('Hi, my fireplace smokes when I light it.'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('transcript-toggle-c-call')),
      findsNothing,
      reason: 'a short transcript needs no Show all',
    );
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

  // Jose, 2026-10-01: "the send button should probably be either centered or
  // the same size as the field".
  testWidgets('Send and the emoji button are as tall as the reply box, and '
      'level with it', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );

    final box = tester.getRect(find.byKey(const ValueKey('reply-box')));
    for (final key in ['reply-send', 'reply-emoji']) {
      final button = tester.getRect(find.byKey(ValueKey(key)));
      expect(button.height, box.height, reason: key);
      expect(button.bottom, box.bottom, reason: key);
    }
    // To the left of Send.
    expect(
      tester.getRect(find.byKey(const ValueKey('reply-emoji'))).right,
      lessThan(tester.getRect(find.byKey(const ValueKey('reply-send'))).left),
    );
    // A desktop, where Jose saw it: there Material does not pad a button to a
    // 48 tap target, so a short Send shows.
  }, variant: TargetPlatformVariant.only(TargetPlatform.windows));

  // Jose, 2026-10-01: "a button for an emoji picker on the left of send".
  testWidgets('the emoji tray puts each one where the cursor is, and closes '
      'once the reply is sent', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
    );
    final field = find.byKey(const ValueKey('reply-box'));
    TextEditingController box() =>
        tester.widget<TextField>(field).controller!;

    expect(find.byKey(const ValueKey('reply-emoji-tray')), findsNothing);
    await tester.enterText(field, 'See you Saturday');
    await tester.tap(find.byKey(const ValueKey('reply-emoji')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('reply-emoji-tray')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('reply-emoji-👍')));
    await tester.pump();
    expect(box().text, 'See you Saturday👍');

    // At the start, where the cursor was put; the tray stays for the next.
    box().selection = const TextSelection.collapsed(offset: 0);
    await tester.tap(find.byKey(const ValueKey('reply-emoji-😊')));
    await tester.pump();
    expect(box().text, '😊See you Saturday👍');
    expect(box().selection.baseOffset, '😊'.length);

    await tester.tap(find.byKey(const ValueKey('reply-send')));
    await tester.pumpAndSettle();
    expect(_sent.last, '😊See you Saturday👍');
    expect(find.byKey(const ValueKey('reply-emoji-tray')), findsNothing);
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

  // UI queue 77: what AI wrote says so, until a person changes it.
  testWidgets("a suggestion is marked as AI's until the words change", (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      suggestion: 'Hi, yes we can inspect it. Which day suits you?',
    );

    final mark = find.byKey(const ValueKey('reply-ai-draft'));
    expect(mark, findsNothing);

    await tester.tap(find.byKey(const ValueKey('reply-suggest')));
    await tester.pumpAndSettle();
    expect(mark, findsOneWidget);
    expect(find.text('Draft by AI'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('reply-box')),
      'Hi, yes we can inspect it. Does Thursday suit you?',
    );
    await tester.pumpAndSettle();
    expect(mark, findsNothing);
  });

  // UI queue 77: a value AI set says so until a person changes it. The API's
  // `ai` list is what says which values are still AI's.
  testWidgets('the name, status and ZIP AI set carry the AI mark', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      ai: const ['contact_name', 'status', 'postal_code'],
      postalCode: '46360',
      details: const {'zip': '46360'},
    );

    expect(find.byKey(const ValueKey('thread-ai-name')), findsOneWidget);
    expect(find.byKey(const ValueKey('thread-ai-status')), findsOneWidget);
    expect(find.byKey(const ValueKey('lead-ai-zip')), findsOneWidget);
  });

  // The control: a lead whose values a person set carries none of it, and a
  // ZIP from Google's own details is not AI's whatever AI guessed.
  testWidgets('values a person set, or Google gave, carry no AI mark', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      ai: const ['postal_code'],
      postalCode: '06103',
      details: const {'zip': '46360'},
    );

    expect(find.byKey(const ValueKey('thread-ai-name')), findsNothing);
    expect(find.byKey(const ValueKey('thread-ai-status')), findsNothing);
    expect(find.byKey(const ValueKey('lead-ai-zip')), findsNothing);
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

  // Jose, 2026-09-28: a date line where the day changes, and an iPhone
  // reaction hung on the message it quotes instead of drawn as a message.
  test('the thread is split by day, and a reaction joins its message', () {
    final items = threadItems([
      {'id': 'a', 'from': 'CONSUMER', 'text': 'Is Saturday ok?', 'occurred_at': '2026-09-26T15:00:00Z'},
      {'id': 'b', 'from': 'ADVERTISER', 'text': 'Yes, 10am works.', 'occurred_at': '2026-09-28T13:00:00Z'},
      {
        'id': 'c',
        'from': 'CONSUMER',
        'text': 'Liked \u201cYes, 10am works.\u201d',
        'occurred_at': '2026-09-28T13:05:00Z',
        'reaction': {'emoji': '👍', 'quote': 'Yes, 10am works'},
      },
    ]);

    expect(items.whereType<String>(), hasLength(2), reason: 'two days, two lines');
    final messages = items.whereType<Map<String, dynamic>>().toList();
    expect(messages.map((m) => m['id']), ['a', 'b'], reason: 'the reaction is not a message');
    expect(messages[1]['_reactions'], ['👍']);

    // Rebuilding must not stack the same reaction twice.
    final source = [
      {'id': 'b', 'from': 'ADVERTISER', 'text': 'Yes', 'occurred_at': '2026-09-28T13:00:00Z'},
      {'id': 'c', 'from': 'CONSUMER', 'text': 'Liked', 'occurred_at': '2026-09-28T13:05:00Z', 'reaction': {'emoji': '👍', 'quote': 'Yes'}},
    ];
    threadItems(source);
    final again = threadItems(source).whereType<Map<String, dynamic>>().single;
    expect(again['_reactions'], ['👍']);
  });

  test('day labels read as people say them', () {
    final now = DateTime(2026, 9, 28, 12);
    expect(threadDay(DateTime(2026, 9, 28, 9).toIso8601String(), now: now), 'Today');
    expect(threadDay(DateTime(2026, 9, 27, 9).toIso8601String(), now: now), 'Yesterday');
    expect(threadDay(DateTime(2026, 2, 14, 9).toIso8601String(), now: now), 'Sat, Feb 14');
    expect(threadDay(DateTime(2025, 2, 14, 9).toIso8601String(), now: now), 'Fri, Feb 14, 2025');
  });

  testWidgets('the account, Google status and the address the customer gave', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      details: {
        'account': 'A1 Chimney - Cleveland',
        'google_status': 'Active',
        'name': 'Dave Malkowski',
        'address': '215 Lakeview Dr Michigan City Indiana',
        'zip': '46360',
      },
    );

    expect(find.text('A1 Chimney - Cleveland'), findsOneWidget);
    expect(find.text('Google: Active'), findsOneWidget);
    expect(find.text('215 Lakeview Dr Michigan City Indiana · ZIP 46360'), findsOneWidget);
  });

  testWidgets('an address Google placed exactly is shown as Google has it', (
    tester,
  ) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      details: {
        'address': '215 Lakeview Dr Michigan City Indiana',
        'zip': '46360',
        'checked_address': {
          'address': '215 Lakeview Dr, Michigan City, IN 46360, USA',
          'zip': '46360',
          'exact': true,
        },
      },
    );

    expect(find.byKey(const ValueKey('lead-address-checked')), findsOneWidget);
    expect(find.text('215 Lakeview Dr, Michigan City, IN 46360, USA'), findsOneWidget);
    expect(find.text('215 Lakeview Dr Michigan City Indiana · ZIP 46360'), findsNothing);
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

    expect(find.textContaining('Existing customer · Marcus Bell · C-0042 · 2 jobs'), findsOneWidget);
    // One line until opened, so the thread keeps its room (Jose, 2026-09-30).
    expect(find.text('12 Hillcrest Ct, Ridgefield, CT'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('lead-customer-more')));
    await tester.pump();
    expect(find.text('12 Hillcrest Ct, Ridgefield, CT'), findsOneWidget);
    expect(find.textContaining('Flue repair · Pending'), findsOneWidget);
    expect(find.textContaining('Chimney sweep · Done'), findsOneWidget);
  });

  // Jose, 2026-09-30: "templates ... next to the send button". A tap fills
  // the box to read first; it does not send.
  testWidgets('a reply template fills the box and sends nothing', (tester) async {
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      templates: [
        {'name': 'Thank you', 'text': 'Hi Marcus, thank you for contacting A-1 Chimney!'},
      ],
    );

    await tester.tap(find.byKey(const ValueKey('reply-template-Thank you')));
    await tester.pump();

    final box = tester.widget<TextField>(find.byKey(const ValueKey('reply-box')));
    expect(box.controller!.text, 'Hi Marcus, thank you for contacting A-1 Chimney!');
    expect(_sent, isEmpty);
  });

  // Jose, 2026-10-01, a phone screenshot: seven templates in four rows took
  // the room the thread needs. One line, scrolled sideways.
  testWidgets('on a phone the templates are one line, not rows', (tester) async {
    final names = [
      'Thank you', 'Inspection prices', 'Sweep price', 'Availability',
      'Booking details', 'Booked', 'We service your area',
    ];
    await _pump(
      tester,
      player: FakeRecordingPlayer(),
      recordingGate: Completer()..complete(),
      size: const Size(390, 900),
      templates: [
        for (final name in names) {'name': name, 'text': 'Text for $name.'},
      ],
    );

    final first = tester.getTopLeft(find.byKey(const ValueKey('reply-template-Thank you')));
    final second = tester.getTopLeft(find.byKey(const ValueKey('reply-template-Inspection prices')));
    expect(second.dy, first.dy);
    expect(
      tester.getSize(find.byKey(const ValueKey('reply-templates'))).height,
      lessThan(48),
    );

    // The last one is reached by scrolling, and still fills the box.
    final last = find.byKey(const ValueKey('reply-template-We service your area'));
    await tester.ensureVisible(last);
    await tester.pumpAndSettle();
    await tester.tap(last);
    await tester.pump();
    expect(
      tester.widget<TextField>(find.byKey(const ValueKey('reply-box'))).controller!.text,
      'Text for We service your area.',
    );
  });

  // Jose, 2026-10-01: "see the time of the messages as well like 11:48 13:23".
  test('a message says its time of day, and how long ago while it is recent', () {
    final recent = DateTime.now().subtract(const Duration(minutes: 57));
    final hh = recent.hour.toString().padLeft(2, '0');
    final mm = recent.minute.toString().padLeft(2, '0');
    expect(messageWhen(recent.toUtc().toIso8601String()), '$hh:$mm · 57m ago');

    final old = DateTime(2026, 9, 23, 13, 5);
    expect(messageWhen(old.toUtc().toIso8601String()), '13:05');

    // In the organization's clock when there is one (UI/UX, 2026-10-01).
    expect(
      messageWhen(old.toUtc().toIso8601String(), clock: (h, m) => 'h$h m$m'),
      'h13 m5',
    );
  });

  test('the number shows under any name, the customer one included', () {
    expect(showsNumberLine({'phone': '+12155550101', 'customer': {'name': 'Mark Morrow'}}), isTrue);
    expect(showsNumberLine({'phone': '+12155550101', 'contact_name': 'Mark'}), isTrue);
    expect(showsNumberLine({'phone': '+12155550101'}), isFalse, reason: 'the title is the number');
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
    expect(find.textContaining('Customer · '), findsOneWidget);
    // Our reply had no sender on record: it was sent outside EzBizi, and it
    // says so rather than "You" (2026-09-29).
    expect(find.textContaining('Sent outside EzBizi · '), findsOneWidget);
    expect(find.textContaining('CONSUMER'), findsNothing);
    expect(find.textContaining('ADVERTISER'), findsNothing);
  });
}

final _sent = <String>[];
final _typingPosts = <String>[];

Future<void> _pump(
  WidgetTester tester, {
  required RecordingPlayer player,
  required Completer<void> recordingGate,
  bool canReply = true,
  String? cannotReplyBecause,
  String? refuseSend,
  String? suggestion,
  Map<String, dynamic>? customer,
  Map<String, dynamic>? details,
  String? callTranscript = 'Hi, my fireplace smokes when I light it.',
  bool transcriptPending = false,
  List<Map<String, dynamic>>? conversations,
  List<String> viewers = const [],
  List<Map<String, String>> templates = const [],
  Future<void> Function()? onRead,
  Future<Map<String, dynamic>?> Function(String? note)? onNote,
  Size size = const Size(600, 900),
  Map<String, dynamic>? company,
  String? source,
  bool visitorTyping = false,
  Map<String, dynamic>? booking,
  List<String>? ai,
  String? postalCode,
}) async {
  _sent.clear();
  _typingPosts.clear();
  tester.view.physicalSize = size;
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

      if (request.method == 'POST' &&
          request.url.path.endsWith('/booking/approve')) {
        return http.Response(
          jsonEncode({
            'data': {...?booking, 'status': 'approved', 'decided_by': 'Mia'},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.method == 'POST' && request.url.path.endsWith('/typing')) {
        _typingPosts.add(request.url.path);
        return http.Response('{}', 200, headers: {'content-type': 'application/json'});
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
              'details': details,
              'viewers': viewers,
              'visitor_typing': visitorTyping,
              'booking': booking,
              'reply_templates': templates,
              'conversations': conversations ?? [
                {
                  'id': 'c-call',
                  'channel': 'PHONE_CALL',
                  'from': 'CONSUMER',
                  'occurred_at': '2026-09-06T10:00:00Z',
                  'duration_ms': 125000,
                  'text': null,
                  'transcript': callTranscript,
                  'transcript_pending': transcriptPending,
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
          lead: {
            'id': 'lead-1',
            'contact_name': 'Marcus Bell',
            'phone': '+1 (555) 123-4567',
            'service': 'fireplace_chimney',
            'status': 'new',
            'franchise': const {'id': 'f-1', 'name': 'Connecticut'},
            'company': ?company,
            'source': ?source,
            'ai': ?ai,
            'postal_code': ?postalCode,
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
  /// Beside its tag the line does not say the company a second time.
  test('with the company tagged the line leaves it out', () {
    const lead = {
      'source': 'local_services',
      'company': {'id': 'c1', 'name': 'A-1 Chimney'},
    };

    expect(leadHeaderLine(lead, company: false), 'Google Ads');
  });

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
              'source': 'local_services', // a live website chat re-reads every 3s
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
              'source': 'local_services',
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

/// Design 28 (Jose, "as u recommend"): the status is a pill beside the name.
/// Changing it is one control since 2026-09-30 - Archive, Not a lead, Lead,
/// the one it is now filled - apart from Call and Book (Jose: "we should
/// also add Lead as well to mark if something is a lead or not").
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

  testWidgets('a New lead shows a pill, stands at Lead, and offers Archive '
      'and Not a lead', (tester) async {
    final asked = await pump(tester);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('thread-status')),
        matching: find.text('New'),
      ),
      findsOneWidget,
    );
    expect(
      tester
          .widget<SegmentedButton<String>>(
            find.byKey(const ValueKey('status-moves')),
          )
          .selected,
      {'new'},
    );

    // Where it already stands asks nothing.
    await tester.tap(find.byKey(const ValueKey('status-lead')));
    await tester.tap(find.byKey(const ValueKey('status-archive')));
    await tester.tap(find.byKey(const ValueKey('status-not-a-lead')));
    expect(asked, ['archived', 'not_a_lead']);
  });

  testWidgets('a lead set aside can be marked a lead again', (tester) async {
    final asked = await pump(tester, status: 'not_a_lead');

    expect(
      tester
          .widget<SegmentedButton<String>>(
            find.byKey(const ValueKey('status-moves')),
          )
          .selected,
      {'not_a_lead'},
    );

    await tester.tap(find.byKey(const ValueKey('status-lead')));
    expect(asked, ['new']);
  });

  // Jose, 2026-10-01: the customer said "Thank you" - nobody owes them an
  // answer, so the dispatcher can mark it Completed and the warning goes.
  testWidgets('a lead can be marked Completed, and shows as Completed', (
    tester,
  ) async {
    final asked = await pump(tester);
    await tester.tap(find.byKey(const ValueKey('status-completed')));
    expect(asked, ['completed']);

    expect(leadStatusPill('completed').label, 'Completed');
  });

  testWidgets('somebody who may only read sees the status, and no moves', (
    tester,
  ) async {
    await pump(tester, canChange: false);

    expect(find.byKey(const ValueKey('thread-status')), findsOneWidget);
    expect(find.byKey(const ValueKey('status-moves')), findsNothing);
  });

  testWidgets('on a phone the picker moves the lead', (tester) async {
    final moved = await pump(tester, size: const Size(390, 844));

    await tester.tap(find.byKey(const ValueKey('status-moves')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lost').last);
    await tester.pumpAndSettle();

    expect(moved, ['lost']);
    expect(tester.takeException(), isNull);
  });
}
