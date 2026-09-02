import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import 'device_display.dart';

/// Desktop Monitoring — one employee's periodic screen captures, in the
/// "hero + filmstrip" layout Jose picked (docs/previews/
/// monitoring-screenshots-layouts.html, "Layout 2"), reached by picking a
/// PERSON from an on-screen list — never a dropdown or a dialog.
///
/// ── Person-first, not device-first ────────────────────────────────
///
/// A browser session can never be screen-captured, so it has no business in
/// this tab's picker at all — `_loadDevices` filters the server's device
/// list down to capture-capable devices before anything else touches it.
/// What is left is grouped by person: each person shows once, defaulting to
/// their own most-recently-seen capture device. A person with more than one
/// such device gets a small inline chooser to switch between them — still no
/// modal, just a row of chips under their name.
///
/// ── The rule this tab exists to keep honest ────────────────────────
///
/// Same guarantee as the reachability column and PC Specs's withheld-grant
/// sentence: nothing here may be shown as though it were more current or
/// more available than it really is.
///
///   * No `monitoring.screenshots.view` → [noPermissionNote], no fetch at
///     all — the server would 403 every read anyway, and asking first only
///     to show the same sentence a beat later teaches nobody anything.
///   * A device that never declared the `screenshot` capability (web,
///     mobile, or a Windows agent that hasn't started capturing) →
///     [noAgentNote]. It CANNOT be screen-captured, which is a different
///     fact from "hasn't captured recently". In the normal flow such a
///     device can never be selected at all (see above) — this branch stays
///     as defence in depth, not the everyday path.
///   * A capable device with zero rows yet → [noCapturesYetNote].
///   * A capable device whose newest capture is older than twice the
///     capture interval, OR whose heartbeat has gone quiet — "capture
///     stopped" — see [_stoppedLabel]. The hero still shows the last frame
///     it has (dimmed), because that is more honest than a blank tile, but
///     the label says plainly that the stream is not live.
///
/// The default hero is always the newest capture (`_shots.first` — the
/// server already sorts newest-first, see ScreenshotsController::index).
/// Tapping a filmstrip thumbnail swaps which frame is the hero; the
/// "capture stopped" fact is about the DEVICE, not the frame on screen, so
/// it stays attached to the newest capture's age regardless of which frame
/// is being looked at — an old frame someone chose to look at is not lying
/// about being current either way.
class DesktopMonitoringTab extends StatefulWidget {
  const DesktopMonitoringTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// How many recent captures the filmstrip holds.
  static const int filmstripLimit = 12;

  /// [ScreenshotService]'s own default interval (client/lib/core/
  /// screenshot_service.dart) — kept as a literal here rather than an
  /// import, because this tab has no reason to depend on the capture
  /// service itself, only on the number it uses.
  static const Duration captureInterval = Duration(minutes: 5);

  /// A device silent longer than this draws as offline — same threshold and
  /// same reasoning as PcSpecsTab.onlineWithin (this tab has its own copy
  /// rather than importing that one, for the same reason PcSpecsTab's own
  /// `_seenAgo` is not shared: independent knobs that happen to agree today).
  static const Duration onlineWithin = Duration(minutes: 5);

  /// Above this width the people list is a left rail beside the capture;
  /// at or below it, a horizontal chip row sits above the capture instead.
  static const double wideAbove = 600;

  static const String noPermissionNote =
      'Your role does not include monitoring.screenshots.view, so desktop '
      'screenshots are not shown here.';

  static const String noAgentNote =
      "No captures — this device can't be screen-captured.";

  static const String noCapturesYetNote =
      'This device can capture screenshots, but none have landed yet.';

  static const String noDevicesNote =
      'No devices are running the desktop agent yet.';

  @override
  State<DesktopMonitoringTab> createState() => _DesktopMonitoringTabState();
}

