import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/files.dart';
import '../core/job_money.dart';
import '../core/live_changes.dart';
import '../core/job_status.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/calendar_day_field.dart';
import '../widgets/colour_field.dart';
import '../widgets/list_search.dart';
import '../widgets/search_picker.dart';
import '../widgets/list_table.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';
import '../widgets/tag.dart';
import 'jobs/job_labels.dart';
import 'jobs/job_detail_panel.dart';
import 'jobs/job_notes.dart';
import 'jobs/job_lines_tab.dart' show pickJobTechnician;

/// Project Management (Jose, 2026-11-06): six sub-tabs of jobs, from
/// unscheduled to complete, each with a Franchise filter (All by default),
/// and a Settings sub-tab. Nothing about the table is fixed here: which
/// statuses make a sub-tab, the columns (name, order, shown, drawn as a
/// label) and the labels' colours are the company's settings, which the
/// server sends with the rows. Money only with Financials: the server leaves
/// the keys out, and so do the columns.
class ProjectBoardScreen extends StatefulWidget {
  const ProjectBoardScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<ProjectBoardScreen> createState() => _ProjectBoardScreenState();
}

class _ProjectBoardScreenState extends State<ProjectBoardScreen>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _loaded = false;
  bool _fetching = false;
  String? _error;
  List<Map<String, dynamic>> _rows = const [];
  List<Map<String, dynamic>> _franchises = const [];
  List<Map<String, dynamic>> _columns = const [];
  Map<String, dynamic> _labels = const {};

  /// The company's suppliers and same-day pickup stores (Settings).
  List<Map<String, dynamic>> _suppliers = const [];
  Map<String, String> _scope = const {};
  Map<String, dynamic> _counts = const {};

  /// Total Paid, Remaining Balance and Amount over the whole tab (Jose,
  /// 2026-11-10); null without Financials.
  Map<String, dynamic>? _totals;

  /// The repair-team and contractor tags on offer (Workiz's), and whether
  /// this person may change a job.

  bool _canEditJobs = false;

  /// Status Time colours: yellow from [warn] days, red from [bad] (Settings).
  ({int warn, int bad}) _days = (warn: 3, bad: 7);

  /// The columns the server can sort by, and the sort in force: per person,
  /// on every device (Jose, 2026-11-10: "clicking the column orders it").
  Set<String> _sortable = const {};
  late final List<String> _savedSort =
      '${widget.session.me?.preferences['project_board.sort'] ?? ''}'.split(
        ':',
      );
  late String? _sortKey = _savedSort.first.isEmpty ? null : _savedSort.first;
  late bool _sortDesc = _savedSort.length > 1 && _savedSort[1] == 'desc';

  void _sortBy(String key) {
    setState(() {
      _sortDesc = _sortKey == key ? !_sortDesc : false;
      _sortKey = key;
      // Rows stay on screen (Jose, 2026-11-16: "the tab seems to reload
      // entirely"). Every row here: sort them in place. Paged: the same
      // pages come back in the new order, swapped in when they arrive.
      if (_rows.length >= _total) {
        final dir = _sortDesc ? -1 : 1;
        _rows = [..._rows]..sort((a, b) => _compare(a[key], b[key], dir));
      }
    });
    widget.session.setPreference(
      'project_board.sort',
      '$key:${_sortDesc ? 'desc' : 'asc'}',
    );
    // The server's order is the answer (Jose, 2026-11-10); the local sort
    // only fills the wait.
    _load(quiet: true);
  }

  /// Blanks last either way, numbers as numbers, text without case.
  static int _compare(Object? a, Object? b, int dir) {
    bool blank(Object? v) => v == null || (v is String && v.isEmpty);
    if (blank(a) || blank(b)) {
      return blank(a) == blank(b) ? 0 : (blank(a) ? 1 : -1);
    }
    if (a is num && b is num) return dir * a.compareTo(b);
    return dir * '$a'.toLowerCase().compareTo('$b'.toLowerCase());
  }

  String _tab = _tabs.first.$1;
  String? _franchise;
  final _search = TextEditingController();
  Timer? _debounce;

  /// Live refresh (Jose, 2026-11-10: "if I stay on this window, will I see
  /// changes?"): Workiz syncs every minute, so the board looks again every
  /// minute while it is on screen, and whenever the window comes back.
  Timer? _live;
  DateTime? _updated;
  String _q = '';
  int _page = 1;
  int _lastPage = 1;
  int _total = 0;

  bool get _money => widget.session.me?.can('jobs.financials') ?? false;
  bool get _canSet => widget.session.me?.can('organizations.edit') ?? false;
  List<(String, String, IconData)> get _shown => [
    ..._tabs,
    if (_canSet) ('settings', 'Settings', Icons.tune),
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _live = Timer.periodic(const Duration(seconds: 60), (_) => _refresh());
    // A job saved anywhere (Workiz sync, webhook, automation) within ~10 s.
    _feed = LiveChanges.of(
      widget.api,
    ).subscribe({'jobs'}, (_) => _refresh(), active: LiveChanges.visible(this));
    _load();
  }

  LiveSubscription? _feed;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _refresh();
  }

  /// A quiet reload of what is on screen: the same rows and counts, no
  /// spinner, so the scroll stays where it is. Skipped while the screen is
  /// hidden (another module, or a sub-tab off stage) or Settings is open.
  void _refresh() {
    if (!mounted || _fetching || _tab == 'settings') return;
    if (!TickerMode.valuesOf(context).enabled) return;
    if (WidgetsBinding.instance.lifecycleState case final s?
        when s != AppLifecycleState.resumed) {
      return;
    }
    _load(quiet: true);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _live?.cancel();
    _feed?.cancel();
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  /// The search stays as it is across sub-tabs and franchises.
  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() => _q = text.trim());
      _load();
    });
  }

  Future<void> _load({int page = 1, bool quiet = false}) async {
    // Quiet: every page already shown, in one call of the same rows.
    final shownPages = quiet ? _page.clamp(1, 4) : page;
    setState(() {
      if (!quiet) _loading = true;
      _fetching = true;
    });
    final tab = _tab == 'settings' ? _tabs.first.$1 : _tab;
    final franchise = _franchise;
    final q = _q;
    final response = await widget.api.get('/project-board/jobs', {
      'tab': tab,
      'page': quiet ? '1' : '$page',
      if (quiet) 'per_page': '${50 * shownPages}',
      if (franchise != null && franchise.startsWith('company:'))
        'company_id': franchise.substring(8)
      else
        'franchise_id': ?franchise,
      if (q.isNotEmpty) 'q': q,
      'sort': ?_sortKey,
      if (_sortKey != null) 'dir': _sortDesc ? 'desc' : 'asc',
    });
    if (!mounted || franchise != _franchise || q != _q) return;
    setState(() {
      _loading = false;
      _fetching = false;
      _loaded = true;
      _error = response.ok ? null : response.message;
      if (!response.ok) return;
      final rows = _maps(response.body['data']);
      _rows = page == 1 || quiet ? rows : [..._rows, ...rows];
      final meta = (response.body['meta'] as Map?) ?? const {};
      _total = meta['total'] as int? ?? _rows.length;
      _lastPage = quiet
          ? (_total / 50).ceil().clamp(1, 1 << 30)
          : meta['last_page'] as int? ?? 1;
      _updated = DateTime.now();
      _counts = (meta['counts'] as Map?)?.cast<String, dynamic>() ?? _counts;
      _totals = (meta['totals'] as Map?)?.cast<String, dynamic>();
      _franchises = _maps(meta['places']);
      _columns = _maps(meta['columns']);
      _labels = (meta['labels'] as Map?)?.cast<String, dynamic>() ?? const {};
      _suppliers = _maps(meta['suppliers']);
      _canEditJobs = meta['can_edit'] == true;
      final days = (meta['status_days'] as Map?) ?? const {};
      _days = (
        warn: (days['warn'] as num?)?.toInt() ?? 3,
        bad: (days['bad'] as num?)?.toInt() ?? 7,
      );
      _sortable = {
        for (final k in (meta['sortable'] as List?) ?? const []) '$k',
      };

      _scope = ((meta['settings_scope'] as Map?) ?? const {}).map(
        (k, v) => MapEntry('$k', '$v'),
      );
      _page = quiet ? shownPages : page;
    });
  }

  /// Widths by column KEY, so a width dragged on one sub-tab lands on the
  /// same column on every other (Jose, 2026-11-16); the Action column
  /// shifted every position. Old position-keyed saves still read on
  /// Unscheduled, where they were made.
  Map<int, double> _widths(List<String> keys) {
    final saved = widget.session.me?.preferences['lists.column_widths'];
    final byKey = saved is Map ? saved : const {};
    final legacy = _tab == 'unscheduled'
        ? widget.session.me?.listColumnWidths('project-board') ?? const {}
        : const <int, double>{};
    final out = <int, double>{};
    for (var i = 0; i < keys.length; i++) {
      final w = byKey['project-board-col:${keys[i]}'];
      final width = w is num ? w.toDouble() : legacy[i];
      if (width != null) out[i] = width;
    }
    return out;
  }

  void _resize(String key, double width) {
    final all = {
      if (widget.session.me?.preferences['lists.column_widths']
          case final Map saved)
        ...saved,
      'project-board-col:$key': width.roundToDouble(),
    };
    widget.session.setPreference('lists.column_widths', all);
    setState(() {});
  }

  /// The job's parts, each "Need to order" (item, quantity, price,
  /// delivery date) or "In stock" (item, quantity, price), in a side panel.
  Future<void> _editParts(Map<String, dynamic> row) async {
    final parts = [
      for (final p in (row['parts'] as List?) ?? const [])
        if (p is Map) Map<String, dynamic>.from(p),
    ];
    final saved = await showSidePanel<List<Map<String, dynamic>>>(
      context,
      builder: (panel) => _PartsPanel(
        customer: '${row['customer'] ?? ''}',
        parts: parts,
        suppliers: _suppliers,
        formats: widget.session.me?.display ?? DisplayFormats.fallback,
      ),
    );
    if (saved == null || !mounted) return;
    final r = await widget.api.put('/project-board/jobs/${row['id']}/parts', {
      'parts': saved,
    });
    if (!mounted) return;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return;
    }
    _load(quiet: true);
  }

  /// Settings scrolls as a page; a board is a column whose table takes
  /// the rest of the window. Short windows keep the page scroll.
  Widget _page_(BuildContext context, List<Widget> kids) {
    const pad = EdgeInsets.fromLTRB(16, 16, 16, 16);
    if (_tab == 'settings' || !_fills(context)) {
      return ListView(padding: pad, children: kids);
    }
    return Padding(
      padding: pad,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: kids,
      ),
    );
  }

  bool _fills(BuildContext context) => MediaQuery.sizeOf(context).height >= 600;

  Widget _fill(Widget Function(double height) table) => _fills(context)
      ? Expanded(
          child: LayoutBuilder(builder: (context, box) => table(box.maxHeight)),
        )
      : table(MediaQuery.sizeOf(context).height - 120);

  /// Always the same height, so the table's bottom edge (and its
  /// sideways bar) does not move when the last page arrives.
  Widget _more() => SizedBox(
    height: 44,
    child: Center(
      child: _page < _lastPage
          ? TextButton(
              key: const Key('project-board-more'),
              onPressed: _fetching ? null : () => _load(page: _page + 1),
              child: Text(
                _fetching
                    ? 'Loading…'
                    : 'Load more (${_total - _rows.length} left)',
              ),
            )
          : Text(
              'All $_total jobs shown',
              style: Theme.of(context).textTheme.bodySmall,
            ),
    ),
  );

  /// Three tinted tiles, a fourth for Overpaid when there is any (design: docs/previews/pm-totals-strip.html, option 1,
  /// Jose 2026-11-12). Remaining is caution, not red: an open balance is
  /// normal. Under 600 px they stack as label-left, figure-right rows.
  Widget _totalsStrip(Map<String, dynamic> t) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final currency = '${t['currency'] ?? 'USD'}';
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: LayoutBuilder(
        builder: (context, box) {
          final narrow = box.maxWidth < 600;
          Widget tile(String key, String label, Color fill, Color ink) {
            final value = Text(
              formatMoney((t[key] as num?)?.toInt() ?? 0, currency: currency),
              key: Key('project-board-total-$key'),
              style:
                  (narrow
                          ? theme.textTheme.titleMedium
                          : theme.textTheme.headlineSmall)
                      ?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: ink,
                        fontFeatures: const [FontFeature.tabularFigures()],
                      ),
            );
            final caption = Text(
              label,
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w600,
                color: ink,
              ),
            );
            return Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: fill,
                borderRadius: BorderRadius.circular(10),
              ),
              child: narrow
                  ? Row(
                      children: [
                        Expanded(child: caption),
                        value,
                      ],
                    )
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [caption, value],
                    ),
            );
          }

          final ok = ToneColours.of(context, Tone.ok);
          final due = ToneColours.of(context, Tone.caution);
          final neutral = ToneColours.of(context, Tone.neutral);
          final tiles = [
            tile('paid', 'Total Paid', ok.fill, ok.ink),
            tile('due', 'Total Remaining Balance', due.fill, due.ink),
            tile(
              'total',
              'Total Amount of Jobs',
              scheme.primaryContainer,
              scheme.onPrimaryContainer,
            ),
            // Paid - Overpaid + Remaining = Total (Jobs 208341af3). Not a
            // problem, so neutral, never red.
            if (((t['over'] as num?) ?? 0) > 0)
              Tooltip(
                message: 'Paid beyond the job totals',
                child: tile('over', 'Overpaid', neutral.fill, neutral.ink),
              ),
          ];
          if (narrow) {
            return Column(
              children: [
                for (final (i, w) in tiles.indexed)
                  Padding(
                    padding: EdgeInsets.only(top: i == 0 ? 0 : 8),
                    child: w,
                  ),
              ],
            );
          }
          return Row(
            children: [
              for (final (i, w) in tiles.indexed) ...[
                if (i > 0) const SizedBox(width: 10),
                Expanded(child: w),
              ],
            ],
          );
        },
      ),
    );
  }

  void _switch(int i) {
    setState(() {
      _tab = _shown[i].$1;
      if (_tab != 'settings') _rows = const [];
    });
    if (_tab != 'settings') _load();
  }

  void _pick(String? franchise) {
    setState(() {
      _franchise = franchise;
      _rows = const [];
    });
    _load();
  }

  void _open(Map<String, dynamic> row, {String tab = 'job'}) =>
      showSidePanel<bool>(
        context,
        builder: (_) => JobDetailPanel(
          api: widget.api,
          session: widget.session,
          id: '${row['id']}',
          initialTab: tab,
        ),
      ).then((_) => _load());

  /// A heading that drags onto another to take its place, with Move left
  /// and Move right beside it; one order for everyone on the board (Jose,
  /// 2026-11-17: "Just let me drag them", like the Task Board).
  Widget _dragHeading(String key, Widget heading) {
    final visible = [
      for (final c in _columns)
        if (c['visible'] != false) '${c['key']}',
    ];
    final at = visible.indexOf(key);
    return Row(
      children: [
        Expanded(
          child: DragTarget<String>(
            onWillAcceptWithDetails: (d) => d.data != key,
            onAcceptWithDetails: (d) => _placeColumn(d.data, key),
            builder: (context, over, _) => Draggable<String>(
              key: ValueKey('project-board-column-drag-$key'),
              data: key,
              axis: Axis.horizontal,
              feedback: Material(
                elevation: 4,
                child: Opacity(
                  opacity: 0.85,
                  child: SizedBox(width: 160, height: 40, child: heading),
                ),
              ),
              childWhenDragging: Opacity(opacity: 0.4, child: heading),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  border: over.isEmpty
                      ? null
                      : Border(
                          left: BorderSide(
                            color: Theme.of(context).colorScheme.primary,
                            width: 3,
                          ),
                        ),
                ),
                child: heading,
              ),
            ),
          ),
        ),
        MenuAnchor(
          menuChildren: [
            MenuItemButton(
              key: ValueKey('project-board-column-left-$key'),
              leadingIcon: const Icon(Icons.chevron_left, size: 18),
              onPressed: at > 0
                  ? () => _placeColumn(key, visible[at - 1])
                  : null,
              child: const Text('Move left'),
            ),
            MenuItemButton(
              key: ValueKey('project-board-column-right-$key'),
              leadingIcon: const Icon(Icons.chevron_right, size: 18),
              onPressed: at >= 0 && at < visible.length - 1
                  ? () => _placeColumn(
                      key,
                      at + 2 < visible.length ? visible[at + 2] : null,
                    )
                  : null,
              child: const Text('Move right'),
            ),
          ],
          builder: (context, menu, _) => InkWell(
            key: ValueKey('project-board-column-menu-$key'),
            onTap: () => menu.isOpen ? menu.close() : menu.open(),
            child: Icon(
              Icons.more_vert,
              size: 16,
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ),
      ],
    );
  }

  /// Puts column [key] just before [before] (null: last), for everyone.
  Future<void> _placeColumn(String key, String? before) async {
    final was = _columns;
    final moving = was.firstWhere((c) => c['key'] == key);
    final rest = [
      for (final c in was)
        if (c['key'] != key) c,
    ];
    final at = before == null
        ? -1
        : rest.indexWhere((c) => c['key'] == before);
    at < 0 ? rest.add(moving) : rest.insert(at, moving);
    setState(() => _columns = rest);
    final r = await widget.api.put('/project-board/column-order', {
      'order': [for (final c in rest) '${c['key']}'],
    });
    if (!mounted || r.ok) return;
    setState(() => _columns = was);
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(r.reason)));
  }

  /// Downloads the job's work order (Jose, 2026-11-17).
  Future<void> _workOrder(Map<String, dynamic> row) async {
    final r = await widget.api.getBytesResponse(
      '/project-board/jobs/${row['id']}/work-order',
    );
    if (!mounted) return;
    final bytes = r.bytes;
    if (!r.ok || bytes == null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(
        SnackBar(content: Text(r.message ?? 'The work order could not be made.')),
      );
      return;
    }
    Files.download('Work order ${row['number'] ?? row['id']}.pdf', bytes);
  }

  /// The job's notes alone, in a side panel of their own.
  void _openNotes(Map<String, dynamic> row) => showSidePanel<void>(
    context,
    builder: (_) => _NotesPanel(
      api: widget.api,
      jobId: '${row['id']}',
      title: [row['customer'], row['number']].where((s) => s != null).join(' · '),
      formats: widget.session.me?.display ?? DisplayFormats.fallback,
      addRefusal: widget.session.me?.refusal('jobs.edit', 'add a note'),
    ),
  ).then((_) => _load(quiet: true));

  String _date(Object? ymd) {
    final at = ymd is String ? DateTime.tryParse(ymd) : null;
    return at == null
        ? '—'
        : (widget.session.me?.display ?? DisplayFormats.fallback).formatDate(
            at,
          );
  }

  /// Shoval's Unscheduled columns (Jose, 2026-11-10).
  Widget? _unscheduledCell(
    Map<String, dynamic> row,
    String key,
    Object? value,
  ) {
    final theme = Theme.of(context);
    switch (key) {
      case 'part_status':
        // The job's parts (Jose, 2026-11-10), else its part tags.
        final parts = [
          for (final p in (row['parts'] as List?) ?? const [])
            if (p is Map) p,
        ];
        if (parts.isEmpty && !_canEditJobs) return null;
        // Jose, 2026-11-17: in stock is green and done, ordered red, a
        // same-day pickup yellow; three days ordered and not received is
        // late, red like a lead.
        bool open(Map p) => p['kind'] != 'stock' && p['received_at'] == null;
        final order = parts.where((p) => p['kind'] == 'order' && open(p));
        final pickup = parts.where((p) => p['kind'] == 'pickup' && open(p));
        final late = parts.where((p) => p['late'] == true).length;
        final ready = parts.length - order.length - pickup.length;
        final eta = [
          for (final p in order)
            ?DateTime.tryParse('${p['delivery_on'] ?? ''}'),
        ]..sort();
        final formats = widget.session.me?.display ?? DisplayFormats.fallback;
        final summary = [
          if (late > 0) '$late late',
          if (order.isNotEmpty) '${order.length} ordered',
          if (eta.isNotEmpty) 'ETA ${formats.formatDate(eta.last)}',
          if (pickup.isNotEmpty) '${pickup.length} to pick up',
          if (ready > 0) '$ready ready',
        ].join(' · ');
        final tone = late > 0 || order.isNotEmpty
            ? Tone.bad
            : pickup.isNotEmpty
            ? Tone.caution
            : Tone.ok;
        return InkWell(
          key: ValueKey('project-board-parts:${row['id']}'),
          onTap: _canEditJobs ? () => _editParts(row) : null,
          child: Align(
            alignment: Alignment.centerLeft,
            child: parts.isEmpty
                ? (value == null
                      ? const Text('Add parts…')
                      : StatusPill('$value', tone: Tone.caution))
                : StatusPill(summary, tone: tone),
          ),
        );
      case 'status_days':
        if (value is! int) return const Text('—');
        // Under 3 days green, 3 to 7 yellow, 7 and up red.
        return Align(
          alignment: Alignment.centerLeft,
          child: StatusPill(
            value == 1 ? '1 day' : '$value days',
            tone: value < _days.warn
                ? Tone.ok
                : value < _days.bad
                ? Tone.caution
                : Tone.bad,
          ),
        );
      case 'payment_date':
        final at = value is String
            ? DateTime.tryParse(value.replaceFirst(' ', 'T'))
            : null;
        if (at == null) return const Text('—');
        final formats = widget.session.me?.display ?? DisplayFormats.fallback;
        return Text(
          '${formats.formatDate(at)} at ${TimeOfDay.fromDateTime(at).format(context)}',
        );
      case 'repair_team':
        // The job's Workiz repair-team / contractor tag (Jose, 2026-11-10).
        final current = value as String?;
        // Only that job's own Workiz account's tags (Jose, 2026-11-10).
        final options = [
          for (final t in (row['repair_options'] as List?) ?? const [])
            if (t is Map) ('${t['name']}', ColourField.parse('${t['colour']}')),
        ];
        if (!_canEditJobs || options.isEmpty) {
          return current == null
              ? const Text('—')
              : Align(
                  alignment: Alignment.centerLeft,
                  child: Tag(current, colour: jobTagColours(row)[current]),
                );
        }
        return MenuAnchor(
          menuChildren: [
            if (current != null)
              MenuItemButton(
                key: ValueKey('project-board-repair:${row['id']}:none'),
                leadingIcon: const Icon(Icons.close, size: 18),
                onPressed: () => _setRepairTeam(row, null),
                child: const Text('Unassign'),
              ),
            for (final (t, colour) in options)
              MenuItemButton(
                key: ValueKey('project-board-repair:${row['id']}:$t'),
                onPressed: () => _setRepairTeam(row, t),
                child: Tag(t, colour: colour),
              ),
          ],
          builder: (context, menu, _) => TextButton(
            key: ValueKey('project-board-repair:${row['id']}'),
            onPressed: () => menu.isOpen ? menu.close() : menu.open(),
            // The one tag, as its coloured chip on one line.
            child: current == null
                ? const Text('Assign…')
                : Tag(current, colour: jobTagColours(row)[current]),
          ),
        );
      case 'technician':
        final name = value as String?;
        if (!_canEditJobs) return Text(name ?? '—', softWrap: true);
        return Align(
          alignment: Alignment.centerLeft,
          child: TextButton(
            key: ValueKey('project-board-technician:${row['id']}'),
            onPressed: () async {
              if (await pickJobTechnician(
                context,
                widget.api,
                '${row['id']}',
                row['performed_by_user_id'] as String?,
              )) {
                _load(quiet: true);
              }
            },
            child: Text(name ?? 'Assign…', softWrap: true),
          ),
        );
      case 'attachments':
        final n = value is int ? value : 0;
        return Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: ValueKey('project-board-files:${row['id']}'),
            onPressed: () => _open(row, tab: 'files'),
            icon: const Icon(Icons.attach_file, size: 18),
            label: Text('$n'),
          ),
        );
      case 'work_order':
        // Jose, 2026-11-17: the job's work order for the crew, as a PDF.
        return Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: ValueKey('project-board-work-order:${row['id']}'),
            onPressed: () => _workOrder(row),
            icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
            label: const Text('PDF'),
          ),
        );
      case 'notes':
        // Jose, 2026-11-17: "Clicking Notes should open only the notes
        // sidebar, nothing else."
        final text = value as String?;
        return InkWell(
          key: ValueKey('project-board-notes:${row['id']}'),
          onTap: () => _openNotes(row),
          child: Align(
            alignment: Alignment.centerLeft,
            child: text == null
                ? Text(
                    _canEditJobs ? 'Add a note…' : '—',
                    style: theme.textTheme.bodySmall,
                  )
                : Tooltip(
                    message: text,
                    child: Text(
                      text,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
          ),
        );
      case 'required':
        // Line by line, a small gap between items so a long one reads as
        // one (Jose, 2026-11-10).
        if (value == null) return const Text('—');
        // Two items, then "+N more" with all of them on hover, so one job
        // does not make its row five times taller (Jose, 2026-11-16).
        final items = '$value'.split(_newline);
        return Tooltip(
          message: items.length > 2 ? items.join(String.fromCharCode(10)) : '',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final (i, item) in items.take(2).indexed)
                Padding(
                  padding: EdgeInsets.only(top: i == 0 ? 0 : 5),
                  child: Text(
                    item,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              if (items.length > 2)
                Text(
                  '+${items.length - 2} more',
                  key: ValueKey('project-board-required-more:${row['id']}'),
                  style: TextStyle(
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
            ],
          ),
        );
    }
    return null;
  }

  /// The job's repair team, here and in Workiz: a pick replaces the old
  /// one, null unassigns (Jose, 2026-11-10).
  Future<void> _setRepairTeam(Map<String, dynamic> row, String? team) async {
    final r = await widget.api.patch(
      '/project-board/jobs/${row['id']}/repair-team',
      {'repair_team': team},
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          !r.ok
              ? r.reason
              : team == null
              ? 'Unassigned.'
              : '$team assigned.',
        ),
      ),
    );
    if (r.ok) _load(quiet: true);
  }

  /// "Ready to schedule": adds READY TO SCHEDULE in Workiz; only once
  /// every column it needs is filled.
  Widget _readyButton(Map<String, dynamic> row) {
    final missing = [
      for (final m in (row['ready_missing'] as List?) ?? const []) '$m',
    ];
    final button = FilledButton(
      key: ValueKey('project-board-ready:${row['id']}'),
      // Roomier, and centred in its cell (Jose, 2026-11-10).
      style: FilledButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
      onPressed: missing.isEmpty && _canEditJobs ? () => _ready(row) : null,
      child: const Text('Ready to schedule'),
    );
    return Center(
      child: missing.isEmpty
          ? button
          : Tooltip(
              message: 'Still needs ${missing.join(', ')}',
              child: button,
            ),
    );
  }

  Future<void> _ready(Map<String, dynamic> row) async {
    final times = await _askTimes(row, title: 'Ready to schedule');
    if (times == null || !mounted) return;
    final r = await widget.api.post(
      '/project-board/jobs/${row['id']}/ready',
      times,
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(r.ok ? 'Moved to Ready to Schedule.' : r.reason)),
    );
    if (r.ok) _load(quiet: true);
  }

  /// Date, time slot, alternative date, alternative time slot, in a side
  /// panel (Jose, 2026-11-10). Pops the four, or null when cancelled.
  Future<Map<String, String>?> _askTimes(
    Map<String, dynamic> row, {
    required String title,
  }) {
    final had = (row['schedule_proposal'] as Map?) ?? const {};
    final fields = {
      for (final k in const ['date', 'slot', 'alt_date', 'alt_slot'])
        k: TextEditingController(text: '${had[k] ?? ''}'),
    };
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    return showSidePanel<Map<String, String>>(
      context,
      builder: (panel) => StatefulBuilder(
        builder: (panel, set) {
          final done = fields.values.every((c) => c.text.trim().isNotEmpty);
          Widget slot(String key, String label) => Autocomplete<String>(
            initialValue: TextEditingValue(text: fields[key]!.text),
            optionsBuilder: (v) => _slots.where(
              (s) => s.toLowerCase().contains(v.text.toLowerCase()),
            ),
            onSelected: (v) => set(() => fields[key]!.text = v),
            fieldViewBuilder: (context, c, focus, submit) => TextField(
              key: Key('project-board-times-$key'),
              controller: c,
              focusNode: focus,
              onChanged: (v) => set(() => fields[key]!.text = v),
              decoration: InputDecoration(
                labelText: label,
                hintText: 'e.g. 8 - 11 AM',
                border: const OutlineInputBorder(),
              ),
            ),
          );
          Widget day(String key, String label) => Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: Theme.of(panel).textTheme.labelLarge),
              const SizedBox(height: 4),
              CalendarDayField(
                key: Key('project-board-times-$key'),
                controller: fields[key]!,
                formats: formats,
                dense: true,
                onChanged: (_) => set(() {}),
              ),
            ],
          );
          return SidePanel(
            title: title,
            subtitle: '${row['customer'] ?? ''}',
            actions: [
              TextButton(
                onPressed: () => Navigator.of(panel).pop(),
                child: const Text('Cancel'),
              ),
              FilledButton(
                key: const Key('project-board-times-save'),
                onPressed: done
                    ? () => Navigator.of(panel).pop({
                        for (final e in fields.entries)
                          e.key: e.value.text.trim(),
                      })
                    : null,
                child: const Text('Save'),
              ),
            ],
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                day('date', 'Date'),
                const SizedBox(height: 12),
                slot('slot', 'Time slot'),
                const SizedBox(height: 20),
                day('alt_date', 'Alternative date'),
                const SizedBox(height: 12),
                slot('alt_slot', 'Alternative time slot'),
              ],
            ),
          );
        },
      ),
    );
  }

  /// The Schedule column on Ready to Schedule: the offered times; a click
  /// shows and changes them.
  Widget _proposalCell(Map<String, dynamic> row) {
    final p = (row['schedule_proposal'] as Map?) ?? const {};
    final formats = widget.session.me?.display ?? DisplayFormats.fallback;
    String day(Object? v) {
      final at = DateTime.tryParse('${v ?? ''}');
      return at == null ? '—' : formats.formatDate(at);
    }

    return InkWell(
      key: ValueKey('project-board-proposal:${row['id']}'),
      onTap: _canEditJobs
          ? () async {
              final times = await _askTimes(row, title: 'Schedule');
              if (times == null || !mounted) return;
              final r = await widget.api.patch(
                '/project-board/jobs/${row['id']}/proposal',
                times,
              );
              if (!mounted) return;
              if (!r.ok) {
                ScaffoldMessenger.of(
                  context,
                ).showSnackBar(SnackBar(content: Text(r.reason)));
                return;
              }
              _load(quiet: true);
            }
          : null,
      child: p.isEmpty
          ? const Text('Set times…')
          : Text(
              '${day(p['date'])} · ${p['slot']}\nor ${day(p['alt_date'])} · ${p['alt_slot']}',
            ),
    );
  }

  Widget _cell(Map<String, dynamic> row, Map<String, dynamic> column) {
    final key = '${column['key']}';
    final value = row[key];
    if (_unscheduledCell(row, key, value) case final special?) return special;
    if (_moneyKeys.contains(key)) {
      return Text(
        value is int
            ? formatMoney(value, currency: '${row['currency'] ?? 'USD'}')
            : '—',
        // Digits line up while centred (Jose, 2026-11-12).
        style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()]),
      );
    }
    if (value is List) {
      // Tags: one chip each, in its Workiz colour where known (Jose,
      // 2026-11-10), else coloured like any label column.
      if (value.isEmpty) return const Text('—');
      final workiz = key == 'tags'
          ? jobTagColours(row)
          : const <String, Color>{};
      if (workiz.isNotEmpty) {
        return Wrap(
          spacing: 4,
          runSpacing: 4,
          children: [for (final t in value) Tag('$t', colour: workiz['$t'])],
        );
      }
      final named = (_labels[key] as Map?) ?? const {};
      return Wrap(
        spacing: 4,
        runSpacing: 4,
        children: [
          for (final t in value)
            StatusPill(
              '$t',
              tone:
                  Tone.values
                      .where(
                        (tone) =>
                            tone.name ==
                            '${named.entries.where((e) => '${e.key}'.toLowerCase() == '$t'.toLowerCase()).firstOrNull?.value}',
                      )
                      .firstOrNull ??
                  Tone.neutral,
            ),
        ],
      );
    }
    final text = key == 'created' || key == 'scheduled'
        ? _date(value)
        : (value == null || '$value'.isEmpty ? '—' : '$value');
    if (column['as_label'] == true && text != '—') {
      final named = ((_labels[key] as Map?) ?? const {}).entries
          .where((e) => '${e.key}'.toLowerCase() == text.toLowerCase())
          .firstOrNull
          ?.value;
      final tone =
          Tone.values.where((t) => t.name == '$named').firstOrNull ??
          (key == 'status' ? toneForJobStatus(text) : Tone.neutral);
      return Align(
        alignment: Alignment.centerLeft,
        child: StatusPill(text, tone: tone),
      );
    }
    return Text(
      text,
      style: key == 'customer'
          ? Theme.of(
              context,
            ).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w700)
          : null,
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = _rows;
    final shown = _shown;
    final selected = shown.indexWhere((t) => t.$1 == _tab);
    final columns = [
      for (final c in _columns)
        if (c['visible'] != false && (_money || !_moneyKeys.contains(c['key'])))
          c,
    ];
    // A first Action column where the sub-tab has an action (Jose,
    // 2026-11-16); every sub-tab is otherwise the same table.
    final Widget Function(Map<String, dynamic>)? action = switch (_tab) {
      'unscheduled' => _readyButton,
      'ready_to_schedule' => _proposalCell,
      _ => null,
    };
    final keys = [
      if (action != null) '_action',
      for (final c in columns) '${c['key']}',
    ];

    // The sub-tabs stay drawn while a sub-tab loads or fails.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: TabBarLike(
            key: const Key('project-board-tabs'),
            labels: [
              for (final (key, name, icon) in shown)
                (_counts[key] is int ? '$name · ${_counts[key]}' : name, icon),
            ],
            selected: selected,
            onSelected: _switch,
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading && !_loaded,
            error: _error,
            onRetry: _load,
            child: _page_(context, [
              if (_tab == 'settings')
                _BoardSettings(
                  api: widget.api,
                  scope: _scope,
                  columns: _columns,
                  labels: _labels,
                  onSaved: _load,
                )
              else ...[
                // The search takes what is left of the row (Jose,
                // 2026-11-10); on a narrow window it wraps below.
                LayoutBuilder(
                  builder: (context, box) {
                    final search = ListSearch(
                      key: const Key('project-board-search'),
                      controller: _search,
                      hint:
                          'Customer, job number, phone, address or technician',
                      width: box.maxWidth >= 900 ? double.infinity : 280,
                      onChanged: _searched,
                    );
                    final items = <Widget>[
                      Text(
                        '${shown[selected].$2} · $_total jobs',
                        style: theme.textTheme.titleMedium,
                      ),
                      if (_updated case final at?)
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              'Updated ${TimeOfDay.fromDateTime(at).format(context)}',
                              key: const Key('project-board-updated'),
                              style: theme.textTheme.bodySmall,
                            ),
                            IconButton(
                              key: const Key('project-board-refresh'),
                              tooltip: 'Refresh now',
                              icon: const Icon(Icons.refresh, size: 18),
                              onPressed: _fetching
                                  ? null
                                  : () => _load(quiet: true),
                            ),
                          ],
                        ),
                      DropdownMenu<String>(
                        enableFilter: true,
                        requestFocusOnTap: true,
                        key: const Key('project-board-franchise'),
                        // The same size as the search beside it, and a
                        // list that scrolls past the screen's height
                        // (Jose, 2026-11-10).
                        width: 280,
                        menuHeight: 420,
                        hintText: 'Franchise',
                        leadingIcon: const Icon(Icons.store_outlined, size: 18),
                        inputDecorationTheme: InputDecorationTheme(
                          isDense: true,
                          constraints: BoxConstraints.tightFor(
                            height: ListSearch.height,
                          ),
                          contentPadding: const EdgeInsets.symmetric(
                            horizontal: 12,
                          ),
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(8),
                          ),
                        ),
                        initialSelection: _franchise ?? '',
                        onSelected: (v) =>
                            _pick(v == null || v.isEmpty ? null : v),
                        dropdownMenuEntries: [
                          const DropdownMenuEntry(value: '', label: 'All'),
                          // Hierarchy order from the server, indented by level.
                          for (final f in _franchises)
                            DropdownMenuEntry(
                              value: '${f['value']}',
                              label: '${f['label']}',
                              labelWidget: Padding(
                                padding: EdgeInsets.only(
                                  left: 16.0 * ((f['depth'] as int?) ?? 0),
                                ),
                                child: Text('${f['label']}'),
                              ),
                            ),
                        ],
                      ),
                    ];
                    if (box.maxWidth < 900) {
                      return Wrap(
                        spacing: 16,
                        runSpacing: 8,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [...items, search],
                      );
                    }
                    return Row(
                      children: [
                        for (final w in items) ...[
                          w,
                          const SizedBox(width: 16),
                        ],
                        Expanded(child: search),
                      ],
                    );
                  },
                ),
                const SizedBox(height: 12),
                if (rows.isEmpty && _fetching)
                  const LinearProgressIndicator()
                else if (rows.isEmpty)
                  Text(
                    _q.isNotEmpty
                        ? 'No job matches "$_q".'
                        : 'No jobs in ${shown[selected].$2}.',
                  )
                else ...[
                  // A thin bar while new rows are on their way; the old
                  // ones stay put (Jose, 2026-11-16).
                  SizedBox(
                    height: 2,
                    child: _fetching
                        ? const LinearProgressIndicator(
                            key: Key('project-board-refreshing'),
                          )
                        : null,
                  ),
                  if (_totals case final t?) _totalsStrip(t),
                  _fill(
                    (height) => ListTable(
                      key: ValueKey('project-board-table:$_tab'),
                      // Fills the window down to its bottom edge: rows scroll
                      // inside, so the headings and the sideways bar never
                      // move, also as more rows load (Jose, 2026-11-16).
                      height: height,
                      footer: _more(),
                      // Dragged widths, per person on every device (Jose,
                      // 2026-11-10), by column key.
                      widths: _widths(keys),
                      onResize: (i, w) => _resize(keys[i], w),
                      rowKey: (i) =>
                          ValueKey('project-board-job:${rows[i]['id']}'),
                      onTap: (i) => _open(rows[i]),
                      columns: [
                        // First, so the button is on screen before any
                        // sideways scroll (Jose, 2026-11-16: "Ready to sch").
                        if (action != null) const ListColumn('Action', flex: 4),
                        for (final c in columns)
                          ListColumn(
                            '${c['label'] ?? c['key']}',
                            flex: c['key'] == 'required' || c['key'] == 'tags'
                                ? 4
                                : 2,
                            center: _moneyKeys.contains(c['key']),
                            onSort: _sortable.contains(c['key'])
                                ? () => _sortBy('${c['key']}')
                                : null,
                            sorted: _sortKey == c['key'] ? _sortDesc : null,
                            // Jose, 2026-11-17: "Just let me drag them".
                            frame: _canEditJobs
                                ? (h) => _dragHeading('${c['key']}', h)
                                : null,
                          ),
                      ],
                      rows: [
                        for (final row in rows)
                          [
                            if (action != null) action(row),
                            for (final c in columns) _cell(row, c),
                          ],
                      ],
                    ),
                  ),
                ],
              ],
            ]),
          ),
        ),
      ],
    );
  }
}

