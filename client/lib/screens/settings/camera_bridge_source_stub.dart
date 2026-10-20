import '../../core/api_client.dart';
import 'camera_bridge_panel.dart';

/// Web (and anywhere without dart:io): no camera bridge, so no panel.
BridgePanelSource realBridgeSource(ApiClient api, String? organization) =>
    _NoBridge();

class _NoBridge extends BridgePanelSource {
  @override
  bool get supported => false;

  @override
  BridgePanelStatus get status => const BridgePanelStatus();

  @override
  Future<String?> pair(String code) async => 'No camera bridge here.';

  @override
  Future<void> saveRecorder({
    required String host,
    required String username,
    required String password,
  }) async {}

  @override
  Future<void> setOn(bool on) async {}
}
