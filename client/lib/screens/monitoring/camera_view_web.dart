import 'dart:js_interop';
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

/// Whether an HLS stream can play inline in THIS browser.
///
/// Two ways it can, and the tab only routes an `hls` tile to the inline video
/// host when one holds (otherwise it stays the deep-link card):
///
///   * the vendored hls.js loaded and reports it can run here — Media Source
///     Extensions, i.e. every desktop browser but Safari; or
///   * the browser plays HLS natively — Safari and iOS, where `<video src>`
///     understands `application/vnd.apple.mpegurl` on its own.
///
/// If the script is absent (`window.Hls` undefined — same null the Twilio
/// interop leans on) AND there is no native support, this is false and the
/// caller shows the copy-URL card, exactly as before inline playback existed.
///
/// Cached: the answer cannot change within a session, and this is called once
/// per `hls` tile per rebuild. The `<video>` probe is cheap, but there is no
/// reason to pay it more than once.
bool? _hlsSupported;

bool hlsInlineSupported() {
  final cached = _hlsSupported;
  if (cached != null) return cached;
  if (_hlsGlobal != null && _Hls.isSupported()) return _hlsSupported = true;
  final probe = web.HTMLVideoElement();
  final native = probe.canPlayType('application/vnd.apple.mpegurl').isNotEmpty;
  return _hlsSupported = native;
}

Widget buildCameraHls(String url) => _CameraHls(url: url);

/// A live HLS camera, inline — a DOM `<video>` fed by hls.js (or by the
/// browser's own HLS on Safari), the `<video>` sibling of [_CameraImage]'s
/// `<img>`. Registered through the same `platformViewRegistry` factory; the
/// only real differences are the element and that hls.js keeps a handle we
/// must tear down.
class _CameraHls extends StatefulWidget {
  const _CameraHls({required this.url});

  final String url;

  @override
  State<_CameraHls> createState() => _CameraHlsState();
}

class _CameraHlsState extends State<_CameraHls> {
  late String _viewType;
  _Hls? _hls;

  @override
  void initState() {
    super.initState();
    _register(widget.url);
  }

  @override
  void didUpdateWidget(covariant _CameraHls old) {
    super.didUpdateWidget(old);
    if (old.url != widget.url) {
      setState(() => _register(widget.url));
    }
  }

  @override
  void dispose() {
    // hls.js holds a MediaSource, buffers and a fetch loop; dropping the Dart
    // reference does not stop them. destroy() is the only thing that frees the
    // network and memory this tile was using.
    _hls?.destroy();
    _hls = null;
    super.dispose();
  }

  void _register(String url) {
    _hls?.destroy();
    _hls = null;
    _viewType = 'ezbizi-camera-hls-${_seq++}';
    ui_web.platformViewRegistry.registerViewFactory(_viewType, (int _) {
      final video = web.HTMLVideoElement()
        // muted is what lets autoplay actually start — browsers block audible
        // autoplay, and a security camera has no audio worth unblocking for.
        ..autoplay = true
        ..muted = true
        ..controls = true;
      video.setAttribute('playsinline', '');
      video.style
        ..setProperty('width', '100%')
        ..setProperty('height', '100%')
        ..setProperty('object-fit', 'cover');

      if (_hlsGlobal != null && _Hls.isSupported()) {
        final hls = _Hls();
        hls.loadSource(url);
        hls.attachMedia(video);
        _hls = hls;
      } else if (video
          .canPlayType('application/vnd.apple.mpegurl')
          .isNotEmpty) {
        // Safari / iOS: no hls.js needed, the element plays the playlist.
        video.src = url;
      }
      // No else. The tab only builds this widget when hlsInlineSupported() is
      // true, so one of the two branches always runs; the guard is belt-and-
      // braces against a script that vanished between the check and here.
      return video;
    });
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox.expand(child: HtmlElementView(viewType: _viewType));
  }
}

/// The `Hls` global the vendored hls.min.js defines (web/index.html). Null when
/// the script did not load — the same undefined-maps-to-null the Twilio interop
/// (phone/voice_device_web.dart) relies on to detect a missing SDK.
@JS('Hls')
external JSObject? get _hlsGlobal;

@JS('Hls')
extension type _Hls._(JSObject _) implements JSObject {
  external factory _Hls();

  external static bool isSupported();
  external void loadSource(String url);
  external void attachMedia(JSObject media);
  external void destroy();
}
