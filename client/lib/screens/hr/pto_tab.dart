import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// Paid time off — what people are allowed, and when they may start using it.
///
/// From a production suggestion (Toula Elwell, A1-Group, 2026-09-08): "how much
/// paid pto we all have and when it can be used". Layouts are Jose's picks of
/// 2026-09-17 — desktop 1 (the team beside one person) and phone 4 (you first,
/// everybody after) — from docs/previews/pto-balances-layouts.html.
///
/// ── The date is the answer; the number is the decoration ─────────
///
/// Her sentence has two halves and the second is the one nothing could answer.
/// A balance is a figure somebody could keep in a spreadsheet; "when it can be
/// used" is a rule counted from the day a person started, and it lives in a
/// contract PDF if it exists at all. So the date gets its own box rather than
/// a footnote under the number.
///
/// ── Design 126 (Jose, 2026-10-21: options 1 and 4) ───────────────
///
/// docs/previews/paid-time-off-layouts.html adds the balance: Total, Used and
/// Left per person, the day it resets, and a way to change Used for whoever
/// may edit it. Every balance field is read as optional, and a row or panel
/// without it draws exactly what it drew before.
///
/// ── Design 140 (Jose, 2026-10-22: options 1 and 4) ───────────────
///
/// docs/previews/pto-person-panel-layouts.html: four tiles that are always
/// there (so the panel does not jump between people), a countdown ring and a
/// period bar for the reset, past resets, "When they can be used", a Role
/// filter, the dated days off recorded on a month calendar (scattered days,
/// half days), and one timeline of everything that changed the balance.
class PtoTab extends StatefulWidget {
  const PtoTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PtoTab> createState() => _PtoTabState();
}

class _PtoTabState extends State<PtoTab> {
  bool _loading = true;
  String? _error;

  Map<String, dynamic> _policy = const {};
  List<Map<String, dynamic>> _people = const [];
  String? _me;

  /// Whose panel is open on the desktop layout. Null means the reader's own,
  /// which is what the screen opens on — the person holding it is usually
  /// asking about themselves.
  String? _showing;

  /// The person open full screen on the phone layout, drawn in place of the
  /// list with a way back rather than pushed as a route.
  String? _phoneOpen;

  /// Whose days off are being recorded. Inline under the tiles on a desktop,
  /// full screen in place on a phone — never a dialog or a side pane.
  String? _recording;

  /// Why removing a run of days off was refused, in the server's words.
  String? _removeError;

