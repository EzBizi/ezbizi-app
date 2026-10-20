import 'dart:async';
import 'dart:js_interop';
import 'dart:typed_data';

import 'package:flutter/foundation.dart'
    show TargetPlatform, defaultTargetPlatform;
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

  _openAttached(input, completer.future);

  return completer.future;
}

/// Click a file input that is part of the page, and take it out again once the
/// pick is over.
///
/// iPhone Safari loses a detached input: the element is collected while the
/// photo picker is open and `change` never fires, so the person picks a photo
/// and nothing happens (Gino's driver licence, 2026-10-01: no upload ever
/// reached the server). Attached and hidden, it lives until the answer comes.
void _openAttached(web.HTMLInputElement input, Future<Object?> done) {
  input.style.display = 'none';
  web.document.body?.append(input);
  // A closure, not `input.remove`: dart2js refuses a tear-off of a JS
  // interop member ("Tear-offs of external extension type interop member
  // 'remove' are disallowed"), and only the web build says so. Analyze passes.
  done.whenComplete(() => input.remove());
  input.click();
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
Future<PickedBytes?> pickImage() =>
    pickBytes(accept: 'image/jpeg,image/png,image/webp');

/// A phone's browser opens the camera for `<input capture>`; a desktop one
/// ignores the attribute and shows its file picker, which is not "take a
/// photo", so it is not offered there.
bool get canTakePhoto =>
    defaultTargetPlatform == TargetPlatform.android ||
    defaultTargetPlatform == TargetPlatform.iOS;

/// The rear camera, through the browser's own file input.
Future<PickedBytes?> takePhoto() =>
    pickBytes(accept: 'image/*', capture: 'environment');

/// Any file, as bytes. [accept] is the input's accept list, or empty for all.
Future<PickedBytes?> pickBytes({String accept = '', String capture = ''}) {
  final completer = Completer<PickedBytes?>();

  final input = web.document.createElement('input') as web.HTMLInputElement
    ..type = 'file';
  if (accept.isNotEmpty) input.accept = accept;
  if (capture.isNotEmpty) input.capture = capture;

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

  _openAttached(input, completer.future);

  return completer.future;
}

/// An object URL over bytes we already hold, so the browser can play or show
/// them without an upload. See `Files.previewUrl`.
String? previewUrl(List<int> bytes, String mime) {
  final blob = web.Blob(
    [Uint8List.fromList(bytes).toJS].toJS,
    web.BlobPropertyBag(type: mime),
  );

  // NOT revoked here, unlike `download` above: that one is finished with the
  // URL the instant the click happens, whereas a <video> element needs it to
  // stay valid for as long as it is on screen. The caller releases it.
  return web.URL.createObjectURL(blob);
}

void releasePreviewUrl(String url) => web.URL.revokeObjectURL(url);