class _DesktopMonitoringTabState extends State<DesktopMonitoringTab> {
  bool _loadingDevices = true;
  String? _devicesError;
  List<Map<String, dynamic>> _devices = [];
  String? _selectedDeviceId;

  bool _loadingShots = false;
  String? _shotsError;
  List<Map<String, dynamic>> _shots = [];
  String? _heroId;

  // Keyed by screenshot id, never cleared for the life of this state — so
  // stepping the hero back and forth across the filmstrip never re-downloads
  // a frame it already has.
  final Map<String, Uint8List> _bytes = {};

  /// Bumped every time a shots fetch STARTS — a device switch, a refresh of
  /// the same device, whatever. `_loadShots` captures the value at the top
  /// and re-checks it after every `await`; a response that lands after a
  /// newer fetch has already started is stale and applies nothing.
  ///
  /// ── The bug this guards against ────────────────────────────────
  ///
  /// Two GETs in flight have no ordering guarantee. Switch device A → B
  /// quickly and A's response can resolve AFTER B's — without this guard,
  /// A's `setState` would land last and overwrite `_shots`/`_heroId` with
  /// A's frames while the picker still names B. That is exactly the
  /// wrong-attribution the honesty rule exists to prevent (one device's
  /// screenshots shown as another's), just triggered by switching instead
  /// of elapsed time. See `desktop_monitoring_tab_test.dart`'s device-switch
  /// race test — it fails without this counter and passes with it.
  int _loadGeneration = 0;

  bool get _canView =>
      widget.session.me?.can('monitoring.screenshots.view') ?? false;

  @override
  void initState() {
    super.initState();
    if (_canView) _loadDevices();
  }

