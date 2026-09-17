/// The body of an email, as the server sanitised it: BLOCKS, never HTML.
///
/// ── The decision this file exists to keep ────────────────────────
///
/// A contract is written by somebody inside the organization. An email is
/// written by a stranger — that is the entire point of the module, which is a
/// window onto `info@` and whatever the world sends it. Handing that markup to
/// the browser inside our own origin would be the worst cross-site scripting
/// surface in the app: one `<img onerror>` in a quote request and the sender is
/// running script against a signed-in session in a shared inbox.
///
/// So the server sanitises and publishes a SHAPE, and this end draws that shape.
/// There is no path here that takes a string of markup, and adding one is the
/// change this comment exists to stop. If a block ever arrives carrying an
/// `html` key it is ignored — see [readMailBlocks], and the test that watches
/// for exactly that.
///
/// ── Why it is not core/document_html.dart's model ────────────────
///
/// It is deliberately the same IDEA, and deliberately not the same classes.
/// That file reads a contract, and its blocks carry a `tag` flag for a merge
/// tag the server could not resolve — a concept email has no version of.
/// Email carries two of its own: a LINK, which a contract never has, and an
/// image that was blocked rather than fetched, which is a thing on the page
/// with no text in it at all.
///
/// Sharing one enum would mean every reader of either having to know which
/// halves apply to it. Two small models, each honest about its own domain, is
/// the cheaper of the two. The DocumentBlock shape is followed closely on
/// purpose so that anybody who has read one file recognises the other.
library;

import 'package:flutter/foundation.dart';

/// What a block of a message is, as far as drawing it goes.
///
/// [blockedImage] is not a picture that failed to load. It is a picture the
/// server refused to fetch, which is a fact about privacy rather than an error,
/// and it is a block of its own so the screen can say so IN PLACE rather than
/// leaving a gap where something used to be.
enum MailBlockKind { paragraph, heading, quote, rule, image, blockedImage }

/// A run of text with the same treatment.
@immutable
class MailSpan {
  const MailSpan(
    this.text, {
    this.strong = false,
    this.emphasis = false,
    this.link,
  });

  final String text;
  final bool strong;
  final bool emphasis;

  /// Where this run points, as the server published it, or null for prose.
  ///
  /// Carried as the WHOLE address rather than as a host, because the screen
  /// shows it: a link in a message from somebody you have never met is how
  /// phishing arrives, and "Click here" over an address nobody can see is the
  /// shape of that attack. What the screen does with it is
  /// message_body.dart's business, and what it does NOT do is open it.
  final String? link;
}

/// One paragraph, heading, quote, rule or blocked image.
@immutable
class MailBlock {
  const MailBlock(
    this.kind, {
    this.spans = const [],
    this.alt,
    this.host,
    this.src,
    this.width,
    this.height,
  });

  final MailBlockKind kind;
  final List<MailSpan> spans;

  /// The image's own description, when the message carried one.
  final String? alt;

  /// The host a picture that could not be fetched would have come from.
  /// [MailBlockKind.blockedImage] only.
  final String? host;

  /// Where to get the picture. A `data:` URI, or a token for our proxy.
  /// Never a sender-chosen http(s) URL.
  final String? src;

  /// CSS pixels the message asked to draw at. Null = pane width, never
  /// larger than the file (Gmail's `max-width: 100%`).
  final int? width;
  final int? height;

  String get text => spans.map((span) => span.text).join();
}

/// The blocks the server sent, read defensively.
///
/// Defensive is not politeness here. This client and the endpoint behind it
/// ship separately, and the failure mode of a strict reader is an exception in
/// a builder that blanks the message — so anything unrecognised is skipped and
/// everything recognised is kept, one block at a time.
///
/// ── The one rule that is not about tolerance ─────────────────────
///
/// A kind this build has never met, but which carries spans, is drawn as a
/// paragraph. Losing a sentence because it arrived wrapped in a block type
/// added next month would be the one failure a mail window must not have —
/// core/document_html.dart makes the same call about an unknown tag, for the
/// same reason.
///
/// A kind it has never met with NO spans is dropped, because there is nothing
/// to keep and inventing a box for it would be drawing a thing this build
/// cannot describe.
List<MailBlock> readMailBlocks(Object? json) {
  if (json is! List) return const [];

  final blocks = <MailBlock>[];

  for (final entry in json) {
    if (entry is! Map) continue;

    final kind = entry['kind'];

    if (kind == 'rule') {
      blocks.add(const MailBlock(MailBlockKind.rule));
      continue;
    }

    if (kind == 'image') {
      blocks.add(
        MailBlock(
          MailBlockKind.image,
          alt: _string(entry['alt']) ?? _spanText(entry['spans']),
          src: _string(entry['src']),
          width: _px(entry['width']),
          height: _px(entry['height']),
        ),
      );
      continue;
    }

    if (kind == 'blocked_image') {
      blocks.add(
        MailBlock(
          MailBlockKind.blockedImage,
          alt: _string(entry['alt']),
          host: _string(entry['host']),
        ),
      );
      continue;
    }

    final spans = _spans(entry['spans']);

    // Nothing to draw. A block that is only whitespace came from the sanitiser
    // tidying markup, not from anybody typing, and a stack of empty paragraphs
    // between two sentences is the message rendered wrongly.
    if (spans.every((span) => span.text.trim().isEmpty)) continue;

    blocks.add(
      MailBlock(
        switch (kind) {
          'heading' => MailBlockKind.heading,
          'quote' => MailBlockKind.quote,
          // 'paragraph', and anything this build has not met — see above.
          _ => MailBlockKind.paragraph,
        },
        spans: spans,
      ),
    );
  }

  return blocks;
}

List<MailSpan> _spans(Object? json) {
  if (json is! List) return const [];

  return [
    for (final entry in json)
      if (entry is Map && entry['text'] is String)
        MailSpan(
          entry['text'] as String,
          // `== true` rather than a cast: a server that sends 1, "1" or nothing
          // at all gets plain text, which is the safe direction to be wrong in.
          strong: entry['strong'] == true,
          emphasis: entry['emphasis'] == true,
          // `href`, which is the key Span::toArray publishes. It was read as
          // `link` from the day this was written, so EVERY address in EVERY
          // message has been dropped in production and the run below it drawn
          // as plain prose -- the anti-phishing affordance this file argues
          // for at length, absent for as long as it has existed. Nothing
          // reported it because a message with no visible address looks
          // exactly like a message with no links in it.
          //
          // Caught against a captured production payload
          // (message_body_desktop_test.dart): 8 addressed spans, 0 read. Every
          // fixture here said `link`, so the eleven tests in
          // email_blocks_test.dart agreed with the reader and neither of them
          // with the server.
          link: _string(entry['href']),
        ),
  ];
}

/// A string, or null for anything else — including the empty string, which is
/// a key the server filled in with nothing and means the same as its absence.
String? _string(Object? value) =>
    value is String && value.isNotEmpty ? value : null;

int? _px(Object? value) {
  if (value is int && value >= 1 && value <= 2000) return value;
  if (value is num) {
    final n = value.round();
    if (n >= 1 && n <= 2000) return n;
  }
  return null;
}

String? _spanText(Object? json) {
  if (json is! List) return null;
  final text = [
    for (final entry in json)
      if (entry is Map && entry['text'] is String) entry['text'] as String,
  ].join();
  return text.isEmpty ? null : text;
}
