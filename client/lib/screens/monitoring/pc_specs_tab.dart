import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';

/// PC Specs — the device roster (people, each expandable inline to their
/// devices — no modal) plus an at-a-glance panel.
///
/// ── The rule this tab exists to keep honest ────────────────────────
///
/// A device shows only what it CAN report. `capabilities` is the device's own
/// declaration (HeartbeatController), not something this tab infers from the
/// platform string — a web session declares `['activity']` because a browser
/// genuinely has no way to read the machine underneath it, and that row says
/// so out loud ([webOnlyNote]) rather than leaving a blank that reads like a
/// bug. An agent device declares `specs` in its capabilities, and gets the
/// real numbers back — UNLESS the caller lacks `monitoring.specs.view`, in
/// which case `DevicesController::present` withholds the `specs` KEY
/// entirely (present-and-null would leak that a spec exists to be granted).
/// Both silences are told apart below: a missing capability draws
/// [webOnlyNote], a missing key on a capable device draws a different
/// sentence naming the permission — the client renders whichever the server
/// actually sent, it never assumes.
class PcSpecsTab extends StatefulWidget {
  const PcSpecsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Below this the glance panel drops under the roster instead of sitting
  /// beside it (spec: "Panel stacks below the roster below 720px").
  static const double splitAbove = 720;

  static const double panelWidth = 320;

  /// A device silent longer than this draws as offline. Independent of the
  /// server's own `monitoring.offline_after_seconds` (DeviceOfflineWorker) —
  /// that setting decides when `monitoring.device_offline` FIRES, a much
  /// heavier consequence than a dot's colour, so the two are free to differ.
  static const Duration onlineWithin = Duration(minutes: 5);

  /// The exact sentence a capability without `specs` draws. A literal, not
  /// composed per-platform: the layout the owner chose (docs/previews/
  /// monitoring-layouts.html, "Chosen") uses this one sentence regardless of
  /// whether the session is a browser tab or a phone's browser view, and a
  /// client test asserts this string verbatim — see monitoring_screen_test.dart.
  static const String webOnlyNote =
      'Web session — no specs or screenshots available from a browser.';

  static const String specsWithheldNote =
      'This device can report specs, but your role does not include '
      'monitoring.specs.view.';

  @override
  State<PcSpecsTab> createState() => _PcSpecsTabState();
}

class _PcSpecsTabState extends State<PcSpecsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _devices = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // A spinner only when there is nothing to keep — see AsyncBody.
    setState(() {
      _loading = _devices.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/monitoring/devices');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _devices = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    });
  }

  bool _isOnline(Map<String, dynamic> device) {
    final seenRaw = device['last_seen_at'] as String?;
    if (seenRaw == null) return false;
    final seen = DateTime.tryParse(seenRaw);
    if (seen == null) return false;
    return DateTime.now().toUtc().difference(seen.toUtc()) <=
        PcSpecsTab.onlineWithin;
  }

  @override
  Widget build(BuildContext context) {
    final people = _groupByPerson(_devices);

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final wide = constraints.maxWidth >= PcSpecsTab.splitAbove;
          final roster = _Roster(
            people: people,
            isOnline: _isOnline,
            session: widget.session,
          );
          final panel = _GlancePanel(people: people, isOnline: _isOnline);

          if (!wide) {
            return SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [roster, const SizedBox(height: 16), panel],
              ),
            );
          }

          return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: roster),
                const SizedBox(width: 16),
                SizedBox(width: PcSpecsTab.panelWidth, child: panel),
              ],
            ),
          );
        },
      ),
    );
  }
}

/// One person and the devices they are signed in from, in the order the
/// server returned them (most recently seen first).
class _Person {
  _Person(this.id, this.name);

  final String id;
  final String name;
  final List<Map<String, dynamic>> devices = [];
}

List<_Person> _groupByPerson(List<Map<String, dynamic>> devices) {
  final byId = <String, _Person>{};
  final order = <String>[];

  for (final device in devices) {
    final user = device['user'] as Map<String, dynamic>? ?? const {};
    final id = user['id'] as String? ?? 'unknown';
    final name = user['name'] as String? ?? 'Unknown';

    final person = byId.putIfAbsent(id, () {
      order.add(id);
      return _Person(id, name);
    });
    person.devices.add(device);
  }

  return [for (final id in order) byId[id]!];
}

bool _declaresSpecs(Map<String, dynamic> device) =>
    (device['capabilities'] as List<dynamic>? ?? const [])
        .cast<String>()
        .contains('specs');