  Future<void> _loadDevices() async {
    setState(() {
      _loadingDevices = _devices.isEmpty;
      _devicesError = null;
    });

    final response = await widget.api.get('/monitoring/devices');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingDevices = false;
        _devicesError = response.message;
      });
      return;
    }

    final all = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    // A browser session can never be screen-captured, so it has no business
    // in this tab's picker at all — filtered here, at the source, rather
    // than left in the list for the selection logic to work around.
    final devices = all.where(_declaresScreenshot).toList();
    final keepSelection = devices.any((d) => d['id'] == _selectedDeviceId);

    setState(() {
      _loadingDevices = false;
      _devices = devices;
      if (!keepSelection) {
        final fallback = _mostRecentCaptureDevice(devices);
        _selectedDeviceId = fallback == null ? null : fallback['id'] as String?;
      }
    });

    final deviceId = _selectedDeviceId;
    if (deviceId != null) await _loadShots(deviceId);
  }

  void _selectDevice(String id) {
    if (id == _selectedDeviceId) return;

    setState(() {
      _selectedDeviceId = id;
      _heroId = null;
      _shots = [];
      _shotsError = null;
    });

    _loadShots(id);
  }

  /// Selecting a person selects THEIR default capture device — the one they
  /// were most recently seen on. Goes through [_selectDevice] so the race
  /// guard and the existing fetch path are exactly the same one a direct
  /// device pick uses.
  void _selectPerson(_Person person) {
    final target = _mostRecentCaptureDevice(person.devices);
    if (target != null) _selectDevice(target['id'] as String);
  }

  Future<void> _loadShots(String deviceId) async {
    // Captured before any `await` — nothing between here and the increment
    // can race it, so every later check in this call is comparing against
    // whichever fetch (this one, or a newer one) is actually current.
    final generation = ++_loadGeneration;

    final device = _deviceById(deviceId);

    // A device that never declared the capability cannot have anything to
    // fetch — asking the server is a round trip to learn a fact this tab
    // already has from the device row itself. In the normal flow every
    // device here already passed that filter in `_loadDevices`; this stays
    // as the same defence-in-depth check regardless.
    if (device == null || !_declaresScreenshot(device)) {
      setState(() {
        _loadingShots = false;
        _shotsError = null;
        _shots = [];
        _heroId = null;
      });
      return;
    }

    setState(() {
      _loadingShots = true;
      _shotsError = null;
    });

    final response = await widget.api.get('/monitoring/screenshots', {
      'device_id': deviceId,
      'limit': '${DesktopMonitoringTab.filmstripLimit}',
    });

    // A newer fetch (another switch, or a refresh) started while this one
    // was in flight — its answer, whenever it lands, is not allowed to
    // touch state a newer selection already owns.
    if (!mounted || generation != _loadGeneration) return;

    if (!response.ok) {
      setState(() {
        _loadingShots = false;
        _shotsError = response.message;
      });
      return;
    }

    final shots = (response.data as List<dynamic>).cast<Map<String, dynamic>>();

    setState(() {
      _loadingShots = false;
      _shots = shots;
      _heroId = shots.isEmpty ? null : shots.first['id'] as String?;
    });

    await _fetchBytes(shots, generation);
  }

  Future<void> _fetchBytes(List<Map<String, dynamic>> shots, int generation) async {
    for (final shot in shots) {
      // Same guard, per frame — a stale fetch stops downloading the moment
      // a newer one has taken over, rather than continuing to work (and
      // occasionally `setState`) on behalf of a selection nobody sees any
      // more.
      if (!mounted || generation != _loadGeneration) return;

      final id = shot['id'] as String?;
      if (id == null || _bytes.containsKey(id)) continue;

      final bytes = await widget.api.getBytes('/monitoring/screenshots/$id');
      if (!mounted || generation != _loadGeneration || bytes == null) continue;

      setState(() => _bytes[id] = Uint8List.fromList(bytes));
    }
  }

  Map<String, dynamic>? _deviceById(String? id) =>
      _devices.where((d) => d['id'] == id).firstOrNull;

  @override
  Widget build(BuildContext context) {
    if (!_canView) {
      return _NoteCard(DesktopMonitoringTab.noPermissionNote);
    }

    return AsyncBody(
      loading: _loadingDevices,
      error: _devicesError,
      onRetry: _loadDevices,
      child: _content(context),
    );
  }

  Widget _content(BuildContext context) {
    if (_devices.isEmpty) {
      return _NoteCard(DesktopMonitoringTab.noDevicesNote);
    }

    final people = _groupByPerson(_devices);
    final device = _deviceById(_selectedDeviceId) ?? _mostRecentCaptureDevice(_devices)!;
    final selectedPersonId = (device['user'] as Map<String, dynamic>?)?['id'] as String?;
    final selectedPerson = _personById(people, selectedPersonId);

    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth > DesktopMonitoringTab.wideAbove;
        final capturePane = _buildCapturePane(device, selectedPerson);

        if (wide) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 240,
                  child: _PeopleRail(
                    key: const Key('desktop-monitoring-picker'),
                    people: people,
                    selectedPersonId: selectedPersonId,
                    isOnline: _isOnline,
                    onSelect: _selectPerson,
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(child: SingleChildScrollView(child: capturePane)),
              ],
            ),
          );
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _PeopleChipRow(
                key: const Key('desktop-monitoring-picker'),
                people: people,
                selectedPersonId: selectedPersonId,
                isOnline: _isOnline,
                onSelect: _selectPerson,
              ),
              const SizedBox(height: 12),
              capturePane,
            ],
          ),
        );
      },
    );
  }

  Widget _buildCapturePane(Map<String, dynamic> device, _Person? person) {
    final canCapture = _declaresScreenshot(device);
    final online = _isOnline(device);

    final newest = _shots.isEmpty ? null : _shots.first;
    final newestAt = newest == null
        ? null
        : DateTime.tryParse(newest['captured_at'] as String? ?? '');

    final stopped = canCapture &&
        newestAt != null &&
        (DateTime.now().toUtc().difference(newestAt.toUtc()) >
                DesktopMonitoringTab.captureInterval * 2 ||
            !online);

    final hero = _heroId == null
        ? null
        : (_shots.where((s) => s['id'] == _heroId).firstOrNull ?? _shots.firstOrNull);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // More than one recent capture device for the selected person — an
        // inline chooser, not a second modal, right under their selection.
        if (person != null && person.devices.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _DeviceChooser(
              devices: person.devices,
              selectedId: device['id'] as String?,
              onSelect: _selectDevice,
            ),
          ),
        if (canCapture && newestAt != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: _FreshnessLine(stopped: stopped, ago: _relativeAge(newestAt)),
          ),
        if (!canCapture)
          _NoteCard(DesktopMonitoringTab.noAgentNote)
        else if (_loadingShots)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_shotsError != null)
          ErrorNote(_shotsError!)
        else if (_shots.isEmpty || hero == null)
          _NoteCard(DesktopMonitoringTab.noCapturesYetNote)
        else ...[
          _Hero(
            shot: hero,
            bytes: _bytes[hero['id']],
            stopped: stopped,
            stoppedAgo: newestAt == null ? '' : _relativeAge(newestAt),
          ),
          const SizedBox(height: 12),
          _Filmstrip(
            shots: _shots,
            heroId: _heroId,
            bytes: _bytes,
            onSelect: (id) => setState(() => _heroId = id),
          ),
        ],
      ],
    );
  }
}