  /// The desktop list's search, its Everyone / Can use / Not yet filter, and
  /// its role (a role id, or a role's name when the server sends only that).
  final _search = TextEditingController();
  _Filter _filter = _Filter.everyone;
  String? _role;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  /// [quiet] reads the list again without the spinner — after a save, when
  /// the tiles already hold the server's answer and only the timeline and
  /// the calendar's recorded days are behind.
  Future<void> _load({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    final response = await widget.api.get('/hr/pto');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        // A quiet read that fails leaves the screen as the save left it.
        if (!quiet) _error = response.reason;
        return;
      }

      _policy = Map<String, dynamic>.from(
        response.body['policy'] as Map? ?? const {},
      );
      _people = List<Map<String, dynamic>>.from(response.data as List? ?? []);
      _me = response.body['me']?.toString();
    });
  }

  Map<String, dynamic>? get _mine {
    for (final person in _people) {
      if (person['id']?.toString() == _me) return person;
    }
    return _people.isEmpty ? null : _people.first;
  }

  Map<String, dynamic>? _person(String? id) {
    for (final person in _people) {
      if (person['id']?.toString() == id) return person;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: LayoutBuilder(
        key: const ValueKey('tour-work_hours-pto'),
        // The CARD's width rather than the window's — the same reason the
        // Training card asks: this is a pane inside a shell, and a window
        // measurement would put the desktop layout into a narrow column.
        builder: (context, constraints) =>
            constraints.maxWidth < 600 ? _phone(context) : _desktop(context),
      ),
    );
  }

  // ── state A: the organization has not set it up ──────────────────

  /// Drawn instead of the screen rather than beside it.
  ///
  /// This is the COMMON case, not an edge: exactly one of the eleven contract
  /// templates mentions paid time off at all. The settings are
  /// organization-scoped, so it is never one person's state beside somebody
  /// else's — when it is true it is true of everybody, and the whole screen
  /// says so.
  Widget _notSetUp(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      key: const ValueKey('tour-work_hours-pto-not-set-up'),
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'This organization has not set up paid time off.',
                style: theme.textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              Text(
                'Nobody has an allowance yet, so there is nothing to count '
                'down to.',
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: 12),
              Text(
                // Names the PLACE, not the permission — that is what makes it
                // actionable for somebody who can. The second sentence is what
                // stops everybody else hunting for a step they are missing.
                'It is set under Work Hours › Settings, by somebody who '
                'can change the organization’s settings. If that is not you, '
                'this is the whole answer rather than a step you are missing.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── desktop 1: the team beside one person ────────────────────────

  /// The role a row carries, as the key the Role picker filters by.
  ///
  /// HR's rows were designed to carry `role: {id, name}`; an older answer
  /// carries the name alone. Either is read, so the filter works on both.
  static String? _roleKey(Map<String, dynamic> person) => switch (person['role']) {
    {'id': final Object id} => '$id',
    final String name when name.isNotEmpty => name,
    _ => null,
  };

  static String? _roleName(Map<String, dynamic> person) => switch (person['role']) {
    {'name': final String name} => name,
    final String name when name.isNotEmpty => name,
    _ => null,
  };

  Widget _desktop(BuildContext context) {
    if (_policy['enabled'] != true) return _notSetUp(context);

    final showing = _showing == null
        ? _mine
        : _people.firstWhere(
            (p) => p['id']?.toString() == _showing,
            orElse: () => _mine ?? const {},
          );

    final query = _search.text.trim().toLowerCase();
    final listed = [
      for (final person in _people)
        if ('${person['name']}'.toLowerCase().contains(query) &&
            (_role == null || _roleKey(person) == _role) &&
            switch (_filter) {
              _Filter.everyone => true,
              _Filter.canUse => person['eligible'] == true,
              _Filter.notYet => person['eligible'] != true,
            })
          person,
    ];

    // The roles the rows carry, each once, by name.
    final roles = <String, String>{
      for (final person in _people)
        ?_roleKey(person): _roleName(person) ?? _roleKey(person) ?? '',
    };
    final roleOptions = [
      const SearchOption<String>(value: null, label: 'All roles'),
      for (final entry in (roles.entries.toList()
        ..sort((a, b) => a.value.compareTo(b.value))))
        SearchOption<String>(value: entry.key, label: entry.value),
    ];

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          key: const ValueKey('tour-work_hours-pto-people'),
          width: 300,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 8, 0),
                child: TextField(
                  key: const Key('pto-search'),
                  controller: _search,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(
                    hintText: 'Search people',
                    prefixIcon: Icon(Icons.search),
                    isDense: true,
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 8, 0),
                child: SegmentedButton<_Filter>(
                  key: const Key('pto-filter'),
                  showSelectedIcon: false,
                  style: const ButtonStyle(
                    visualDensity: VisualDensity.compact,
                  ),
                  segments: const [
                    ButtonSegment(
                      value: _Filter.everyone,
                      label: Text('Everyone'),
                    ),
                    ButtonSegment(value: _Filter.canUse, label: Text('Can use')),
                    ButtonSegment(value: _Filter.notYet, label: Text('Not yet')),
                  ],
                  selected: {_filter},
                  onSelectionChanged: (v) => setState(() => _filter = v.first),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 8, 0),
                child: SearchPickerField<String>(
                  key: const Key('pto-role'),
                  value: _role,
                  options: roleOptions,
                  label: 'Role',
                  isDense: true,
                  onChanged: (v) => setState(() => _role = v),
                ),
              ),
              Expanded(
                child: listed.isEmpty
                    ? Padding(
                        padding: const EdgeInsets.fromLTRB(16, 16, 8, 0),
                        child: Text(
                          'Nobody matches.',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 8, 8, 24),
                        itemCount: listed.length,
                        itemBuilder: (context, i) => _row(context, listed[i]),
                      ),
              ),
            ],
          ),
        ),
        const VerticalDivider(width: 1),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 20, 24, 32),
            child: showing == null || showing.isEmpty
                ? const SizedBox.shrink()
                : _panel(context, showing, heading: showing['name'] as String?),
          ),
        ),
      ],
    );
  }

  /// One person in the list: their name, and the shortest true thing about them.
  Widget _row(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);
    final id = person['id']?.toString();
    final selected = id == (_showing ?? _me);

    return Card(
      elevation: 0,
      color: selected
          ? theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.6)
          : Colors.transparent,
      child: ListTile(
        title: Text(
          id == _me ? '${person['name']} (you)' : '${person['name']}',
          style: theme.textTheme.bodyMedium,
          overflow: TextOverflow.ellipsis,
        ),
        // Without a balance from the server the allowance is the row's one
        // fact, as it was before; with one, the Left on the right says it.
        subtitle: _hasBalance(person)
            ? null
            : Text(_allowance(person), style: theme.textTheme.bodySmall),
        trailing: _rowEnd(context, person),
        onTap: () => setState(() {
          // A half-picked set of days belongs to the person it was for.
          if (_recording != id) _recording = null;
          _removeError = null;
          _showing = id;
        }),
      ),
    );
  }

  /// "7 left", or why there is no number to show yet.
  ///
  /// The chip REPLACES the number rather than sitting beside it: somebody who
  /// cannot use their days yet has no balance they could spend, and a "10
  /// left" beside "From Mar 3" reads as ten days they may book.
  Widget? _rowEnd(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);

    if (person['eligible'] != true) {
      final from = person['eligible_from'];

      // Bounded, because a ListTile throws when its trailing widget is as
      // wide as the tile, and a long date format in a narrow list can be.
      return ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 150),
        child: from == null
            ? const StatusPill('Start date unknown')
            : StatusPill('From ${_date(from)}', tone: Tone.caution),
      );
    }

    final left = _num(person['left']);
    if (left == null) return null;
    final expired = _num(person['expired']) ?? 0;

    // Bounded for the same reason as the chip above.
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 150),
      child: Text(
        '${_days(left)} left'
        '${expired > 0 ? ' · ${_days(expired)} expired' : ''}',
        textAlign: TextAlign.end,
        style: theme.textTheme.bodyMedium?.copyWith(
          fontWeight: FontWeight.w700,
          color: left <= 0 ? theme.colorScheme.error : null,
        ),
      ),
    );
  }

  /// Whether the server sent a balance for this person, rather than only the
  /// allowance it has always sent.
  bool _hasBalance(Map<String, dynamic> person) =>
      _num(person['total']) != null || _num(person['left']) != null;

  /// "6 days a year" — the period is part of the allowance, not decoration.
  ///
  /// Six days a year and half a day a month are different sentences about the
  /// same policy, and the number alone is only an answer when everybody
  /// already knows which one their company wrote down.
  String _allowance(Map<String, dynamic> person) {
    final days = person['allowance'] as int? ?? 0;
    final period = switch (_policy['allowance_period']) {
      'week' => 'a week',
      'month' => 'a month',
      'quarter' => 'a quarter',
      _ => 'a year',
    };

    return '${days == 1 ? '1 day' : '$days days'} $period';
  }

  String _waitingPhrase() =>
      _span(_policy['waiting'] as int? ?? 0, _policy['waiting_unit']);

  /// "90 days", "3 months", "1 week" — the unit the setting named.
  ///
  /// Never converted to days: three months from 30 November is 28 February and
  /// ninety days is 1 March in three years out of four, so a conversion here
  /// would hand somebody a wrong date about their own leave.
  String _span(int n, Object? unit) {
    final word = switch (unit) {
      'week' => 'week',
      'month' => 'month',
      'quarter' => 'quarter',
      'year' => 'year',
      _ => 'day',
    };

    return '$n ${n == 1 ? word : '${word}s'}';
  }

  // ── phone 4: you first, everybody after ──────────────────────────

  Widget _phone(BuildContext context) {
    if (_policy['enabled'] != true) return _notSetUp(context);

    // Recording days off: the calendar full screen, in place, with a way back
    // that cancels — option 4.
    if (_person(_recording) case final person?) {
      return SidePanel(
        key: const Key('pto-phone-record'),
        docked: true,
        title: 'Record days off',
        subtitle: '${person['name']}',
        onBack: () => setState(() => _recording = null),
        child: _recorder(person, phone: true),
      );
    }

    // Somebody tapped: their panel, full screen, in place of the list. In
    // place rather than a pushed route, like the other HR records — the ←
    // is what the system Back presses (see pressInPageBack).
    if (_person(_phoneOpen) case final person?) {
      return SidePanel(
        key: const Key('pto-phone-person'),
        docked: true,
        title: '${person['name']}',
        onBack: () => setState(() => _phoneOpen = null),
        child: _panel(context, person, phone: true),
      );
    }

    final mine = _mine;
    final others = [
      for (final person in _people)
        if (person['id']?.toString() != _me) person,
    ];

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      children: [
        // The person holding a phone is almost always asking about themselves
        // — the suggestion this came from was somebody asking about her own
        // days — so the top answers that without a tap.
        if (mine != null) _panel(context, mine, heading: 'You', phone: true),
        if (others.isNotEmpty) ...[
          const SizedBox(height: 28),
          Text(
            'Everyone you can see',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: Theme.of(context).colorScheme.outline,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 8),
          for (final person in others)
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(
                '${person['name']}',
                overflow: TextOverflow.ellipsis,
              ),
              subtitle: _hasBalance(person)
                  ? null
                  : Text(
                      _allowance(person),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
              trailing: _rowEnd(context, person),
              onTap: () =>
                  setState(() => _phoneOpen = person['id']?.toString()),
            ),
        ],
      ],
    );
  }

  // ── the panel both layouts share ─────────────────────────────────

  Widget _panel(
    BuildContext context,
    Map<String, dynamic> person, {
    String? heading,
    bool phone = false,
  }) {
    final theme = Theme.of(context);
    final self = person['id']?.toString() == _me;
    final id = person['id']?.toString();

    if (!_hasBalance(person)) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (heading != null) ...[
            Text(heading, style: theme.textTheme.titleMedium),
            const SizedBox(height: 12),
          ],
          Text(
            _allowance(person),
            key: const ValueKey('tour-work_hours-pto-allowance'),
            // headlineMedium, not displaySmall. A TextTheme slot theme.dart
            // leaves out does NOT fall back to Material's default — it renders
            // with no size and no weight at all, at whatever the surrounding
            // text happens to be. `theme_conventions_test` caught this one, and
            // the fix is a slot that exists rather than defining a new one in
            // theme.dart, which is design's file rather than HR's.
            style: theme.textTheme.headlineMedium,
          ),

          // Blank, and deliberately kept rather than removed: when a "taken"
          // arrives it lands here without the number above it moving.
          const SizedBox(height: 6),
          SizedBox(
            height: 18,
            child: person['taken'] == null
                ? null
                : Text(
                    '${person['taken']} taken',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.outline,
                    ),
                  ),
          ),
          const SizedBox(height: 16),
          _whenBox(context, person),
        ],
      );
    }

    final recording = _recording == id && !phone;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (heading != null) ...[
          Text(heading, style: theme.textTheme.titleMedium),
          const SizedBox(height: 12),
        ],
        Wrap(
          spacing: 8,
          runSpacing: 8,
          alignment: WrapAlignment.spaceBetween,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            _standing(context, person),
            // Only with the server's say-so: the permission is HR's to decide
            // per person, and a button that answers 403 is a promise this
            // screen cannot keep. A wrap, not a row: beside a long name the
            // button was wider than the space left and painted past the card.
            if (person['can_edit_used'] == true && !recording)
              FilledButton.icon(
                key: const Key('pto-record-days'),
                onPressed: () => setState(() {
                  _recording = id;
                  _removeError = null;
                }),
                icon: const Icon(Icons.event_available, size: 18),
                label: const Text('Record days off'),
              ),
          ],
        ),
        const SizedBox(height: 14),
        _tiles(context, person, phone: phone),
        if (recording) ...[
          const SizedBox(height: 14),
          _recorder(person, phone: false),
        ],
        const SizedBox(height: 16),
        if (phone) ...[
          _resetsCard(context, person, self: self, phone: true),
          const SizedBox(height: 12),
          _whenBox(context, person),
        ] else
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(
                  flex: 2,
                  child: _resetsCard(context, person, self: self, phone: false),
                ),
                const SizedBox(width: 12),
                Expanded(child: _whenBox(context, person)),
              ],
            ),
          ),
        const SizedBox(height: 20),
        _timeline(context, person),
      ],
    );
  }

  /// "Can use now · Technician · Started 4/12/2023" — the panel's first answer.
  Widget _standing(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);
    final started = person['started_on'];
    final from = person['eligible_from'];
    final role = _roleName(person);
    final quiet = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.outline,
    );

    return Wrap(
      spacing: 10,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (person['eligible'] == true)
          const StatusPill('Can use now', tone: Tone.ok)
        else if (from == null)
          const StatusPill('Start date unknown')
        else
          StatusPill('From ${_date(from)}', tone: Tone.caution),
        if (role != null) Text(role, style: quiet),
        if (started != null) Text('Started ${_date(started)}', style: quiet),
      ],
    );
  }

  /// Total, Used, Left and Expired — always all four, so the panel does not
  /// change shape between people (Jose, 2026-10-22). One row on a desktop,
  /// two by two on a phone.
  Widget _tiles(
    BuildContext context,
    Map<String, dynamic> person, {
    required bool phone,
  }) {
    final scheme = Theme.of(context).colorScheme;
    final caution = ToneColours.of(context, Tone.caution).ink;
    final total = _num(person['total']);
    final used = _num(person['used']);
    final left = _num(person['left']);
    final carried = _num(person['carried']) ?? 0;
    // Days lost at past resets. HR sends 0 when carry-over is on and null
    // with no start date — the tile is there either way, as a 0 or a dash.
    final expired = _num(person['expired']);

    Color? tone(num? value, {Color? alarm}) {
      if (alarm != null) return alarm;
      return value == null || value == 0 ? scheme.outline : null;
    }

    final tiles = [
      _tile(
        context,
        key: const Key('pto-tile-total'),
        label: 'Total',
        value: total,
        colour: tone(total),
        note: total != null && carried > 0
            ? '${_days(total - carried)} this period + '
                  '${_days(carried)} carried'
            : null,
      ),
      _tile(
        context,
        key: const Key('pto-tile-used'),
        label: 'Used',
        value: used,
        colour: tone(used),
      ),
      _tile(
        context,
        key: const Key('pto-tile-left'),
        label: 'Left',
        value: left,
        colour: tone(left, alarm: left != null && left <= 0 ? scheme.error : null),
      ),
      _tile(
        context,
        key: const Key('pto-tile-expired'),
        label: 'Expired',
        value: expired,
        colour: tone(
          expired,
          alarm: expired != null && expired > 0 ? caution : null,
        ),
        note: 'lost at past resets',
      ),
    ];

    Widget line(List<Widget> row) => IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < row.length; i++) ...[
            if (i > 0) const SizedBox(width: 8),
            Expanded(child: row[i]),
          ],
        ],
      ),
    );

    return Column(
      key: const ValueKey('tour-work_hours-pto-allowance'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: phone
          ? [
              line(tiles.sublist(0, 2)),
              const SizedBox(height: 8),
              line(tiles.sublist(2)),
            ]
          : [line(tiles)],
    );
  }

  Widget _tile(
    BuildContext context, {
    required Key key,
    required String label,
    required num? value,
    Color? colour,
    String? note,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      key: key,
      padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: theme.textTheme.bodySmall?.copyWith(
              color: scheme.outline,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            // An em dash, not 0: a missing figure is "not kept", and zero
            // would claim somebody has used none or has none left.
            value == null ? '—' : _days(value),
            style: theme.textTheme.headlineMedium?.copyWith(color: colour),
          ),
          if (note != null)
            Text(
              note,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
        ],
      ),
    );
  }

  /// The reset: a countdown ring, the period as a bar, the policy, and the
  /// resets that already happened.
  Widget _resetsCard(
    BuildContext context,
    Map<String, dynamic> person, {
    required bool self,
    required bool phone,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final caution = ToneColours.of(context, Tone.caution);
    final reset = _policy['reset'];
    final end = PtoMaths.day(person['resets_on']);
    final start = PtoMaths.day(person['period_start']);
    final today = PtoMaths.today();
    final wouldExpire = _num(person['would_expire']) ?? 0;

    String? line;
    int? away;
    if (end != null) {
      away = PtoMaths.daysBetween(today, end);
      final whose = self ? 'your' : 'their';
      // jan_1 is HR's default, so an absent value reads as it.
      final when = switch (reset) {
        'anniversary' => '$whose work anniversary',
        'period' => 'at the start of each ${switch (_policy['allowance_period']) {
          'week' => 'week',
          'month' => 'month',
          'quarter' => 'quarter',
          _ => 'year',
        }}',
        _ => 'every January 1',
      };
      line = 'Resets ${_formats.formatDate(end)}, $when';
    }

    final elapsed = PtoMaths.elapsed(start: start, end: end, today: today);
    final shares = PtoMaths.shares(
      total: _num(person['total']),
      used: _num(person['used']),
      wouldExpire: wouldExpire,
    );

    final resets = _resets(person);
    final quiet = theme.textTheme.bodySmall?.copyWith(color: scheme.outline);

    final details = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (line != null) ...[
          Text(line, style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
        ],
        if (end != null) ...[
          _periodBar(context, elapsed: elapsed ?? 0, shares: shares),
          const SizedBox(height: 4),
          _barEnds(context, start: start, end: end, elapsed: elapsed),
          const SizedBox(height: 8),
        ],
        Text(_policyLine(), style: quiet),
        if (wouldExpire > 0) ...[
          const SizedBox(height: 2),
          Text(
            '${_days(wouldExpire)} would expire if none are used',
            key: const Key('pto-would-expire'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: caution.ink,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
        const SizedBox(height: 10),
        _pastResets(context, person, resets),
      ],
    );

    return Container(
      key: const Key('pto-resets'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (away != null) ...[
            _ring(context, days: away, elapsed: elapsed ?? 0, compact: phone),
            SizedBox(width: phone ? 12 : 16),
          ],
          Expanded(child: details),
        ],
      ),
    );
  }

  /// The days to the next reset in the middle, and how much of the period is
  /// behind us as the arc.
  Widget _ring(
    BuildContext context, {
    required int days,
    required double elapsed,
    required bool compact,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final size = compact ? 60.0 : 88.0;
    final left = days < 0 ? 0 : days;

    final number = Text(
      '$left',
      style: (compact
              ? theme.textTheme.titleMedium
              : theme.textTheme.headlineSmall)
          ?.copyWith(height: 1),
    );
    // On a phone the word is "days", which fits in the hole. On a desk
    // "days left" is wider than the ring, and drawing it in the hole
    // painted it across the stroke, so it sits underneath.
    final dial = SizedBox(
      key: const Key('pto-ring'),
      width: size,
      height: size,
      child: Stack(
        fit: StackFit.expand,
        children: [
          CircularProgressIndicator(
            value: elapsed,
            strokeWidth: compact ? 6 : 8,
            color: scheme.primary,
            backgroundColor: scheme.surfaceContainerHighest,
          ),
          Center(
            child: Padding(
              padding: EdgeInsets.all(compact ? 8 : 14),
              child: FittedBox(
                fit: BoxFit.scaleDown,
                child: compact
                    ? Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          number,
                          Text(
                            left == 1 ? 'day' : 'days',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: scheme.outline,
                              height: 1,
                            ),
                          ),
                        ],
                      )
                    : number,
              ),
            ),
          ),
        ],
      ),
    );
    if (compact) return dial;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        dial,
        const SizedBox(height: 4),
        Text(
          left == 1 ? 'day left' : 'days left',
          textAlign: TextAlign.center,
          style: theme.textTheme.labelSmall?.copyWith(color: scheme.outline),
        ),
      ],
    );
  }

  /// The period from the last reset to the next: the days used in the brand
  /// colour, the days that would expire in the warning tone after them, and a
  /// marker where today falls in the period.
  Widget _periodBar(
    BuildContext context, {
    required double elapsed,
    required ({double used, double wouldExpire}) shares,
  }) {
    final scheme = Theme.of(context).colorScheme;
    final caution = ToneColours.of(context, Tone.caution).ink;

    return ClipRect(
      child: SizedBox(
      key: const Key('pto-period-bar'),
      height: 16,
      child: Stack(
        children: [
          Positioned.fill(
            top: 2,
            bottom: 2,
            child: ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: Row(
                children: [
                  if (shares.used > 0)
                    Expanded(
                      key: const Key('pto-bar-used'),
                      flex: (shares.used * 1000).round(),
                      child: ColoredBox(color: scheme.primary),
                    ),
                  if (shares.wouldExpire > 0)
                    Expanded(
                      key: const Key('pto-bar-would-expire'),
                      flex: (shares.wouldExpire * 1000).round(),
                      child: ColoredBox(color: caution),
                    ),
                  if (shares.used + shares.wouldExpire < 1)
                    Expanded(
                      flex: ((1 - shares.used - shares.wouldExpire) * 1000)
                          .round(),
                      child: ColoredBox(color: scheme.surfaceContainerHighest),
                    ),
                ],
              ),
            ),
          ),
          Align(
            key: const Key('pto-bar-today'),
            alignment: Alignment(elapsed * 2 - 1, 0),
            child: Container(width: 3, height: 16, color: scheme.onSurface),
          ),
        ],
      ),
      ),
    );
  }

  Widget _barEnds(
    BuildContext context, {
    required DateTime? start,
    required DateTime end,
    required double? elapsed,
  }) {
    final theme = Theme.of(context);
    final style = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.outline,
    );

    return ClipRect(
      child: Stack(
      children: [
        // Each end may shrink: two long date formats in a phone's card are
        // wider than the card.
        Row(
          children: [
            Expanded(
              child: start == null
                  ? const SizedBox.shrink()
                  : Text(
                      _formats.formatDate(start),
                      style: style,
                      overflow: TextOverflow.ellipsis,
                    ),
            ),
            Expanded(
              child: Text(
                _formats.formatDate(end),
                style: style,
                textAlign: TextAlign.end,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
        if (elapsed != null && elapsed > 0.3 && elapsed < 0.7)
          Align(
            alignment: Alignment(elapsed * 2 - 1, 0),
            child: Text('today', style: style),
          ),
      ],
      ),
    );
  }

  /// `resets` from HR when it sends them (newest first), or null when the
  /// answer predates them.
  List<Map<String, dynamic>>? _resets(Map<String, dynamic> person) {
    final raw = person['resets'];
    if (raw is! List) return null;
    return [
      for (final r in raw)
        if (r is Map) Map<String, dynamic>.from(r),
    ];
  }

  /// "Dec 17, 2025 · 2 expired" per past reset, or the one reset this period
  /// started from when the server does not list them.
  Widget _pastResets(
    BuildContext context,
    Map<String, dynamic> person,
    List<Map<String, dynamic>>? resets,
  ) {
    final theme = Theme.of(context);
    final quiet = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.outline,
    );

    // HR's list, when sent, is the whole answer: empty means no reset has
    // happened yet (or there is no start date to count one from).
    if (resets != null && resets.isEmpty) {
      return Text(
        'No resets yet',
        key: const Key('pto-no-resets'),
        style: quiet,
      );
    }

    if (resets == null) {
      final start = person['period_start'];
      if (start == null) return const SizedBox.shrink();
      return Text(
        'Last reset ${_date(start)}',
        key: const Key('pto-last-reset'),
        style: quiet,
      );
    }

    return Column(
      key: const Key('pto-past-resets'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Past resets',
          style: quiet?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 4),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final r in resets)
              StatusPill(
                '${_date(r['on'])} · '
                '${_resetWords(_num(r['expired']), _num(r['carried']))}',
                tone: (_num(r['expired']) ?? 0) > 0
                    ? Tone.caution
                    : Tone.neutral,
              ),
          ],
        ),
      ],
    );
  }

  /// "2 expired", "3 carried", both, or "nothing expired".
  String _resetWords(num? expired, num? carried) {
    final words = [
      if ((expired ?? 0) > 0) '${_days(expired!)} expired',
      if ((carried ?? 0) > 0) '${_days(carried!)} carried',
    ];
    return words.isEmpty ? 'nothing expired' : words.join(' · ');
  }

  /// "Earns 10 days a year · usable from 90 days after starting · unused days
  /// carry over" — the setting's own words, as the when box uses them.
  String _policyLine() {
    final days = _num(_policy['allowance']) ?? 0;
    final period = switch (_policy['allowance_period']) {
      'week' => 'a week',
      'month' => 'a month',
      'quarter' => 'a quarter',
      _ => 'a year',
    };
    final waiting = (_policy['waiting'] as int? ?? 0) == 0
        ? 'usable from the first day'
        : 'usable from ${_waitingPhrase()} after starting';
    final accrueAfter = _policy['accrue_after'] as int? ?? 0;

    return [
      'Earns ${days == 1 ? '1 day' : '${_days(days)} days'} $period',
      if (accrueAfter > 0)
        'starts accumulating ${_span(accrueAfter, _policy['accrue_after_unit'])} after starting',
      waiting,
      if (_policy['carry_over'] == true) 'unused days carry over',
    ].join(' · ');
  }

  // ── the timeline ─────────────────────────────────────────────────

  /// Everything that moved this person's balance, newest first, in one
  /// stream: days off (consecutive days as one run), the days earned,
  /// corrections to the total, resets and the day they started.
  Widget _timeline(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);
    final canEdit = person['can_edit_used'] == true;
    final entries = PtoMaths.timeline(person);

    return Column(
      key: const Key('pto-timeline'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Timeline',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.outline,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        if (_removeError != null) ...[
          Text(
            _removeError!,
            key: const Key('pto-remove-refused'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
          const SizedBox(height: 6),
        ],
        if (entries.isEmpty)
          Text('Nothing yet.', style: theme.textTheme.bodySmall)
        else
          for (var i = 0; i < entries.length; i++)
            _timelineEntry(
              context,
              person,
              entries[i],
              last: i == entries.length - 1,
              canEdit: canEdit,
            ),
      ],
    );
  }

  Widget _timelineEntry(
    BuildContext context,
    Map<String, dynamic> person,
    PtoEvent event, {
    required bool last,
    required bool canEdit,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final tone = ToneColours.of(context, switch (event.kind) {
      PtoEventKind.daysOff => Tone.info,
      PtoEventKind.earned => Tone.ok,
      PtoEventKind.correction => Tone.review,
      PtoEventKind.reset => Tone.caution,
      PtoEventKind.started => Tone.ok,
    });

    final (String title, List<String> detail) = switch (event.kind) {
      PtoEventKind.earned => (
        'Earned · ${_formats.formatDate(event.on)} (${event.days == 1 ? '1 day' : '${_days(event.days ?? 0)} days'})',
        const <String>[],
      ),
      PtoEventKind.daysOff => (
        'Days off · ${event.run!.label} (${event.run!.daysWords})',
        [
          if (event.byName != null) 'recorded by ${event.byName}',
          if (event.at != null) _formats.formatDate(event.at!),
          if ((event.reason ?? '').isNotEmpty) '“${event.reason}”',
        ],
      ),
      PtoEventKind.correction => (
        'Total corrected ${_orDash(event.from)} → ${_orDash(event.to)}',
        [
          if (event.byName != null) event.byName!,
          if (event.at != null) _formats.formatDate(event.at!),
          if ((event.reason ?? '').isNotEmpty) '“${event.reason}”',
        ],
      ),
      PtoEventKind.reset => (
        'Reset · ${_formats.formatDate(event.on)}',
        [_resetWords(event.expired, event.carried)],
      ),
      PtoEventKind.started => ('Started · ${_formats.formatDate(event.on)}', []),
    };

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 18,
            child: Column(
              children: [
                const SizedBox(height: 4),
                Container(
                  width: 10,
                  height: 10,
                  decoration: BoxDecoration(
                    color: tone.ink,
                    shape: BoxShape.circle,
                  ),
                ),
                if (!last)
                  Expanded(
                    child: Container(width: 2, color: scheme.outlineVariant),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Flexible(
                        child: Text(
                          title,
                          key: Key('pto-event-${event.kind.name}'),
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                      // Beside the words. At the end of the row it sat on the
                      // far side of the panel, a screen away from the day it
                      // removes.
                      if (event.kind == PtoEventKind.daysOff && canEdit)
                        IconButton(
                          key: Key('pto-remove-${event.run!.ids.first}'),
                          tooltip: 'Remove these days',
                          visualDensity: VisualDensity.compact,
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(
                            minWidth: 28,
                            minHeight: 28,
                          ),
                          iconSize: 18,
                          onPressed: () => _removeRun(person, event.run!),
                          icon: const Icon(Icons.close),
                        ),
                    ],
                  ),
                  if (detail.isNotEmpty)
                    Text(
                      detail.join(' · '),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: scheme.outline,
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  String _orDash(num? value) => value == null ? '—' : _days(value);

  Future<void> _removeRun(Map<String, dynamic> person, PtoDayRun run) async {
    final pid = '${person['id']}';
    setState(() => _removeError = null);

    Map<String, dynamic>? balance;
    for (final day in run.ids) {
      final response = await widget.api.delete('/hr/people/$pid/pto/days/$day');
      if (!mounted) return;
      if (!response.ok) {
        setState(() => _removeError = response.reason);
        break;
      }
      if (response.data case final Map data) {
        balance = Map<String, dynamic>.from(data);
      }
    }

    if (balance != null) _merge(pid, balance, _daysKeys);
    await _load(quiet: true);
  }

  // ── recording days off ───────────────────────────────────────────

  static const _daysKeys = ['total', 'used', 'left', 'would_expire'];
  static const _usedKeys = ['total', 'used', 'left', 'carried', 'resets_on'];

  /// The server answers with the new balance, so the tiles take it from there
  /// at once. Only the balance fields: anything else in the answer is not
  /// this row's to overwrite.
  void _merge(String id, Map<String, dynamic> saved, List<String> keys) {
    setState(() {
      _people = [
        for (final p in _people)
          if ('${p['id']}' == id)
            {
              ...p,
              for (final key in keys)
                if (saved.containsKey(key)) key: saved[key],
              // The correction itself, so the timeline has it without a read.
              if (saved['used_change'] case final Map change)
                'used_changes': [
                  Map<String, dynamic>.from(change),
                  ...?(p['used_changes'] as List?),
                ],
            }
          else
            p,
      ];
    });
  }

  Widget _recorder(Map<String, dynamic> person, {required bool phone}) {
    final id = '${person['id']}';

    return _RecordDaysOff(
      key: ValueKey('pto-recorder-$id'),
      api: widget.api,
      person: person,
      phone: phone,
      onCancel: () => setState(() => _recording = null),
      onSavedDays: (balance) async {
        _merge(id, balance, _daysKeys);
        setState(() => _recording = null);
        await _load(quiet: true);
      },
      onSavedTotal: (balance) {
        _merge(id, balance, _usedKeys);
        setState(() => _recording = null);
      },
    );
  }

  /// "When they can be used" — her question, in its own box, said about the
  /// person rather than to the reader (Jose, 2026-10-22).
  Widget _whenBox(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    final waiting = _waitingPhrase();
    final noWait = (_policy['waiting'] as int? ?? 0) == 0;
    final unknown = person['started_on'] == null;
    final eligible = person['eligible'] == true;

    // Eligible is asked FIRST, because a policy with no waiting period makes
    // the unknown start date stop mattering: there is nothing to count from
    // when there is nothing to count. Asking `unknown` first would tell
    // everybody who predates the employment record that we cannot say, about a
    // policy that opens on day one for anybody.
    final (String value, String why) = switch ((eligible, unknown)) {
      (true, _) => (
        'Now',
        noWait
            ? 'Paid time off can be used from somebody’s first day here.'
            : 'Paid time off becomes available $waiting after somebody starts '
                  'here, and that has passed.',
      ),
      // NOT "somebody in HR can add it". There is no way to record a start
      // date that already happened: both writers of a `started` employment
      // event stamp today, so the only action resembling that advice —
      // deactivate and reactivate — would manufacture a confidently wrong date
      // ninety days out and fire `user.activated` on the way past. UI/UX caught
      // that in a sentence I proposed; it explains instead of pointing at a
      // door that does harm.
      (false, true) => (
        'Not known',
        'Paid time off becomes available $waiting after somebody starts here, '
            'and there is no record of when they started — so any date would '
            'be a guess. Start dates have only been kept since this was added, '
            'so people who were already here do not have one.',
      ),
      (false, false) => (
        _date(person['eligible_from']),
        // The SETTING's own words — "90 days" or "3 months", whichever was
        // chosen — never a phrase describing it. A hardcoded "three months"
        // would go stale the moment somebody changed the number, and would
        // then be wrong in both directions at once.
        'Paid time off becomes available $waiting after somebody starts here. '
            'They started on ${_date(person['started_on'])}.',
      ),
    };

    return Container(
      key: const ValueKey('tour-work_hours-pto-when'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'When they can be used',
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.outline),
          ),
          const SizedBox(height: 6),
          Text(
            value,
            style: theme.textTheme.titleLarge?.copyWith(
              color: unknown && !eligible ? scheme.outline : null,
            ),
          ),
          const SizedBox(height: 8),
          Text(why, style: theme.textTheme.bodySmall),
        ],
      ),
    );
  }

  String _date(Object? iso) {
    final at = iso is String ? DateTime.tryParse(iso) : null;

    return at == null ? 'an unknown date' : _formats.formatDate(at);
  }

  /// A number the server may send as 5, 5.0 or "5" — or not at all.
  static num? _num(Object? value) =>
      value is num ? value : num.tryParse('${value ?? ''}');

  /// 5, or 2.5 — never "5.0".
  static String _days(num value) =>
      value == value.roundToDouble() ? '${value.round()}' : '$value';
}

enum _Filter { everyone, canUse, notYet }

// ── the maths, kept out of the widgets so a test can hold it ─────────

/// One run of consecutive days off recorded with the same reason by the same
/// person: "Nov 3–4 (2 days)".
class PtoDayRun {
  const PtoDayRun({
    required this.first,
    required this.last,
    required this.days,
    required this.ids,
    this.reason,
    this.byName,
    this.at,
  });

  final DateTime first;
  final DateTime last;

  /// Full days count 1 and half days 0.5.
  final num days;
  final List<String> ids;
  final String? reason;
  final String? byName;
  final DateTime? at;

  /// "Nov 3", "Nov 3–4", "Oct 31–Nov 1".
  String get label {
    final a = '${PtoMaths.month(first)} ${first.day}';
    if (first == last) return a;
    if (first.month == last.month && first.year == last.year) {
      return '$a–${last.day}';
    }
    return '$a–${PtoMaths.month(last)} ${last.day}';
  }

  /// "2 days", "1 day", "half a day", "1.5 days".
  String get daysWords {
    if (days == 0.5) return 'half a day';
    if (days == 1) return '1 day';
    return '${_PtoTabState._days(days)} days';
  }
}

enum PtoEventKind { daysOff, earned, correction, reset, started }

/// One line of the timeline.
class PtoEvent {
  const PtoEvent({
    required this.kind,
    required this.on,
    this.run,
    this.at,
    this.byName,
    this.reason,
    this.from,
    this.to,
    this.expired,
    this.carried,
    this.days,
  });

  final PtoEventKind kind;

  /// The day it is placed by.
  final DateTime on;
  final PtoDayRun? run;
  final DateTime? at;
  final String? byName;
  final String? reason;
  final num? from;
  final num? to;
  final num? expired;
  final num? carried;

  /// Days added by an earn grant.
  final num? days;
}

abstract final class PtoMaths {
  static const _months = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', //
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  static const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June', //
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  static String month(DateTime at) => _months[at.month - 1];

  /// Today as a date with no time in it.
  static DateTime today() {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  /// A server date ("2026-11-03", or a timestamp) as a local calendar day.
  static DateTime? day(Object? iso) {
    final at = iso is String ? DateTime.tryParse(iso) : null;
    if (at == null) return null;
    // A bare date parses as local midnight; a stamp is moved to local first.
    final local = iso is String && iso.length > 10 ? at.toLocal() : at;
    return DateTime(local.year, local.month, local.day);
  }

  /// Whole days from [a] to [b], counted between UTC midnights: two local
  /// midnights either side of a clock change are 23 or 25 hours apart, and
  /// inDays would drop one in the spring.
  static int daysBetween(DateTime a, DateTime b) => DateTime.utc(
    b.year,
    b.month,
    b.day,
  ).difference(DateTime.utc(a.year, a.month, a.day)).inDays;

  /// How much of the period is behind [today], 0 to 1 — the ring's arc and
  /// the bar's today marker. Null when the period has no length to measure.
  static double? elapsed({
    required DateTime? start,
    required DateTime? end,
    required DateTime today,
  }) {
    if (start == null || end == null) return null;
    final span = daysBetween(start, end);
    if (span <= 0) return null;
    return (daysBetween(start, today) / span).clamp(0.0, 1.0).toDouble();
  }

  /// The bar's two coloured parts as fractions of the total: used first, then
  /// what would expire, which never runs past the end.
  static ({double used, double wouldExpire}) shares({
    required num? total,
    required num? used,
    required num? wouldExpire,
  }) {
    if (total == null || total <= 0) return (used: 0.0, wouldExpire: 0.0);
    final u = ((used ?? 0) / total).clamp(0.0, 1.0).toDouble();
    final w = ((wouldExpire ?? 0) / total).clamp(0.0, 1.0 - u).toDouble();
    return (used: u, wouldExpire: w);
  }

  /// Tap once for a full day, again for a half, a third time to clear.
  static double? cycle(double? portion) => switch (portion) {
    null => 1,
    1 => 0.5,
    _ => null,
  };

  /// Days off as runs of consecutive dates. A run breaks where a day is
  /// missing, or where the reason or the person who recorded it changes.
  static List<PtoDayRun> runs(List<Object?> daysOff) {
    final days = <({DateTime date, num portion, String id, String? reason, String? by, DateTime? at})>[
      for (final d in daysOff)
        if (d is Map)
          if (day(d['date']) case final date?)
            (
              date: date,
              portion: _PtoTabState._num(d['portion']) ?? 1,
              id: '${d['id']}',
              reason: d['reason'] as String?,
              by: d['by_name'] as String?,
              at: day(d['at']),
            ),
    ]..sort((a, b) => a.date.compareTo(b.date));

    final runs = <PtoDayRun>[];
    for (final d in days) {
      final previous = runs.isEmpty ? null : runs.last;
      if (previous != null &&
          daysBetween(previous.last, d.date) == 1 &&
          previous.reason == d.reason &&
          previous.byName == d.by) {
        runs.last = PtoDayRun(
          first: previous.first,
          last: d.date,
          days: previous.days + d.portion,
          ids: [...previous.ids, d.id],
          reason: previous.reason,
          byName: previous.byName,
          at: previous.at == null || (d.at != null && d.at!.isAfter(previous.at!))
              ? d.at
              : previous.at,
        );
      } else {
        runs.add(
          PtoDayRun(
            first: d.date,
            last: d.date,
            days: d.portion,
            ids: [d.id],
            reason: d.reason,
            byName: d.by,
            at: d.at,
          ),
        );
      }
    }
    return runs;
  }

  /// The whole timeline for one row, newest first.
  static List<PtoEvent> timeline(Map<String, dynamic> person) {
    final events = <PtoEvent>[
      for (final run in runs(person['days_off'] as List? ?? const []))
        PtoEvent(
          kind: PtoEventKind.daysOff,
          on: run.last,
          run: run,
          at: run.at,
          byName: run.byName,
          reason: run.reason,
        ),
      for (final e in person['earned'] as List? ?? const [])
        if (e is Map)
          if (day(e['on']) case final on?)
            PtoEvent(
              kind: PtoEventKind.earned,
              on: on,
              days: _PtoTabState._num(e['days']),
            ),
      for (final c in person['used_changes'] as List? ?? const [])
        if (c is Map)
          if (day(c['at']) case final at?)
            PtoEvent(
              kind: PtoEventKind.correction,
              on: at,
              at: at,
              byName: c['by_name'] as String?,
              reason: c['reason'] as String?,
              from: _PtoTabState._num(c['from']),
              to: _PtoTabState._num(c['to']),
            ),
    ];

    final started = day(person['started_on']);
    final resets = person['resets'];
    if (resets is List) {
      for (final r in resets) {
        if (r is Map) {
          if (day(r['on']) case final on?) {
            events.add(
              PtoEvent(
                kind: PtoEventKind.reset,
                on: on,
                expired: _PtoTabState._num(r['expired']),
                carried: _PtoTabState._num(r['carried']),
              ),
            );
          }
        }
      }
    } else if (day(person['period_start']) case final start?) {
      // Without HR's list, the one reset known is the one this period began
      // at — unless that is simply the day they started.
      if (started == null || start != started) {
        events.add(PtoEvent(kind: PtoEventKind.reset, on: start));
      }
    }
    if (started != null) {
      events.add(PtoEvent(kind: PtoEventKind.started, on: started));
    }

    // Newest first; on one day the start sits below what happened on it.
    events.sort((a, b) {
      final byDay = b.on.compareTo(a.on);
      if (byDay != 0) return byDay;
      return a.kind.index.compareTo(b.kind.index);
    });
    return events;
  }

  static String iso(DateTime at) =>
      '${at.year}-${at.month.toString().padLeft(2, '0')}-'
      '${at.day.toString().padLeft(2, '0')}';
}

// ── the recorder: a month calendar, a reason, Save ───────────────────

/// Record which days somebody was off — scattered days, full or half — or
/// correct the total when there are no dates to give.
class _RecordDaysOff extends StatefulWidget {
  const _RecordDaysOff({
    super.key,
    required this.api,
    required this.person,
    required this.phone,
    required this.onCancel,
    required this.onSavedDays,
    required this.onSavedTotal,
  });

  final ApiClient api;
  final Map<String, dynamic> person;
  final bool phone;
  final VoidCallback onCancel;
  final ValueChanged<Map<String, dynamic>> onSavedDays;
  final ValueChanged<Map<String, dynamic>> onSavedTotal;

  @override
  State<_RecordDaysOff> createState() => _RecordDaysOffState();
}

class _RecordDaysOffState extends State<_RecordDaysOff> {
  late DateTime _month = DateTime(PtoMaths.today().year, PtoMaths.today().month);
  final Map<DateTime, double> _picked = {};
  final _reason = TextEditingController();
  bool _saving = false;
  String? _refused;
  bool _correcting = false;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  /// The days already recorded, which the calendar tints and will not pick.
  Set<DateTime> get _recorded => {
    for (final d in widget.person['days_off'] as List? ?? const [])
      if (d is Map) ?PtoMaths.day(d['date']),
  };

  num get _total => _picked.values.fold<num>(0, (a, b) => a + b);

  Future<void> _save() async {
    if (_picked.isEmpty) return;
    final days = _picked.keys.toList()..sort();
    final reason = _reason.text.trim();

    setState(() {
      _saving = true;
      _refused = null;
    });
    final response = await widget.api.post(
      '/hr/people/${widget.person['id']}/pto/days',
      {
        'days': [
          for (final d in days)
            {'date': PtoMaths.iso(d), 'portion': _picked[d] == 1 ? 1 : 0.5},
        ],
        if (reason.isNotEmpty) 'reason': reason,
      },
    );
    if (!mounted) return;

    if (response.ok) {
      final data = response.data;
      widget.onSavedDays(
        data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{},
      );
      return;
    }

    setState(() {
      _saving = false;
      // A date already recorded comes back as a 422 whose top-level message
      // is the sentence ("Already recorded as a day off: …"); its `errors`
      // carry only the bare dates, which is what `reason` would pick.
      final message = response.body['message'];
      _refused = response.statusCode == 422 &&
              message is String &&
              message.isNotEmpty &&
              message != 'The given data was invalid.'
          ? message
          : response.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final name = '${widget.person['name']}'.split(' ').first;

    if (_correcting) {
      return _frame(
        context,
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Correct the total used', style: theme.textTheme.titleSmall),
            const SizedBox(height: 4),
            Text(
              'For a number with no dates behind it. The change is listed in '
              'the timeline with the reason.',
              style: theme.textTheme.bodySmall?.copyWith(color: scheme.outline),
            ),
            const SizedBox(height: 12),
            _ChangeUsedForm(
              api: widget.api,
              personId: '${widget.person['id']}',
              used: _PtoTabState._num(widget.person['used']),
              onSaved: widget.onSavedTotal,
              onCancel: widget.onCancel,
            ),
          ],
        ),
      );
    }

    final left = _PtoTabState._num(widget.person['left']);
    final leaves = left == null ? null : left - _total;
    final picked = _picked.keys.toList()..sort();

    final calendar = _Calendar(
      month: _month,
      picked: _picked,
      recorded: _recorded,
      phone: widget.phone,
      onMonth: (m) => setState(() => _month = m),
      onTap: (d) => setState(() {
        final next = PtoMaths.cycle(_picked[d]);
        if (next == null) {
          _picked.remove(d);
        } else {
          _picked[d] = next;
        }
      }),
    );

    final details = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!widget.phone)
          Text(
            'Record days off for $name',
            style: theme.textTheme.titleSmall,
          ),
        Text(
          'Tap the days. Tap again for a half day, a third time to clear.',
          style: theme.textTheme.bodySmall?.copyWith(color: scheme.outline),
        ),
        const SizedBox(height: 10),
        Text.rich(
          key: const Key('pto-days-picked'),
          TextSpan(
            children: [
              TextSpan(
                text: picked.isEmpty
                    ? 'No days picked yet'
                    : '${_total == 1 ? '1 day' : '${_PtoTabState._days(_total)} days'} picked: '
                          '${picked.map((d) => '${PtoMaths.month(d)} ${d.day}${_picked[d] == 0.5 ? ' (half)' : ''}').join(', ')}',
              ),
              if (leaves != null) ...[
                const TextSpan(text: ' · leaves '),
                TextSpan(
                  text: _PtoTabState._days(leaves),
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: leaves < 0 ? scheme.error : null,
                  ),
                ),
              ],
            ],
          ),
          style: theme.textTheme.bodyMedium,
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('pto-days-reason'),
          controller: _reason,
          decoration: const InputDecoration(
            labelText: 'Reason',
            hintText: 'Optional',
            isDense: true,
          ),
        ),
        if (_refused != null) ...[
          const SizedBox(height: 10),
          Text(
            _refused!,
            key: const Key('pto-days-refused'),
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.error),
          ),
        ],
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          alignment: WrapAlignment.spaceBetween,
          children: [
            TextButton(
              key: const Key('pto-correct-total'),
              onPressed: _saving
                  ? null
                  : () => setState(() => _correcting = true),
              child: const Text('Correct the total instead'),
            ),
            Wrap(
              spacing: 8,
              children: [
                TextButton(
                  key: const Key('pto-days-cancel'),
                  onPressed: _saving ? null : widget.onCancel,
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  key: const Key('pto-days-save'),
                  onPressed: _saving || _picked.isEmpty ? null : _save,
                  child: const Text('Save'),
                ),
              ],
            ),
          ],
        ),
      ],
    );

    return _frame(
      context,
      widget.phone
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [calendar, const SizedBox(height: 16), details],
            )
          : Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                calendar,
                const SizedBox(width: 20),
                Expanded(child: details),
              ],
            ),
    );
  }

  /// Bordered in the brand colour inline; bare when it is the whole screen.
  Widget _frame(BuildContext context, Widget child) {
    if (widget.phone) return child;
    final scheme = Theme.of(context).colorScheme;
    return Container(
      key: const Key('pto-recorder'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.primary, width: 2),
        borderRadius: BorderRadius.circular(10),
      ),
      child: child,
    );
  }
}

