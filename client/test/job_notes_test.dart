import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/jobs/job_detail_panel.dart';
import 'package:ezbizi_client/screens/jobs/job_notes.dart';
import 'package:ezbizi_client/widgets/refused.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A job's notes (UI queue 122; Jose: option 1 and phone 4). Every name here
/// is invented.
void main() {
  final now = DateTime.now();
  String stamp(DateTime at) => at.toIso8601String();
  final today = DateTime(now.year, now.month, now.day, now.hour, now.minute);
  final yesterday = DateTime(now.year, now.month, now.day - 1, 16, 5);

  Map<String, dynamic> note(String id, String body, String? who, DateTime at) =>
      {
        'id': id,
        'body': body,
        'author': who == null ? null : {'id': 'u-$id', 'name': who},
        'created_at': stamp(at),
      };

  final two = [
    note(
      'n-2',
      'Customer wants the cap replaced too.\nQuote by text.',
      'Rowan Pike',
      today,
    ),
    note('n-1', 'Gate code 2291#. Dog in the yard.', 'Ilse Marr', yesterday),
  ];

  Future<void> openNotes(WidgetTester tester) async {
    // The strip scrolls sideways on a phone.
    await tester.ensureVisible(_tab('Notes'));
    await tester.pumpAndSettle();
    await tester.tap(_tab('Notes'));
    await tester.pumpAndSettle();
  }

  testWidgets('notes render newest first, with author and time', (
    tester,
  ) async {
    final server = _Server(notes: two);
    await _pumpPanel(tester, server, permissions: ['jobs.view', 'jobs.edit']);

    expect(_tab('Notes · 2'), findsOneWidget);
    await openNotes(tester);

    final first = find.byKey(const ValueKey('job-note-n-2'));
    final second = find.byKey(const ValueKey('job-note-n-1'));
    expect(tester.getTopLeft(first).dy, lessThan(tester.getTopLeft(second).dy));
    expect(
      find.descendant(of: first, matching: find.text('Rowan Pike')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: first, matching: find.text('RP')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: first, matching: find.textContaining('today ')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: second, matching: find.text('Ilse Marr')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: second, matching: find.textContaining('yesterday ')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: second, matching: find.byType(SelectableText)),
      findsOneWidget,
    );
    final tip = tester.widget<Tooltip>(
      find.descendant(of: second, matching: find.byType(Tooltip)),
    );
    expect(tip.message, contains('16'));
    expect(tip.message, contains('05'));
  });

  testWidgets('Add note posts the body and puts it on top', (tester) async {
    final server = _Server(notes: [two.last]);
    await _pumpPanel(tester, server, permissions: ['jobs.view', 'jobs.edit']);
    await openNotes(tester);

    await tester.enterText(
      find.byKey(const Key('job-note-box')),
      '  Bring the tall ladder.  ',
    );
    await tester.tap(find.byKey(const Key('job-note-add')));
    await tester.pumpAndSettle();

    final post = server.posts.single;
    expect(post.url.path, endsWith('/api/v1/jobs/$_jobId/notes'));
    expect(jsonDecode(post.body), {'body': 'Bring the tall ladder.'});
    expect(server.jobLoads, 1, reason: 'no reload of the panel');
    expect(_tab('Notes · 2'), findsOneWidget);

    final added = find.byKey(const ValueKey('job-note-new-1'));
    expect(
      tester.getTopLeft(added).dy,
      lessThan(
        tester.getTopLeft(find.byKey(const ValueKey('job-note-n-1'))).dy,
      ),
    );
    expect(find.text('Bring the tall ladder.'), findsOneWidget);
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('job-note-box')))
          .controller!
          .text,
      isEmpty,
    );
  });

  testWidgets('Ctrl+Enter adds the note', (tester) async {
    final server = _Server(notes: const []);
    await _pumpPanel(tester, server, permissions: ['jobs.view', 'jobs.edit']);
    await openNotes(tester);

    await tester.enterText(find.byKey(const Key('job-note-box')), 'Swept.');
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    await tester.pumpAndSettle();

    expect(server.posts, hasLength(1));
    expect(jsonDecode(server.posts.single.body), {'body': 'Swept.'});
    expect(_tab('Notes · 1'), findsOneWidget);
  });

  testWidgets('a refused note keeps the text and says why', (tester) async {
    final server = _Server(notes: const [], refuse: true);
    await _pumpPanel(tester, server, permissions: ['jobs.view', 'jobs.edit']);
    await openNotes(tester);

    await tester.enterText(
      find.byKey(const Key('job-note-box')),
      'Half a thought',
    );
    await tester.tap(find.byKey(const Key('job-note-add')));
    await tester.pumpAndSettle();

    expect(find.text('A note needs some words.'), findsOneWidget);
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('job-note-box')))
          .controller!
          .text,
      'Half a thought',
    );
    expect(find.text('No notes yet'), findsOneWidget);
  });

  testWidgets('without jobs.edit the box is greyed and says why', (
    tester,
  ) async {
    final server = _Server(notes: [two.first]);
    await _pumpPanel(tester, server, permissions: ['jobs.view']);
    await openNotes(tester);

    final box = tester.widget<TextField>(find.byKey(const Key('job-note-box')));
    expect(box.enabled, isFalse);
    final add = tester.widget<FilledButton>(
      find.byKey(const Key('job-note-add')),
    );
    expect(add.onPressed, isNull);
    final refusals = tester
        .widgetList<Refused>(
          find.ancestor(
            of: find.byKey(const Key('job-note-box')),
            matching: find.byType(Refused),
          ),
        )
        .map((r) => r.reason);
    expect(refusals.single, contains('permission to change a job'));
    expect(find.text('Rowan Pike'), findsOneWidget);
  });

  testWidgets('the Job tab\'s latest-note line opens the Notes tab', (
    tester,
  ) async {
    final server = _Server(notes: two);
    await _pumpPanel(tester, server, permissions: ['jobs.view', 'jobs.edit']);

    final line = find.byKey(const Key('job-latest-note'));
    expect(line, findsOneWidget);
    final text = tester.widget<Text>(
      find.descendant(of: line, matching: find.byType(Text)),
    );
    expect(
      text.textSpan!.toPlainText(),
      startsWith(
        'Latest note: Customer wants the cap replaced too. · Rowan Pike · today ',
      ),
    );
    expect(find.byKey(const Key('job-notes')), findsNothing);

    await tester.tap(line);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('job-notes')), findsOneWidget);
  });

  testWidgets('no notes: "No notes yet", and no latest-note line', (
    tester,
  ) async {
    final server = _Server(notes: const []);
    await _pumpPanel(tester, server, permissions: ['jobs.view', 'jobs.edit']);

    expect(find.byKey(const Key('job-latest-note')), findsNothing);
    expect(_tab('Notes'), findsOneWidget);
    expect(
      find.text('Notes'),
      findsOneWidget,
      reason: 'only the tab: the job field is Description',
    );
    expect(find.widgetWithText(TextField, 'Description'), findsOneWidget);
    await openNotes(tester);

    expect(find.text('No notes yet'), findsOneWidget);
  });

  testWidgets('a phone keeps the box on top and drops the avatars', (
    tester,
  ) async {
    final server = _Server(notes: two);
    await _pumpPanel(
      tester,
      server,
      permissions: ['jobs.view', 'jobs.edit'],
      size: const Size(390, 900),
    );
    await openNotes(tester);

    final box = find.byKey(const Key('job-note-box'));
    final first = find.byKey(const ValueKey('job-note-n-2'));
    expect(tester.getTopLeft(box).dy, lessThan(tester.getTopLeft(first).dy));
    expect(
      find.descendant(
        of: first,
        matching: find.textContaining('Rowan Pike · today '),
      ),
      findsOneWidget,
    );
    expect(find.text('RP'), findsNothing);
  });

  group('noteWhen and noteAuthor', () {
    const formats = DisplayFormats(dateFormat: 'YYYY-MM-DD', timeFormat: '24h');
    final at = DateTime(2026, 3, 10, 14, 30);

    test('today, yesterday, then the date', () {
      expect(
        noteWhen(
          stamp(DateTime(2026, 3, 10, 9, 40)),
          formats: formats,
          now: at,
        ),
        'today 09:40',
      );
      expect(
        noteWhen(stamp(DateTime(2026, 3, 9, 16, 5)), formats: formats, now: at),
        'yesterday 16:05',
      );
      expect(
        noteWhen(stamp(DateTime(2026, 3, 7, 8, 0)), formats: formats, now: at),
        '2026-03-07',
      );
    });

    test('a note whose writer was deleted reads as a former user', () {
      expect(noteAuthor(note('x', 'Hi', null, at)), 'Former user');
      expect(noteAuthor(note('y', 'Hi', 'Juno Hale', at)), 'Juno Hale');
    });
  });
}

