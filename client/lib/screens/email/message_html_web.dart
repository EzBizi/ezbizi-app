import 'dart:ui_web' as ui_web;

import 'package:flutter/widgets.dart';
import 'package:web/web.dart' as web;

int _seq = 0;

Widget buildMessageHtml(String html, {bool ownHtml = false}) =>
    _MailIframe(html: html);

class _MailIframe extends StatefulWidget {
  const _MailIframe({required this.html});

  final String html;

  @override
  State<_MailIframe> createState() => _MailIframeState();
}

class _MailIframeState extends State<_MailIframe> {
  late String _viewType;

  @override
  void initState() {
    super.initState();
    _register(widget.html);
  }

  @override
  void didUpdateWidget(covariant _MailIframe old) {
    super.didUpdateWidget(old);
    if (old.html != widget.html) {
      setState(() => _register(widget.html));
    }
  }

  void _register(String html) {
    _viewType = 'ezbizi-mail-html-${_seq++}';
    ui_web.platformViewRegistry.registerViewFactory(_viewType, (int _) {
      final iframe = web.HTMLIFrameElement();
      iframe.setAttribute('srcdoc', html);
      iframe.style
        ..setProperty('border', '0')
        ..setProperty('width', '100%')
        ..setProperty('height', '100%')
        ..setProperty('background', '#ffffff')
        ..setProperty('overflow', 'auto');
      iframe.setAttribute('referrerpolicy', 'no-referrer');
      // Scripts stay out. Popups are so a "View in console" button opens
      // a real tab rather than navigating this app. Same-origin is NOT
      // granted, so the frame cannot read our session.
      iframe.setAttribute(
        'sandbox',
        'allow-popups allow-popups-to-escape-sandbox',
      );
      return iframe;
    });
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox.expand(
      child: HtmlElementView(viewType: _viewType),
    );
  }
}