/// The Settings sub-tab: the company's columns, label colours, which job
/// statuses make each sub-tab, and the tags that are a part status. Saved
/// as the settings they are (project_board.*), so MCP and Settings read the
/// same values.
class _BoardSettings extends StatefulWidget {
  const _BoardSettings({
    required this.api,
    required this.scope,
    required this.columns,
    required this.labels,
    required this.onSaved,
  });

  final ApiClient api;
  final Map<String, String> scope;
  final List<Map<String, dynamic>> columns;
  final Map<String, dynamic> labels;
  final VoidCallback onSaved;

  @override
  State<_BoardSettings> createState() => _BoardSettingsState();
}

class _BoardSettingsState extends State<_BoardSettings> {
  late final List<Map<String, dynamic>> _columns = [
    for (final c in widget.columns) {...c},
  ];
  late final Map<String, List<(TextEditingController, String)>> _labels = {
    for (final MapEntry(:key, :value) in widget.labels.entries)
      key: [
        for (final MapEntry(key: v, value: tone) in (value as Map).entries)
          (TextEditingController(text: '$v'), '$tone'),
      ],
  };
  final Map<String, List<String>> _statuses = {};
  final Map<String, bool?> _scheduled = {};
  final Map<String, List<String>> _tabTags = {};
  final Map<String, bool> _anyTag = {};
  List<String> _partTags = [];