const _jobId = 'job-7';

Finder _tab(String label) => find.descendant(
  of: find.byKey(const Key('job-tabs')),
  matching: find.textContaining(label),
);

class _Server {
  _Server({required this.notes, this.refuse = false});

  final List<Map<String, dynamic>> notes;
  final bool refuse;
  final posts = <http.Request>[];
  int jobLoads = 0;
  int _added = 0;

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (request.method == 'POST' && path.endsWith('/jobs/$_jobId/notes')) {
      posts.add(request);
      if (refuse) {
        return _json({'message': 'A note needs some words.'}, 422);
      }
      _added++;
      final body = jsonDecode(request.body) as Map;
      return _json({
        'data': {
          'id': 'new-$_added',
          'body': (body['body'] as String).trim(),
          'author': {'id': 'u-1', 'name': 'Dana Reyes'},
          'created_at': DateTime.now().toIso8601String(),
        },
      }, 201);
    }
    if (path.endsWith('/jobs/$_jobId')) {
      jobLoads++;
      return _json({
        'data': {
          'id': _jobId,
          'number': 'J-30411',
          'title': 'Flue inspection',
          'status': 'Pending',
          'status_label': 'Pending',
          'scheduled_for': null,
          'franchise': {'id': 'fr-1', 'name': 'Lantern Chimney'},
          'customer': {
            'id': 'c-1',
            'name': 'Avery Stone',
            'owned_by_this_franchise': true,
          },
          'service_area': null,
          'description': null,
          'duration_minutes': null,
          'allowed_statuses': ['Pending', 'Done'],
          'allowed_service_areas': <dynamic>[],
          'money': null,
          'notes': notes,
        },
      });
    }
    if (path.endsWith('/attachments')) {
      return _json({'data': <dynamic>[]});
    }

    return _json({'message': 'Not found'}, 404);
  }
}

Future<void> _pumpPanel(
  WidgetTester tester,
  _Server server, {
  required List<String> permissions,
  Size size = const Size(1000, 1400),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana Reyes',
            'email': 'dana@example.test',
            'role': {'name': 'Dispatcher'},
            'has_avatar': false,
            'preferences': <String, dynamic>{},
            'permissions': permissions,
            'organization': {
              'id': 'org-1',
              'name': 'Lantern Group',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'scope': {
              'type': 'organization',
              'id': 'org-1',
              'label': 'Lantern Group',
              'path': ['Lantern Group'],
            },
            'companies': <dynamic>[],
            'franchises': <dynamic>[],
          },
        });
      }

      return server.handle(request);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: JobDetailPanel(
          api: api,
          session: session,
          id: _jobId,
          onDone: (_) {},
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