/// One month, Sunday first, with ‹ › to move between months.
class _Calendar extends StatelessWidget {
  const _Calendar({
    required this.month,
    required this.picked,
    required this.recorded,
    required this.phone,
    required this.onMonth,
    required this.onTap,
  });

  final DateTime month;
  final Map<DateTime, double> picked;
  final Set<DateTime> recorded;
  final bool phone;
  final ValueChanged<DateTime> onMonth;
  final ValueChanged<DateTime> onTap;

  @override
  Widget build(BuildContext context) {
    if (!phone) return _grid(context, 38);
    return LayoutBuilder(
      builder: (context, c) =>
          _grid(context, (c.maxWidth / 7).clamp(32.0, 48.0).toDouble()),
    );
  }

  Widget _grid(BuildContext context, double cell) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final recordedTone = ToneColours.of(context, Tone.info);
    final today = PtoMaths.today();

    final first = DateTime(month.year, month.month);
    final lead = first.weekday % 7; // Sunday = 0
    final count = DateTime(month.year, month.month + 1, 0).day;
    final cells = <DateTime?>[
      for (var i = 0; i < lead; i++) null,
      for (var d = 1; d <= count; d++) DateTime(month.year, month.month, d),
    ];
    while (cells.length % 7 != 0) {
      cells.add(null);
    }

