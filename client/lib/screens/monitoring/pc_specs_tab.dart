import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import 'device_display.dart';
import 'machine_card.dart';
import 'machine_detail.dart';

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

  /// What the search box holds. Matched against the machine name, the person
  /// and the active window - the three things somebody actually looks a
  /// machine up by. Not the IP, which nobody remembers.
  String _query = '';

  /// The person whose machine the panel is showing, or null for the summary of
  /// the whole room. Same rule the General tab's map follows: clicking a thing
  /// shows that thing, clicking nothing shows everything.
  String? _selectedPersonId;

  /// The roster reloads itself on this cadence.
  ///
  /// The agent measures and beats every 60 seconds, so anything longer than
  /// that shows a machine's load later than it had to; anything much shorter
  /// re-fetches rows that cannot have changed. 30s is the General tab's own
  /// poll, and two Monitoring tabs disagreeing about how fresh "now" is would
  /// be a difference nobody could explain.
  ///
  /// It exists because a live-looking meter that never moves is a worse lie
  /// than a stale number that admits it: this screen used to load once and
  /// then sit there, so a card left open through a lunch break was still
  /// drawing the CPU load from before it.
  static const Duration refreshEvery = Duration(seconds: 30);

  Timer? _refresh;

  @override
  void initState() {
    super.initState();
    _load();
    _refresh = Timer.periodic(refreshEvery, (_) => _load());
    // Between the timed reads: a machine's facts changing shows at once.
    _live = LiveChanges.of(widget.api).subscribe({'monitoring_devices'}, (_) {
      if (!_loading) return _load();
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _refresh?.cancel();
    super.dispose();
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

  @override
  Widget build(BuildContext context) {
    final people = groupByPerson(_devices);
    final shown = _matching(people);

    // A selection survives a refresh but not a filter that hides it: a panel
    // describing a machine you can no longer see on the grid is a puzzle.
    final selected = shown.where((p) => p.id == _selectedPersonId).firstOrNull;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final wide = constraints.maxWidth >= PcSpecsTab.splitAbove;

          final grid = Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _SearchRow(
                query: _query,
                showing: shown.length,
                total: people.length,
                onChanged: (value) => setState(() => _query = value),
              ),
              const SizedBox(height: 12),
              _MachineGrid(
                people: shown,
                isOnline: isDeviceOnline,
                session: widget.session,
                selectedPersonId: _selectedPersonId,
                onSelect: (id) => setState(
                  () => _selectedPersonId = _selectedPersonId == id ? null : id,
                ),
                maxWidth: constraints.maxWidth,
                wide: wide,
              ),
            ],
          );

          final panel = selected == null
              ? _GlancePanel(people: people, isOnline: isDeviceOnline)
              : _selectedDetail(selected);

          if (!wide) {
            return SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [grid, const SizedBox(height: 16), panel],
              ),
            );
          }

          return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: grid),
                const SizedBox(width: 16),
                SizedBox(width: PcSpecsTab.panelWidth, child: panel),
              ],
            ),
          );
        },
      ),
    );
  }

  /// The people whose name, machine name or active window matches [_query].
  /// An empty query matches everybody rather than nobody.
  List<MonitoringPerson> _matching(List<MonitoringPerson> people) {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return people;

    return people.where((person) {
      if (person.name.toLowerCase().contains(needle)) return true;

      return person.devices.any((device) {
        final specs = device['specs'];
        final screen = device['screen_context'];
        final haystack = <String?>[
          machineTitle(device),
          if (screen is Map) screen['active_window'] as String?,
          if (specs is Map) specs['os_version'] as String?,
        ];
        return haystack.any(
          (value) => value != null && value.toLowerCase().contains(needle),
        );
      });
    }).toList();
  }

  Widget _selectedDetail(MonitoringPerson person) {
    final device = currentDeviceFor(person.devices, isDeviceOnline);
    final specs = device['specs'];
    final screen = device['screen_context'];

    return Card(
      key: const ValueKey('tour-monitoring-machine-detail'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                onPressed: () => setState(() => _selectedPersonId = null),
                child: const Text('Show everyone'),
              ),
            ),
            _PersonBody(person: person, isOnline: isDeviceOnline),
            const SizedBox(height: 12),
            MachineDetail(
              title: machineTitle(device),
              person: person.name,
              specs: specs is Map<String, dynamic> ? specs : null,
              screenContext: screen is Map<String, dynamic> ? screen : null,
            ),
          ],
        ),
      ),
    );
  }
}

