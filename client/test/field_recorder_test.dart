import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/field_recorder.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Writes a few bytes where the plugin would write AAC.
class _FakeMic implements FieldMic {
  int files = 0;

  @override
  Future<bool> hasPermission() async => true;

  @override
  Future<void> start(String path) async =>
      File(path).writeAsStringSync('minute ${files++}');

  @override
  Future<void> stop() async {}

  @override
  Future<void> pause() async {}

  @override
  Future<void> resume() async {}

  @override
  Future<bool> isRecording() async => true;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late Directory root;
  late List<http.BaseRequest> requests;
  var online = true;

  setUp(() {
    root = Directory.systemTemp.createTempSync('field-audio');
    requests = [];
    online = true;
  });
  tearDown(() => root.deleteSync(recursive: true));

  ApiClient api() => ApiClient(
    client: MockClient.streaming((request, body) async {
      requests.add(request);
      final bytes = await body.toBytes();
      if (!online) {
        return http.StreamedResponse(Stream.value(utf8.encode('{}')), 503);
      }
      if (request.url.path.endsWith('/chunks')) {
        // The sha256 the phone sends must be of the bytes it sends.
        final text = latin1.decode(bytes);
        final sent = RegExp(
          r'name="sha256"\r\n\r\n([0-9a-f]{64})',
        ).firstMatch(text)!.group(1);
        final audio = RegExp(r'minute \d+').firstMatch(text)!.group(0)!;
        expect(sent, sha256.convert(utf8.encode(audio)).toString());
      }
      final reply = request.url.path.endsWith('/field/recordings')
          ? {
              'data': {'id': 'rec-1'},
            }
          : {'data': {}};
      return http.StreamedResponse(
        Stream.value(utf8.encode(jsonEncode(reply))),
        201,
      );
    }),
  );

  List<String> paths() => [
    for (final r in requests) '${r.method} ${r.url.path}',
  ];

  test('records in chunks, sends each, and closes the recording', () async {
    final recorder = FieldRecorder(
      api(),
      mic: _FakeMic(),
      root: () async => root,
      chunk: const Duration(milliseconds: 150),
    );

    expect(await recorder.start(jobId: 'job-1'), isTrue);
    expect(recorder.state.value.status, FieldRecorderStatus.recording);
    await Future<void>.delayed(const Duration(milliseconds: 400));
    await recorder.stop('done');

    final chunks = paths().where((p) => p.endsWith('/chunks')).length;
    expect(chunks, greaterThanOrEqualTo(3));
    expect(paths().last, 'POST /api/v1/field/recordings/rec-1/finish');
    expect(root.listSync(), isEmpty, reason: 'Everything sent, nothing kept.');
    expect(recorder.state.value.status, FieldRecorderStatus.idle);
  });

  test(
    'offline it keeps the audio; the next launch sends it as recovered',
    () async {
      final recorder = FieldRecorder(
        api(),
        mic: _FakeMic(),
        root: () async => root,
        chunk: const Duration(milliseconds: 150),
      );
      await recorder.start();
      online = false;
      await Future<void>.delayed(const Duration(milliseconds: 250));
      // The app dies here: no stop().
      recorder.dispose();
      expect(recorder.state.value.unsent, greaterThan(0));

      online = true;
      requests.clear();
      final next = FieldRecorder(
        api(),
        mic: _FakeMic(),
        root: () async => root,
      );
      await next.recover();

      expect(paths(), contains('POST /api/v1/field/recordings/rec-1/chunks'));
      final finish = requests.last as http.Request;
      expect(finish.url.path, '/api/v1/field/recordings/rec-1/finish');
      expect(jsonDecode(finish.body)['stop_reason'], 'recovered');
      expect(root.listSync(), isEmpty);
    },
  );
}
