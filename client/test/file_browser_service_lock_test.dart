import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/file_access_types.dart';
import 'package:ezbizi_client/core/file_browser_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The authenticator lock as the screen sees it (Jose, 2026-10-12): the
/// server's own expiry, refreshed by every request, and a "Lock now" that
/// really asks the server to lock.
void main() {
  http.Response json(Object body, [int status = 200]) => http.Response(
        jsonEncode(body),
        status,
        headers: const {'content-type': 'application/json'},
      );

  test('unlock, slide, lock now, and a lapsed lock clears the expiry', () async {
    var locked = true;
    final calls = <String>[];

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        calls.add('${request.method} ${path.replaceFirst(RegExp(r'^.*/v1'), '')}');

        if (path.endsWith('/monitoring/files/unlock')) {
          if (request.method == 'DELETE') {
            locked = true;
            return http.Response('', 204);
          }
          locked = false;
          return json({'data': {'unlocked_until': '2026-10-12T15:00:00Z'}});
        }
        if (path.endsWith('/files') && request.method == 'POST') {
          if (locked) return json({'message': 'Locked.', 'code': 'files_locked'}, 403);
          return json({'data': {'request_id': 'r1', 'unlocked_until': '2026-10-12T15:10:00Z'}});
        }
        if (path.endsWith('/files/r1')) {
          return json({'data': {'status': 'ready', 'op': 'list', 'path': '', 'entries': []}});
        }
        return json({'message': 'unstubbed'}, 500);
      }),
    );

    final browser = FileBrowserService(api, pollInterval: Duration.zero);

    expect(browser.unlockedUntil, isNull);
    expect(await browser.unlock('123456'), isNull);
    expect(browser.unlockedUntil, DateTime.parse('2026-10-12T15:00:00Z'));

    // A request slides it, by the server's clock.
    await browser.list('dev-1', '');
    expect(browser.unlockedUntil, DateTime.parse('2026-10-12T15:10:00Z'));

    // "Lock now" asks the server, and the next request is refused as locked.
    await browser.lock();
    expect(calls, contains('DELETE /monitoring/files/unlock'));
    expect(browser.unlockedUntil, isNull);
    await expectLater(
      browser.list('dev-1', ''),
      throwsA(isA<FileAccessException>().having((e) => e.slug, 'slug', 'locked')),
    );
    expect(browser.unlockedUntil, isNull);
  });
}
