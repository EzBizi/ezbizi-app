import 'package:flutter/material.dart';

import '../../core/theme.dart';

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

/// A device silent longer than this draws as offline. Independent of the
/// server's own `monitoring.offline_after_seconds` (DeviceOfflineWorker) —
/// that setting decides when `monitoring.device_offline` FIRES, a much
/// heavier consequence than a dot's colour, so the two are free to differ.
const Duration deviceOnlineWithin = Duration(minutes: 5);

bool isDeviceOnline(Map<String, dynamic> device) {
  final seen = DateTime.tryParse(device['last_seen_at'] as String? ?? '');
  if (seen == null) return false;
  return DateTime.now().toUtc().difference(seen.toUtc()) <= deviceOnlineWithin;
}

/// "just now" / "3m ago" / "2h ago" / "yesterday" / "5d ago" — the one
/// relative-time shape the monitoring tabs use (PC Specs' `seenAgo` wraps it
/// for a raw `last_seen_at` string).
String relativeAge(DateTime at) {
  final ago = DateTime.now().difference(at.toLocal());
  if (ago.isNegative || ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return '${ago.inMinutes}m ago';
  if (ago.inHours < 24) return '${ago.inHours}h ago';
  if (ago.inHours < 48) return 'yesterday';
  return '${ago.inDays}d ago';
}

/// One person and the devices they are signed in from, in the order the
/// server returned them (most recently seen first — see
/// DevicesController::index).
class MonitoringPerson {
  MonitoringPerson(this.id, this.name);

  final String id;
  final String name;
  final List<Map<String, dynamic>> devices = [];
}

List<MonitoringPerson> groupByPerson(List<Map<String, dynamic>> devices) {
  final byId = <String, MonitoringPerson>{};
  final order = <String>[];

  for (final device in devices) {
    final user = device['user'] as Map<String, dynamic>? ?? const {};
    final id = user['id'] as String? ?? 'unknown';
    final name = user['name'] as String? ?? 'Unknown';

    final person = byId.putIfAbsent(id, () {
      order.add(id);
      return MonitoringPerson(id, name);
    });
    person.devices.add(device);
  }

  return [for (final id in order) byId[id]!];
}

/// The most recently seen of [devices] — max by `last_seen_at` — or null for
/// an empty list. A missing or unparseable stamp sorts as the oldest
/// possible, the same treatment `seenAgo` gives it ("never").
Map<String, dynamic>? mostRecentlySeen(List<Map<String, dynamic>> devices) {
  if (devices.isEmpty) return null;

  var best = devices.first;
  var bestSeen = DateTime.tryParse(best['last_seen_at'] as String? ?? '') ??
      DateTime.fromMillisecondsSinceEpoch(0);

  for (final device in devices.skip(1)) {
    final seen = DateTime.tryParse(device['last_seen_at'] as String? ?? '') ??
        DateTime.fromMillisecondsSinceEpoch(0);
    if (seen.isAfter(bestSeen)) {
      best = device;
      bestSeen = seen;
    }
  }

  return best;
}

class StatusDot extends StatelessWidget {
  const StatusDot({super.key, required this.online});

  final bool online;

  @override
  Widget build(BuildContext context) {
    // A bolt and a struck-through bolt, not two coloured circles: colour was
    // the ONLY thing separating online from offline here, which fails anyone
    // who cannot tell this green from this grey, and fails everyone in a
    // screenshot pasted into a ticket. The shape now carries the fact and the
    // colour reinforces it.
    return Icon(
      online ? Icons.flash_on : Icons.flash_off,
      size: 13,
      color: online
          ? EzBiziTheme.clockIn(context)
          : Theme.of(context).colorScheme.outlineVariant,
      semanticLabel: online ? 'Online' : 'Offline',
    );
  }
}