/// "just now" / "3m ago" / "2h ago" / "yesterday" / "5d ago" for a
/// `last_seen_at` stamp — never the raw ISO-8601 string a device row used to
/// show verbatim. `null` (and anything unparseable) reads as "never", the
/// same word this app already uses for "has not happened yet" elsewhere.
///
/// No shared "ago" helper exists to import: leads_screen.dart and
/// profile_screen.dart each carry their own private formatter, slightly
/// different from one another, and core/timestamps.dart's [formatStamp] is
/// deliberately NOT this — its own doc comment says a wrong "2 hours ago" is
/// worse than an exact stamp, which is the opposite tradeoff a glance-only
/// roster wants. So this stays local rather than reaching for either.
String _seenAgo(String? iso) {
  if (iso == null) return 'never';

  final at = DateTime.tryParse(iso)?.toLocal();
  if (at == null) return 'never';

  final ago = DateTime.now().difference(at);
  if (ago.isNegative || ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return '${ago.inMinutes}m ago';
  if (ago.inHours < 24) return '${ago.inHours}h ago';
  if (ago.inHours < 48) return 'yesterday';
  return '${ago.inDays}d ago';
}

/// The module's human label, read from the session's own catalogue — the
/// same `Me.module` lookup the sidebar uses (session.dart), so `jobs` reads
/// as whatever the server actually called it rather than as its wire key.
///
/// Falls back to a title-cased rendering of the key itself only when the
/// session's module list does not carry it (an older server, or a module
/// this person cannot reach), so the roster never shows a raw snake_case
/// key either way.
String _moduleLabel(Session session, String key) {
  final label = session.me?.module(key)?.label;
  if (label != null && label.isNotEmpty) return label;

  return key
      .split('_')
      .where((word) => word.isNotEmpty)
      .map((word) => word[0].toUpperCase() + word.substring(1))
      .join(' ');
}

num? _diskFreeGb(Map<String, dynamic> device) {
  final specs = device['specs'];
  if (specs is! Map<String, dynamic>) return null;
  final value = specs['disk_free_gb'];
  return value is num ? value : null;
}

/// The people-first roster: a card per person, expanded inline to their
/// devices — never a dialog.
class _Roster extends StatelessWidget {
  const _Roster({
    required this.people,
    required this.isOnline,
    required this.session,
  });

  final List<_Person> people;
  final bool Function(Map<String, dynamic>) isOnline;
  final Session session;

  @override
  Widget build(BuildContext context) {
    if (people.isEmpty) {
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No devices have reported in yet.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      );
    }

    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (var i = 0; i < people.length; i++) ...[
            if (i > 0) Divider(height: 1, color: EzBiziTheme.edge(context)),
            _PersonTile(
              person: people[i],
              isOnline: isOnline,
              session: session,
            ),
          ],
        ],
      ),
    );
  }
}

class _PersonTile extends StatelessWidget {
  const _PersonTile({
    required this.person,
    required this.isOnline,
    required this.session,
  });

  final _Person person;
  final bool Function(Map<String, dynamic>) isOnline;
  final Session session;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final anyOnline = person.devices.any(isOnline);
    final current = person.devices.isEmpty
        ? null
        : person.devices.first['current_module'] as String?;

    return ExpansionTile(
      // Expanded on first render: the roster's own screen test asserts on a
      // device row without a tap, and an admin scanning the crew wants the
      // specs already in view rather than a second click per person.
      initiallyExpanded: true,
      title: Row(
        children: [
          _StatusDot(online: anyOnline),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              person.name,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleMedium,
            ),
          ),
          // Flexible rather than a bare Text: at 375px the dot, the name and
          // this together are wider than the row ExpansionTile hands the
          // title, and a non-flexible child there is what overflowed by 13
          // pixels before this — see the 375px test.
          Flexible(
            child: Text(
              anyOnline && current != null && current.isNotEmpty
                  ? 'on ${_moduleLabel(session, current)}'
                  : 'offline',
              overflow: TextOverflow.ellipsis,
              maxLines: 1,
              textAlign: TextAlign.right,
              style: theme.textTheme.bodyMedium,
            ),
          ),
        ],
      ),
      subtitle: Text(
        person.devices.length == 1
            ? '1 device'
            : '${person.devices.length} devices',
        style: theme.textTheme.bodyMedium,
      ),
      children: [
        for (final device in person.devices)
          _DeviceRow(
            key: ValueKey('monitoring-device-${device['id']}'),
            device: device,
            online: isOnline(device),
          ),
      ],
    );
  }
}

class _StatusDot extends StatelessWidget {
  const _StatusDot({required this.online});

  final bool online;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 9,
      height: 9,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: online
            ? EzBiziTheme.clockIn(context)
            : Theme.of(context).colorScheme.outlineVariant,
      ),
    );
  }
}

class _DeviceRow extends StatelessWidget {
  const _DeviceRow({super.key, required this.device, required this.online});

