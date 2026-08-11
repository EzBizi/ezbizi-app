/// Reading the document the server rendered, so the editor can DRAW it.
///
/// ── Why this exists at all ───────────────────────────────────────
///
/// `POST /hr/contracts/{contract}/preview` answers with the document as HTML,
/// because that is the format the finished contract is stored and fingerprinted
/// in (App\Support\Contracts\DocumentRenderer states the case). The onboarding
/// form shows that markup EXACTLY as it arrived, tags and all, and says so —
/// there the bytes are the evidence and a neatened version would be a picture
/// of a document nobody signed.
///
/// The editor is the opposite situation. Nothing here is signed, nothing is
/// fingerprinted, and the owner's request is literally about appearance:
/// *"50% showing the actual contract, and how it looks with white background as
/// that's the color of pages"*. A preview pane full of `<p>` would answer none
/// of it. So the markup is read into blocks and the blocks are drawn.
///
/// ── Why it is hand-written and small ─────────────────────────────
///
/// No HTML package is in pubspec, and adding one is a deploy decision rather
/// than a code one — the same argument DocumentRenderer makes about a PDF
/// writer. What has to be read is one server's own output: paragraphs,
/// headings, a definition list, and the odd bit of emphasis. So this covers
/// that and is explicit about the rest.
///
/// What it does NOT do, deliberately: no attributes, no CSS, no tables laid
/// out, no images, no scripts (`<script>` and `<style>` are dropped whole,
/// content included). A tag it has never met is IGNORED and the text inside it
/// is kept — losing a sentence because it arrived wrapped in something new
/// would be the one failure a preview must not have.
library;

import 'package:flutter/foundation.dart';

/// What a block of the document is, as far as drawing it goes.
///
/// Not "what tag produced it": [DocumentBlockKind.term] and
/// [DocumentBlockKind.definition] are a definition list's two halves, and the
/// page draws them as a label above its answer.
enum DocumentBlockKind { title, heading, paragraph, term, definition, rule }

/// A run of text with the same treatment.
@immutable
class DocumentSpan {
  const DocumentSpan(
    this.text, {
    this.strong = false,
    this.emphasis = false,
    this.tag = false,
  });

  final String text;
  final bool strong;
  final bool emphasis;

  /// This run is a merge tag that is still, literally, `{{like_this}}`.
  ///
  /// It is not called "unresolved", because what it MEANS depends on where the
  /// text came from and this file does not know: in a document the server
  /// rendered, a surviving tag is one the server could not resolve; in the
  /// draft of a contract that has not been created yet, nothing has been merged
  /// at all. The widget drawing it says which — see ContractDocumentPage.
  final bool tag;

  /// `first_name` out of `{{ first_name }}`, and empty when this is not a tag.
  String get tagKey {
    if (!tag) return '';

    var inner = text.trim();

    if (inner.startsWith('{{')) inner = inner.substring(2);
    if (inner.endsWith('}}')) inner = inner.substring(0, inner.length - 2);

    return inner.trim();
  }
}

/// One paragraph, heading or list line of the document.
@immutable
class DocumentBlock {
  const DocumentBlock(this.kind, this.spans);

  final DocumentBlockKind kind;
  final List<DocumentSpan> spans;

  String get text => spans.map((span) => span.text).join();
}

/// The document the server rendered, as blocks.
List<DocumentBlock> readDocumentHtml(String html) =>
    (_Reader()..read(html)).blocks;

/// A body somebody is typing, as blocks — no merging, no server.
///
/// Used only where there is nothing to ask: a contract that does not exist yet
/// has no endpoint to render it and no version to render it against. Every
/// `{{tag}}` comes back marked as a tag, because that is exactly what is on
/// screen. Nothing is resolved here and nothing ever should be — the values are
/// the server's answer, and a second implementation of the merge would be a
/// preview that agrees with the document until the day it does not.
List<DocumentBlock> readDraftText(String text) {
  final blocks = <DocumentBlock>[];

  // A blank line ends a paragraph, which is how anybody typing prose already
  // expects it to work. Single newlines are kept inside the block and drawn as
  // line breaks.
  for (final paragraph in text.split(RegExp(r'\n[ \t]*\n'))) {
    if (paragraph.trim().isEmpty) continue;

    blocks.add(
      DocumentBlock(
        DocumentBlockKind.paragraph,
        _spans(paragraph.trim(), strong: false, emphasis: false),
      ),
    );
  }

  return blocks;
}

