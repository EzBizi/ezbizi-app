import 'dart:convert';

/// What the composer can undo to: the content of one newsletter at a moment.
class NewsletterSnapshot {
  NewsletterSnapshot({
    required this.subject,
    required this.name,
    required this.preheader,
    required this.html,
    required List<Map<String, dynamic>> blocks,
  }) : blocks = _copy(blocks);

  final String subject;
  final String name;
  final String preheader;

  /// The custom HTML, or empty when the blocks are what goes out.
  final String html;
  final List<Map<String, dynamic>> blocks;

  /// A deep copy, so later in-place edits to the live blocks cannot reach it.
  List<Map<String, dynamic>> copyBlocks() => _copy(blocks);

  String get _fingerprint => jsonEncode([subject, name, preheader, html, blocks]);

  static List<Map<String, dynamic>> _copy(List<Map<String, dynamic>> blocks) => [
    for (final b in blocks)
      Map<String, dynamic>.from(jsonDecode(jsonEncode(b)) as Map),
  ];
}

/// Undo and redo over the composer's content (P1a, 2026-11-17).
///
/// The composer edits its blocks in place and then says "changed", so the
/// history keeps the state AFTER the last change ([_base]) and, on the next
/// change, pushes that onto the undo stack. Changes closer together than
/// [coalesce] are one step, so typing a word is one undo rather than five.
class NewsletterHistory {
  /// Widget tests set this to zero so each action is its own step.
  static Duration coalesce = const Duration(milliseconds: 900);

  final List<NewsletterSnapshot> _undo = [];
  final List<NewsletterSnapshot> _redo = [];
  NewsletterSnapshot? _base;
  DateTime? _last;

  bool get canUndo => _undo.isNotEmpty;
  bool get canRedo => _redo.isNotEmpty;

  /// A newsletter was opened or reloaded: nothing before it can be undone.
  void reset(NewsletterSnapshot now) {
    _undo.clear();
    _redo.clear();
    _base = now;
    _last = null;
  }

  /// The content is now [now]. A no-op change (same content) records nothing.
  void changed(NewsletterSnapshot now) {
    final base = _base;
    if (base == null) {
      _base = now;
      return;
    }
    if (base._fingerprint == now._fingerprint) return;
    final at = DateTime.now();
    final last = _last;
    if (_undo.isEmpty || last == null || at.difference(last) > coalesce) {
      _undo.add(base);
      if (_undo.length > 100) _undo.removeAt(0);
    }
    _last = at;
    _redo.clear();
    _base = now;
  }

  /// The state to go back to, or null when there is none.
  NewsletterSnapshot? undo() {
    if (_undo.isEmpty || _base == null) return null;
    _redo.add(_base!);
    _base = _undo.removeLast();
    _last = null;
    return _base;
  }

  NewsletterSnapshot? redo() {
    if (_redo.isEmpty || _base == null) return null;
    _undo.add(_base!);
    _base = _redo.removeLast();
    _last = null;
    return _base;
  }
}