bool _declaresScreenshot(Map<String, dynamic> device) =>
    (device['capabilities'] as List<dynamic>? ?? const [])
        .cast<String>()
        .contains('screenshot');

bool _isOnline(Map<String, dynamic> device) {
  final seenRaw = device['last_seen_at'] as String?;
  if (seenRaw == null) return false;
  final seen = DateTime.tryParse(seenRaw);
  if (seen == null) return false;
  return DateTime.now().toUtc().difference(seen.toUtc()) <=
      DesktopMonitoringTab.onlineWithin;
}

/// "just now" / "3m ago" / "2h ago" / "yesterday" / "5d ago" — this tab's own
/// copy of the same relative-time shape PcSpecsTab's `_seenAgo` and
/// leads_screen.dart each carry independently (see PcSpecsTab's doc comment
/// on why no shared helper exists for this).
String _relativeAge(DateTime at) {
  final ago = DateTime.now().difference(at.toLocal());
  if (ago.isNegative || ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return '${ago.inMinutes}m ago';
  if (ago.inHours < 24) return '${ago.inHours}h ago';
  if (ago.inHours < 48) return 'yesterday';
  return '${ago.inDays}d ago';
}

String _freshLabel(String ago) => 'last capture $ago';

/// The one sentence for "the stream is not live right now" — shared between
/// the freshness line and the hero's own badge so the two can never say it
/// two different ways.
String _stoppedLabel(String ago) => 'capture stopped $ago';

String _clockTime(DateTime at) {
  final local = at.toLocal();
  final hour24 = local.hour;
  final hour12 = hour24 % 12 == 0 ? 12 : hour24 % 12;
  final minute = local.minute.toString().padLeft(2, '0');
  final meridiem = hour24 < 12 ? 'AM' : 'PM';
  return '$hour12:$minute $meridiem';
}

/// One person and the capture-capable devices they are signed in from, in
/// the order the server returned them (most recently seen first — see
/// DevicesController::index).
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

_Person? _personById(List<_Person> people, String? id) {
  if (id == null) return null;
  for (final person in people) {
    if (person.id == id) return person;
  }
  return null;
}

/// The device to default to for a person or for the whole list — max by
/// `last_seen_at`. A missing or unparseable stamp sorts as the oldest
/// possible.
Map<String, dynamic>? _mostRecentCaptureDevice(List<Map<String, dynamic>> devices) {
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

class _NoteCard extends StatelessWidget {
  const _NoteCard(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
      ),
    );
  }
}

