import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/jobs/job_notes.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-11-17: "i cannot edit notes on project management". The
/// author changes their own note, and Workiz's notes are edited in place;
/// a refused Workiz write keeps the text. Invented people only.
void main() {
  Future<List<http.Request>> pump(
    WidgetTester tester, {
    required List<Map<String, dynamic>> notes,
    String? workiz,
    bool refuseWorkiz = false,
  }) async {
    final sent = <http.Request>[];
    final api = ApiClient(
      client: MockClient((request) async {
        sent.add(request);
        if (request.url.path.endsWith('/workiz-notes')) {
          return refuseWorkiz
              ? _json({
                  'message': 'This branch is not on its Workiz v2 key.',
                }, 422)
              : _json({
                  'data': {
                    'workiz_notes': jsonDecode(request.body)['text'],
                  },
                });
        }
        if (request.method == 'PATCH') {
          return _json({
            'data': {
              ...notes.first,
              'body': jsonDecode(request.body)['body'],
              'edited_at': '2026-11-17T15:00:00Z',
            },
          });
        }
        return _json({'data': <String, dynamic>{}});
      }),
    );
    var shown = notes;
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SingleChildScrollView(
            child: StatefulBuilder(
              builder: (context, setState) => JobNotes(
                api: api,
                jobId: 'j-1',
                notes: shown,
                draft: TextEditingController(),
                formats: DisplayFormats.fallback,
                workizNotes: workiz,
                onAdded: (_) {},
                onChanged: (next) => setState(() => shown = next),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return sent;
  }

  testWidgets('the author edits their note, and it reads edited', (
    tester,
  ) async {
    final sent = await pump(
      tester,
      notes: [
        {
          'id': 'n-1',
          'body': 'Call 30 min before.',
          'author': {'id': 'u-1', 'name': 'Shoval'},
          'created_at': '2026-11-17T14:00:00Z',
          'can_change': true,
        },
        {
          'id': 'n-2',
          'body': 'Gate code 1234.',
          'author': {'id': 'u-2', 'name': 'Dana'},
          'created_at': '2026-11-17T13:00:00Z',
          'can_change': false,
        },
      ],
    );

    expect(find.byKey(const ValueKey('job-note-menu-n-2')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('job-note-menu-n-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('job-note-edit-item-n-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('job-note-edit-n-1')),
      'Call 1 hour before.',
    );
    await tester.tap(find.byKey(const ValueKey('job-note-save-n-1')));
    await tester.pumpAndSettle();

    expect(sent.last.method, 'PATCH');
    expect(sent.last.url.path, endsWith('/jobs/j-1/notes/n-1'));
    expect(find.text('Call 1 hour before.'), findsOneWidget);
    expect(find.textContaining('edited'), findsOneWidget);
  });

  testWidgets('Workiz notes: a refusal keeps the text; Save writes it', (
    tester,
  ) async {
    await pump(
      tester,
      notes: const [],
      workiz: 'Level 2 \$149',
      refuseWorkiz: true,
    );
    expect(find.textContaining('READ ONLY'), findsNothing);

    await tester.tap(find.byKey(const Key('job-notes-workiz-edit')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('job-notes-workiz-box')),
      'Level 2 \$149, call 30 mins before',
    );
    await tester.tap(find.byKey(const Key('job-notes-workiz-save')));
    await tester.pumpAndSettle();

    expect(
      find.text('This branch is not on its Workiz v2 key.'),
      findsOneWidget,
    );
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('job-notes-workiz-box')))
          .controller!
          .text,
      'Level 2 \$149, call 30 mins before',
    );
  });

  testWidgets('Workiz notes saved show the new text', (tester) async {
    final sent = await pump(tester, notes: const [], workiz: 'Level 2 \$149');

    await tester.tap(find.byKey(const Key('job-notes-workiz-edit')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('job-notes-workiz-box')),
      'Chimney sweep \$299',
    );
    await tester.tap(find.byKey(const Key('job-notes-workiz-save')));
    await tester.pumpAndSettle();

    expect(sent.last.method, 'PUT');
    expect(jsonDecode(sent.last.body), {
      'text': 'Chimney sweep \$299',
      'was': 'Level 2 \$149',
    });
    expect(find.byKey(const Key('job-notes-workiz-box')), findsNothing);
    expect(find.text('Chimney sweep \$299'), findsOneWidget);
  });
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
