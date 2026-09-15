import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/dashboard_chart.dart';

/// What the Google Ads accounts spent, and what came back for it.
///
/// ── One account at a time, on purpose ────────────────────────────
///
/// This screen used to load every connected account when it opened. A-1 has
/// fifty-three, each costing two Google API calls, so the tab spent 106
/// operations of a 15,000/day ceiling before showing anything and took as long
/// as the slowest of fifty-three round trips.
///
/// Now the account list comes from our own rows and answers instantly, and
/// nothing is asked of Google until somebody picks one. The cost of opening the
/// screen is zero.
///
/// ── Two levels, and the fields are not optional ──────────────────
///
/// Google's Reporting Minimum Functionality applies to each level of the Ads
/// hierarchy a tool DISPLAYS. This shows Account and Campaign, which makes R.10
/// and R.20 apply and leaves ad group, ad and keyword out of scope entirely.
///
/// Both levels must show clicks, cost, impressions, conversions and all
/// conversions. Every one is drawn at both levels. They are not columns to trim
/// when the table feels wide, and the status filter must never hide the status
/// COLUMN — filtering which rows are listed is a user's choice, dropping a
/// required field is a policy breach.
class AdsPerformanceScreen extends StatefulWidget {
  const AdsPerformanceScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<AdsPerformanceScreen> createState() => _AdsPerformanceScreenState();
}

/// A named range, or a pair of dates somebody chose.
class _Range {
  const _Range(this.label, this.days);

  final String label;

  /// Null means "custom" — the dates come from state instead.
  final int? days;
}

const _ranges = <_Range>[
  _Range('Yesterday', 1),
  _Range('7 days', 7),
  _Range('30 days', 30),
  _Range('60 days', 60),
  _Range('90 days', 90),
  _Range('Custom', null),
];

class _AdsPerformanceScreenState extends State<AdsPerformanceScreen> {
  List<Map<String, dynamic>> _accounts = const [];
  String? _selected;

  Map<String, dynamic>? _report;
  List<dynamic> _refused = const [];

  String? _error;
  bool _loadingAccounts = true;
  bool _loadingReport = false;

  /*
   * Yesterday, not 30 days. Jose's call, 2026-09-15.
   *
   * It is also the cheapest default: a one-day window is one day of rows from
   * Google rather than thirty, and this screen is opened far more often than a
   * month's history is actually wanted.
   */
  int _range = 0;
  DateTimeRange? _custom;

  /// Which campaign statuses are listed. Empty means all of them.
  final Set<String> _statuses = <String>{};

  /// Filters the sidebar. 51 connected accounts is more than anybody scrolls.
  final TextEditingController _accountSearch = TextEditingController();

  /// Filters the campaign table. A1's Hamden account alone has 180 campaigns,
  /// most of them one county each, so scanning for one is hopeless without it.
  final TextEditingController _campaignSearch = TextEditingController();

  /// Which column the campaign table is ordered by, and which way.
  ///
  /// Defaults to cost, descending: the first question anybody asks of a spend
  /// table is "what is costing the most", and alphabetical by campaign name
  /// answers a question nobody asked.
  String _sortBy = 'cost';
  bool _sortDescending = true;

  /// A manager's roll-up, when one is showing.
  List<dynamic>? _rollup;
  int _rollupChildren = 0;
  int _rollupNotConnected = 0;

  /// Which metric the chart draws. Three series share no scale, so one at a
  /// time is the only honest way to draw them on one axis.
  String _metric = 'cost';

  @override
  void initState() {
    super.initState();
    _loadAccounts();
    _accountSearch.addListener(() => setState(() {}));
    _campaignSearch.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _accountSearch.dispose();
    _campaignSearch.dispose();
    super.dispose();
  }