  /// Suppliers and same-day pickup stores (Jose, 2026-11-17: "we need to set
  /// up list of supplier"): id, name, address, phone, kind.
  final List<_SupplierRow> _suppliers = [];
  final Map<String, List<String>> _tabNoTags = {};

  /// The company's real job statuses and tags, most used first.
  List<String> _knownStatuses = const [];
  List<String> _knownTags = const [];
  Map<String, Color> _tagColours = const {};

  /// Settings for the new columns (Jose, 2026-11-10).
  List<Map<String, dynamic>> _repairTeams = [];
  List<Map<String, dynamic>> _users = const [];
  List<String> _repairColours = const [];

  /// The team whose Delete is waiting for its confirmation, in the card.
  String? _deletingTeam;
  final Set<String> _readyNeeds = {};

  /// Roles that can be picked as Technician (Jose, 2026-11-16).
  List<String> _knownRoles = const [];
  List<String> _techRoles = [];
  final _warnDays = TextEditingController();
  final _badDays = TextEditingController();
  bool _ready = false;
  String? _saving;
  String? _error;

  @override
  void initState() {
    super.initState();
    _read();
  }

  @override
  void dispose() {
    for (final list in _labels.values) {
      for (final (c, _) in list) {
        c.dispose();
      }
    }
    _warnDays.dispose();
    _badDays.dispose();
    for (final r in _suppliers) {
      r.dispose();
    }
    super.dispose();
  }

