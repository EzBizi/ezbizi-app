import 'dart:convert';
import 'dart:io';

import 'file_access_types.dart';

/// The real reader: the monitored machine's own disk, via `dart:io`. Selected
/// by the conditional import in file_access_reader.dart on every build that has
/// `dart:io` — which is the Windows agent, the only build the service runs on.
///
/// There is no path sandbox here, and that is the feature: a manager who holds
/// `monitoring.files.view`, is within §3 reach of this person, and whose every
/// open is audited, is looking at this machine. The boundary is the OS's own
/// ACLs — a path Windows refuses comes back `access_denied`, not a path this
/// code decided to hide.
class IoFileAccessReader implements FileAccessReader {
  /// [driveProbe] is the seam for testing the roots listing: it answers "does
  /// this drive letter have readable media", and is allowed to THROW to stand
  /// in for a not-ready device. Null in production → the real guarded probe.
  const IoFileAccessReader({this.driveProbe, this.home, this.shellFolders});

  /// Test seam only. `(String root) => bool`, may throw.
  final bool Function(String root)? driveProbe;

  /// Test seams for [quickLinks]: the profile folder (null → USERPROFILE) and
  /// Windows' User Shell Folders values (null → read from the registry).
  final String? home;
  final Future<Map<String, String>> Function()? shellFolders;

  /// Explorer's labels, and the User Shell Folders value naming each one.
  /// Downloads has no friendly value name, only its known-folder GUID.
  static const _quickFolders = <(String, String)>[
    ('Desktop', 'Desktop'),
    ('Documents', 'Personal'),
    ('Downloads', '{374DE290-123F-4565-9164-39C4925E467B}'),
    ('Pictures', 'My Pictures'),
    ('Videos', 'My Video'),
    ('Music', 'My Music'),
  ];

  @override
  Future<List<Map<String, String>>> quickLinks() async {
    try {
      final profile = home ?? Platform.environment['USERPROFILE'];
      if (profile == null || profile.isEmpty) return const [];

      // Where Windows actually keeps each folder. OneDrive "backup" moves
      // Desktop/Documents/Pictures into the OneDrive folder and records it
      // here, so guessing <profile>\Desktop would open an empty leftover.
      final shell = await (shellFolders ?? _readShellFolders)();

      final links = <Map<String, String>>[];
      for (final (label, value) in _quickFolders) {
        final recorded = shell[value];
        final path = recorded != null && recorded.isNotEmpty
            ? _expand(recorded)
            : '$profile\\$label';
        try {
          if (Directory(path).existsSync()) {
            links.add({'label': label, 'path': path});
          }
        } catch (_) {
          // An unreachable redirect (an offline network share) is skipped.
        }
      }
      return links;
    } catch (_) {
      return const [];
    }
  }

  /// `reg query` of HKCU's User Shell Folders → {value name: data}. Async so
  /// the spawn never blocks the agent's UI isolate; it runs only when a manager
  /// opens This PC.
  static Future<Map<String, String>> _readShellFolders() async {
    final result = await Process.run('reg', [
      'query',
      r'HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders',
    ]);
    if (result.exitCode != 0) return const {};

    final values = <String, String>{};
    final row = RegExp(r'^\s+(.+?)\s+REG_(?:EXPAND_)?SZ\s+(.*)$');
    for (final line in LineSplitter.split(result.stdout as String)) {
      final m = row.firstMatch(line);
      if (m != null) values[m.group(1)!] = m.group(2)!.trim();
    }
    return values;
  }

  /// `%USERPROFILE%\OneDrive\Desktop` → the real path, from this process's
  /// environment. An unknown variable is left as written.
  static String _expand(String value) => value.replaceAllMapped(
        RegExp(r'%([^%]+)%'),
        (m) => Platform.environment[m.group(1)!] ?? m.group(0)!,
      );

  @override
  Future<List<Map<String, Object?>>> list(String path) async {
    if (path.isEmpty) return _drives();

    final dir = Directory(path);
    if (!dir.existsSync()) {
      throw const FileAccessException('not_found');
    }

    try {
      final entries = <Map<String, Object?>>[];

      await for (final entity in dir.list(followLinks: false)) {
        // One entry the agent cannot stat (a lock, a race with a delete) is
        // skipped, not fatal: a single bad child must not fail the listing the
        // way one malformed wall must not fail a floor plan.
        try {
          final stat = entity.statSync();
          final isDir = stat.type == FileSystemEntityType.directory;
          entries.add({
            'name': _basename(entity.path),
            'is_dir': isDir,
            'size_bytes': isDir ? null : stat.size,
            'modified_at': stat.modified.toUtc().toIso8601String(),
          });
        } catch (_) {
          continue;
        }

        // The agent's own ceiling, below the server's MAX_ENTRIES: a folder of
        // tens of thousands is cut here rather than marshalled whole.
        if (entries.length >= _maxEntries) break;
      }

      return entries;
    } on FileSystemException catch (ex) {
      throw FileAccessException(_slug(ex));
    }
  }

