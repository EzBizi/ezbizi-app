import 'dart:ui_web' as ui_web;

import 'package:flutter/widgets.dart';
import 'package:web/web.dart' as web;

int _seq = 0;

Widget buildCameraImage(String url) => _CameraImage(url: url);

/// A live camera frame, inline — a DOM `<img>` pointed at the camera's own
/// URL.
///
/// This is the same mechanism as message_html_web.dart's iframe: a
/// `platformViewRegistry` factory, only wrapping `<img>` instead of
/// `<iframe>`. `<img>` rather than anything more elaborate because it is
/// enough for both protocols this tile ever gets: an `mjpeg` source is a
/// `multipart/x-mixed-replace` stream, which every browser already renders
/// live inside a plain `<img>` without any script of ours; a `snapshot`
/// source is just a still picture, which is what `<img>` is for. Neither
/// needs the `<video>` element `hls`/`rtsp` will eventually want once a
/// player script exists (see camera_monitoring_tab.dart's ponytail note).
class _CameraImage extends StatefulWidget {
  const _CameraImage({required this.url});

  final String url;

  @override
  State<_CameraImage> createState() => _CameraImageState();
}

class _CameraImageState extends State<_CameraImage> {
  late String _viewType;

  @override
  void initState() {
    super.initState();
    _register(widget.url);
  }

  @override
  void didUpdateWidget(covariant _CameraImage old) {
    super.didUpdateWidget(old);
    if (old.url != widget.url) {
      setState(() => _register(widget.url));
    }
  }

  void _register(String url) {
    _viewType = 'ezbizi-camera-img-${_seq++}';
    ui_web.platformViewRegistry.registerViewFactory(_viewType, (int _) {
      final img = web.HTMLImageElement();
      img.src = url;
      img.alt = 'Camera live view';
      img.referrerPolicy = 'no-referrer';
      img.style
        ..setProperty('width', '100%')
        ..setProperty('height', '100%')
        ..setProperty('object-fit', 'cover');
      return img;
    });
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox.expand(child: HtmlElementView(viewType: _viewType));
  }
}
