import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/jobs/job_detail_panel.dart';
import 'package:ezbizi_client/screens/jobs/job_files.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A job's own photos and files (UI queue 119; Jose: option 1 and phone 4).
///
/// "Added in EzBizi" first, through the generic attachments endpoint with
/// `attachable_type=jobs`; Workiz's underneath, read only. Every name here is
/// invented.
void main() {
  testWidgets('the tab lists the job\'s own first and Workiz\'s under them', (
    tester,
  ) async {
    final server = _Server(own: [_photo, _pdf]);
    await _pumpPanel(tester, server, permissions: ['jobs.view', 'jobs.edit']);

    expect(find.text('Photos & files · 3'), findsOneWidget);
    await tester.tap(find.textContaining('Photos & files'));
    await tester.pumpAndSettle();

    final own = find.byKey(const Key('job-own-files'));
    final workiz = find.byKey(const Key('job-workiz-files'));
    expect(own, findsOneWidget);
    expect(workiz, findsOneWidget);
    expect(tester.getTopLeft(own).dy, lessThan(tester.getTopLeft(workiz).dy));
    expect(find.text('ADDED IN EZBIZI · 2'), findsOneWidget);
    expect(find.text('FROM WORKIZ · READ-ONLY · 1'), findsOneWidget);
    expect(
      find.text("They'll move here once they're copied in."),
      findsOneWidget,
    );
    expect(
      find.descendant(of: own, matching: find.text('Hearth before.jpg')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: own, matching: find.textContaining('Morgan Vale')),
      findsNWidgets(2),
    );
    expect(
      server.requests.any(
        (r) =>
            r.method == 'GET' &&
            r.url.path.endsWith('/attachments') &&
            r.url.queryParameters['attachable_type'] == 'jobs' &&
            r.url.queryParameters['attachable_id'] == _jobId,
      ),
      isTrue,
    );
    expect(find.byKey(const Key('job-file-drop')), findsOneWidget);
  });

  // Inspection labels arrival and finished photos (Jose's before & after, 11-03).
  testWidgets('a labelled photo says Before or After on its tile', (
    tester,
  ) async {
    final after = {
      ..._pdf,
      'id': '0199a1b2-0000-7000-8000-000000000003',
      'label': 'after',
    };
    await _pumpFiles(
      tester,
      _Server(
        own: [
          {..._photo, 'label': 'before'},
          after,
          _pdf,
        ],
      ),
    );

    final before = find.byKey(ValueKey('job-own-label-${_photo['id']}'));
    expect(
      find.descendant(of: before, matching: find.text('Before')),
      findsOneWidget,
    );
    expect(find.text('After'), findsOneWidget);
    expect(find.byKey(ValueKey('job-own-label-${_pdf['id']}')), findsNothing);
  });

  testWidgets('an upload posts the file against this job', (tester) async {
    final server = _Server(own: const []);
    await _pumpFiles(
      tester,
      server,
      pick: () async => const PickedBytes('Cap sketch.png', [1, 2, 3]),
    );

    await tester.tap(find.byKey(const Key('job-file-add')));
    await tester.pumpAndSettle();

    final post = server.requests.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/api/v1/attachments'));
    final body = utf8.decode((post as http.Request).bodyBytes);
    expect(body, contains('name="attachable_type"\r\n\r\njobs'));
    expect(body, contains('name="attachable_id"\r\n\r\n$_jobId'));
    expect(body, contains('name="file"; filename="Cap sketch.png"'));
    expect(find.text('Cap sketch.png'), findsOneWidget);
    expect(find.text('ADDED IN EZBIZI · 1'), findsOneWidget);
  });

  testWidgets('a refused upload says why and offers Try again', (tester) async {
    final server = _Server(own: const [], refuseUploads: 1);
    await _pumpFiles(
      tester,
      server,
      pick: () async => const PickedBytes('Flue video.mov', [9, 9]),
    );

    await tester.tap(find.byKey(const Key('job-file-add')));
    await tester.pumpAndSettle();

    expect(find.text('The file may not be larger than 25 MB.'), findsOneWidget);
    final retry = find.byKey(const ValueKey('job-upload-retry-Flue video.mov'));
    expect(retry, findsOneWidget);
    expect(find.text('ADDED IN EZBIZI · 0'), findsOneWidget);

    await tester.tap(retry);
    await tester.pumpAndSettle();

    expect(server.requests.where((r) => r.method == 'POST'), hasLength(2));
    expect(retry, findsNothing);
    expect(find.text('ADDED IN EZBIZI · 1'), findsOneWidget);
  });

  testWidgets('Remove asks once, in a panel, then deletes', (tester) async {
    final server = _Server(own: [_photo]);
    await _pumpFiles(tester, server);

    await tester.longPress(
      find.byKey(ValueKey('job-own-open-${_photo['id']}')),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('job-file-remove-panel')), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);
    expect(server.requests.where((r) => r.method == 'DELETE'), isEmpty);

    await tester.tap(find.byKey(const Key('job-file-remove-confirm')));
    await tester.pumpAndSettle();

    final deletes = server.requests.where((r) => r.method == 'DELETE');
    expect(deletes, hasLength(1));
    expect(deletes.single.url.path, endsWith('/attachments/${_photo['id']}'));
    expect(find.byKey(const Key('job-file-remove-panel')), findsNothing);
    expect(find.text('ADDED IN EZBIZI · 0'), findsOneWidget);
  });

  testWidgets('hovering a tile shows its ✕; Workiz\'s have none', (
    tester,
  ) async {
    final server = _Server(own: [_photo]);
    await _pumpFiles(tester, server);

    final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
    addTearDown(mouse.removePointer);
    await mouse.addPointer(location: Offset.zero);
    await mouse.moveTo(
      tester.getCenter(find.byKey(ValueKey('job-own-open-${_photo['id']}'))),
    );
    await tester.pumpAndSettle();
    expect(find.byTooltip('Remove Hearth before.jpg'), findsOneWidget);

    await mouse.moveTo(
      tester.getCenter(find.byKey(const ValueKey('job-photo-0'))),
    );
    await tester.pumpAndSettle();
    expect(find.byTooltip('Remove Hearth before.jpg'), findsNothing);
    expect(find.byTooltip(RegExp('^Remove ')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const Key('job-workiz-files')),
        matching: find.textContaining('Remove'),
      ),
      findsNothing,
    );
  });

  testWidgets('without Jobs Edit the job\'s files are read only, and say why', (
    tester,
  ) async {
    final server = _Server(own: [_photo]);
    await _pumpPanel(tester, server, permissions: ['jobs.view']);
    await tester.tap(find.textContaining('Photos & files'));
    await tester.pumpAndSettle();

    final add = tester.widget<FilledButton>(
      find.byKey(const Key('job-file-add')),
    );
    expect(add.onPressed, isNull);
    expect(
      find.ancestor(
        of: find.byKey(const Key('job-file-add')),
        matching: find.byWidgetPredicate(
          (w) =>
              w is Tooltip &&
              (w.message ?? '').contains('permission to change a job'),
        ),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const Key('job-file-drop')), findsNothing);

    final tile = tester.widget<InkWell>(
      find.byKey(ValueKey('job-own-open-${_photo['id']}')),
    );
    expect(tile.onLongPress, isNull, reason: 'no Remove by long press');

    await tester.tap(find.byKey(ValueKey('job-own-open-${_photo['id']}')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('job-file-viewer')), findsOneWidget);
    final remove = tester.widget<OutlinedButton>(
      find.descendant(
        of: find.byKey(const Key('job-file-viewer')),
        matching: find.byWidgetPredicate((w) => w is OutlinedButton),
      ),
    );
    expect(remove.onPressed, isNull);
  });

  testWidgets('on a phone: Take a photo where the camera is there', (
    tester,
  ) async {
    final server = _Server(own: [_photo, _pdf]);
    await _pumpFiles(
      tester,
      server,
      size: const Size(390, 900),
      camera: true,
      takePhoto: () async => const PickedBytes('Crown crack.jpg', [4, 5]),
    );

    expect(find.text('Take a photo'), findsOneWidget);
    expect(find.text('Choose files'), findsOneWidget);
    expect(find.byKey(const Key('job-file-drop')), findsNothing);
    final first = tester.getSize(
      find.byKey(ValueKey('job-own-file-${_photo['id']}')),
    );
    expect(first.width, lessThan((390 - 24) / 3 + 1), reason: 'three across');

    await tester.tap(find.byKey(const Key('job-file-camera')));
    await tester.pumpAndSettle();
    final post = server.requests.singleWhere((r) => r.method == 'POST');
    expect(
      utf8.decode((post as http.Request).bodyBytes),
      contains('filename="Crown crack.jpg"'),
    );
  });

  testWidgets('on a phone without a camera: Choose files alone', (
    tester,
  ) async {
    final server = _Server(own: const []);
    await _pumpFiles(tester, server, size: const Size(390, 900), camera: false);

    expect(find.text('Take a photo'), findsNothing);
    expect(find.text('Choose files'), findsOneWidget);
  });
}

