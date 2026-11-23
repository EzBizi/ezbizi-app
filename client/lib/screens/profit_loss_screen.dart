import '../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../widgets/loading_skeleton.dart';
import '../widgets/report_grid.dart';
import '../widgets/module_overview.dart';
import '../core/theme.dart';
import '../core/job_money.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/error_note.dart';
import '../widgets/search_picker.dart';

/// Profit and loss, read live out of a business's own Airtable base.
///
/// ── WHAT THIS SCREEN IS FOR ─────────────────────────────────────
///
/// Replacing the weekly financial summary a contractor was assembling by hand
/// and sending out as an image. The figures are the same figures; what changes
/// is that they are derived from the sheet's detail lines every time somebody
/// looks, rather than typed once into a message.
///
/// ── THE DISAGREEMENTS PANEL IS THE POINT, NOT A WARNING ─────────
///
/// The reader sums the detail lines and reads the sheet's own Total cells only
/// to CHECK them. Where the two differ it says so and resolves nothing, and
/// this screen shows that list rather than tucking it behind an icon.
///
/// On A-1's own data, one week in thirty disagrees with ITSELF by $1,613 —
/// its category totals do not equal its own net cell. A screen that quietly
/// picked the prettier number would have hidden the only part anybody can act
/// on, and it would have looked right while doing it.
///
/// ── AN INCOMPLETE WEEK SHOWS NO FIGURES ─────────────────────────
///
/// Not zeros. "Nobody has finished filling this in" and "nothing happened" are
/// different facts, and a zero-cost week reads as a triumph.
class ProfitLossScreen extends StatefulWidget {
  const ProfitLossScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Below this the category table becomes a list of cards. Guessed from a
  /// gallery rather than measured against a real nine-category week.
  static const double tableAbove = 700;

  /// How long one read may take before the tab says so (a week has taken
  /// over 15 s, so the limit sits well above that). Airtable can stall;
  /// without a limit the skeleton stayed up for good (desktop review
  /// 2026-11-13, Top 2: still grey after 15 s, twice).
  static const timeout = Duration(seconds: 45);

  @override
  State<ProfitLossScreen> createState() => _ProfitLossScreenState();
}

class _ProfitLossScreenState extends State<ProfitLossScreen> {
  List<dynamic>? _sources;
  String? _sourceId;
  String? _table;
  String? _week;

  List<String> _weeks = [];
  Map<String, dynamic>? _figures;

  String? _error;
  bool _loadingSources = true;
  bool _loadingWeek = false;
  String _query = '';

  @override
  void initState() {
    super.initState();
    _loadSources();
  }

  Future<ApiResponse> _get(String path, [Map<String, String>? query]) => widget
      .api
      .get(path, query)
      .timeout(
        ProfitLossScreen.timeout,
        onTimeout: () => const ApiResponse(
          statusCode: 0,
          body: {
            'message':
                'Airtable took too long to answer. Try again in a moment.',
          },
        ),
      );

