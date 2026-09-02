/// Shared "what do we call this device" rule for the monitoring tabs.
///
/// Devices carry no real label yet — the server almost always sends
/// `label: null` — so code that fell back to `device['label'] ??
/// device['platform']` printed the platform twice ("WEB web", "WINDOWS
/// windows"). PC Specs and Desktop Monitoring both name devices; this is the
/// one rule that decides what a device is CALLED, so the two tabs cannot
/// drift into two different answers for the same device.
///
/// The platform badge (the "WEB"/"WINDOWS" chip drawn beside this name) is
/// unaffected — it stays a literal reading of `platform`. This only decides
/// the NAME that sits next to it, so a row reads "WINDOWS · Desktop app" (or
/// just "Desktop app" where there is no separate badge), never "WINDOWS
/// windows".
String friendlyDeviceName(Map device) {
  final label = device['label'];
  final platform = (device['platform'] as String?) ?? '';

  // A real custom name/hostname — but only when it says something the
  // platform badge does not already say. `label` occasionally arrives equal
  // to `platform` itself (case-insensitively), which is the exact "WEB web"
  // shape this rule exists to stop.
  if (label is String &&
      label.isNotEmpty &&
      label.toLowerCase() != platform.toLowerCase()) {
    return label;
  }

  if (isDesktopAgent(device)) return 'Desktop app';
  if (platform == 'web') return 'Browser';
  if (platform.isEmpty) return 'Device';

  return platform[0].toUpperCase() + platform.substring(1);
}

/// Whether a device is the EzBizi desktop agent — capable of reporting
/// hardware (`specs`) or being screen-viewed (`screenshot`) — as opposed to a
/// browser session, which can only ever report `activity`.
bool isDesktopAgent(Map device) {
  final capabilities = (device['capabilities'] as List<dynamic>? ?? const [])
      .cast<String>();
  return capabilities.contains('screenshot') || capabilities.contains('specs');
}
