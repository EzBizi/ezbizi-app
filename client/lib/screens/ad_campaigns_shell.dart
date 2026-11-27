import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/live_changes.dart';
import '../core/job_money.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../core/timestamps.dart';
import '../widgets/ad_schedule.dart';
import '../widgets/date_range_picker.dart';
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

/// The picker's value for "All accounts" (Jose, 2026-11-16).
const allAdAccounts = '*all*';

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

  /// Under the account's name in the picker: what kind of account it is,
  /// where a source has more than one (Rodrigo, 01a1ef57).
  String accountDetail(Map account) => '${account[accountNumberField]}';

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

  /// On, as opposed to paused or removed: Inactive is its own folded group
  /// (Jose, 2026-11-16: "paused ones are hidden by default").
  bool isActive(R row);

  /// A row under another (an ad set under its campaign) goes where its
  /// campaign goes.
  int depthOf(R row) => 0;

  /// The campaign map a row stands for, for the Account column.
  Map<String, dynamic> mapOf(R row);

  // ── State ──────────────────────────────────────────────────────────────

  List<Map<String, dynamic>>? accounts;
  String? account;

  /// Reports' own range: presets and From/To (Jose, 2026-11-16).
  DateSpan span = RangePreset.standard
      .firstWhere((p) => p.key == 'last_30')
      .span(DateTime.now());
  bool picking = false;
  int get days => span.days;

  /// Every account's campaigns in one list.
  bool get all => account == allAdAccounts;

  /// The account a row belongs to: its own in All, else the one chosen.
  String connectionOf(Map<String, dynamic> row) =>
      '${row['connection_id'] ?? account}';

  bool showInactive = false;

  List<Map<String, dynamic>>? campaigns;

  /// The on/off schedules of this account, by campaign id (design 185).
  Map<String, Map<String, dynamic>> schedules = const {};
  String? error;
  bool loading = false;

  /// When these figures were read from the ad network: they are live, not
  /// stored, so the time is the freshness (Rodrigo, 01a1ef57).
  DateTime? readAt;

  final TextEditingController search = TextEditingController();
  String sortBy = 'spend';
  bool descending = true;

  bool get canManage => widget.session.me?.can('ads_manager.manage') ?? false;

  @override
  void initState() {
    super.initState();
    search.addListener(() => setState(() {}));
    _loadAccounts();
    // A schedule flipped a campaign on or off (the minute applier) or was
    // set by somebody else: the campaigns and their schedules re-read
    // quietly. The figures themselves are the ad network's, read live.
    _live = LiveChanges.of(widget.api).subscribe({'ad_schedules'}, (_) {
      if (account != null && !loading) return load(quiet: true);
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
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
    final span = this.span;
    if (!quiet) {
      setState(() {
        loading = true;
        error = null;
      });
    }
    final response = await widget.api.get(campaignsPath, {
      if (id == allAdAccounts) 'all': '1' else 'connection_id': id,
      'from': span.fromKey,
      'to': span.toKey,
    });
    if (!mounted || account != id || this.span != span) return;
    setState(() {
      loading = false;
      if (!response.ok) {
        if (!quiet) error = response.message;
        return;
      }
      readAt = DateTime.now();
      campaigns = List<Map<String, dynamic>>.from(
        ((response.body['data'] as Map?)?['campaigns'] as List? ?? const [])
            .cast<Map>(),
      );
      // In All, an ad set or ad is changed on its campaign's account.
      void stamp(Map node, Object? connection) {
        node['connection_id'] ??= connection;
        for (final child in (node['children'] as List? ?? const [])) {
          stamp(child as Map, node['connection_id']);
        }
      }

      if (id == allAdAccounts) {
        for (final c in campaigns!) {
          stamp(c, null);
        }
      }
    });
    await loadSchedules();
  }

  Future<void> loadSchedules() async {
    final id = account;
    // ponytail: no schedules in All; open the account to schedule.
    if (id == null || all) {
      setState(() => schedules = const {});
      return;
    }
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
      connectionId: connectionOf(c),
      objectId: '${c['id']}',
      objectName: titleOf(c),
      schedule: schedules['${c['id']}'],
      canManage: canManage,
    );
    if (changed && mounted) loadSchedules();
  }

  /// The clock chip that opens the campaign's on/off schedule.
  Widget scheduleChip(Map<String, dynamic> c) => all
      ? const SizedBox.shrink()
      : Align(
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
        if (picking)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: DateRangePanel(
              initial: span,
              today: DateTime.now(),
              formats: widget.session.me?.display ?? DisplayFormats.fallback,
              narrow: phone,
              onApply: (span) {
                setState(() {
                  this.span = span;
                  picking = false;
                });
                load();
              },
              onCancel: () => setState(() => picking = false),
            ),
          ),
        const SizedBox(height: 12),
        if (loading)
          const Expanded(child: Center(child: CircularProgressIndicator()))
        else if (error != null)
          Expanded(
            // What failed and where it is fixed, not a bare sentence: most
            // failures here are a lost or missing permission on the account.
            child: EmptyState(
              key: ValueKey('$keyPrefix-ads-error'),
              icon: Icons.lock_outline,
              title: 'This account could not be read',
              message: error!,
              actionLabel: OpenDestination.of(context) == null
                  ? null
                  : 'Open Integrations',
              actionIcon: Icons.open_in_new,
              onAction: OpenDestination.of(context) == null
                  ? null
                  : () => OpenDestination.of(context)!('settings'),
            ),
          )
        else ...[
          if (readAt != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                key: ValueKey('$keyPrefix-ads-read-at'),
                'Read live from the ad network ${formatAgo(readAt!)}. '
                '${(campaigns ?? const []).isEmpty ? noCampaignsText : '${campaigns!.length} campaigns.'}',
                style: theme.textTheme.bodySmall,
              ),
            ),
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
                SearchOption(
                  value: allAdAccounts,
                  label: 'All accounts',
                  detail: '${accounts!.length} accounts',
                ),
                for (final a in accounts!)
                  SearchOption(
                    value: '${a['connection_id']}',
                    label: '${a['name']}',
                    detail: accountDetail(a),
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
        DateRangeBar(
          key: rangeKey,
          span: span,
          today: DateTime.now(),
          formats: widget.session.me?.display ?? DisplayFormats.fallback,
          open: picking,
          onToggle: () => setState(() => picking = !picking),
        ),
        ListSearch(
          key: _searchKeys[keyPrefix],
          controller: search,
          hint: 'Search campaigns',
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
        if (all)
          SizedBox(
            width: _accountWidth - headerStart,
            child: Text('ACCOUNT', style: head),
          ),
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
                      textAlign: label.startsWith('Budget')
                          ? null
                          : TextAlign.end,
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
          final least = tableMinWidth + (all ? _accountWidth : 0);
          final width = box.maxWidth < least ? least : box.maxWidth;
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
                        : Builder(
                            builder: (context) {
                              final items = _grouped(
                                rows,
                                (r) => _withAccount(r, tableRow(r, theme)),
                                theme,
                              );
                              return ListView.separated(
                                key: tableKey,
                                itemCount: items.length,
                                separatorBuilder: (_, _) =>
                                    const Divider(height: 1),
                                itemBuilder: (_, i) => items[i],
                              );
                            },
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
    return ListView(
      key: ValueKey('$keyPrefix-ads-cards'),
      children: _grouped(
        rows,
        (r) => all
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 8, 4, 0),
                    child: Text(
                      '${mapOf(r)['account'] ?? ''}',
                      style: theme.textTheme.labelSmall,
                    ),
                  ),
                  card(r, theme),
                ],
              )
            : card(r, theme),
        theme,
      ),
    );
  }

  static const double _accountWidth = 200;

  /// The Account column, in All only.
  Widget _withAccount(R row, Widget child) => !all
      ? child
      : Row(
          children: [
            SizedBox(
              width: _accountWidth,
              child: Padding(
                padding: const EdgeInsets.only(left: 12, right: 8),
                child: Text(
                  depthOf(row) == 0 ? '${mapOf(row)['account'] ?? ''}' : '',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ),
            Expanded(child: child),
          ],
        );

  /// Active first; Inactive (paused or removed) folded under its count.
  /// Searching opens the fold: a fold that hides a match is the defect.
  List<Widget> _grouped(
    List<R> rows,
    Widget Function(R) draw,
    ThemeData theme,
  ) {
    final active = <R>[];
    final inactive = <R>[];
    var on = true;
    for (final r in rows) {
      if (depthOf(r) == 0) on = isActive(r);
      (on ? active : inactive).add(r);
    }
    final open = showInactive || needle.isNotEmpty;
    final count = inactive.where((r) => depthOf(r) == 0).length;

    return [
      for (final r in active) draw(r),
      if (inactive.isNotEmpty)
        InkWell(
          key: ValueKey('$keyPrefix-inactive'),
          onTap: () => setState(() => showInactive = !showInactive),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              children: [
                Icon(open ? Icons.expand_less : Icons.expand_more, size: 18),
                const SizedBox(width: 6),
                Text('Inactive ($count)', style: theme.textTheme.titleSmall),
              ],
            ),
          ),
        ),
      if (open)
        for (final r in inactive) draw(r),
    ];
  }
}
