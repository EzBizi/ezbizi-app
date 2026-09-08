import 'dart:ui_web' as ui_web;

import 'package:flutter/widgets.dart';
import 'package:web/web.dart' as web;

int _seq = 0;

Widget buildVideoEmbed(String url) => _VideoIframe(url: url);

/// A guide screen's video, inline — a DOM `<iframe>` pointed at the URL an
/// author typed, the same `platformViewRegistry` mechanism
/// message_html_web.dart's mail iframe and camera_view_web.dart's camera
/// `<img>`/`<video>` already use.
///
/// Scripts stay ON here (`allow-scripts`), unlike the mail iframe, which
/// withholds them on purpose — mail is an unknown sender's HTML, a training
/// screen's video link is content an org's own staff typed while authoring a
/// guide, and most embeddable video hosts (YouTube among them) do not render
/// at all without script. `allow-same-origin` is granted alongside it for the
/// same reason it is safe on a genuinely cross-origin URL: "same origin"
/// here means the VIDEO HOST's own origin, never this app's, so the frame
/// still cannot read this session.
class _VideoIframe extends StatefulWidget {
  const _VideoIframe({required this.url});

  final String url;

  @override
  State<_VideoIframe> createState() => _VideoIframeState();
}

class _VideoIframeState extends State<_VideoIframe> {
  late String _viewType;

  @override
  void initState() {
    super.initState();
    _register(widget.url);
  }

  @override
  void didUpdateWidget(covariant _VideoIframe old) {
    super.didUpdateWidget(old);
    if (old.url != widget.url) {
      setState(() => _register(widget.url));
    }
  }

  void _register(String url) {
    _viewType = 'ezbizi-training-video-${_seq++}';
    ui_web.platformViewRegistry.registerViewFactory(_viewType, (int _) {
      final iframe = web.HTMLIFrameElement();
      iframe.src = url;
      iframe.style
        ..setProperty('border', '0')
        ..setProperty('width', '100%')
        ..setProperty('height', '100%');
      iframe.setAttribute('referrerpolicy', 'no-referrer');
      iframe.setAttribute('allowfullscreen', 'true');
      iframe.setAttribute(
        'sandbox',
        'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox',
      );
      return iframe;
    });
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox.expand(child: HtmlElementView(viewType: _viewType));
  }
}
