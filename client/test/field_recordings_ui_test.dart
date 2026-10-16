import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/field_recorder.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/field/record_card.dart';
import 'package:ezbizi_client/screens/field/recordings_tab.dart';
import 'package:ezbizi_client/screens/phone/recording_player.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 102: the Record card under SOS, and Field › Recordings.
/// Invented people only.
void main() {
  group('Record card', () {
    Future<FieldRecorder> pump(
      WidgetTester tester, {
      required Map<String, dynamic>? me,
      FieldRecorderState state = const FieldRecorderState(),
    }) async {
      final recorder = FieldRecorder(_api((_) => null));
      recorder.state.value = state;
      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: RecordCard(recorder: recorder, me: ValueNotifier(me)),
          ),
        ),
      );
      await tester.pump();
      return recorder;
    }

    testWidgets('without the grant there is nothing to start', (tester) async {
      await pump(tester, me: {'may_record': false});
      expect(find.byKey(const ValueKey('field-record-start')), findsNothing);
    });

    testWidgets(
      'switched off for the company, it says why and offers no Start',
      (tester) async {
        await pump(
          tester,
          me: {
            'may_record': true,
            'recording': {
              'allowed': false,
              'announce': false,
              'reason': 'Recording is off for Brightside Home.',
            },
          },
        );
        expect(
          find.text('Recording is off for Brightside Home.'),
          findsOneWidget,
        );
        expect(find.byKey(const ValueKey('field-record-start')), findsNothing);
      },
    );

    testWidgets('Start shows the disclosure, and where announced it waits '
        'for "I told the customer"', (tester) async {
      await pump(
        tester,
        me: {
          'may_record': true,
          'at_job': {'id': 'j1', 'number': 1042},
          'recording': {
            'allowed': true,
            'announce': true,
            'notice': 'This visit may be recorded for safety and quality.',
          },
        },
      );
      expect(find.text('At JOB-1042'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('field-record-start')));
      await tester.pump();
      expect(
        find.byKey(const ValueKey('field-record-disclosure')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('field-record-notice')), findsOneWidget);
      final start = tester.widget<FilledButton>(
        find.byKey(const ValueKey('field-record-confirm')),
      );
      expect(
        start.onPressed,
        isNull,
        reason: 'The customer must be told first.',
      );

      await tester.tap(find.byKey(const ValueKey('field-record-told')));
      await tester.pump();
      expect(
        tester
            .widget<FilledButton>(
              find.byKey(const ValueKey('field-record-confirm')),
            )
            .onPressed,
        isNotNull,
      );
    });

    testWidgets('recording shows the time and Stop asks why, inline', (
      tester,
    ) async {
      await pump(
        tester,
        me: {'may_record': true},
        state: FieldRecorderState(
          status: FieldRecorderStatus.recording,
          startedAt: DateTime.now().subtract(const Duration(minutes: 12)),
          unsent: 2,
        ),
      );
      expect(find.textContaining('Recording · 12:'), findsOneWidget);
      expect(find.text('2 minutes still to send'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('field-record-stop')));
      await tester.pump();
      expect(find.text('The customer asked'), findsOneWidget);
      expect(find.byType(AlertDialog), findsNothing);
    });
  });

  group('Recordings tab', () {
    final row = {
      'id': 'r1',
      'user': {'id': 'u1', 'name': 'Sam Ortiz'},
      'job': {'id': 'j1', 'number': 1042, 'title': 'Sweep'},
      'started_at': '2026-10-16T13:12:00Z',
      'ended_at': '2026-10-16T14:00:00Z',
      'stop_reason': 'done',
      'duration_s': 2880,
      'chunks': 48,
      'events': [
        {'type': 'phone_call', 'at': '2026-10-16T13:31:00Z'},
      ],
      'flagged': false,
      'flag_reason': null,
      'delete_on': '2027-01-14',
    };

    testWidgets('a row opens the player, and Keep needs a reason', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(1400, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final posted = <Map<String, dynamic>>[];

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(
            body: FieldRecordingsTab(
              api: _api((r) {
                if (r.method == 'POST') {
                  posted.add(jsonDecode(r.body) as Map<String, dynamic>);
                }
                return {
                  'data': r.method == 'GET' ? [row] : row,
                };
              }),
              canListen: true,
              canKeep: true,
              player: _SilentPlayer(),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Sam Ortiz'), findsOneWidget);
      expect(find.text('Deletes 2027-01-14'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('field-recording-r1')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('field-recording-panel')),
        findsOneWidget,
      );
      expect(find.textContaining('stopped: Done'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('field-recording-keep')));
      await tester.pumpAndSettle();
      final keep = tester.widget<FilledButton>(
        find.byKey(const ValueKey('field-recording-keep-save')),
      );
      expect(keep.onPressed, isNull, reason: 'Keeping needs a reason.');

      await tester.enterText(
        find.byKey(const ValueKey('field-recording-reason')),
        'Customer complaint',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('field-recording-keep-save')));
      await tester.pumpAndSettle();

      expect(posted.single, {'flagged': true, 'reason': 'Customer complaint'});
    });
  });
}

ApiClient _api(Object? Function(http.Request) answer) => ApiClient(
  client: MockClient((request) async {
    final body = answer(request);
    return http.Response(
      jsonEncode(body ?? {'data': null}),
      200,
      headers: const {'content-type': 'application/json'},
    );
  }),
);

class _SilentPlayer implements RecordingPlayer {
  final _changes = StreamController<RecordingPlayback>.broadcast();

  @override
  RecordingPlayback get state => const RecordingPlayback();

  @override
  Stream<RecordingPlayback> get changes => _changes.stream;

  @override
  Future<void> play(List<int> bytes) async {}

  @override
  Future<void> pause() async {}

  @override
  Future<void> resume() async {}

  @override
  Future<void> stop() async {}

  @override
  Future<void> seek(Duration to) async {}

  @override
  double get speed => 1;

  @override
  Future<void> setSpeed(double speed) async {}

  @override
  void dispose() => _changes.close();
}
