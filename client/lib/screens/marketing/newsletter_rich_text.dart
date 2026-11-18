import 'package:flutter/material.dart';

/// Formatted words in a heading or text block (P1a, 2026-11-17).
///
/// The block keeps its words in `text` as before. Formatting is written into
/// that text as a handful of tags, `<b> <i> <a href="…"> <ul> <li>`, and the
/// block is marked `rich: true`. `BlockRenderer` escapes everything and lets
/// back in ONLY those tags (with a web, mail or phone link), so nothing typed
/// here can become arbitrary HTML in the email. This file draws the same
/// tags on the canvas and helps the format bar write them.
final RegExp _tag = RegExp(
  r'<(/?)(b|strong|i|em|ul|li)>|<a href="([^"]*)">|</a>',
  caseSensitive: false,
);

/// Does [text] carry any of the tags the renderer lets through?
bool hasMarkup(String text) => _tag.hasMatch(text);

/// [text] as spans: bold, italic, links underlined, list items bulleted.
///
/// With [showTags] (the editing field) every character is kept, as the field
/// requires, and the tags are drawn small and faint; without it (the canvas)
/// the tags are dropped and list items start on their own line with a bullet.
List<InlineSpan> markupSpans(
  String text,
  TextStyle base, {
  bool showTags = false,
  Color? linkColor,
}) {
  final spans = <InlineSpan>[];
  var bold = 0;
  var italic = 0;
  var link = 0;
  var at = 0;
  var startOfText = true;

  TextStyle style() => base.copyWith(
    fontWeight: bold > 0 ? FontWeight.bold : base.fontWeight,
    fontStyle: italic > 0 ? FontStyle.italic : base.fontStyle,
    decoration: link > 0 ? TextDecoration.underline : base.decoration,
    color: link > 0 ? (linkColor ?? const Color(0xFF00708F)) : base.color,
  );

  void words(String s) {
    if (s.isEmpty) return;
    spans.add(TextSpan(text: s, style: style()));
    startOfText = false;
  }

  for (final m in _tag.allMatches(text)) {
    words(text.substring(at, m.start));
    at = m.end;
    // `<a href="…">` and `</a>` have no group 2; both are the link.
    final name = (m.group(2) ?? 'a').toLowerCase();
    final closing = m.group(1) == '/' || m.group(0)!.toLowerCase() == '</a>';
    final delta = closing ? -1 : 1;
    switch (name) {
      case 'b' || 'strong':
        bold = (bold + delta).clamp(0, 99);
      case 'i' || 'em':
        italic = (italic + delta).clamp(0, 99);
      case 'a':
        link = (link + delta).clamp(0, 99);
    }

    if (showTags) {
      spans.add(
        TextSpan(
          text: m.group(0),
          style: base.copyWith(
            fontSize: (base.fontSize ?? 15) * 0.7,
            fontWeight: FontWeight.normal,
            fontStyle: FontStyle.normal,
            color: (base.color ?? Colors.black).withValues(alpha: 0.4),
          ),
        ),
      );
    } else if (name == 'li' && !closing) {
      spans.add(TextSpan(text: startOfText ? '•  ' : '\n•  ', style: style()));
      startOfText = false;
    } else if (name == 'ul' && closing) {
      spans.add(TextSpan(text: '\n', style: style()));
    }
  }
  words(text.substring(at));
  return spans;
}

/// A text field controller that draws the tags it holds, faintly, and the
/// words between them formatted.
class MarkupEditingController extends TextEditingController {
  MarkupEditingController({super.text});

  @override
  TextSpan buildTextSpan({
    required BuildContext context,
    TextStyle? style,
    required bool withComposing,
  }) {
    final base = style ?? DefaultTextStyle.of(context).style;
    return TextSpan(style: base, children: markupSpans(text, base, showTags: true));
  }
}

/// The selection wrapped in [open] … [close]; with nothing selected the pair
/// is inserted and the cursor put between them.
TextEditingValue wrapSelection(TextEditingValue v, String open, String close) {
  final s = v.selection.isValid
      ? v.selection
      : TextSelection.collapsed(offset: v.text.length);
  final picked = v.text.substring(s.start, s.end);
  final text = v.text.replaceRange(s.start, s.end, '$open$picked$close');
  final caret = s.isCollapsed
      ? s.start + open.length
      : s.start + open.length + picked.length + close.length;
  return TextEditingValue(
    text: text,
    selection: TextSelection.collapsed(offset: caret),
  );
}

/// A link around the selection, with the cursor left inside the empty
/// address so it can be typed straight away.
TextEditingValue insertLink(TextEditingValue v) {
  final s = v.selection.isValid
      ? v.selection
      : TextSelection.collapsed(offset: v.text.length);
  final picked = v.text.substring(s.start, s.end);
  const open = '<a href="https://';
  final text = v.text.replaceRange(
    s.start,
    s.end,
    '$open">${picked.isEmpty ? 'link' : picked}</a>',
  );
  return TextEditingValue(
    text: text,
    selection: TextSelection.collapsed(offset: s.start + open.length),
  );
}

/// The selected lines (or the current one) as a bulleted list.
TextEditingValue makeList(TextEditingValue v) {
  final s = v.selection.isValid
      ? v.selection
      : TextSelection.collapsed(offset: v.text.length);
  var start = v.text.lastIndexOf('\n', s.start == 0 ? 0 : s.start - 1);
  start = start < 0 ? 0 : start + 1;
  var end = v.text.indexOf('\n', s.end);
  end = end < 0 ? v.text.length : end;
  final lines = v.text.substring(start, end).split('\n');
  final list = '<ul>${[for (final l in lines) '<li>${l.trim()}</li>'].join()}</ul>';
  return TextEditingValue(
    text: v.text.replaceRange(start, end, list),
    selection: TextSelection.collapsed(offset: start + list.length),
  );
}
