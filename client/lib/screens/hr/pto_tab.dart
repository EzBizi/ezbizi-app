import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
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
/// Left per person, the day it resets, and a Change on Used for whoever may
/// edit it. HR is building that data, so every new field is read as optional
/// and a row or panel without it draws exactly what it drew before.
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

  /// The desktop list's search and its Everyone / Can use / Not yet filter.
  final _search = TextEditingController();
  _Filter _filter = _Filter.everyone;

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

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/hr/pto');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.reason;
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
            switch (_filter) {
              _Filter.everyone => true,
              _Filter.canUse => person['eligible'] == true,
              _Filter.notYet => person['eligible'] != true,
            })
          person,
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
        onTap: () => setState(() => _showing = id),
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

  /// "90 days", "3 months" — said the way the setting says it.
  ///
  /// Never converted to days: three months from 30 November is 28 February and
  /// ninety days is 1 March in three years out of four, so a conversion here
  /// would hand somebody a wrong date about their own leave.
  String _waitingPhrase() {
    final n = _policy['waiting'] as int? ?? 0;
    final word = switch (_policy['waiting_unit']) {
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

    // Somebody tapped: their panel, full screen, in place of the list. In
    // place rather than a pushed route, like the other HR records — the ←
    // is what the system Back presses (see pressInPageBack).
    if (_phoneOpen case final id?) {
      for (final person in _people) {
        if (person['id']?.toString() == id) {
          return SidePanel(
            key: const Key('pto-phone-person'),
            docked: true,
            title: '${person['name']}',
            onBack: () => setState(() => _phoneOpen = null),
            child: _panel(context, person),
          );
        }
      }
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
        if (mine != null) _panel(context, mine, heading: 'You'),
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
  }) {
    final theme = Theme.of(context);
    final self = person['id']?.toString() == _me;
    final changes = [
      for (final change in person['used_changes'] as List? ?? const [])
        if (change is Map) Map<String, dynamic>.from(change),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (heading != null) ...[
          Text(heading, style: theme.textTheme.titleMedium),
          const SizedBox(height: 12),
        ],
        if (_hasBalance(person)) ...[
          _standing(context, person),
          const SizedBox(height: 14),
          _tiles(context, person),
          const SizedBox(height: 16),
          _resetsCard(context, person, self: self),
        ] else ...[
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

          // Blank, and deliberately kept rather than removed. Nothing records
          // a day off yet, so there is no "taken" to show — and when there is,
          // it lands here without the number above it moving. UI/UX drew the
          // line for exactly that and asked that it stay.
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
        ],

        const SizedBox(height: 16),
        _whenBox(context, person),

        if (changes.isNotEmpty) ...[
          const SizedBox(height: 20),
          Text(
            'Changes to Used',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.outline,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 6),
          for (final change in changes)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Text(
                _changeLine(change),
                style: theme.textTheme.bodySmall,
              ),
            ),
        ],
      ],
    );
  }

  /// "Can use now · Started 4/12/2023" — the panel's first answer.
  Widget _standing(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);
    final started = person['started_on'];
    final from = person['eligible_from'];

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
        if (started != null)
          Text(
            'Started ${_date(started)}',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.outline,
            ),
          ),
      ],
    );
  }

  /// Total, Used and Left — and Expired when days were lost — as equal tiles,
  /// and the bar under them.
  Widget _tiles(BuildContext context, Map<String, dynamic> person) {
    final total = _num(person['total']);
    final used = _num(person['used']);
    final left = _num(person['left']);
    final carried = _num(person['carried']) ?? 0;
    // Days lost at past resets — Jose, 2026-10-22: with carry-over off he
    // wants to see them. HR sends 0 when carry-over is on and null with no
    // start date; neither is a loss, so neither gets a tile.
    final expired = _num(person['expired']) ?? 0;

    return Column(
      key: const ValueKey('tour-work_hours-pto-allowance'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: _tile(
                  context,
                  key: const Key('pto-tile-total'),
                  label: 'Total',
                  value: total,
                  note: total != null && carried > 0
                      ? '${_days(total - carried)} this year + '
                            '${_days(carried)} carried'
                      : null,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _tile(
                  context,
                  key: const Key('pto-tile-used'),
                  label: 'Used',
                  value: used,
                  // Only with the server's say-so: the permission is HR's to
                  // decide per person, and a button that answers 403 is a
                  // promise this screen cannot keep.
                  action: person['can_edit_used'] == true
                      ? TextButton(
                          key: const Key('pto-change-used'),
                          style: TextButton.styleFrom(
                            visualDensity: VisualDensity.compact,
                            padding: const EdgeInsets.symmetric(horizontal: 8),
                          ),
                          onPressed: () => _changeUsed(person),
                          child: const Text('Change'),
                        )
                      : null,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _tile(
                  context,
                  key: const Key('pto-tile-left'),
                  label: 'Left',
                  value: left,
                  alarm: left != null && left <= 0,
                ),
              ),
              if (expired > 0) ...[
                const SizedBox(width: 8),
                Expanded(
                  child: _tile(
                    context,
                    key: const Key('pto-tile-expired'),
                    label: 'Expired',
                    value: expired,
                    note: 'lost at past resets',
                  ),
                ),
              ],
            ],
          ),
        ),
        if (total != null && total > 0 && used != null) ...[
          const SizedBox(height: 10),
          LinearProgressIndicator(
            key: const Key('pto-used-bar'),
            value: (used / total).clamp(0, 1).toDouble(),
            minHeight: 8,
            borderRadius: BorderRadius.circular(99),
          ),
        ],
      ],
    );
  }

  Widget _tile(
    BuildContext context, {
    required Key key,
    required String label,
    required num? value,
    String? note,
    Widget? action,
    bool alarm = false,
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
            style: theme.textTheme.headlineMedium?.copyWith(
              color: alarm ? scheme.error : null,
            ),
          ),
          if (note != null) Text(note, style: theme.textTheme.bodySmall),
          ?action,
        ],
      ),
    );
  }

  /// "Resets 4/12/2027, their work anniversary · 173 days away", and the
  /// policy in one line under it.
  Widget _resetsCard(
    BuildContext context,
    Map<String, dynamic> person, {
    required bool self,
  }) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final reset = _policy['reset'];
    final on = person['resets_on'];
    final at = on is String ? DateTime.tryParse(on) : null;

    String? line;
    if (at != null) {
      // Counted between UTC midnights: two local midnights either side of a
      // clock change are 23 or 25 hours short of whole days, and inDays
      // would drop one in the spring.
      final now = DateTime.now();
      final away = DateTime.utc(at.year, at.month, at.day)
          .difference(DateTime.utc(now.year, now.month, now.day))
          .inDays;
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
      line =
          'Resets ${_formats.formatDate(at)}, $when'
          '${away > 0 ? ' · ${away == 1 ? '1 day' : '$away days'} away' : ''}';
    }

    return Container(
      key: const Key('pto-resets'),
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (line != null) ...[
            Text(line, style: theme.textTheme.bodyMedium),
            const SizedBox(height: 6),
          ],
          Text(
            _policyLine(),
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.outline),
          ),
        ],
      ),
    );
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

    return [
      'Earns ${days == 1 ? '1 day' : '${_days(days)} days'} $period',
      waiting,
      if (_policy['carry_over'] == true) 'unused days carry over',
    ].join(' · ');
  }

  /// `10/2/2026 · Pat Lin · 3 → 5 · "Two sick days"`.
  String _changeLine(Map<String, dynamic> change) {
    final from = _num(change['from']);
    final to = _num(change['to']);
    final reason = '${change['reason'] ?? ''}'.trim();

    return [
      _date(change['at']),
      if (change['by_name'] != null) '${change['by_name']}',
      '${from == null ? '—' : _days(from)} → ${to == null ? '—' : _days(to)}',
      if (reason.isNotEmpty) '“$reason”',
    ].join(' · ');
  }

  Future<void> _changeUsed(Map<String, dynamic> person) async {
    final saved = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (context) => SidePanel(
        title: 'Change Used',
        subtitle: '${person['name']}',
        onClose: () => Navigator.of(context).pop(),
        child: _ChangeUsedForm(
          api: widget.api,
          personId: '${person['id']}',
          used: _num(person['used']),
          onSaved: (balance) => Navigator.of(context).pop(balance),
          onCancel: () => Navigator.of(context).pop(),
        ),
      ),
    );

    if (saved == null || !mounted) return;

    // The server answers with the new balance, so the panel takes it from
    // there rather than reading the whole list again. Only the five balance
    // fields: anything else in the answer is not this row's to overwrite.
    final id = '${person['id']}';
    setState(() {
      _people = [
        for (final p in _people)
          if ('${p['id']}' == id)
            {
              ...p,
              for (final key in const [
                'total',
                'used',
                'left',
                'carried',
                'resets_on',
              ])
                if (saved.containsKey(key)) key: saved[key],
            }
          else
            p,
      ];
    });
  }

  /// "When you can use them" — her question, in its own box.
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
        'We cannot tell you',
        'Paid time off becomes available $waiting after somebody starts here, '
            'and there is no record of when you started — so any date would be '
            'a guess. Start dates have only been kept since this was added, so '
            'people who were already here do not have one.',
      ),
      (false, false) => (
        _date(person['eligible_from']),
        // The SETTING's own words — "90 days" or "3 months", whichever was
        // chosen — never a phrase describing it. A hardcoded "three months"
        // would go stale the moment somebody changed the number, and would
        // then be wrong in both directions at once.
        'Paid time off becomes available $waiting after somebody starts here. '
            'You started on ${_date(person['started_on'])}.',
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
            'When you can use them',
            style: theme.textTheme.bodySmall?.copyWith(color: scheme.outline),
          ),
          const SizedBox(height: 6),
          Text(
            value,
            style: theme.textTheme.titleLarge?.copyWith(
              color: unknown ? scheme.outline : null,
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

/// The number Used should be, and why — the reason is required, because the
/// change is listed under the tiles for everybody who can see this person.
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
