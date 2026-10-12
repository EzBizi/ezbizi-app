// ignore_for_file: dangling_library_doc_comments
/// Shared, platform-free types for remote file access (the agent half of
/// 2026-10-01-monitoring-files). No `dart:io` here, so the web stub, the
/// `dart:io` reader and the poll service can all import it.

/// A read the agent could not complete, carrying the slug the manager's screen
/// shows — the SAME slugs FilesController validates the answer against:
/// `access_denied`, `not_found`, `too_large`, `unreadable`. The viewer side
/// (file_browser_service.dart) also throws `offline` when no answer comes back.
class FileAccessException implements Exception {
  const FileAccessException(this.slug);

  final String slug;

  @override
  String toString() => 'FileAccessException($slug)';
}

/// What the reader does for one request, injected in tests so the service can
/// be driven without touching a real disk.
abstract interface class FileAccessReader {
  /// Entries of [path] as `{name, is_dir, size_bytes, modified_at}`. An empty
  /// path means "the roots" — the drives on Windows. Throws
  /// [FileAccessException] with a slug the manager will see.
  Future<List<Map<String, Object?>>> list(String path);

  /// [path]'s bytes as `{name, size, content_base64}`, refusing with
  /// `too_large` before reading anything when the file is over [maxBytes].
  Future<Map<String, Object?>> read(String path, int maxBytes);

  /// Explorer's Quick access for the signed-in user — `{label, path}` for
  /// Desktop, Documents, Downloads, Pictures, Videos, Music that exist. Sent
  /// with the roots listing (Jose, 2026-10-12). Never throws: no links is an
  /// empty list, not a failed drive list.
  Future<List<Map<String, String>>> quickLinks();
}