/// The wide layout's people picker: a search field above a scrollable list —
/// always on screen, never a dropdown or a dialog. Owns its own search text
/// (rather than lifting it to the tab's state) so switching the selected
/// device — a rebuild from above — never clears what somebody typed.
class _PeopleRail extends StatefulWidget {
  const _PeopleRail({
    super.key,
    required this.people,
    required this.selectedPersonId,
    required this.isOnline,
    required this.onSelect,
  });

  final List<_Person> people;
  final String? selectedPersonId;
  final bool Function(Map<String, dynamic>) isOnline;
  final ValueChanged<_Person> onSelect;

  @override
  State<_PeopleRail> createState() => _PeopleRailState();
}

class _PeopleRailState extends State<_PeopleRail> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final query = _query.trim().toLowerCase();
    final filtered = query.isEmpty
        ? widget.people
        : widget.people.where((p) => p.name.toLowerCase().contains(query)).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          decoration: const InputDecoration(
            hintText: 'Search people',
            prefixIcon: Icon(Icons.search),
          ),
          onChanged: (value) => setState(() => _query = value),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: filtered.isEmpty
              ? Center(
                  child: Text('No matches.', style: theme.textTheme.bodyMedium),
                )
              : ListView(
                  children: [
                    for (final person in filtered)
                      _PersonListTile(
                        person: person,
                        device: _mostRecentCaptureDevice(person.devices)!,
                        selected: person.id == widget.selectedPersonId,
                        isOnline: widget.isOnline,
                        onTap: () => widget.onSelect(person),
                      ),
                  ],
                ),
        ),
      ],
    );
  }
}

class _PersonListTile extends StatelessWidget {
  const _PersonListTile({
    required this.person,
    required this.device,
    required this.selected,
    required this.isOnline,
    required this.onTap,
  });

  final _Person person;
  final Map<String, dynamic> device;
  final bool selected;
  final bool Function(Map<String, dynamic>) isOnline;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? scheme.primaryContainer : null,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    person.name,
                    style: theme.textTheme.titleSmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(
                    friendlyDeviceName(device),
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            _StatusDot(online: isOnline(device)),
          ],
        ),
      ),
    );
  }
}

/// The narrow layout's people picker — a horizontal, scrollable chip row
/// above the capture, never a dropdown or a menu. Scrolls sideways rather
/// than wrapping, same reasoning as [_Filmstrip]: frames (here, names) stay a
/// legible size at 375px instead of shrinking to fit.
class _PeopleChipRow extends StatelessWidget {
  const _PeopleChipRow({
    super.key,
    required this.people,
    required this.selectedPersonId,
    required this.isOnline,
    required this.onSelect,
  });

  final List<_Person> people;
  final String? selectedPersonId;
  final bool Function(Map<String, dynamic>) isOnline;
  final ValueChanged<_Person> onSelect;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: people.length,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final person = people[i];
          return ChoiceChip(
            label: Text(person.name),
            selected: person.id == selectedPersonId,
            onSelected: (_) => onSelect(person),
          );
        },
      ),
    );
  }
}

