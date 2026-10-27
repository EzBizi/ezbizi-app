import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key.dart';
import 'package:ezbizi_client/core/file_access_service.dart';
import 'package:ezbizi_client/core/file_access_types.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The agent half of remote file access — see
/// app/Http/Controllers/Api/Monitoring/FilesController for the contract this
/// pins: `GET /monitoring/file-requests?device_key=...` answers
/// `{"requests":[{id,op,path}]}` and RETURNS-AND-CLEARS them (the server's job,
/// not tested here), each request is run on the disk via a [FileAccessReader],
/// and the answer is POSTed to `/monitoring/file-requests/{id}`.
void main() {
  /// A reader standing in for the disk: it records what it was asked, and
  /// answers from canned maps or throws a [FileAccessException] — so the service
  /// can be driven without a real filesystem, the way the input channel is faked
  /// in the Remote Control test.
  ({
    ApiClient api,
    List<http.Request> posts,
    List<Map<String, dynamic>> bodies,
    List<String> read,
  })
  harness({
    required List<Map<String, Object?>> requests,
    FileAccessReader? reader,
  }) {
    final posts = <http.Request>[];
    final bodies = <Map<String, dynamic>>[];
    var served = false;

    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/monitoring/file-requests')) {
          // Return the batch once; then empty, so the test sees exactly one
          // answer per request rather than the poll re-serving them.
          final payload = served ? <Map<String, Object?>>[] : requests;
          served = true;
          return http.Response(
            jsonEncode({'requests': payload}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        if (request.url.path.contains('/monitoring/file-requests/')) {
          posts.add(request);
          bodies.add(jsonDecode(request.body) as Map<String, dynamic>);
          return http.Response('', 204);
        }

        return http.Response('', 204);
      }),
    );

    return (api: api, posts: posts, bodies: bodies, read: <String>[]);
  }

  FileAccessService build(ApiClient api, FileAccessReader reader) =>
      FileAccessService(
        api,
        fast: const Duration(milliseconds: 20),
        slow: const Duration(milliseconds: 20),
        reader: reader,
      );

  test(
    'a list request is run on the disk and its entries posted back as ready',
    () async {
      final reader = _FakeReader(
        listing: {
          'C:\\Users\\toula': [
            {
              'name': 'Documents',
              'is_dir': true,
              'size_bytes': null,
              'modified_at': '2026-09-30T10:00:00Z',
            },
            {
              'name': 'invoice.pdf',
              'is_dir': false,
              'size_bytes': 20480,
              'modified_at': '2026-09-29T08:00:00Z',
            },
          ],
        },
      );
      final it = harness(
        requests: [
          {'id': 'r1', 'op': 'list', 'path': 'C:\\Users\\toula'},
        ],
      );

      final service = build(it.api, reader);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(
        reader.listed,
        contains('C:\\Users\\toula'),
        reason: 'The service must actually read the requested directory.',
      );
      expect(
        it.posts,
        isNotEmpty,
        reason: 'The answer must be POSTed back to the request id.',
      );
      expect(it.posts.first.url.path, endsWith('/monitoring/file-requests/r1'));

      final body = it.bodies.first;
      expect(body['status'], 'ready');
      expect(body['op'], 'list');
      expect(body['device_key'], await DeviceKey.value());
      expect((body['entries'] as List).length, 2);
      expect((body['entries'] as List).first['name'], 'Documents');
    },
  );

  test(
    'the roots answer carries quick links; a folder answer does not',
    () async {
      final reader = _FakeReader(
        listing: {
          '': [
            {
              'name': r'C:\',
              'is_dir': true,
              'size_bytes': null,
              'modified_at': null,
            },
          ],
          r'C:\': const [],
        },
        links: const [
          {'label': 'Desktop', 'path': r'C:\Users\toula\Desktop'},
        ],
      );
      final it = harness(
        requests: [
          {'id': 'roots', 'op': 'list', 'path': ''},
          {'id': 'drive', 'op': 'list', 'path': r'C:\'},
        ],
      );

      final service = build(it.api, reader);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final roots = it.bodies.firstWhere((b) => b['path'] == '');
      expect(roots['quick_links'], [
        {'label': 'Desktop', 'path': r'C:\Users\toula\Desktop'},
      ]);
      final drive = it.bodies.firstWhere((b) => b['path'] == r'C:\');
      expect(
        drive.containsKey('quick_links'),
        isFalse,
        reason: 'Quick links belong to This PC only.',
      );
    },
  );

  test(
    'a download request posts the file back as base64 under op:download',
    () async {
      final reader = _FakeReader(
        downloads: {
          'C:\\Users\\toula\\invoice.pdf': {
            'name': 'invoice.pdf',
            'size': 13,
            'content_base64': base64Encode('the file body'.codeUnits),
          },
        },
      );
      final it = harness(
        requests: [
          {
            'id': 'r2',
            'op': 'download',
            'path': 'C:\\Users\\toula\\invoice.pdf',
          },
        ],
      );

      final service = build(it.api, reader);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final body = it.bodies.first;
      expect(body['status'], 'ready');
      expect(body['op'], 'download');
      expect(body['name'], 'invoice.pdf');
      expect(body['content_base64'], base64Encode('the file body'.codeUnits));
    },
  );

  test(
    'a reader refusal becomes the error answer, with the slug intact',
    () async {
      final reader = _FakeReader(
        errors: {'C:\\Windows\\System32\\config': 'access_denied'},
      );
      final it = harness(
        requests: [
          {'id': 'r3', 'op': 'list', 'path': 'C:\\Windows\\System32\\config'},
        ],
      );

      final service = build(it.api, reader);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final body = it.bodies.first;
      expect(
        body['status'],
        'error',
        reason: 'A path the disk refuses is reported, not swallowed.',
      );
      expect(
        body['error'],
        'access_denied',
        reason: 'The slug the reader raised must reach the manager unchanged.',
      );
      expect(body['op'], 'list');
    },
  );

  test(
    'an unexpected throw is an honest unreadable, not a dropped request',
    () async {
      // The reader throws something that is NOT a FileAccessException — the
      // manager must still get an answer rather than polling a slot forever.
      final reader = _FakeReader(throwArbitrary: true);
      final it = harness(
        requests: [
          {'id': 'r4', 'op': 'list', 'path': 'C:\\somewhere'},
        ],
      );

      final service = build(it.api, reader);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final body = it.bodies.first;
      expect(body['status'], 'error');
      expect(body['error'], 'unreadable');
    },
  );

  test(
    'read-only: the service never asks the reader to write anything',
    () async {
      // The reader only has list/read; there is no write method to call. This
      // pins that the loop handles exactly the two server operations and does
      // nothing with anything else — a download and a list both route to a read
      // or a list, never to a mutation.
      final reader = _FakeReader(
        listing: {'C:\\': []},
        downloads: {
          'C:\\x': {'name': 'x', 'size': 0, 'content_base64': ''},
        },
      );
      final it = harness(
        requests: [
          {'id': 'r5', 'op': 'list', 'path': 'C:\\'},
          {'id': 'r6', 'op': 'download', 'path': 'C:\\x'},
        ],
      );

      final service = build(it.api, reader);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(reader.listed, contains('C:\\'));
      expect(reader.readCalls, contains('C:\\x'));
      expect(it.bodies.every((b) => b['status'] == 'ready'), isTrue);
    },
  );

  test(
    'a download request with an offset reads that piece, not the whole file',
    () async {
      final reader = _FakeReader(
        downloads: {
          'C:\v.mp4': {'name': 'v.mp4', 'size': 9, 'content_base64': ''},
        },
      );
      final it = harness(
        requests: [
          {'id': 'r7', 'op': 'download', 'path': 'C:\v.mp4', 'offset': 4194304},
          {'id': 'r8', 'op': 'download', 'path': 'C:\v.mp4'},
        ],
      );

      final service = build(it.api, reader);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(reader.offsets, [4194304, null]);
    },
  );
}

/// A disk stand-in: canned answers keyed by path, or a slug to throw.
class _FakeReader implements FileAccessReader {
  _FakeReader({
    this.listing = const {},
    this.downloads = const {},
    this.errors = const {},
    this.throwArbitrary = false,
    this.links = const [],
  });

  final List<Map<String, String>> links;

  final Map<String, List<Map<String, Object?>>> listing;
  final Map<String, Map<String, Object?>> downloads;
  final Map<String, String> errors;
  final bool throwArbitrary;

  final listed = <String>[];
  final readCalls = <String>[];
  final offsets = <int?>[];

  @override
  Future<List<Map<String, Object?>>> list(String path) async {
    listed.add(path);
    if (throwArbitrary) throw StateError('boom');
    if (errors.containsKey(path)) throw FileAccessException(errors[path]!);
    return listing[path] ?? const [];
  }

  @override
  Future<Map<String, Object?>> read(
    String path,
    int maxBytes, {
    int? offset,
  }) async {
    readCalls.add(path);
    offsets.add(offset);
    if (throwArbitrary) throw StateError('boom');
    if (errors.containsKey(path)) throw FileAccessException(errors[path]!);
    return downloads[path] ?? const {};
  }

  @override
  Future<List<Map<String, String>>> quickLinks() async => links;
}
