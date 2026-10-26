import 'dart:async';
import 'dart:convert';
import 'dart:io';

import '../monitoring_heartbeat.dart';

/// What the machine is made of, read from Windows (WMI) every few hours and
/// carried in specs as `hardware` (Jose, 2026-10-26: "add whatever
/// collection of data you need … as well as any other you think could be
/// useful to have in the future"). Motherboard and RAM decide what RGB can
/// light; the rest is asset tracking: serials, warranty, disk and battery
/// health, monitors, peripherals.
///
/// ponytail: one PowerShell per 6 h (~2 s); move to C++ WMI in
/// monitoring_specs.cpp if spawning PowerShell ever shows up in profiles.
class HardwareInventory {
  static Timer? _timer;

  static void start() {
    if (!Platform.isWindows || _timer != null) return;
    unawaited(read());
    _timer = Timer.periodic(const Duration(hours: 6), (_) => unawaited(read()));
  }

  static Future<void> read() async {
    try {
      final r = await Process.run('powershell.exe', [
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-Command',
        script,
      ]).timeout(const Duration(seconds: 60));
      final out = (r.stdout as String).trim();
      if (r.exitCode != 0 || out.isEmpty) return;
      MonitoringHeartbeat.extraSpecs['hardware'] = jsonDecode(out);
    } catch (_) {
      // Leave the last good read in place.
    }
  }

  /// One JSON object. Every query is wrapped so one missing WMI class (a
  /// desktop has no battery) never loses the rest.
  static const script = r'''
$ErrorActionPreference = 'SilentlyContinue'
function Q($c, $ns = 'root/cimv2') { @(Get-CimInstance -ClassName $c -Namespace $ns) }
function S($b) { if ($b) { ([System.Text.Encoding]::ASCII.GetString(($b | Where-Object { $_ -ne 0 }))).Trim() } }
$bb = Q Win32_BaseBoard | Select-Object -First 1
$bios = Q Win32_BIOS | Select-Object -First 1
$cs = Q Win32_ComputerSystem | Select-Object -First 1
$os = Q Win32_OperatingSystem | Select-Object -First 1
$enc = Q Win32_SystemEnclosure | Select-Object -First 1
$full = Q BatteryFullChargedCapacity 'root/wmi' | Select-Object -First 1
$design = Q BatteryStaticData 'root/wmi' | Select-Object -First 1
[ordered]@{
  read_at = (Get-Date).ToUniversalTime().ToString('o')
  motherboard = @{ maker = $bb.Manufacturer; model = $bb.Product; version = $bb.Version }
  bios = @{ maker = $bios.Manufacturer; version = $bios.SMBIOSBIOSVersion; date = "$($bios.ReleaseDate)" }
  system = @{ maker = $cs.Manufacturer; model = $cs.Model; serial = $bios.SerialNumber; chassis = @($enc.ChassisTypes) }
  os = @{ name = $os.Caption; build = $os.BuildNumber; installed = "$($os.InstallDate)"; last_boot = "$($os.LastBootUpTime)" }
  ram_modules = @(Q Win32_PhysicalMemory | ForEach-Object { @{ maker = $_.Manufacturer; part = "$($_.PartNumber)".Trim(); gb = [math]::Round($_.Capacity / 1GB); mhz = $_.ConfiguredClockSpeed; slot = $_.DeviceLocator } })
  gpus = @(Q Win32_VideoController | ForEach-Object { @{ name = $_.Name; driver = $_.DriverVersion; driver_date = "$($_.DriverDate)" } })
  disks = @(Get-PhysicalDisk | ForEach-Object { @{ model = $_.FriendlyName; type = "$($_.MediaType)"; gb = [math]::Round($_.Size / 1GB); health = "$($_.HealthStatus)"; serial = "$($_.SerialNumber)".Trim() } })
  monitors = @(Q WmiMonitorID 'root/wmi' | ForEach-Object { @{ maker = S $_.ManufacturerName; model = S $_.UserFriendlyName; serial = S $_.SerialNumberID; year = $_.YearOfManufacture } })
  network = @(Q Win32_NetworkAdapter | Where-Object { $_.PhysicalAdapter } | ForEach-Object { @{ name = $_.Name; mac = $_.MACAddress; mbps = if ($_.Speed) { [math]::Round($_.Speed / 1e6) } else { $null } } })
  battery = if ($design) { @{ design_mwh = $design.DesignedCapacity; full_mwh = $full.FullChargedCapacity; health_pct = if ($design.DesignedCapacity) { [math]::Round(100 * $full.FullChargedCapacity / $design.DesignedCapacity) } else { $null } } } else { $null }
  keyboards = @(Q Win32_Keyboard | ForEach-Object { $_.Description } | Select-Object -Unique)
  mice = @(Q Win32_PointingDevice | ForEach-Object { $_.Name } | Select-Object -Unique)
  printers = @(Q Win32_Printer | Where-Object { -not $_.Network } | ForEach-Object { $_.Name })
  usb_devices = @(Get-PnpDevice -PresentOnly -Class 'HIDClass','USB','Image','Camera','AudioEndpoint' | ForEach-Object { $_.FriendlyName } | Select-Object -Unique | Select-Object -First 60)
} | ConvertTo-Json -Depth 5 -Compress
''';
}
