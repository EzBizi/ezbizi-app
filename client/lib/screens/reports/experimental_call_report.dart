import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../phone/recording_playback_bar.dart';
import '../phone/recording_player.dart';

/// One day of A-1's calls against the jobs they became (TEMPORARY).
///
/// This pane goes when EzBizi's own Phone and Jobs modules answer the question.
/// It reads RingCentral and Workiz and nothing in EzBizi, which is why it has
/// no view switcher and no place picker — there is nothing tenant-shaped in it.
///
/// ── The screen's job is to stop a number being read wrongly ─────
///
/// Three of the sentences here exist only for that:
///
/// 1. "Rang" and "answered" are different columns, because a queue call rings
///    three or four people until one picks up. The rang column is deliberately
///    NOT totalled — adding it would produce a number larger than the day's
///    calls that looks like the day's calls.
/// 2. The Workiz accounts covered are named. The accounts are disjoint and the
///    same people book into all of them, so a missing account makes a person's
///    job count wrong rather than merely omitting a region.
/// 3. A person known to only one of the two systems is marked. RingCentral's
///    "Adeline Pierre Louis-Aghaji" is Workiz's "Adeline Pierrelouis" and
///    nothing joins them; a near-match rule would credit one person's work to
///    another, which is worse than a visible duplicate.
class ExperimentalCallReport extends StatefulWidget {
  const ExperimentalCallReport({super.key, required this.api});

  final ApiClient api;

  static const double tableAbove = 900;

  @override
  State<ExperimentalCallReport> createState() => _ExperimentalCallReportState();
}

class _ExperimentalCallReportState extends State<ExperimentalCallReport> {
  DateTime _day = DateTime.now();
  Map<String, dynamic>? _report;
  String? _error;
  bool _loading = true;

  /// Which column the table is ordered by, and which way.
  ///
  /// Defaults to answered, descending: the question somebody opens this on a
  /// Monday morning is who took the calls, and alphabetical by first name
  /// answers nothing. Null would mean the server's order, which is
  /// alphabetical - available by tapping Person.
  String _sortBy = 'answered';
  bool _ascending = false;

  /// The person whose calls are open, by name. One at a time: several open
  /// rows turn a table into a wall and the comparison the table exists for is
  /// lost.
  String? _expanded;

  /// Built once and shared by every row's transport, so opening a second
  /// person stops the first rather than playing two calls at once.
  final RecordingPlayer _player = createRecordingPlayer();

  /// Why a recording would not play, kept per call id.
  final Map<String, String> _recordingErrors = {};

  final TextEditingController _token = TextEditingController();
  String? _disconnecting;
  String? _confirmingDisconnect;
  bool _managing = false;
  bool _connecting = false;
  String? _connectError;
  bool _showConnect = false;

  /// The Reached nobody card, opened, and which reason it is narrowed to.
  bool _nobodyOpen = false;
  String? _nobodyFilter;

  @override
  void dispose() {
    _token.dispose();
    _player.dispose();
    super.dispose();
  }

  /// Attach one Workiz account to the branch currently in view.
  ///
  /// The server refuses outside a branch view and its sentence says so, which
  /// is why nothing here tries to work out whether the view is right. One place
  /// deciding beats two places agreeing.
  Future<void> _connect() async {
    setState(() {
      _connecting = true;
      _connectError = null;
    });

    final response = await widget.api.post('/reports/experimental/workiz', {
      'api_token': _token.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _connecting = false;
        _connectError = response.message;
      });
      return;
    }

