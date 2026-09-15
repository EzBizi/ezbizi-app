import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';

/// What the Google Ads accounts spent, and what came back for it.
///
/// ── Two levels, and the fields are not optional ──────────────────
///
/// Google's Reporting Minimum Functionality applies to each level of the Ads
/// hierarchy a tool DISPLAYS. This shows Account and Campaign, which makes R.10
/// and R.20 apply and leaves ad group, ad and keyword out of scope entirely.
///
/// Both levels must show clicks, cost, impressions, conversions and all
/// conversions. Every one of those is drawn below at both levels. They are not
/// columns to trim when the table feels wide: dropping one is a policy breach,
/// and on a phone the answer is to scroll rather than to hide.
///
/// `campaign.status` is drawn because paused campaigns are listed. Hiding
/// paused campaigns would remove that obligation; showing them without the
/// status would not.
class AdsPerformanceScreen extends StatefulWidget {
  const AdsPerformanceScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<AdsPerformanceScreen> createState() => _AdsPerformanceScreenState();
}

class _AdsPerformanceScreenState extends State<AdsPerformanceScreen> {
  List<dynamic> _accounts = const [];
  List<dynamic> _refused = const [];
  String? _error;
  bool _loading = true;
  int _days = 30;

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

    // Ending yesterday, not today: Google restates the current day as
    // conversions are attributed late, so today's figure is the only one on
    // screen guaranteed to change after somebody has read it.
    final to = DateTime.now().subtract(const Duration(days: 1));
    final from = to.subtract(Duration(days: _days - 1));
    String d(DateTime x) => '${x.year.toString().padLeft(4, '0')}-'
        '${x.month.toString().padLeft(2, '0')}-${x.day.toString().padLeft(2, '0')}';

    final response = await widget.api.get('/ads/performance', {
      'from': d(from),
      'to': d(to),
    });

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        // Google's own sentence where there is one - "not yet enabled or has
        // been deactivated" is a different problem from a quota refusal, and
        // both arrive as 403.
        _error = response.message;
        _accounts = const [];
        _refused = const [];
        return;
      }

      _error = null;
      // `body`, not `data`: the envelope carries `refused` beside `data`, and
      // reading `response.data['refused']` indexes a List with a String.
      _accounts = (response.body['data'] as List?) ?? const [];
      _refused = (response.body['refused'] as List?) ?? const [];
    });
  }

  /// Money, with its unit.
  ///
  /// `cost` arrives as a number and a whole figure crosses JSON as an int, so
  /// `as double` would throw on a campaign that spent exactly 900. `as num`
  /// then `toDouble()` is the only cast that holds for both.
  String _money(dynamic value, dynamic currency) {
    final amount = (value as num?)?.toDouble() ?? 0;
    final code = (currency as String?) ?? '';
    return '${amount.toStringAsFixed(2)}${code.isEmpty ? '' : ' $code'}';
  }

  String _number(dynamic value) => ((value as num?) ?? 0).toString();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Ads Performance', style: theme.textTheme.headlineSmall),
                    Text(
                      'What each connected Google Ads account spent, and what it produced.',
                      style: theme.textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
              DropdownButton<int>(
                key: const ValueKey('ads-range'),
                value: _days,
                onChanged: (v) {
                  if (v == null) return;
                  setState(() => _days = v);
                  _load();
                },
                items: const [
                  DropdownMenuItem(value: 7, child: Text('Last 7 days')),
                  DropdownMenuItem(value: 30, child: Text('Last 30 days')),
                  DropdownMenuItem(value: 90, child: Text('Last 90 days')),
                ],
              ),
              const SizedBox(width: 8),
              IconButton(
                key: const ValueKey('ads-refresh'),
                icon: const Icon(Icons.refresh),
                tooltip: 'Read again from Google',
                onPressed: _loading ? null : _load,
              ),
            ],
          ),
          const SizedBox(height: 12),
          Expanded(child: _body(theme)),
        ],
      ),
    );
  }

  Widget _body(ThemeData theme) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    if (_error != null) {
      return Center(
        child: Text(_error!, key: const ValueKey('ads-error'), textAlign: TextAlign.center),
      );
    }

    if (_accounts.isEmpty && _refused.isEmpty) {
      // The empty state names the way in. A screen that says only "no data"
      // leaves somebody with a connected account wondering which of the two
      // things is wrong.
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

    return ListView(
      children: [
        for (final account in _accounts) _account(theme, account as Map<String, dynamic>),
        if (_refused.isNotEmpty) _refusals(theme),
      ],
    );
  }

  Widget _account(ThemeData theme, Map<String, dynamic> row) {
    final account = (row['account'] as Map?)?.cast<String, dynamic>() ?? const {};
    final campaigns = (row['campaigns'] as List?) ?? const [];
    final currency = row['currency'];

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              (account['name'] as String?) ?? 'Google Ads account',
              style: theme.textTheme.titleMedium,
            ),
            Text('Account ${account['id']}', style: theme.textTheme.bodySmall),
            const SizedBox(height: 8),

            // R.10, the Account level. All five, every time.
            //
            // Keyed so a test can assert INSIDE it. Without the key the only
            // available assertion is "this label appears somewhere on screen",
            // which the campaign table's own column headers satisfy - so
            // deleting a figure from here would leave the suite green.
            Wrap(
              key: const ValueKey('ads-account-figures'),
              spacing: 24,
              runSpacing: 8,
              children: [
                _figure(theme, 'Cost', _money(account['cost'], currency)),
                _figure(theme, 'Clicks', _number(account['clicks'])),
                _figure(theme, 'Impressions', _number(account['impressions'])),
                _figure(theme, 'Conversions', _number(account['conversions'])),
                _figure(theme, 'All conversions', _number(account['all_conversions'])),
              ],
            ),

            if (campaigns.isNotEmpty) ...[
              const Divider(height: 24),
              Text('Campaigns', style: theme.textTheme.titleSmall),
              const SizedBox(height: 4),

              // R.20, the Campaign level, same five plus status. Horizontally
              // scrollable rather than column-dropping: a narrow screen must
              // not be a reason to show fewer required fields than the policy
              // asks for.
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  key: const ValueKey('ads-campaigns'),
                  columns: const [
                    DataColumn(label: Text('Campaign')),
                    DataColumn(label: Text('Status')),
                    DataColumn(label: Text('Cost')),
                    DataColumn(label: Text('Clicks')),
                    DataColumn(label: Text('Impressions')),
                    DataColumn(label: Text('Conversions')),
                    DataColumn(label: Text('All conversions')),
                  ],
                  rows: [
                    for (final c in campaigns.cast<Map<String, dynamic>>())
                      DataRow(cells: [
                        DataCell(Text((c['name'] as String?) ?? '—')),
                        DataCell(Text((c['status'] as String?) ?? '—')),
                        DataCell(Text(_money(c['cost'], currency))),
                        DataCell(Text(_number(c['clicks']))),
                        DataCell(Text(_number(c['impressions']))),
                        DataCell(Text(_number(c['conversions']))),
                        DataCell(Text(_number(c['all_conversions']))),
                      ]),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _figure(ThemeData theme, String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.bodySmall),
        Text(value, style: theme.textTheme.titleMedium),
      ],
    );
  }

  /// Accounts Google refused, named rather than dropped.
  ///
  /// A-1 holds accounts Google itself calls deactivated. Leaving them out of
  /// the list entirely would read as "this account spent nothing", which is a
  /// different and wrong statement.
  Widget _refusals(ThemeData theme) {
    return Card(
      key: const ValueKey('ads-refused'),
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Google would not report on these', style: theme.textTheme.titleSmall),
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