  /// Ends YESTERDAY, never today.
  ///
  /// Google restates the current day as conversions are attributed late, so
  /// today's figure is the only one on the screen guaranteed to change after
  /// somebody has read it.
  (DateTime, DateTime) _window() {
    if (_ranges[_range].days == null && _custom != null) {
      return (_custom!.start, _custom!.end);
    }
    final to = DateTime.now().subtract(const Duration(days: 1));
    final days = _ranges[_range].days ?? 30;
    return (to.subtract(Duration(days: days - 1)), to);
  }

  static String _d(DateTime x) =>
      '${x.year.toString().padLeft(4, '0')}-'
      '${x.month.toString().padLeft(2, '0')}-'
      '${x.day.toString().padLeft(2, '0')}';

  Future<void> _loadAccounts() async {
    setState(() {
      _loadingAccounts = true;
      _error = null;
    });

    final response = await widget.api.get('/ads/accounts');
    if (!mounted) return;

    setState(() {
      _loadingAccounts = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _accounts = List<Map<String, dynamic>>.from(
        (response.body['data'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      // Deliberately does NOT auto-select. Picking one is what spends quota,
      // and a screen that spends it on open is the thing this replaced.
    });
  }

  bool get _selectedIsManager {
    final a = _accounts.where((a) => a['connection_id'] == _selected);
    return a.isNotEmpty && a.first['is_manager'] == true;
  }

  Future<void> _loadReport() async {
    final id = _selected;
    if (id == null) return;

    setState(() {
      _loadingReport = true;
      _error = null;
      _report = null;
      _rollup = null;
    });

    final (from, to) = _window();
    final query = {'connection_id': id, 'from': _d(from), 'to': _d(to)};

    // A manager holds no spend of its own — every figure on it would be zero —
    // so asking it for a campaign report answers a question nobody meant. The
    // roll-up is what "A1 Chimney - Ads Manager" actually means.
    final manager = _selectedIsManager;
    final response = await widget.api.get(
      manager ? '/ads/rollup' : '/ads/performance',
      query,
    );

    if (!mounted) return;

    setState(() {
      _loadingReport = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }

      _refused = (response.body['refused'] as List? ?? const []);

      if (manager) {
        _rollup = (response.body['data'] as List? ?? const []);
        _rollupChildren = (response.body['children_total'] as num?)?.toInt() ?? 0;
        _rollupNotConnected = (response.body['not_connected'] as num?)?.toInt() ?? 0;
        return;
      }

      final data = (response.body['data'] as List? ?? const []);
      _report = data.isEmpty ? null : data.first as Map<String, dynamic>;
    });
  }

  /// Money, with its unit.
  ///
  /// `cost` arrives as a number, and a whole figure crosses JSON as an int, so
  /// `as double` throws on a campaign that spent exactly 900. `as num` then
  /// `toDouble()` is the only cast that holds for both.
  String _money(dynamic value, dynamic currency) {
    final amount = (value as num?)?.toDouble() ?? 0;
    final code = (currency as String?) ?? '';
    return '${amount.toStringAsFixed(2)}${code.isEmpty ? '' : ' $code'}';
  }

  String _number(dynamic value) {
    final n = (value as num?) ?? 0;
    return n is int || n == n.roundToDouble()
        ? n.round().toString()
        : n.toStringAsFixed(2);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _header(theme),
          const SizedBox(height: 12),
          Expanded(
            child: LayoutBuilder(
              builder: (context, constraints) => constraints.maxWidth >= 860
                  ? Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SizedBox(width: 280, child: _accountList(theme)),
                        const SizedBox(width: 16),
                        Expanded(child: _body(theme)),
                      ],
                    )
                  : Column(
                      children: [
                        SizedBox(height: 180, child: _accountList(theme)),
                        const SizedBox(height: 12),
                        Expanded(child: _body(theme)),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _header(ThemeData theme) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Google Ads', style: theme.textTheme.headlineSmall),
              Text(
                'What each connected account spent, and what it produced.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
        if (_selected != null)
          IconButton(
            key: const ValueKey('ads-refresh'),
            icon: const Icon(Icons.refresh),
            tooltip: 'Read again from Google',
            onPressed: _loadingReport ? null : _loadReport,
          ),
      ],
    );
  }

  Widget _accountList(ThemeData theme) {
    if (_loadingAccounts) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_accounts.isEmpty) {
      return Center(
        child: Text(
          'No Google Ads account is connected here yet.\n'
          'Connect one under Leads, on Connected sources, and its spend appears here.',
          key: const ValueKey('ads-empty'),
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    // Matched on NAME and on the customer id, because people know an account by
    // either - "Hamden" and "8839813550" are both how somebody refers to it.
    final needle = _accountSearch.text.trim().toLowerCase();
    final shown = needle.isEmpty
        ? _accounts
        : _accounts
              .where(
                (a) =>
                    '${a['name']}'.toLowerCase().contains(needle) ||
                    '${a['customer_id']}'.contains(needle),
              )
              .toList();

    return Card(
      margin: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
            child: TextField(
              key: const ValueKey('ads-account-search'),
              controller: _accountSearch,
              decoration: InputDecoration(
                isDense: true,
                prefixIcon: const Icon(Icons.search, size: 18),
                border: const OutlineInputBorder(),
                hintText: 'Search ${_accounts.length} accounts',
              ),
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: ListView.builder(
              key: const ValueKey('ads-accounts'),
              itemCount: shown.length,
              itemBuilder: (context, i) {
                final a = shown[i];
                final id = a['connection_id'] as String?;
                final selected = id != null && id == _selected;

                return ListTile(
                  key: ValueKey('ads-account-$id'),
                  dense: true,
                  selected: selected,
                  selectedTileColor: theme.colorScheme.primaryContainer,
                  title: Row(
                    children: [
                      Flexible(
                        child: Text(
                          (a['name'] as String?) ?? 'Google Ads account',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      // A manager reports on everything beneath it rather than
                      // on itself, and the row says so before it is clicked -
                      // otherwise a screen of zeroes looks like a broken
                      // account rather than a different kind of one.
                      if (a['is_manager'] == true) ...[
                        const SizedBox(width: 6),
                        Icon(
                          Icons.account_tree_outlined,
                          size: 14,
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ],
                    ],
                  ),
                  subtitle: Text('${a['customer_id']}'),
                  onTap: () {
                    setState(() => _selected = id);
                    _loadReport();
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _body(ThemeData theme) {
    if (_selected == null) {
      return Center(
        child: Text(
          'Pick an account to see what it spent.',
          key: const ValueKey('ads-pick-one'),
          style: theme.textTheme.bodyMedium,
        ),
      );
    }

    if (_error != null) {
      return Center(
        child: Text(
          _error!,
          key: const ValueKey('ads-error'),
          textAlign: TextAlign.center,
        ),
      );
    }

    if (_loadingReport) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_rollup != null) {
      return ListView(
        children: [
          _rangeChips(theme),
          const SizedBox(height: 12),
          _rollupCard(theme),
          if (_refused.isNotEmpty) ...[
            const SizedBox(height: 16),
            _refusals(theme),
          ],
        ],
      );
    }

    if (_report == null) {
      return _refused.isEmpty
          ? const SizedBox.shrink()
          : ListView(children: [_refusals(theme)]);
    }

    final account =
        (_report!['account'] as Map?)?.cast<String, dynamic>() ?? const {};
    final currency = _report!['currency'];

    return ListView(
      children: [
        _rangeChips(theme),
        const SizedBox(height: 12),
        _accountCard(theme, account, currency),
        const SizedBox(height: 16),
        _chart(theme),
        const SizedBox(height: 16),
        _campaigns(theme, currency),
        if (_refused.isNotEmpty) ...[
          const SizedBox(height: 16),
          _refusals(theme),
        ],
      ],
    );
  }

  Widget _rangeChips(ThemeData theme) {
    final (from, to) = _window();

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (var i = 0; i < _ranges.length; i++)
          ChoiceChip(
            key: ValueKey('ads-range-$i'),
            label: Text(_ranges[i].label),
            selected: _range == i,
            onSelected: (on) async {
              if (!on) return;
              if (_ranges[i].days == null) {
                final picked = await showDateRangePicker(
                  context: context,
                  firstDate: DateTime.now().subtract(const Duration(days: 400)),
                  lastDate: DateTime.now().subtract(const Duration(days: 1)),
                  initialDateRange: _custom,
                );
                if (picked == null || !mounted) return;
                setState(() {
                  _custom = picked;
                  _range = i;
                });
              } else {
                setState(() => _range = i);
              }
              _loadReport();
            },
          ),
        Text('${_d(from)} to ${_d(to)}', style: theme.textTheme.bodySmall),
      ],
    );
  }

  Widget _accountCard(
    ThemeData theme,
    Map<String, dynamic> account,
    dynamic currency,
  ) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              (account['name'] as String?) ?? 'Google Ads account',
              style: theme.textTheme.titleMedium,
            ),
            Text('Account ${account['id']}', style: theme.textTheme.bodySmall),
            const SizedBox(height: 14),
            // R.10, the Account level. All five, every time.
            Wrap(
              key: const ValueKey('ads-account-figures'),
              spacing: 28,
              runSpacing: 12,
              children: [
                _figure(theme, 'Cost', _money(account['cost'], currency)),
                _figure(theme, 'Clicks', _number(account['clicks'])),
                _figure(theme, 'Impressions', _number(account['impressions'])),
                _figure(
                  theme,
                  'Conversions',
                  _number(account['conversions']),
                  // Jose asked what the difference is. The answer belongs where
                  // the number is, not in a chat message he has to remember.
                  hint: 'Conversion actions you chose to count as conversions. '
                      'This is the number Google optimises bidding against.',
                ),
                _figure(
                  theme,
                  'All conversions',
                  _number(account['all_conversions']),
                  hint: 'Every conversion action, including the ones not '
                      'counted above - so it is always the same or larger.',
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _figure(
    ThemeData theme,
    String label,
    String value, {
    String? hint,
  }) {
    final text = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(label, style: theme.textTheme.bodySmall),
            if (hint != null) ...[
              const SizedBox(width: 4),
              Icon(
                Icons.info_outline,
                size: 13,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ],
          ],
        ),
        Text(
          value,
          style: theme.textTheme.titleLarge?.copyWith(
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );

    return hint == null ? text : Tooltip(message: hint, child: text);
  }

  /// One metric at a time.
  ///
  /// Cost, clicks and conversions do not share a scale — spend is money, clicks
  /// are hundreds, conversions are tens. Drawn together on one axis the
  /// conversions line sits flat on the floor and says nothing, which is worse
  /// than not drawing it.
  Widget _chart(ThemeData theme) {
    final all = (_report!['series'] as List? ?? const []);
    final chosen = all.cast<Map<String, dynamic>>().where(
      (s) => s['key'] == _metric,
    );

    if (chosen.isEmpty) return const SizedBox.shrink();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text('Over time', style: theme.textTheme.titleSmall),
                const Spacer(),
                for (final m in const [
                  ('cost', 'Cost'),
                  ('clicks', 'Clicks'),
                  ('conversions', 'Conversions'),
                ]) ...[
                  const SizedBox(width: 6),
                  ChoiceChip(
                    key: ValueKey('ads-metric-${m.$1}'),
                    label: Text(m.$2),
                    selected: _metric == m.$1,
                    onSelected: (on) {
                      if (on) setState(() => _metric = m.$1);
                    },
                  ),
                ],
              ],
            ),
            const SizedBox(height: 12),
            DashboardChart(
              key: ValueKey('ads-chart-$_metric'),
              presentation: 'line',
              series: chosen.toList(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _campaigns(ThemeData theme, dynamic currency) {
    final all = (_report!['campaigns'] as List? ?? const [])
        .cast<Map<String, dynamic>>();

    // Every status present in the data, so the chips describe THIS account
    // rather than a list of Google's enum somebody typed out.
    final present = <String>{
      for (final c in all) (c['status'] as String?) ?? 'UNKNOWN',
    }.toList()..sort();

    final needle = _campaignSearch.text.trim().toLowerCase();

    final rows =
        all
            .where(
              (c) =>
                  (_statuses.isEmpty ||
                      _statuses.contains(c['status'] ?? 'UNKNOWN')) &&
                  (needle.isEmpty ||
                      '${c['name']}'.toLowerCase().contains(needle)),
            )
            .toList()
          // Sorted on a COPY of the filtered list, never on `all`: sorting the
          // source would reorder the data the chart and the totals came from,
          // and a table that reorders its own inputs is how a header comes to
          // disagree with the rows under it.
          ..sort((a, b) {
            final x = a[_sortBy], y = b[_sortBy];
            final c = x is num && y is num
                ? x.compareTo(y)
                : '$x'.toLowerCase().compareTo('$y'.toLowerCase());
            return _sortDescending ? -c : c;
          });

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text('Campaigns', style: theme.textTheme.titleSmall),
                const SizedBox(width: 12),
                Text(
                  '${rows.length} of ${all.length}',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final status in present)
                  FilterChip(
                    key: ValueKey('ads-status-$status'),
                    label: Text(_statusWord(status)),
                    selected: _statuses.contains(status),
                    onSelected: (on) => setState(() {
                      on ? _statuses.add(status) : _statuses.remove(status);
                    }),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            TextField(
              key: const ValueKey('ads-campaign-search'),
              controller: _campaignSearch,
              decoration: const InputDecoration(
                isDense: true,
                prefixIcon: Icon(Icons.search, size: 18),
                border: OutlineInputBorder(),
                hintText: 'Search campaigns',
              ),
            ),
            const SizedBox(height: 12),
            // Scrolls sideways rather than dropping columns: a narrow screen
            // must not be a reason to show fewer required fields than the
            // policy asks for.
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                key: const ValueKey('ads-campaigns'),
                columnSpacing: 26,
                sortColumnIndex: _sortIndex(),
                sortAscending: !_sortDescending,
                columns: [
                  _sortable('Campaign', 'name'),
                  _sortable('Status', 'status'),
                  _sortable('Cost', 'cost'),
                  _sortable('Clicks', 'clicks'),
                  _sortable('Impressions', 'impressions'),
                  _sortable('Conversions', 'conversions'),
                  _sortable('All conversions', 'all_conversions'),
                ],
                rows: [
                  for (final c in rows)
                    DataRow(
                      cells: [
                        DataCell(Text((c['name'] as String?) ?? '—')),
                        DataCell(Text(_statusWord(c['status'] as String?))),
                        DataCell(Text(_money(c['cost'], currency))),
                        DataCell(Text(_number(c['clicks']))),
                        DataCell(Text(_number(c['impressions']))),
                        DataCell(Text(_number(c['conversions']))),
                        DataCell(Text(_number(c['all_conversions']))),
                      ],
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  static const _sortColumns = [
    'name',
    'status',
    'cost',
    'clicks',
    'impressions',
    'conversions',
    'all_conversions',
  ];

  int _sortIndex() {
    final i = _sortColumns.indexOf(_sortBy);
    return i < 0 ? 2 : i;
  }

  /// A column header that sorts.
  ///
  /// Clicking the column already sorted flips the direction; clicking a new one
  /// starts DESCENDING, because every column here except the name is a
  /// quantity and "most" is what somebody is looking for. Starting ascending
  /// would put the campaigns that spent nothing at the top.
  DataColumn _sortable(String label, String key) {
    return DataColumn(
      label: Text(label),
      onSort: (columnIndex, ascending) => setState(() {
        if (_sortBy == key) {
          _sortDescending = !_sortDescending;
        } else {
          _sortBy = key;
          _sortDescending = key != 'name';
        }
      }),
    );
  }

  /// A manager account: every account beneath it, one row each.
  ///
  /// The header states what the total COVERS rather than leaving it implied.
  /// A roll-up that silently omits the accounts we hold no token for would be
  /// a smaller number wearing the clothes of a complete one, and nobody
  /// reading it would know to ask.
  Widget _rollupCard(ThemeData theme) {
    final rows = List<Map<String, dynamic>>.from(
      (_rollup ?? const []).cast<Map<String, dynamic>>(),
    )..sort((a, b) {
      final x = a[_sortBy], y = b[_sortBy];
      final c = x is num && y is num
          ? x.compareTo(y)
          : '$x'.toLowerCase().compareTo('$y'.toLowerCase());
      return _sortDescending ? -c : c;
    });

    num total(String key) =>
        rows.fold<num>(0, (sum, r) => sum + ((r[key] as num?) ?? 0));

    final currency = rows.isEmpty ? null : rows.first['currency'];

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('All accounts under this manager',
                style: theme.textTheme.titleMedium),
            Text(
              _rollupNotConnected == 0
                  ? '${rows.length} of $_rollupChildren accounts'
                  : '${rows.length} of $_rollupChildren accounts — '
                        '$_rollupNotConnected not connected to EzBizi, so they are '
                        'not in these totals',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 14),
            Wrap(
              key: const ValueKey('ads-rollup-figures'),
              spacing: 28,
              runSpacing: 12,
              children: [
                _figure(theme, 'Cost', _money(total('cost'), currency)),
                _figure(theme, 'Clicks', _number(total('clicks'))),
                _figure(theme, 'Impressions', _number(total('impressions'))),
                _figure(theme, 'Conversions', _number(total('conversions'))),
                _figure(
                  theme,
                  'All conversions',
                  _number(total('all_conversions')),
                ),
              ],
            ),
            const Divider(height: 28),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                key: const ValueKey('ads-rollup'),
                columnSpacing: 26,
                sortColumnIndex: _sortIndex(),
                sortAscending: !_sortDescending,
                columns: [
                  _sortable('Account', 'name'),
                  _sortable('Cost', 'cost'),
                  _sortable('Clicks', 'clicks'),
                  _sortable('Impressions', 'impressions'),
                  _sortable('Conversions', 'conversions'),
                  _sortable('All conversions', 'all_conversions'),
                ],
                rows: [
                  for (final r in rows)
                    DataRow(
                      cells: [
                        DataCell(Text('${r['name'] ?? r['id']}')),
                        DataCell(Text(_money(r['cost'], r['currency']))),
                        DataCell(Text(_number(r['clicks']))),
                        DataCell(Text(_number(r['impressions']))),
                        DataCell(Text(_number(r['conversions']))),
                        DataCell(Text(_number(r['all_conversions']))),
                      ],
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Google's enum in sentence case. `ENABLED` is shown as "Active" because
  /// that is the word the Google Ads interface itself uses next to a campaign,
  /// and a report that renames what the advertiser already knows is a report
  /// they have to translate.
  static String _statusWord(String? status) => switch (status) {
    'ENABLED' => 'Active',
    'PAUSED' => 'Paused',
    'REMOVED' => 'Removed',
    null => '—',
    _ => status[0] + status.substring(1).toLowerCase(),
  };

  /// Accounts Google refused, named rather than dropped.
  ///
  /// Leaving one out of the list would read as "this account spent nothing",
  /// which is a different and wrong statement.
  Widget _refusals(ThemeData theme) {
    return Card(
      key: const ValueKey('ads-refused'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Google would not report on this account',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 4),
            for (final r in _refused.cast<Map<String, dynamic>>())
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  '${r['account'] ?? 'Account'} — ${r['because'] ?? ''}',
                  style: theme.textTheme.bodySmall,
                ),
              ),
          ],
        ),
      ),
    );
  }
}
