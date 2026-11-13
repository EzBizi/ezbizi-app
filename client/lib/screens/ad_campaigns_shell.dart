import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/job_money.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/ad_schedule.dart';
import '../widgets/empty_state.dart';
import '../widgets/list_search.dart';
import '../widgets/open_destination.dart';
import '../widgets/search_picker.dart';

/// What Marketing › Ads draws the same way for every advertiser: the account
/// picker, the date range, the search, the four tiles, the sortable table on
/// a wide screen and the cards on a phone. MetaCampaignsView and
/// GoogleCampaignsView supply their rows, cells and changes; this holds the
/// rest, so the two cannot drift apart on the parts Jose asked to match
/// (2026-11-09: "the same filter bar, tiles and table").
abstract class AdCampaignsView extends StatefulWidget {
  const AdCampaignsView({
    super.key,
    required this.api,
    required this.session,
    required this.sourceSwitch,
  });

  final ApiClient api;
  final Session session;

  /// The Google | Meta control, drawn in this view's own filter bar.
  final Widget sourceSwitch;
}

const _ranges = <int, String>{
  1: 'Today',
  7: 'Last 7 days',
  30: 'Last 30 days',
  90: 'Last 90 days',
};

double adNumber(dynamic v) => (v as num?)?.toDouble() ?? 0;

String adMoney(dynamic v) => formatMoney((adNumber(v) * 100).round());

/// [R] is one visible row as the source walks it: a campaign, or a campaign
/// with its depth in a tree.
abstract class AdCampaignsState<W extends AdCampaignsView, R> extends State<W> {
  // ── What each source says ──────────────────────────────────────────────

  /// Prefixes every key on the screen: `meta`, `google`.
  String get keyPrefix;

  /// The keys a Marketing › Ads walkthrough points at, spelled out by each
  /// source: tab_tours_test reads the source for `ValueKey('google-ads-range')`
  /// as written, so a key built from [keyPrefix] would read as gone.
  Key get rangeKey;
  Key get tableKey;

  /// The search field's key, spelled out here rather than by each source:
  /// list_search_guard_test wants the file that draws the list to hold its
  /// search Key, and tab_tours_test wants `ValueKey('google-ads-search')` as
  /// written.
  static const _searchKeys = {
    'meta': ValueKey('meta-ads-search'),
    'google': ValueKey('google-ads-search'),
  };
  String get accountsPath;
  String get campaignsPath;

  /// The account number shown under its name in the picker.
  String get accountNumberField;
  bool keepAccount(Map account) => true;

  String get emptyTitle;
  String get emptyMessage;
  String get noCampaignsText;

  /// The field counted as a result, and the two tiles that name it.
  String get resultsField;
  String get resultsLabel;
  String get costPerLabel;

  String get tableHeading;
  List<(String, String?, double)> get columns;
  double get tableMinWidth;
  double get headerStart;

  List<R> visibleRows();
  Widget tableRow(R row, ThemeData theme);
  Widget card(R row, ThemeData theme);

  /// A sentence under the tiles, when the figures need one.
  Widget? kpiNote(List<Map<String, dynamic>> campaigns, double spend) => null;

  /// Runs inside the setState that switches the account.
  void onAccountChanged() {}

  // ── State ──────────────────────────────────────────────────────────────

  List<Map<String, dynamic>>? accounts;
  String? account;
  int days = 30;

  List<Map<String, dynamic>>? campaigns;

  /// The on/off schedules of this account, by campaign id (design 185).
  Map<String, Map<String, dynamic>> schedules = const {};
  String? error;
  bool loading = false;

  final TextEditingController search = TextEditingController();
  String sortBy = 'spend';
  bool descending = true;

  bool get canManage => widget.session.me?.can('ads_manager.manage') ?? false;

