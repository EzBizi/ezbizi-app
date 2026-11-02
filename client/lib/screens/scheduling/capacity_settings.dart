import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/franchise_field.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'area_delete_panel.dart';

/// Capacity settings — the area rail, the pattern overview, and one area's grid.
///
/// design: docs/previews/scheduling-layouts.html #7 (#8 selected, #9 at 375px)
///
/// ── Three things this screen must not do ─────────────────────────
///
/// CLOSING A DAY DELETES ITS SLOTS. It never writes zero-capacity rows. A day
/// with no rows is closed; a day with rows is open. If closing ever wrote
/// zeros, "we do not work Sundays" and "we work Sundays and are full" would
/// become one stored state and nothing would error. The server refuses a zero
/// as well — a layout can decline to OFFER it, only the write path can decline
/// to ACCEPT it, and both halves are needed.
///
/// APPLYING A PATTERN NAMES WHAT IT WILL OVERWRITE FIRST, with the values, and
/// then applies to every area or none.
///
/// A FAILED WRITE MUST NOT BLANK THE SCREEN. `_error` is for the initial load
/// only, because [AsyncBody] renders it INSTEAD of the content. A create or a
/// save that fails writes `_paneError`, which draws an [ErrorNote] beside the
/// form that caused it. Sending a create failure to `_error` is what turned
/// "pick a franchise" into an empty page with one red box on it.
///
/// ── Forms are panes, never modals ────────────────────────────────
///
/// Adding and renaming an area happen in the right-hand pane, the same way
/// `AddServicePane` does it. A dialog over a split screen hides the rail you
/// were reading to decide what to type.
class CapacitySettings extends StatefulWidget {
  const CapacitySettings({
    super.key,
    required this.api,
    required this.session,
    required this.visible,
  });

  final ApiClient api;
  final Session session;
  final bool visible;

  static const double splitAbove = 600;

  @override
  State<CapacitySettings> createState() => _CapacitySettingsState();
}

/// What the pane is showing instead of an area or the overview.
enum _Form { none, create, rename }

class _CapacitySettingsState extends State<CapacitySettings> {
  /// Monday first, because the design's week reads Mon–Sun. The VALUES stay
  /// Carbon's weekday numbers (0 = Sunday) so nothing translates on the wire.
  static const _week = <(int, String)>[
    (1, 'Mon'),
    (2, 'Tue'),
    (3, 'Wed'),
    (4, 'Thu'),
    (5, 'Fri'),
    (6, 'Sat'),
    (0, 'Sun'),
  ];

  final _search = TextEditingController();
  final _zipSearch = TextEditingController();
  final _zipEntry = TextEditingController();
  final _cityEntry = TextEditingController();
  final _formName = TextEditingController();

  bool _loading = true;

  /// The initial load only. See the class docblock — AsyncBody replaces the
  /// whole pane with this, so a write failure must never reach it.
  String? _error;

  /// A failure that belongs to the form or the area in front of you.
  String? _paneError;

  List<Map<String, dynamic>> _areas = [];

  /// null means the Overview is showing. The rail always has a selection.
  String? _openAreaId;
  Map<String, dynamic>? _openArea;
  bool _saving = false;

  _Form _form = _Form.none;

  String? _formFranchiseId;

  /// This area's zips as ITEMS, each `{zip, city}`. Not a comma-separated
  /// string: one mistyped separator in a field of fifty should cost one zip,
  /// not the whole set.
  List<Map<String, dynamic>> _zipList = [];

  /// weekday -> list of {start, end, max}. Absent weekday = closed.
  ///
  /// `max_jobs` may be NULL in here while editing: a window left blank on an
  /// open day. It is never SENT — see [_saveGrid] — so a blank window is a
  /// window that day does not offer, and never a zero.
  final Map<int, List<Map<String, dynamic>>> _grid = {};

  /// The franchise whose Overview is open; null is the whole view's.
  String? _overviewFranchiseId;

  /// While set, the week grid edits the pattern that takes over from this
  /// date instead of the current one (Eve, 2026-09-29).
  String? _editingFrom;

  /// Each franchise's daily limit, weekday => jobs (Jose, 2026-09-25: the
  /// franchise's technicians are shared, so its areas' sum is not what it can
  /// do). Absent is no limit. Openings enforces it on the server.
  Map<String, Map<int, int>> _franchiseLimits = {};

  /// The open franchise's limit fields, weekday => field.
  final Map<int, TextEditingController> _limitFields = {
    for (var d = 0; d < 7; d++) d: TextEditingController(),
  };
  bool _savingLimits = false;

  /// The open area's own daily limit fields, weekday => field (Jose,
  /// 2026-09-28: "we do not have daily limit for service area").
  final Map<int, TextEditingController> _areaLimitFields = {
    for (var d = 0; d < 7; d++) d: TextEditingController(),
  };
  bool _savingAreaLimits = false;

  /// Each weekday's "Day" box. Kept equal to the sum of its windows, so typing
  /// a window updates it; typing IT splits the number across the windows.
  final Map<int, TextEditingController> _dayTotal = {};

  /// Bumped when a split rewrites a day's windows. It keys that day's window
  /// fields, which read their value once, so new numbers draw fresh fields.
  final Map<int, int> _dayGen = {};

  /// The organization's windows, `{id, start_time, end_time}`, earliest first.
  /// Every area books into these; what differs per area is the number.
  List<Map<String, dynamic>> _timeSlots = [];

  /// Whether this person may change the windows. They apply to every branch,
  /// so a branch-level manager reads them and cannot move them.
  bool _canEditSlots = false;

  /// Whose windows these are when not the company in view's own, e.g.
  /// "the organization" (per company since 2026-10-28).
  String? _slotsInheritedFrom;

  /// A failure that belongs to the time-slots card, and only to it.
  String? _slotError;

  /// The add row's two times, or the row being edited's.
  String _draftStart = '10:00';
  String _draftEnd = '13:00';
  String? _editingSlotId;
  String? _removingSlotId;

  /// The time slots card is read-only until "Edit time slots".
  bool _slotsUnlocked = false;
  bool _addingSlot = false;

  /// A change of hours that moves areas' capacity waits on this.
  bool _confirmMove = false;
  final _removeTyped = TextEditingController();

