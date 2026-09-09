import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/franchise_field.dart';

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
    (1, 'Mon'), (2, 'Tue'), (3, 'Wed'), (4, 'Thu'), (5, 'Fri'), (6, 'Sat'), (0, 'Sun'),
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
  Map<String, dynamic>? _pattern;

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
  final Map<int, List<Map<String, dynamic>>> _grid = {};

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
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _areas.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/areas', const {});

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _areas = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _pattern = response.body['pattern'] as Map<String, dynamic>?;
    });
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
      _grid.clear();
      for (final slot in (_openArea?['slots'] as List? ?? const [])
          .cast<Map<String, dynamic>>()) {
        (_grid[slot['weekday'] as int] ??= []).add({
          'start_time': slot['start_time'],
          'end_time': slot['end_time'],
          'max_jobs': slot['max_jobs'],
        });
      }
    });
  }

  void _showOverview() => setState(() {
    _openAreaId = null;
    _openArea = null;
    _form = _Form.none;
    _paneError = null;
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

  Future<void> _deleteArea() async {
    final id = _openAreaId;
    final name = _openArea?['name'] as String? ?? 'this area';
    if (id == null) return;

    // A confirmation, not a form — so this one stays a dialog. It asks a
    // yes/no about something irreversible and has nothing to fill in, which is
    // the case a dialog is actually for.
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Delete $name?'),
        content: Text(
          'Its zip codes and its week go with it. Anything already booked in '
          '$name is not touched.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Keep it'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    final response = await widget.api.delete('/scheduling/areas/$id');

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _paneError = response.message);
      return;
    }

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
          {
            'weekday': entry.key,
            'start_time': slot['start_time'],
            'end_time': slot['end_time'],
            'max_jobs': slot['max_jobs'],
          },
    ];

    final response =
        await widget.api.put('/scheduling/areas/$id/slots', {'slots': slots});

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

  void _openDay(int weekday) => setState(() {
    _grid[weekday] = [
      for (final slot in _pattern?['slots'] as List? ?? const [])
        if ((slot as Map)['weekday'] == weekday)
          {
            'start_time': slot['start_time'],
            'end_time': slot['end_time'],
            'max_jobs': slot['max_jobs'],
          },
    ];

    // A day the pattern does not cover still has to become something.
    if (_grid[weekday]!.isEmpty) {
      _grid[weekday] = [
        {'start_time': '08:00', 'end_time': '12:00', 'max_jobs': 1},
      ];
    }
  });

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
        final hasPane = _openAreaId != null || _form != _Form.none;
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
            selected: _openAreaId == null && _form == _Form.none,
            title: const Text('Overview'),
            subtitle: const Text('Pattern & exceptions'),
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
            areas.length == 1 ? '1 service area' : '${areas.length} service areas',
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
        rows.add(_railHeader(theme, _railGroupLabel(me, entry.key, ambiguous: ambiguous)));
      }

      for (final area in entry.value) {
        rows.add(_railTile(area));
      }
    }

    return rows;
  }

  /// The franchise a group of areas belongs to, named so it cannot be confused
  /// with another franchise of the same name under a different company.
  String _railGroupLabel(Me me, String? franchiseId, {required bool ambiguous}) {
    final franchise = me.franchises.where((f) => f.id == franchiseId).firstOrNull;

    // A franchise the session does not list. Says so rather than showing a
    // blank heading: an area filed under something out of view is a real
    // state — reach can be narrowed after the area was made — and a heading
    // reading nothing at all looks like a rendering fault.
    if (franchise == null) return 'Elsewhere';

    return ambiguous ? franchisePath(me, franchise) : franchise.name;
  }

  Widget _railHeader(ThemeData theme, String label) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 14, 12, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label.toUpperCase(),
            style: theme.textTheme.labelSmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
              letterSpacing: 0.6,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 6),
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

    return _openAreaId == null ? _overview() : _areaPane(showBack: showBack);
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

  Widget _overview() {
    final theme = Theme.of(context);
    final differing =
        _areas.where((a) => a['follows_pattern'] == false).toList();
    final slots =
        (_pattern?['slots'] as List? ?? const []).cast<Map<String, dynamic>>();

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 32),
      children: [
        Text('Capacity', style: theme.textTheme.titleMedium),
        const SizedBox(height: 2),
        Text(
          'Most franchises run one pattern everywhere. Set it here, then open '
          'an area on the left to give it different numbers.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 16),
        if (_paneError != null) ...[
          ErrorNote(_paneError!),
          const SizedBox(height: 12),
        ],
        _card(
          theme,
          title: 'Your slot pattern',
          badge: _pattern == null
              ? 'nothing set up yet'
              : 'followed by ${_pattern!['followed_by']} of ${_pattern!['of']} areas',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (slots.isEmpty)
                Text(
                  'No area has slots yet. Open one and set its week.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                )
              else
                Text(
                  _describePattern(slots),
                  style: theme.textTheme.bodyMedium,
                ),
            ],
          ),
        ),
        // The design's third card, and the one whose absence made this screen
        // a dead end: with no areas and no way to add one, nothing else on it
        // could ever be reached.
        _card(
          theme,
          title: 'Service areas and zip codes',
          badge: _areas.isEmpty
              ? 'none yet'
              : '${_areas.length} ${_areas.length == 1 ? 'area' : 'areas'} · '
                    '${_areas.fold<int>(0, (n, a) => n + ((a['zip_count'] as int?) ?? 0))} zips',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                _areas.isEmpty
                    ? 'Add the places this business works — Dallas, Houston — '
                          'then give each one its zip codes and its week.'
                    : 'A zip belongs to exactly one area, so a search by zip is '
                          'never ambiguous.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 10),
              Align(
                alignment: Alignment.centerLeft,
                child: FilledButton.icon(
                  onPressed: _startCreate,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add service area'),
                ),
              ),
            ],
          ),
        ),
        _card(
          theme,
          title: 'Areas that differ',
          badge: '${differing.length}',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (differing.isEmpty)
                Text(
                  'Every area follows the pattern.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                )
              else
                for (final area in differing)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(area['name'] as String? ?? ''),
                    trailing: const Icon(Icons.chevron_right, size: 18),
                    onTap: () => _openArea_(area['id'] as String),
                  ),
              const SizedBox(height: 4),
              Text(
                'Listed so an exception cannot be forgotten. An area appears '
                'here only once somebody edits it directly, which is the only '
                'way to differ.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    );
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
        const SizedBox(height: 14),
        Text(
          'Jobs ${area['name']} can take per slot, per weekday.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 10),
        // One card per day, slots as rows. The seven-column grid is dropped
        // entirely rather than made to scroll sideways.
        for (final (weekday, label) in _week) _dayEditor(theme, weekday, label),
        const SizedBox(height: 16),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: _saving ? null : _saveGrid,
            child: Text(_saving ? 'Saving…' : 'Save ${area['name']}'),
          ),
        ),
        const SizedBox(height: 24),
        _zipCard(theme),
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

  Widget _dayEditor(ThemeData theme, int weekday, String label) {
    final slots = _grid[weekday];
    final closed = slots == null || slots.isEmpty;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: EzBiziTheme.edge(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 8, 8, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(label, style: theme.textTheme.titleSmall),
                ),
                if (closed)
                  TextButton(
                    onPressed: () => _openDay(weekday),
                    child: const Text('Open'),
                  )
                else
                  TextButton(
                    onPressed: () => _closeDay(weekday),
                    child: const Text('Close'),
                  ),
              ],
            ),
            if (closed)
              Text(
                'Closed',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              )
            else
              for (var i = 0; i < slots.length; i++)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${slots[i]['start_time']} – ${slots[i]['end_time']}',
                          style: theme.textTheme.bodyMedium,
                        ),
                      ),
                      SizedBox(
                        width: 64,
                        child: TextFormField(
                          key: ValueKey('slot-$weekday-$i-${slots[i]['start_time']}'),
                          initialValue: '${slots[i]['max_jobs']}',
                          textAlign: TextAlign.center,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                            isDense: true,
                            border: OutlineInputBorder(),
                          ),
                          onChanged: (value) {
                            final n = int.tryParse(value);
                            // Zero is not storable and the server refuses it.
                            // Closing a day is the Close button, not a zero.
                            if (n != null && n > 0) slots[i]['max_jobs'] = n;
                          },
                        ),
                      ),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }

  Widget _card(
    ThemeData theme, {
    required String title,
    required String badge,
    required Widget child,
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
            Wrap(
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
            const SizedBox(height: 8),
            child,
          ],
        ),
      ),
    );
  }

  /// "Three windows a day, two jobs each" from the rows themselves.
  String _describePattern(List<Map<String, dynamic>> slots) {
    final days = slots.map((s) => s['weekday']).toSet().length;
    final windows = slots
        .map((s) => '${s['start_time']}–${s['end_time']}')
        .toSet()
        .toList()
      ..sort();
    final jobs = slots.map((s) => s['max_jobs']).toSet();

    final capacity = jobs.length == 1
        ? '${jobs.first} job${jobs.first == 1 ? '' : 's'} each'
        : 'varying capacity';

    return '${windows.join(', ')} — $capacity, across $days '
        '${days == 1 ? 'day' : 'days'}.';
  }
}
