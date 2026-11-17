import 'dart:convert';

import 'newsletter_local_copy_io.dart'
    if (dart.library.js_interop) 'newsletter_local_copy_web.dart'
    as store;

/// The composer's unsaved work, kept on this device (P1a, 2026-11-17).
///
/// Written whenever there are edits the server has not acknowledged, and
/// cleared when a save lands. Keyed by newsletter id AND the revision the
/// edits were built on, so a refresh, an expired session or a refused (409)
/// save can offer the copy back, and say which saved version it started from.
/// In a browser this is localStorage; elsewhere it lives for the session.
class NewsletterLocalCopy {
  const NewsletterLocalCopy({
    required this.newsletterId,
    required this.revision,
    required this.at,
    required this.content,
  });

  final String newsletterId;

  /// The saved revision these edits were built on.
  final int revision;
  final DateTime at;

  /// subject, name, preheader, blocks, html_override.
  final Map<String, dynamic> content;

  static String _pointer(String id) => 'ezbizi.newsletter.local.$id';
  static String _key(String id, int revision) =>
      'ezbizi.newsletter.local.$id@$revision';

  static void save(String id, int revision, Map<String, dynamic> content) {
    final old = store.read(_pointer(id));
    if (old != null && old != '$revision') {
      store.write(_key(id, int.tryParse(old) ?? -1), null);
    }
    store.write(
      _key(id, revision),
      jsonEncode({'at': DateTime.now().toIso8601String(), 'content': content}),
    );
    store.write(_pointer(id), '$revision');
  }

  static NewsletterLocalCopy? load(String id) {
    final revision = int.tryParse(store.read(_pointer(id)) ?? '');
    if (revision == null) return null;
    final raw = store.read(_key(id, revision));
    if (raw == null) return null;
    try {
      final json = jsonDecode(raw) as Map<String, dynamic>;
      return NewsletterLocalCopy(
        newsletterId: id,
        revision: revision,
        at: DateTime.tryParse('${json['at']}') ?? DateTime.now(),
        content: Map<String, dynamic>.from(json['content'] as Map),
      );
    } catch (_) {
      return null;
    }
  }

  static void clear(String id) {
    final revision = int.tryParse(store.read(_pointer(id)) ?? '');
    if (revision != null) store.write(_key(id, revision), null);
    store.write(_pointer(id), null);
  }
}