    Widget day(DateTime? d) {
      if (d == null) return SizedBox(width: cell, height: cell);
      final portion = picked[d];
      final was = recorded.contains(d);
      final (Color? fill, Color? ink) = was
          ? (recordedTone.fill, recordedTone.ink)
          : portion == 1
          ? (scheme.primary, scheme.onPrimary)
          : portion == 0.5
          ? (scheme.primaryContainer, scheme.onPrimaryContainer)
          : (null, null);

      return Padding(
        padding: const EdgeInsets.all(1.5),
        child: Material(
          color: fill ?? Colors.transparent,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(6),
            side: d == today
                ? BorderSide(color: scheme.primary)
                : BorderSide.none,
          ),
          child: InkWell(
            key: Key('pto-day-${PtoMaths.iso(d)}'),
            borderRadius: BorderRadius.circular(6),
            // Already recorded: shown, and not offered again.
            onTap: was ? null : () => onTap(d),
            child: SizedBox(
              width: cell - 3,
              height: cell - 3,
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    '${d.day}',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: ink,
                      fontWeight: portion != null || was
                          ? FontWeight.w700
                          : null,
                    ),
                  ),
                  if (portion == 0.5)
                    Text(
                      '½',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: ink,
                        height: 1,
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    return SizedBox(
      key: const Key('pto-calendar'),
      width: cell * 7,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              IconButton(
                key: const Key('pto-cal-prev'),
                tooltip: 'Previous month',
                visualDensity: VisualDensity.compact,
                onPressed: () => onMonth(DateTime(month.year, month.month - 1)),
                icon: const Icon(Icons.chevron_left),
              ),
              Expanded(
                child: Text(
                  '${PtoMaths.monthNames[month.month - 1]} ${month.year}',
                  key: const Key('pto-cal-month'),
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleSmall,
                ),
              ),
              IconButton(
                key: const Key('pto-cal-next'),
                tooltip: 'Next month',
                visualDensity: VisualDensity.compact,
                onPressed: () => onMonth(DateTime(month.year, month.month + 1)),
                icon: const Icon(Icons.chevron_right),
              ),
            ],
          ),
          Row(
            children: [
              for (final w in const ['S', 'M', 'T', 'W', 'T', 'F', 'S'])
                SizedBox(
                  width: cell,
                  child: Text(
                    w,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: scheme.outline,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          for (var r = 0; r < cells.length; r += 7)
            Row(children: [for (final d in cells.sublist(r, r + 7)) day(d)]),
        ],
      ),
    );
  }
}

/// The number Used should be, and why — the reason is required, because the
/// change is listed in the timeline for everybody who can see this person.
class _ChangeUsedForm extends StatefulWidget {
  const _ChangeUsedForm({
    required this.api,
    required this.personId,
    required this.used,
    required this.onSaved,
    required this.onCancel,
  });

  final ApiClient api;
  final String personId;
  final num? used;
  final ValueChanged<Map<String, dynamic>> onSaved;
  final VoidCallback onCancel;

  @override
  State<_ChangeUsedForm> createState() => _ChangeUsedFormState();
}

class _ChangeUsedFormState extends State<_ChangeUsedForm> {
  late final _used = TextEditingController(
    text: widget.used == null ? '' : _PtoTabState._days(widget.used!),
  );
  final _reason = TextEditingController();

  bool _saving = false;
  String? _usedError;
  String? _reasonError;
  String? _refused;

  @override
  void dispose() {
    _used.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final used = num.tryParse(_used.text.trim());
    final reason = _reason.text.trim();

    setState(() {
      _usedError = used == null || used < 0
          ? 'Enter the number of days used.'
          : null;
      _reasonError = reason.isEmpty ? 'Say why it changed.' : null;
      _refused = null;
    });
    if (_usedError != null || _reasonError != null) return;

    setState(() => _saving = true);
    final response = await widget.api.put(
      '/hr/people/${widget.personId}/pto/used',
      {'used': used, 'reason': reason},
    );
    if (!mounted) return;

    if (response.ok) {
      final data = response.data;
      widget.onSaved(
        data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{},
      );
      return;
    }

    // A 403 or a 422 says why in the server's own words.
    setState(() {
      _saving = false;
      _refused = response.reason;
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('pto-used-number'),
          controller: _used,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: 'Days used',
            errorText: _usedError,
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('pto-used-reason'),
          controller: _reason,
          minLines: 2,
          maxLines: 4,
          decoration: InputDecoration(
            labelText: 'Reason',
            helperText: 'Shown with the change, with your name and the date.',
            errorText: _reasonError,
          ),
        ),
        if (_refused != null) ...[
          const SizedBox(height: 12),
          Text(
            _refused!,
            key: const Key('pto-used-refused'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
        const SizedBox(height: 20),
        Wrap(
          alignment: WrapAlignment.end,
          spacing: 10,
          children: [
            TextButton(
              onPressed: _saving ? null : widget.onCancel,
              child: const Text('Cancel'),
            ),
            FilledButton(
              key: const Key('pto-used-save'),
              onPressed: _saving ? null : _save,
              child: const Text('Save'),
            ),
          ],
        ),
      ],
    );
  }
}
