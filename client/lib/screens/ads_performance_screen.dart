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
/// What this screen last showed, kept for as long as the app is open.
///
/// Jose, 2026-09-24: *"every time i leave one of the ads pages and come back it
/// loads everything all over again ... it should load the cache and in the
/// meantime fetch the new stuff and then refresh itself (seamlessly)"*.
///
/// So the screen's state outlives the widget. Coming back draws the last
/// figures for the same account and range immediately, asks Google again
/// behind them, and swaps the new ones in when they arrive - no spinner, and
/// never a blank page where numbers just were.
///
/// ── Keyed by WHO is looking and at WHAT ─────────────────────────────
///
/// The key carries the user, the organization and the company in view.
/// Switching the company picker, or somebody else signing in on the same
/// machine, is a different key and therefore a cold load - one company's
/// advertising spend must never appear, even for a frame, under another's
/// name.
///
/// ponytail: memory only, gone when the app closes. Figures here are restated
/// by Google as conversions arrive, so a copy on disk from yesterday would be
/// shown as if current; persist it only if a cold start proves too slow.
class _AdsMemory {
  List<Map<String, dynamic>>? accounts;
  String? selected;
  int range = 0;
  DateTimeRange? custom;
  Set<String> extra = <String>{};

  /// Report bodies by account + window.
  final Map<String, Map<String, dynamic>> reports = {};

  /// When each report body was read, so the screen can say how old it is.
  final Map<String, DateTime> readAt = {};
}

final Map<String, _AdsMemory> _memories = {};

/// Forget everything remembered. Tests only: each one must start cold, or the
/// previous test's account and figures would be on screen before it began.
@visibleForTesting
void forgetAdsMemory() => _memories.clear();

/// One optional metric column: "All other Metric columns must be made
/// available, as an option" (Reporting RMF, reporting-only clients).
class _Extra {
  const _Extra(this.key, this.label, this.kind);

  final String key;
  final String label;

  /// 'number', 'money', 'percent' or 'ratio'.
  final String kind;
}