/// Every merge tag still sitting in the text, in the order they appear, once
/// each.
List<String> tagsIn(List<DocumentBlock> blocks) {
  final keys = <String>[];

  for (final block in blocks) {
    for (final span in block.spans) {
      if (span.tag && !keys.contains(span.tagKey)) keys.add(span.tagKey);
    }
  }

  return keys;
}

/// `{{ … }}`, with no braces inside it.
///
/// Deliberately un-greedy about nesting: `{{a}} and {{b}}` is two tags, and a
/// stray `{{` with no closing pair is text, which is what somebody mid-keystroke
/// has typed.
final RegExp _tagPattern = RegExp(r'\{\{[^{}]*\}\}');

/// Splits a run of text into spans, marking the merge tags in it.
List<DocumentSpan> _spans(
  String text, {
  required bool strong,
  required bool emphasis,
}) {
  final spans = <DocumentSpan>[];
  var at = 0;

  for (final match in _tagPattern.allMatches(text)) {
    if (match.start > at) {
      spans.add(
        DocumentSpan(
          text.substring(at, match.start),
          strong: strong,
          emphasis: emphasis,
        ),
      );
    }

    spans.add(DocumentSpan(match[0]!, strong: strong, emphasis: emphasis, tag: true));
    at = match.end;
  }

  if (at < text.length) {
    spans.add(
      DocumentSpan(text.substring(at), strong: strong, emphasis: emphasis),
    );
  }

  return spans;
}

/// The scanner.
///
/// A single pass: text up to the next `<`, then the tag, then repeat. It keeps
/// one open block and one style depth, and everything it does not recognise is
/// a no-op that leaves the text flowing into the block it is already in.
class _Reader {
  final List<DocumentBlock> blocks = [];

  final List<DocumentSpan> _spansSoFar = [];
  final StringBuffer _text = StringBuffer();

  DocumentBlockKind _kind = DocumentBlockKind.paragraph;
  int _strong = 0;
  int _emphasis = 0;

  /// Tags whose CONTENT is not part of the document. `<head>` carries a
  /// `<title>` that repeats the heading below it, and the other two are not
  /// prose at all.
  static const _skipped = {'head', 'style', 'script'};

  static const _blocks = {
    'p', 'div', 'section', 'article', 'header', 'footer', 'main', 'body',
    'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'tr', 'td',
    'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'figure',
  };

  static DocumentBlockKind _kindOf(String name) => switch (name) {
    'h1' => DocumentBlockKind.title,
    'h2' || 'h3' || 'h4' || 'h5' || 'h6' => DocumentBlockKind.heading,
    'dt' => DocumentBlockKind.term,
    'dd' => DocumentBlockKind.definition,
    _ => DocumentBlockKind.paragraph,
  };

  void read(String html) {
    var at = 0;

    while (at < html.length) {
      final open = html.indexOf('<', at);

      if (open < 0) {
        _write(html.substring(at));
        break;
      }

      if (open > at) _write(html.substring(at, open));

      // A comment can contain '>' — including the doctype's own internal
      // subset — so it is skipped by its own terminator rather than by the
      // next angle bracket.
      if (html.startsWith('<!--', open)) {
        final end = html.indexOf('-->', open);
        at = end < 0 ? html.length : end + 3;
        continue;
      }

      final close = html.indexOf('>', open);

      // An unclosed '<' is not markup. It is a less-than sign somebody typed,
      // and it belongs in the document.
      if (close < 0) {
        _write(html.substring(open));
        break;
      }

      at = _tag(html, open, close);
    }

    _endBlock(DocumentBlockKind.paragraph);
  }

  /// Handles one tag and answers where reading carries on from.
  int _tag(String html, int open, int close) {
    final raw = html.substring(open + 1, close).trim();
    final closing = raw.startsWith('/');
    final name = _nameOf(closing ? raw.substring(1) : raw);

    if (name.isEmpty) return close + 1;

    if (_skipped.contains(name) && !closing) {
      // Everything up to the matching close, gone. Falling back to the end of
      // the input would swallow the document; falling back to just past this
      // tag keeps whatever follows, which is the safer half of a malformed
      // page.
      final end = html.toLowerCase().indexOf('</$name', close);

      if (end < 0) return close + 1;

      final after = html.indexOf('>', end);

      return after < 0 ? html.length : after + 1;
    }

    switch (name) {
      case 'br':
        // Not through _write: a line break the markup asked for has to survive
        // the whitespace collapsing that every other space goes through.
        _text.write('\n');
        return close + 1;

      case 'hr':
        _endBlock(DocumentBlockKind.paragraph);
        blocks.add(const DocumentBlock(DocumentBlockKind.rule, []));
        return close + 1;

      case 'strong' || 'b':
        _endSpan();
        _strong += closing ? -1 : 1;
        if (_strong < 0) _strong = 0;
        return close + 1;

      case 'em' || 'i':
        _endSpan();
        _emphasis += closing ? -1 : 1;
        if (_emphasis < 0) _emphasis = 0;
        return close + 1;
    }

    // A block boundary either way round: `<p>` starts one, `</p>` ends one.
    // Anything else — a span, a link, a tag this build has never met — is left
    // alone, and its text keeps flowing into the block it is already in.
    if (_blocks.contains(name)) {
      _endBlock(closing ? DocumentBlockKind.paragraph : _kindOf(name));
    }

    return close + 1;
  }

