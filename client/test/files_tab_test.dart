import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/files_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Files tab — the viewer half of remote file access. Drives the real
/// request→poll round trip against a MockClient that plays the agent: the first
/// result poll is `pending`, the next is `ready`, exactly as the backend slot
/// fills once the agent answers.
void main() {
  Future<Session> signedIn(ApiClient api) async {
    final session = Session(api);
    await session.adopt('tok');
    return session;
  }

  Widget wrap(ApiClient api, Session session) => MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: FilesTab(api: api, session: session),
        ),
      );

  /// A MockClient standing in for the server + agent. [answers] maps a path to
  /// the agent's eventual answer; the first poll of each request returns
  /// pending, the second returns the answer, so the loading beat is exercised.
  ApiClient fakeServer({
    required List<Map<String, dynamic>> devices,
    required Map<String, Map<String, dynamic>> answers,
    List<Map<String, dynamic>>? requestLog,
  }) {
    var nextId = 0;
    final pathOf = <String, String>{}; // request_id -> path
    final polled = <String>{}; // request_ids polled at least once

    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        final now = DateTime.now().toUtc().toIso8601String();

        if (path.endsWith('/me')) {
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'me', 'name': 'Viewer', 'email': 'v@e.test',
                'permissions': ['monitoring.view', 'monitoring.files.view'],
                'modules': <dynamic>[], 'preferences': <String, dynamic>{},
                'organization': {
                  'id': 'org-1', 'name': 'Everdoors',
                  'franchise_label': 'Franchise', 'is_read_only': false,
                },
                'companies': <dynamic>[], 'franchises': <dynamic>[],
              },
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        if (path.endsWith('/monitoring/devices')) {
          return http.Response(
            jsonEncode({'data': [
              for (final d in devices) {...d, 'last_seen_at': now},
            ]}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        // POST a file request → mint an id, remember its path.
        final reqMatch = RegExp(r'/monitoring/devices/[^/]+/files$');
        if (reqMatch.hasMatch(path) && request.method == 'POST') {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          requestLog?.add(body);
          final id = 'req-${nextId++}';
          pathOf[id] = body['path'] as String? ?? '';
          return http.Response(
            jsonEncode({'data': {'request_id': id}}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        // GET the result → pending the first time, the answer after.
        final resMatch = RegExp(r'/monitoring/devices/[^/]+/files/(req-\d+)$');
        final m = resMatch.firstMatch(path);
        if (m != null && request.method == 'GET') {
          final id = m.group(1)!;
          if (!polled.contains(id)) {
            polled.add(id);
            return http.Response(
              jsonEncode({'data': {'status': 'pending'}}),
              200,
              headers: const {'content-type': 'application/json'},
            );
          }
          final answer = answers[pathOf[id]] ??
              {'status': 'error', 'error': 'not_found'};
          return http.Response(
            jsonEncode({'data': answer}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        return http.Response('{"message":"unstubbed ${request.method} $path"}', 500);
      }),
    );
  }

  testWidgets('pick a device, see its roots, drill in, and the breadcrumb grows',
      (tester) async {
    tester.view.physicalSize = const Size(1200, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = fakeServer(
      devices: [
        {'id': 'dev-1', 'label': 'TOULA', 'platform': 'windows',
          'user': {'id': 'u-1', 'name': 'Toula Elwell'}},
      ],
      answers: {
        '': {'status': 'ready', 'op': 'list', 'path': '', 'entries': [
          {'name': 'C:\\', 'is_dir': true, 'size_bytes': null, 'modified_at': null},
        ]},
        'C:\\': {'status': 'ready', 'op': 'list', 'path': 'C:\\', 'entries': [
          {'name': 'Users', 'is_dir': true, 'size_bytes': null, 'modified_at': null},
          {'name': 'readme.txt', 'is_dir': false, 'size_bytes': 2048, 'modified_at': '2026-10-01'},
        ]},
      },
    );

    await tester.pumpWidget(wrap(api, await signedIn(api)));
    await tester.pumpAndSettle();

    // The rail lists the device.
    expect(find.byKey(const ValueKey('files-device-dev-1')), findsOneWidget);

    await tester.tap(find.text('Toula Elwell'));
    await tester.pump(); // request posted
    // The loading beat is up while the first poll is pending.
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    await tester.pumpAndSettle();

    // Roots arrived.
    expect(find.byKey(const ValueKey('files-entry-C:\\')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('files-entry-C:\\')));
    await tester.pumpAndSettle();

    // Drilled into C:\ — its children show, and the breadcrumb has grown.
    expect(find.byKey(const ValueKey('files-entry-Users')), findsOneWidget);
    expect(find.byKey(const ValueKey('files-entry-readme.txt')), findsOneWidget);
    expect(find.widgetWithText(TextButton, 'This PC'), findsOneWidget);
    expect(find.widgetWithText(TextButton, 'C:\\'), findsOneWidget);
  });

  testWidgets('a download posts an op:download request and the tray marks it ready',
      (tester) async {
    tester.view.physicalSize = const Size(1200, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final log = <Map<String, dynamic>>[];
    final api = fakeServer(
      devices: [
        {'id': 'dev-1', 'label': 'TOULA', 'platform': 'windows',
          'user': {'id': 'u-1', 'name': 'Toula Elwell'}},
      ],
      answers: {
        '': {'status': 'ready', 'op': 'list', 'path': '', 'entries': [
          {'name': 'invoice.pdf', 'is_dir': false, 'size_bytes': 13, 'modified_at': '2026-10-01'},
        ]},
        'invoice.pdf': {'status': 'ready', 'op': 'download', 'name': 'invoice.pdf',
          'size': 13, 'content_base64': base64Encode('the file body'.codeUnits)},
      },
      requestLog: log,
    );

    await tester.pumpWidget(wrap(api, await signedIn(api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Toula Elwell'));
    await tester.pumpAndSettle();

    // A file is chosen first, as in Explorer (UI queue 79); Download is in
    // what it shows.
    await tester.tap(find.byKey(const ValueKey('files-entry-invoice.pdf')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('files-download-invoice.pdf')));
    // No animated spinner rides a download (the tray shows a static state), so
    // pumpAndSettle has nothing to advance the poll timers with — step the two
    // ~900ms polls explicitly.
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();

    // A download request was sent for that file.
    expect(log.any((r) => r['op'] == 'download' && r['path'] == 'invoice.pdf'),
        isTrue);
    // The tray shows it, ready.
    expect(find.byKey(const Key('files-downloads-tray')), findsOneWidget);
    expect(find.byIcon(Icons.check_circle_outline), findsOneWidget);
  });

  // UI queue 79: Explorer's panes once a computer is chosen.
  testWidgets('a chosen picture is fetched and shown beside the list, a '
      'PDF is not fetched, and the list sorts by size', (tester) async {
    tester.view.physicalSize = const Size(1400, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // A 1×1 PNG, so Image.memory has something real to decode.
    const png =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    final log = <Map<String, dynamic>>[];
    final api = fakeServer(
      devices: [
        {'id': 'dev-1', 'label': 'TOULA', 'platform': 'windows',
          'user': {'id': 'u-1', 'name': 'Toula Elwell'}},
      ],
      answers: {
        '': {'status': 'ready', 'op': 'list', 'path': '', 'entries': [
          {'name': 'cap.png', 'is_dir': false, 'size_bytes': 70, 'modified_at': '2026-10-11'},
          {'name': 'estimate.pdf', 'is_dir': false, 'size_bytes': 9000, 'modified_at': '2026-10-10'},
        ]},
        'cap.png': {'status': 'ready', 'op': 'download', 'name': 'cap.png',
          'size': 70, 'content_base64': png},
      },
      requestLog: log,
    );

    await tester.pumpWidget(wrap(api, await signedIn(api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Toula Elwell'));
    await tester.pumpAndSettle();

    // The rail folded into the picker, and the list has its headings.
    expect(find.byKey(const Key('files-device-rail')), findsNothing);
    expect(find.byKey(const Key('files-device-picker')), findsOneWidget);
    expect(find.text('Choose a file to see it here.'), findsOneWidget);

    // Size, largest first: the PDF above the picture.
    await tester.tap(find.byKey(const ValueKey('files-sort-size')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('files-sort-size')));
    await tester.pumpAndSettle();
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('files-entry-estimate.pdf'))).dy,
      lessThan(
        tester.getTopLeft(find.byKey(const ValueKey('files-entry-cap.png'))).dy,
      ),
    );

    // A PDF shows what it is and is not fetched.
    await tester.tap(find.byKey(const ValueKey('files-entry-estimate.pdf')));
    await tester.pumpAndSettle();
    expect(find.text('PDF document'), findsOneWidget);
    expect(log.where((r) => r['op'] == 'download'), isEmpty);

    // A picture is fetched once and shown.
    await tester.tap(find.byKey(const ValueKey('files-entry-cap.png')));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
    expect(log.where((r) => r['op'] == 'download' && r['path'] == 'cap.png'),
        hasLength(1));
    expect(find.byKey(const ValueKey('files-preview-image-cap.png')),
        findsOneWidget);
    expect(find.text('Opening a preview counts as a download in the log.'),
        findsOneWidget);
  });

  testWidgets('on a phone a chosen file stands in for the list, with the way '
      'back', (tester) async {
    tester.view.physicalSize = const Size(380, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = fakeServer(
      devices: [
        {'id': 'dev-1', 'label': 'TOULA', 'platform': 'windows',
          'user': {'id': 'u-1', 'name': 'Toula Elwell'}},
      ],
      answers: {
        '': {'status': 'ready', 'op': 'list', 'path': '', 'entries': [
          {'name': 'estimate.pdf', 'is_dir': false, 'size_bytes': 9000, 'modified_at': '2026-10-10'},
        ]},
      },
    );

    await tester.pumpWidget(wrap(api, await signedIn(api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Toula Elwell'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('files-entry-estimate.pdf')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('files-listing')), findsNothing);
    expect(find.byKey(const ValueKey('files-download-estimate.pdf')),
        findsOneWidget);

    await tester.tap(find.byKey(const Key('files-preview-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('files-listing')), findsOneWidget);
  });

  testWidgets('access_denied on a folder reads as a sentence, not a spinner',
      (tester) async {
    tester.view.physicalSize = const Size(1200, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = fakeServer(
      devices: [
        {'id': 'dev-1', 'label': 'TOULA', 'platform': 'windows',
          'user': {'id': 'u-1', 'name': 'Toula Elwell'}},
      ],
      answers: {
        '': {'status': 'error', 'op': 'list', 'error': 'access_denied', 'path': ''},
      },
    );

    await tester.pumpWidget(wrap(api, await signedIn(api)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Toula Elwell'));
    await tester.pumpAndSettle();

    expect(find.text(FilesTab.errorNotes['access_denied']!), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
    // And a way to retry, not a dead end.
    expect(find.widgetWithText(OutlinedButton, 'Try again'), findsOneWidget);
  });

  testWidgets('on a phone the rail and the browser are one at a time',
      (tester) async {
    tester.view.physicalSize = const Size(380, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = fakeServer(
      devices: [
        {'id': 'dev-1', 'label': 'TOULA', 'platform': 'windows',
          'user': {'id': 'u-1', 'name': 'Toula Elwell'}},
      ],
      answers: {
        '': {'status': 'ready', 'op': 'list', 'path': '', 'entries': [
          {'name': 'C:\\', 'is_dir': true, 'size_bytes': null, 'modified_at': null},
        ]},
      },
    );

    await tester.pumpWidget(wrap(api, await signedIn(api)));
    await tester.pumpAndSettle();

    // Rail is up; no browser back button yet.
    expect(find.byKey(const Key('files-device-rail')), findsOneWidget);
    expect(find.byKey(const Key('files-back-to-rail')), findsNothing);

    await tester.tap(find.text('Toula Elwell'));
    await tester.pumpAndSettle();

    // Now the browser replaced the rail, with a back arrow — no second screen
    // pushed, the containment rule.
    expect(find.byKey(const Key('files-device-rail')), findsNothing);
    expect(find.byKey(const Key('files-back-to-rail')), findsOneWidget);

    await tester.tap(find.byKey(const Key('files-back-to-rail')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('files-device-rail')), findsOneWidget);
  });
}