  final Map<String, dynamic> device;
  final bool online;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final hasSpecsCapability = _declaresSpecs(device);
    // `device['specs']` alone cannot tell "the key was never sent" apart
    // from "the key was sent, and it is null" — Dart's Map returns null for
    // both. The two mean different things: DevicesController::present omits
    // the KEY entirely when the caller lacks `monitoring.specs.view`, and
    // sends it present-and-null when the caller is permitted but the agent
    // simply has not reported yet. `containsKey` is what tells them apart.
    final hasSpecsKey = device.containsKey('specs');
    final specs = device['specs'];
    final label =
        device['label'] as String? ?? device['platform'] as String? ?? 'Device';
    final platform = (device['platform'] as String? ?? '').toUpperCase();
    final seen = device['last_seen_at'] as String?;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 10, 16, 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: _StatusDot(online: online),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: 8,
                  children: [
                    if (platform.isNotEmpty)
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 6,
                          vertical: 1,
                        ),
                        decoration: BoxDecoration(
                          color: scheme.surfaceContainerHighest,
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          platform,
                          style: theme.textTheme.labelSmall,
                        ),
                      ),
                    Text(label, style: theme.textTheme.titleSmall),
                  ],
                ),
                const SizedBox(height: 4),
                if (!hasSpecsCapability)
                  Text(
                    PcSpecsTab.webOnlyNote,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontStyle: FontStyle.italic,
                    ),
                  )
                else if (!hasSpecsKey)
                  Text(
                    PcSpecsTab.specsWithheldNote,
                    style: theme.textTheme.bodyMedium,
                  )
                else if (specs is Map<String, dynamic>)
                  _SpecsLine(specs: specs)
                else
                  Text(
                    'This device has not reported hardware details yet.',
                    style: theme.textTheme.bodyMedium,
                  ),
                const SizedBox(height: 4),
                Text(
                  'seen ${_seenAgo(seen)}',
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SpecsLine extends StatelessWidget {
  const _SpecsLine({required this.specs});

  final Map<String, dynamic> specs;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final parts = <String>[];

    final ram = specs['ram_gb'];
    if (ram != null) parts.add('$ram GB RAM');

    final disk = specs['disk_free_gb'];
    if (disk != null) parts.add('$disk GB free');

    final os = specs['os_version'];
    if (os != null) parts.add('$os');

    final ssid = specs['wifi_ssid'];
    final signal = specs['wifi_signal_dbm'];
    if (ssid != null) {
      parts.add(signal != null ? 'Wi-Fi "$ssid" $signal dBm' : 'Wi-Fi "$ssid"');
    }

    final apps = specs['open_apps'];
    final appsLine = apps is List && apps.isNotEmpty
        ? 'Open: ${apps.join(', ')}'
        : null;

    if (parts.isEmpty && appsLine == null) {
      return Text(
        'This device has not reported hardware details yet.',
        style: theme.textTheme.bodyMedium,
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (parts.isNotEmpty)
          Text(parts.join(' · '), style: theme.textTheme.bodyMedium),
        if (appsLine != null) Text(appsLine, style: theme.textTheme.bodyMedium),
      ],
    );
  }
}

/// The at-a-glance panel: people-online count, a disk-low slot, and the list
/// of devices with an agent installed.
class _GlancePanel extends StatelessWidget {
  const _GlancePanel({required this.people, required this.isOnline});

  final List<_Person> people;
  final bool Function(Map<String, dynamic>) isOnline;

  /// Below this an agent device's free space earns a spot in the disk-low
  /// slot. A plain read of the numbers already in `specs` — NOT the
  /// `monitoring.disk_low` trigger, which the module spec defers to the next
  /// sub-project; this is the glance panel noticing the same fact early.
  static const num lowDiskGb = 10;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final onlinePeople = people.where((p) => p.devices.any(isOnline)).length;

    final agentDevices = <(_Person, Map<String, dynamic>)>[
      for (final person in people)
        for (final device in person.devices)
          if (_declaresSpecs(device)) (person, device),
    ];

    final lowDisk = [
      for (final entry in agentDevices)
        if ((_diskFreeGb(entry.$2) ?? lowDiskGb) < lowDiskGb) entry,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('AT A GLANCE', style: theme.textTheme.labelSmall),
        const SizedBox(height: 8),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Text(
              '$onlinePeople / ${people.length} people online',
              style: theme.textTheme.titleMedium,
            ),
          ),
        ),
        const SizedBox(height: 10),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  lowDisk.isEmpty
                      ? 'Nothing low on disk right now.'
                      : '${lowDisk.length} device'
                            '${lowDisk.length == 1 ? '' : 's'} low on disk',
                  style: theme.textTheme.titleMedium,
                ),
                if (lowDisk.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      lowDisk
                          .map(
                            (e) =>
                                '${e.$1.name}’s '
                                '${e.$2['label'] ?? e.$2['platform']}',
                          )
                          .join(', '),
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 10),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Devices with agent', style: theme.textTheme.titleSmall),
                const SizedBox(height: 8),
                if (agentDevices.isEmpty)
                  Text(
                    'No desktop agents have reported in yet.',
                    style: theme.textTheme.bodyMedium,
                  )
                else
                  for (final entry in agentDevices)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              (entry.$2['label'] as String?) ??
                                  (entry.$2['platform'] as String? ?? 'Device'),
                              style: theme.textTheme.bodyMedium,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          const SizedBox(width: 8),
                          _StatusDot(online: isOnline(entry.$2)),
                        ],
                      ),
                    ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
