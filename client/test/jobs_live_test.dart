import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/live_changes.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/job_detail_panel.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jobs follow the change feed (Jose, 2026-11-16: "The tab needs to be live
/// basically"): a sync's write shows on the open list and the open job
/// without leaving, and the list keeps its scroll.
void main() {
  testWidgets('a changed job re-reads the list in place, scroll kept', (
    tester,
  ) async {
    final s = await _pump(
      tester,
      (api, session) => JobsScreen(api: api, session: session),
    );
    expect(find.text('Job 3'), findsOneWidget);

    final scroll = tester.state<ScrollableState>(find.byType(Scrollable).last);
    scroll.position.jumpTo(300);
    await tester.pump();
    final before = scroll.position.pixels;
    expect(before, greaterThan(0));
    final reads = s.listReads;

    s.rows[12] = {...s.rows[12], 'title': 'Swept by Workiz'};
    s.feed = [
      {'type': 'jobs', 'id': 'j12', 'deleted': false},
    ];
    await _tick(tester, s.api);

    expect(s.listReads, reads + 1, reason: 'one quiet re-read, no reload');
    expect(find.text('Swept by Workiz'), findsOneWidget);
    expect(
      tester
          .state<ScrollableState>(find.byType(Scrollable).last)
          .position
          .pixels,
      before,
    );
    expect(find.byType(LinearProgressIndicator), findsNothing);
  });

  testWidgets('nothing changed: nothing is read again', (tester) async {
    final s = await _pump(
      tester,
      (api, session) => JobsScreen(api: api, session: session),
    );
    final reads = s.listReads;
    s.feed = const [];
    await _tick(tester, s.api);
    expect(s.listReads, reads);
  });

  testWidgets('the open job shows a change made elsewhere', (tester) async {
    final s = await _pump(
      tester,
      (api, session) => JobDetailPanel(api: api, session: session, id: 'j0'),
    );
    expect(find.text('Job 0'), findsWidgets);

    s.rows[0] = {...s.rows[0], 'title': 'Renamed by automation'};
    s.feed = [
      {'type': 'jobs', 'id': 'j0', 'deleted': false},
    ];
    await _tick(tester, s.api);
    expect(find.text('Renamed by automation'), findsWidgets);
  });
}

class _Server {
  _Server(this.rows);
  final List<Map<String, dynamic>> rows;
  List<Map<String, dynamic>> feed = const [];
  int cursor = 1;
  int listReads = 0;
  late ApiClient api;
}

Future<void> _tick(WidgetTester tester, ApiClient api) async {
  LiveChanges.of(api).poll();
  await tester.pumpAndSettle();
}

Future<_Server> _pump(
  WidgetTester tester,
  Widget Function(ApiClient api, Session session) build,
) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final s = _Server([
    for (var i = 0; i < 40; i++)
      {
        'id': 'j$i',
        'number': 'J-$i',
        'title': 'Job $i',
        'status': 'Pending',
        'status_label': 'Pending',
        'scheduled_for': null,
        'customer': {'id': 'c$i', 'name': 'Customer $i'},
        'description': null,
        'duration_minutes': null,
        'allowed_statuses': ['Pending', 'Done'],
        'allowed_service_areas': <dynamic>[],
        'created_at': '2026-11-01T10:00:00Z',
      },
  ]);
  s.api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({'data': _me});
      }
      if (path.endsWith('/changes')) {
        final since = request.url.queryParameters['since'];
        final out = since == null ? const [] : s.feed;
        s.feed = const [];
        if (out.isNotEmpty) s.cursor++;
        return _json({
          'data': {'cursor': s.cursor, 'reload': false, 'changes': out},
        });
      }
      final one = RegExp(r'/jobs/([^/]+)$').firstMatch(path);
      if (one != null) {
        return _json({
          'data': s.rows.firstWhere((r) => r['id'] == one.group(1)),
        });
      }
      if (path.endsWith('/jobs')) {
        s.listReads++;
        return _json({
          'data': s.rows,
          'meta': {'total': s.rows.length, 'last_page': 1, 'current_page': 1},
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 404);
    }),
  );
  final session = Session(s.api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: build(s.api, session)),
    ),
  );
  await tester.pumpAndSettle();
  return s;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Dispatcher'},
  'has_avatar': false,
  'permissions': ['jobs.view', 'jobs.edit'],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
