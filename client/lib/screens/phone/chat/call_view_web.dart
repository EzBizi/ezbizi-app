import 'dart:async';
import 'dart:convert';
import 'dart:js_interop';
import 'dart:ui_web' as ui_web;

import 'package:flutter/widgets.dart';
import 'package:web/web.dart' as web;

final _frames = <Key, web.HTMLIFrameElement>{};

/// An iframe of the call page; it posts {ezbiziCall: event} to this window.
Widget buildChatCallView({
  required String url,
  required void Function(Map<String, dynamic> event) onEvent,
  Key? key,
}) => _CallFrame(key: key, url: url, onEvent: onEvent);

void hangUp(Key key) => _frames[key]?.contentWindow?.postMessage(
  {'ezbiziCallCommand': 'hangup'}.jsify(),
  '*'.toJS,
);

class _CallFrame extends StatefulWidget {
  const _CallFrame({super.key, required this.url, required this.onEvent});

  final String url;
  final void Function(Map<String, dynamic> event) onEvent;

  @override
  State<_CallFrame> createState() => _CallFrameState();
}

class _CallFrameState extends State<_CallFrame> {
  static int _seq = 0;
  late final String _viewType = 'ezbizi-chat-call-${_seq++}';
  late final web.HTMLIFrameElement _frame;
  StreamSubscription<web.MessageEvent>? _sub;

  @override
  void initState() {
    super.initState();
    _frame = web.HTMLIFrameElement()
      ..src = widget.url
      ..allow = 'camera; microphone; autoplay; fullscreen; display-capture'
      ..style.border = '0'
      ..style.width = '100%'
      ..style.height = '100%';
    if (widget.key != null) _frames[widget.key!] = _frame;
    ui_web.platformViewRegistry.registerViewFactory(
      _viewType,
      (int _) => _frame,
    );
    _sub = web.EventStreamProviders.messageEvent.forTarget(web.window).listen((
      e,
    ) {
      if (e.source != _frame.contentWindow) return;
      final data = e.data.dartify();
      if (data is Map && data['ezbiziCall'] is Map) {
        widget.onEvent(
          jsonDecode(jsonEncode(data['ezbiziCall'])) as Map<String, dynamic>,
        );
      }
    });
  }

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    _frames.remove(widget.key);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => HtmlElementView(viewType: _viewType);
}