  Future<void> _read() async {
    final (r, o, t) = await (
      widget.api.get('/settings', widget.scope),
      widget.api.get('/project-board/options'),
      widget.api.get('/project-board/repair-teams'),
    ).wait;
    if (!mounted) return;
    final byKey = {
      for (final s in (r.ok && r.data is List ? r.data as List : const []))
        '${(s as Map)['key']}': s['value'],
    };
    final tabs = (byKey['project_board.tabs'] as Map?) ?? const {};
    final options = o.ok && o.data is Map ? o.data as Map : const {};
    setState(() {
      _error = r.ok ? null : r.message;
      _knownStatuses = [
        for (final s in (options['statuses'] as List?) ?? const []) '$s',
      ];
      _knownTags = [
        for (final t in (options['tags'] as List?) ?? const []) '$t',
      ];
      // The teams already there, before the company's first change too
      // (Jose, 2026-11-16), in their Workiz colours.
      final teams = t.ok && t.data is Map ? t.data as Map : const {};
      _tagColours = {
        ...jobTagColours({'tag_colours': teams['tag_colours']}),
        ...jobTagColours({'tag_colours': options['tag_colours']}),
      };
      _repairTeams = [
        for (final t
            in (teams['list'] as List?) ??
                (byKey['project_board.repair_tags'] as List?) ??
                const [])
          t is Map ? Map<String, dynamic>.from(t) : {'name': '$t'},
      ];
      _users = [
        for (final u in (options['users'] as List?) ?? const [])
          if (u is Map) Map<String, dynamic>.from(u),
      ];
      _repairColours = [
        for (final c in (options['repair_colours'] as List?) ?? const []) '$c',
      ];
      _knownRoles = [
        for (final r in (options['roles'] as List?) ?? const []) '$r',
      ];
      _techRoles = [
        for (final r
            in (byKey['project_board.technician_roles'] as List?) ??
                const ['Technician', 'Local Manager'])
          '$r',
      ];
      _readyNeeds
        ..clear()
        ..addAll([
          for (final k
              in (byKey['project_board.ready_requires'] as List?) ??
                  const [
                    'service_area',
                    'technician',
                    'repair_team',
                    'attachment',
                    'note',
                  ])
            '$k',
        ]);
      final days = (byKey['project_board.status_days'] as Map?) ?? const {};
      _warnDays.text = '${days['warn'] ?? 3}';
      _badDays.text = '${days['bad'] ?? 7}';
      for (final (key, _, _) in _tabs) {
        final rule = (tabs[key] as Map?) ?? const {};
        _statuses[key] = [
          for (final s in (rule['statuses'] as List?) ?? const []) '$s',
        ];
        _scheduled[key] = rule['scheduled'] as bool?;
        _tabTags[key] = [
          for (final t in (rule['tags'] as List?) ?? const []) '$t',
        ];
        _anyTag[key] = rule['tags_mode'] == 'any';
        _tabNoTags[key] = [
          for (final t in (rule['tags_none'] as List?) ?? const []) '$t',
        ];
      }
      _partTags = [
        for (final t in (byKey['project_board.part_tags'] as List?) ?? const [])
          '$t',
      ];
      for (final r in _suppliers) {
        r.dispose();
      }
      _suppliers
        ..clear()
        ..addAll([
        for (final v in (byKey['project_board.suppliers'] as List?) ?? const [])
          if (v is Map) _SupplierRow(Map<String, dynamic>.from(v)),
      ]);
      // Job Status starts from the company's own statuses, not an empty list.
      final status = _labels['status'];
      if (status != null && status.isEmpty) {
        for (final s in _knownStatuses) {
          status.add((
            TextEditingController(text: s),
            toneForJobStatus(s).name,
          ));
        }
      }
      _ready = true;
    });
  }

