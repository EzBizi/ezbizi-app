import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_gallery.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Sensitive tasks (Jose, 2026-11-12): a lock on the row and the drawer,
/// "Unlock with authenticator" → a code → the content for 5 minutes, then
/// locked again; mark/unmark in the task menu; locked Gallery tiles.
void main() {
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbs');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  Map<String, dynamic> task(
    String id,
    String title, {
    bool sensitive = false,
    bool locked = false,
  }) => {
    'id': id,
    'board_id': 'b1',
    'group_id': 'g1',
    'title': title,
    'sensitive': sensitive,
    'locked': locked,
    'status': 'not_started',
    'values': <String, dynamic>{},
    'owner': null,
    'owners': const [],
    'due_on': null,
    'about': null,
    'updates_count': 1,
    'created_by': null,
  };

  late List<(String, String, Object?)> log;

  ApiClient fakeServer() {
    log = [];
    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        final body = request.body.isEmpty ? null : jsonDecode(request.body);
        if (request.method != 'GET') log.add((request.method, path, body));
        if (path.endsWith('/me')) {
          return json({
            'data': {
              'id': 'me',
              'name': 'Maya Lopez',
              'email': 'maya@example.test',
              'permissions': ['tasks.view', 'tasks.edit'],
              'modules': <dynamic>[],
              'preferences': <String, dynamic>{},
              'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
              'organization': {
                'id': 'org-1',
                'name': 'A-1',
                'franchise_label': 'Franchise',
                'is_read_only': false,
              },
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
            },
          });
        }
        if (path == '/task-boards') {
          return json({
            'data': [
              {
                'id': 'b1',
                'kind': 'company',
                'name': 'A-1',
                'open_for_me': 0,
                'can_edit': true,
              },
            ],
          });
        }
        if (path == '/task-boards/b1') {
          return json({
            'data': {
              'id': 'b1',
              'kind': 'company',
              'name': 'A-1',
              'can_edit': true,
              'statuses': const [
                {'key': 'not_started', 'label': 'Not started'},
              ],
              'groups': [
                {'id': 'g1', 'name': 'Open Tasks', 'colour': '#579BFC'},
              ],
              'tasks': [
                task('t1', 'Logins', sensitive: true, locked: true),
                task('t2', 'Order a cap'),
              ],
            },
          });
        }
        if (path == '/tasks/t1' && request.method == 'GET') {
          return json({
            'data': task('t1', 'Logins', sensitive: true, locked: true)
              ..['updates'] = const [],
          });
        }
        if (path == '/tasks/t1/unlock') {
          if ((body as Map)['code'] != '123456') {
            return json({
              'message': 'That code did not verify.',
              'code': 'authenticator_code_invalid',
            }, 422);
          }
          return json({
            'data': task('t1', 'Logins', sensitive: true)
              ..['updates'] = [
                {
                  'id': 'u1',
                  'body': 'Chase PIN 4321',
                  'author': {'id': 'me', 'name': 'Maya Lopez'},
                  'created_at': '2026-11-12T12:00:00Z',
                  'attachments': const [],
                },
              ],
          });
        }
        if (path == '/tasks/t2' && request.method == 'PATCH') {
          return json({'data': task('t2', 'Order a cap', sensitive: true)});
        }
        return json({'message': 'Not found.'}, 404);
      }),
    );
  }

  /// A write's reply lands a beat after the frames settle.
  Future<void> settle(WidgetTester tester) async {
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
  }

  Future<void> pumpBoard(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = fakeServer();
    final session = Session(api);
    await session.adopt('tok');
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TaskBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('a sensitive row wears a lock; the menu marks and unmarks', (
    tester,
  ) async {
    await pumpBoard(tester);
    expect(find.text('🔒 Logins'), findsOneWidget);
    expect(find.text('Order a cap'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('task-menu-t1')));
    await tester.pumpAndSettle();
    expect(find.text('Unmark sensitive'), findsOneWidget);
    await tester.tapAt(const Offset(5, 5));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('task-menu-t2')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Mark sensitive'));
    await tester.pumpAndSettle();
    expect(log.last.$1, 'PATCH');
    expect(log.last.$2, '/tasks/t2');
    expect(log.last.$3, {'sensitive': true});
  });

  testWidgets('the drawer is locked until a code, then locks again', (
    tester,
  ) async {
    await pumpBoard(tester);
    // The name opens the task; Owner and About edit in place (Jose, 2026-11-13).
    await tester.tapAt(
      tester.getTopLeft(find.byKey(const ValueKey('task-t1'))) +
          const Offset(160, 19),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-locked')), findsOneWidget);
    expect(find.text('Chase PIN 4321'), findsNothing);

    await tester.tap(find.byKey(const Key('task-unlock')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('sensitive-unlock-code')),
      '000000',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('sensitive-unlock-confirm')));
    await settle(tester);
    expect(log.last.$2, '/tasks/t1/unlock');
    expect(find.byKey(const Key('sensitive-unlock-error')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('sensitive-unlock-code')),
      '123456',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('sensitive-unlock-confirm')));
    await settle(tester);
    expect(log.last.$2, '/tasks/t1/unlock');
    expect(log.last.$3, {'code': '123456'});
    expect(find.byKey(const Key('task-locked')), findsNothing);
    expect(find.text('Chase PIN 4321'), findsOneWidget);

    await tester.pump(const Duration(minutes: 5, seconds: 1));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('task-locked')), findsOneWidget);
    expect(find.text('Chase PIN 4321'), findsNothing);
  });

  testWidgets('a sensitive file is a locked Gallery tile that opens its task', (
    tester,
  ) async {
    final opened = <String>[];
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/task-boards/b1/attachments')) {
          return json({
            'data': [
              {
                'id': 'a1',
                'locked': true,
                'kind': 'file',
                'name': null,
                'mime': null,
                'size': null,
                'created_at': '2026-11-10T14:00:00Z',
                'uploader': {'id': 'u1', 'name': 'Maya Lopez'},
                'task': {'id': 't1', 'name': 'Logins', 'sensitive': true},
                'url': null,
                'inline_url': null,
                'thumbnail_url': null,
              },
            ],
            'next_cursor': null,
            'people': const [],
          });
        }
        fail('No file request for a locked tile: ${request.url}');
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TaskBoardGallery(
            api: api,
            boardId: 'b1',
            formats: DisplayFormats.platform,
            onOpenTask: (id) {
              opened.add(id);
              return true;
            },
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('gallery-locked-a1')), findsOneWidget);
    expect(find.text('🔒 Logins'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('gallery-tile-a1')));
    await tester.pumpAndSettle();
    expect(opened, ['t1']);
  });
}