/// The optional metrics, in the order the chooser lists them.
///
/// Every one is valid at BOTH the account and the campaign level - measured
/// against Google on 2026-09-24; `phone_calls` is valid on campaign and refused
/// on customer, which would have broken the account figures - and the ratios
/// are derived on the server from summed totals, never summed themselves.
const _extras = <_Extra>[
  _Extra('ctr', 'CTR', 'percent'),
  _Extra('average_cpc', 'Avg. CPC', 'money'),
  _Extra('average_cpm', 'Avg. CPM', 'money'),
  _Extra('cost_per_conversion', 'Cost / conv.', 'money'),
  _Extra('conversion_rate', 'Conv. rate', 'percent'),
  _Extra('conversions_value', 'Conv. value', 'money'),
  _Extra('all_conversions_value', 'All conv. value', 'money'),
  _Extra('value_per_conversion', 'Value / conv.', 'money'),
  _Extra('conversion_value_per_cost', 'Conv. value / cost', 'ratio'),
  _Extra('interactions', 'Interactions', 'number'),
  _Extra('interaction_rate', 'Interaction rate', 'percent'),
  _Extra('view_through_conversions', 'View-through conv.', 'number'),
  _Extra('engagements', 'Engagements', 'number'),
  _Extra('invalid_clicks', 'Invalid clicks', 'number'),
];

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

  /// Figures are on screen from memory and fresher ones are being fetched.
  bool _refreshing = false;

  /// The refresh behind remembered figures failed. The figures stay - they
  /// are the last true ones - and this says how old they are.
  String? _staleBecause;

  /// Campaigns ticked for Pause / Enable, by campaign id.
  final Set<String> _picked = <String>{};

  /// Which change is waiting for its confirmation: 'PAUSED' or 'ENABLED'.
  String? _confirming;
  bool _changing = false;

  /// What the last Pause / Enable did, in Google's own words where it refused.
  String? _changeResult;

  /// Optional metric columns somebody chose.
  Set<String> get _extra => _memory.extra;

  late final _AdsMemory _memory;

  /*
   * Yesterday, not 30 days. Jose's call, 2026-09-15.
   *
   * It is also the cheapest default: a one-day window is one day of rows from
   * Google rather than thirty, and this screen is opened far more often than a
   * month's history is actually wanted.
   */
  int _range = 0;
  DateTimeRange? _custom;

  /// The word Google puts in the name of every Local Services campaign.
  ///
  /// It is generated rather than typed - `LocalServicesCampaign:SystemGenerated:
  /// 00062050e55e652b` - so the name IS the type, and there is no field on the
  /// campaign that says so. Matched case-insensitively because a generated
  /// string is not a thing to be fussy about the casing of.
  static const _localServicesMark = 'localservicescampaign';

  /// Which KIND of campaign is listed - Jose, 2026-09-17.
  ///
  /// A second dimension beside status rather than more status chips: Local
  /// Services and Search are what a campaign IS, and Active and Paused are what
  /// it is DOING. A business asks both questions at once - "what are my Search
  /// ads costing while Local Services is paused" - and chips that shared one
  /// set could not express it.
  ///
  /// Empty means all, the same rule the status chips follow. Selecting both is
  /// therefore the same as selecting neither, which is the honest answer rather
  /// than a special case: the two cover every campaign between them.
  final Set<String> _adTypes = <String>{};

  static const _typeLocalServices = 'local_services';
  static const _typeSearch = 'search';

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

  /// Who is looking at what - see [_AdsMemory].
  String get _who {
    final me = widget.session.me;
    return '${me?.id}|${me?.organization.id}|${me?.scope.type}:${me?.scope.id}';
  }

  String _reportKey(String id, DateTime from, DateTime to) =>
      '$id|${_d(from)}|${_d(to)}|${_selectedIsManager ? 'rollup' : 'account'}';

  @override
  void initState() {
    super.initState();
    _memory = _memories.putIfAbsent(_who, _AdsMemory.new);

    // Everything the last visit showed, before a single request is made.
    if (_memory.accounts != null) {
      _accounts = _memory.accounts!;
      _loadingAccounts = false;
    }
    _selected = _memory.selected;
    _range = _memory.range;
    _custom = _memory.custom;
    if (_selected != null) _loadReport();

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
    // Remembered accounts stay on screen while the list is asked again; only a
    // first visit shows the spinner.
    final quiet = _memory.accounts != null;

    if (!quiet) {
      setState(() {
        _loadingAccounts = true;
        _error = null;
      });
    }

    final response = await widget.api.get('/ads/accounts');
    if (!mounted) return;

    setState(() {
      _loadingAccounts = false;
      if (!response.ok) {
        // A failed refresh of a list we already hold is not worth replacing
        // the list with an error.
        if (!quiet) _error = response.message;
        return;
      }
      _accounts = List<Map<String, dynamic>>.from(
        (response.body['data'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      _memory.accounts = _accounts;
      // Deliberately does NOT auto-select. Picking one is what spends quota,
      // and a screen that spends it on open is the thing this replaced.
    });
  }

  bool get _selectedIsManager {
    final a = _accounts.where((a) => a['connection_id'] == _selected);
    return a.isNotEmpty && a.first['is_manager'] == true;
  }

  /// The report: remembered figures first, then Google's current ones.
  ///
  /// With something remembered for this account and window, it is drawn at
  /// once and the request runs behind it - `_refreshing` shows a thin bar, not
  /// a spinner in place of the numbers. Without, it is the ordinary cold load.
  Future<void> _loadReport() async {
    final id = _selected;
    if (id == null) return;

    _memory
      ..selected = id
      ..range = _range
      ..custom = _custom;

    final (from, to) = _window();
    final key = _reportKey(id, from, to);
    final manager = _selectedIsManager;
    final remembered = _memory.reports[key];

    setState(() {
      _error = null;
      _staleBecause = null;
      if (remembered != null) {
        _apply(remembered, manager);
        _loadingReport = false;
        _refreshing = true;
      } else {
        _loadingReport = true;
        _refreshing = false;
        _report = null;
        _rollup = null;
      }
    });

    final query = {'connection_id': id, 'from': _d(from), 'to': _d(to)};

    // A manager holds no spend of its own — every figure on it would be zero —
    // so asking it for a campaign report answers a question nobody meant. The
    // roll-up is what "A1 Chimney - Ads Manager" actually means.
    final response = await widget.api.get(
      manager ? '/ads/rollup' : '/ads/performance',
      query,
    );

    // Somebody picked another account or range while this was in flight: its
    // answer belongs to a page that is no longer on screen.
    if (!mounted || _selected != id || _reportKey(id, from, to) != key) return;

    setState(() {
      _loadingReport = false;
      _refreshing = false;

      if (!response.ok) {
        if (remembered != null) {
          // Keep the figures - they are the last true ones - and say so.
          _staleBecause = response.message;
        } else {
          _error = response.message;
        }
        return;
      }

      _memory.reports[key] = response.body;
      _memory.readAt[key] = DateTime.now();
      _apply(response.body, manager);
    });
  }

  void _apply(Map<String, dynamic> body, bool manager) {
    _refused = (body['refused'] as List? ?? const []);

    if (manager) {
      _report = null;
      _rollup = (body['data'] as List? ?? const []);
      _rollupChildren = (body['children_total'] as num?)?.toInt() ?? 0;
      _rollupNotConnected = (body['not_connected'] as num?)?.toInt() ?? 0;
      return;
    }

    _rollup = null;
    final data = (body['data'] as List? ?? const []);
    _report = data.isEmpty ? null : data.first as Map<String, dynamic>;

    // A remembered selection may name campaigns this page no longer lists.
    final present = {
      for (final c in (_report?['campaigns'] as List? ?? const [])) '${(c as Map)['id']}',
    };
    _picked.removeWhere((id) => !present.contains(id));
  }

  /// Pause or enable the ticked campaigns, then read the account again.
  ///
  /// The page is re-read rather than patched: Google is the authority on what
  /// state a campaign is in, and a row flipped locally after a refused change
  /// would show a pause that never happened.
  Future<void> _change(String status) async {
    final id = _selected;
    if (id == null || _picked.isEmpty) return;

    setState(() {
      _changing = true;
      _confirming = null;
      _changeResult = null;
    });

    final asked = _picked.toList();
    final response = await widget.api.post('/ads/campaigns/status', {
      'connection_id': id,
      'campaign_ids': asked,
      'status': status,
    });

    if (!mounted) return;

    final results = (response.body['data'] as List? ?? const []).cast<Map>();
    final done = results.where((r) => r['ok'] == true).length;
    final refused = results.where((r) => r['ok'] != true).toList();
    final names = {
      for (final c in (_report?['campaigns'] as List? ?? const [])) '${(c as Map)['id']}': '${c['name']}',
    };

    setState(() {
      _changing = false;
      _picked.clear();

      if (!response.ok) {
        _changeResult = response.message;
        return;
      }

      final verb = status == 'PAUSED' ? 'Paused' : 'Enabled';
      _changeResult = [
        '$verb $done of ${asked.length}.',
        for (final r in refused)
          '${names[r['campaign_id']] ?? r['campaign_id']}: ${r['because']}',
      ].join('\n');

      // Whatever was remembered for this account is now wrong.
      _memory.reports.removeWhere((k, _) => k.startsWith('$id|'));
    });

    await _loadReport();
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
                  // A manager's row says what the click actually does. It
                  // reads every account beneath it - one Google query each -
                  // which is the same fan-out that was just taken OFF page
                  // load, and the only thing that makes it correct here is
                  // that somebody chose it knowing what it was.
                  subtitle: Text(
                    a['is_manager'] == true
                        ? '${a['customer_id']} - reads every account beneath it'
                        : '${a['customer_id']}',
                  ),
                  onTap: () {
                    setState(() {
                      _selected = id;
                      _picked.clear();
                      _confirming = null;
                      _changeResult = null;
                    });
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
          _freshness(theme),
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
        _freshness(theme),
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

  /// How fresh the figures are: a thin bar while they are being re-read, a
  /// sentence if re-reading failed. Nothing at all when they are current.
  Widget _freshness(ThemeData theme) {
    if (_refreshing) {
      return const Padding(
        padding: EdgeInsets.only(bottom: 8),
        child: LinearProgressIndicator(key: ValueKey('ads-refreshing'), minHeight: 2),
      );
    }

    if (_staleBecause == null) return const SizedBox.shrink();

    final id = _selected;
    final (from, to) = _window();
    final at = id == null ? null : _memory.readAt[_reportKey(id, from, to)];
    final when = at == null
        ? 'earlier'
        : '${at.hour.toString().padLeft(2, '0')}:${at.minute.toString().padLeft(2, '0')}';

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Text(
        'Showing the figures read at $when - Google did not answer just now: $_staleBecause',
        key: const ValueKey('ads-stale'),
        style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.error),
      ),
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
                for (final e in _extras.where((e) => _extra.contains(e.key)))
                  _figure(theme, e.label, _format(e, account[e.key], currency)),
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

  /// Does this campaign match the kind filter?
  ///
  /// True when nothing is selected, and true when BOTH are - the two chips
  /// partition the list, so asking for both is asking for all of it. Written as
  /// a set test rather than a boolean pair for exactly that reason: a pair of
  /// bools would have needed a fourth case nobody wanted.
  bool _isAdType(Map<String, dynamic> campaign) {
    if (_adTypes.isEmpty) return true;

    final isLocalServices = '${campaign['name']}'.toLowerCase().contains(
      _localServicesMark,
    );

    return _adTypes.contains(
      isLocalServices ? _typeLocalServices : _typeSearch,
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
                  _isAdType(c) &&
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
            // The title may wrap and the Columns button may not: at phone width
            // the three did not fit on one line and overflowed by 41px.
            Row(
              children: [
                Expanded(
                  child: Wrap(
                    spacing: 12,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text('Campaigns', style: theme.textTheme.titleSmall),
                      Text(
                        '${rows.length} of ${all.length}',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                _columnsButton(theme),
              ],
            ),
            if (_canManage && (_picked.isNotEmpty || _changing || _changeResult != null))
              _actionBar(theme),
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
                // The kind of campaign, beside the state of it. Drawn in the
                // same Wrap so a narrow window reflows all of them together
                // rather than leaving one row of chips stranded above another.
                FilterChip(
                  key: const ValueKey('ads-type-local_services'),
                  label: const Text('LS Ads'),
                  selected: _adTypes.contains(_typeLocalServices),
                  onSelected: (on) => setState(() {
                    on
                        ? _adTypes.add(_typeLocalServices)
                        : _adTypes.remove(_typeLocalServices);
                  }),
                ),
                FilterChip(
                  key: const ValueKey('ads-type-search'),
                  label: const Text('Search Ads'),
                  selected: _adTypes.contains(_typeSearch),
                  onSelected: (on) => setState(() {
                    on ? _adTypes.add(_typeSearch) : _adTypes.remove(_typeSearch);
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
                // Ticking a row selects it for Pause / Enable. Offered only to
                // somebody holding the grant: a checkbox whose only outcome is
                // a refusal is a control that lies about what it does.
                showCheckboxColumn: _canManage,
                columns: [
                  _sortable('Campaign', 'name'),
                  _sortable('Status', 'status'),
                  _sortable('Cost', 'cost'),
                  _sortable('Clicks', 'clicks'),
                  _sortable('Impressions', 'impressions'),
                  _sortable('Conversions', 'conversions'),
                  _sortable('All conversions', 'all_conversions'),
                  for (final e in _extras.where((e) => _extra.contains(e.key)))
                    _sortable(e.label, e.key),
                ],
                rows: [
                  for (final c in rows)
                    DataRow(
                      key: ValueKey('ads-campaign-${c['id']}'),
                      selected: _picked.contains('${c['id']}'),
                      onSelectChanged: !_canManage || _changing
                          ? null
                          : (on) => setState(() {
                              _confirming = null;
                              on == true
                                  ? _picked.add('${c['id']}')
                                  : _picked.remove('${c['id']}');
                            }),
                      cells: [
                        DataCell(Text((c['name'] as String?) ?? '—')),
                        DataCell(Text(_statusWord(c['status'] as String?))),
                        DataCell(Text(_money(c['cost'], currency))),
                        DataCell(Text(_number(c['clicks']))),
                        DataCell(Text(_number(c['impressions']))),
                        DataCell(Text(_number(c['conversions']))),
                        DataCell(Text(_number(c['all_conversions']))),
                        for (final e in _extras.where((e) => _extra.contains(e.key)))
                          DataCell(Text(_format(e, c[e.key], currency))),
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

  /// The columns in the order the table draws them, so the sort arrow sits on
  /// the column actually sorted - including an optional one.
  List<String> get _sortColumns => [
    'name',
    'status',
    'cost',
    'clicks',
    'impressions',
    'conversions',
    'all_conversions',
    for (final e in _extras.where((e) => _extra.contains(e.key))) e.key,
  ];

  int _sortIndex() {
    final i = _sortColumns.indexOf(_sortBy);
    return i < 0 ? 2 : i;
  }

  static const _rollupColumns = ['name', 'cost', 'clicks', 'impressions', 'conversions', 'all_conversions'];

  int? _rollupSortIndex() {
    final i = _rollupColumns.indexOf(_sortBy);
    return i < 0 ? null : i;
  }

  bool get _canManage => widget.session.me?.can('ads_performance.manage') ?? false;

  /// An optional figure in its own unit. Null is a dash: a rate with nothing
  /// under it (no impressions, no clicks) has no value, and zero would claim
  /// one.
  String _format(_Extra e, dynamic value, dynamic currency) {
    if (value == null) return '—';
    final n = (value as num).toDouble();
    return switch (e.kind) {
      'money' => _money(n, currency),
      'percent' => '${(n * 100).toStringAsFixed(2)}%',
      'ratio' => n.toStringAsFixed(2),
      _ => _number(n),
    };
  }

  /// "All other Metric columns must be made available, as an option".
  ///
  /// The required five are not in this menu and cannot be switched off - the
  /// policy says they are shown "as default", and a chooser that could hide
  /// Clicks would be one click from breaching it.
  Widget _columnsButton(ThemeData theme) {
    return PopupMenuButton<String>(
      key: const ValueKey('ads-columns'),
      tooltip: 'Add or remove optional columns',
      onSelected: (key) => setState(() {
        _extra.contains(key) ? _extra.remove(key) : _extra.add(key);
        if (!_extra.contains(_sortBy) && _extras.any((e) => e.key == _sortBy)) {
          _sortBy = 'cost';
          _sortDescending = true;
        }
      }),
      itemBuilder: (context) => [
        for (final e in _extras)
          CheckedPopupMenuItem<String>(
            key: ValueKey('ads-column-${e.key}'),
            value: e.key,
            checked: _extra.contains(e.key),
            child: Text(e.label),
          ),
      ],
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.view_column_outlined, size: 18),
            const SizedBox(width: 6),
            Text(
              _extra.isEmpty ? 'Columns' : 'Columns (${_extra.length})',
              style: theme.textTheme.labelLarge,
            ),
          ],
        ),
      ),
    );
  }

  /// Pause / Enable for the ticked campaigns, with its confirmation inline.
  ///
  /// Inline rather than a dialog, the house rule: a confirmation needs nowhere
  /// to type, only somewhere to state the consequence and two answers - and
  /// the consequence here is real money and a phone that stops ringing.
  Widget _actionBar(ThemeData theme) {
    final account = '${(_report?['account'] as Map?)?['name'] ?? 'this account'}';
    final n = _picked.length;
    final plural = n == 1 ? 'campaign' : 'campaigns';

    return Container(
      key: const ValueKey('ads-actions'),
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (_changing)
            Row(
              children: [
                const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
                const SizedBox(width: 10),
                Text('Telling Google…', style: theme.textTheme.bodySmall),
              ],
            )
          else if (_confirming != null)
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  _confirming == 'PAUSED'
                      ? 'Pause $n $plural in $account? Their ads stop showing within minutes.'
                      : 'Enable $n $plural in $account? They start spending again straight away.',
                  style: theme.textTheme.bodySmall,
                ),
                TextButton(
                  onPressed: () => setState(() => _confirming = null),
                  child: const Text('Not yet'),
                ),
                FilledButton(
                  key: const ValueKey('ads-confirm'),
                  onPressed: () => _change(_confirming!),
                  // Names the act, so the last button pressed says what it does.
                  child: Text(_confirming == 'PAUSED' ? 'Pause them' : 'Enable them'),
                ),
              ],
            )
          else if (_picked.isNotEmpty)
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text('$n selected', style: theme.textTheme.bodySmall),
                OutlinedButton.icon(
                  key: const ValueKey('ads-pause'),
                  icon: const Icon(Icons.pause, size: 16),
                  label: const Text('Pause'),
                  onPressed: () => setState(() => _confirming = 'PAUSED'),
                ),
                OutlinedButton.icon(
                  key: const ValueKey('ads-enable'),
                  icon: const Icon(Icons.play_arrow, size: 16),
                  label: const Text('Enable'),
                  onPressed: () => setState(() => _confirming = 'ENABLED'),
                ),
                TextButton(
                  onPressed: () => setState(_picked.clear),
                  child: const Text('Clear'),
                ),
              ],
            ),
          if (_changeResult != null && !_changing) ...[
            if (_picked.isNotEmpty || _confirming != null) const SizedBox(height: 8),
            Text(_changeResult!, key: const ValueKey('ads-change-result'), style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
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
                // Its OWN column order, not the campaign table's: that one has
                // Status second and optional columns after, so reusing its
                // index put the arrow one column off here - and past the last
                // column entirely when an optional one was sorted, which
                // DataTable asserts on.
                sortColumnIndex: _rollupSortIndex(),
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