  Future<void> _save(String card, Map<String, Object> values) async {
    setState(() => _saving = card);
    String? failed;
    for (final MapEntry(:key, :value) in values.entries) {
      final r = await widget.api.patch('/settings/$key', {
        'value': value,
      }, widget.scope);
      if (!r.ok) failed ??= r.message;
    }
    if (!mounted) return;
    setState(() => _saving = null);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(failed ?? 'Project Management settings saved.')),
    );
    if (failed == null) widget.onSaved();
  }

  Map<String, Object> _labelValues() => {
    'project_board.labels': {
      for (final MapEntry(:key, :value) in _labels.entries)
        key: {
          for (final (c, tone) in value)
            if (c.text.trim().isNotEmpty) c.text.trim(): tone,
        },
    },
  };

  /// Where parts are ordered from (red on the board) and the same-day pickup
  /// stores (yellow), offered in each part's Supplier box.
  Widget _suppliersCard() => _card(
    'suppliers',
    'Suppliers',
    'Where parts are ordered from, and the stores for a same-day pickup.',
    [
      for (final (i, r) in _suppliers.indexed)
        Padding(
          key: ValueKey('project-board-supplier-$i'),
          padding: const EdgeInsets.only(bottom: 10),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              for (final (c, label, w) in [
                (r.name, 'Name', 200.0),
                (r.address, 'Address', 260.0),
                (r.phone, 'Phone', 150.0),
              ])
                SizedBox(
                  width: w,
                  child: TextField(
                    controller: c,
                    decoration: InputDecoration(
                      isDense: true,
                      labelText: label,
                      border: const OutlineInputBorder(),
                    ),
                  ),
                ),
              SegmentedButton<String>(
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(value: 'order', label: Text('Order')),
                  ButtonSegment(value: 'pickup', label: Text('Same-day pickup')),
                ],
                selected: {r.kind},
                onSelectionChanged: (v) => setState(() => r.kind = v.first),
              ),
              IconButton(
                tooltip: 'Remove',
                icon: const Icon(Icons.close, size: 18),
                onPressed: () =>
                    setState(() => _suppliers.removeAt(i).dispose()),
              ),
            ],
          ),
        ),
      Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: const Key('project-board-supplier-add'),
          onPressed: () => setState(() => _suppliers.add(_SupplierRow(const {}))),
          icon: const Icon(Icons.add, size: 18),
          label: const Text('Add a supplier'),
        ),
      ),
    ],
    () => {
      'project_board.suppliers': [
        for (final r in _suppliers)
          if (r.name.text.trim().isNotEmpty) r.value,
      ],
    },
  );

  /// A titled card with its one-line explanation and its own Save.
  Widget _card(
    String id,
    String title,
    String explain,
    List<Widget> body,
    Map<String, Object> Function() values,
  ) {
    final theme = Theme.of(context);
    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: theme.textTheme.titleMedium),
            Text(explain, style: theme.textTheme.bodySmall),
            const SizedBox(height: 12),
            ...body,
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                key: Key('project-board-save:$id'),
                onPressed: _saving != null ? null : () => _save(id, values()),
                child: Text(_saving == id ? 'Saving…' : 'Save'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// The Repair Team / Contractor list (Jose, 2026-11-10): managed here,
  /// each a Workiz tag on every account, with a phone or a user. Each change
  /// is saved at once and shows what every Workiz account answered.
  Widget _repairCard() {
    final theme = Theme.of(context);
    String? userName(Object? id) =>
        _users.where((u) => u['id'] == id).firstOrNull?['name'] as String?;
    return Card(
      key: const Key('project-board-repair-teams'),
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Repair teams / contractors',
              style: theme.textTheme.titleMedium,
            ),
            Text(
              'Each is a job tag in every Workiz account: adding, renaming or deleting one here does it there too.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 12),
            for (final t in _repairTeams)
              ListTile(
                key: ValueKey('project-board-repair-team:${t['name']}'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Align(
                  alignment: Alignment.centerLeft,
                  child: Tag('${t['name']}', colour: _tagColours[t['name']]),
                ),
                subtitle: _deletingTeam == t['name']
                    ? Text(
                        'Delete ${t['name']} from every Workiz account?',
                        style: TextStyle(color: theme.colorScheme.error),
                      )
                    : Text(
                        [
                          if ('${t['phone'] ?? ''}'.isNotEmpty) '${t['phone']}',
                          ?userName(t['user_id']),
                        ].join(' · '),
                      ),
                trailing: Wrap(
                  spacing: 4,
                  children: _deletingTeam == t['name']
                      ? [
                          TextButton(
                            key: ValueKey(
                              'project-board-repair-team-keep:${t['name']}',
                            ),
                            onPressed: () =>
                                setState(() => _deletingTeam = null),
                            child: const Text('Keep'),
                          ),
                          FilledButton(
                            key: ValueKey(
                              'project-board-repair-team-confirm:${t['name']}',
                            ),
                            style: FilledButton.styleFrom(
                              backgroundColor: theme.colorScheme.error,
                              foregroundColor: theme.colorScheme.onError,
                            ),
                            onPressed: _saving != null
                                ? null
                                : () => _sendRepairTeam({
                                    'op': 'delete',
                                    'name': t['name'],
                                  }),
                            child: const Text('Delete'),
                          ),
                        ]
                      : [
                          TextButton(
                            key: ValueKey(
                              'project-board-repair-team-rename:${t['name']}',
                            ),
                            onPressed: _saving != null
                                ? null
                                : () => _editRepairTeam(t),
                            child: const Text('Rename'),
                          ),
                          TextButton(
                            key: ValueKey(
                              'project-board-repair-team-remove:${t['name']}',
                            ),
                            onPressed: _saving != null
                                ? null
                                : () => setState(
                                    () => _deletingTeam = '${t['name']}',
                                  ),
                            child: const Text('Delete'),
                          ),
                        ],
                ),
              ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const Key('project-board-repair-tags-add'),
                onPressed: _saving != null ? null : () => _editRepairTeam(null),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add a repair team / contractor'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _editRepairTeam(Map<String, dynamic>? team) async {
    final body = await showSidePanel<Map<String, Object?>>(
      context,
      builder: (_) =>
          _RepairTeamPanel(team: team, users: _users, colours: _repairColours),
    );
    if (body == null || !mounted) return;
    await _sendRepairTeam(body);
  }

  /// One add, rename or delete, here and on every Workiz account.
  Future<void> _sendRepairTeam(Map<String, Object?> body) async {
    setState(() => _saving = 'repair');
    final r = await widget.api.post('/project-board/repair-teams', body);
    if (!mounted) return;
    setState(() {
      _saving = null;
      _deletingTeam = null;
    });
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.message)));
      return;
    }
    final data = r.data as Map;
    final outcomes = (data['outcomes'] as Map?) ?? const {};
    setState(
      () => _repairTeams = [
        for (final t in (data['list'] as List?) ?? const [])
          Map<String, dynamic>.from(t as Map),
      ],
    );
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(
            outcomes.isEmpty
                ? 'Saved.'
                : [
                    for (final e in outcomes.entries) '${e.key}: ${e.value}',
                  ].join(_newline),
          ),
        ),
      );
  }

  /// The chosen tags as coloured chips, and "+ Add tag" opening a searchable
  /// list (Jose, 2026-11-10: the ~95 chips were a wall, four times over).
  Widget _tagPicker(String key, List<String> chosen) => Wrap(
    key: Key(key),
    spacing: 6,
    runSpacing: 6,
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      for (final t in chosen)
        InputChip(
          label: Text(
            t,
            style: _tagColours[t] == null
                ? null
                : TextStyle(color: inkOn(_tagColours[t]!)),
          ),
          backgroundColor: _tagColours[t],
          onDeleted: () => setState(() => chosen.remove(t)),
        ),
      ActionChip(
        key: Key('$key-add'),
        avatar: const Icon(Icons.add, size: 16),
        label: const Text('Add tag'),
        onPressed: () async {
          final picked = await showSearchPicker<String>(
            context,
            title: 'Add a tag',
            options: [
              for (final t in _knownTags)
                if (!chosen.contains(t))
                  SearchOption(
                    value: t,
                    label: t,
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: Tag(t, colour: _tagColours[t]),
                    ),
                  ),
            ],
          );
          if (picked != null && mounted) setState(() => chosen.add(picked));
        },
      ),
    ],
  );

  /// Every known value as a chip, the chosen ones ticked; a chosen value the
  /// company no longer uses stays listed so it can be unticked.
  Widget _chips(String key, List<String> known, List<String> chosen) {
    bool same(String a, String b) => a.toLowerCase() == b.toLowerCase();
    return Wrap(
      key: Key(key),
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final v in [
          ...known,
          ...chosen.where((c) => !known.any((k) => same(k, c))),
        ])
          FilterChip(
            label: Text(v),
            selected: chosen.any((c) => same(c, v)),
            onSelected: (on) => setState(() {
              chosen.removeWhere((c) => same(c, v));
              if (on) chosen.add(v);
            }),
          ),
      ],
    );
  }

  Widget _labelRow(
    String column,
    int i,
    TextEditingController text,
    String tone,
  ) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: text,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                isDense: true,
                hintText: 'Value, as it reads',
              ),
            ),
          ),
          const SizedBox(width: 12),
          for (final (t, name) in _swatches)
            Tooltip(
              message: name,
              child: InkWell(
                key: ValueKey('project-board-swatch:$column:$i:${t.name}'),
                customBorder: const CircleBorder(),
                onTap: () =>
                    setState(() => _labels[column]![i] = (text, t.name)),
                child: Container(
                  width: 24,
                  height: 24,
                  margin: const EdgeInsets.symmetric(horizontal: 2),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: ToneColours.of(context, t).ink,
                    border: tone == t.name
                        ? Border.all(
                            color: theme.colorScheme.onSurface,
                            width: 3,
                          )
                        : null,
                  ),
                ),
              ),
            ),
          const SizedBox(width: 12),
          SizedBox(
            width: 180,
            child: Align(
              alignment: Alignment.centerLeft,
              child: StatusPill(
                text.text.isEmpty ? 'Preview' : text.text,
                tone:
                    Tone.values.where((t) => t.name == tone).firstOrNull ??
                    Tone.neutral,
              ),
            ),
          ),
          IconButton(
            tooltip: 'Remove',
            icon: const Icon(Icons.close),
            onPressed: () => setState(() => _labels[column]!.removeAt(i)),
          ),
        ],
      ),
    );
  }

  /// One column's row: its name, drag handle, Shown and coloured label.
  /// Below 600 px the name takes a line of its own above the controls; beside
  /// them it was squeezed to nothing, and the rows could not be told apart
  /// (mobile review 2026-11-13).
  Widget _columnRow(int i, Map<String, dynamic> c, {required bool phone}) {
    final name = TextFormField(
      key: ValueKey('project-board-column-name:${c['key']}'),
      initialValue: '${c['label'] ?? ''}',
      decoration: const InputDecoration(isDense: true),
      onChanged: (v) => c['label'] = v,
    );
    final controls = [
      const Text('Shown'),
      Switch(
        value: c['visible'] != false,
        onChanged: (v) => setState(() => c['visible'] = v),
      ),
      Flexible(
        child: Tooltip(
          message:
              'Draw each value as a coloured chip. Pick the colours under Label colours.',
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Checkbox(
                value: c['as_label'] == true,
                onChanged: (v) => setState(() {
                  c['as_label'] = v == true;
                  if (v == true) {
                    _labels.putIfAbsent('${c['key']}', () => []);
                  }
                }),
              ),
              const Flexible(
                child: Text(
                  'Show as coloured label',
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
      ),
    ];
    final handle = ReorderableDragStartListener(
      index: i,
      child: const Icon(Icons.drag_indicator),
    );
    final key = ValueKey('project-board-column:${c['key']}');
    if (phone) {
      return Padding(
        key: key,
        padding: const EdgeInsets.only(bottom: 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                handle,
                const SizedBox(width: 8),
                Expanded(child: name),
              ],
            ),
            Padding(
              padding: const EdgeInsets.only(left: 32),
              child: Row(children: controls),
            ),
          ],
        ),
      );
    }
    return Row(
      key: key,
      children: [
        handle,
        const SizedBox(width: 8),
        Expanded(child: name),
        const SizedBox(width: 8),
        ...controls,
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) return const LinearProgressIndicator();
    final theme = Theme.of(context);
    final phone = MediaQuery.sizeOf(context).width < 600;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (_error != null) Text(_error!),
        _card(
          'columns',
          'Columns',
          'Rename, drag to reorder, and choose which columns show.',
          [
            ReorderableListView(
              key: const Key('project-board-columns'),
              shrinkWrap: true,
              buildDefaultDragHandles: false,
              physics: const NeverScrollableScrollPhysics(),
              onReorder: (from, to) => setState(() {
                final c = _columns.removeAt(from);
                _columns.insert(to > from ? to - 1 : to, c);
              }),
              children: [
                for (final (i, c) in _columns.indexed)
                  _columnRow(i, c, phone: phone),
              ],
            ),
          ],
          () => {'project_board.columns': _columns, ..._labelValues()},
        ),
        _card(
          'labels',
          'Label colours',
          'The colour each value gets in a column shown as coloured labels.',
          [
            for (final c in _columns)
              if (c['as_label'] == true) ...[
                Text(
                  '${c['label'] ?? c['key']}',
                  style: theme.textTheme.titleSmall,
                ),
                for (final (i, (text, tone))
                    in (_labels['${c['key']}'] ?? []).indexed)
                  _labelRow('${c['key']}', i, text, tone),
                TextButton.icon(
                  key: ValueKey('project-board-add-label:${c['key']}'),
                  onPressed: () => setState(
                    () => (_labels['${c['key']}'] ??= []).add((
                      TextEditingController(),
                      Tone.neutral.name,
                    )),
                  ),
                  icon: const Icon(Icons.add),
                  label: const Text('Add value'),
                ),
                const SizedBox(height: 8),
              ],
          ],
          _labelValues,
        ),
        // One card per sub-tab (Jose, 2026-11-16: "separated in their own
        // boxes"); each Save writes every sub-tab's rules, as before.
        for (final (key, name, _) in _tabs)
          KeyedSubtree(
            key: ValueKey('project-board-tab-card:$key'),
            child: _card(
              'tabs:$key',
              name,
              'What puts a job on $name: its status, its tags, and its scheduled date. No status chosen lists nothing.',
              [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Spacer(),
                        DropdownMenu<String>(
                          enableFilter: true,
                          requestFocusOnTap: true,
                          width: 240,
                          label: const Text('Scheduled date'),
                          initialSelection: switch (_scheduled[key]) {
                            true => 'yes',
                            false => 'no',
                            null => 'any',
                          },
                          onSelected: (v) => _scheduled[key] = switch (v) {
                            'yes' => true,
                            'no' => false,
                            _ => null,
                          },
                          dropdownMenuEntries: const [
                            DropdownMenuEntry(value: 'any', label: 'Any'),
                            DropdownMenuEntry(
                              value: 'yes',
                              label: 'Must be set',
                            ),
                            DropdownMenuEntry(
                              value: 'no',
                              label: 'Must be empty',
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text('Status is one of', style: theme.textTheme.bodySmall),
                    const SizedBox(height: 4),
                    _chips(
                      'project-board-statuses:$key',
                      _knownStatuses,
                      _statuses[key]!,
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Text('Tags: has', style: theme.textTheme.bodySmall),
                        const SizedBox(width: 8),
                        DropdownMenu<bool>(
                          key: ValueKey('project-board-tags-mode:$key'),
                          enableFilter: true,
                          requestFocusOnTap: true,
                          width: 140,
                          initialSelection: _anyTag[key] ?? false,
                          onSelected: (v) => _anyTag[key] = v ?? false,
                          dropdownMenuEntries: const [
                            DropdownMenuEntry(value: false, label: 'all of'),
                            DropdownMenuEntry(value: true, label: 'any of'),
                          ],
                        ),
                        const SizedBox(width: 8),
                        // Flexible: on a phone it ran past the edge.
                        Flexible(
                          child: Text(
                            'these (none chosen: tags do not matter)',
                            style: theme.textTheme.bodySmall,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    _tagPicker('project-board-tab-tags:$key', _tabTags[key]!),
                    const SizedBox(height: 8),
                    Text(
                      'Must not have any of these tags',
                      style: theme.textTheme.bodySmall,
                    ),
                    const SizedBox(height: 4),
                    _tagPicker(
                      'project-board-tab-no-tags:$key',
                      _tabNoTags[key]!,
                    ),
                  ],
                ),
              ],
              () => {
                'project_board.tabs': {
                  for (final (key, _, _) in _tabs)
                    key: {
                      'statuses': _statuses[key]!,
                      if (_tabTags[key]!.isNotEmpty) ...{
                        'tags': _tabTags[key]!,
                        'tags_mode': _anyTag[key] == true ? 'any' : 'all',
                      },
                      if (_tabNoTags[key]!.isNotEmpty)
                        'tags_none': _tabNoTags[key]!,
                      if (_scheduled[key] != null)
                        'scheduled': _scheduled[key]!,
                    },
                },
              },
            ),
          ),
        _card(
          'tech_roles',
          'Roles that can be picked as Technician',
          'The technician picker lists only active users holding one of these roles.',
          [
            Wrap(
              key: const Key('project-board-tech-roles'),
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final r in {..._knownRoles, ..._techRoles})
                  FilterChip(
                    key: ValueKey('project-board-tech-role:$r'),
                    label: Text(r),
                    selected: _techRoles.any(
                      (t) => t.toLowerCase() == r.toLowerCase(),
                    ),
                    onSelected: (on) => setState(() {
                      _techRoles.removeWhere(
                        (t) => t.toLowerCase() == r.toLowerCase(),
                      );
                      if (on) _techRoles.add(r);
                    }),
                  ),
              ],
            ),
          ],
          () => {'project_board.technician_roles': _techRoles},
        ),
        _card(
          'parts',
          'Part status',
          'The job tags shown in the Part Status column.',
          [
            KeyedSubtree(
              key: const Key('project-board-part-tags'),
              child: _tagPicker('project-board-part-tags-chips', _partTags),
            ),
          ],
          () => {'project_board.part_tags': _partTags},
        ),
        _repairCard(),
        _suppliersCard(),
        _card(
          'ready',
          'Ready to schedule',
          'What a job needs before "Ready to schedule" can move it on.',
          [
            for (final (k, label) in const [
              ('service_area', 'Service Area'),
              ('technician', 'Technician'),
              ('repair_team', 'Repair Team / Contractor'),
              ('attachment', 'At least one attachment'),
              ('note', 'At least one note'),
            ])
              CheckboxListTile(
                key: ValueKey('project-board-ready-needs:$k'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                title: Text(label),
                value: _readyNeeds.contains(k),
                onChanged: (on) => setState(
                  () => on == true ? _readyNeeds.add(k) : _readyNeeds.remove(k),
                ),
              ),
          ],
          () => {'project_board.ready_requires': _readyNeeds.toList()},
        ),
        _card(
          'status_days',
          'Status Time colours',
          'Green below the first number of days, yellow from it, red from the second.',
          [
            Wrap(
              spacing: 12,
              runSpacing: 8,
              children: [
                for (final (c, label, key) in [
                  (_warnDays, 'Yellow from (days)', 'project-board-warn-days'),
                  (_badDays, 'Red from (days)', 'project-board-bad-days'),
                ])
                  SizedBox(
                    width: 180,
                    child: TextField(
                      key: Key(key),
                      controller: c,
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(
                        isDense: true,
                        labelText: label,
                        border: const OutlineInputBorder(),
                      ),
                    ),
                  ),
              ],
            ),
          ],
          () => {
            'project_board.status_days': {
              'warn': int.tryParse(_warnDays.text.trim()) ?? 3,
              'bad': int.tryParse(_badDays.text.trim()) ?? 7,
            },
          },
        ),
      ],
    );
  }
}

/// The label colours, named as a person sees them.
const _swatches = [
  (Tone.neutral, 'Grey'),
  (Tone.info, 'Blue'),
  (Tone.caution, 'Amber'),
  (Tone.review, 'Teal'),
  (Tone.ok, 'Green'),
  (Tone.bad, 'Red'),
];

List<Map<String, dynamic>> _maps(Object? list) => [
  for (final m in (list as List?) ?? const [])
    (m as Map).cast<String, dynamic>(),
];

const _moneyKeys = {'paid', 'pending', 'total'};

/// Jose's six sub-tabs (2026-11-06): the server's key, the label, the icon.
const _tabs = [
  ('unscheduled', 'Unscheduled', Icons.event_busy_outlined),
  ('ready_to_schedule', 'Ready to Schedule', Icons.event_available_outlined),
  ('scheduled', 'Scheduled', Icons.calendar_month_outlined),
  ('in_progress', 'In Progress', Icons.construction_outlined),
  ('done_waiting_payment', 'Done Waiting Payment', Icons.payments_outlined),
  ('complete', 'Complete', Icons.task_alt),
];

/// One required item per line, as the server joins them.
const _newline = '\n';

/// Time slots offered in the Ready to schedule questions; any other can be typed.
const _slots = ['8 - 11 AM', '11 AM - 2 PM', '2 - 5 PM'];

/// The parts selector (Jose, 2026-11-10, and 2026-11-17): each part's
/// item, quantity and price; In stock (green, done), Order from a supplier
/// (red) or Same-day pickup at a store (yellow); where it goes; and
/// Received once it is here. Pops the list to save, or null.
class _PartsPanel extends StatefulWidget {
  const _PartsPanel({
    required this.customer,
    required this.parts,
    required this.formats,
    this.suppliers = const [],
  });

  final String customer;
  final List<Map<String, dynamic>> parts;
  final DisplayFormats formats;
  final List<Map<String, dynamic>> suppliers;

  @override
  State<_PartsPanel> createState() => _PartsPanelState();
}

/// One part being edited.
class _PartRow {
  _PartRow(Map<String, dynamic> p)
    : id = p['id'] as String?,
      kind = '${p['kind'] ?? 'stock'}',
      was = p['supplier'] is Map
          ? Map<String, dynamic>.from(p['supplier'] as Map)
          : null,
      supplierId = (p['supplier'] as Map?)?['id'] as String?,
      destination = p['destination'] as String?,
      received = p['received_at'] != null,
      item = TextEditingController(text: '${p['item'] ?? ''}'),
      quantity = TextEditingController(text: '${p['quantity'] ?? 1}'),
      price = TextEditingController(
        text: p['price_minor'] is num
            ? ((p['price_minor'] as num) / 100).toStringAsFixed(2)
            : '',
      ),
      delivery = TextEditingController(text: '${p['delivery_on'] ?? ''}');

  final String? id;
  String kind;

  /// The supplier as it was picked, for one that has left the list since.
  final Map<String, dynamic>? was;
  String? supplierId;
  String? destination;
  bool received;
  final TextEditingController item;
  final TextEditingController quantity;
  final TextEditingController price;
  final TextEditingController delivery;

  void dispose() {
    item.dispose();
    quantity.dispose();
    price.dispose();
    delivery.dispose();
  }
}

class _PartsPanelState extends State<_PartsPanel> {
  late final List<_PartRow> _rows = [
    for (final p in widget.parts) _PartRow(p),
  ];

  @override
  void dispose() {
    for (final r in _rows) {
      r.dispose();
    }
    super.dispose();
  }

  List<Map<String, dynamic>> get _value => [
    for (final r in _rows)
      if (r.item.text.trim().isNotEmpty)
        {
          'id': ?r.id,
          'kind': r.kind,
          'item': r.item.text.trim(),
          'quantity': int.tryParse(r.quantity.text.trim()) ?? 1,
          'price_minor': switch (double.tryParse(
            r.price.text.trim().replaceAll(r'$', ''),
          )) {
            final d? => (d * 100).round(),
            null => null,
          },
          if (r.kind == 'order' && r.delivery.text.trim().isNotEmpty)
            'delivery_on': r.delivery.text.trim(),
          if (r.kind != 'stock') ...{
            'supplier_id': r.supplierId,
            'destination': r.destination,
            'received': r.received,
          },
        },
  ];

  Map<String, dynamic>? _supplier(_PartRow r) {
    for (final s in widget.suppliers) {
      if (s['id'] == r.supplierId) return s;
    }
    return r.was != null && r.was!['id'] == r.supplierId ? r.was : null;
  }

  String _where(_PartRow r, Map<String, dynamic>? supplier) =>
      switch (r.destination) {
        'warehouse' => 'Work order: warehouse pickup.',
        'customer' => "At the customer's location.",
        'supplier' =>
          supplier == null
              ? 'Collected at the supplier.'
              : 'Collected at ${[supplier['name'], supplier['address']].whereType<String>().join(', ')}.',
        _ => '',
      };

  Widget _part(BuildContext context, int i, _PartRow r) {
    final theme = Theme.of(context);
    InputDecoration box(String label) => InputDecoration(
      isDense: true,
      labelText: label,
      border: const OutlineInputBorder(),
    );
    final fill = ToneColours.of(context, switch (r.kind) {
      'stock' => Tone.ok,
      'pickup' => Tone.caution,
      _ => Tone.bad,
    }).fill;
    final picked = _supplier(r);
    final choices = [
      for (final s in widget.suppliers)
        if (s['kind'] == r.kind) s,
    ];

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: TextField(
                    key: ValueKey('project-board-part-item-$i'),
                    controller: r.item,
                    decoration: box('Item'),
                  ),
                ),
                IconButton(
                  tooltip: 'Remove',
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: () => setState(() => _rows.removeAt(i).dispose()),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: r.quantity,
                    keyboardType: TextInputType.number,
                    decoration: box('Quantity'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    controller: r.price,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: box('Price'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: SegmentedButton<String>(
                key: ValueKey('project-board-part-kind-$i'),
                showSelectedIcon: false,
                style: ButtonStyle(
                  backgroundColor: WidgetStateProperty.resolveWith(
                    (states) =>
                        states.contains(WidgetState.selected) ? fill : null,
                  ),
                ),
                segments: const [
                  ButtonSegment(value: 'stock', label: Text('In stock')),
                  ButtonSegment(value: 'order', label: Text('Order')),
                  ButtonSegment(
                    value: 'pickup',
                    label: Text('Same-day pickup'),
                  ),
                ],
                selected: {r.kind},
                onSelectionChanged: (v) => setState(() {
                  r.kind = v.first;
                  if (_supplier(r)?['kind'] != r.kind) r.supplierId = null;
                  r.destination ??= r.kind == 'pickup'
                      ? 'supplier'
                      : 'warehouse';
                }),
              ),
            ),
            if (r.kind != 'stock') ...[
              const SizedBox(height: 8),
              SearchPickerField<String?>(
                key: ValueKey('project-board-part-supplier-$i'),
                value: r.supplierId,
                label: r.kind == 'pickup' ? 'Pickup store' : 'Supplier',
                hint: choices.isEmpty ? 'Add them in Settings' : 'Choose one',
                isDense: true,
                options: [
                  for (final s in choices)
                    SearchOption<String?>(
                      value: '${s['id']}',
                      label: '${s['name']}',
                      detail: s['address'] as String?,
                    ),
                  if (picked != null &&
                      !choices.any((s) => s['id'] == picked['id']))
                    SearchOption<String?>(
                      value: '${picked['id']}',
                      label: '${picked['name']}',
                      detail: 'No longer on the list',
                    ),
                ],
                onChanged: (v) => setState(() => r.supplierId = v),
              ),
              const SizedBox(height: 8),
              Text('Goes to', style: theme.textTheme.labelLarge),
              const SizedBox(height: 4),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: SegmentedButton<String>(
                  key: ValueKey('project-board-part-where-$i'),
                  showSelectedIcon: false,
                  emptySelectionAllowed: true,
                  segments: const [
                    ButtonSegment(value: 'warehouse', label: Text('Warehouse')),
                    ButtonSegment(
                      value: 'customer',
                      label: Text('Customer location'),
                    ),
                    ButtonSegment(value: 'supplier', label: Text('Supplier')),
                  ],
                  selected: {?r.destination},
                  onSelectionChanged: (v) => setState(
                    () => r.destination = v.isEmpty ? null : v.first,
                  ),
                ),
              ),
              if (_where(r, picked) case final where
                  when where.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(
                  where,
                  key: ValueKey('project-board-part-where-line-$i'),
                  style: theme.textTheme.bodySmall,
                ),
              ],
              if (r.kind == 'order') ...[
                const SizedBox(height: 8),
                Text('Delivery date', style: theme.textTheme.labelLarge),
                const SizedBox(height: 4),
                CalendarDayField(
                  controller: r.delivery,
                  formats: widget.formats,
                  dense: true,
                ),
              ],
              CheckboxListTile(
                key: ValueKey('project-board-part-received-$i'),
                contentPadding: EdgeInsets.zero,
                dense: true,
                controlAffinity: ListTileControlAffinity.leading,
                title: const Text('Received'),
                value: r.received,
                onChanged: (v) => setState(() => r.received = v ?? false),
              ),
            ],
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    title: 'Parts',
    subtitle: widget.customer,
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancel'),
      ),
      FilledButton(
        key: const Key('project-board-parts-save'),
        onPressed: () => Navigator.of(context).pop(_value),
        child: const Text('Save'),
      ),
    ],
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (i, r) in _rows.indexed) _part(context, i, r),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: const Key('project-board-part-add'),
            onPressed: () => setState(
              () => _rows.add(_PartRow(const {'kind': 'order', 'quantity': 1})),
            ),
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Add a part'),
          ),
        ),
      ],
    ),
  );
}

