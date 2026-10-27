import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/file_browser_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A big download comes in pieces (Jose, 2026-10-27: past the old 5 MB cap):
/// asked from offset 0, then from where each piece ended, until `size`.
void main() {
  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  ApiClient agent({required bool pieces}) {
    final file = List<int>.generate(10, (i) => i);
    final asked = <String, int>{};
    var n = 0;
    return ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/files') && request.method == 'POST') {
          final id = 'r${n++}';
          asked[id] =
              (jsonDecode(request.body)['offset'] as num?)?.toInt() ?? 0;
          return json({
            'data': {'request_id': id},
          });
        }
        final id = path.split('/').last;
        final from = asked[id]!;
        final piece = pieces ? file.skip(from).take(4).toList() : file;
        return json({
          'data': {
            'status': 'ready',
            'op': 'download',
            'name': 'v.mp4',
            'size': 10,
            'offset': pieces ? from : null,
            'content_base64': base64Encode(piece),
          },
        });
      }),
    );
  }

  test('pieces are asked for in turn and joined into the whole file', () async {
    final progress = <int>[];
    final file = await FileBrowserService(
      agent(pieces: true),
      pollInterval: Duration.zero,
    ).download('dev-1', r'C:\v.mp4', onProgress: (got, _) => progress.add(got));

    expect(file.bytes, List<int>.generate(10, (i) => i));
    expect(progress, [4, 8, 10]);
  });

  test('an older agent that sends the file whole is one request', () async {
    final file = await FileBrowserService(
      agent(pieces: false),
      pollInterval: Duration.zero,
    ).download('dev-1', r'C:\v.mp4');

    expect(file.bytes, List<int>.generate(10, (i) => i));
  });
}
