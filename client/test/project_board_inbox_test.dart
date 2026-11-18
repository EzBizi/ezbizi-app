import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management › Inbox (Jose, 2026-11-17): one thread per board job,
/// the client's answers included, and a reply from the branch line.
/// Invented people only.
void main() {
  testWidgets('the Inbox lists a client, opens the thread and replies', (
    tester,
  ) async {
    final posts = <Map<String, dynamic>>[];
    final api = _api(posts);
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1600, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: ProjectBoardScreen(api: api, session: session)),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Inbox'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('project-inbox')), findsOneWidget);
    // All is the default (Shoval, 2026-11-18); the filters are one row.
    expect(find.text('All · 2'), findsOneWidget);
    expect(find.byKey(const ValueKey('project-inbox-thread-j-2')), findsOneWidget);
    final filters = [
      for (final k in ['all', 'waiting', 'replied'])
        tester.getRect(find.byKey(ValueKey('project-inbox-filter-$k'))).top,
    ];
    expect(filters.toSet(), hasLength(1), reason: 'one row, never wrapped');
    // A row as in Leads: the name, the job, what it waits on.
    expect(find.text('Lena Park'), findsWidgets);
    expect(find.text('J-1001'), findsOneWidget);
    expect(find.byKey(const ValueKey('project-inbox-waiting-j-1')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('project-inbox-state-j-1')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('project-inbox-filter-waiting')));
    await tester.pumpAndSettle();
    expect(find.text('Waiting · 1'), findsOneWidget);
    expect(find.byKey(const ValueKey('project-inbox-thread-j-2')), findsNothing);
    expect(session.me!.preferences['project_board.inbox_filter'], 'waiting');
    await tester.tap(find.byKey(const ValueKey('project-inbox-filter-replied')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('project-inbox-thread-j-2')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('project-inbox-thread-j-1')));
    await tester.pumpAndSettle();
    expect(find.text('Can we come Tuesday 8-11?'), findsOneWidget);

    // The header: who, which job, where it stands.
    expect(find.byKey(const Key('project-inbox-header')), findsOneWidget);
    expect(find.byKey(const Key('project-inbox-stage')), findsOneWidget);
    expect(find.text('Scheduled'), findsWidgets);
    expect(find.byKey(const Key('project-inbox-open-job')), findsOneWidget);
    // Who sent each one; the client's on the left.
    expect(find.textContaining('Automatic · Review request ·'), findsOneWidget);
    expect(find.textContaining('Dana ·'), findsOneWidget);
    expect(find.textContaining('Lena Park ·'), findsOneWidget);
    final theirs = tester.getCenter(
      find.descendant(
        of: find.byKey(const ValueKey('project-inbox-message-m-2')),
        matching: find.text('NO'),
      ),
    );
    final ours = tester.getCenter(find.text('Can we come Tuesday 8-11?'));
    expect(theirs.dx, lessThan(ours.dx));
    // The composer sits at the bottom, under the newest text, on screen.
    final box = tester.getRect(find.byKey(const Key('project-inbox-box')));
    expect(box.bottom, lessThanOrEqualTo(1400));
    expect(
      box.top,
      greaterThan(
        tester.getRect(find.byKey(const ValueKey('project-inbox-message-m-2'))).bottom,
      ),
    );
    expect(box.bottom, greaterThan(1200), reason: 'pinned to the bottom');

    await tester.enterText(
      find.byKey(const Key('project-inbox-box')),
      'Wednesday works too.',
    );
    await tester.tap(find.byKey(const Key('project-inbox-send')));
    await tester.pumpAndSettle();
    expect(posts.single, {'body': 'Wednesday works too.'});
    expect(find.text('Wednesday works too.'), findsOneWidget);

    await tester.pumpWidget(const SizedBox()); // stops the board's live poll
  });

  testWidgets('on a 390 phone: the list, then the thread with its header '
      'and composer, and back', (tester) async {
    final api = _api([]);
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: ProjectBoardScreen(api: api, session: session)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Inbox'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('project-inbox-thread-j-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('project-inbox-header')), findsOneWidget);
    expect(
      tester.getRect(find.byKey(const Key('project-inbox-box'))).bottom,
      lessThanOrEqualTo(844),
    );
    await tester.tap(find.byKey(const Key('project-inbox-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('project-inbox-list')), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });
}

ApiClient _api(List<Map<String, dynamic>> posts) => ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana',
            'email': 'dana@brightside.example',
            'permissions': ['project_board.view', 'jobs.view', 'jobs.edit'],
            'modules': <dynamic>[],
            'organization': {'id': 'org-1', 'name': 'Brightside'},
          },
        });
      }
      if (path.endsWith('/settings')) return _json({'data': <dynamic>[]});
      if (path.endsWith('/project-board/inbox/j-1')) {
        if (request.method == 'POST') {
          posts.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({
            'data': {
              'id': 'm-3',
              'body': 'Wednesday works too.',
              'direction': 'outbound',
              'at': '2026-11-17T15:00:00Z',
              'sent_by': 'Dana',
              'automatic': false,
            },
          });
        }
        return _json({
          'job': {
            'id': 'j-1',
            'number': 'J-1001',
            'customer': 'Lena Park',
            'phone': '+15125550101',
            'stage': 'scheduled',
          },
          'data': [
            {
              'id': 'm-0',
              'body': 'Thank you for working with us!',
              'direction': 'outbound',
              'at': '2026-11-17T12:00:00Z',
              'sent_by': null,
              'automatic': true,
              'automation_name': 'Review request',
            },
            {
              'id': 'm-1',
              'body': 'Can we come Tuesday 8-11?',
              'direction': 'outbound',
              'at': '2026-11-17T13:00:00Z',
              'sent_by': 'Dana',
              'automatic': false,
            },
            {
              'id': 'm-2',
              'body': 'NO',
              'direction': 'inbound',
              'at': '2026-11-17T14:00:00Z',
            },
          ],
        });
      }
      if (path.endsWith('/project-board/inbox')) {
        return _json({
          'data': [
            {
              'job_id': 'j-1',
              'job_number': 'J-1001',
              'customer': 'Lena Park',
              'last_body': 'NO',
              'last_at': '2026-11-17T14:00:00Z',
              'last_direction': 'inbound',
              'waiting': true,
              'client_spoke': true,
            },
            // Only our review text: no answer, so not Waiting or Replied.
            {
              'job_id': 'j-2',
              'job_number': 'J-1002',
              'customer': 'Omar Diaz',
              'last_body': 'Thank you for working with us!',
              'last_at': '2026-11-17T12:00:00Z',
              'last_direction': 'outbound',
              'waiting': false,
              'client_spoke': false,
            },
          ],
        });
      }
      return _json({
        'data': <dynamic>[],
        'meta': {
          'total': 0,
          'last_page': 1,
          'counts': <String, dynamic>{},
          'places': <dynamic>[],
          'columns': <dynamic>[],
          'labels': <String, dynamic>{},
          'can_edit': true,
        },
      });
    }),
  );

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
