import 'package:flutter/material.dart';

/// Never actually reached. `CameraImageView` (camera_view.dart) is only ever
/// built by `_InlineViewCard` behind a `kIsWeb` check — non-web platforms
/// render their own "open in browser" card instead and never construct this
/// widget at all. This stub exists purely so the conditional import in
/// camera_view.dart has something to resolve to on non-web targets, the same
/// way message_html_stub.dart exists for `MessageHtmlView`.
Widget buildCameraImage(String url) => const SizedBox.shrink();

/// Never reached, for the same reason [buildCameraImage] is not: the tab only
/// builds the HLS video host behind a `kIsWeb` check. Present so the
/// conditional import resolves off-web.
Widget buildCameraHls(String url) => const SizedBox.shrink();

/// Off-web there is no `<video>` and no hls.js, so inline HLS is never
/// available — the tab keeps the deep-link card. (Desktop already routed HLS
/// there anyway.)
bool hlsInlineSupported() => false;
