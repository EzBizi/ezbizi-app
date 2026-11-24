import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:window_manager/window_manager.dart';

import 'token_store.dart';

/// See app_window.dart. The second copy is told where to open, and which copy
/// is the first, through its environment: Flutter's macOS embedder does not
/// pass command-line arguments to Dart, and the variables reach the Windows
/// runner too, which skips the single-instance hand-off and the tray for it.
const _where = 'EZBIZI_WINDOW';
const _first = 'EZBIZI_PARENT';

/// The session, handed over in memory: a sign-in without "keep me signed in"
/// is on no disk for the new copy to read (token_store_io.dart takes it).
/// Only this user's own processes can read another's environment.
const handedToken = 'EZBIZI_TOKEN';

bool get _desktop => Platform.isWindows || Platform.isMacOS || Platform.isLinux;

Uri? start() {
  if (!_desktop) return null;
  final path = Platform.environment[_where];
  return path == null || path.isEmpty ? null : Uri.tryParse(path);
}

bool get canOpen => _desktop;

Future<void> open(String path) async {
  await Process.start(
    Platform.resolvedExecutable,
    const [],
    environment: {
      _where: path,
      // A window opened from a second window still belongs to the first.
      _first: Platform.environment[_first] ?? '$pid',
      handedToken: ?TokenStore.tenantToken,
    },
    mode: ProcessStartMode.detached,
  );
}

void watchFirst() {
  // The tray's "New window" (windows/runner): the first window opens one.
  if (Platform.isWindows && start() == null) {
    const MethodChannel('com.ezbizi.app/window').setMethodCallHandler((
      call,
    ) async {
      if (call.method == 'newWindow') await open('/');
    });
  }
  final first = int.tryParse(Platform.environment[_first] ?? '');
  // Windows: the runner waits on the first process and closes this one.
  if (first == null || start() == null || Platform.isWindows) return;
  // SIGCONT does nothing to a running process; it only asks "still there?".
  // Measured once first: a sandbox that refuses the signal must not read as
  // "the first window is gone", so the watch only acts on alive -> gone.
  // ponytail: a 3 s poll; a kqueue wait if anybody notices the delay.
  bool there() => Process.killPid(first, ProcessSignal.sigcont);
  if (!there()) return;
  Timer.periodic(const Duration(seconds: 3), (_) {
    if (!there()) exit(0);
  });
}

Future<void> setTitle(String title) async {
  if (!_desktop) return;
  try {
    await windowManager.setTitle(title);
  } catch (_) {
    // A title is not worth a crash; tests have no window.
  }
}