  /// `p class="org"` → `p`.
  static String _nameOf(String raw) {
    final buffer = StringBuffer();

    for (final rune in raw.runes) {
      final character = String.fromCharCode(rune).toLowerCase();

      if (RegExp(r'[a-z0-9]').hasMatch(character)) {
        buffer.write(character);
      } else {
        break;
      }
    }

    return buffer.toString();
  }

  /// A run of text, entities decoded and whitespace collapsed the way a browser
  /// collapses it.
  void _write(String raw) {
    if (raw.isEmpty) return;

    _text.write(_decode(raw).replaceAll(RegExp(r'[ \t\r\n]+'), ' '));
  }

  void _endSpan() {
    final text = _text.toString();

    _text.clear();

    if (text.isEmpty) return;

    _spansSoFar.addAll(_spans(text, strong: _strong > 0, emphasis: _emphasis > 0));
  }

  void _endBlock(DocumentBlockKind next) {
    _endSpan();

    if (_spansSoFar.isNotEmpty) {
      final trimmed = _trimEnds(_spansSoFar);

      if (trimmed.isNotEmpty) blocks.add(DocumentBlock(_kind, trimmed));
    }

    _spansSoFar.clear();
    _kind = next;
  }

  /// The space either side of a block, which came from the markup's own
  /// indentation rather than from anything anybody wrote.
  static List<DocumentSpan> _trimEnds(List<DocumentSpan> spans) {
    final kept = [
      for (final span in spans)
        if (span.text.isNotEmpty) span,
    ];

    if (kept.isEmpty) return const [];

    DocumentSpan trim(DocumentSpan span, String text) => DocumentSpan(
      text,
      strong: span.strong,
      emphasis: span.emphasis,
      tag: span.tag,
    );

    kept[0] = trim(kept.first, kept.first.text.trimLeft());
    kept[kept.length - 1] = trim(kept.last, kept.last.text.trimRight());

    final left = [
      for (final span in kept)
        if (span.text.isNotEmpty) span,
    ];

    return left.every((span) => span.text.trim().isEmpty) ? const [] : left;
  }

  /// The handful of entities this server's own escaping produces, plus the
  /// numeric forms.
  ///
  /// `htmlspecialchars` with `ENT_QUOTES` emits exactly the first five. The
  /// numeric branch is there because it costs two lines and covers every
  /// accented character a contract written in Portuguese or Spanish would
  /// carry if anything upstream ever encodes them.
  static String _decode(String raw) {
    if (!raw.contains('&')) return raw;

    return raw.replaceAllMapped(RegExp(r'&(#x?[0-9a-fA-F]+|[a-zA-Z]+);'), (
      match,
    ) {
      final body = match[1]!;

      if (body.startsWith('#')) {
        final hex = body.startsWith('#x') || body.startsWith('#X');
        final digits = body.substring(hex ? 2 : 1);
        final code = int.tryParse(digits, radix: hex ? 16 : 10);

        // Out of range, a surrogate half, or not a number at all: left exactly
        // as it arrived, because a replacement character is a worse lie than
        // the entity itself.
        if (code == null || code < 0x20 || code > 0x10FFFF) return match[0]!;
        if (code >= 0xD800 && code <= 0xDFFF) return match[0]!;

        return String.fromCharCode(code);
      }

      return switch (body.toLowerCase()) {
        'amp' => '&',
        'lt' => '<',
        'gt' => '>',
        'quot' => '"',
        'apos' || '#39' => "'",
        'nbsp' => ' ',
        'mdash' => '—',
        'ndash' => '–',
        'hellip' => '…',
        'ldquo' => '“',
        'rdquo' => '”',
        'lsquo' => '‘',
        'rsquo' => '’',
        // Not a name this build knows. Shown as it arrived rather than dropped
        // — see the note on the class.
        _ => match[0]!,
      };
    });
  }
}
