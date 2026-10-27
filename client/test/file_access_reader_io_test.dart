@TestOn('vm')
library;

import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/file_access_reader_io.dart';
import 'package:ezbizi_client/core/file_access_types.dart';
import 'package:flutter_test/flutter_test.dart';

/// The agent's disk reader — the half that shipped untested and broke on the
/// first real machine (an empty drive letter threw "device not ready" and took
/// the whole roots listing down). `flutter test` runs on the VM, so the dart:io
/// reader is exercised against a real temp directory here.
void main() {
  late Directory tmp;

  setUp(() => tmp = Directory.systemTemp.createTempSync('files_reader_'));
  tearDown(() => tmp.deleteSync(recursive: true));

  const reader = IoFileAccessReader();

  test('list returns a directory\'s entries with dir/size/modified', () async {
    Directory('${tmp.path}/sub').createSync();
    File('${tmp.path}/note.txt').writeAsStringSync('hello');

    final entries = await reader.list(tmp.path);
    final byName = {for (final e in entries) e['name']: e};

    expect(byName.keys, containsAll(['sub', 'note.txt']));
    expect(byName['sub']!['is_dir'], true);
    expect(byName['sub']!['size_bytes'], isNull);
    expect(byName['note.txt']!['is_dir'], false);
    expect(byName['note.txt']!['size_bytes'], 5);
    expect(byName['note.txt']!['modified_at'], isNotNull);
  });

  test('list of a path that does not exist is not_found', () async {
    await expectLater(
      reader.list('${tmp.path}/nope'),
      throwsA(
        isA<FileAccessException>().having((e) => e.slug, 'slug', 'not_found'),
      ),
    );
  });

  test('read returns a file\'s bytes as base64', () async {
    File('${tmp.path}/f.bin').writeAsBytesSync([1, 2, 3, 4]);

    final out = await reader.read('${tmp.path}/f.bin', 1024);

    expect(out['name'], 'f.bin');
    expect(out['size'], 4);
    expect(base64Decode(out['content_base64'] as String), [1, 2, 3, 4]);
  });

  test(
    'read refuses an over-cap file as too_large, before reading it',
    () async {
      File('${tmp.path}/big.bin').writeAsBytesSync(List.filled(2048, 0));

      await expectLater(
        reader.read('${tmp.path}/big.bin', 1024),
        throwsA(
          isA<FileAccessException>().having((e) => e.slug, 'slug', 'too_large'),
        ),
      );
    },
  );

  test(
    'a piece of an over-cap file is read from its offset, with the whole size',
    () async {
      File(
        '${tmp.path}/big.bin',
      ).writeAsBytesSync([for (var i = 0; i < 2500; i++) i % 256]);

      final out = await reader.read('${tmp.path}/big.bin', 1024, offset: 2048);

      expect(out['size'], 2500);
      expect(out['offset'], 2048);
      expect(base64Decode(out['content_base64'] as String), [
        for (var i = 2048; i < 2500; i++) i % 256,
      ]);
    },
  );

  test('read of a missing file is not_found', () async {
    await expectLater(
      reader.read('${tmp.path}/ghost', 1024),
      throwsA(
        isA<FileAccessException>().having((e) => e.slug, 'slug', 'not_found'),
      ),
    );
  });

  // THE PRODUCTION BUG. A drive letter whose probe throws (device not ready)
  // must be skipped, not fail the whole roots listing.
  test(
    'roots skips a drive whose probe throws, and keeps the good ones',
    () async {
      final reader = IoFileAccessReader(
        driveProbe: (root) {
          if (root == 'D:\\')
            throw const FileSystemException('device not ready');
          return root == 'C:\\'; // only C: has media
        },
      );

      final roots = await reader.list('');

      final names = roots.map((e) => e['name']).toList();
      expect(
        names,
        contains('C:\\'),
        reason: 'A readable drive must be listed.',
      );
      expect(
        names,
        isNot(contains('D:\\')),
        reason: 'The drive whose probe threw is skipped, not fatal.',
      );
      // And it is a listing, not a throw — the bug was the whole call failing.
      expect(roots, isNotEmpty);
    },
  );

  group('quickLinks', () {
    late Directory profile;
    setUp(() => profile = Directory.systemTemp.createTempSync('ezb-profile-'));
    tearDown(() => profile.deleteSync(recursive: true));

    test(
      'follows a redirected folder, falls back to the profile, skips missing ones',
      () async {
        Directory('${profile.path}\\Downloads').createSync();
        final oneDriveDesktop = Directory('${profile.path}\\OneDrive\\Desktop')
          ..createSync(recursive: true);
        // A stale, empty <profile>\Desktop that Explorer no longer uses.
        Directory('${profile.path}\\Desktop').createSync();

        final reader = IoFileAccessReader(
          home: profile.path,
          shellFolders: () async => {'Desktop': oneDriveDesktop.path},
        );

        final links = await reader.quickLinks();
        final byLabel = {for (final l in links) l['label']: l['path']};

        expect(
          byLabel['Desktop'],
          oneDriveDesktop.path,
          reason: 'Where Windows says Desktop is, not the leftover folder.',
        );
        expect(
          byLabel['Downloads'],
          '${profile.path}\\Downloads',
          reason: 'No record → the profile folder.',
        );
        expect(
          byLabel.containsKey('Videos'),
          isFalse,
          reason: 'A folder that does not exist is not offered.',
        );
        expect(links.first['label'], 'Desktop', reason: "Explorer's order.");
      },
    );

    test('a failing registry read still yields the profile folders', () async {
      Directory('${profile.path}\\Documents').createSync();
      final reader = IoFileAccessReader(
        home: profile.path,
        shellFolders: () async => throw const ProcessException('reg', []),
      );
      // Never throws: an empty or partial list, not a failed drive list.
      expect(await reader.quickLinks(), isA<List<Map<String, String>>>());
    });

    // The real thing, on a real Windows profile: the registry query and its
    // parsing against actual `reg` output, not a hand-written map.
    test('on this Windows machine, finds the real Desktop', () async {
      final links = await const IoFileAccessReader().quickLinks();
      final desktop = links.where((l) => l['label'] == 'Desktop').toList();
      expect(desktop, hasLength(1));
      expect(Directory(desktop.single['path']!).existsSync(), isTrue);
      expect(
        desktop.single['path'],
        isNot(contains('%')),
        reason: 'Environment variables must be expanded.',
      );
    }, skip: !Platform.isWindows);
  });
}
