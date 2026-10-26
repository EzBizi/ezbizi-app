import 'dart:io';

import '../api_client.dart';
import 'hardware_inventory.dart';
import 'lighting_service.dart';

/// Start with the heartbeat (signed in); both are no-ops off Windows.
void startLighting(ApiClient api) {
  // flutter test runs on this same Windows box: never spawn PowerShell or
  // OpenRGB from a widget test of the shell.
  if (Platform.environment.containsKey('FLUTTER_TEST')) return;
  HardwareInventory.start();
  LightingService.start(api);
}

void stopLighting() => LightingService.stop();
