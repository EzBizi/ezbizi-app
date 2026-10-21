import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';

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

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
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

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          key: const ValueKey('tour-work_hours-pto-people'),
          width: 280,
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 12, 8, 24),
            itemCount: _people.length,
            itemBuilder: (context, i) => _row(context, _people[i]),
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
        subtitle: Text(_shortState(person), style: theme.textTheme.bodySmall),
        onTap: () => setState(() => _showing = id),
      ),
    );
  }

  /// The list's one-line version. It must not say a date is known when it is
  /// not — the panel explains, this only has to be true.
  String _shortState(Map<String, dynamic> person) {
    final days = _allowance(person);

    if (person['eligible'] == true) return days;
    if (person['started_on'] == null) return 'Start date unknown';

    return '$days, not yet';
  }

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
              title: Text('${person['name']}'),
              trailing: Text(
                _shortState(person),
                style: Theme.of(context).textTheme.bodySmall,
              ),
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

        // Blank, and deliberately kept rather than removed. Nothing records a
        // day off yet, so there is no "taken" to show — and when there is, it
        // lands here without the number above it moving. UI/UX drew the line
        // for exactly that and asked that it stay.
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
}
