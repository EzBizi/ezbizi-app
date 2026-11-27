import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/task_board_gallery.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The board's Gallery view (Jose, 2026-11-12).
void main() {
  final png = base64Decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  );
  const root = 'http://localhost/api/v1/attachments';
  final items = [
    {
      'id': 'p1',
      'kind': 'photo',
      'name': 'roof.jpg',
      'mime': 'image/jpeg',
      'size': 2048,
      'created_at': '2026-11-10T14:00:00Z',
      'uploader': {'id': 'u1', 'name': 'Maya Lopez'},
      'task': {'id': 't1', 'name': 'Roof job'},
      'url': '$root/p1',
      'inline_url': '$root/p1?inline=1',
      'thumbnail_url': '$root/p1?inline=1',
    },
    {
      'id': 'v1',
      'kind': 'video',
      'name': 'walkthrough.mp4',
      'mime': 'video/mp4',
      'size': 9000000,
      'created_at': '2026-11-09T14:00:00Z',
      'uploader': {'id': 'u2', 'name': 'Omar Feld'},
      'task': {'id': 't2', 'name': 'Flashing'},
      'url': '$root/v1',
      'inline_url': null,
      'thumbnail_url': null,
      'stream_url': '$root/v1/stream?signature=x',
      'poster_url': '$root/v1/stream?poster=1&signature=x',
    },
  ];

  late List<Map<String, String>> queries;

  ApiClient server({bool empty = false}) {
    queries = [];
    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path.replaceFirst('/api/v1', '');
        if (path == '/task-boards/b1/attachments') {
          queries.add(request.url.queryParameters);
          final type = request.url.queryParameters['type'];
          final data = empty
              ? const []
              : items.where((i) => type == null || i['kind'] == type).toList();
          return http.Response(
            jsonEncode({
              'data': data,
              'next_cursor': null,
              'people': [
                {'id': 'u1', 'name': 'Maya Lopez'},
                {'id': 'u2', 'name': 'Omar Feld'},
              ],
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }
        if (path.startsWith('/attachments/')) {
          return http.Response.bytes(png, 200);
        }
        return http.Response('{}', 404);
      }),
    );
  }

  final opened = <String>[];

  Future<void> pump(WidgetTester tester, ApiClient api) async {
    opened.clear();
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
  }

  testWidgets('shows every file with its task, and filters by type', (
    tester,
  ) async {
    await pump(tester, server());
    expect(find.byKey(const Key('gallery-grid')), findsOneWidget);
    expect(find.byKey(const ValueKey('gallery-tile-p1')), findsOneWidget);
    expect(find.byKey(const ValueKey('gallery-tile-v1')), findsOneWidget);
    expect(find.text('Roof job'), findsOneWidget);
    expect(find.text('Flashing'), findsOneWidget);
    expect(find.byIcon(Icons.play_circle_fill), findsOneWidget);
    expect(find.byKey(const Key('gallery-person-u2')), findsOneWidget);

    await tester.tap(find.byKey(const Key('gallery-type-video')));
    await tester.pumpAndSettle();
    expect(queries.last['type'], 'video');
    expect(find.byKey(const ValueKey('gallery-tile-p1')), findsNothing);
    expect(find.byKey(const ValueKey('gallery-tile-v1')), findsOneWidget);
  });

  testWidgets('a video opens a panel with Download and Go to task', (
    tester,
  ) async {
    await pump(tester, server());
    expect(find.byKey(const ValueKey('gallery-poster-v1')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('gallery-tile-v1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('gallery-file-panel')), findsOneWidget);
    expect(find.byKey(const Key('gallery-video')), findsOneWidget);
    expect(find.byKey(const Key('gallery-download')), findsOneWidget);
    await tester.tap(find.byKey(const Key('gallery-go-to-task')));
    await tester.pumpAndSettle();
    expect(opened, ['t2']);
  });

  testWidgets('an empty board says so', (tester) async {
    await pump(tester, server(empty: true));
    expect(find.byKey(const Key('gallery-empty')), findsOneWidget);
  });
}
