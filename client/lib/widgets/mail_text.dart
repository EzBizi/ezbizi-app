import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// A customer message as a bubble shows it (Jose, 2026-11-13: "perhaps we
/// can have that thing at the bottom properly shown"). Display only: the
/// stored text, and what Copy copies, stay as received.
///   - "Sent from Yahoo Mail for iPad <https://…>" becomes a quiet [footer];
///   - a line break inside a sentence (plain-text mail wraps at ~72) is joined;
///   - a URL shows as its domain, tappable ([mailSpans]).
class MailText {
  const MailText(this.body, this.footer);

  final String body;
  final String? footer;

  static final _url = RegExp(r'<?(https?://[^\s<>]+)>?');
  static final _client = RegExp(r'^(sent from|get outlook for)\b', caseSensitive: false);

  factory MailText.of(String text) {
    var lines = text.replaceAll('\r\n', '\n').split('\n');
    String? footer;

    final at = lines.lastIndexWhere((l) => _client.hasMatch(l.trim()));
    // Only when nothing but links and blank lines follows: a quoted reply
    // underneath keeps its signature where it is.
    if (at >= 0 && lines.skip(at + 1).join().replaceAll(_url, '').replaceAll(RegExp(r'[<>\s]'), '').isEmpty) {
      footer = lines[at].replaceAll(_url, '').trim();
      lines = lines.sublist(0, at);
    }

    final out = <String>[];
    for (final line in lines) {
      final prev = out.isEmpty ? '' : out.last.trimRight();
      final next = line.trimLeft();
      if (prev.isNotEmpty && !RegExp(r'[.!?:]$').hasMatch(prev) && RegExp(r'^[a-z]').hasMatch(next)) {
        out[out.length - 1] = '$prev $next';
      } else {
        out.add(line);
      }
    }

    return MailText(out.join('\n').trim(), footer);
  }

  /// The body with each URL as "domain ↗", opening the full link on tap.
  // ponytail: recognizers are not disposed; a bubble holds a few at most.
  List<InlineSpan> spans(TextStyle? link) {
    final spans = <InlineSpan>[];
    var from = 0;
    for (final m in _url.allMatches(body)) {
      spans.add(TextSpan(text: body.substring(from, m.start)));
      final uri = Uri.tryParse(m.group(1)!);
      spans.add(TextSpan(
        text: '${uri?.host ?? m.group(1)} ↗',
        style: link,
        recognizer: uri == null
            ? null
            : (TapGestureRecognizer()..onTap = () => launchUrl(uri, mode: LaunchMode.externalApplication)),
      ));
      from = m.end;
    }
    spans.add(TextSpan(text: body.substring(from)));

    return spans;
  }
}