/// A person with more than one recent capture device — pick among them right
/// here, inline, as chips. Never a modal.
class _DeviceChooser extends StatelessWidget {
  const _DeviceChooser({
    required this.devices,
    required this.selectedId,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> devices;
  final String? selectedId;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 4,
      children: [
        for (final device in devices)
          ChoiceChip(
            label: Text(friendlyDeviceName(device)),
            selected: device['id'] == selectedId,
            onSelected: (_) => onSelect(device['id'] as String),
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

class _FreshnessLine extends StatelessWidget {
  const _FreshnessLine({required this.stopped, required this.ago});

  final bool stopped;
  final String ago;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final text = stopped ? _stoppedLabel(ago) : _freshLabel(ago);

    return Text(
      text,
      style: theme.textTheme.bodyMedium?.copyWith(
        color: stopped ? theme.colorScheme.error : null,
        fontWeight: stopped ? FontWeight.w600 : null,
      ),
    );
  }
}

/// The latest (or tapped) capture, large — never a modal, just the top of
/// this scrolling tab.
class _Hero extends StatelessWidget {
  const _Hero({
    required this.shot,
    required this.bytes,
    required this.stopped,
    required this.stoppedAgo,
  });

  final Map<String, dynamic> shot;
  final Uint8List? bytes;

  /// Whether the DEVICE's stream has stopped — not whether this particular
  /// frame is old. See the class doc comment on [DesktopMonitoringTab].
  final bool stopped;

  /// The newest capture's age, used only when [stopped] — the sentence is
  /// about the device going quiet, not about how old this exact frame is.
  final String stoppedAgo;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    final capturedAt = DateTime.tryParse(
      shot['captured_at'] as String? ?? '',
    )?.toLocal();
    final ownAgo = capturedAt == null ? '' : _relativeAge(capturedAt);
    final timeText = capturedAt == null ? '' : _clockTime(capturedAt);

    final badgeText = stopped
        ? _stoppedLabel(stoppedAgo)
        : (timeText.isEmpty ? ownAgo : '$timeText · $ownAgo');

    Widget image = bytes == null
        ? Center(
            child: Icon(Icons.image_outlined, color: scheme.onSurfaceVariant),
          )
        : Image.memory(bytes!, fit: BoxFit.cover);

    // "Greyed", per the honesty rule — a dimmed frame reads as not-current
    // at a glance, before anybody has to read the label to know it.
    if (stopped && bytes != null) {
      image = Opacity(opacity: 0.5, child: image);
    }

    return Column(
      key: const Key('desktop-monitoring-hero'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        AspectRatio(
          aspectRatio: 16 / 9,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: Stack(
              fit: StackFit.expand,
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(color: scheme.surfaceContainerHighest),
                ),
                image,
                Positioned(
                  left: 8,
                  bottom: 8,
                  child: _Badge(
                    text: badgeText,
                    background: stopped ? scheme.errorContainer : scheme.inverseSurface,
                    foreground: stopped ? scheme.onErrorContainer : scheme.onInverseSurface,
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          '${shot['width']}×${shot['height']} · '
          '${((shot['bytes'] as num?) ?? 0) ~/ 1024} KB',
          style: theme.textTheme.bodyMedium,
        ),
      ],
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({
    required this.text,
    required this.background,
    required this.foreground,
  });

  final String text;
  final Color background;
  final Color foreground;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: background.withValues(alpha: 0.85),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(color: foreground),
      ),
    );
  }
}

/// A horizontal, scrollable strip of recent captures — tapping one makes it
/// the hero. Scrolls sideways rather than wrapping, so it works unchanged at
/// 375px (R7): the frames stay a legible size and the strip scrolls instead
/// of shrinking them to fit a phone's width.
class _Filmstrip extends StatelessWidget {
  const _Filmstrip({
    required this.shots,
    required this.heroId,
    required this.bytes,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> shots;
  final String? heroId;
  final Map<String, Uint8List> bytes;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return SizedBox(
      key: const Key('desktop-monitoring-filmstrip'),
      height: 84,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: shots.length,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final shot = shots[i];
          final id = shot['id'] as String;
          final selected = id == heroId;
          final thumb = bytes[id];

          return GestureDetector(
            key: ValueKey('desktop-monitoring-thumb-$id'),
            onTap: () => onSelect(id),
            child: Container(
              width: 120,
              clipBehavior: Clip.antiAlias,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: selected ? scheme.primary : scheme.outlineVariant,
                  width: selected ? 2 : 1,
                ),
              ),
              child: thumb == null
                  ? DecoratedBox(
                      decoration: BoxDecoration(color: scheme.surfaceContainerHighest),
                      child: Center(
                        child: Icon(Icons.image_outlined, color: scheme.onSurfaceVariant),
                      ),
                    )
                  : Image.memory(thumb, fit: BoxFit.cover),
            ),
          );
        },
      ),
    );
  }
}
