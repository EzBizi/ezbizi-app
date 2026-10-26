import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/session.dart';
import '../widgets/tab_bar_like.dart';
import 'scheduling/area_map_pane.dart';
import 'scheduling/availability_pane.dart';
import 'scheduling/booked_available_pane.dart';
import 'scheduling/capacity_settings.dart';
import 'scheduling/helpers_pane.dart';
import 'scheduling/locations_pane.dart';
import 'scheduling/overbooking_pane.dart';
import 'scheduling/routing_pane.dart';
import 'scheduling/schedule_pane.dart';
import 'scheduling/technician_links.dart';
import 'settings_screen.dart';

/// Scheduling: Availability, the Schedule board, and Settings.
///
/// design: docs/previews/scheduling-layouts.html — #4 Availability, #7 Settings
class SchedulingScreen extends StatefulWidget {
  const SchedulingScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SchedulingScreen> createState() => _SchedulingScreenState();
}

class _SchedulingScreenState extends State<SchedulingScreen> {
  int _tab = 0;
  final Set<int> _shown = {0};

  /// The day the last `/scheduling/overbooking?date=` link named, and how many
  /// have arrived, so the same day linked twice still opens.
  String? _linkDate;
  int _linkSerial = 0;

  @override
  void initState() {
    super.initState();
    _takeLink();
    DeepLinks.pending.addListener(_linkArrived);
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_linkArrived);
    super.dispose();
  }

  void _linkArrived() => setState(_takeLink);

  void _takeLink() {
    final link = DeepLinks.take('scheduling');
    if (link?.type != 'booked_vs_available') return;
    final at = _tabs.indexWhere((t) => t.$1 == 'rescheduling');
    if (at < 0) return;
    _linkDate = link!.date;
    _linkSerial++;
    _tab = at;
    _shown.add(at);
  }

  /// Every tab this screen has, with the permission key that opens it.
  ///
  /// Jose, 2026-09-17: "i should be able to grant access by sub tab as well".
  /// Here that splits reading what the business can take from changing it —
  /// a dispatcher answering the phone needs Availability and has no business
  /// editing how many jobs a franchise accepts.
  ///
  /// THE KEYS HERE AND THE TABS IN THE REGISTRY ARE THE SAME THREE. The labels
  /// are the strings the tab bar draws, and ModuleServiceProvider copies them
  /// rather than restating them — a checkbox named for a tab that does not
  /// exist under that name is a grant nobody can verify.
  List<(String, String, IconData, Widget Function())> get _declaredTabs => [
    (
      'availability',
      'Availability',
      Icons.event_available_outlined,
      () => AvailabilityPane(
        api: widget.api,
        session: widget.session,
        visible: _visible('availability'),
      ),
    ),
    (
      'schedule',
      'Schedule',
      Icons.calendar_month_outlined,
      // One day's board, read-only: A-1 assigns in Workiz. See SchedulePane.
      () => SchedulePane(
        api: widget.api,
        session: widget.session,
        visible: _visible('schedule'),
      ),
    ),
    (
      'overbooking',
      'Overbooking',
      Icons.warning_amber_outlined,
      () => OverbookingPane(api: widget.api, visible: _visible('overbooking')),
    ),
    (
      'rescheduling',
      'Re-Scheduling',
      Icons.swap_horiz,
      // Rescheduling Availability (Booked vs available, UI queue 147) and AI
      // Rescheduling Suggestion (Jose, 2026-10-26).
      () => ReschedulingTab(
        api: widget.api,
        session: widget.session,
        visible: _visible('rescheduling'),
        canEdit: widget.session.me?.can('scheduling.edit') ?? false,
        showAvailability: _mayOpen('overbooking'),
        linkDate: _linkDate,
        linkSerial: _linkSerial,
      ),
    ),
    (
      // A route per technician for a day, each opening in Google Maps (Jose,
      // 2026-10-14; UI queue 90).
      'routing',
      'Routing',
      Icons.route_outlined,
      () => RoutingPane(api: widget.api, visible: _visible('routing')),
    ),
    (
      // A1 Tools' helper calendar: helpers booked to technicians by day or
      // half (A1 Leads takeover; UI queue 112, Jose: 1 and 4).
      'helpers',
      'Helpers',
      Icons.group_add_outlined,
      () => HelpersPane(
        api: widget.api,
        session: widget.session,
        visible: _visible('helpers'),
      ),
    ),
    (
      // Every branch, the closest one, who to call (UI queue 49).
      'locations',
      'Locations',
      Icons.place_outlined,
      () => LocationsPane(
        api: widget.api,
        session: widget.session,
        visible: _visible('locations'),
      ),
    ),
    (
      'settings',
      'Settings',
      Icons.tune,
      () => SchedulingSettings(
        api: widget.api,
        session: widget.session,
        visible: _visible('settings'),
      ),
    ),
  ];

  /// The tabs this person may actually open.
  List<(String, String, IconData, Widget Function())> get _tabs => [
    for (final tab in _declaredTabs)
      if (_mayOpen(tab.$1)) tab,
  ];

  /// Absent `/me` answers NO, not yes. This screen is reached only with a
  /// session, so a null here is a load that has not finished or a state nobody
  /// designed, and the safe reading of "I do not know yet" is to draw nothing.
  bool _mayOpen(String tab) =>
      widget.session.me?.permissions.contains('scheduling.$tab.view') ?? false;

  /// The tab actually on screen, clamped ONCE.
  ///
  /// Selection is an index and the list length depends on the grants, so a
  /// selection made when three tabs were drawn can point past the end of one.
  /// Both readers of that — the bar and the pane stack — have to clamp the
  /// same way, and when the fallback lived in `build` alone the two disagreed:
  /// `build` drew tabs[0] while `_visible` compared the unclamped index and
  /// told that pane it was off-screen.
  ///
  /// THE CONSEQUENCE HERE IS NARROWER THAN IT LOOKS, and worth stating so
  /// nobody removes this thinking it fixed a blank screen. `CapacitySettings`
  /// loads in `initState`, so a freshly built pane fetches whatever `visible`
  /// says; the flag only drives the RELOAD in `didUpdateWidget`. So the
  /// disagreement cost a stale pane after a revocation, not the empty screen
  /// the same shape produced in HR, where the landing tab was a constant.
  int get _selected {
    final length = _tabs.length;

    return _tab < length ? _tab : 0;
  }

  /// Whether the pane at [key] is the one on screen.
  ///
  /// By KEY rather than by index, because the index depends on which tabs this
  /// person can open: with Availability hidden, Settings is index 0.
  bool _visible(String key) {
    final tabs = _tabs;

    return tabs.isNotEmpty && tabs[_selected].$1 == key;
  }

  void _select(int tab) => setState(() {
    _tab = tab;
    _shown.add(tab);
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tabs = _tabs;

    if (tabs.isEmpty) {
      // Reachable: the rail draws Scheduling for anybody holding
      // `scheduling.view`, and the tab grants are separate from it. Somebody
      // with the module and none of its tabs gets a sentence rather than a
      // blank pane that reads as a broken screen.
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            'You do not have access to any part of Scheduling yet. An '
            'administrator grants these one tab at a time.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
      );
    }

    // The same clamp the pane stack uses — see `_selected`.
    final selected = _selected;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surface,
          child: TabBarLike(
            key: const ValueKey('tour-scheduling-tabs'),
            labels: [for (final tab in tabs) (tab.$2, tab.$3)],
            selected: selected,
            onSelected: _select,
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < tabs.length; i++)
                Offstage(
                  offstage: i != selected,
                  child: _shown.contains(i)
                      ? tabs[i].$4()
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Settings, in sub-tabs (Jose, 2026-09-25): Availability is the capacity
/// screen that was all of Settings until now; Schedule links Workiz
/// technicians to EzBizi users; Locations keeps each company's note for new
/// dispatchers (UI queue 49).
///
/// ponytail: both sit under `scheduling.settings.view`. Split the grant when
/// somebody needs one without the other.
class SchedulingSettings extends StatefulWidget {
  const SchedulingSettings({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  @override
  State<SchedulingSettings> createState() => _SchedulingSettingsState();
}

class _SchedulingSettingsState extends State<SchedulingSettings> {
  int _tab = 0;
  final Set<int> _shown = {0};

  @override
  Widget build(BuildContext context) {
    // The notes belong to the Locations tab, so they come with its grant: a
    // server without the tab has nowhere to keep them.
    final notes =
        widget.session.me?.permissions.contains('scheduling.locations.view') ??
        false;
    // Each sub-tab with its pane; `visible` is asked by position, which is
    // computed here so a tab drawn or not (Locations) cannot shift another's.
    final tabs = <(String, IconData, Widget Function(bool visible))>[
      (
        'Availability',
        Icons.event_available_outlined,
        (visible) => CapacitySettings(
          api: widget.api,
          session: widget.session,
          visible: visible,
        ),
      ),
      // Drawn service areas (UI queue 125).
      (
        'Service areas',
        Icons.map_outlined,
        (visible) => AreaMapPane(
          api: widget.api,
          session: widget.session,
          visible: visible,
        ),
      ),
      (
        'Schedule',
        Icons.engineering_outlined,
        (visible) => TechnicianLinks(api: widget.api, visible: visible),
      ),
      if (notes)
        (
          'Locations',
          Icons.place_outlined,
          (visible) => DispatcherNotesSettings(
            api: widget.api,
            session: widget.session,
            visible: visible,
          ),
        ),
      // The suggested-availability radius and look-ahead (Jose, 2026-10-13:
      // "make it so i can change that in the app as well on the scheduling
      // settings"): the Settings rows of the scheduling group, as Field's
      // Settings tab draws its own.
      (
        'Routing & suggestions',
        Icons.near_me_outlined,
        (_) => SettingsScreen(
          key: const ValueKey('tour-scheduling-suggestions'),
          api: widget.api,
          session: widget.session,
          onlyGroup: 'scheduling',
        ),
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          key: const ValueKey('tour-scheduling-settings-tabs'),
          level: TabLevel.secondary,
          labels: [for (final t in tabs) (t.$1, t.$2)],
          selected: _tab,
          onSelected: (i) => setState(() {
            _tab = i;
            _shown.add(i);
          }),
        ),
        const Divider(height: 1),
        Expanded(
          child: Stack(
            fit: StackFit.expand,
            children: [
              for (var i = 0; i < tabs.length; i++)
                Offstage(
                  offstage: i != _tab,
                  child: _shown.contains(i)
                      ? tabs[i].$3(widget.visible && _tab == i)
                      : const SizedBox.shrink(),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