/// Adding or changing one repair team / contractor: its name, (new) its
/// Workiz colour, a phone or a user; Delete removes it everywhere. Pops the
/// request body for POST /project-board/repair-teams, or null.
class _RepairTeamPanel extends StatefulWidget {
  const _RepairTeamPanel({
    required this.team,
    required this.users,
    required this.colours,
  });

  final Map<String, dynamic>? team;
  final List<Map<String, dynamic>> users;
  final List<String> colours;

  @override
  State<_RepairTeamPanel> createState() => _RepairTeamPanelState();
}

class _RepairTeamPanelState extends State<_RepairTeamPanel> {
  late final _name = TextEditingController(
    text: '${widget.team?['name'] ?? ''}',
  );
  late final _phone = TextEditingController(
    text: '${widget.team?['phone'] ?? ''}',
  );
  late String? _userId = widget.team?['user_id'] as String?;
  late String? _colour = widget.colours.firstOrNull;
  bool _deleting = false;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    super.dispose();
  }

  bool get _isNew => widget.team == null;

  @override
  Widget build(BuildContext context) {
    final user = widget.users.where((u) => u['id'] == _userId).firstOrNull;
    return SidePanel(
      title: _isNew
          ? 'Add a repair team / contractor'
          : 'Repair team / contractor',
      actions: [
        if (!_isNew)
          TextButton(
            key: const Key('project-board-repair-team-delete'),
            onPressed: () => _deleting
                ? Navigator.of(context).pop(<String, Object?>{
                    'op': 'delete',
                    'name': widget.team!['name'],
                  })
                : setState(() => _deleting = true),
            child: Text(
              _deleting ? 'Delete from every Workiz account' : 'Delete',
            ),
          ),
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        ValueListenableBuilder(
          valueListenable: _name,
          builder: (context, name, _) => FilledButton(
            key: const Key('project-board-repair-team-save'),
            onPressed: name.text.trim().isEmpty
                ? null
                : () => Navigator.of(context).pop(<String, Object?>{
                    'op': _isNew ? 'create' : 'update',
                    'name': _isNew ? name.text.trim() : widget.team!['name'],
                    if (!_isNew) 'to': name.text.trim(),
                    if (_isNew) 'color': _colour,
                    'phone': _phone.text.trim().isEmpty
                        ? null
                        : _phone.text.trim(),
                    'user_id': _userId,
                  }),
            child: const Text('Save'),
          ),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const Key('project-board-repair-team-name'),
            controller: _name,
            decoration: const InputDecoration(
              labelText: 'Name (the Workiz tag)',
              border: OutlineInputBorder(),
            ),
          ),
          if (_isNew) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              children: [
                for (final c in widget.colours)
                  ChoiceChip(
                    key: ValueKey('project-board-repair-colour:$c'),
                    showCheckmark: false,
                    label: const SizedBox(width: 16, height: 16),
                    backgroundColor: ColourField.parse(c),
                    selectedColor: ColourField.parse(c),
                    side: _colour == c ? const BorderSide(width: 3) : null,
                    selected: _colour == c,
                    onSelected: (_) => setState(() => _colour = c),
                  ),
              ],
            ),
          ],
          const SizedBox(height: 12),
          TextField(
            key: const Key('project-board-repair-team-phone'),
            controller: _phone,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: 'Phone',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            key: const Key('project-board-repair-team-user'),
            icon: const Icon(Icons.person_outline, size: 18),
            label: Text('${user?['name'] ?? 'Pick a user'}'),
            onPressed: () async {
              final picked = await showSearchPicker<String>(
                context,
                title: 'User',
                value: _userId,
                options: [
                  const SearchOption(value: '', label: 'Nobody'),
                  for (final u in widget.users)
                    SearchOption(value: '${u['id']}', label: '${u['name']}'),
                ],
              );
              if (picked != null && mounted) {
                setState(() => _userId = picked.isEmpty ? null : picked);
              }
            },
          ),
        ],
      ),
    );
  }
}

