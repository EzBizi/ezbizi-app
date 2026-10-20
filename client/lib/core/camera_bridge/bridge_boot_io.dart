import 'dart:io';

import 'package:path_provider/path_provider.dart';

import 'bridge_api.dart';
import 'bridge_store.dart';
import 'camera_bridge_service.dart';
import 'engine.dart';

/// The running bridge on this PC, if it is paired (the bridge-PC panel reads
/// its `state`).
CameraBridgeService? cameraBridge;

bool get cameraBridgeSupported => Platform.isWindows;

/// Start the bridge if this PC is paired with a camera site — at launch, and
/// again right after pairing. Independent of anybody being signed in.
Future<void> startCameraBridge() async {
  if (!Platform.isWindows || cameraBridge != null) return;
  final store = BridgeStore();
  final key = await store.siteKey();
  final exe = File(enginePathFor(Platform.resolvedExecutable));
  // A missing engine is reported, not hidden; switched off means not at all.
  if (key == null || await store.switchedOff()) return;

  final dir = await getApplicationSupportDirectory();
  final bridge = CameraBridgeService(
    api: BridgeApi(key),
    store: store,
    engine: Engine(
      exe: exe.path,
      configFile: File('${dir.path}${Platform.pathSeparator}go2rtc.yaml'),
    ),
  );
  cameraBridge = bridge;
  bridge.state.addListener(() {
    if (bridge.state.value != BridgeState.unpaired) return;
    cameraBridge = null; // pairing again starts a fresh one
  });
  await bridge.start();
}

/// The panel's on/off switch: remembered, and applied now.
Future<void> setCameraBridgeOn(bool on) async {
  await BridgeStore().setSwitchedOff(!on);
  if (on) {
    await startCameraBridge();
  } else {
    cameraBridge?.stop();
    cameraBridge = null;
  }
}