  @override
  Future<Map<String, Object?>> read(
    String path,
    int maxBytes, {
    int? offset,
  }) async {
    final file = File(path);
    if (!file.existsSync()) {
      throw const FileAccessException('not_found');
    }

    final int length;
    try {
      length = await file.length();
    } on FileSystemException catch (ex) {
      throw FileAccessException(_slug(ex));
    }

    if (offset != null) {
      try {
        final raf = await file.open();
        try {
          await raf.setPosition(offset.clamp(0, length));
          final bytes = await raf.read(maxBytes);
          return {
            'name': _basename(path),
            'size': length,
            'offset': offset,
            'content_base64': base64Encode(bytes),
          };
        } finally {
          await raf.close();
        }
      } on FileSystemException catch (ex) {
        throw FileAccessException(_slug(ex));
      }
    }

    // Refused on the SIZE, before a byte is read — so a 2 GB file is never
    // pulled into memory to then be rejected.
    if (length > maxBytes) {
      throw const FileAccessException('too_large');
    }

    try {
      final bytes = await file.readAsBytes();
      return {
        'name': _basename(path),
        'size': bytes.length,
        'content_base64': base64Encode(bytes),
      };
    } on FileSystemException catch (ex) {
      throw FileAccessException(_slug(ex));
    }
  }

  /// The Windows drives that exist, as the top level an empty path lists. Probed
  /// A–Z because `dart:io` has no drive enumeration; a non-existent letter's
  /// `existsSync` is a cheap stat that says no.
  List<Map<String, Object?>> _drives() {
    final drives = <Map<String, Object?>>[];

    for (var c = 'A'.codeUnitAt(0); c <= 'Z'.codeUnitAt(0); c++) {
      final root = '${String.fromCharCode(c)}:\\';
      // Each probe is guarded, and that is the whole point: a drive LETTER
      // that exists as a device but has no media — an empty DVD or card
      // reader, a mapped-but-disconnected network drive — makes existsSync
      // THROW "device not ready" (Win32 21), not return false. Unguarded, one
      // such drive took the WHOLE roots listing down as `unreadable` (Jose hit
      // exactly this on the first real machine: the drives would not open). A
      // throwing drive is skipped, as a listing skips a child it cannot stat.
      bool present;
      try {
        present = (driveProbe ?? _realDriveProbe)(root);
      } catch (_) {
        continue;
      }

      if (present) {
        drives.add({
          'name': root,
          'is_dir': true,
          'size_bytes': null,
          'modified_at': null,
        });
      }
    }

    return drives;
  }

  static bool _realDriveProbe(String root) => Directory(root).existsSync();

  /// The last segment of a path, tolerant of either separator and of a trailing
  /// one. `C:\Users\toula\` → `toula`, `C:\` → `C:\` (a bare drive keeps its
  /// label rather than collapsing to empty).
  static String _basename(String path) {
    final parts = path
        .replaceAll('\\', '/')
        .split('/')
        .where((s) => s.isNotEmpty)
        .toList();

    return parts.isEmpty ? path : parts.last;
  }

  /// Map a Windows filesystem error to the manager-facing slug. The codes are
  /// Win32 system error codes `dart:io` surfaces in `osError.errorCode`.
  static String _slug(FileSystemException ex) {
    switch (ex.osError?.errorCode) {
      case 5: // ERROR_ACCESS_DENIED
        return 'access_denied';
      case 2: // ERROR_FILE_NOT_FOUND
      case 3: // ERROR_PATH_NOT_FOUND
        return 'not_found';
      default:
        // ERROR_SHARING_VIOLATION (32), a lock, anything else we could not
        // read — all one honest "could not read it", never a guess at why.
        return 'unreadable';
    }
  }

  static const int _maxEntries = 2000;
}

/// The instance the conditional import exposes as `reader`.
const FileAccessReader reader = IoFileAccessReader();
