import 'package:flutter/material.dart';

import 'message_html_stub.dart'
    if (dart.library.js_interop) 'message_html_web.dart' as impl;

/// HTML mail, as Gmail would show it: tables, sized pictures, the layout
/// the sender designed.
///
/// The string is already sanitised on the server. This widget must not
/// parse it as Flutter HTML and must not open it in the app's own origin
/// without a sandbox. The web implementation is a sandboxed iframe;
/// everywhere else this is a no-op and the block body is used instead —
/// unless [ownHtml] says the HTML is EzBizi's own (the newsletter preview),
/// which a native WebView may draw with scripts off.
class MessageHtmlView extends StatelessWidget {
  const MessageHtmlView({super.key, required this.html, this.ownHtml = false});

  final String html;
  final bool ownHtml;

  @override
  Widget build(BuildContext context) =>
      impl.buildMessageHtml(html, ownHtml: ownHtml);
}