/// The search field and the count beside it.
///
/// The count says "7 of 42" rather than just "7", because a filtered list that
/// only reports what it kept cannot tell you it is filtered at all.
class _SearchRow extends StatelessWidget {
  const _SearchRow({
    required this.query,
    required this.showing,
    required this.total,
    required this.onChanged,
  });

  final String query;
  final int showing;
  final int total;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: TextFormField(
            key: const Key('monitoring-machine-search'),
            initialValue: query,
            onChanged: onChanged,
            decoration: const InputDecoration(
              isDense: true,
              prefixIcon: Icon(Icons.search),
              hintText: 'Search machines, people, active window',
            ),
          ),
        ),
        const SizedBox(width: 12),
        Text(
          showing == total ? '$total' : '$showing of $total',
          style: Theme.of(context).textTheme.labelMedium,
        ),
      ],
    );
  }
}

/// The machines, laid out as cards that reflow with the pane.
class _MachineGrid extends StatelessWidget {
  const _MachineGrid({
    required this.people,
    required this.isOnline,
    required this.session,
    required this.selectedPersonId,
    required this.onSelect,
    required this.maxWidth,
    required this.wide,
  });

  final List<MonitoringPerson> people;
  final bool Function(Map<String, dynamic>) isOnline;
  final Session session;
  final String? selectedPersonId;
  final ValueChanged<String> onSelect;
  final double maxWidth;
  final bool wide;

  /// Narrower than this and a meter and its number start fighting for the same
  /// few characters, so the grid drops a column rather than shrinking further.
  static const double minCardWidth = 300;