    _token.clear();
    setState(() {
      _connecting = false;
      _showConnect = false;
    });
    _load();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  String get _dayKey =>
      '${_day.year.toString().padLeft(4, '0')}-'
      '${_day.month.toString().padLeft(2, '0')}-'
      '${_day.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/reports/experimental/calls', {
      'day': _dayKey,
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _report = response.body;
      } else {
        _error = response.message;
      }
    });
  }

  void _move(int days) {
    setState(() => _day = _day.add(Duration(days: days)));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _dayBar(context),
        const SizedBox(height: 12),
        Expanded(child: _body(context)),
      ],
    );
  }

  Widget _dayBar(BuildContext context) {
    final today = DateTime.now();
    final isToday =
        _dayKey ==
        '${today.year.toString().padLeft(4, '0')}-'
            '${today.month.toString().padLeft(2, '0')}-'
            '${today.day.toString().padLeft(2, '0')}';

    return Row(
      children: [
        IconButton(
          onPressed: _loading ? null : () => _move(-1),
          icon: const Icon(Icons.chevron_left),
          tooltip: 'Previous day',
        ),
        Text(_dayKey, style: Theme.of(context).textTheme.titleMedium),
        // Forward is disabled on today rather than hidden: a control that
        // vanishes reads as a bug, one that is greyed reads as a boundary.
        IconButton(
          onPressed: (_loading || isToday) ? null : () => _move(1),
          icon: const Icon(Icons.chevron_right),
          tooltip: 'Next day',
        ),
        const Spacer(),
        IconButton(
          onPressed: _loading ? null : _load,
          icon: const Icon(Icons.refresh),
          tooltip: 'Reload',
        ),
      ],
    );
  }

  Widget _body(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Align(alignment: Alignment.topCenter, child: ErrorNote(_error!));
    }

    final report = _report!;

    if (report['connected'] != true) {
      return _notConnected(
        context,
        report['missing']?.toString() ?? 'A vendor',
      );
    }

    final people = (report['people'] as List? ?? const [])
        .map((row) => (row as Map).cast<String, dynamic>())
        .toList();

    final totals = (report['totals'] as Map? ?? const {})
        .cast<String, dynamic>();

    final calls = (report['calls'] as List? ?? const [])
        .map((row) => (row as Map).cast<String, dynamic>())
        .toList();

    final ordered = _sorted(people);

    /*
     * ── A CustomScrollView SO THE COLUMN HEADER CAN PIN ─────────
     *
     * Jose asked for the header to stay put while the people scroll. A
     * DataTable cannot do that - it draws its own header inside itself and
     * scrolls as one box - so the header is a sliver of its own and the rows
     * are a list beneath it.
     *
     * Which means the widths can no longer be decided by the table. They come
     * from _columns(), and the header and every row lay themselves out from
     * that one list, so a column added to it cannot appear in one and not the
     * other.
     */
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= ExperimentalCallReport.tableAbove;

        return CustomScrollView(
          slivers: [
            SliverToBoxAdapter(child: _totals(context, totals)),
            if (_nobodyOpen)
              SliverToBoxAdapter(child: _reachedNobody(context, calls)),
            const SliverToBoxAdapter(child: SizedBox(height: 16)),
            SliverToBoxAdapter(child: _coverage(context, report)),
            const SliverToBoxAdapter(child: SizedBox(height: 16)),

            if (people.isEmpty)
              SliverToBoxAdapter(child: _nothing(context))
            else if (wide) ...[
              SliverPersistentHeader(
                pinned: true,
                delegate: _StickyHeader(child: _headerRow(context)),
              ),
              SliverList.builder(
                itemCount: ordered.length,
                itemBuilder: (context, index) =>
                    _personRow(context, ordered[index], calls),
              ),
            ] else
              SliverList.builder(
                itemCount: ordered.length,
                itemBuilder: (context, index) =>
                    _card(context, ordered[index], calls),
              ),

            if (people.isNotEmpty)
              SliverToBoxAdapter(child: _conversionNote(context)),
          ],
        );
      },
    );
  }

  /// An absent credential is a STATE. An empty report and a missing connection
  /// look identical on a screen and mean opposite things.
  Widget _notConnected(BuildContext context, String vendor) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              '$vendor is not connected.',
              style: theme.textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            Text(
              'This report reads $vendor directly, and no credential is '
              'configured for it. Nothing has gone wrong and no calls are '
              'missing — there is simply nothing to read.',
              style: theme.textTheme.bodyMedium,
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }

  Widget _nothing(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Text(
        'No calls and no jobs on this day.',
        style: Theme.of(context).textTheme.bodyMedium,
        textAlign: TextAlign.center,
      ),
    );
  }

  Widget _totals(BuildContext context, Map<String, dynamic> totals) {
    return Wrap(
      spacing: 12,
      runSpacing: 12,
      children: [
        _stat(context, 'Calls', totals['calls']),
        _stat(context, 'Answered', totals['answered']),
        _stat(
          context,
          'Reached nobody',
          totals['reached_nobody'],
          key: const ValueKey('stat-reached-nobody'),
          open: _nobodyOpen,
          onTap: () => setState(() => _nobodyOpen = !_nobodyOpen),
        ),
        _stat(context, 'New client', totals['new_client']),
        _stat(context, 'Existing client', totals['existing_client']),
        _stat(context, 'Jobs booked', totals['jobs']),
        _stat(context, 'Avg talk', _mmss(totals['avg_talk_seconds'])),
      ],
    );
  }

  Widget _stat(
    BuildContext context,
    String label,
    Object? value, {
    Key? key,
    bool open = false,
    VoidCallback? onTap,
  }) {
    final theme = Theme.of(context);

    return Material(
      key: key,
      color: open
          ? theme.colorScheme.secondaryContainer
          : theme.colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(8),
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('${value ?? 0}', style: theme.textTheme.headlineSmall),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(label, style: theme.textTheme.bodySmall),
                  // A card that opens says so, or nobody finds out it does.
                  if (onTap != null)
                    Icon(
                      open ? Icons.expand_less : Icons.expand_more,
                      size: 16,
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Why a call reached nobody, in Jose's words (2026-09-24). The server
  /// decides which is which from RingCentral's result; this only names them.
  static const _outcomes = {
    'missed': 'Missed call',
    'voicemail': 'Voicemail',
    'spam': 'Spam call',
    'blocked': 'Blocked',
    'other': 'Other',
  };

  /// The calls behind the Reached nobody card, narrowed by reason.
  Widget _reachedNobody(
    BuildContext context,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final nobody = calls.where((c) => c['outcome'] != 'answered').toList();

    final counts = <String, int>{};
    for (final call in nobody) {
      final outcome = '${call['outcome']}';
      counts[outcome] = (counts[outcome] ?? 0) + 1;
    }

    final shown = _nobodyFilter == null
        ? nobody
        : nobody.where((c) => c['outcome'] == _nobodyFilter).toList();

    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final entry in _outcomes.entries)
                // A reason with no calls is still offered, at zero, so the
                // choices do not change shape from day to day - except
                // Other, which exists only to keep the count honest.
                if (entry.key != 'other' || (counts['other'] ?? 0) > 0)
                  FilterChip(
                    key: ValueKey('nobody-${entry.key}'),
                    label: Text('${entry.value} (${counts[entry.key] ?? 0})'),
                    selected: _nobodyFilter == entry.key,
                    onSelected: (on) =>
                        setState(() => _nobodyFilter = on ? entry.key : null),
                  ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Missed call: rang a line or queue, nobody picked up. '
            'Voicemail: the caller left a message. '
            'Spam call: hung up in the phone menu without pressing anything. '
            'Blocked: a blocked or rejected number.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          if (shown.isEmpty)
            Text('No calls.', style: theme.textTheme.bodyMedium)
          else
            for (final call in shown) _call(context, null, call),
        ],
      ),
    );
  }

  /// What these numbers cover, by name.
  Widget _coverage(BuildContext context, Map<String, dynamic> report) {
    final theme = Theme.of(context);

    final accounts = (report['workiz_accounts'] as List? ?? const [])
        .map((a) => (a as Map).cast<String, dynamic>())
        .toList();

    final failed = (report['workiz_failed'] as Map? ?? const {}).keys
        .map((k) => k.toString())
        .toList();

    final covered = accounts
        .map((a) => '${a['name']}')
        .where((name) => !failed.contains(name))
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          covered.isEmpty
              ? 'No Workiz account answered, so the jobs column is blank — '
                    'that is not the same as nobody booking anything.'
              : 'Jobs cover ${covered.length} Workiz '
                    '${covered.length == 1 ? 'account' : 'accounts'}: '
                    '${covered.join(', ')}.',
          style: theme.textTheme.bodySmall,
        ),
        // A book nobody will ever read, named rather than absent. Jose,
        // 2026-09-15: "we dont pay the api access there". Saying "not
        // connected" would send somebody off to connect it.
        for (final account
            in (report['workiz_unreachable'] as List? ?? const []))
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              '${(account as Map)['name']} is never included: '
              '${account['reason']}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.outline,
              ),
            ),
          ),
        if (failed.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(
            'Did not answer: ${failed.join(', ')}. The same people book into '
            'every account, so those numbers are low rather than incomplete.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],

        /*
         * ── THE ACCOUNTS ARE SAID IN WORDS, MANAGED BEHIND A CLICK ──
         *
         * A row of chips sitting above a table reads as a FILTER - Jose's
         * words: "they appear as if they were filters and people can get
         * confused". They were never filters; they are the connection list,
         * and their X disconnects a credential.
         *
         * So which accounts a number covers stays in the sentence above, where
         * it belongs to the report, and the chips move behind this toggle,
         * where they belong to setup. Nothing is hidden that the reader needs
         * in order to read the numbers correctly.
         *
         * The chips cannot be dropped entirely: a Workiz connection is a data
         * source, excluded from the shared connections list on purpose, so this
         * is still the ONLY place its id is published and the only way one can
         * be taken back.
         */
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => setState(() => _managing = !_managing),
            icon: Icon(
              _managing ? Icons.expand_less : Icons.expand_more,
              size: 18,
            ),
            label: Text(
              _managing
                  ? 'Hide Workiz accounts'
                  : 'Manage Workiz accounts (${accounts.length})',
            ),
          ),
        ),
        if (_managing) ...[
          // Each connected branch, with the way to remove it. This is the ONLY
          // place a Workiz account's id is published: it is a data source, and
          // the shared connections list excludes those on purpose, so without
          // these chips a credential could be connected and never taken back.
          if (accounts.isNotEmpty)
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                for (final account in accounts)
                  if (_confirmingDisconnect == account['id'])
                    // Confirmed IN PLACE rather than in a dialog, the same way
                    // deleting a saved report is. The X used to disconnect on one
                    // click, which is a poor trade: undoing it needs that
                    // branch's Workiz token pasted again, and the token is not
                    // something the app can show you.
                    Wrap(
                      spacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          'Disconnect ${account['name']}?',
                          style: theme.textTheme.bodySmall,
                        ),
                        TextButton(
                          onPressed: _disconnecting == account['id']
                              ? null
                              : () => _disconnect('${account['id']}'),
                          child: const Text('Disconnect'),
                        ),
                        TextButton(
                          onPressed: () =>
                              setState(() => _confirmingDisconnect = null),
                          child: const Text('Keep'),
                        ),
                      ],
                    )
                  else
                    InputChip(
                      label: Text('${account['name']}'),
                      onDeleted: () => setState(
                        () => _confirmingDisconnect = '${account['id']}',
                      ),
                      deleteIcon: const Icon(Icons.close, size: 16),
                      tooltip: 'Disconnect ${account['name']}',
                    ),
              ],
            ),
          const SizedBox(height: 8),
          _connectControl(context),
        ],
      ],
    );
  }

  /// Take a branch's Workiz account back.
  ///
  /// Straight through the shared connections endpoint, which already gates on
  /// this provider's own connect permission and needs nothing from here beyond
  /// the id.
  ///
  /// IT REMOVES EZBIZI'S STORED TOKEN AND NOTHING ELSE. No Workiz data is
  /// touched, no job is deleted, nothing changes on their side — that branch
  /// simply stops appearing in this report until somebody pastes its token
  /// again. That is still worth a confirmation, because the token is write-only
  /// once stored and nobody can read it back out of the app.
  Future<void> _disconnect(String id) async {
    setState(() {
      _disconnecting = id;
      _confirmingDisconnect = null;
    });

    final response = await widget.api.delete('/social/connections/$id');

    if (!mounted) return;

    setState(() => _disconnecting = null);

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    _load();
  }

  /// The way a branch's Workiz account gets attached.
  ///
  /// It lives HERE rather than in Settings because the tokens and this report
  /// are retired together, and a connect flow parked somewhere permanent
  /// outlives the thing that needed it. It is also the entry point: without it
  /// the report has no way to reach its first account, which is the kind of
  /// dead end a screen full of stubbed rows never shows.
  Widget _connectControl(BuildContext context) {
    final theme = Theme.of(context);

    if (!_showConnect) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          onPressed: () => setState(() => _showConnect = true),
          icon: const Icon(Icons.add_link),
          label: const Text('Connect a Workiz account'),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Switch to the branch this account belongs to, then paste its API '
          'Token from the v1 tab of Workiz › Developer API.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _token,
                decoration: InputDecoration(
                  labelText: 'Workiz API Token',
                  border: const OutlineInputBorder(),
                  errorText: _connectError,
                ),
                // Obscured: it is an account credential that can delete jobs,
                // and it is being pasted on somebody's screen.
                obscureText: true,
                enabled: !_connecting,
              ),
            ),
            const SizedBox(width: 8),
            FilledButton(
              onPressed: _connecting ? null : _connect,
              child: const Text('Connect'),
            ),
            TextButton(
              onPressed: _connecting
                  ? null
                  : () => setState(() {
                      _showConnect = false;
                      _connectError = null;
                    }),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ],
    );
  }

  /// The columns, once, so the header and the rows cannot disagree.
  ///
  /// `flex` rather than fixed widths: above 900px there is room for all of
  /// them, and a fixed-width table would need its own horizontal scrollbar
  /// that the pinned header could not follow.
  List<({String key, String label, int flex, bool numeric})> _columns() =>
      const [
        (key: 'person', label: 'Person', flex: 3, numeric: false),
        (key: 'rang', label: 'Rang', flex: 2, numeric: true),
        (key: 'answered', label: 'Answered', flex: 2, numeric: true),
        (key: 'new_client', label: 'New', flex: 2, numeric: true),
        (key: 'existing_client', label: 'Existing', flex: 2, numeric: true),
        (key: 'jobs', label: 'Jobs', flex: 2, numeric: true),

        // Jose's column, 2026-09-17: "conversion rate %, which is calculated
        // between new and jobs".
        (key: 'conversion', label: 'Conversion', flex: 2, numeric: true),

        (key: 'avg_talk_seconds', label: 'Avg talk', flex: 2, numeric: true),
      ];

  /// Jobs booked as a share of new-client calls answered.
  ///
  /// ── IT IS A RATIO OF TWO HONEST NUMBERS, NOT A FUNNEL ───────
  ///
  /// `jobs` counts what a person created in Workiz on the day; `new_client`
  /// counts new-client calls they answered. Those are not the same population:
  /// somebody can book a job for an existing customer, or book today from a
  /// call taken last week. So this reads "jobs per new-client call", which is
  /// worth watching, and it is NOT "the share of their calls that converted".
  /// The screen says so under the table rather than leaving the percent sign
  /// to imply the stronger claim.
  ///
  /// Null when they answered no new-client calls — a dash. Zero divided by
  /// zero is not 0%, and 0% next to a name reads as a judgement about somebody
  /// who was never given the chance.
  double? _conversion(Map<String, dynamic> person) {
    final calls = (person['new_client'] as num?)?.toDouble() ?? 0;
    if (calls <= 0) return null;

    return ((person['jobs'] as num?)?.toDouble() ?? 0) / calls * 100;
  }

  /// What the conversion column does and does not claim.
  ///
  /// Written on the screen rather than left to the percent sign, because a
  /// column headed "Conversion" beside somebody's name will be read as a
  /// verdict on them. The two counts come from different systems and different
  /// populations, and the honest reading is narrower than the word.
  Widget _conversionNote(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      child: Text(
        'Conversion is jobs booked in Workiz divided by new-client calls '
        'answered, for the same day. It is not the share of a person\'s calls '
        'that became work: a job can be booked for an existing customer, or '
        'booked today from a call taken last week. A dash means they answered '
        'no new-client calls, which is different from converting none.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.colorScheme.outline,
        ),
      ),
    );
  }

  List<Map<String, dynamic>> _sorted(List<Map<String, dynamic>> people) {
    final ordered = [...people];

    ordered.sort((a, b) {
      final comparison = switch (_sortBy) {
        'person' => '${a['person']}'.toLowerCase().compareTo(
          '${b['person']}'.toLowerCase(),
        ),

        // Nulls last whichever way the arrow points. A person with no average
        // talk time has no value to rank, and sorting them to the top of a
        // descending column would read as the longest calls of the day.
        'conversion' => _nullsLast(_conversion(a), _conversion(b)),
        'avg_talk_seconds' => _nullsLast(
          (a['avg_talk_seconds'] as num?)?.toDouble(),
          (b['avg_talk_seconds'] as num?)?.toDouble(),
        ),

        _ => ((a[_sortBy] as num?) ?? 0).compareTo((b[_sortBy] as num?) ?? 0),
      };

      return _ascending ? comparison : -comparison;
    });

    return ordered;
  }

  /// Ranks two possibly-absent numbers, keeping absence at the bottom.
  ///
  /// Returns 0 when both are missing so the sort stays stable and two dashes
  /// do not swap places every time somebody flips the arrow.
  int _nullsLast(double? a, double? b) {
    if (a == null && b == null) return 0;

    // The sign is flipped by the caller, so "last" has to be expressed as
    // though ascending: a missing value sorts high, and the descending pass
    // inverts it back to the bottom.
    if (a == null) return _ascending ? 1 : -1;
    if (b == null) return _ascending ? -1 : 1;

    return a.compareTo(b);
  }

  void _sort(String key) {
    setState(() {
      if (_sortBy == key) {
        _ascending = !_ascending;
        return;
      }

      _sortBy = key;

      // A newly chosen column starts on the end somebody wants to see: most
      // calls first for a number, A first for a name.
      _ascending = key == 'person';
    });
  }

  Widget _headerRow(BuildContext context) {
    final theme = Theme.of(context);

    return Material(
      // Opaque on purpose: pinned, it scrolls over the rows beneath it, and a
      // transparent header would print the names through itself.
      color: theme.colorScheme.surface,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                for (final column in _columns())
                  Expanded(
                    flex: column.flex,
                    child: InkWell(
                      onTap: () => _sort(column.key),
                      child: Row(
                        mainAxisAlignment: column.numeric
                            ? MainAxisAlignment.end
                            : MainAxisAlignment.start,
                        children: [
                          Flexible(
                            child: Text(
                              column.label,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.labelLarge,
                            ),
                          ),

                          // Only on the column actually in force. An arrow on
                          // every header says nothing about which one is doing
                          // the sorting.
                          if (_sortBy == column.key)
                            Icon(
                              _ascending
                                  ? Icons.arrow_upward
                                  : Icons.arrow_downward,
                              size: 14,
                            ),
                        ],
                      ),
                    ),
                  ),

                // Matches the chevron's slot in every row below.
                const SizedBox(width: 40),
              ],
            ),
          ),
          const Divider(height: 1),
        ],
      ),
    );
  }

  Widget _personRow(
    BuildContext context,
    Map<String, dynamic> person,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final name = '${person['person']}';
    final open = _expanded == name;
    final only = _onlyIn(person);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        InkWell(
          onTap: () => setState(() => _expanded = open ? null : name),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                for (final column in _columns())
                  Expanded(
                    flex: column.flex,
                    child: Align(
                      alignment: column.numeric
                          ? Alignment.centerRight
                          : Alignment.centerLeft,
                      child: column.key == 'person'
                          ? Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(name, overflow: TextOverflow.ellipsis),
                                if (only != null)
                                  Text(
                                    only,
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.outline,
                                    ),
                                  ),
                              ],
                            )
                          : Text(_cell(person, column.key)),
                    ),
                  ),
                SizedBox(
                  width: 40,
                  child: Icon(
                    open ? Icons.expand_less : Icons.expand_more,
                    color: theme.colorScheme.outline,
                  ),
                ),
              ],
            ),
          ),
        ),
        if (open) _calls(context, name, calls),
        const Divider(height: 1),
      ],
    );
  }

  String _cell(Map<String, dynamic> person, String key) => switch (key) {
    'avg_talk_seconds' => _mmss(person['avg_talk_seconds']),
    'conversion' => switch (_conversion(person)) {
      null => '—',
      final rate => '${rate.round()}%',
    },
    _ => '${person[key] ?? 0}',
  };

  /// The calls behind one person's row.
  ///
  /// ── A CALL THAT RANG THEM AND A CALL THEY TOOK ARE BOTH HERE ──
  ///
  /// The row has a Rang column and an Answered column, so drilling into it has
  /// to account for both or the list will not add up to the numbers that were
  /// clicked. Each call says which it was, and a call that rang four people
  /// appears under all four — which is what happened.
  Widget _calls(
    BuildContext context,
    String name,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);

    final mine = calls.where((call) {
      if (call['answered_by'] == name) return true;

      return ((call['rang'] as List?) ?? const []).contains(name);
    }).toList();

    if (mine.isEmpty) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(32, 0, 16, 16),
        child: Text(
          'No calls for this person on this day. They are listed because '
          'Workiz has them booking jobs.',
          style: theme.textTheme.bodySmall,
        ),
      );
    }

    return Container(
      color: theme.colorScheme.surfaceContainerHighest,
      padding: const EdgeInsets.fromLTRB(32, 8, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [for (final call in mine) _call(context, name, call)],
      ),
    );
  }

  /// One call. [name] is the person whose list it sits in, or null in the
  /// Reached nobody list.
  Widget _call(BuildContext context, String? name, Map<String, dynamic> call) {
    final theme = Theme.of(context);
    final by = call['answered_by'];
    final id = call['recording_id']?.toString();

    // Said in words. Jose, 2026-09-24: "hard to understand what the icons
    // mean" - an arrow and a bell told nobody which was which.
    final status = by == null
        ? (_outcomes[call['outcome']] ?? 'Nobody answered')
        : by == name
        ? 'Answered'
        : 'Rang · $by answered';

    // Talk time on EVERY answered call, whoever took it. It used to say
    // "rang" on calls this person did not take, which read as a missing time.
    final talk = by == null ? 'no talk' : _mmss(call['talk_seconds']);
    final error = id == null ? null : _recordingErrors[id];

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                status,
                style: theme.textTheme.labelMedium?.copyWith(
                  color: by != null && by == name
                      ? theme.colorScheme.primary
                      : theme.colorScheme.onSurfaceVariant,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  '${_clock(call['started_at'])} · '
                  '${call['caller_label'] ?? call['from'] ?? 'Unknown caller'}'
                  '${call['queue'] == null ? '' : ' · ${call['queue']}'}',
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              Text(talk, style: theme.textTheme.bodySmall),
            ],
          ),
          if (id != null)
            Padding(
              padding: const EdgeInsets.only(left: 24, top: 4),
              child: RecordingPlaybackBar(
                player: _player,
                dense: true,
                load: () => _recording(id),
              ),
            ),
          if (error != null)
            Padding(
              padding: const EdgeInsets.only(left: 24, top: 4),
              child: Text(
                error,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ),
        ],
      ),
    );
  }

  /// Fetches one recording, keeping the server's sentence when it refuses.
  ///
  /// `getBytesResponse` rather than `getBytes`, because the refusal here is
  /// worth reading: the recordings exist and the RingCentral app has not been
  /// granted permission to play them, which is something somebody can go and
  /// fix in a console. A bare "could not load" sends them into this codebase
  /// instead.
  Future<List<int>?> _recording(String id) async {
    final response = await widget.api.getBytesResponse(
      '/reports/experimental/calls/$id/recording',
    );

    if (response.bytes != null) {
      if (mounted) setState(() => _recordingErrors.remove(id));
      return response.bytes;
    }

    if (mounted) {
      setState(
        () => _recordingErrors[id] =
            response.message ?? 'That recording could not be loaded.',
      );
    }

    return null;
  }

  /// The time of day out of an ISO stamp, without pulling in a date library
  /// for eight characters.
  String _clock(dynamic stamp) {
    final parsed = DateTime.tryParse('$stamp')?.toLocal();
    if (parsed == null) return '';

    final hour = parsed.hour.toString().padLeft(2, '0');
    final minute = parsed.minute.toString().padLeft(2, '0');

    return '$hour:$minute';
  }

  Widget _card(
    BuildContext context,
    Map<String, dynamic> person,
    List<Map<String, dynamic>> calls,
  ) {
    final theme = Theme.of(context);
    final only = _onlyIn(person);
    final name = '${person['person']}';
    final open = _expanded == name;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${person['person']}', style: theme.textTheme.titleMedium),
            if (only != null)
              Text(
                only,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            const SizedBox(height: 8),
            Text(
              '${person['answered']} answered of ${person['rang']} rung · '
              '${person['new_client']} new · '
              '${person['existing_client']} existing · '
              '${person['jobs']} jobs · '
              '${_cell(person, 'conversion')} conversion · '
              '${_mmss(person['avg_talk_seconds'])} avg talk',
              style: theme.textTheme.bodyMedium,
            ),

            // The same drill-down as the table, because a phone is where
            // somebody is most likely to want to hear the call rather than
            // read the count.
            InkWell(
              onTap: () => setState(() => _expanded = open ? null : name),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Row(
                  children: [
                    Text(
                      open ? 'Hide calls' : 'Show calls',
                      style: theme.textTheme.labelLarge,
                    ),
                    Icon(open ? Icons.expand_less : Icons.expand_more),
                  ],
                ),
              ),
            ),
            if (open) _calls(context, name, calls),
          ],
        ),
      ),
    );
  }

  /// Seconds as m:ss, and a DASH when there is no average at all.
  ///
  /// Null means they answered nothing, which is not the same fact as averaging
  /// zero seconds. Printing "0:00" would read as a measurement of a real call.
  String _mmss(Object? seconds) {
    if (seconds is! int) return '—';

    final minutes = seconds ~/ 60;
    final rest = (seconds % 60).toString().padLeft(2, '0');

    return '$minutes:$rest';
  }

  /// Flagged only when Workiz has never heard of somebody who took calls.
  ///
  /// ONE DIRECTION, because only one is knowable here. `in_jobs` is asked of a
  /// 30-day window of Workiz jobs, so a false answer means Workiz has no record
  /// of that name at all — which usually means the two systems spell them
  /// differently, and the reader is the only one who can tell.
  ///
  /// The reverse is deliberately NOT marked. This screen holds one day of
  /// calls, so "Workiz knows them, RingCentral does not" cannot be told apart
  /// from "they did not answer a phone today", and booking without answering is
  /// a normal way to work.
  ///
  /// It used to say "calls only" / "Workiz only" off the DAY's rows, which
  /// marked three people on the first live day who were in both systems and
  /// had simply booked nothing that morning.
  String? _onlyIn(Map<String, dynamic> person) {
    final inCalls = person['in_calls'] == true;
    final inJobs = person['in_jobs'] == true;

    return inCalls && !inJobs ? 'not in Workiz' : null;
  }
}

/// Holds the column header at the top of the list while the people scroll.
///
/// A fixed height, because the header is one row of labels and measuring it
/// would mean laying it out twice on every frame. It is stated in one place
/// and the header's own padding is derived from nothing else.
class _StickyHeader extends SliverPersistentHeaderDelegate {
  const _StickyHeader({required this.child});

  final Widget child;

  static const double _height = 45;

  @override
  double get minExtent => _height;

  @override
  double get maxExtent => _height;

  @override
  Widget build(
    BuildContext context,
    double shrinkOffset,
    bool overlapsContent,
  ) {
    return SizedBox(height: _height, child: child);
  }

  /// True whenever the header WIDGET differs, which it does on every sort
  /// change — the arrow moves and the labels rebuild. Returning false would
  /// pin a header showing the previous column's arrow forever.
  @override
  bool shouldRebuild(_StickyHeader old) => old.child != child;
}
