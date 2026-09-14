import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';

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

  final TextEditingController _token = TextEditingController();
  String? _disconnecting;
  bool _connecting = false;
  String? _connectError;
  bool _showConnect = false;

  @override
  void dispose() {
    _token.dispose();
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

    return ListView(
      children: [
        _totals(context, totals),
        const SizedBox(height: 16),
        _coverage(context, report),
        const SizedBox(height: 16),
        if (people.isEmpty)
          _nothing(context)
        else
          LayoutBuilder(
            builder: (context, constraints) =>
                constraints.maxWidth >= ExperimentalCallReport.tableAbove
                ? _table(context, people)
                : Column(
                    children: [
                      for (final person in people) _card(context, person),
                    ],
                  ),
          ),
      ],
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
        _stat(context, 'Reached nobody', totals['reached_nobody']),
        _stat(context, 'New client', totals['new_client']),
        _stat(context, 'Existing client', totals['existing_client']),
        _stat(context, 'Jobs booked', totals['jobs']),
      ],
    );
  }

  Widget _stat(BuildContext context, String label, Object? value) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('${value ?? 0}', style: theme.textTheme.headlineSmall),
          Text(label, style: theme.textTheme.bodySmall),
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
        const SizedBox(height: 8),
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
                InputChip(
                  label: Text('${account['name']}'),
                  onDeleted: _disconnecting == account['id']
                      ? null
                      : () => _disconnect('${account['id']}'),
                  deleteIcon: const Icon(Icons.close, size: 16),
                  tooltip: 'Disconnect ${account['name']}',
                ),
            ],
          ),
        const SizedBox(height: 8),
        _connectControl(context),
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
      ],
    );
  }

  /// Take a branch's Workiz account back.
  ///
  /// Straight through the shared connections endpoint, which already gates on
  /// this provider's own connect permission and needs nothing from here beyond
  /// the id.
  Future<void> _disconnect(String id) async {
    setState(() => _disconnecting = id);

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

  Widget _table(BuildContext context, List<Map<String, dynamic>> people) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        columns: const [
          DataColumn(label: Text('Person')),
          DataColumn(label: Text('Rang'), numeric: true),
          DataColumn(label: Text('Answered'), numeric: true),
          DataColumn(label: Text('New'), numeric: true),
          DataColumn(label: Text('Existing'), numeric: true),
          DataColumn(label: Text('Jobs'), numeric: true),
          DataColumn(label: Text('')),
        ],
        rows: [
          for (final person in people)
            DataRow(
              cells: [
                DataCell(Text('${person['person']}')),
                DataCell(Text('${person['rang']}')),
                DataCell(Text('${person['answered']}')),
                DataCell(Text('${person['new_client']}')),
                DataCell(Text('${person['existing_client']}')),
                DataCell(Text('${person['jobs']}')),
                DataCell(Text(_onlyIn(person) ?? '')),
              ],
            ),
        ],
      ),
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> person) {
    final theme = Theme.of(context);
    final only = _onlyIn(person);

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
              '${person['jobs']} jobs',
              style: theme.textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    );
  }

  /// Which system knows this name, when only one of them does.
  ///
  /// Said rather than hidden, because the usual cause is the two systems
  /// spelling one person differently and the reader is the only one who can
  /// tell. Nothing here guesses.
  String? _onlyIn(Map<String, dynamic> person) {
    final inCalls = person['in_calls'] == true;
    final inJobs = person['in_jobs'] == true;

    if (inCalls && inJobs) return null;
    if (inCalls) return 'calls only';
    return 'Workiz only';
  }
}