  @override
  Widget build(BuildContext context) {
    if (people.isEmpty) {
      return Card(
        key: const ValueKey('tour-monitoring-machines-empty'),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'No devices have reported in yet.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      );
    }

    // The pane, less the panel and the gaps, is what the cards actually get.
    final available =
        wide ? maxWidth - PcSpecsTab.panelWidth - 48 : maxWidth - 32;
    final columns =
        available <= 0 ? 1 : (available / minCardWidth).floor().clamp(1, 4);
    final cardWidth =
        columns == 1 ? available : (available - (columns - 1) * 12) / columns;

    // Asked of the whole grid, not per card: it is what makes every card the
    // same height, so it has to be one answer for all of them.
    final anyActivity = people.any((person) {
      final screen = currentDeviceFor(person.devices, isOnline)['screen_context'];
      final window = screen is Map ? screen['active_window'] as String? : null;
      return window != null && window.isNotEmpty;
    });

    return Wrap(
      key: const ValueKey('tour-monitoring-machines'),
      spacing: 12,
      runSpacing: 12,
      children: [
        for (final person in people)
          SizedBox(
            width: cardWidth > 0 ? cardWidth : null,
            child: _cardFor(person, reserveActivityLine: anyActivity),
          ),
      ],
    );
  }

  Widget _cardFor(MonitoringPerson person, {required bool reserveActivityLine}) {
    final device = currentDeviceFor(person.devices, isOnline);
    final specs = device['specs'];
    final map =
        specs is Map<String, dynamic> ? specs : const <String, dynamic>{};
    final screen = device['screen_context'];

    return MachineCard(
      key: ValueKey('monitoring-machine-${person.id}'),
      title: machineTitle(device),
      person: person.name,
      module: _moduleFor(device),
      sessions: _sessionsFor(person),
      online: isOnline(device),
      seenLabel: seenAgo(device['last_seen_at'] as String?),
      reserveActivityLine: reserveActivityLine,
      cpuPercent: _asInt(map['cpu_percent']),
      ramPercent: _asInt(map['ram_percent']),
      gpuPercent: _asInt(map['gpu_percent']),
      needsAttention: securityNeedsAttention(map),
      diskPercent: _diskPercent(map['drives']),
      diskFreeLabel: _diskFree(map),
      activeWindow: screen is Map ? screen['active_window'] as String? : null,
      selected: person.id == selectedPersonId,
      onTap: () => onSelect(person.id),
    );
  }

  /// "1 active - 20 earlier", or just "offline" when none of a person's
  /// devices is. Word for word what the roster's tile subtitle said before the
  /// grid replaced it: the count of a person's OTHER sessions is not visible
  /// anywhere on a card that shows only their current machine, and it is how
  /// somebody notices they are still signed in on a machine they left.
  String? _sessionsFor(MonitoringPerson person) {
    final active = person.devices.where(isOnline).length;
    final earlier = person.devices.length - (active > 0 ? active : 1);
    final suffix = earlier > 0 ? ' · $earlier earlier' : '';
    return active > 0 ? '$active active$suffix' : 'offline$suffix';
  }

  /// The tab this device is on, worded the way the nav words it. Null when
  /// the device is offline: "on Leads" about a machine nobody is signed in at
  /// is a claim about the past dressed as the present.
  String? _moduleFor(Map<String, dynamic> device) {
    if (!isOnline(device)) return null;
    final key = device['current_module'] as String?;
    if (key == null || key.isEmpty) return null;
    return _moduleLabel(session, key);
  }

  static int? _asInt(Object? value) => value is int ? value : null;

  /// How full the biggest fixed drive is. The biggest rather than the first,
  /// because Windows enumerates drives in its own order, and "the drive that
  /// matters" is the one the room is on.
  static int? _diskPercent(Object? drives) {
    final biggest = _biggestDrive(drives);
    if (biggest == null) return null;

    final total = biggest['total_gb'];
    final free = biggest['free_gb'];
    if (total is! int || free is! int || total <= 0) return null;

    return (100 * (total - free) / total).round().clamp(0, 100);
  }

  static String? _diskFree(Map<String, dynamic> specs) {
    final biggest = _biggestDrive(specs['drives']);
    final free = biggest == null ? specs['disk_free_gb'] : biggest['free_gb'];
    return free is int ? '$free GB free' : null;
  }

  static Map? _biggestDrive(Object? drives) {
    if (drives is! List) return null;

    Map? biggest;
    for (final drive in drives.whereType<Map>()) {
      final total = drive['total_gb'];
      if (total is! int) continue;
      final best = biggest?['total_gb'];
      if (best is! int || total > best) biggest = drive;
    }
    return biggest;
  }
}

/// The device a person's card and panel describe: their newest ONLINE machine,
/// preferring the desktop agent over a browser tab, and falling back to their
/// most recently seen device when nothing of theirs is online.
///
/// Public because the card and the detail panel must not disagree about which
/// machine they are describing - one function, called twice.
Map<String, dynamic> currentDeviceFor(
  List<Map<String, dynamic>> devices,
  bool Function(Map<String, dynamic>) isOnline,
) {
  final active = devices.where(isOnline).toList()..sort(_byAgentThenRecency);
  return active.isNotEmpty ? active.first : mostRecentlySeen(devices)!;
}

/// What to call a machine: the name it reports for itself, falling back to the
/// device label the roster already knows how to word.
String machineTitle(Map<String, dynamic> device) {
  final specs = device['specs'];
  if (specs is Map) {
    final name = specs['computer_name'];
    if (name is String && name.trim().isNotEmpty) return name;
  }
  return friendlyDeviceName(device);
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
/// roster wants. So the monitoring tabs share their own, [relativeAge].
///
/// Public, and deliberately: FloorPlanSummary says "seen 3m ago" about the
/// same `last_seen_at` stamp this roster does, and the General map and PC
/// Specs disagreeing about how long ago a person was last seen would be a
/// bug you could only find by holding the two tabs side by side. One
/// formatter, imported, not a third copy.
String seenAgo(String? iso) {
  final at = iso == null ? null : DateTime.tryParse(iso);
  return at == null ? 'never' : relativeAge(at);
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

/// This person's own most-recently-seen agent device, or `null` when they
/// have none within [window]. Used to collapse the glance panel's "Desktop
/// app" list to one line per person — their newest agent — while dropping
/// anyone whose only agent device has gone quiet for longer than [window].
Map<String, dynamic>? _mostRecentAgentWithin(MonitoringPerson person, Duration window) {
  final cutoff = DateTime.now().toUtc().subtract(window);
  Map<String, dynamic>? best;
  DateTime? bestSeen;

  for (final device in person.devices) {
    if (!isDesktopAgent(device)) continue;
    final seen = DateTime.tryParse(device['last_seen_at'] as String? ?? '');
    if (seen == null || seen.toUtc().isBefore(cutoff)) continue;
    if (best == null || seen.isAfter(bestSeen!)) {
      best = device;
      bestSeen = seen;
    }
  }

  return best;
}

num? _diskFreeGb(Map<String, dynamic> device) {
  final specs = device['specs'];
  if (specs is! Map<String, dynamic>) return null;
  final value = specs['disk_free_gb'];
  return value is num ? value : null;
}

/// The people-first roster: a card per person, expanded inline to their
/// devices — never a dialog.
/// One person's devices, as the detail panel shows them: the device they are
/// actually on, a switcher when more than one of theirs is live, and their
/// earlier sessions folded away behind a toggle.
///
/// This is what the roster's expandable person tile used to render inline,
/// lifted out unchanged when the tab became a grid. Kept rather than rebuilt
/// because it carries three things the grid's cards deliberately do not: the
/// exact sentence for a device that CANNOT report specs, the different
/// sentence for one whose specs were WITHHELD from this viewer, and the
/// switcher for somebody signed in from two machines at once. A card says how
/// busy a machine is; only this says why it might be saying nothing.
class _PersonBody extends StatelessWidget {
  const _PersonBody({required this.person, required this.isOnline});

  final MonitoringPerson person;
  final bool Function(Map<String, dynamic>) isOnline;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Agent devices sort first, so somebody signed in from both their desktop
    // app and a browser tab defaults to the desktop app - not whichever
    // session happened to arrive first from the server.
    final active = person.devices.where(isOnline).toList()
      ..sort(_byAgentThenRecency);

    // Every active device, or - when nobody is online - the single
    // most-recently-seen one, so this is never empty just because everyone
    // signed out. Everything else folds into the stale group below.
    final shown = active.isNotEmpty
        ? active
        : [mostRecentlySeen(person.devices)!];
    final shownIds = shown.map((d) => d['id']).toSet();
    final stale = [
      for (final device in person.devices)
        if (!shownIds.contains(device['id'])) device,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (shown.length > 1)
          _ActiveDeviceSwitcher(devices: shown, isOnline: isOnline)
        else
          _DeviceRow(
            key: ValueKey('monitoring-device-${shown.first['id']}'),
            device: shown.first,
            online: isOnline(shown.first),
          ),
        if (stale.isNotEmpty)
          ExpansionTile(
            tilePadding: const EdgeInsets.fromLTRB(8, 0, 8, 0),
            title: Text(
              'Show ${stale.length} earlier '
              'session${stale.length == 1 ? '' : 's'}',
              style: theme.textTheme.bodyMedium,
            ),
            children: [
              for (final device in stale)
                _DeviceRow(
                  key: ValueKey('monitoring-device-${device['id']}'),
                  device: device,
                  online: isOnline(device),
                ),
            ],
          ),
      ],
    );
  }
}

/// Agent devices (see [isDesktopAgent]) sort before everything else; within
/// the same tier, the most-recently-seen device sorts first. Used to order
/// the "active" list a person's tile shows, so the default (index 0) is
/// always the desktop app when one is active, never a browser tab that
/// merely happened to arrive first from the server.
int _byAgentThenRecency(Map<String, dynamic> a, Map<String, dynamic> b) {
  final agentA = isDesktopAgent(a);
  final agentB = isDesktopAgent(b);
  if (agentA != agentB) return agentA ? -1 : 1;

  final seenA = DateTime.tryParse(a['last_seen_at'] as String? ?? '') ??
      DateTime.fromMillisecondsSinceEpoch(0);
  final seenB = DateTime.tryParse(b['last_seen_at'] as String? ?? '') ??
      DateTime.fromMillisecondsSinceEpoch(0);
  return seenB.compareTo(seenA);
}

/// More than one device is active at once — a row of chips, one selected
/// device's [_DeviceRow] shown below it, rather than every active session
/// stacked as an equal row. The selected index is the only reason this
/// needs to be a StatefulWidget instead of another loop in [_PersonTile].
class _ActiveDeviceSwitcher extends StatefulWidget {
  const _ActiveDeviceSwitcher({required this.devices, required this.isOnline});

