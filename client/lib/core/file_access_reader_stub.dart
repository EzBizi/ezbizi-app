import 'file_access_types.dart';

/// The web / no-`dart:io` reader. It exists only so `flutter build web`
/// compiles — the service that would call it is guarded off every build but the
/// Windows agent (see FileAccessService), so these never actually run. They
/// refuse loudly rather than returning a plausible empty listing, because a
/// reached stub is a wiring bug, not a device with no files.
class StubFileAccessReader implements FileAccessReader {
  const StubFileAccessReader();

  @override
  Future<List<Map<String, Object?>>> list(String path) async =>
      throw UnsupportedError('Remote file access runs only on the Windows agent.');

  @override
  Future<Map<String, Object?>> read(String path, int maxBytes) async =>
      throw UnsupportedError('Remote file access runs only on the Windows agent.');

  @override
  Future<List<Map<String, String>>> quickLinks() async =>
      throw UnsupportedError('Remote file access runs only on the Windows agent.');
}

/// The instance the conditional import exposes as `reader`.
const FileAccessReader reader = StubFileAccessReader();