  @override
  void initState() {
    super.initState();
    search.addListener(() => setState(() {}));
    _loadAccounts();
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Future<void> _loadAccounts() async {
    final response = await widget.api.get(accountsPath);
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        error = response.message;
        accounts = const [];
        return;
      }
      accounts = [
        for (final a
            in (response.body['data'] as List? ?? const []).cast<Map>())
          if (keepAccount(a)) Map<String, dynamic>.from(a),
      ];
      if (accounts!.isNotEmpty) {
        account = accounts!.first['connection_id'] as String?;
      }
    });
    if (account != null) await load();
  }

  /// The campaigns for the account and range. [quiet] keeps the rows on
  /// screen while they are asked again, after a switch or a budget change.
  Future<void> load({bool quiet = false}) async {
    final id = account;
    if (id == null) return;
    final days = this.days;
    if (!quiet) {
      setState(() {
        loading = true;
        error = null;
      });
    }
    final to = DateTime.now();
    final response = await widget.api.get(campaignsPath, {
      'connection_id': id,
      'from': formatCalendarDay(to.subtract(Duration(days: days - 1))),
      'to': formatCalendarDay(to),
    });
    if (!mounted || account != id || this.days != days) return;
    setState(() {
      loading = false;
      if (!response.ok) {
        if (!quiet) error = response.message;
        return;
      }
      campaigns = List<Map<String, dynamic>>.from(
        ((response.body['data'] as Map?)?['campaigns'] as List? ?? const [])
            .cast<Map>(),
      );
    });
    await loadSchedules();
  }

  Future<void> loadSchedules() async {
    final id = account;
    if (id == null) return;
    final response = await widget.api.get('/ad-schedules', {
      'connection_id': id,
    });
    if (!mounted || account != id || !response.ok) return;
    setState(
      () => schedules = {
        for (final row
            in (response.body['data'] as List? ?? const []).cast<Map>())
          '${row['object_id']}': Map<String, dynamic>.from(row),
      },
    );
  }

  /// The name a campaign is shown under; a source may rename system names.
  String titleOf(Map<String, dynamic> c) => '${c['name']}';

  Future<void> _editSchedule(Map<String, dynamic> c) async {
    final changed = await editAdSchedule(
      context,
      api: widget.api,
      connectionId: account!,
      objectId: '${c['id']}',
      objectName: titleOf(c),
      schedule: schedules['${c['id']}'],
      canManage: canManage,
    );
    if (changed && mounted) loadSchedules();
  }

  /// The clock chip that opens the campaign's on/off schedule.
  Widget scheduleChip(Map<String, dynamic> c) => Align(
    alignment: AlignmentDirectional.centerStart,
    child: AdScheduleChip(
      key: ValueKey('$keyPrefix-schedule-${c['id']}'),
      schedule: schedules['${c['id']}'],
      canManage: canManage,
      onTap: () => _editSchedule(c),
    ),
  );

  // ── Figures ────────────────────────────────────────────────────────────

  String get needle => search.text.trim().toLowerCase();

  List<Map<String, dynamic>> sorted(List<Map<String, dynamic>> rows) {
    final sorted = [...rows];
    sorted.sort((a, b) {
      final x = adNumber(a[sortBy]);
      final y = adNumber(b[sortBy]);
      return descending ? y.compareTo(x) : x.compareTo(y);
    });
    return sorted;
  }

  String get _emptyText =>
      needle.isEmpty ? noCampaignsText : 'Nothing matches "${search.text}".';

  // ── Drawing ────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final phone = MediaQuery.sizeOf(context).width < 600;

    if (accounts == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          widget.sourceSwitch,
          const Expanded(child: Center(child: CircularProgressIndicator())),
        ],
      );
    }
    if (accounts!.isEmpty) {
      final open = OpenDestination.of(context);
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          widget.sourceSwitch,
          Expanded(
            child: EmptyState(
              key: ValueKey('$keyPrefix-ads-empty'),
              icon: Icons.campaign_outlined,
              title: emptyTitle,
              message: error ?? emptyMessage,
              actionLabel: open == null ? null : 'Open Integrations',
              actionIcon: Icons.open_in_new,
              onAction: open == null ? null : () => open('settings'),
            ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _filters(),
        const SizedBox(height: 12),
        if (loading)
          const Expanded(child: Center(child: CircularProgressIndicator()))
        else if (error != null)
          Expanded(
            child: Center(
              child: Text(error!, style: theme.textTheme.bodyMedium),
            ),
          )
        else ...[
          _kpis(phone),
          const SizedBox(height: 12),
          Expanded(child: phone ? _cards(theme) : _table(theme)),
        ],
      ],
    );
  }

  Widget _filters() {
    return Wrap(
      spacing: 10,
      runSpacing: 10,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        widget.sourceSwitch,
        if (accounts!.length > 1)
          SizedBox(
            width: 240,
            child: SearchPickerField<String>(
              key: ValueKey('$keyPrefix-ads-account'),
              value: account,
              isDense: true,
              options: [
                for (final a in accounts!)
                  SearchOption(
                    value: '${a['connection_id']}',
                    label: '${a['name']}',
                    detail: '${a[accountNumberField]}',
                  ),
              ],
              onChanged: (id) {
                if (id == null) return;
                setState(() {
                  account = id;
                  onAccountChanged();
                });
                load();
              },
            ),
          ),
        PopupMenuButton<int>(
          key: rangeKey,
          initialValue: days,
          onSelected: (days) {
            setState(() => this.days = days);
            load();
          },
          itemBuilder: (_) => [
            for (final e in _ranges.entries)
              PopupMenuItem(value: e.key, child: Text(e.value)),
          ],
          child: Chip(
            label: Text(_ranges[days]!),
            avatar: const Icon(Icons.date_range, size: 18),
          ),
        ),
        ListSearch(
          key: _searchKeys[keyPrefix],
          controller: search,
          hint: 'Search campaigns',
          width: 260,
          onClear: search.clear,
        ),
      ],
    );
  }

  Widget _kpis(bool phone) {
    final campaigns = this.campaigns ?? const [];
    final spend = campaigns.fold<double>(0, (s, c) => s + adNumber(c['spend']));
    final results = campaigns.fold<double>(
      0,
      (s, c) => s + adNumber(c[resultsField]),
    );
    final clicks = campaigns.fold<double>(
      0,
      (s, c) => s + adNumber(c['clicks']),
    );
    final tiles = {
      'Spend': adMoney(spend),
      resultsLabel: formatCount(results.round()),
      costPerLabel: results > 0 ? adMoney(spend / results) : '—',
      'Clicks': formatCount(clicks.round()),
    };
    final theme = Theme.of(context);

    final grid = LayoutBuilder(
      builder: (context, box) {
        final width = phone ? (box.maxWidth - 10) / 2 : (box.maxWidth - 30) / 4;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final e in tiles.entries)
              SizedBox(
                width: width,
                child: Card(
                  margin: EdgeInsets.zero,
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(e.key, style: theme.textTheme.bodySmall),
                        const SizedBox(height: 4),
                        Text(
                          e.value,
                          key: ValueKey('$keyPrefix-kpi-${e.key}'),
                          style: theme.textTheme.titleLarge,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
    final note = kpiNote(campaigns, spend);
    if (note == null) return grid;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [grid, const SizedBox(height: 6), note],
    );
  }

  Widget _table(ThemeData theme) {
    final rows = visibleRows();
    final head = theme.textTheme.labelSmall;

    Widget header() => Row(
      children: [
        Expanded(child: Text(tableHeading, style: head)),
        for (final (label, key, width) in columns)
          SizedBox(
            width: width,
            child: key == null
                ? Text(label.toUpperCase(), style: head)
                : InkWell(
                    key: ValueKey('$keyPrefix-sort-$key'),
                    onTap: () => setState(() {
                      descending = sortBy == key ? !descending : true;
                      sortBy = key;
                    }),
                    child: Text(
                      '${label.toUpperCase()}'
                      '${sortBy == key ? (descending ? ' ↓' : ' ↑') : ''}',
                      style: head,
                      textAlign: label == 'Budget / day' ? null : TextAlign.end,
                    ),
                  ),
          ),
      ],
    );

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: LayoutBuilder(
        builder: (context, box) {
          final width = box.maxWidth < tableMinWidth
              ? tableMinWidth
              : box.maxWidth;
          return SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: SizedBox(
              width: width,
              child: Column(
                children: [
                  Padding(
                    padding: EdgeInsets.fromLTRB(headerStart, 10, 12, 8),
                    child: header(),
                  ),
                  const Divider(height: 1),
                  Expanded(
                    child: rows.isEmpty
                        ? Center(child: Text(_emptyText))
                        : ListView.separated(
                            key: tableKey,
                            itemCount: rows.length,
                            separatorBuilder: (_, _) =>
                                const Divider(height: 1),
                            itemBuilder: (_, i) => tableRow(rows[i], theme),
                          ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _cards(ThemeData theme) {
    final rows = visibleRows();
    if (rows.isEmpty) return Center(child: Text(_emptyText));
    return ListView.builder(
      key: ValueKey('$keyPrefix-ads-cards'),
      itemCount: rows.length,
      itemBuilder: (_, i) => card(rows[i], theme),
    );
  }
}
