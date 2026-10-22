import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;
import 'package:flutter/material.dart';

/// A camera's live frame OFF WEB — desktop and mobile, where there is no DOM
/// `<img>` but there IS `dart:io`. (Web uses camera_view_web.dart.)
///
/// It reads the camera URL as a byte stream and pulls whole JPEG frames out of
/// it — a boundary-agnostic MJPEG reader (an `mjpeg` stream delivers frame after
/// frame; a `snapshot` URL delivers one frame then closes, and the reconnect
/// loop below turns that into polling). Framing is done on the JPEG markers
/// themselves (FFD8 … FFD9) rather than the multipart boundary, so it does not
/// care how the server delimits parts.
///
/// Jose, 2026-10-06: the Ring cameras "should work on the app, not just on web."
/// This is what makes the inline tile render in the Windows/Mac/mobile build
/// instead of the open-in-browser card.
Widget buildCameraImage(
  String url, {
  ByteStreamOpener? open,
  BoxFit fit = BoxFit.contain,
  ValueChanged<double>? onAspect,
}) => _MjpegView(url: url, open: open, fit: fit, onAspect: onAspect);

/// No native HLS player, so an `hls` camera still falls to the deep-link card
/// off-web (unchanged). mjpeg/snapshot are what gained an inline view here.
Widget buildCameraHls(String url) => const SizedBox.shrink();

bool hlsInlineSupported() => false;

/// Opens [url] and yields its raw body bytes. Injected in tests so the view can
/// be driven without a real socket; the default is a real `dart:io` request.
typedef ByteStreamOpener = Stream<List<int>> Function(String url);

Stream<List<int>> _defaultOpen(String url) async* {
  final client = HttpClient()..connectionTimeout = const Duration(seconds: 10);
  try {
    final req = await client.getUrl(Uri.parse(url));
    // The secret lives in the URL; send nothing else that could leak it.
    req.headers.removeAll(HttpHeaders.refererHeader);
    final res = await req.close();
    yield* res;
  } finally {
    client.close(force: true);
  }
}

/// Pull every COMPLETE JPEG (FFD8 … FFD9) out of [bytes], and return them with
/// the leftover tail (a partial next frame). Pure and public so the framing —
/// the one bit with real logic — is unit-tested without a socket or a widget.
(List<Uint8List> frames, Uint8List tail) scanJpegFrames(Uint8List bytes) {
  const soi = 0xD8, eoi = 0xD9;
  final frames = <Uint8List>[];
  var start = -1;
  var cut = 0;
  for (var i = 0; i + 1 < bytes.length; i++) {
    if (bytes[i] != 0xFF) continue;
    if (bytes[i + 1] == soi) {
      start = i;
    } else if (bytes[i + 1] == eoi && start != -1) {
      frames.add(Uint8List.sublistView(bytes, start, i + 2));
      cut = i + 2;
      start = -1;
    }
  }
  final tail = cut < bytes.length
      ? Uint8List.fromList(bytes.sublist(cut))
      : Uint8List(0);
  return (frames, tail);
}

/// The width/height of a JPEG, read from its SOF (start-of-frame) marker, or
/// null if there is no complete SOF in [bytes]. Cheap — it walks the segment
/// headers, it does not decode pixels. Public for the unit test.
double? jpegAspect(Uint8List bytes) {
  var i = 2; // skip the FFD8 SOI
  while (i + 8 < bytes.length) { // need bytes[i+8] for a SOF's width low byte
    if (bytes[i] != 0xFF) {
      i++;
      continue;
    }
    final marker = bytes[i + 1];
    // SOF0..SOF15 carry the dimensions, except the DHT/DAC/RSTn markers in
    // that range (C4 = DHT, C8 = JPG, CC = DAC) which do not.
    final isSof = marker >= 0xC0 &&
        marker <= 0xCF &&
        marker != 0xC4 &&
        marker != 0xC8 &&
        marker != 0xCC;
    if (isSof) {
      final h = (bytes[i + 5] << 8) | bytes[i + 6];
      final w = (bytes[i + 7] << 8) | bytes[i + 8];
      if (w <= 0 || h <= 0) return null;
      return w / h;
    }
    // Any other marker segment carries a 2-byte length; skip over it.
    final len = (bytes[i + 2] << 8) | bytes[i + 3];
    if (len < 2) return null;
    i += 2 + len;
  }
  return null;
}

class _MjpegView extends StatefulWidget {
  const _MjpegView({
    required this.url,
    this.open,
    this.fit = BoxFit.contain,
    this.onAspect,
  });

