import 'dart:async';
import 'dart:io';

import '../../core/api_client.dart';
// The io half directly: through bridge_boot.dart the analyzer sees the stub,
// which has no `cameraBridge`. This file is itself reached only on dart:io.
import '../../core/camera_bridge/bridge_boot_io.dart';
import '../../core/camera_bridge/bridge_store.dart';
import '../../core/camera_bridge/camera_bridge_service.dart';
import 'camera_bridge_panel.dart';

/// The panel's view of Monitoring's bridge on this PC. dart:io only; the
/// panel reaches it through a conditional import, so web never sees it.
BridgePanelSource realBridgeSource(ApiClient api, String? organization) =>
    _RealBridge(api, organization);

class _RealBridge extends BridgePanelSource {
  _RealBridge(this.api, this.organization) {
    _refresh();
    // The scan and the last check-in change without a state change, and
    // "last 3m ago" ages on its own: read again while the panel is open.
    _tick = Timer.periodic(const Duration(seconds: 10), (_) => _refresh());
  }

  final ApiClient api;
  final String? organization;
  final BridgeStore _store = BridgeStore();
  CameraBridgeService? _watched;
  BridgePanelStatus _status = const BridgePanelStatus();
  late final Timer _tick;
  bool _disposed = false;

  @override
  bool get supported => cameraBridgeSupported;

  @override
  BridgePanelStatus get status => _status;

  /// The installer's "Start EzBizi when I sign in to Windows" shortcut
  /// (scripts/windows-installer.iss, `{userstartup}\EzBizi`).
  static bool _startsWithWindows() {
    final appData = Platform.environment['APPDATA'];
    if (appData == null) return false;
    return File(
      '$appData'
      r'\Microsoft\Windows\Start Menu\Programs\Startup\EzBizi.lnk',
    ).existsSync();
  }

  void _watch() {
    final bridge = cameraBridge;
    if (identical(bridge, _watched)) return;
    _watched?.state.removeListener(_refresh);
    _watched = bridge;
    bridge?.state.addListener(_refresh);
  }

  Future<void> _refresh() async {
    _watch();
    final key = await _store.siteKey();
    final site = await _store.siteName();
    final off = await _store.switchedOff();
    final recorder = await _store.recorder();
    final starts = _startsWithWindows();
    final bridge = cameraBridge;
    final found = bridge?.found ?? const [];
    final paired = key != null;
    final running = paired && !off && bridge != null;
    final engineMissing = bridge?.engine.missing ?? false;
    final trouble = bridge?.state.value == BridgeState.trouble;
    if (_disposed) return;
    _status = BridgePanelStatus(
      on: !off,
      startsWithWindows: starts,
      paired: paired,
      siteName: (site ?? '').isEmpty ? null : site,
      organization: organization,
      recorderFoundAt: [
        for (final f in found)
          if (f.kind == 'recorder') f.address,
      ].firstOrNull,
      recorderHost: recorder?.host,
      passwordSaved: (recorder?.password ?? '').isNotEmpty,
      camerasFound: bridge == null
          ? null
          : found.where((f) => f.kind == 'camera').length,
      lastOk: bridge?.lastOk,
      fixes: {
        if (!off && !starts)
          BridgeLine.on:
              'EzBizi does not start with Windows on this PC. Run the EzBizi '
              'installer again and tick "Start EzBizi when I sign in to '
              'Windows".',
        if (running && engineMissing)
          BridgeLine.talking:
              'The bridge cannot relay: go2rtc.exe is missing from the engine '
              'folder beside EzBizi. Install EzBizi again.'
        else if (running && trouble)
          BridgeLine.talking:
              'EzBizi did not answer this PC. Check its internet connection; '
              'the bridge keeps trying.',
      },
    );
    notifyListeners();
  }

  @override
  Future<String?> pair(String code) async {
    final error = await CameraBridgeService.pair(api, _store, code);
    if (error == null) await startCameraBridge(); // stays off if switched off
    await _refresh();
    return error;
  }

  @override
  Future<void> saveRecorder({
    required String host,
    required String username,
    required String password,
  }) async {
    await _store.saveRecorder(
      Recorder(host: host, username: username, password: password),
    );
    await _refresh();
  }

  @override
  Future<void> setOn(bool on) async {
    await setCameraBridgeOn(on);
    await _refresh();
  }

  @override
  void dispose() {
    _disposed = true;
    _tick.cancel();
    _watched?.state.removeListener(_refresh);
    super.dispose();
  }
}
