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

/// Switched off from the panel. Until the app restarts: Monitoring's bridge
/// has no stored on/off, and startCameraBridge() runs at every launch.
bool _switchedOff = false;

class _RealBridge extends BridgePanelSource {
  _RealBridge(this.api, this.organization) {
    _refresh();
  }

  final ApiClient api;
  final String? organization;
  final BridgeStore _store = BridgeStore();
  CameraBridgeService? _watched;
  BridgePanelStatus _status = const BridgePanelStatus();
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

  static bool _engineInstalled() => File(
    '${File(Platform.resolvedExecutable).parent.path}'
    '${Platform.pathSeparator}go2rtc.exe',
  ).existsSync();

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
    final recorder = await _store.recorder();
    final starts = _startsWithWindows();
    final bridge = cameraBridge;
    final paired = key != null;
    final trouble = bridge?.state.value == BridgeState.trouble;
    if (_disposed) return;
    _status = BridgePanelStatus(
      on: !_switchedOff,
      startsWithWindows: starts,
      paired: paired,
      organization: organization,
      recorderHost: recorder?.host,
      passwordSaved: (recorder?.password ?? '').isNotEmpty,
      fixes: {
        if (!_switchedOff && !starts)
          BridgeLine.on:
              'EzBizi does not start with Windows on this PC. Run the EzBizi '
              'installer again and tick "Start EzBizi when I sign in to '
              'Windows".',
        if (paired && !_switchedOff && bridge == null && !_engineInstalled())
          BridgeLine.talking:
              'The bridge cannot start: go2rtc.exe is missing beside EzBizi. '
              'Install EzBizi again.',
        if (paired && !_switchedOff && trouble)
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
    if (error == null) {
      _switchedOff = false;
      await startCameraBridge();
    }
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
    _switchedOff = !on;
    final bridge = cameraBridge;
    if (!on) {
      bridge?.stop();
    } else if (bridge != null) {
      await bridge.start();
    } else {
      await startCameraBridge();
    }
    await _refresh();
  }

  @override
  void dispose() {
    _disposed = true;
    _watched?.state.removeListener(_refresh);
    super.dispose();
  }
}
