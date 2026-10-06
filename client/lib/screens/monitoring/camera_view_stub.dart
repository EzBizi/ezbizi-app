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
Widget buildCameraImage(String url, {ByteStreamOpener? open}) =>
    _MjpegView(url: url, open: open);

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

class _MjpegView extends StatefulWidget {
  const _MjpegView({required this.url, this.open});

  final String url;
  final ByteStreamOpener? open;

  @override
  State<_MjpegView> createState() => _MjpegViewState();
}

class _MjpegViewState extends State<_MjpegView> {
  Uint8List? _frame;
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
    if (!_disposed && mounted) {
      setState(() {
        _frame = frames.last;
        _error = false;
      });
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

    final child = _frame != null
        ? Image.memory(
            _frame!,
            gaplessPlayback: true, // no flash between frames
            fit: BoxFit.cover,
            width: double.infinity,
            height: double.infinity,
            // A corrupt frame must not crash the tile; show the offline mark
            // and let the next frame recover.
            errorBuilder: (_, _, _) => Center(
              child: Icon(Icons.videocam_off_outlined,
                  color: scheme.onSurfaceVariant),
            ),
          )
        : Center(
            child: _error
                ? Icon(Icons.videocam_off_outlined,
                    color: scheme.onSurfaceVariant)
                : const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
          );

    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: ColoredBox(color: scheme.surfaceContainerHighest, child: child),
    );
  }
}
