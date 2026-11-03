import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/field_recorder.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/job_record_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Design 177, part B: the job header's Record pill. Invented people only.
void main() {
  const told = {
    'may_record': true,
    'recording': {
      'allowed': true,
      'announce': true,
      'notice': 'This visit may be recorded for safety and quality.',
    },
  };

  Future<_Spy> pump(
    WidgetTester tester, {
    required Map<String, dynamic> me,
    FieldRecorderState state = const FieldRecorderState(),
  }) async {
    final recorder = _Spy()..state.value = state;
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Center(
            child: JobRecordPill(
              jobId: 'j1',
              recorder: recorder,
              me: ValueNotifier(me),
            ),
          ),
        ),
      ),
    );
    await tester.pump();
    return recorder;
  }

  testWidgets('without the grant, or switched off, there is no Record', (
    tester,
  ) async {
    await pump(tester, me: {'may_record': false});
    expect(find.byKey(const ValueKey('job-record-start')), findsNothing);

    await pump(
      tester,
      me: {
        'may_record': true,
        'recording': {'allowed': false},
      },
    );
    expect(find.byKey(const ValueKey('job-record-start')), findsNothing);
  });

  testWidgets('Record asks in a side panel, and waits for "I told the '
      'customer" before it starts this job', (tester) async {
    final recorder = await pump(tester, me: told);

    await tester.tap(find.byKey(const ValueKey('job-record-start')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('job-record-disclosure')), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(
            find.byKey(const ValueKey('job-record-confirm')),
          )
          .onPressed,
      isNull,
    );

    await tester.tap(find.byKey(const ValueKey('job-record-told')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('job-record-confirm')));
    await tester.pumpAndSettle();
    expect(recorder.started, [('j1', true)]);
  });

  testWidgets('recording this job, it is a red timer that stops', (
    tester,
  ) async {
    final recorder = await pump(
      tester,
      me: told,
      state: FieldRecorderState(
        status: FieldRecorderStatus.recording,
        jobId: 'j1',
        startedAt: DateTime.now().subtract(const Duration(seconds: 12)),
      ),
    );

    expect(find.textContaining('00:1'), findsOneWidget);
    expect(find.textContaining('Stop'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('job-record-stop')));
    await tester.pump();
    expect(recorder.stopped, ['done']);
  });
}

class _Spy extends FieldRecorder {
  _Spy()
    : super(
        ApiClient(client: MockClient((_) async => http.Response('{}', 200))),
      );

  final started = <(String?, bool)>[];
  final stopped = <String>[];

  @override
  Future<bool> start({String? jobId, bool customerTold = false}) async {
    started.add((jobId, customerTold));
    return true;
  }

  @override
  Future<void> stop(String reason) async => stopped.add(reason);
}