  Me? get _me => widget.session.me;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant CapacitySettings old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _zipSearch.dispose();
    _zipEntry.dispose();
    _cityEntry.dispose();
    _formName.dispose();
    _removeTyped.dispose();
    for (final c in _dayTotal.values) {
      c.dispose();
    }
    for (final c in _limitFields.values) {
      c.dispose();
    }
    for (final c in _areaLimitFields.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _areas.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/areas', const {});
    await _loadTimeSlots();

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _areas = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _franchiseLimits = {
        for (final e
            in (response.body['franchise_limits'] as Map? ?? const {}).entries)
          '${e.key}': {
            for (final d in (e.value as Map).entries)
              int.parse('${d.key}'): (d.value as num).toInt(),
          },
      };
    });
  }

  /// The windows, loaded on their own so a failure here costs the card and not
  /// the screen: an area's existing capacity is still readable and editable
  /// without the list, because every row carries its own window.
  Future<void> _loadTimeSlots() async {
    final response = await widget.api.get('/scheduling/time-slots', const {});

    if (!mounted) return;

    setState(() {
      if (!response.ok) {
        _slotError = response.message;
        return;
      }
      _slotError = null;
      _timeSlots = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _canEditSlots = response.body['can_edit'] == true;
      _slotsInheritedFrom = response.body['inherited_from'] as String?;
    });
  }

  /// Add a window, or change the hours of [_editingSlotId].
  ///
  /// Changing hours MOVES every area's capacity on that window — the server
  /// does it in one transaction — so the areas and the open grid are reloaded
  /// afterwards rather than trusted.
  Future<void> _saveTimeSlot() async {
    final editing = _editingSlotId;
    final body = {'start_time': _draftStart, 'end_time': _draftEnd};

    final response = editing == null
        ? await widget.api.post('/scheduling/time-slots', body)
        : await widget.api.patch('/scheduling/time-slots/$editing', body);

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _slotError = response.message);
      return;
    }

    setState(() {
      _editingSlotId = null;
      _addingSlot = false;
      _confirmMove = false;
    });
    await _afterWindowsChanged();
  }

  /// The server refuses a removal that does not name the window's own hours,
  /// so a stray request cannot delete one.
  Future<void> _removeTimeSlot(Map<String, dynamic> w) async {
    final response = await widget.api.delete(
      '/scheduling/time-slots/${w['id']}',
      {'confirm': '${w['start_time']}-${w['end_time']}'},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _slotError = response.message);
      return;
    }

    setState(() => _removingSlotId = null);
    await _afterWindowsChanged();
  }

  Future<void> _afterWindowsChanged() async {
    final open = _openAreaId;
    await _load();
    if (open != null && mounted) await _openArea_(open);
  }

  Future<void> _openArea_(String id) async {
    setState(() {
      _openAreaId = id;
      _openArea = null;
      _form = _Form.none;
      _paneError = null;
    });

    final response = await widget.api.get('/scheduling/areas/$id', const {});

    if (!mounted || _openAreaId != id) return;

    setState(() {
      if (!response.ok) {
        _paneError = response.message;
        return;
      }
      _openArea = response.body['data'] as Map<String, dynamic>?;
      _zipList = (_openArea?['zips'] as List? ?? const [])
          .map(
            // Tolerates the old bare-string shape as well as {zip, city}, so
            // a cached payload from before cities does not crash the pane.
            (z) => z is Map
                ? {'zip': z['zip'] as String, 'city': z['city'] as String?}
                : {'zip': z as String, 'city': null},
          )
          .cast<Map<String, dynamic>>()
          .toList();
      _zipSearch.clear();
      _zipEntry.clear();
      _cityEntry.clear();
      _editingFrom = null;
      _fillGrid((_openArea?['slots'] as List? ?? const []));
      final limits = (_openArea?['daily_limits'] as Map?) ?? const {};
      for (final e in _areaLimitFields.entries) {
        e.value.text = limits['${e.key}']?.toString() ?? '';
      }
    });
  }

  /// Loads [slots] into the week grid: the area's current week, or a week
  /// scheduled ahead. The window fields read their value once, so every day's
  /// generation moves on and they are drawn fresh.
  void _fillGrid(List slots) {
    _grid.clear();
    for (final slot in slots.cast<Map<String, dynamic>>()) {
      (_grid[slot['weekday'] as int] ??= []).add({
        'start_time': slot['start_time'],
        'end_time': slot['end_time'],
        'max_jobs': slot['max_jobs'],
      });
    }
    for (var weekday = 0; weekday < 7; weekday++) {
      _syncDayTotal(weekday);
      _dayGen[weekday] = (_dayGen[weekday] ?? 0) + 1;
    }
  }

  /// Edit the week that takes over from [date]: its saved version if there
  /// is one, else a copy of the current week to change from.
  void _startScheduled(String date) {
    final existing = ((_openArea?['scheduled'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .where((v) => v['starts_on'] == date)
        .firstOrNull;
    setState(() {
      _editingFrom = date;
      _paneError = null;
      _fillGrid(
        (existing?['slots'] as List?) ??
            (_openArea?['slots'] as List? ?? const []),
      );
    });
  }

  void _stopScheduled() => setState(() {
    _editingFrom = null;
    _fillGrid((_openArea?['slots'] as List? ?? const []));
  });

  Future<void> _scheduleNew() async {
    final now = DateTime.now();
    final tomorrow = _ymd(DateTime(now.year, now.month, now.day + 1));
    final picked = await _pickDate(initial: tomorrow);
    if (picked == null || !mounted) return;
    if (picked.compareTo(tomorrow) < 0) {
      setState(
        () => _paneError =
            'A scheduled change starts tomorrow or later. Change the '
            'current week directly.',
      );
      return;
    }
    _startScheduled(picked);
  }

  /// What an area takes on a weekday as saved: its windows' sum, lowered by
  /// its own daily limit. Zero is closed.
  int _areaDay(Map<String, dynamic> area, int weekday) {
    final week = ((area['week'] as Map?)?['$weekday'] as num?)?.toInt() ?? 0;
    final max = ((area['daily_limits'] as Map?)?['$weekday'] as num?)?.toInt();

    return max == null ? week : (week < max ? week : max);
  }

  /// A franchise's weekday as saved: its areas added up, lowered by its own
  /// daily limit.
  int _franchiseDay(String? franchiseId, int weekday) {
    final sum = _areas
        .where((a) => a['franchise_id'] == franchiseId)
        .fold(0, (t, a) => t + _areaDay(a, weekday));
    final max = _franchiseLimits[franchiseId]?[weekday];

    return max == null ? sum : (sum < max ? sum : max);
  }

  Future<void> _saveAreaLimits(String areaId) async {
    final limits = <String, int?>{
      for (final e in _areaLimitFields.entries)
        '${e.key}': int.tryParse(e.value.text.trim()),
    };

    setState(() {
      _savingAreaLimits = true;
      _paneError = null;
    });

    final response = await widget.api.put('/scheduling/areas/$areaId/limits', {
      'limits': limits,
    });
    if (!mounted) return;

    setState(() {
      _savingAreaLimits = false;
      if (!response.ok) {
        _paneError = response.message;
        return;
      }
      final saved = {
        for (final e in limits.entries)
          if (e.value != null) e.key: e.value,
      };
      _openArea = {...?_openArea, 'daily_limits': saved};
      final i = _areas.indexWhere((a) => a['id'] == areaId);
      if (i >= 0) _areas[i] = {..._areas[i], 'daily_limits': saved};
    });
  }

  /// Seven small fields under seven tiles, blank for no limit.
  Widget _limitRow(
    ThemeData theme,
    Map<int, TextEditingController> fields, {
    required String keyPrefix,
    required bool enabled,
  }) {
    return Row(
      children: [
        for (final (weekday, _) in _week) ...[
          if (weekday != 1) const SizedBox(width: 6),
          Expanded(
            child: TextField(
              key: ValueKey('$keyPrefix-$weekday'),
              controller: fields[weekday],
              textAlign: TextAlign.center,
              keyboardType: TextInputType.number,
              enabled: enabled,
              decoration: InputDecoration(
                isDense: true,
                hintText: 'No limit',
                hintStyle: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 4,
                  vertical: 10,
                ),
                border: const OutlineInputBorder(),
              ),
            ),
          ),
        ],
      ],
    );
  }

  void _showOverview() => setState(() {
    _openAreaId = null;
    _openArea = null;
    _form = _Form.none;
    _paneError = null;
    _overviewFranchiseId = null;
  });

  /// One franchise's Overview: its daily totals and its areas (Jose,
  /// 2026-09-25: "i want to be able to have overview for each franchise too").
  void _showFranchise(String? franchiseId) => setState(() {
    _openAreaId = null;
    _openArea = null;
    _form = _Form.none;
    _paneError = null;
    _overviewFranchiseId = franchiseId;
    final limits = _franchiseLimits[franchiseId] ?? const {};
    for (final e in _limitFields.entries) {
      e.value.text = limits[e.key]?.toString() ?? '';
    }
  });

  /// Open the create form.
  ///
  /// The franchise is PRE-FILLED when the view names exactly one and left EMPTY
  /// otherwise, for the form to ask. Refusing to open at company level — which
  /// is what this did — made the answer unreachable: the one control that could
  /// have said "Connecticut" was the one being withheld.
  void _startCreate() {
    final inView = _me?.franchisesInView ?? const [];

    setState(() {
      _form = _Form.create;
      _openAreaId = null;
      _openArea = null;
      _paneError = null;
      _formName.clear();
      _formFranchiseId = inView.length == 1 ? inView.first.id : null;
    });
  }

  void _startRename() {
    setState(() {
      _form = _Form.rename;
      _paneError = null;
      _formName.text = _openArea?['name'] as String? ?? '';
    });
  }

  Future<void> _submitForm() async {
    final name = _formName.text.trim();

    if (name.isEmpty) {
      setState(() => _paneError = 'Give the service area a name.');
      return;
    }

    if (_form == _Form.create && _formFranchiseId == null) {
      setState(
        () => _paneError =
            'Choose which ${_me?.organization.franchiseLabel.toLowerCase() ?? 'franchise'} '
            'this service area belongs to.',
      );
      return;
    }

    setState(() {
      _saving = true;
      _paneError = null;
    });

    final response = _form == _Form.create
        ? await widget.api.post('/scheduling/areas', {
            'franchise_id': _formFranchiseId,
            'name': name,
          })
        : await widget.api.patch('/scheduling/areas/$_openAreaId', {
            'name': name,
          });

    if (!mounted) return;

    if (!response.ok) {
      // Beside the form, not instead of the screen.
      setState(() {
        _saving = false;
        _paneError = response.message;
      });
      return;
    }

    final saved = response.body['data'] as Map<String, dynamic>?;

    setState(() {
      _saving = false;
      _form = _Form.none;
    });

    await _load();

    if (mounted && saved != null) await _openArea_(saved['id'] as String);
  }

  /// Deletes the open area from a side pane that asks where its ZIPs go
  /// (UI queue 125): moved to another area of the same branch, or left
  /// unassigned. The pane names the area and its ZIP count, and Delete stays
  /// off until a choice is made; afterwards a SnackBar says what happened.
  Future<void> _deleteArea() async {
    final id = _openAreaId;
    final area = _openArea;
    if (id == null || area == null) return;
    final name = area['name'] as String? ?? '';
    final franchise = area['franchise_id'];

    final result = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) => AreaDeletePanel(
        api: widget.api,
        areaId: id,
        areaName: name,
        zipCount: _zipList.length,
        siblings: [
          for (final a in _areas)
            if (a['id'] != id && a['franchise_id'] == franchise)
              {'id': a['id'], 'name': a['name']},
        ],
      ),
    );
    if (result == null || !mounted) return;

    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(SnackBar(content: Text(deleteResultLine(name, result))));
    _showOverview();
    await _load();
  }

  /// Writes the zip set, one item's worth of change at a time.
  ///
  /// Every add and every remove saves immediately, so a refusal lands on the
  /// zip that caused it and nothing else moves. The old field took the whole
  /// set as one comma-separated string and saved on a button, which meant a
  /// single zip already held by another area refused FIFTY edits at once and
  /// told you about one of them.
  ///
  /// The server's sentence is shown as it arrives — "75201 is already in
  /// Dallas" — because the useful half is which area to go and look at.
  Future<void> _setZips(List<Map<String, dynamic>> next) async {
    final id = _openAreaId;
    if (id == null) return;

    final previous = _zipList;

    setState(() {
      _saving = true;
      _paneError = null;
      _zipList = next;
    });

    final response = await widget.api.put('/scheduling/areas/$id/zips', {
      'zips': next,
    });

    if (!mounted) return;

    setState(() {
      _saving = false;

      if (!response.ok) {
        // Put the list back. A chip the server refused must not sit there
        // looking saved.
        _zipList = previous;
        _paneError = response.message;
        return;
      }

      _openArea = {...?_openArea, 'zips': next};
      _zipEntry.clear();
      _cityEntry.clear();
    });

    if (response.ok) await _load();
  }

  /// Adds the typed zip, with the typed city if there is one.
  ///
  /// The city is optional and is a second way to FIND this area — Availability
  /// can be searched by it — so a zip without one is still a perfectly good
  /// zip. A paste of several zips is still accepted; they all take the city in
  /// the box, which is the common case for pasting one city's zips.
  Future<void> _addZip(String raw) async {
    final city = _cityEntry.text.trim();

    final entered = raw
        .split(RegExp(r'[\s,;]+'))
        .map((z) => z.trim())
        .where((z) => z.isNotEmpty)
        .toList();

    if (entered.isEmpty) {
      setState(() => _paneError = 'Type a zip code to add.');
      return;
    }

    final held = _zipList.map((z) => z['zip'] as String).toSet();
    final fresh = entered.where((z) => !held.contains(z)).toList();

    if (fresh.isEmpty) {
      setState(() {
        _paneError = entered.length == 1
            ? '${entered.first} is already in this area.'
            : 'Those are already in this area.';
      });
      return;
    }

    await _setZips([
      ..._zipList,
      for (final zip in fresh) {'zip': zip, 'city': city.isEmpty ? null : city},
    ]);
  }

  Future<void> _removeZip(String zip) =>
      _setZips(_zipList.where((z) => z['zip'] != zip).toList());

  Future<void> _saveGrid() async {
    final id = _openAreaId;
    if (id == null) return;

    setState(() {
      _saving = true;
      _paneError = null;
    });

    // Flattened from the map, so a weekday absent from `_grid` sends no rows
    // at all. That absence IS the closure — there is no zero to write.
    final slots = <Map<String, dynamic>>[
      for (final entry in _grid.entries)
        for (final slot in entry.value)
          // A blank window is one this day does not offer. It is left out,
          // never sent as a zero.
          if (slot['max_jobs'] != null)
            {
              'weekday': entry.key,
              'start_time': slot['start_time'],
              'end_time': slot['end_time'],
              'max_jobs': slot['max_jobs'],
            },
    ];

    final from = _editingFrom;
    if (from != null) {
      final response = await widget.api.put(
        '/scheduling/areas/$id/scheduled/$from',
        {'slots': slots},
      );
      if (!mounted) return;
      setState(() {
        _saving = false;
        if (!response.ok) {
          _paneError = response.message;
          return;
        }
        _openArea = response.body['data'] as Map<String, dynamic>?;
        _editingFrom = null;
        _fillGrid((_openArea?['slots'] as List? ?? const []));
      });
      return;
    }

    final response = await widget.api.put('/scheduling/areas/$id/slots', {
      'slots': slots,
    });

    if (!mounted) return;

    setState(() {
      _saving = false;
      if (!response.ok) _paneError = response.message;
    });

    if (response.ok) {
      await _load();
      if (mounted) await _openArea_(id);
    }
  }

  /// Deletes the day's rows. Never writes zeros — see the class docblock.
  void _closeDay(int weekday) => setState(() => _grid.remove(weekday));

  int _sumOf(int weekday) => (_grid[weekday] ?? const []).fold(
    0,
    (sum, r) => sum + ((r['max_jobs'] as num?)?.toInt() ?? 0),
  );

  void _syncDayTotal(int weekday) {
    final sum = _sumOf(weekday);
    (_dayTotal[weekday] ??= TextEditingController()).text = sum > 0
        ? '$sum'
        : '';
  }

  /// "10 on Monday" becomes 4/3/3 over three windows: A1's
  /// `capacityForBlock` — equal shares, and the earliest windows take the
  /// remainder one each. So 4 over three is 2/1/1, and 2 over three is 1/1
  /// with the last window not offered (never a zero; see the top of the file).
  /// Spread over the grid's columns, so the Day box always equals the row.
  void _splitDay(int weekday, String value) {
    final n = int.tryParse(value.trim());
    if (n == null || n <= 0) return;

    final windows = _columns;
    if (windows.isEmpty) return;

    final base = n ~/ windows.length;
    final extra = n % windows.length;

    setState(() {
      _grid[weekday] = [
        for (var i = 0; i < windows.length; i++)
          {
            'start_time': windows[i].$1,
            'end_time': windows[i].$2,
            'max_jobs': (base + (i < extra ? 1 : 0)) > 0
                ? base + (i < extra ? 1 : 0)
                : null,
          },
      ];
      _dayGen[weekday] = (_dayGen[weekday] ?? 0) + 1;
    });
  }

  /// Opening a day offers EVERY window at one job each, to be changed.
  ///
  /// It used to write a hard-coded 08:00–12:00, which is why all 108 of A-1's
  /// capacity rows were that window: there was nowhere else for a window to
  /// come from. Now they come from the Time slots card, and with none stated
  /// there is nothing to open a day onto — the editor says so instead.
  void _openDay(int weekday) => setState(() {
    _grid[weekday] = [
      for (final w in _timeSlots)
        {
          'start_time': w['start_time'],
          'end_time': w['end_time'],
          'max_jobs': 1,
        },
    ];
    _syncDayTotal(weekday);
  });

  /// The week's columns: the organization's windows, plus any this area already
  /// has capacity on that the list does not name. The second set should be
  /// empty — changing a window moves its rows — but a row the list does not
  /// know about must still be visible, or it is counted by availability and
  /// cannot be seen or removed from the screen that set it.
  ///
  /// One set for every day, so the editor is a grid: a day without capacity
  /// on a column shows that cell blank.
  List<(String, String)> get _columns {
    final seen = <String>{};
    final out = <(String, String)>[];

    void add(String start, String end) {
      if (seen.add('$start-$end')) out.add((start, end));
    }

    for (final w in _timeSlots) {
      add(w['start_time'] as String, w['end_time'] as String);
    }
    for (final rows in _grid.values) {
      for (final row in rows) {
        add(row['start_time'] as String, row['end_time'] as String);
      }
    }

    out.sort((a, b) => a.$1.compareTo(b.$1));

    return out;
  }

  List<Map<String, dynamic>> get _filtered {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return _areas;

    return _areas
        .where((a) => (a['name'] as String? ?? '').toLowerCase().contains(q))
        .toList();
  }

  /// Filtered by zip OR city, because "everything in Plano" is as likely a
  /// question as "is 75201 in here".
  List<Map<String, dynamic>> get _visibleZips {
    final q = _zipSearch.text.trim().toLowerCase();
    final sorted = [..._zipList]
      ..sort((a, b) => (a['zip'] as String).compareTo(b['zip'] as String));

    if (q.isEmpty) return sorted;

    return sorted.where((z) {
      final zip = (z['zip'] as String).toLowerCase();
      final city = ((z['city'] as String?) ?? '').toLowerCase();

      return zip.contains(q) || city.contains(q);
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= CapacitySettings.splitAbove;
        final hasPane =
            _openAreaId != null ||
            _form != _Form.none ||
            _overviewFranchiseId != null;
        final paneOnly = !wide && hasPane;

        return AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: paneOnly
              ? _pane(showBack: true)
              : wide
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(width: 236, child: _rail()),
                    VerticalDivider(
                      width: 1,
                      thickness: 1,
                      color: EzBiziTheme.edge(context),
                    ),
                    Expanded(child: _pane(showBack: false)),
                  ],
                )
              // Below 600 the rail IS the screen until something is selected,
              // which is the sub-600 rule Web Management already ships.
              : _rail(),
        );
      },
    );
  }

  Widget _rail() {
    final theme = Theme.of(context);
    final areas = _filtered;

    return Column(
      key: const ValueKey('tour-scheduling-areas'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 4, 12),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _search,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(
                    isDense: true,
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Search areas',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
              IconButton(
                icon: const Icon(Icons.add),
                tooltip: 'Add service area',
                onPressed: _startCreate,
              ),
            ],
          ),
        ),
        // Overview is pinned above the list and never scrolls away.
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: ListTile(
            dense: true,
            selected:
                _openAreaId == null &&
                _form == _Form.none &&
                _overviewFranchiseId == null,
            title: const Text('Overview'),
            subtitle: const Text('Every area, and the time slots'),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(8),
            ),
            onTap: _showOverview,
          ),
        ),
        const Divider(height: 9),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 6),
          child: Text(
            areas.length == 1
                ? '1 service area'
                : '${areas.length} service areas',
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.only(bottom: 12),
            children: _railRows(theme, areas),
          ),
        ),
      ],
    );
  }

  /// The rail, grouped by the franchise each area belongs to.
  ///
  /// ── Why this is not one flat list ────────────────────────────────
  ///
  /// At company or organization scope the rail holds every area of every
  /// franchise in view — twenty-six of them for the business this is built
  /// for — and nothing on the row said which franchise owned it. "Atlanta
  /// North" and "East Connecticut" sat adjacent as if they were alternatives,
  /// and the same area name can legitimately appear under two franchises, so
  /// there was no way to tell those apart at all.
  ///
  /// The header is the FRANCHISE, and it carries its company as well whenever
  /// more than one company is on screen — the same rule `FranchiseField` uses
  /// and for the same reason: four franchises called Connecticut under
  /// different companies is real data here, and the bare name is ambiguous
  /// exactly then. Borrowed rather than reinvented so the two controls cannot
  /// disagree about what a place is called.
  ///
  /// One group means no headers. At franchise scope there is nothing to
  /// disambiguate and a single header would be a label on the whole screen.
  List<Widget> _railRows(ThemeData theme, List<Map<String, dynamic>> areas) {
    final me = _me;

    // Grouped by franchise id, in the order the server sent (already by name).
    final groups = <String?, List<Map<String, dynamic>>>{};

    for (final area in areas) {
      (groups[area['franchise_id'] as String?] ??= []).add(area);
    }

    // Whether a header needs its company. Read off the franchises actually
    // ON SCREEN, not off everything reachable — a rail showing one company's
    // franchises should not carry that company's name on every row.
    final shown = me == null
        ? const <FranchiseSummary>[]
        : me.franchises.where((f) => groups.containsKey(f.id)).toList();

    final ambiguous = shown.map((f) => f.companyId).toSet().length > 1;

    final entries = groups.entries.toList();

    if (me != null) {
      entries.sort((a, b) {
        final left = _railGroupLabel(me, a.key, ambiguous: ambiguous);
        final right = _railGroupLabel(me, b.key, ambiguous: ambiguous);

        return left.compareTo(right);
      });
    }

    final rows = <Widget>[];

    for (final entry in entries) {
      if (entries.length > 1 && me != null) {
        rows.add(
          _railHeader(
            theme,
            _railGroupLabel(me, entry.key, ambiguous: ambiguous),
            entry.key,
          ),
        );
      }

      for (final area in entry.value) {
        rows.add(_railTile(area));
      }
    }

    return rows;
  }

  /// The franchise a group of areas belongs to, named so it cannot be confused
  /// with another franchise of the same name under a different company.
  String _railGroupLabel(
    Me me,
    String? franchiseId, {
    required bool ambiguous,
  }) {
    final franchise = me.franchises
        .where((f) => f.id == franchiseId)
        .firstOrNull;

    // A franchise the session does not list. Says so rather than showing a
    // blank heading: an area filed under something out of view is a real
    // state — reach can be narrowed after the area was made — and a heading
    // reading nothing at all looks like a rendering fault.
    if (franchise == null) return 'Elsewhere';

    return ambiguous ? franchisePath(me, franchise) : franchise.name;
  }

  /// A franchise's heading, which opens that franchise's Overview.
  Widget _railHeader(ThemeData theme, String label, String? franchiseId) {
    final selected =
        franchiseId != null &&
        _overviewFranchiseId == franchiseId &&
        _openAreaId == null &&
        _form == _Form.none;

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            key: ValueKey('rail-franchise-$franchiseId'),
            borderRadius: BorderRadius.circular(6),
            onTap: franchiseId == null
                ? null
                : () => _showFranchise(franchiseId),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 4, 4, 4),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      label.toUpperCase(),
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: selected
                            ? theme.colorScheme.primary
                            : theme.colorScheme.onSurfaceVariant,
                        letterSpacing: 0.6,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  if (franchiseId != null)
                    Icon(
                      Icons.chevron_right,
                      size: 16,
                      color: selected
                          ? theme.colorScheme.primary
                          : theme.colorScheme.onSurfaceVariant,
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 4),
          Divider(height: 1, color: EzBiziTheme.edge(context)),
        ],
      ),
    );
  }

  Widget _railTile(Map<String, dynamic> area) {
    final differs = area['follows_pattern'] == false;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: ListTile(
        dense: true,
        selected: _openAreaId == area['id'],
        title: Text(area['name'] as String? ?? ''),
        subtitle: Text(
          '${area['zip_count'] ?? 0} zips${differs ? ' · differs' : ''}',
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        onTap: () => _openArea_(area['id'] as String),
      ),
    );
  }

  Widget _pane({required bool showBack}) {
    if (_form != _Form.none) return _formPane(showBack: showBack);

    if (_openAreaId != null) return _areaPane(showBack: showBack);

    return _overviewFranchiseId == null
        ? _overview()
        : _franchiseOverview(_overviewFranchiseId!, showBack: showBack);
  }

  /// Add or rename, in the pane.
  ///
  /// Rename does NOT offer the franchise. Which franchise owns an area decides
  /// whose numbers its work counts in, and §5.2 makes moving one a separate
  /// audited step rather than a field on a rename form — the same reason
  /// FranchiseField exists rather than a bare dropdown of names.
  Widget _formPane({required bool showBack}) {
    final theme = Theme.of(context);
    final me = _me;
    final creating = _form == _Form.create;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
      children: [
        Row(
          children: [
            if (showBack)
              IconButton(
                icon: const Icon(Icons.arrow_back),
                tooltip: 'Back',
                onPressed: _showOverview,
              ),
            Expanded(
              child: Text(
                creating ? 'New service area' : 'Rename service area',
                style: theme.textTheme.titleMedium,
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          creating
              ? 'A place this business works — Dallas, Houston. Give it zip '
                    'codes and a week once it exists.'
              : 'Only the name. Moving an area to another '
                    '${me?.organization.franchiseLabel.toLowerCase() ?? 'franchise'} '
                    'changes whose work it counts in, so it is a separate step.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          key: const Key('scheduling-area-name'),
          controller: _formName,
          autofocus: true,
          textInputAction: TextInputAction.done,
          onSubmitted: (_) => _submitForm(),
          decoration: const InputDecoration(
            labelText: 'Name',
            hintText: 'Dallas',
            border: OutlineInputBorder(),
          ),
        ),
        if (creating && me != null) ...[
          const SizedBox(height: 16),
          // Asks rather than refuses. At company level this is the control that
          // answers the question the screen used to refuse to open over.
          FranchiseField(
            key: const Key('scheduling-area-franchise'),
            me: me,
            value: _formFranchiseId,
            onChanged: (id) => setState(() => _formFranchiseId = id),
            helperText: 'Which one this area belongs to.',
          ),
        ],
        if (_paneError != null) ...[
          const SizedBox(height: 16),
          ErrorNote(_paneError!),
        ],
        const SizedBox(height: 20),
        Row(
          children: [
            FilledButton(
              onPressed: _saving ? null : _submitForm,
              child: Text(
                _saving
                    ? 'Saving…'
                    : creating
                    ? 'Add service area'
                    : 'Save name',
              ),
            ),
            const SizedBox(width: 12),
            TextButton(
              onPressed: _saving
                  ? null
                  : () {
                      if (creating) {
                        _showOverview();
                      } else {
                        setState(() {
                          _form = _Form.none;
                          _paneError = null;
                        });
                      }
                    },
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }

  /// Every area's week at once.
  ///
  /// Jose asked for this directly, and it replaces three cards that were each
  /// answering a smaller question: the slot pattern, an Add button the rail
  /// already has, and a list of which areas differ from the pattern. The last
  /// one is the interesting deletion — a table showing every area's days SHOWS
  /// the differences, so a card naming them was a worse version of the same
  /// answer. "It is normal for them not to be the same" was the reason given,
  /// and a screen that treats difference as an exception to be listed argues
  /// the opposite.
  ///
  /// A closed day is a dash, not a zero. `week[weekday]` is null when the area
  /// has no rows for that day, and the storage cannot express "open and takes
  /// nothing" — see the class docblock.
  Widget _overview() {
    final theme = Theme.of(context);

    return _withWeekTable(
      theme,
      _areas,
      top: const EdgeInsets.fromLTRB(20, 18, 20, 0),
      [
        _timeSlotsCard(theme),
        if (_areas.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(
            'DAILY TOTALS',
            style: theme.textTheme.labelSmall?.copyWith(
              letterSpacing: 0.8,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 6),
          // Every franchise's day, each already lowered by its own limit and
          // its areas' limits, added up (Jose, 2026-09-28).
          _dayTiles(
            theme,
            keyPrefix: 'overview-total',
            total: (weekday) => {
              for (final a in _areas) a['franchise_id'] as String?,
            }.fold(0, (sum, f) => sum + _franchiseDay(f, weekday)),
          ),
          const SizedBox(height: 6),
          Text(
            'Every area in view added up, as saved, after each area\'s and '
            'each franchise\'s daily limit.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 18),
        ] else
          const SizedBox(height: 8),
        Text('Capacity', style: theme.textTheme.titleMedium),
        const SizedBox(height: 2),
        Text(
          'Jobs each service area can take per day.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 16),
        if (_paneError != null) ...[
          ErrorNote(_paneError!),
          const SizedBox(height: 12),
        ],
        if (_areas.isEmpty)
          Text(
            'No service areas in this view. Add one with + above the list to '
            'say where this business works.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
      ],
    );
  }

  /// [above], then the week table with its weekday row PINNED: Jose, on a
  /// long Overview, "once you go down u dont know what day ur looking at
  /// anymore". Slivers rather than a ListView because only a sliver can stay
  /// put while the rows under it scroll.
  Widget _withWeekTable(
    ThemeData theme,
    List<Map<String, dynamic>> areas,
    List<Widget> above, {
    required EdgeInsets top,
    Key? key,
  }) {
    return LayoutBuilder(
      builder: (context, box) => CustomScrollView(
        key: key,
        slivers: [
          SliverPadding(
            padding: top,
            sliver: SliverList(delegate: SliverChildListDelegate(above)),
          ),
          if (areas.isNotEmpty)
            ..._weekTable(theme, areas, box.maxWidth - 40).map(
              (sliver) => SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                sliver: sliver,
              ),
            ),
          const SliverToBoxAdapter(child: SizedBox(height: 32)),
        ],
      ),
    );
  }

  /// One franchise: its week added up across its areas, then its areas.
  Widget _franchiseOverview(String franchiseId, {required bool showBack}) {
    final theme = Theme.of(context);
    final areas = [
      for (final a in _areas)
        if (a['franchise_id'] == franchiseId) a,
    ];
    final name =
        _me?.franchises.where((f) => f.id == franchiseId).firstOrNull?.name ??
        'Franchise';

    int total(int weekday) =>
        areas.fold(0, (sum, a) => sum + _areaDay(a, weekday));

    return _withWeekTable(
      theme,
      areas,
      key: const Key('scheduling-franchise-overview'),
      top: const EdgeInsets.fromLTRB(20, 12, 20, 0),
      [
        Row(
          children: [
            if (showBack)
              IconButton(
                icon: const Icon(Icons.arrow_back),
                tooltip: 'Areas',
                onPressed: _showOverview,
              ),
            Expanded(child: Text(name, style: theme.textTheme.titleMedium)),
            Text(
              '${areas.length} ${areas.length == 1 ? 'area' : 'areas'}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        Text(
          'DAILY TOTALS',
          style: theme.textTheme.labelSmall?.copyWith(
            letterSpacing: 0.8,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 6),
        _dayTiles(
          theme,
          keyPrefix: 'franchise-total',
          total: total,
          under: (weekday) {
            final max = _franchiseLimits[franchiseId]?[weekday];

            return max == null ? null : 'max $max';
          },
        ),
        const SizedBox(height: 6),
        Text(
          'Every $name area added up, as saved. A day is closed only when '
          'every area is.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 18),
        Text(
          'DAILY LIMIT FOR $name'.toUpperCase(),
          style: theme.textTheme.labelSmall?.copyWith(
            letterSpacing: 0.8,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 6),
        // Under the tiles, one field per day, so each limit sits beneath the
        // total it caps.
        Row(
          children: [
            for (final (weekday, _) in _week) ...[
              if (weekday != 1) const SizedBox(width: 6),
              Expanded(
                child: TextField(
                  key: ValueKey('franchise-limit-$weekday'),
                  controller: _limitFields[weekday],
                  textAlign: TextAlign.center,
                  keyboardType: TextInputType.number,
                  enabled: !_savingLimits,
                  decoration: InputDecoration(
                    isDense: true,
                    hintText: 'No limit',
                    hintStyle: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    contentPadding: const EdgeInsets.symmetric(
                      horizontal: 4,
                      vertical: 10,
                    ),
                    border: const OutlineInputBorder(),
                  ),
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 8),
        Text(
          'The most jobs $name takes that day across all its areas, since '
          'they share technicians. Once that many are booked, Availability '
          "stops offering the day in every $name area, whatever an area has "
          'left. It never raises an area\'s own numbers. Blank is no limit.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        if (_paneError != null) ...[
          const SizedBox(height: 10),
          ErrorNote(_paneError!),
        ],
        const SizedBox(height: 10),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: _savingLimits ? null : () => _saveLimits(franchiseId),
            child: Text(_savingLimits ? 'Saving…' : 'Save $name limit'),
          ),
        ),
        const SizedBox(height: 18),
      ],
    );
  }

  /// Areas down, weekdays across, grouped by franchise the way the rail is,
  /// each franchise headed by its own daily totals — A1's "Daily totals — NY",
  /// so nobody adds a franchise's areas up by hand (Jose, 2026-09-25: "i don't
  /// need to do math").
  ///
  /// An area with no capacity on any day says "Not set up" across its week
  /// rather than seven dashes: that is the one row somebody has to act on, and
  /// seven dashes read the same as an area closed all week.
  ///
  /// Every cell is the same size (Jose: "you always make that mistake"), and
  /// narrows with the pane instead of scrolling sideways — a sideways scroll
  /// and a pinned header row cannot both hold without two scroll positions to
  /// keep in step.
  List<Widget> _weekTable(
    ThemeData theme,
    List<Map<String, dynamic>> shownAreas,
    double width,
  ) {
    final muted = theme.colorScheme.onSurfaceVariant;
    // 52 per day where there is room for a readable name beside them;
    // narrower, down to 28, where there is not.
    final cellWidth = (width - 24 - 7 * 52 >= 140)
        ? 52.0
        : ((width - 24 - 110) / 7).clamp(28.0, 52.0);
    const cellHeight = 30.0;
    final ok = ToneColours.of(context, Tone.ok);
    final neutral = ToneColours.of(context, Tone.neutral);
    final caution = ToneColours.of(context, Tone.caution);

    int? jobs(Map<String, dynamic> area, int weekday) =>
        ((area['week'] as Map?)?['$weekday'] as num?)?.toInt();

    // The server's word when it sends one: a week saved closed every day is
    // set up, closed (Mia, 2026-10-02). An older server: any open day.
    bool setUp(Map<String, dynamic> area) =>
        area['configured'] as bool? ??
        _week.any((d) => jobs(area, d.$1) != null);

    final groups = _groupedAreas(shownAreas);
    final notSetUp = shownAreas.where((a) => !setUp(a)).length;

    Widget tile(String text, {ToneColours? tone, bool bold = false}) =>
        Container(
          width: cellWidth - 6,
          height: cellHeight,
          margin: const EdgeInsets.symmetric(horizontal: 3),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: tone?.fill,
            borderRadius: BorderRadius.circular(6),
          ),
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              text,
              style: theme.textTheme.bodySmall?.copyWith(
                color: tone?.ink ?? muted,
                fontWeight: bold ? FontWeight.w800 : FontWeight.w600,
              ),
            ),
          ),
        );

    Widget row({
      required Widget name,
      required List<Widget> cells,
      VoidCallback? onTap,
      Color? fill,
      Key? key,
    }) => Material(
      key: key,
      color: fill ?? Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          decoration: BoxDecoration(
            border: Border(
              bottom: BorderSide(color: EzBiziTheme.edge(context)),
            ),
          ),
          child: Row(
            children: [
              Expanded(child: name),
              ...cells,
            ],
          ),
        ),
      ),
    );

    List<Widget> week(Map<String, dynamic> area) {
      if (!setUp(area)) {
        return [
          Container(
            width: cellWidth * 7,
            height: cellHeight,
            alignment: Alignment.center,
            margin: const EdgeInsets.symmetric(horizontal: 3),
            decoration: BoxDecoration(
              color: caution.fill,
              borderRadius: BorderRadius.circular(6),
            ),
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                'Not set up · open it to add capacity',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: caution.ink,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ),
        ];
      }

      return [
        for (final (weekday, _) in _week)
          jobs(area, weekday) == null
              ? tile('—')
              : tile('${jobs(area, weekday)}', tone: ok),
      ];
    }

    final header = row(
      key: const Key('overview-weekdays'),
      fill: theme.colorScheme.surface,
      name: Text(
        'Area',
        style: theme.textTheme.labelSmall?.copyWith(color: muted),
      ),
      cells: [
        for (final (_, label) in _week)
          SizedBox(
            width: cellWidth,
            child: Text(
              label,
              textAlign: TextAlign.center,
              style: theme.textTheme.labelSmall?.copyWith(color: muted),
            ),
          ),
      ],
    );
    final rows = <Widget>[];

    for (final (label, areas) in groups) {
      if (label != null) {
        rows.add(
          row(
            key: ValueKey('overview-franchise-$label'),
            fill: neutral.fill.withValues(alpha: 0.5),
            name: Text(
              label,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
            cells: [
              for (final (weekday, _) in _week)
                Builder(
                  builder: (context) {
                    final open = areas.where((a) => jobs(a, weekday) != null);
                    final total = open.fold(
                      0,
                      (sum, a) => sum + jobs(a, weekday)!,
                    );

                    return open.isEmpty
                        ? tile('—')
                        : tile('$total', tone: neutral, bold: true);
                  },
                ),
            ],
          ),
        );
      }

      for (final area in areas) {
        rows.add(
          row(
            key: ValueKey('overview-area-${area['id']}'),
            // The row is the way in, so the table does the rail's job for
            // somebody already reading it.
            onTap: () => _openArea_(area['id'] as String),
            name: Text(
              area['name'] as String? ?? '',
              style: theme.textTheme.bodyMedium,
              overflow: TextOverflow.ellipsis,
            ),
            cells: week(area),
          ),
        );
      }
    }

    return [
      if (notSetUp > 0)
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 6,
                ),
                decoration: BoxDecoration(
                  color: caution.fill,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  '$notSetUp of ${shownAreas.length} areas have no capacity '
                  'yet, so Availability cannot offer them.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: caution.ink,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ),
          ),
        ),
      PinnedHeaderSliver(child: SizedBox(height: 43, child: header)),
      SliverList(delegate: SliverChildListDelegate(rows)),
      SliverToBoxAdapter(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(4, 8, 4, 0),
          child: Text(
            'A dash is a day that area is closed. Franchise rows add up '
            'their areas. Tap an area to change it.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
        ),
      ),
    ];
  }

  /// The areas in franchise groups, in the rail's order, each labelled the way
  /// the rail labels it. One group comes back with a null label: at franchise
  /// scope a header would only name the whole screen.
  List<(String?, List<Map<String, dynamic>>)> _groupedAreas(
    List<Map<String, dynamic>> areas,
  ) {
    final me = _me;
    final groups = <String?, List<Map<String, dynamic>>>{};

    for (final area in areas) {
      (groups[area['franchise_id'] as String?] ??= []).add(area);
    }

    if (me == null || groups.length < 2) {
      return [for (final g in groups.values) (null, g)];
    }

    final shown = me.franchises.where((f) => groups.containsKey(f.id));
    final ambiguous = shown.map((f) => f.companyId).toSet().length > 1;

    return [
      for (final e in groups.entries)
        (_railGroupLabel(me, e.key, ambiguous: ambiguous), e.value),
    ]..sort((a, b) => a.$1!.compareTo(b.$1!));
  }

  Widget _areaPane({required bool showBack}) {
    final theme = Theme.of(context);
    final area = _openArea;

    if (area == null) {
      return _paneError == null
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(20),
              child: ErrorNote(_paneError!),
            );
    }

    final rail = _areas.firstWhere(
      (a) => a['id'] == _openAreaId,
      orElse: () => const {},
    );
    final differs = rail['follows_pattern'] == false;

    return ListView(
      // Keyed so a test can scroll THIS list rather than the rail, which is
      // the other Scrollable on screen.
      key: const Key('scheduling-area-pane'),
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
      children: [
        Row(
          children: [
            if (showBack)
              IconButton(
                icon: const Icon(Icons.arrow_back),
                tooltip: 'Areas',
                onPressed: _showOverview,
              ),
            Expanded(
              child: Text(
                area['name'] as String? ?? '',
                style: theme.textTheme.titleMedium,
              ),
            ),
            Text(
              '${_zipList.length} zips',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
        // The exception is shown HERE, not only in the Overview list, because
        // somebody who navigated straight to this area to change a number is
        // exactly the person who has not opened the Overview.
        if (differs)
          Container(
            margin: const EdgeInsets.only(top: 10),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: theme.colorScheme.secondaryContainer,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'This area does not follow the pattern. Applying the pattern to '
              'every area would change it back — and would say so first.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        if (_paneError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_paneError!),
        ],
        // A member books into its lead's pool: its own week, limits and
        // dates are never read, so they are not offered here.
        if (area['shares_with_id'] == null) ...[
          const SizedBox(height: 14),
          _dailyTotals(theme, area),
          const SizedBox(height: 14),
          Text(
            'DAILY LIMIT FOR ${area['name']}'.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              letterSpacing: 0.8,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 6),
          _limitRow(
            theme,
            _areaLimitFields,
            keyPrefix: 'area-limit',
            enabled: !_savingAreaLimits,
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              Expanded(
                child: Text(
                  'The most jobs ${area['name']} takes that day, whatever its '
                  'time slots add up to. It only ever lowers a day. Blank is no '
                  'limit.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton(
                onPressed: _savingAreaLimits
                    ? null
                    : () => _saveAreaLimits(area['id'] as String),
                child: Text(_savingAreaLimits ? 'Saving…' : 'Save limit'),
              ),
            ],
          ),
          const SizedBox(height: 18),
          Text(
            "Type a day's total and it splits across the time slots, earliest "
            'first — or set each slot yourself. A blank slot is not offered '
            'that day.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 10),
          if (_editingFrom != null)
            Container(
              key: const Key('scheduled-banner'),
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: ToneColours.of(context, Tone.info).fill,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                'Editing the week that takes over on '
                '${_prettyDate(_editingFrom!)}. It replaces the current week '
                'automatically that day and stays until the next change. Days '
                'before it keep the current week.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: ToneColours.of(context, Tone.info).ink,
                ),
              ),
            ),
          _weekGrid(theme),
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerLeft,
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FilledButton(
                  onPressed: _saving ? null : _saveGrid,
                  child: Text(
                    _saving
                        ? 'Saving…'
                        : _editingFrom == null
                        ? 'Save ${area['name']}'
                        : 'Save the week from ${_prettyDate(_editingFrom!)}',
                  ),
                ),
                if (_editingFrom != null)
                  TextButton(
                    onPressed: _saving ? null : _stopScheduled,
                    child: const Text('Cancel'),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 24),
          _scheduledCard(theme),
          const SizedBox(height: 8),
          _datesCard(theme),
        ],
        const SizedBox(height: 8),
        _zipCard(theme),
        const SizedBox(height: 8),
        _poolCard(theme, area),
        const SizedBox(height: 8),
        Row(
          children: [
            TextButton.icon(
              onPressed: _startRename,
              icon: const Icon(Icons.edit_outlined, size: 18),
              label: const Text('Rename'),
            ),
            const SizedBox(width: 8),
            TextButton.icon(
              onPressed: _deleteArea,
              icon: const Icon(Icons.delete_outline, size: 18),
              label: const Text('Delete area'),
              style: TextButton.styleFrom(
                foregroundColor: theme.colorScheme.error,
              ),
            ),
          ],
        ),
      ],
    );
  }

  // ── Specific dates and "not taking bookings before" ──────────────

  /// The date being edited in the inline editor, or null.
  String? _dateEditing;
  bool _dateClosed = false;

  /// `start-end` -> what was typed for that window on that date.
  final Map<String, String> _dateValues = {};

  static String _prettyDate(String ymd) {
    final d = DateTime.parse(ymd);
    return '${shortWeekdays[d.weekday - 1]}, ${shortMonths[d.month - 1]} ${d.day}';
  }

  /// Weeks scheduled to take over from a date (Eve, 2026-09-29: "change
  /// automatically starting from October 30, going forward").
  Widget _scheduledCard(ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final versions = ((_openArea?['scheduled'] as List?) ?? const [])
        .cast<Map<String, dynamic>>();

    String summary(List slots) {
      final byDay = <int, int>{};
      for (final s in slots.cast<Map<String, dynamic>>()) {
        byDay[s['weekday'] as int] =
            (byDay[s['weekday'] as int] ?? 0) +
            ((s['max_jobs'] as num).toInt());
      }
      return [
        for (final (weekday, label) in _week) '$label ${byDay[weekday] ?? '–'}',
      ].join(' · ');
    }

    return _card(
      theme,
      title: 'Scheduled changes',
      badge: versions.isEmpty ? 'None' : '${versions.length}',
      action: _editingFrom != null
          ? null
          : OutlinedButton.icon(
              key: const Key('schedule-change'),
              onPressed: _saving ? null : _scheduleNew,
              icon: const Icon(Icons.event_repeat, size: 18),
              label: const Text('Schedule a change'),
            ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'A new week that takes over automatically from a date, and stays '
            'until the next change. A specific date below still wins on its '
            'own day.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          const SizedBox(height: 8),
          if (versions.isEmpty)
            Text(
              'Nothing scheduled.',
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          for (final v in versions)
            Container(
              key: ValueKey('scheduled-${v['starts_on']}'),
              margin: const EdgeInsets.symmetric(vertical: 3),
              padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
              decoration: BoxDecoration(
                border: Border.all(
                  color: _editingFrom == v['starts_on']
                      ? theme.colorScheme.primary
                      : EzBiziTheme.edge(context),
                ),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'From ${_prettyDate(v['starts_on'] as String)}',
                          style: theme.textTheme.titleSmall,
                        ),
                        Text(
                          summary(v['slots'] as List? ?? const []),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: muted,
                          ),
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: 'Edit this week',
                    icon: const Icon(Icons.edit_outlined, size: 18),
                    onPressed: _saving
                        ? null
                        : () => _startScheduled(v['starts_on'] as String),
                  ),
                  IconButton(
                    tooltip: 'Cancel this change',
                    icon: const Icon(Icons.delete_outline, size: 18),
                    onPressed: _saving
                        ? null
                        : () async {
                            if (_editingFrom == v['starts_on']) {
                              _stopScheduled();
                            }
                            await _areaWrite(
                              (id) => widget.api.delete(
                                '/scheduling/areas/$id/scheduled/${v['starts_on']}',
                              ),
                            );
                          },
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  static String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-'
      '${d.month.toString().padLeft(2, '0')}-'
      '${d.day.toString().padLeft(2, '0')}';

  /// A date picker, never a typed date. Jose, on A1 Tools: "i want a date
  /// picker, not the yyyy--mm-dd shit".
  Future<String?> _pickDate({String? initial}) async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: initial == null ? now : DateTime.parse(initial),
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: DateTime(now.year + 2),
    );
    return picked == null ? null : _ymd(picked);
  }

  Future<void> _areaWrite(Future<ApiResponse> Function(String id) call) async {
    final id = _openAreaId;
    if (id == null) return;

    setState(() {
      _saving = true;
      _paneError = null;
    });

    final response = await call(id);
    if (!mounted) return;

    setState(() {
      _saving = false;
      if (!response.ok) {
        _paneError = response.message;
        return;
      }
      _openArea = response.body['data'] as Map<String, dynamic>?;
      _dateEditing = null;
    });

    if (response.ok) await _load();
  }

  Future<void> _setSharesWith(String? leadId) => _areaWrite(
    (id) => widget.api.put('/scheduling/areas/$id/shares-with', {
      'shares_with_id': leadId,
    }),
  );

  /// One availability pool for several areas (Eve, 2026-09-29: "give 2
  /// service areas, the same availability pool"). A member uses its lead's
  /// week, dates and limits; a job in any area of the pool takes its spots.
  /// One level only, same franchise — the server refuses anything else.
  Widget _poolCard(ThemeData theme, Map<String, dynamic> area) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final name = area['name'] as String? ?? '';
    final leadId = area['shares_with_id'] as String?;
    final lead =
        _areas.firstWhere(
              (a) => a['id'] == leadId,
              orElse: () => const {},
            )['name']
            as String? ??
        'another area';
    final sharers = [
      for (final a in _areas)
        if (a['shares_with_id'] == area['id']) a['name'] as String,
    ];
    final leads = [
      for (final a in _areas)
        if (a['id'] != area['id'] &&
            a['franchise_id'] == area['franchise_id'] &&
            a['shares_with_id'] == null)
          a,
    ];

    return _card(
      theme,
      title: 'Shared availability',
      badge: leadId != null
          ? 'Shared'
          : sharers.isNotEmpty
          ? '${sharers.length + 1} areas'
          : 'Own',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            leadId != null
                ? "$name books into $lead's availability: its week, dates and "
                      'limits. A job in either area takes the same spots. '
                      'Change them on $lead.'
                : sharers.isNotEmpty
                ? "${sharers.join(', ')} ${sharers.length == 1 ? 'books' : 'book'} "
                      "into $name's availability. A job in any of them takes "
                      'the same spots.'
                : 'Give $name the same availability pool as another area of '
                      'its franchise: a job in either takes the same spots.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          if (sharers.isEmpty) ...[
            const SizedBox(height: 10),
            SearchPickerField<String>(
              key: const Key('shares-with'),
              value: leadId,
              label: 'Shares availability with',
              hint: 'Its own availability',
              isDense: true,
              allowClear: true,
              options: [
                for (final a in leads)
                  SearchOption(
                    value: a['id'] as String,
                    label: a['name'] as String,
                  ),
              ],
              onChanged: _saving ? null : _setSharesWith,
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _setAvailableFrom(String? date) => _areaWrite(
    (id) => widget.api.put('/scheduling/areas/$id/available-from', {
      'available_from': date,
    }),
  );

  /// Opens the editor for [date], prefilled from what that date already has,
  /// or from the weekly pattern for its weekday.
  void _editDate(String date) {
    final existing = (_openArea?['dates'] as List? ?? const [])
        .cast<Map<String, dynamic>>()
        .where((d) => d['date'] == date)
        .firstOrNull;

    _dateValues.clear();

    if (existing != null) {
      _dateClosed = existing['closed'] == true;
      for (final w
          in (existing['windows'] as List? ?? const [])
              .cast<Map<String, dynamic>>()) {
        _dateValues['${w['start_time']}-${w['end_time']}'] = '${w['max_jobs']}';
      }
    } else {
      _dateClosed = false;
      // Carbon's weekday numbers: 0 is Sunday.
      final weekday = DateTime.parse(date).weekday % 7;
      for (final row in _grid[weekday] ?? const <Map<String, dynamic>>[]) {
        if (row['max_jobs'] != null) {
          _dateValues['${row['start_time']}-${row['end_time']}'] =
              '${row['max_jobs']}';
        }
      }
    }

    setState(() => _dateEditing = date);
  }

  Future<void> _saveDate() async {
    final date = _dateEditing;
    if (date == null) return;

    final windows = <Map<String, dynamic>>[
      for (final entry in _dateValues.entries)
        if ((int.tryParse(entry.value.trim()) ?? 0) > 0)
          {
            'start_time': entry.key.split('-').first,
            'end_time': entry.key.split('-').last,
            'max_jobs': int.parse(entry.value.trim()),
          },
    ];

    await _areaWrite(
      (id) => widget.api.put(
        '/scheduling/areas/$id/dates/$date',
        _dateClosed || windows.isEmpty
            ? {'closed': true}
            : {'windows': windows},
      ),
    );
  }

  Future<void> _clearDate(String date) => _areaWrite(
    (id) => widget.api.delete('/scheduling/areas/$id/dates/$date'),
  );

  Widget _datesCard(ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final from = _openArea?['available_from'] as String?;
    final dates = (_openArea?['dates'] as List? ?? const [])
        .cast<Map<String, dynamic>>();

    String summary(Map<String, dynamic> d) {
      if (d['closed'] == true) return 'Closed all day';
      return (d['windows'] as List? ?? const [])
          .cast<Map<String, dynamic>>()
          .map(
            (w) =>
                '${_label(w['start_time'] as String)}–'
                '${_label(w['end_time'] as String)}: ${w['max_jobs']}',
          )
          .join('  ·  ');
    }

    return _card(
      theme,
      title: 'Specific dates',
      badge: dates.isEmpty ? 'None' : '${dates.length}',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'A date here replaces the weekly hours for that day — close it, or '
            'change how many jobs it takes.',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                from == null
                    ? 'Taking bookings from: any day'
                    : 'Not taking bookings before ${_prettyDate(from)}',
                style: theme.textTheme.bodyMedium,
              ),
              TextButton(
                key: const Key('set-available-from'),
                onPressed: _saving
                    ? null
                    : () async {
                        final d = await _pickDate(initial: from);
                        if (d != null) await _setAvailableFrom(d);
                      },
                child: Text(from == null ? 'Set a date' : 'Change'),
              ),
              if (from != null)
                TextButton(
                  onPressed: _saving ? null : () => _setAvailableFrom(null),
                  child: const Text('Clear'),
                ),
            ],
          ),
          const Divider(height: 20),
          for (final d in dates)
            if (_dateEditing == d['date'])
              _dateEditor(theme)
            else
              Row(
                children: [
                  SizedBox(
                    width: 96,
                    child: Text(
                      _prettyDate(d['date'] as String),
                      style: theme.textTheme.titleSmall,
                    ),
                  ),
                  Expanded(
                    child: Text(summary(d), style: theme.textTheme.bodySmall),
                  ),
                  IconButton(
                    tooltip: 'Change this date',
                    icon: const Icon(Icons.edit_outlined, size: 18),
                    onPressed: () => _editDate(d['date'] as String),
                  ),
                  IconButton(
                    tooltip: 'Back to the weekly hours',
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: _saving
                        ? null
                        : () => _clearDate(d['date'] as String),
                  ),
                ],
              ),
          if (_dateEditing != null &&
              !dates.any((d) => d['date'] == _dateEditing))
            _dateEditor(theme),
          if (_dateEditing == null) ...[
            const SizedBox(height: 6),
            OutlinedButton.icon(
              key: const Key('add-specific-date'),
              onPressed: () async {
                final d = await _pickDate();
                if (d != null) _editDate(d);
              },
              icon: const Icon(Icons.event_outlined, size: 18),
              label: const Text('Add a date'),
            ),
          ],
        ],
      ),
    );
  }

  Widget _dateEditor(ThemeData theme) {
    final date = _dateEditing!;
    final windows = <(String, String)>[
      for (final w in _timeSlots)
        (w['start_time'] as String, w['end_time'] as String),
    ];

    return Container(
      margin: const EdgeInsets.symmetric(vertical: 6),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(_prettyDate(date), style: theme.textTheme.titleSmall),
          SwitchListTile(
            key: const Key('date-closed'),
            contentPadding: EdgeInsets.zero,
            dense: true,
            title: const Text('Closed all day'),
            value: _dateClosed,
            onChanged: (v) => setState(() => _dateClosed = v),
          ),
          if (!_dateClosed)
            for (final (start, end) in windows)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  children: [
                    Expanded(child: Text('${_label(start)} – ${_label(end)}')),
                    SizedBox(
                      width: 56,
                      child: TextFormField(
                        key: ValueKey('date-$date-$start-$end'),
                        initialValue: _dateValues['$start-$end'] ?? '',
                        textAlign: TextAlign.center,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(
                          isDense: true,
                          hintText: '–',
                          contentPadding: EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 6,
                          ),
                          border: OutlineInputBorder(),
                        ),
                        onChanged: (v) => _dateValues['$start-$end'] = v,
                      ),
                    ),
                  ],
                ),
              ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: [
              FilledButton(
                key: const Key('save-specific-date'),
                onPressed: _saving ? null : _saveDate,
                child: const Text('Save date'),
              ),
              TextButton(
                onPressed: () => setState(() => _dateEditing = null),
                child: const Text('Cancel'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Zip codes as removable items, each with the city it is in.
  ///
  /// Search because an area can hold fifty and "is 75209 in here" is the
  /// question somebody actually has. Items because a set typed as one string
  /// fails as one string. The city because Availability can be searched by it,
  /// so a caller who knows "Plano" and not the zip still gets an answer.
  Widget _zipCard(ThemeData theme) {
    final visible = _visibleZips;
    final filtering = _zipSearch.text.trim().isNotEmpty;

    return _card(
      theme,
      title: 'Zip codes',
      badge: '${_zipList.length}',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                flex: 2,
                child: TextField(
                  key: const Key('scheduling-zip-entry'),
                  controller: _zipEntry,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    isDense: true,
                    border: OutlineInputBorder(),
                    labelText: 'Zip code',
                    hintText: '75201',
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                flex: 3,
                child: TextField(
                  key: const Key('scheduling-city-entry'),
                  controller: _cityEntry,
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _addZip(_zipEntry.text),
                  decoration: const InputDecoration(
                    isDense: true,
                    border: OutlineInputBorder(),
                    labelText: 'City (optional)',
                    hintText: 'Dallas',
                  ),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filledTonal(
                icon: const Icon(Icons.add, size: 20),
                tooltip: 'Add zip code',
                onPressed: _saving ? null : () => _addZip(_zipEntry.text),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'One at a time. The city is optional and lets people search '
              'Availability by city name.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          const SizedBox(height: 14),
          if (_zipList.length > 8)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: TextField(
                key: const Key('scheduling-zip-search'),
                controller: _zipSearch,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  isDense: true,
                  prefixIcon: Icon(Icons.search, size: 18),
                  hintText: 'Find a zip or city in this area',
                  border: OutlineInputBorder(),
                ),
              ),
            ),
          if (_zipList.isEmpty)
            Text(
              'No zip codes yet. A search by zip or city will not find this '
              'area until it has some.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            )
          else if (visible.isEmpty)
            Text(
              'Nothing here matches "${_zipSearch.text.trim()}".',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            )
          else
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final entry in visible)
                  InputChip(
                    key: ValueKey('zip-${entry['zip']}'),
                    label: Text(
                      entry['city'] == null
                          ? entry['zip'] as String
                          : '${entry['zip']} · ${entry['city']}',
                    ),
                    onDeleted: _saving
                        ? null
                        : () => _removeZip(entry['zip'] as String),
                    // Named rather than left to Material's default, which has
                    // changed shape between versions — the test taps this icon
                    // and should not be asserting on a framework default.
                    deleteIcon: const Icon(Icons.close, size: 18),
                    deleteButtonTooltipMessage: 'Remove ${entry['zip']}',
                    deleteIconColor: theme.colorScheme.onSurfaceVariant,
                  ),
              ],
            ),
          if (filtering && visible.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Text(
                'Showing ${visible.length} of ${_zipList.length}.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          const SizedBox(height: 10),
          Text(
            'A zip belongs to one area. One already covered elsewhere is '
            'refused, and says which area has it.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }

  /// What a weekday adds up to, above the editor, so nobody adds up the slots
  /// by hand (Jose, 2026-09-25, pointing at A1's "Daily totals"). Follows the
  /// typing, before Save. When the franchise has other areas, each tile also
  /// says what the whole franchise takes that day: the other areas' SAVED
  /// numbers plus this area's live ones.
  Widget _dailyTotals(ThemeData theme, Map<String, dynamic> area) {
    int dayTotal(int weekday) => _sumOf(weekday);

    final siblings = _areas
        .where(
          (a) =>
              a['franchise_id'] == area['franchise_id'] &&
              a['id'] != _openAreaId,
        )
        .toList();
    final franchise = _me?.franchises
        .where((f) => f.id == area['franchise_id'])
        .firstOrNull
        ?.name;

    int franchiseTotal(int weekday, int own) =>
        siblings.fold(own, (sum, a) => sum + _areaDay(a, weekday));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'DAILY TOTALS',
          style: theme.textTheme.labelSmall?.copyWith(
            letterSpacing: 0.8,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 6),
        _dayTiles(
          theme,
          keyPrefix: 'daily-total',
          total: dayTotal,
          under: (weekday) {
            final whole = franchiseTotal(weekday, dayTotal(weekday));
            final own = ((area['daily_limits'] as Map?)?['$weekday'] as num?)
                ?.toInt();
            final max = _franchiseLimits[area['franchise_id']]?[weekday];
            final franchiseLine = [
              if (siblings.isNotEmpty && whole > 0) '$whole in all',
              if (max != null) '${franchise ?? 'franchise'} max $max',
            ].join(' · ');
            final lines = [
              if (own != null) 'max $own',
              if (franchiseLine.isNotEmpty) franchiseLine,
            ];

            return lines.isEmpty ? null : lines.join('\n');
          },
        ),
        if (siblings.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(
            '"In all" is every ${franchise ?? 'franchise'} area together: '
            '${area['name']} as typed above, the other '
            '${siblings.length == 1 ? 'area' : '${siblings.length} areas'} '
            'as saved.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }

  /// The open franchise's limit fields, as typed. Blank is no limit; the
  /// server refuses a zero, which is not a limit but a closure.
  Future<void> _saveLimits(String franchiseId) async {
    final limits = <String, int?>{
      for (final e in _limitFields.entries)
        '${e.key}': int.tryParse(e.value.text.trim()),
    };

    setState(() {
      _savingLimits = true;
      _paneError = null;
    });

    final response = await widget.api.put(
      '/scheduling/franchises/$franchiseId/limits',
      {'limits': limits},
    );
    if (!mounted) return;

    setState(() {
      _savingLimits = false;
      if (!response.ok) {
        _paneError = response.message;
        return;
      }
      _franchiseLimits[franchiseId] = {
        for (final e in limits.entries)
          if (e.value != null) int.parse(e.key): e.value!,
      };
    });
  }

  /// Seven day tiles, Monday first: green with the number, grey "Closed" at
  /// zero, and an optional line under the number.
  ///
  /// IntrinsicHeight + stretch: every tile as tall as the tallest. A closed day
  /// and a day without the line under it have less in them, and were drawing
  /// shorter than their neighbours (Jose: "you always make that mistake").
  Widget _dayTiles(
    ThemeData theme, {
    required String keyPrefix,
    required int Function(int weekday) total,
    String? Function(int weekday)? under,
  }) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (weekday, label) in _week) ...[
            if (weekday != 1) const SizedBox(width: 6),
            Expanded(
              child: Builder(
                builder: (context) {
                  final n = total(weekday);
                  final tone = ToneColours.of(
                    context,
                    n > 0 ? Tone.ok : Tone.neutral,
                  );
                  final line = under?.call(weekday);

                  return Container(
                    key: ValueKey('$keyPrefix-$weekday'),
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    decoration: BoxDecoration(
                      color: tone.fill,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          label,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: tone.ink,
                          ),
                        ),
                        const SizedBox(height: 2),
                        FittedBox(
                          child: Text(
                            n > 0 ? '$n' : 'Closed',
                            style:
                                (n > 0
                                        ? theme.textTheme.titleLarge
                                        : theme.textTheme.labelMedium)
                                    ?.copyWith(
                                      color: tone.ink,
                                      fontWeight: FontWeight.w700,
                                    ),
                          ),
                        ),
                        if (line != null)
                          FittedBox(
                            child: Text(
                              line,
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: tone.ink.withValues(alpha: 0.75),
                              ),
                            ),
                          ),
                      ],
                    ),
                  );
                },
              ),
            ),
          ],
        ],
      ),
    );
  }

  /// The week as one grid: a row per day, the time slots as columns, and the
  /// day's total first because it is what gets typed most (Jose, 2026-09-25,
  /// "so it's easier to fill up").
  ///
  /// Replaced a stack of three rows per day with the Day box off at the far
  /// edge of a wide screen, which Jose asked to look better. Capped at 760
  /// wide so the numbers sit beside their day instead of across the screen.
  ///
  /// EVERY ROW IS THE SAME HEIGHT, closed ones included. Jose, twice, about
  /// sibling tiles drawn at different sizes; the test measures it.
  Widget _weekGrid(ThemeData theme) {
    final columns = _columns;
    final muted = theme.colorScheme.onSurfaceVariant;
    final canOpen = _timeSlots.isNotEmpty;
    const rowHeight = 48.0;
    const dayWidth = 44.0;
    const actionWidth = 60.0;

    Widget cell(Widget child) => Expanded(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 3),
        child: child,
      ),
    );

    InputDecoration box({Color? fill}) => InputDecoration(
      isDense: true,
      hintText: '–',
      filled: fill != null,
      fillColor: fill,
      contentPadding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
      border: const OutlineInputBorder(),
    );

    final header = SizedBox(
      height: 36,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          const SizedBox(width: dayWidth),
          cell(
            Text(
              'Day total',
              textAlign: TextAlign.center,
              maxLines: 2,
              style: theme.textTheme.labelSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          for (final (start, end) in columns)
            cell(
              Text(
                _shortWindow(start, end),
                textAlign: TextAlign.center,
                maxLines: 2,
                style: theme.textTheme.labelSmall?.copyWith(color: muted),
              ),
            ),
          const SizedBox(width: actionWidth),
        ],
      ),
    );

    Widget dayRow(int weekday, String label) {
      final rows = _grid[weekday];
      final closed = rows == null || rows.isEmpty;

      Map<String, dynamic>? rowFor(String start, String end) {
        for (final r in rows ?? const <Map<String, dynamic>>[]) {
          if (r['start_time'] == start && r['end_time'] == end) return r;
        }
        return null;
      }

      return Container(
        key: ValueKey('day-row-$weekday'),
        height: rowHeight,
        decoration: BoxDecoration(
          border: Border(bottom: BorderSide(color: EzBiziTheme.edge(context))),
        ),
        child: Row(
          children: [
            SizedBox(
              width: dayWidth,
              child: Text(label, style: theme.textTheme.titleSmall),
            ),
            if (closed)
              Expanded(
                child: Text(
                  canOpen
                      ? 'Closed'
                      : 'Closed · add time slots in Overview to open it',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              )
            else ...[
              cell(
                TextField(
                  key: ValueKey('day-total-field-$weekday'),
                  controller: _dayTotal[weekday] ??= TextEditingController(
                    text: _sumOf(weekday) > 0 ? '${_sumOf(weekday)}' : '',
                  ),
                  textAlign: TextAlign.center,
                  keyboardType: TextInputType.number,
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                  decoration: box(fill: ToneColours.of(context, Tone.ok).fill),
                  onChanged: (value) => _splitDay(weekday, value),
                ),
              ),
              for (final (start, end) in columns)
                cell(
                  // Keyed by the split generation: the field reads its value
                  // once, so a split draws it fresh.
                  KeyedSubtree(
                    key: ValueKey('day-$weekday-${_dayGen[weekday] ?? 0}'),
                    child: TextFormField(
                      key: ValueKey('slot-$weekday-$start-$end'),
                      initialValue: '${rowFor(start, end)?['max_jobs'] ?? ''}',
                      textAlign: TextAlign.center,
                      keyboardType: TextInputType.number,
                      style: theme.textTheme.bodyMedium,
                      decoration: box(),
                      onChanged: (value) {
                        final n = int.tryParse(value.trim());
                        var row = rowFor(start, end);
                        if (row == null) {
                          row = {'start_time': start, 'end_time': end};
                          rows.add(row);
                        }
                        // Blank, or zero: this window is not offered that
                        // day. Stored as absence, never as a zero — the
                        // server refuses a zero anyway.
                        setState(() {
                          row!['max_jobs'] = (n != null && n > 0) ? n : null;
                          _syncDayTotal(weekday);
                        });
                      },
                    ),
                  ),
                ),
            ],
            SizedBox(
              width: actionWidth,
              child: TextButton(
                onPressed: closed
                    ? (canOpen ? () => _openDay(weekday) : null)
                    : () => _closeDay(weekday),
                style: TextButton.styleFrom(
                  padding: EdgeInsets.zero,
                  visualDensity: VisualDensity.compact,
                ),
                child: Text(closed ? 'Open' : 'Close'),
              ),
            ),
          ],
        ),
      );
    }

    return Align(
      alignment: Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            header,
            for (final (weekday, label) in _week) dayRow(weekday, label),
          ],
        ),
      ),
    );
  }

  /// "8 AM – 11 AM" as "8–11 AM", "11 AM – 2 PM" as "11 AM–2 PM": a column
  /// header has the room of one number field.
  static String _shortWindow(String start, String end) {
    final a = _label(start);
    final b = _label(end);
    final pa = a.substring(a.length - 2);
    final pb = b.substring(b.length - 2);

    return pa == pb ? '${a.substring(0, a.length - 3)}–$b' : '$a–$b';
  }

  /// "13:00" → "1 PM", "12:30" → "12:30 PM", "00:00" → "12 AM" — the way Jose
  /// says them ("10 am to 1"), rather than the way the column stores them.
  static String _label(String hm) {
    final parts = hm.split(':');
    final h = int.tryParse(parts.first) ?? 0;
    final m = parts.length > 1
        ? parts[1].padRight(2, '0').substring(0, 2)
        : '00';
    final h12 = h % 12 == 0 ? 12 : h % 12;
    final period = h < 12 ? 'AM' : 'PM';

    return m == '00' ? '$h12 $period' : '$h12:$m $period';
  }

  /// Half-hour steps across a working day. A dropdown rather than a time
  /// picker because the picker is a modal, and this screen does not open one.
  static final List<String> _times = [
    for (var minutes = 5 * 60; minutes <= 22 * 60; minutes += 30)
      '${(minutes ~/ 60).toString().padLeft(2, '0')}:'
          '${(minutes % 60).toString().padLeft(2, '0')}',
  ];

  /// A time of day, picked from half-hour steps with a search box — "10"
  /// finds 10 AM and 10:30 AM rather than scrolling through thirty-five.
  ///
  /// NOT a DropdownButton. Jose, 2026-09-17: "this sort of popup dropdown
  /// are terrible ui design, anything that offers users should be a search
  /// bar picker instead" — and `no_popup_dropdown_test.dart` enforces it
  /// across lib/. The first version of this card used one and turned that
  /// guard red; the scheduling tests alone never ran it.
  Widget _timeField(
    String value,
    ValueChanged<String> onChanged,
    String key,
    String label,
  ) {
    // A stored window off the half-hour grid is still offered, or the field
    // would show a value that is not among its own options.
    final options = _times.contains(value)
        ? _times
        : ([..._times, value]..sort());

    return SizedBox(
      width: 132,
      child: SearchPickerField<String>(
        key: ValueKey(key),
        value: value,
        label: label,
        isDense: true,
        options: [
          for (final t in options) SearchOption(value: t, label: _label(t)),
        ],
        onChanged: (v) {
          if (v != null) onChanged(v);
        },
      ),
    );
  }

  /// The organization's windows, stated once for every area.
  ///
  /// LOCKED UNTIL ASKED (Jose, 2026-09-28: "anyone could click by accident
  /// and delete a full time slot"). The windows read as tiles; changing them
  /// takes "Edit time slots" first. A removal says what it deletes (every
  /// area's capacity on that window) and needs REMOVE typed, and the server
  /// refuses one that does not name the window's hours. A change of hours
  /// says how many areas it moves before it does.
  Widget _timeSlotsCard(ThemeData theme) {
    final muted = theme.colorScheme.onSurfaceVariant;
    final bad = ToneColours.of(context, Tone.bad);

    String span(Map<String, dynamic> w) =>
        '${_label(w['start_time'] as String)} – ${_label(w['end_time'] as String)}';
    String used(Map<String, dynamic> w) {
      final n = (w['areas'] as num?)?.toInt() ?? 0;

      return n == 0
          ? 'No area uses it yet'
          : 'Used by $n ${n == 1 ? 'area' : 'areas'}';
    }

    Widget editor(String saveLabel, VoidCallback onSave) => Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        _timeField(
          _draftStart,
          (v) => setState(() => _draftStart = v),
          'slot-draft-start',
          'From',
        ),
        _timeField(
          _draftEnd,
          (v) => setState(() => _draftEnd = v),
          'slot-draft-end',
          'To',
        ),
        FilledButton(onPressed: onSave, child: Text(saveLabel)),
        TextButton(
          onPressed: () => setState(() {
            _editingSlotId = null;
            _addingSlot = false;
            _confirmMove = false;
          }),
          child: const Text('Cancel'),
        ),
      ],
    );

    Widget tile(Map<String, dynamic> w) {
      final id = w['id'] as String;
      final selected = _editingSlotId == id || _removingSlotId == id;

      return Container(
        key: ValueKey('slot-tile-$id'),
        width: 190,
        padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest.withValues(
            alpha: 0.4,
          ),
          border: Border.all(
            color: selected
                ? theme.colorScheme.primary
                : EzBiziTheme.edge(context),
          ),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Row(
          children: [
            Icon(Icons.schedule, size: 18, color: muted),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    span(w),
                    style: theme.textTheme.titleSmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(
                    used(w),
                    style: theme.textTheme.labelSmall?.copyWith(color: muted),
                  ),
                ],
              ),
            ),
            if (_slotsUnlocked) ...[
              IconButton(
                tooltip: 'Change hours',
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.edit_outlined, size: 18),
                onPressed: () => setState(() {
                  _editingSlotId = id;
                  _removingSlotId = null;
                  _addingSlot = false;
                  _confirmMove = false;
                  _draftStart = w['start_time'] as String;
                  _draftEnd = w['end_time'] as String;
                  _slotError = null;
                }),
              ),
              IconButton(
                tooltip: 'Remove',
                visualDensity: VisualDensity.compact,
                icon: Icon(Icons.delete_outline, size: 18, color: bad.ink),
                onPressed: () => setState(() {
                  _removingSlotId = id;
                  _editingSlotId = null;
                  _addingSlot = false;
                  _removeTyped.clear();
                  _slotError = null;
                }),
              ),
            ],
          ],
        ),
      );
    }

    final editing = _timeSlots.where((w) => w['id'] == _editingSlotId);
    final removing = _timeSlots.where((w) => w['id'] == _removingSlotId);

    return _card(
      theme,
      title: 'Time slots',
      // Never a bare "0": this screen's rule is that a zero on the Overview
      // means an area is full, and a test holds it to that.
      badge: _timeSlots.isEmpty ? 'None' : '${_timeSlots.length}',
      action: !_canEditSlots
          ? null
          : _slotsUnlocked
          ? FilledButton.tonalIcon(
              onPressed: () => setState(() {
                _slotsUnlocked = false;
                _editingSlotId = null;
                _removingSlotId = null;
                _addingSlot = false;
                _confirmMove = false;
              }),
              icon: const Icon(Icons.lock_outline, size: 18),
              label: const Text('Done'),
            )
          : OutlinedButton.icon(
              onPressed: () => setState(() => _slotsUnlocked = true),
              icon: const Icon(Icons.lock_open_outlined, size: 18),
              label: const Text('Edit time slots'),
            ),
      child: Column(
        key: const ValueKey('tour-scheduling-time-slots'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _slotsInheritedFrom == null
                ? 'The windows every service area books into. Each area sets '
                      'how many jobs a window takes on each day.'
                : 'Using $_slotsInheritedFrom’s windows. Changing one '
                      'gives this company its own copy; the rest keep theirs.',
            key: const ValueKey('time-slots-inherited'),
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
          ),
          const SizedBox(height: 12),
          if (_slotError != null) ...[
            ErrorNote(_slotError!),
            const SizedBox(height: 8),
          ],
          if (_timeSlots.isEmpty)
            Text(
              'None yet. Until there is one, no day can be opened.',
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            )
          else
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [for (final w in _timeSlots) tile(w)],
            ),
          if (editing.isNotEmpty) ...[
            const SizedBox(height: 14),
            Text(
              'New hours for ${span(editing.first)}',
              style: theme.textTheme.labelMedium,
            ),
            const SizedBox(height: 6),
            if (_confirmMove)
              _warning(
                theme,
                'Every area\'s capacity on ${span(editing.first)} moves to '
                '${_label(_draftStart)} – ${_label(_draftEnd)}: '
                '${used(editing.first).toLowerCase()}. Jobs already booked '
                'keep their own times.',
                confirmLabel: 'Move to the new hours',
                onConfirm: _saveTimeSlot,
                onCancel: () => setState(() => _confirmMove = false),
              )
            else
              editor('Save hours', () {
                final w = editing.first;
                final changed =
                    w['start_time'] != _draftStart ||
                    w['end_time'] != _draftEnd;
                if (!changed) {
                  setState(() => _editingSlotId = null);
                } else if (((w['areas'] as num?) ?? 0) > 0) {
                  setState(() => _confirmMove = true);
                } else {
                  _saveTimeSlot();
                }
              }),
          ],
          if (removing.isNotEmpty) ...[
            const SizedBox(height: 14),
            _warning(
              theme,
              'Remove ${span(removing.first)}? It deletes every area\'s '
              'capacity on it (${used(removing.first).toLowerCase()}), and it '
              'cannot be undone. Type REMOVE to confirm.',
              field: TextField(
                key: const ValueKey('slot-remove-confirm'),
                controller: _removeTyped,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  isDense: true,
                  hintText: 'REMOVE',
                  border: OutlineInputBorder(),
                ),
              ),
              confirmLabel: 'Remove time slot',
              onConfirm: _removeTyped.text.trim().toUpperCase() == 'REMOVE'
                  ? () => _removeTimeSlot(removing.first)
                  : null,
              onCancel: () => setState(() => _removingSlotId = null),
            ),
          ],
          if (_slotsUnlocked && editing.isEmpty && removing.isEmpty) ...[
            const SizedBox(height: 14),
            if (_addingSlot)
              editor('Add time slot', _saveTimeSlot)
            else
              TextButton.icon(
                onPressed: () => setState(() {
                  _addingSlot = true;
                  _slotError = null;
                }),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add a time slot'),
              ),
          ],
          if (!_canEditSlots && _timeSlots.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              'These apply to every branch of the company, so they are '
              'changed at company or organization level.',
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ],
        ],
      ),
    );
  }

  /// A tinted box that says what an action will do, with its confirm and
  /// cancel side by side — used where one click would change every area.
  Widget _warning(
    ThemeData theme,
    String text, {
    Widget? field,
    required String confirmLabel,
    required VoidCallback? onConfirm,
    required VoidCallback onCancel,
  }) {
    final tone = ToneColours.of(context, Tone.bad);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: tone.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            text,
            style: theme.textTheme.bodySmall?.copyWith(color: tone.ink),
          ),
          if (field != null) ...[
            const SizedBox(height: 8),
            SizedBox(width: 200, child: field),
          ],
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: [
              FilledButton(
                onPressed: onConfirm,
                style: FilledButton.styleFrom(
                  backgroundColor: theme.colorScheme.error,
                  foregroundColor: theme.colorScheme.onError,
                ),
                child: Text(confirmLabel),
              ),
              TextButton(onPressed: onCancel, child: const Text('Keep it')),
            ],
          ),
        ],
      ),
    );
  }

  Widget _card(
    ThemeData theme, {
    required String title,
    required String badge,
    required Widget child,
    Widget? action,
  }) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(title, style: theme.textTheme.titleSmall),
                      Chip(
                        label: Text(badge),
                        visualDensity: VisualDensity.compact,
                        padding: EdgeInsets.zero,
                      ),
                    ],
                  ),
                ),
                ?action,
              ],
            ),
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );
  }
}