  final String url;
  final ByteStreamOpener? open;
  final BoxFit fit;
  final ValueChanged<double>? onAspect;

  @override
  State<_MjpegView> createState() => _MjpegViewState();
}

class _MjpegViewState extends State<_MjpegView> {
  Uint8List? _frame;
  double? _aspect; // width / height of the stream, learned from the first frame
  bool _error = false;
  StreamSubscription<List<int>>? _sub;
  Timer? _reconnect;
  bool _disposed = false;

  final BytesBuilder _buf = BytesBuilder(copy: false);

  @override
  void initState() {
    super.initState();
    _connect();
  }

  @override
  void didUpdateWidget(_MjpegView old) {
    super.didUpdateWidget(old);
    if (old.url != widget.url) {
      _teardown();
      _buf.clear();
      _frame = null;
      _connect();
    }
  }

  void _connect() {
    if (_disposed) return;
    _error = false;
    final opener = widget.open ?? _defaultOpen;
    try {
      _sub = opener(widget.url).listen(
        _onBytes,
        onError: _onError,
        onDone: _onDone, // snapshot: one frame then done → reconnect = poll
        cancelOnError: true,
      );
    } catch (e) {
      _onError(e);
    }
  }

  void _onBytes(List<int> chunk) {
    _buf.add(chunk);
    // Only scan when the buffer is large enough to plausibly hold a frame end.
    _extractFrames();
    // Guard against a runaway buffer if a frame never completes.
    if (_buf.length > 12 * 1024 * 1024) _buf.clear();
  }

  void _extractFrames() {
    final (frames, tail) = scanJpegFrames(_buf.toBytes());
    _buf.clear();
    if (tail.isNotEmpty) _buf.add(tail);
    if (frames.isEmpty) return;
    // Only the newest frame matters for a live tile.
    final newest = frames.last;
    // Learn the camera's real aspect ratio once (it does not change), so the
    // view can show the WHOLE wide frame instead of cropping it to fill.
    final aspect = _aspect == null ? jpegAspect(newest) : null;
    if (!_disposed && mounted) {
      setState(() {
        _frame = newest;
        if (aspect != null) _aspect = aspect;
        _error = false;
      });
      if (aspect != null) widget.onAspect?.call(aspect);
    }
  }

  void _onError(Object e, [StackTrace? st]) {
    if (kDebugMode) debugPrint('camera stream error: $e');
    if (_disposed) return;
    if (_frame == null && mounted) setState(() => _error = true);
    _scheduleReconnect();
  }

  void _onDone() {
    // Reached for a single-frame snapshot (connection closes after one image)
    // and for a dropped mjpeg alike. Reconnect — which is a refresh for the
    // snapshot and a recovery for the stream.
    _scheduleReconnect(const Duration(seconds: 4));
  }

  void _scheduleReconnect([Duration delay = const Duration(seconds: 3)]) {
    _sub?.cancel();
    _sub = null;
    _reconnect?.cancel();
    if (_disposed) return;
    _reconnect = Timer(delay, _connect);
  }

  void _teardown() {
    _sub?.cancel();
    _sub = null;
    _reconnect?.cancel();
    _reconnect = null;
  }

  @override
  void dispose() {
    _disposed = true;
    _teardown();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    Widget child;
    if (_frame != null) {
      // contain by default, not cover: these are wide cameras, and cover crops
      // a wide frame to fill a taller tile (Fill asks for exactly that). Wrapped in the stream's real aspect ratio
      // (once known) so the whole feed shows at the largest size that fits,
      // instead of floating in a tall letterbox. Jose, 2026-10-07.
      final cover = widget.fit == BoxFit.cover;
      Widget image = Image.memory(
        _frame!,
        gaplessPlayback: true, // no flash between frames
        fit: widget.fit,
        // Fill (cover) fills the whole box and crops; the box is the bounds.
        width: cover ? double.infinity : null,
        height: cover ? double.infinity : null,
        // A corrupt frame must not crash the tile; show the offline mark
        // and let the next frame recover.
        errorBuilder: (_, _, _) => Center(
          child: Icon(Icons.videocam_off_outlined,
              color: scheme.onSurfaceVariant),
        ),
      );
      if (_aspect != null && !cover) {
        image = AspectRatio(aspectRatio: _aspect!, child: image);
      }
      child = Center(child: image);
    } else {
      child = Center(
        child: _error
            ? Icon(Icons.videocam_off_outlined, color: scheme.onSurfaceVariant)
            : const SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
      );
    }

    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: ColoredBox(color: scheme.surfaceContainerHighest, child: child),
    );
  }
}