const _jobId = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

const _photo = <String, dynamic>{
  'id': '0199a1b2-0000-7000-8000-000000000001',
  'original_name': 'Hearth before.jpg',
  'mime': 'image/jpeg',
  'size_bytes': 2048,
  'uploaded_by': {'id': 'u-7', 'name': 'Morgan Vale'},
  'uploaded_at': '2026-10-19T14:05:00+00:00',
};

const _pdf = <String, dynamic>{
  'id': '0199a1b2-0000-7000-8000-000000000002',
  'original_name': 'Signed estimate.pdf',
  'mime': 'application/pdf',
  'size_bytes': 4096,
  'uploaded_by': {'id': 'u-7', 'name': 'Morgan Vale'},
  'uploaded_at': '2026-10-18T09:30:00+00:00',
};

const _workizFiles = [
  (name: 'IMG_7710.jpg', url: 'https://st.example.test/files/IMG_7710.jpg'),
];

/// The attachments endpoint, as the controller answers it.
class _Server {
  _Server({required List<Map<String, dynamic>> own, this.refuseUploads = 0})
    : own = [...own];

  final List<Map<String, dynamic>> own;
  int refuseUploads;
  final requests = <http.BaseRequest>[];
  var _next = 10;

  late final client = MockClient.streaming((request, stream) async {
    final bytes = await stream.toBytes();
    final copy = http.Request(request.method, request.url)
      ..headers.addAll(request.headers)
      ..bodyBytes = bytes;
    requests.add(copy);
    final r = await handle(copy);
    return http.StreamedResponse(
      Stream.value(r.bodyBytes),
      r.statusCode,
      headers: r.headers,
    );
  });

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/attachments') && request.method == 'GET') {
      return _json({
        'data': own,
        'limits': {
          'max_kilobytes': 25600,
          'max_readable': '25 MB',
          'extensions': ['jpg', 'png', 'pdf'],
        },
      });
    }
    if (path.endsWith('/attachments') && request.method == 'POST') {
      if (refuseUploads > 0) {
        refuseUploads--;
        return _json({
          'message': 'The file may not be larger than 25 MB.',
          'errors': {
            'file': ['The file may not be larger than 25 MB.'],
          },
        }, 422);
      }
      final name = RegExp(
        r'filename="([^"]+)"',
      ).firstMatch(utf8.decode(request.bodyBytes))!.group(1)!;
      final row = {
        'id': '0199a1b2-0000-7000-8000-0000000000${_next++}',
        'original_name': name,
        'mime': name.endsWith('.png') ? 'image/png' : 'image/jpeg',
        'size_bytes': 3,
        'uploaded_by': {'id': 'u-1', 'name': 'Dana Reyes'},
        'uploaded_at': '2026-10-20T10:00:00+00:00',
      };
      own.add(row);
      return _json({'data': row}, 201);
    }
    if (path.contains('/attachments/') && request.method == 'DELETE') {
      own.removeWhere((r) => path.endsWith('/${r['id']}'));
      return _json({'message': 'Attachment removed.'});
    }
    if (path.contains('/attachments/')) {
      return http.Response('', 404);
    }
    return _json({'data': <dynamic>[]});
  }
}

Future<void> _pumpFiles(
  WidgetTester tester,
  _Server server, {
  Size size = const Size(900, 1200),
  bool camera = false,
  Future<PickedBytes?> Function()? pick,
  Future<PickedBytes?> Function()? takePhoto,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final files = JobAttachments(
    api: ApiClient(client: server.client),
    jobId: _jobId,
  );
  addTearDown(files.dispose);
  files.load();

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(12),
          child: JobFiles(
            files: files,
            workiz: _workizFiles,
            formats: DisplayFormats.platform,
            addRefusal: null,
            cameraSupported: camera,
            pick: pick,
            takePhoto: takePhoto,
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpPanel(
  WidgetTester tester,
  _Server server, {
  required List<String> permissions,
}) async {
  tester.view.physicalSize = const Size(1000, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final inner = server.handle;
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
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
      if (path.endsWith('/jobs/$_jobId')) {
        return _json({
          'data': {
            'id': _jobId,
            'number': 'J-20117',
            'title': 'Crown repair',
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
            'workiz': {
              'fields': <dynamic>[],
              'files': [
                {
                  'name': _workizFiles.single.name,
                  'url': _workizFiles.single.url,
                },
              ],
            },
          },
        });
      }
      server.requests.add(request);
      return inner(request);
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
