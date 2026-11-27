import 'files_io.dart' if (dart.library.js_interop) 'files_web.dart' as impl;

/// Getting a file out of the browser and back into it.
///
/// Behind the same conditional import as TokenStore, and for the same reason:
/// `package:web` will not compile off the web, which takes `flutter test` down
/// with it. The stubs make the whole app testable without pretending the
/// non-web build can do these things.
class Files {
  /// Offer [content] to the user as a download named [filename].
  static void download(String filename, List<int> content) {
    final name = withExtension(filename, content);
    final hook = debugOnDownload;
    if (hook != null) return hook(name, content);
    impl.download(name, content);
  }

  /// [filename] with an extension read from [content]'s first bytes when it
  /// has none (Adeline 11-24: Monday assets often come without one, and the
  /// saved file would not open).
  static String withExtension(String filename, List<int> content) {
    if (RegExp(r'\.[A-Za-z0-9]{1,5}$').hasMatch(filename)) return filename;
    bool at(int i, List<int> sig) =>
        content.length >= i + sig.length &&
        List.generate(
          sig.length,
          (k) => content[i + k] == sig[k],
        ).every((b) => b);
    final ext = at(0, [0x89, 0x50, 0x4E, 0x47])
        ? 'png'
        : at(0, [0xFF, 0xD8, 0xFF])
        ? 'jpg'
        : at(0, [0x47, 0x49, 0x46, 0x38])
        ? 'gif'
        : at(0, [0x25, 0x50, 0x44, 0x46])
        ? 'pdf'
        : at(8, [0x57, 0x45, 0x42, 0x50])
        ? 'webp'
        : at(4, [0x66, 0x74, 0x79, 0x70, 0x68, 0x65, 0x69, 0x63])
        ? 'heic'
        : at(4, [0x66, 0x74, 0x79, 0x70, 0x71, 0x74])
        ? 'mov'
        : at(4, [0x66, 0x74, 0x79, 0x70])
        ? 'mp4'
        : at(0, [0x50, 0x4B, 0x03, 0x04])
        ? 'zip'
        : null;
    return ext == null
        ? filename
        : '${filename.isEmpty ? 'download' : filename}.$ext';
  }

  /// Tests set this to see what would have been saved, and under what name.
  static void Function(String filename, List<int> content)? debugOnDownload;

  /// Remove the query string from the address bar, on web. A no-op elsewhere.
  static void clearQuery() => impl.clearQuery();

  /// Ask for a file and return its text, or null if they cancelled.
  ///
  /// Uses the browser's own file input rather than a picker package — one
  /// `<input type="file">` against a dependency, its transitive tree, and its
  /// permission plumbing.
  static Future<PickedFile?> pickText() => impl.pickText();

  /// Ask for an image and return its bytes.
  static Future<PickedBytes?> pickImage() => impl.pickImage();

  /// Whether [takePhoto] opens a camera here: the browser on a phone, where
  /// `<input capture>` hands the pick to the camera, and the Android and iOS
  /// apps, through image_picker. Desktop browsers ignore `capture` and the
  /// desktop apps have no camera flow, so both are false and offer the file
  /// picker alone.
  static bool get canTakePhoto => impl.canTakePhoto;

  /// A photo straight from the phone's camera, or null if they cancelled.
  static Future<PickedBytes?> takePhoto() => impl.takePhoto();

  /// Ask for any file the OS picker will give us, as bytes.
  ///
  /// Papers on a period, visit or payment are PDFs as often as pictures, and
  /// reading them as text would corrupt them the same way a photo would.
  static Future<PickedBytes?> pickBytes({String accept = ''}) {
    final hook = debugPickBytes;
    if (hook != null) return hook(accept);
    return impl.pickBytes(accept: accept);
  }

  /// Tests set this to answer [pickBytes] with a file, given its `accept`.
  static Future<PickedBytes?> Function(String accept)? debugPickBytes;

  /// A URL the browser can play or show, for bytes we already hold.
  ///
  /// Web only, and null everywhere else. On web this is an object URL over a
  /// Blob — the same mechanism [download] already uses — so a video the person
  /// just picked can be played back BEFORE it is uploaded anywhere. Nothing
  /// leaves the machine to make this work, which is the point: the file is
  /// being reviewed precisely because nobody is sure it is the right one yet.
  ///
  /// Off the web it answers null and the caller shows what it knows instead.
  /// A native player would need the bytes written to a real file and a
  /// different controller, which is a platform's worth of work for a preview
  /// the desktop and mobile builds can live without today.
  static String? previewUrl(List<int> bytes, String mime) =>
      impl.previewUrl(bytes, mime);

  /// Hand back a URL from [previewUrl]. Blobs are held until revoked, so a
  /// composer that picked ten videos would otherwise keep all ten in memory.
  static void releasePreviewUrl(String url) => impl.releasePreviewUrl(url);

  /// Running under `flutter test`, where no platform video player answers.
  static bool get underTest => impl.underTest;
}

class PickedFile {
  const PickedFile(this.name, this.text);

  final String name;
  final String text;
}

class PickedBytes {
  const PickedBytes(this.name, this.bytes);

  final String name;
  final List<int> bytes;
}
