import 'dart:async';
import 'dart:js_interop';
import 'dart:typed_data';

import 'package:web/web.dart' as web;

import 'files.dart';

void download(String filename, List<int> content) {
  final blob = web.Blob(
    [Uint8List.fromList(content).toJS].toJS,
    web.BlobPropertyBag(type: 'text/csv;charset=utf-8'),
  );

  final url = web.URL.createObjectURL(blob);
  final anchor = web.document.createElement('a') as web.HTMLAnchorElement
    ..href = url
    ..download = filename;

  anchor.click();

  // Without this the blob is held for the life of the page, and a few exports
  // of a large customer list add up to real memory.
  web.URL.revokeObjectURL(url);
}

Future<PickedFile?> pickText() {
  final completer = Completer<PickedFile?>();

  final input = web.document.createElement('input') as web.HTMLInputElement
    ..type = 'file'
    ..accept = '.csv,text/csv,text/plain';

  input.onchange = (web.Event _) {
    final file = input.files?.item(0);

    if (file == null) {
      completer.complete(null);
      return;
    }

    final reader = web.FileReader();

    reader.onload = (web.Event _) {
      completer.complete(PickedFile(file.name, (reader.result as JSString?)?.toDart ?? ''));
    }.toJS;

    reader.onerror = (web.Event _) {
      completer.complete(null);
    }.toJS;

    reader.readAsText(file);
  }.toJS;

  // Cancelling the OS dialog fires no event at all, so this future would hang
  // forever and the button would stay spinning. `cancel` is the modern signal;
  // the window focus fallback covers browsers that do not send it yet.
  input.oncancel = (web.Event _) {
    if (!completer.isCompleted) completer.complete(null);
  }.toJS;

  input.click();

  return completer.future;
}

/// Drop the query string without reloading.
///
/// Used after a password reset: the token is single-use and already spent, but
/// leaving `?token=` in the address bar keeps it in history and in anything the
/// user copies out of the URL bar.
void clearQuery() {
  try {
    web.window.history.replaceState(null, '', web.window.location.pathname);
  } catch (_) {
    // Some embedded webviews refuse history manipulation. A stale query string
    // is untidy; a crash on the success screen is worse.
  }
}

/// Ask for an image and return its bytes.
///
/// Separate from pickText because a photo read as text is corrupted the moment
/// it touches a String — the bytes have to stay bytes the whole way.
Future<PickedBytes?> pickImage() {
  final completer = Completer<PickedBytes?>();

  final input = web.document.createElement('input') as web.HTMLInputElement
    ..type = 'file'
    ..accept = 'image/jpeg,image/png,image/webp';

  input.onchange = (web.Event _) {
    final file = input.files?.item(0);

    if (file == null) {
      completer.complete(null);
      return;
    }

    final reader = web.FileReader();

    reader.onload = (web.Event _) {
      final buffer = reader.result as JSArrayBuffer?;

      completer.complete(buffer == null
          ? null
          : PickedBytes(file.name, buffer.toDart.asUint8List()));
    }.toJS;

    reader.onerror = (web.Event _) {
      completer.complete(null);
    }.toJS;

    reader.readAsArrayBuffer(file);
  }.toJS;

  input.oncancel = (web.Event _) {
    if (!completer.isCompleted) completer.complete(null);
  }.toJS;

  input.click();

  return completer.future;
}