  Future<void> _loadSources() async {
    setState(() {
      _loadingSources = true;
      _error = null;
    });

    final response = await _get('/reports/profit-loss/sources');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingSources = false;
        _error = response.message;
      });
      return;
    }

    final sources = (response.body['sources'] as List<dynamic>?) ?? [];

    /*
     * OPEN ON THE FIRST BASE THAT HAS WEEKS IN IT, not the first with tables.
     *
     * Measured on A-1 2026-09-24: of three connected bases only one holds a
     * weekly P&L (appWhbwht0xgB5Yut, 7-12 weeks per table). The other two are
     * job and refund sheets - 0 weeks in every one of their 12 tables - and
     * the tab opened on the first of them, blank. Jose: "only the third one
     * shows stuff, so make that one the default".
     *
     * Found by asking rather than by naming the base: an id in the code would
     * be A-1's default for every tenant. It costs one request per base that
     * has no weeks, and stops at the first that does.
     */
    String? sourceId;
    String? table;

    for (final source in sources) {
      final tables = (source['tables'] as List<dynamic>?) ?? [];
      if (tables.isEmpty) continue;

      // The first base with tables stays the fallback, so a tenant whose
      // bases have no weeks at all still lands somewhere it can choose from.
      sourceId ??= source['id'] as String;
      table ??= tables.first as String;

      final probe = await _get('/reports/profit-loss', {
        'connection': source['id'] as String,
        'table': tables.first as String,
      });

      if (!mounted) return;

      if (probe.ok && ((probe.body['weeks'] as List?) ?? const []).isNotEmpty) {
        sourceId = source['id'] as String;
        table = tables.first as String;
        break;
      }
    }

    setState(() {
      _loadingSources = false;
      _sources = sources;
      _sourceId = sourceId;
      _table = table;
    });

    if (_sourceId != null && _table != null) _loadWeek();
  }

  Future<void> _loadWeek() async {
    setState(() {
      _loadingWeek = true;
      _error = null;
    });

    final query = {'connection': _sourceId!, 'table': _table!};

    // Built up rather than written inline: the week is genuinely optional and
    // its ABSENCE is what asks the server for the latest complete one.
    final week = _week;
    if (week != null) query['week'] = week;

    final response = await _get('/reports/profit-loss', query);

    if (!mounted) return;

    setState(() {
      _loadingWeek = false;

      if (!response.ok) {
        _error = response.message;
        _figures = null;
        return;
      }

      _weeks = ((response.body['weeks'] as List<dynamic>?) ?? [])
          .map((week) => week as String)
          .toList();

      _figures = response.body['week'] as Map<String, dynamic>?;
      _week = _figures?['week'] as String?;
    });
  }

  Map<String, dynamic>? get _source {
    if (_sources == null || _sourceId == null) return null;

    for (final source in _sources!) {
      if (source['id'] == _sourceId) return source as Map<String, dynamic>;
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_loadingSources) return const LoadingSkeleton();

    if (_sources != null && _sources!.isEmpty) {
      return _empty(
        context,
        'No Airtable base is connected yet.',
        'Profit and loss is read from a business\'s own Airtable base. Connect '
            'one under Settings, Integrations, and the weeks in it appear here.',
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _pickers(context),
        const SizedBox(height: 16),
        if (_error != null) ...[
          ErrorNote(_error!),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              key: const ValueKey('pl-try-again'),
              onPressed: _sources == null ? _loadSources : _loadWeek,
              icon: const Icon(Icons.refresh, size: 18),
              label: const Text('Try again'),
            ),
          ),
        ],
        // Grey rows, not a spinner: the week takes over 15 s (deep dive).
        if (_error == null && _loadingWeek)
          const SizedBox(height: 360, child: LoadingSkeleton(rows: 5)),
        if (_error == null && !_loadingWeek && _figures != null)
          ..._week_(context),
        // A table with no weeks said so rather than drawing nothing - a
        // blank page reads as "still loading" or "broken", and it is neither.
        if (_error == null &&
            !_loadingWeek &&
            _figures == null &&
            _table != null)
          _empty(
            context,
            'No weekly profit and loss in this table.',
            'EzBizi reads the weeks from a column named Week, and this table '
                'has none filled in. Try another base or table.',
          ),
      ],
    );
  }

  Widget _empty(BuildContext context, String title, String beneath) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(title, style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(
              beneath,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    );
  }

  /// Base, table and week.
  ///
  /// `SearchPickerField` rather than `DropdownMenu`, which is the house control
  /// and not a preference: Jose, 2026-09-17 — "this sort of popup dropdown are
  /// terrible ui design, anything that offers users should be a search bar
  /// picker instead". An unfiltered `DropdownMenu` is a popup dropdown wearing a
  /// search bar's name, and `no_popup_dropdown_test.dart` fails the build over
  /// it. This screen was written the same afternoon the rule landed and shipped
  /// with three of them.
  ///
  /// It costs nothing on the short lists here: the picker shows its search box
  /// only from eight options up, so two bases and four tables simply open. The
  /// WEEK list is the one that earns it — A-1's base carries a year of them.
  Widget _pickers(BuildContext context) {
    final source = _source;
    final tables = ((source?['tables'] as List<dynamic>?) ?? [])
        .map((table) => table as String)
        .toList();

    return Wrap(
      key: const ValueKey('tour-reports-pl-controls'),
      spacing: 12,
      runSpacing: 12,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 260,
          child: SearchPickerField<String>(
            value: _sourceId,
            label: 'Base',
            options: [
              for (final s in _sources ?? [])
                SearchOption<String>(
                  value: s['id'] as String,
                  label: (s['name'] as String?) ?? 'Airtable',

                  // A base whose token has stopped working stays in the list and
                  // says so. Dropping it makes something somebody connected last
                  // week vanish with no explanation.
                  enabled: ((s['tables'] as List<dynamic>?) ?? []).isNotEmpty,
                ),
            ],
            onChanged: (id) {
              if (id == null || id == _sourceId) return;
              setState(() {
                _sourceId = id;
                _table = ((_source?['tables'] as List<dynamic>?) ?? []).isEmpty
                    ? null
                    : (_source!['tables'] as List<dynamic>).first as String;
                _week = null;
                _figures = null;
              });
              if (_table != null) _loadWeek();
            },
          ),
        ),
        if (tables.isNotEmpty)
          SizedBox(
            width: 220,
            child: SearchPickerField<String>(
              value: _table,
              label: 'Table',
              options: [
                for (final table in tables)
                  SearchOption<String>(value: table, label: table),
              ],
              onChanged: (table) {
                if (table == null || table == _table) return;
                // The week is cleared rather than kept: week labels are free
                // text and another table need not have one spelled the same way.
                setState(() {
                  _table = table;
                  _week = null;
                });
                _loadWeek();
              },
            ),
          ),
        if (_weeks.isNotEmpty)
          SizedBox(
            width: 240,
            child: SearchPickerField<String>(
              value: _week,
              label: 'Week',
              options: [
                for (final week in _weeks)
                  SearchOption<String>(value: week, label: week),
              ],
              onChanged: (week) {
                if (week == null || week == _week) return;
                setState(() => _week = week);
                _loadWeek();
              },
            ),
          ),
        if (source?['unavailable'] != null)
          Text(
            source!['unavailable'] as String,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
      ],
    );
  }

  List<Widget> _week_(BuildContext context) {
    final figures = _figures!;
    final theme = Theme.of(context);

    if (figures['complete'] != true) {
      return [
        _empty(
          context,
          'This week is not filled in yet.',
          'One or more categories have no lines for ${figures['week']}, so there '
              'is no total to show. A partly-entered week would read as a good '
              'one rather than an unfinished one.',
        ),
      ];
    }

    final disagreements = (figures['disagreements'] as List<dynamic>?) ?? [];
    final categories = (figures['categories'] as Map<String, dynamic>?) ?? {};
    final net = (figures['net'] as num?)?.toDouble() ?? 0;

    final income = (figures['income'] as num?)?.toDouble();
    final query = _query.trim().toLowerCase();
    final shown = [
      for (final entry in categories.entries)
        if (query.isEmpty || entry.key.toLowerCase().contains(query)) entry,
    ];

    // UI queue 87, desktop 1: equal figure tiles, then the one table.
    return [
      OverviewFigures(
        key: const ValueKey('pl-figures'),
        figures: [
          OverviewFigure(
            label: 'Income',
            value: income == null ? '—' : _money(income),
          ),
          OverviewFigure(
            label: 'Costs',
            value: figures['costs'] is num
                ? _money(figures['costs'] as num)
                : '—',
          ),
          OverviewFigure(
            label: net < 0 ? 'Loss' : 'Profit',
            value: _money(net),
            tone: net < 0 ? Tone.bad : null,
          ),
          OverviewFigure(
            label: 'Margin',
            value: income == null || income == 0
                ? '—'
                : '${(net / income * 100).toStringAsFixed(1)}%',
            note: 'of income',
          ),
        ],
      ),
      const SizedBox(height: 20),
      if (disagreements.isNotEmpty) ...[
        _disagreements(context, disagreements),
        const SizedBox(height: 20),
      ],
      Wrap(
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        alignment: WrapAlignment.spaceBetween,
        children: [
          Text('Categories', style: theme.textTheme.titleMedium),
          // Standard field height, as tall as Leads search (Jose, 2026-10-27).
          SizedBox(
            width: 240,
            child: ListSearch(
              key: const Key('profit-loss-search'),
              hint: 'Find a category',
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
        ],
      ),
      const SizedBox(height: 8),
      if (shown.isEmpty && query.isNotEmpty)
        Text(
          'No categories match "${_query.trim()}".',
          style: theme.textTheme.bodyMedium,
        )
      else
        ReportGrid(
          key: const ValueKey('pl-categories'),
          columns: const [
            GridColumn('Category', pinned: true),
            GridColumn('Lines', numeric: true, main: true),
            GridColumn('Total', numeric: true, headline: true),
          ],
          rows: [
            for (final MapEntry(key: name, value: detail) in shown)
              switch ((detail as Map<String, dynamic>?) ?? const {}) {
                final row => [
                  GridCell(name),
                  GridCell(
                    (row['lines'] as num?)?.toInt() == 1
                        ? '1 line'
                        : '${(row['lines'] as num?)?.toInt() ?? 0} lines',
                  ),
                  row['total'] is num
                      ? GridCell(_money(row['total'] as num))
                      : const GridCell.none(),
                ],
              },
          ],
        ),
    ];
  }

  /// The sheet disagreeing with itself, said plainly.
  ///
  /// Deliberately not a red error: nothing is broken in EzBizi and the figures
  /// beside it are still the best available reading. It is a note about the
  /// spreadsheet, addressed to whoever maintains it.
  Widget _disagreements(BuildContext context, List<dynamic> lines) {
    final theme = Theme.of(context);

    return Card(
      color: theme.colorScheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              lines.length == 1
                  ? 'One category does not add up in the sheet'
                  : '${lines.length} categories do not add up in the sheet',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 4),
            Text(
              'The figures above come from the individual lines, which is what '
              'they always come from. These are the places where the sheet\'s '
              'own total cell says something different — worth a look in '
              'Airtable.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 8),
            for (final line in lines)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('• $line', style: theme.textTheme.bodyMedium),
              ),
          ],
        ),
      ),
    );
  }

  /// Money as every Reports tab writes it (UI queue 87): `$12,480.50`, and
  /// a minus rather than parentheses, which most people read as a footnote.
  String _money(num amount) => formatMoney((amount * 100).round());
}
