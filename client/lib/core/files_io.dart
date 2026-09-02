import 'package:file_selector/file_selector.dart' as fs;
import 'package:flutter/foundation.dart';

import 'files.dart';

/// Native implementations for every non-web target. See files.dart.
///
/// These were `null` stubs until 2026-09-02, which is why Add photo did
/// nothing on the Windows desktop build: `pickImage` answered null and the
/// caller could not tell that from a cancelled dialog. Twenty call sites
/// across Fleet, Tasks, Inspection, HR, Marketing, Services and Settings
/// shared the one fault.
///
/// Flutter ships no file dialog, so this is a package or an FFI binding per
/// platform. `file_selector` is flutter/packages' own, federated across
/// windows/macos/linux/android/ios, and asks for no runtime permission —
/// the user picking the file IS the grant.

/// What `image/*` and `video/*` mean to a native dialog, which filters on
/// extension rather than on MIME.
const _imageExtensions = <String>[
  'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp',
];

const _videoExtensions = <String>['mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv'];

/// Translate the web `accept` string the callers already pass.
///
/// Three shapes are in use and all three appear here: bare extensions from the
/// server's attachment limits (`.pdf,.jpg,.png`), a fixed extension list
/// (`.csv,.xlsx`), and MIME wildcards (`image/*,video/*`).
///
/// An accept string we cannot read yields NO filter rather than an empty one:
/// a dialog showing every file is a worse filter, but a dialog showing nothing
/// is a bug, and this string comes from the server.
@visibleForTesting
List<fs.XTypeGroup> acceptGroups(String accept) {
  final extensions = <String>{};

  for (final raw in accept.split(',')) {
    final token = raw.trim().toLowerCase();
    if (token.isEmpty) continue;

    if (token.startsWith('.')) {
      extensions.add(token.substring(1));
    } else if (token == 'image/*') {
      extensions.addAll(_imageExtensions);
    } else if (token == 'video/*') {
      extensions.addAll(_videoExtensions);
    } else if (token.contains('/')) {
      // A concrete type such as application/pdf. Its subtype is the extension
      // often enough to be worth trying, and costs nothing when it is not.
      extensions.add(token.split('/').last);
    } else {
      extensions.add(token);
    }
  }

  if (extensions.isEmpty) return const <fs.XTypeGroup>[];

  return [
    fs.XTypeGroup(label: 'Allowed files', extensions: extensions.toList()),
  ];
}

Future<PickedBytes?> _openBytes(List<fs.XTypeGroup> groups) async {
  final file = await fs.openFile(acceptedTypeGroups: groups);
  if (file == null) return null;

  return PickedBytes(file.name, await file.readAsBytes());
}

Future<PickedBytes?> pickImage() => _openBytes(const [
  fs.XTypeGroup(label: 'Images', extensions: _imageExtensions),
]);

Future<PickedBytes?> pickBytes({String accept = ''}) =>
    _openBytes(acceptGroups(accept));

Future<PickedFile?> pickText() async {
  final file = await fs.openFile(acceptedTypeGroups: acceptGroups('.csv,.txt'));
  if (file == null) return null;

  return PickedFile(file.name, await file.readAsString());
}

/// Still a no-op off the web, and deliberately so in this change.
///
/// Saving needs `getSaveLocation` plus a real write, and the facade declares
/// `void download(...)` — making it await would turn six call sites async in
/// Settings, Tasks, Inspection and Fleet. That is a different change with a
/// different blast radius from fixing a picker that returns null, so it is not
/// smuggled in beside one. Export and download still do nothing off the web.
void download(String filename, List<int> content) {}

void clearQuery() {}

String? previewUrl(List<int> bytes, String mime) => null;

void releasePreviewUrl(String url) {}