/// Clicking Notes on the board (Jose, 2026-11-17): the job's notes and
/// nothing else, read with the job and added in place.
class _NotesPanel extends StatefulWidget {
  const _NotesPanel({
    required this.api,
    required this.jobId,
    required this.title,
    required this.formats,
    this.addRefusal,
  });

  final ApiClient api;
  final String jobId;
  final String title;
  final DisplayFormats formats;
  final String? addRefusal;

  @override
  State<_NotesPanel> createState() => _NotesPanelState();
}

class _NotesPanelState extends State<_NotesPanel> {
  final _draft = TextEditingController();
  List<Map<String, dynamic>>? _notes;
  String? _workiz;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _draft.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/jobs/${widget.jobId}');
    if (!mounted) return;
    final row = response.data;
    setState(() {
      if (!response.ok || row is! Map) {
        _error = response.reason;
        return;
      }
      _notes = jobNotes(row['notes']);
      _workiz = row['workiz_notes'] as String?;
    });
  }

  @override
  Widget build(BuildContext context) => SidePanel(
    key: const ValueKey('project-board-notes-panel'),
    title: 'Notes',
    subtitle: widget.title,
    width: 460,
    child: _notes == null
        ? (_error == null
              ? const Padding(
                  padding: EdgeInsets.all(32),
                  child: Center(child: CircularProgressIndicator()),
                )
              : Text(_error!))
        : JobNotes(
            api: widget.api,
            jobId: widget.jobId,
            notes: _notes!,
            draft: _draft,
            formats: widget.formats,
            addRefusal: widget.addRefusal,
            workizNotes: _workiz,
            onAdded: (note) => setState(() => _notes = [note, ..._notes!]),
          ),
  );
}

/// One supplier in Settings while it is edited.
class _SupplierRow {
  _SupplierRow(Map<String, dynamic> v)
    : id =
          v['id'] as String? ??
          's-${DateTime.now().microsecondsSinceEpoch.toRadixString(36)}',
      kind = v['kind'] == 'pickup' ? 'pickup' : 'order',
      name = TextEditingController(text: v['name'] as String? ?? ''),
      address = TextEditingController(text: v['address'] as String? ?? ''),
      phone = TextEditingController(text: v['phone'] as String? ?? '');

  /// Kept across edits: a part points at its supplier by it.
  final String id;
  String kind;
  final TextEditingController name;
  final TextEditingController address;
  final TextEditingController phone;

  Map<String, Object?> get value => {
    'id': id,
    'name': name.text.trim(),
    'address': address.text.trim().isEmpty ? null : address.text.trim(),
    'phone': phone.text.trim().isEmpty ? null : phone.text.trim(),
    'kind': kind,
  };

  void dispose() {
    name.dispose();
    address.dispose();
    phone.dispose();
  }
}
