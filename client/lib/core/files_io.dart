import 'package:printing/printing.dart';
import 'dart:io';
import 'dart:async';

import 'package:file_selector/file_selector.dart' as fs;
import 'package:flutter/foundation.dart';
import 'package:image_picker/image_picker.dart'
    show CameraDevice, ImagePicker, ImageSource;

import 'files.dart';

/// Native implementations for every non-web target. See files.dart.
///
/// These were `null` stubs until 2026-09-02, which is why Add photo did
/// nothing on the Windows desktop build: `pickImage` answered null and the
/// caller could not tell that from a cancelled dialog. Twenty call sites
/// across Fleet, Inspection, HR, Marketing, Services and Settings
/// shared the one fault.
///
/// Flutter ships no file dialog, so this is a package or an FFI binding per
/// platform. `file_selector` is flutter/packages' own, federated across
/// windows/macos/linux/android/ios, and asks for no runtime permission —
/// the user picking the file IS the grant.

/// What `image/*` and `video/*` mean to a native dialog, which filters on
/// extension rather than on MIME.
const _imageExtensions = <String>[
  'jpg',
  'jpeg',
  'png',
  'gif',
  'webp',
  'heic',
  'heif',
  'bmp',
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

/// The phone apps open the device camera through `image_picker` (Jose,
/// 2026-10-20: "yes add to android and iphone sure"). Desktop stays false:
/// image_picker's Windows/macOS/Linux halves are a file dialog, which is not
/// "take a photo", and Choose files already offers that.
bool get canTakePhoto =>
    defaultTargetPlatform == TargetPlatform.android ||
    defaultTargetPlatform == TargetPlatform.iOS;

/// The rear camera, through the OS camera app. Android needs no permission
/// for the capture intent; iOS asks once, with NSCameraUsageDescription.
///
/// 2560 px and quality 85 keep a job photo legible while cutting a 12 MP
/// capture to a size that uploads over a field connection.
/// `requestFullMetadata: false` keeps iOS from asking for the photo library.
Future<PickedBytes?> takePhoto() async {
  if (!canTakePhoto) return null;

  final shot = await ImagePicker().pickImage(
    source: ImageSource.camera,
    preferredCameraDevice: CameraDevice.rear,
    imageQuality: 85,
    maxWidth: 2560,
    requestFullMetadata: false,
  );
  if (shot == null) return null;

  return PickedBytes(shot.name, await shot.readAsBytes());
}

Future<PickedFile?> pickText() async {
  final file = await fs.openFile(acceptedTypeGroups: acceptGroups('.csv,.txt'));
  if (file == null) return null;

  return PickedFile(file.name, await file.readAsString());
}

/// A Save dialog, then the write (Mia, 2026-10-02: a signed contract could
/// not be downloaded on the desktop app). It was a no-op until then.
///
/// The facade's `void download(...)` is kept: the dialog runs without being
/// awaited, so the seven call sites stay as they are. A cancelled dialog
/// writes nothing; a failed write is swallowed rather than thrown into a
/// caller that already moved on, and leaves no half-file behind it.
void download(String filename, List<int> content) {
  // A phone has no "Save as" window: file_selector's getSaveLocation is
  // desktop-only and returned nothing, so the icon did nothing (Jose,
  // 2026-10-30, a signed contract on his phone). There the share sheet is
  // where a file is saved, mailed or opened.
  if (isPhone()) {
    unawaited(_share(filename, content));
    return;
  }
  unawaited(_saveAs(filename, content));
}

/// Swappable so a test can take the phone path on a desktop runner.
@visibleForTesting
bool Function() isPhone = () => Platform.isAndroid || Platform.isIOS;

@visibleForTesting
Future<void> Function(Uint8List bytes, String filename) sharePdf =
    (bytes, filename) => Printing.sharePdf(bytes: bytes, filename: filename);

Future<void> _share(String filename, List<int> content) async {
  try {
    // Every file goes to the share sheet. Writing non-PDFs silently into
    // the app's documents showed nothing, so an email attachment "did
    // nothing" on iPhone (Jose, 2026-11-03). iOS shares the file by its name
    // and extension, so any type opens or saves there.
    // ponytail: Android's intent is typed application/pdf by printing; Save
    // to Files/Drive keeps the name, but a viewer may refuse a non-PDF. Swap
    // to share_plus if that bites.
    await sharePdf(Uint8List.fromList(content), filename);
  } catch (_) {
    // As below: the caller has moved on.
  }
}

Future<void> _saveAs(String filename, List<int> content) async {
  try {
    final dot = filename.lastIndexOf('.');
    final location = await fs.getSaveLocation(
      suggestedName: filename,
      acceptedTypeGroups: dot < 0
          ? const []
          : [
              fs.XTypeGroup(
                label: filename.substring(dot + 1).toUpperCase(),
                extensions: [filename.substring(dot + 1)],
              ),
            ],
    );
    if (location == null) return;

    await fs.XFile.fromData(
      Uint8List.fromList(content),
      name: filename,
    ).saveTo(location.path);
  } catch (_) {
    // Nothing to tell: see above.
  }
}

void clearQuery() {}

String? previewUrl(List<int> bytes, String mime) => null;

void releasePreviewUrl(String url) {}