  final List<Map<String, dynamic>> devices;
  final bool Function(Map<String, dynamic>) isOnline;

  @override
  State<_ActiveDeviceSwitcher> createState() => _ActiveDeviceSwitcherState();
}

class _ActiveDeviceSwitcherState extends State<_ActiveDeviceSwitcher> {
  int _selected = 0;

  @override
  Widget build(BuildContext context) {
    final index = _selected < widget.devices.length ? _selected : 0;
    final device = widget.devices[index];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 16, 4),
          child: Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (var i = 0; i < widget.devices.length; i++)
                ChoiceChip(
                  label: Text(_chipLabel(widget.devices[i])),
                  selected: i == index,
                  onSelected: (_) => setState(() => _selected = i),
                ),
            ],
          ),
        ),
        _DeviceRow(
          key: ValueKey('monitoring-device-${device['id']}'),
          device: device,
          online: widget.isOnline(device),
        ),
      ],
    );
  }
}

/// A switcher chip's label: platform badge + the row's own label, e.g.
/// "WINDOWS Shop desktop" — same two facts [_DeviceRow] shows, just on one
/// line, so the chip that swaps a device in is identifiable before you tap.
String _chipLabel(Map<String, dynamic> device) {
  final platform = (device['platform'] as String? ?? '').toUpperCase();
  final label = friendlyDeviceName(device);
  return platform.isEmpty ? label : '$platform $label';
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
    final label = friendlyDeviceName(device);
    final platform = (device['platform'] as String? ?? '').toUpperCase();
    final seen = device['last_seen_at'] as String?;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 10, 16, 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: StatusDot(online: online),
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
                  SpecsLine(specs: specs)
                else
                  Text(
                    'This device has not reported hardware details yet.',
                    style: theme.textTheme.bodyMedium,
                  ),
                const SizedBox(height: 4),
                Text(
                  'seen ${seenAgo(seen)}',
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

/// One device's reported hardware, as the roster words it: the numbers on one
/// line, open apps on a second, and a plain sentence when the map is present
/// but says nothing.
///
/// Public for the same reason [seenAgo] is: the General tab's summary sidebar
/// shows the selected person's specs, and it shows them by mounting THIS —
/// so "32 GB RAM · 128 GB free" is one rendering with one set of labels,
/// rather than a second one that drifts the first time a key is added.
class SpecsLine extends StatelessWidget {
  const SpecsLine({super.key, required this.specs});

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

  final List<MonitoringPerson> people;
  final bool Function(Map<String, dynamic>) isOnline;

  /// Below this an agent device's free space earns a spot in the disk-low
  /// slot. A plain read of the numbers already in `specs` — NOT the
  /// `monitoring.disk_low` trigger, which the module spec defers to the next
  /// sub-project; this is the glance panel noticing the same fact early.
  static const num lowDiskGb = 10;

  /// An agent device silent longer than this drops out of the "Desktop app"
  /// list entirely — a stale historical row from months ago is not "a
  /// machine running the desktop app" in any useful sense, and showing it
  /// forever is exactly the per-session-row noise this rewrite exists to
  /// remove.
  static const Duration recentAgentWindow = Duration(hours: 24);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final onlinePeople = people.where((p) => p.devices.any(isOnline)).length;

    final agentDevices = <(MonitoringPerson, Map<String, dynamic>)>[
      for (final person in people)
        for (final device in person.devices)
          if (_declaresSpecs(device)) (person, device),
    ];

    final lowDisk = [
      for (final entry in agentDevices)
        if ((_diskFreeGb(entry.$2) ?? lowDiskGb) < lowDiskGb) entry,
    ];

    // One line per PERSON, not one per device row — a person signed in
    // from both their desktop and an old laptop used to draw two equal
    // rows here. Each line is that person's own most-recently-seen agent
    // device, and a device that has not reported in over a day drops out
    // entirely rather than sitting here as permanent, meaningless noise.
    final recentDesktopApps = <(MonitoringPerson, Map<String, dynamic>)>[];
    for (final person in people) {
      final device = _mostRecentAgentWithin(person, recentAgentWindow);
      if (device != null) recentDesktopApps.add((person, device));
    }

    return Column(
      key: const ValueKey('tour-monitoring-glance'),
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
                                '${friendlyDeviceName(e.$2)}',
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
                Text('Desktop app', style: theme.textTheme.titleSmall),
                const SizedBox(height: 2),
                Text(
                  'Machines running the EzBizi desktop app — they report '
                  'hardware and can be screen-viewed.',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
                const SizedBox(height: 8),
                if (recentDesktopApps.isEmpty)
                  Text(
                    'No desktop agents have reported in yet.',
                    style: theme.textTheme.bodyMedium,
                  )
                else
                  for (final entry in recentDesktopApps)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${entry.$1.name} · ${friendlyDeviceName(entry.$2)}',
                              style: theme.textTheme.bodyMedium,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          const SizedBox(width: 8),
                          StatusDot(online: isOnline(entry.$2)),
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
