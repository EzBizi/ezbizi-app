import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/job_money.dart';
import '../../core/numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/franchise_field.dart';
import '../../widgets/grouped_rows.dart';
import '../../widgets/load_more.dart';
import '../../widgets/module_page_header.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'estimate_board.dart';

/// The word and tone of an estimate's status, as the chips, rows and panel
/// say it.
(String, Tone) estimateStatus(Object? status) => switch (status) {
  'sent' => ('Sent', Tone.info),
  'accepted' => ('Accepted', Tone.ok),
  'declined' => ('Declined', Tone.bad),
  'archived' => ('Archived', Tone.caution),
  _ => ('Draft', Tone.neutral),
};

const _statuses = ['draft', 'sent', 'accepted', 'declined', 'archived'];

/// Estimates, the Jobs module's second tab (queue 32, Jose's 1 and 4,
/// 2026-09-29): the jobs list's twin, status chips with their counts and what
/// was quoted and accepted, and the estimate docked beside the list. A phone
/// opens the estimate in place, with its own way back.
///
/// Money, so it needs the Financials toggle as well as `estimates.view`; the
/// Jobs screen draws this tab only for a reader who holds both.
class EstimatesTab extends StatefulWidget {
  const EstimatesTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<EstimatesTab> createState() => _EstimatesTabState();
}

class _EstimatesTabState extends State<EstimatesTab> {
  /// Where the table starts, as on the Jobs tab.
  static const _tableFrom = 900.0;

  String? _status;
  final _search = TextEditingController();
  List<Map<String, dynamic>> _rows = const [];
  Map<String, int> _counts = const {};
  Map<String, Map<String, int>> _totals = const {};
  Transitions _transitions = const {};

  /// Bumped on every first-page load, so the board reloads with the list.
  int _generation = 0;

  bool _loading = true;
  bool _fetching = false;
  String? _error;
  String? _pageError;
  int _page = 1;
  int _lastPage = 1;
  int _total = 0;
  int _request = 0;

  /// The estimate open beside the list, or in place of it on a phone; `new`
  /// is one being written.
  String? _openId;

  /// The open one has changes nobody saved, so another row must not replace
  /// it -- the jobs list's rule for the job docked beside it.
  final _openDirty = ValueNotifier<bool>(false);

  Me get _me => widget.session.me!;

  /// List or Board, remembered for each person (design 172).
  bool get _board => _me.preferences['jobs.estimates.view'] == 'board';

  void _setBoard(bool board, {required bool wide}) {
    widget.session.setPreference(
      'jobs.estimates.view',
      board ? 'board' : 'list',
    );
    // A phone's board shows one status at a time, never all of them.
    if (board && !wide && _status == null) {
      _setStatus('draft');
    } else {
      setState(() {});
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
    // An estimate written, sent or answered elsewhere: page one (and so the
    // board, the counts and the totals) re-reads quietly.
    _live = LiveChanges.of(widget.api).subscribe({'estimates'}, (_) {
      if (_page == 1 && !_fetching) return _load(quiet: true);
    }, active: LiveChanges.visible(this));
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _search.dispose();
    _openDirty.dispose();
    super.dispose();
  }

  String? get _term => _search.text.trim().isEmpty ? null : _search.text.trim();

  /// [quiet]: a live re-read; a failure keeps the rows on screen.
  Future<void> _load({int page = 1, bool quiet = false}) async {
    if (page > 1 && _fetching) return;
    final request = ++_request;

    setState(() {
      _loading = page == 1 && _rows.isEmpty;
      _fetching = true;
      if (!quiet) {
        _error = null;
        _pageError = null;
      }
    });

    final response = await widget.api.get('/estimates', {
      'page': '$page',
      'status': ?_status,
      'search': ?_term,
    });

    if (!mounted || request != _request) return;
    if (quiet && !response.ok) {
      setState(() => _fetching = false);
      return;
    }

    setState(() {
      _loading = false;
      _fetching = false;

      if (!response.ok) {
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _rows = page == 1 ? rows : [..._rows, ...rows];

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _rows.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;

      _transitions = readTransitions(meta?['transitions']);

      // The header's figures come with the first page only.
      if (page == 1) {
        _generation++;
        final counts = meta?['status_counts'];
        _counts = counts is Map
            ? {
                for (final e in counts.entries)
                  if (e.value is int) '${e.key}': e.value as int,
              }
            : const {};

        final totals = meta?['totals'];
        _totals = totals is Map
            ? {
                for (final e in totals.entries)
                  if (e.value is Map)
                    '${e.key}': {
                      for (final t in (e.value as Map).entries)
                        if (t.value is num)
                          '${t.key}': (t.value as num).toInt(),
                    },
              }
            : const {};
      }
    });
  }

  void _setStatus(String? status) {
    if (status == _status) return;
    setState(() => _status = status);
    _load();
  }

  void _open(String id) {
    if (id == _openId) return;

    if (_openId != null && _openDirty.value) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Save or cancel the changes to this estimate first.'),
        ),
      );
      return;
    }

    setState(() => _openId = id);
  }

  void _closed(bool changed) {
    _openDirty.value = false;
    setState(() => _openId = null);
    if (changed) _load();
  }

  void _created(String id) {
    _openDirty.value = false;
    setState(() => _openId = id);
    _load();
  }

  EstimatePanel _panel(String id, {required bool inPlace}) => EstimatePanel(
    // A new estimate is a new panel: its fields, its load, its dirt.
    key: ValueKey('estimate-$id'),
    api: widget.api,
    session: widget.session,
    id: id == 'new' ? null : id,
    inPlace: inPlace,
    onDone: _closed,
    onCreated: _created,
    onSaved: _load,
    dirty: _openDirty,
  );

  @override
  Widget build(BuildContext context) {
    final refused =
        _me.refusal('estimates.create', 'add an estimate') ??
        (_me.franchises.isEmpty ? _me.nowhereToAct('add an estimate') : null);

    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= _tableFrom;

        // A phone draws the estimate in place of the list (containment).
        if (!wide && _openId != null) return _panel(_openId!, inPlace: true);

        return HeaderAboveList(
          header: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              PanelHeader(
                note: _note(),
                // In the module header, on the right, as every tab's primary
                // action (desktop review D3; UI pass 2026-11-17).
                action: ModuleAction(
                  child: Refused(
                    reason: refused,
                    child: FilledButton.icon(
                      key: const ValueKey('estimate-new'),
                      onPressed: refused == null ? () => _open('new') : null,
                      icon: const Icon(Icons.add, size: 18),
                      label: const Text('New estimate'),
                    ),
                  ),
                ),
              ),
              if (!(_board && wide)) _chips(context, wide: wide),
              _searchAndTotals(context, wide: wide),
            ],
          ),
          list: AsyncBody(
            key: const ValueKey('tour-jobs-estimates-list'),
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _body(context, wide: wide),
          ),
        );
      },
    );
  }

  String _note() {
    if (_loading || _error != null) return 'Everything you can reach.';

    final reach =
        'across the ${_me.organization.franchiseLabel.toLowerCase()}s you can reach';

    return _rows.length < _total
        ? 'Showing ${_rows.length} of ${formatCount(_total)} $reach.'
        : '${formatCount(_total)} ${_total == 1 ? 'estimate' : 'estimates'} $reach.';
  }

  /// Each status with its count, and a dot in the colour of its pill. A desk
  /// wraps them; a phone swipes them.
  Widget _chips(BuildContext context, {required bool wide}) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    Widget chip(String? value, String label, {Tone? tone, int? count}) =>
        ChoiceChip(
          key: ValueKey('estimate-chip-${value ?? 'all'}'),
          avatar: tone == null ? null : ToneDot(tone),
          showCheckmark: false,
          selected: _status == value,
          label: Text.rich(
            TextSpan(
              text: label,
              children: [
                if (count != null)
                  TextSpan(
                    text: '  ${formatCount(count)}',
                    style: TextStyle(color: muted, fontWeight: FontWeight.w500),
                  ),
              ],
            ),
          ),
          onSelected: (_) => _setStatus(value),
        );

    final counted = _counts.isNotEmpty;
    final chips = [
      if (!_board)
        chip(
          null,
          'All',
          count: counted ? _counts.values.fold<int>(0, (a, b) => a + b) : null,
        ),
      for (final s in _statuses)
        chip(
          s,
          estimateStatus(s).$1,
          tone: estimateStatus(s).$2,
          count: counted ? (_counts[s] ?? 0) : null,
        ),
    ];

    return Padding(
      key: const ValueKey('tour-jobs-estimates-chips'),
      padding: EdgeInsets.fromLTRB(wide ? 24 : 0, 0, wide ? 24 : 0, 10),
      child: wide
          ? Wrap(spacing: 6, runSpacing: 6, children: chips)
          : SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Row(
                children: [
                  for (final c in chips)
                    Padding(padding: const EdgeInsets.only(right: 6), child: c),
                ],
              ),
            ),
    );
  }

  /// The search, then what was quoted and what was accepted, per currency and
  /// never added across them. Like the chip counts, the figures follow the
  /// search and not the chosen status.
  Widget _searchAndTotals(BuildContext context, {required bool wide}) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    Widget figure(String label, int minor, String currency, {Tone? tone}) =>
        Text.rich(
          TextSpan(
            text: '$label ',
            style: theme.textTheme.bodySmall?.copyWith(color: muted),
            children: [
              TextSpan(
                text: wide
                    ? formatMoney(minor, currency: currency, cents: false)
                    : shortMinor(minor, currency: currency),
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tone == null
                      ? null
                      : ToneColours.of(context, tone).ink,
                ),
              ),
            ],
          ),
          key: ValueKey('estimate-total-${label.toLowerCase()}-$currency'),
        );

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
      child: Wrap(
        spacing: 18,
        runSpacing: 10,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: 320,
            child: ListSearch(
              key: const ValueKey('estimate-search'),
              controller: _search,
              hint: 'Number or customer',
              onChanged: (_) => setState(() {}),
              onSubmitted: (_) => _load(),
              onClear: () {
                _search.clear();
                _load();
              },
            ),
          ),
          for (final MapEntry(key: currency, value: t) in _totals.entries) ...[
            figure('Quoted', t['quoted'] ?? 0, currency),
            figure('Accepted', t['accepted'] ?? 0, currency, tone: Tone.ok),
          ],
          SegmentedButton<bool>(
            key: const ValueKey('estimate-view'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(
                value: false,
                icon: Icon(Icons.view_list, size: 18),
                label: Text('List'),
              ),
              ButtonSegment(
                value: true,
                icon: Icon(Icons.view_kanban_outlined, size: 18),
                label: Text('Board'),
              ),
            ],
            selected: {_board},
            onSelectionChanged: (v) => _setBoard(v.first, wide: wide),
          ),
        ],
      ),
    );
  }

  Widget _body(BuildContext context, {required bool wide}) {
    final open = _openId;

    if (_board && wide) {
      final board = EstimateBoard(
        api: widget.api,
        me: _me,
        search: _term,
        counts: _counts,
        transitions: _transitions,
        generation: _generation,
        selectedId: open,
        onOpen: _open,
        onMoved: _load,
      );
      if (open == null) return board;
      return Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(child: board),
          SizedBox(width: 440, child: _panel(open, inPlace: false)),
        ],
      );
    }

    if (_rows.isEmpty && open == null) {
      // The first one starts here too, not only from the header (UI review,
      // 2026-10-08), for somebody allowed to add one.
      final first =
          _term == null &&
          _status == null &&
          _me.refusal('estimates.create', 'add an estimate') == null &&
          _me.franchises.isNotEmpty;

      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                _term != null
                    ? 'Nothing matches "$_term".'
                    : _status == null
                    ? 'No estimates yet.'
                    : 'No ${estimateStatus(_status).$1.toLowerCase()} estimates.',
              ),
              if (first) ...[
                const SizedBox(height: 12),
                FilledButton.icon(
                  key: const ValueKey('estimate-new-empty'),
                  onPressed: () => _open('new'),
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('New estimate'),
                ),
              ],
            ],
          ),
        ),
      );
    }

    // One group with no heading: estimates arrive newest first, and there is
    // no day or letter they are honestly divided by.
    final groups = [RowGroup(heading: '', rows: _rows)];
    final footer = _page < _lastPage ? _loadMore() : null;

    if (!wide && _board) {
      return ListView(
        key: const ValueKey('estimate-board-phone'),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        children: [
          for (final row in _rows)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: EstimateCard(
                row: row,
                me: _me,
                onTap: () => _open(_id(row)),
                trailing: TextButton(
                  key: ValueKey('estimate-move-to-${_id(row)}'),
                  onPressed: () => _moveTo(row),
                  child: const Text('Move to ▾'),
                ),
              ),
            ),
          ?footer,
        ],
      );
    }

    if (!wide) {
      return GroupedList<Map<String, dynamic>>(
        groups: groups,
        idOf: _id,
        tile: _tile,
        onOpen: (row) => _open(_id(row)),
        footer: footer,
      );
    }

    if (open == null) {
      return GroupedTable<Map<String, dynamic>>(
        columns: _columns(context),
        groups: groups,
        idOf: _id,
        onOpen: (row) => _open(_id(row)),
        footer: footer,
      );
    }

    return DockedListDetail(
      list: GroupedList<Map<String, dynamic>>(
        groups: groups,
        idOf: _id,
        tile: _tile,
        onOpen: (row) => _open(_id(row)),
        selectedId: open,
        footer: footer,
      ),
      detail: _panel(open, inPlace: false),
    );
  }

  Future<void> _moveTo(Map<String, dynamic> row) async {
    final to = await moveSheet(
      context,
      _customer(row),
      _transitions['${row['status']}'] ?? const [],
    );
    if (to == null || !mounted) return;
    final reason = await moveEstimate(widget.api, _id(row), to);
    if (!mounted) return;
    if (reason != null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(reason)));
      return;
    }
    _load();
  }

  Widget _loadMore() => Padding(
    padding: const EdgeInsets.symmetric(vertical: 12),
    child: LoadMore(
      shown: _rows.length,
      total: _total,
      busy: _fetching,
      error: _pageError,
      onMore: () => _load(page: _page + 1),
      groupDigits: true,
    ),
  );

  static String _id(Map<String, dynamic> row) => '${row['id']}';

  static String _customer(Map<String, dynamic> row) =>
      '${(row['customer'] as Map?)?['name'] ?? ''}';

  static String? _job(Map<String, dynamic> row) =>
      (row['job'] as Map?)?['number'] as String?;

  /// What it is for, when somebody said. A1 sent one on nearly every row.
  static String? _title(Map<String, dynamic> row) {
    final title = '${row['title'] ?? ''}'.trim();
    return title.isEmpty ? null : title;
  }

  /// When it was made, in the reader's zone. The date every row has: A1's
  /// estimates came with none issued, and keep A1's own created date
  /// (Jobs, 768c9658). The issue date is on the estimate itself.
  String? _createdOn(Map<String, dynamic> row) {
    final at = DateTime.tryParse('${row['created_at'] ?? ''}')?.toLocal();
    return at == null ? null : _me.display.formatDate(at);
  }

  static String _amount(Map<String, dynamic> row) => formatMoney(
    (row['total_minor'] as num?)?.toInt() ?? 0,
    currency: '${row['currency'] ?? 'USD'}',
  );

  static StatusPill _pill(Map<String, dynamic> row) {
    final (label, tone) = estimateStatus(row['status']);
    return StatusPill(label, tone: tone);
  }

  List<TableColumn<Map<String, dynamic>>> _columns(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    return [
      TableColumn(
        'Estimate',
        width: 110,
        cell: (_, row) =>
            Text('${row['number'] ?? ''}', style: TextStyle(color: muted)),
      ),
      TableColumn(
        'Title',
        flex: 3,
        cell: (_, row) => Text(
          _title(row) ?? '—',
          style: _title(row) == null
              ? TextStyle(color: muted)
              : const TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
      TableColumn('Customer', flex: 2, cell: (_, row) => Text(_customer(row))),
      TableColumn(
        'Job',
        width: 104,
        cell: (_, row) =>
            Text(_job(row) ?? '—', style: TextStyle(color: muted)),
      ),
      TableColumn(
        'Created',
        width: 120,
        cell: (_, row) => Text(_createdOn(row) ?? '—'),
      ),
      TableColumn('Status', width: 120, cell: (_, row) => _pill(row)),
      TableColumn(
        'Total',
        width: 130,
        numeric: true,
        cell: (_, row) => Text(
          _amount(row),
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
    ];
  }

  Widget _tile(BuildContext context, Map<String, dynamic> row) => TwoLineTile(
    title: _customer(row),
    topRight: _amount(row),
    subtitle: [
      _title(row),
      '${row['number'] ?? ''}',
      _job(row),
      _createdOn(row),
      if (row['source'] == 'a1') 'from A1 Tools',
    ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
    bottomRight: _pill(row),
  );
}

/// One line as it is being edited: the words and the two numbers, kept as
/// typed until they are sent.
class _Line {
  _Line({
    this.serviceId,
    String name = '',
    this.description,
    String quantity = '1',
    String price = '',
  }) : name = TextEditingController(text: name),
       quantity = TextEditingController(text: quantity),
       price = TextEditingController(text: price);

  final String? serviceId;
  final String? description;
  final TextEditingController name;
  final TextEditingController quantity;
  final TextEditingController price;

  double get qty => double.tryParse(quantity.text.trim()) ?? 0;

  /// What was typed as money, in cents: "$2,400", "2400.5" and "2,400.50"
  /// alike.
  int get unitMinor {
    final cleaned = price.text.replaceAll(RegExp(r'[^0-9.]'), '');
    return ((double.tryParse(cleaned) ?? 0) * 100).round();
  }

  int get amountMinor => (qty * unitMinor).round();

  Map<String, dynamic> toJson() => {
    'service_id': ?serviceId,
    'name': name.text.trim(),
    'description': ?description,
    'quantity': qty,
    'unit_price_minor': unitMinor,
  };

  void dispose() {
    name.dispose();
    quantity.dispose();
    price.dispose();
  }
}

/// One estimate: read, written, and moved along. Docked beside the list on a
/// desk, in place of it on a phone.
class EstimatePanel extends StatefulWidget {
  const EstimatePanel({
    super.key,
    required this.api,
    required this.session,
    required this.id,
    required this.inPlace,
    required this.onDone,
    this.onCreated,
    this.onSaved,
    this.dirty,
  });

  final ApiClient api;
  final Session session;

  /// Null writes a new one.
  final String? id;

  /// A phone: drawn in place of the list, with a way back.
  final bool inPlace;

  /// Closed; true when something changed.
  final ValueChanged<bool> onDone;

  /// A new one was created, with this id: the list opens it.
  final ValueChanged<String>? onCreated;

  /// Saved and still open: the list re-reads its rows.
  final VoidCallback? onSaved;

  /// Told whether anything here is unsaved, so the list can refuse to open
  /// another estimate over it.
  final ValueNotifier<bool>? dirty;

  @override
  State<EstimatePanel> createState() => _EstimatePanelState();
}

class _EstimatePanelState extends State<EstimatePanel> {
  Map<String, dynamic>? _row;
  bool _loading = true;
  bool _busy = false;
  bool _changed = false;
  String? _error;

  final List<_Line> _lines = [];
  final _title = TextEditingController();
  final _note = TextEditingController();
  final _issued = TextEditingController();

  /// A new estimate's who and what for.
  String? _franchiseId;
  Map<String, dynamic>? _customer;
  String? _jobId;
  List<Map<String, dynamic>> _customerJobs = const [];

  List<Map<String, dynamic>> _services = const [];

  /// What the server last said, as [_fingerprint] reads it: "unchanged".
  String _saved = '';

  Me get _me => widget.session.me!;
  bool get _isNew => widget.id == null;
  String get _status => '${_row?['status'] ?? 'draft'}';

  /// Lines change while the customer has not decided.
  bool get _linesOpen => _isNew || _status == 'draft' || _status == 'sent';

  bool get _canWrite =>
      _isNew ? _me.can('estimates.create') : _me.can('estimates.edit');

  @override
  void initState() {
    super.initState();
    _load();
    // This estimate changed elsewhere (sent, accepted, its lines edited):
    // read again in place, never over unsaved changes or mid-write.
    if (!_isNew) {
      _live = LiveChanges.of(widget.api).subscribe({'estimates'}, (batch) {
        if (batch.reload || batch.changedOf('estimates').contains(widget.id)) {
          return _refresh();
        }
      }, active: LiveChanges.visible(this));
    }
  }

  LiveSubscription? _live;

  Future<void> _refresh() async {
    if (_loading || _busy || _dirty) return;
    final response = await widget.api.get('/estimates/${widget.id}');
    if (!mounted || !response.ok || _busy || _dirty) return;
    setState(() => _take(Map<String, dynamic>.from(response.data as Map)));
  }

  @override
  void dispose() {
    _live?.cancel();
    for (final line in _lines) {
      line.dispose();
    }
    _title.dispose();
    _note.dispose();
    _issued.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final services = widget.api.get('/services', {'per_page': '200'});

    if (_isNew) {
      _issued.text = formatCalendarDay(DateTime.now());
      final places = _me.franchisesInView;
      if (places.length == 1) _franchiseId = places.first.id;
    } else {
      final response = await widget.api.get('/estimates/${widget.id}');
      if (!mounted) return;
      if (!response.ok) {
        setState(() {
          _loading = false;
          _error = response.message;
        });
        return;
      }
      _take(Map<String, dynamic>.from(response.data as Map));
    }

    final catalogue = await services;
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (catalogue.ok && catalogue.data is List) {
        _services = List<Map<String, dynamic>>.from(catalogue.data as List);
      }
    });
  }

  /// The server's copy becomes what is on screen, and what "unchanged" means.
  void _take(Map<String, dynamic> row) {
    for (final line in _lines) {
      line.dispose();
    }
    _lines
      ..clear()
      ..addAll([
        for (final l in (row['lines'] as List? ?? const []))
          if (l is Map)
            _Line(
              serviceId: l['service_id'] as String?,
              name: '${l['name'] ?? ''}',
              description: l['description'] as String?,
              quantity: _quantityText(l['quantity']),
              price: _priceText((l['unit_price_minor'] as num?)?.toInt() ?? 0),
            ),
      ]);
    _title.text = '${row['title'] ?? ''}';
    _note.text = '${row['note'] ?? ''}';
    _issued.text = '${row['issued_on'] ?? ''}';
    _row = row;
    _saved = _fingerprint();
  }

  static String _quantityText(Object? q) {
    final n = (q as num?) ?? 1;
    return n == n.roundToDouble() ? '${n.round()}' : '$n';
  }

  /// "2,400.00": grouped as the amounts beside it are, and read back by
  /// [_Line.unitMinor], which drops the commas.
  static String _priceText(int minor) =>
      '${formatCount(minor ~/ 100)}.${(minor % 100).toString().padLeft(2, '0')}';

  String _fingerprint() => [
    _title.text.trim(),
    _note.text.trim(),
    _issued.text.trim(),
    for (final l in _lines) '${l.name.text.trim()}|${l.qty}|${l.unitMinor}',
  ].join('\n');

  bool get _dirty => !_isNew && _fingerprint() != _saved;

  /// A1 Tools brought some over with a total and no lines; they keep it until
  /// somebody adds lines.
  bool get _imported =>
      !_isNew &&
      _lines.isEmpty &&
      ((_row?['total_minor'] as num?)?.toInt() ?? 0) > 0;

  int get _totalMinor => _imported
      ? (_row!['total_minor'] as num).toInt()
      : _lines.fold(0, (sum, l) => sum + l.amountMinor);

  String _money(int minor) =>
      formatMoney(minor, currency: '${_row?['currency'] ?? 'USD'}');

  void _addLine([Map<String, dynamic>? service]) {
    setState(() {
      _lines.add(
        service == null
            ? _Line()
            : _Line(
                serviceId: service['id'] as String?,
                name: '${service['name'] ?? ''}',
                description: service['description'] as String?,
                price: _priceText(
                  (service['price_minor'] as num?)?.toInt() ?? 0,
                ),
              ),
      );
    });
  }

  void _removeLine(int i) => setState(() => _lines.removeAt(i).dispose());

  String? _trimmed(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();

  /// Everything typed, and a new status if there is one, in one request.
  Future<void> _patch({String? status}) async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.patch('/estimates/${widget.id}', {
      'status': ?status,
      if (_dirty) ...{
        'title': _trimmed(_title),
        'note': _trimmed(_note),
        'issued_on': _trimmed(_issued),
        if (_linesOpen) 'lines': [for (final l in _lines) l.toJson()],
      },
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    setState(() {
      _busy = false;
      _changed = true;
      _take(Map<String, dynamic>.from(response.data as Map));
    });
    widget.onSaved?.call();
  }

  Future<void> _create() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/estimates', {
      'franchise_id': _franchiseId,
      'customer_id': _customer?['id'],
      'job_id': ?_jobId,
      'title': ?_trimmed(_title),
      'note': ?_trimmed(_note),
      'issued_on': ?_trimmed(_issued),
      'lines': [for (final l in _lines) l.toJson()],
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    final id = (response.data as Map?)?['id'];
    if (id is String && widget.onCreated != null) {
      widget.onCreated!(id);
    } else {
      widget.onDone(true);
    }
  }

  Future<void> _delete() async {
    final sure = await showSidePanel<bool>(
      context,
      builder: (context) => SidePanel(
        title: 'Delete ${_row?['number'] ?? 'this draft'}?',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep it'),
          ),
          FilledButton(
            key: const ValueKey('estimate-delete-confirm'),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
              foregroundColor: Theme.of(context).colorScheme.onError,
            ),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Delete'),
          ),
        ],
        child: const Text(
          'Only a draft can be deleted: nobody has been sent it. Anything '
          'further along is archived instead, so its number keeps meaning '
          'something.',
        ),
      ),
    );

    if (sure != true || !mounted) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    final response = await widget.api.delete('/estimates/${widget.id}');
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }
    widget.onDone(true);
  }

  /// The customer's jobs at the chosen franchise, to name one. Jobs has no
  /// customer filter, so the search is by name and the ids decide.
  Future<void> _loadCustomerJobs() async {
    final customer = _customer;
    if (customer == null) return;

    final response = await widget.api.get('/jobs', {
      'when': 'all',
      'search': '${customer['name'] ?? ''}',
      'per_page': '100',
    });
    if (!mounted || !response.ok || response.data is! List) return;
    if (!identical(customer, _customer)) return;

    setState(() {
      _customerJobs = [
        for (final job in (response.data as List).whereType<Map>())
          if ((job['customer'] as Map?)?['id'] == customer['id'])
            Map<String, dynamic>.from(job),
      ];
    });
  }

  /// The jobs a new estimate may name: this customer's, at its franchise.
  List<Map<String, dynamic>> get _jobChoices => [
    for (final job in _customerJobs)
      if ((job['franchise'] as Map?)?['id'] == _franchiseId) job,
  ];

  /// Something typed that is not on the server yet.
  bool get _unsaved => _isNew
      ? _customer != null ||
            _lines.isNotEmpty ||
            _title.text.trim().isNotEmpty ||
            _note.text.trim().isNotEmpty
      : _dirty;

  @override
  Widget build(BuildContext context) {
    final row = _row;
    final job = (row?['job'] as Map?)?['number'];
    final named = row == null ? null : _EstimatesTabState._title(row);
    final who =
        '${row?['number'] ?? ''} · ${(row?['customer'] as Map?)?['name'] ?? ''}';

    // Every edit rebuilds, so this is where the answer is always current.
    // Nothing listens to the notifier -- the list reads it on a tap -- so
    // setting it here schedules no second build.
    widget.dirty?.value = !_loading && _unsaved;

    return SidePanel(
      // Named by its title when it has one, as a job's panel is, with the
      // number and the customer under it; by those two when it has none.
      title: _isNew
          ? 'New estimate'
          : row == null
          ? 'Estimate'
          : named ?? who,
      subtitle: _isNew
          ? 'A draft until it is marked sent.'
          : row == null
          ? null
          : [
              if (named != null) who,
              (row['franchise'] as Map?)?['name'],
              if (job != null) 'for job $job',
            ].whereType<String>().join(' · '),
      docked: true,
      onClose: () => widget.onDone(_changed),
      onBack: widget.inPlace ? () => widget.onDone(_changed) : null,
      actions: _loading ? const [] : _actions(),
      child: _loading
          ? const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            )
          : _body(context),
    );
  }

  Widget _body(BuildContext context) {
    final theme = Theme.of(context);
    final settled = _canWrite && !_busy && _status != 'archived';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_row != null) ...[
          Row(
            children: [
              // The pill the list shows, so an estimate reads the same colour
              // open as it did in its row.
              StatusPill(
                estimateStatus(_status).$1,
                tone: estimateStatus(_status).$2,
              ),
              const SizedBox(width: 16),
              Expanded(child: _steps(context)),
            ],
          ),
          const SizedBox(height: 18),
        ],
        if (_isNew) ...[
          FranchiseField(
            me: _me,
            value: _franchiseId,
            enabled: !_busy,
            onChanged: (v) => setState(() {
              _franchiseId = v;
              _jobId = null;
            }),
          ),
          const SizedBox(height: 14),
          _customerField(),
          if (_jobChoices.isNotEmpty) ...[
            const SizedBox(height: 14),
            SearchPickerField<String>(
              key: const ValueKey('estimate-job'),
              value: _jobId,
              label: 'For a job',
              allowClear: true,
              options: [
                for (final job in _jobChoices)
                  SearchOption(
                    value: '${job['id']}',
                    label: '${job['number']} · ${job['title'] ?? ''}',
                  ),
              ],
              onChanged: _busy ? null : (v) => setState(() => _jobId = v),
            ),
          ],
          const SizedBox(height: 14),
        ],
        TextField(
          key: const ValueKey('estimate-title'),
          controller: _title,
          enabled: settled,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(labelText: 'Title'),
        ),
        const SizedBox(height: 14),
        CalendarDayField(
          controller: _issued,
          formats: _me.display,
          label: 'Issued',
          enabled: settled,
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 18),
        if (_imported)
          Container(
            key: const ValueKey('estimate-imported'),
            padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
            margin: const EdgeInsets.only(bottom: 12),
            decoration: BoxDecoration(
              color: ToneColours.of(context, Tone.caution).fill,
              borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
            ),
            // The second sentence only where lines can still be added: on
            // a decided estimate it would offer what the server refuses.
            child: Text(
              _linesOpen
                  ? 'Brought over from A1 Tools as a total with no lines. '
                        'Adding lines replaces the total with their sum.'
                  : 'Brought over from A1 Tools as a total with no lines.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: ToneColours.of(context, Tone.caution).ink,
              ),
            ),
          ),
        _linesEditor(context),
        Container(
          margin: const EdgeInsets.only(top: 8),
          padding: const EdgeInsets.only(top: 10),
          decoration: BoxDecoration(
            border: Border(
              top: BorderSide(color: theme.colorScheme.outlineVariant),
            ),
          ),
          child: Row(
            children: [
              Text(
                'Total',
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const Spacer(),
              Text(
                _money(_totalMinor),
                key: const ValueKey('estimate-total'),
                style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        TextField(
          key: const ValueKey('estimate-note'),
          controller: _note,
          enabled: settled,
          minLines: 1,
          maxLines: 5,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(labelText: 'Note for the customer'),
        ),
        if (_error != null) ...[const SizedBox(height: 12), ErrorNote(_error!)],
      ],
    );
  }

  /// Draft, sent, and how it ended: where this one stands.
  Widget _steps(BuildContext context) {
    final theme = Theme.of(context);
    final (ended, endTone) = estimateStatus(
      _status == 'declined' ? 'declined' : 'accepted',
    );
    final reached = switch (_status) {
      'draft' => 0,
      'sent' => 1,
      'accepted' || 'declined' => 2,
      // Archived: it was decided if somebody accepted it.
      _ => _row?['accepted_at'] != null ? 2 : 1,
    };
    final steps = [
      ('Draft', Tone.neutral),
      ('Sent', Tone.info),
      (ended, endTone),
    ];

    // Scaled down rather than wrapped or cut when a phone is too narrow for
    // it: three words and two rules read as one line or not at all.
    return FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerLeft,
      child: Row(
        key: const ValueKey('estimate-steps'),
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < steps.length; i++) ...[
            if (i > 0)
              Container(
                width: 28,
                height: 2,
                margin: const EdgeInsets.symmetric(horizontal: 6),
                color: i <= reached
                    ? ToneColours.of(context, steps[i].$2).ink
                    : theme.colorScheme.outlineVariant,
              ),
            Icon(
              i <= reached ? Icons.check_circle : Icons.circle_outlined,
              size: 14,
              color: i <= reached
                  ? ToneColours.of(context, steps[i].$2).ink
                  : theme.colorScheme.outline,
            ),
            const SizedBox(width: 4),
            Text(
              steps[i].$1,
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: i == reached ? FontWeight.w800 : FontWeight.w500,
                color: i <= reached
                    ? theme.colorScheme.onSurface
                    : theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _customerField() => Autocomplete<Map<String, dynamic>>(
    displayStringForOption: (c) =>
        [c['name'], c['number']].whereType<String>().join(' · '),
    optionsBuilder: (value) async {
      final term = value.text.trim();
      if (term.length < 2) return const <Map<String, dynamic>>[];
      final response = await widget.api.get('/customers', {
        'search': term,
        'per_page': '20',
      });
      if (!response.ok || response.data is! List) {
        return const <Map<String, dynamic>>[];
      }
      return List<Map<String, dynamic>>.from(response.data as List);
    },
    onSelected: (c) {
      setState(() {
        _customer = c;
        _customerJobs = const [];
        _jobId = null;
      });
      _loadCustomerJobs();
    },
    fieldViewBuilder: (context, controller, focusNode, onSubmit) => TextField(
      key: const ValueKey('estimate-customer'),
      controller: controller,
      focusNode: focusNode,
      enabled: !_busy,
      decoration: InputDecoration(
        labelText: 'Customer',
        helperText: _customer == null ? 'Type a name, phone or number.' : null,
        suffixIcon: Icon(
          _customer == null ? Icons.search : Icons.check_circle,
          size: 20,
        ),
      ),
      // Typing again un-chooses the customer: what is in the box is who it
      // is for, or nobody.
      onChanged: (_) {
        if (_customer == null) return;
        setState(() {
          _customer = null;
          _customerJobs = const [];
          _jobId = null;
        });
      },
    ),
  );

  // Qty, Price, Amount and the bin take ~360 px, so under 560 the Line field
  // was squeezed to one character; a narrow panel gets the card layout too.
  Widget _linesEditor(BuildContext context) => LayoutBuilder(
    builder: (context, box) =>
        _linesBody(context, cards: widget.inPlace || box.maxWidth < 560),
  );

  Widget _linesBody(BuildContext context, {required bool cards}) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final editable = _canWrite && _linesOpen && !_busy;

    Widget field(
      String key,
      TextEditingController controller, {
      String? hint,
      bool number = false,
      String? prefix,
    }) => TextField(
      key: ValueKey(key),
      controller: controller,
      enabled: editable,
      textAlign: number ? TextAlign.right : TextAlign.start,
      keyboardType: number
          ? const TextInputType.numberWithOptions(decimal: true)
          : null,
      // A table's cells, not a form's boxes: the theme's 16px padding made
      // every line 56px tall.
      decoration: InputDecoration(
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 10,
          vertical: 10,
        ),
        hintText: hint,
        prefixText: prefix,
      ),
      onChanged: (_) => setState(() {}),
    );

    Widget line(int i) {
      final l = _lines[i];
      final name = field('estimate-line-name-$i', l.name, hint: 'What it is');
      final qty = SizedBox(
        width: 64,
        child: field('estimate-line-qty-$i', l.quantity, number: true),
      );
      final price = SizedBox(
        width: 128,
        child: field(
          'estimate-line-price-$i',
          l.price,
          number: true,
          prefix: r'$ ',
        ),
      );
      final amount = Text(
        _money(l.amountMinor),
        key: ValueKey('estimate-line-amount-$i'),
        textAlign: TextAlign.right,
        style: const TextStyle(fontWeight: FontWeight.w700),
      );
      final remove = IconButton(
        key: ValueKey('estimate-line-remove-$i'),
        tooltip: 'Remove this line',
        visualDensity: VisualDensity.compact,
        icon: const Icon(Icons.close, size: 18),
        onPressed: editable ? () => _removeLine(i) : null,
      );

      // A phone: each line a card, the name over its numbers.
      if (cards) {
        return Container(
          margin: const EdgeInsets.only(bottom: 8),
          padding: const EdgeInsets.fromLTRB(12, 10, 4, 10),
          decoration: BoxDecoration(
            border: Border.all(color: theme.colorScheme.outlineVariant),
            borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(child: name),
                  if (editable) remove else const SizedBox(width: 8),
                ],
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  qty,
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    child: Text('×', style: TextStyle(color: muted)),
                  ),
                  price,
                  const SizedBox(width: 8),
                  Expanded(child: amount),
                  const SizedBox(width: 8),
                ],
              ),
            ],
          ),
        );
      }

      return Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          children: [
            Expanded(child: name),
            const SizedBox(width: 8),
            qty,
            const SizedBox(width: 8),
            price,
            const SizedBox(width: 8),
            SizedBox(width: 100, child: amount),
            if (editable) remove else const SizedBox(width: 40),
          ],
        ),
      );
    }

    final head = theme.textTheme.labelSmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: muted,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_lines.isEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              _imported ? 'No lines.' : 'No lines yet.',
              style: theme.textTheme.bodyMedium?.copyWith(color: muted),
            ),
          )
        else if (!cards)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              children: [
                Expanded(child: Text('Line', style: head)),
                const SizedBox(width: 8),
                SizedBox(
                  width: 64,
                  child: Text('Qty', style: head, textAlign: TextAlign.right),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 128,
                  child: Text('Price', style: head, textAlign: TextAlign.right),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 100,
                  child: Text(
                    'Amount',
                    style: head,
                    textAlign: TextAlign.right,
                  ),
                ),
                const SizedBox(width: 40),
              ],
            ),
          ),
        for (var i = 0; i < _lines.length; i++) line(i),
        if (editable)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              OutlinedButton.icon(
                key: const ValueKey('estimate-line-add'),
                onPressed: _addLine,
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add a line'),
              ),
              if (_services.isNotEmpty)
                SizedBox(
                  width: 260,
                  child: SearchPickerField<String>(
                    key: const ValueKey('estimate-line-service'),
                    value: null,
                    isDense: true,
                    hint: 'Or add a service',
                    options: [
                      for (final s in _services)
                        SearchOption(
                          value: '${s['id']}',
                          label: '${s['name'] ?? ''}',
                          detail: s['price_minor'] is num
                              ? formatMoney((s['price_minor'] as num).toInt())
                              : null,
                        ),
                    ],
                    onChanged: (id) {
                      final service = _services
                          .where((s) => '${s['id']}' == id)
                          .firstOrNull;
                      if (service != null) _addLine(service);
                    },
                  ),
                ),
            ],
          ),
      ],
    );
  }

  /// The next steps NEXT allows from here, the likeliest last.
  List<Widget> _actions() {
    final canEdit = _me.can('estimates.edit');
    final busy = _busy;

    Widget quiet(String key, String label, VoidCallback onPressed) =>
        TextButton(
          key: ValueKey(key),
          onPressed: busy ? null : onPressed,
          child: Text(label),
        );
    Widget plain(String key, String label, VoidCallback onPressed) =>
        OutlinedButton(
          key: ValueKey(key),
          onPressed: busy ? null : onPressed,
          child: Text(label),
        );
    Widget loud(String key, String label, VoidCallback? onPressed) =>
        FilledButton(
          key: ValueKey(key),
          onPressed: busy ? null : onPressed,
          child: Text(label),
        );

    if (_isNew) {
      final ready = _canWrite && _franchiseId != null && _customer != null;
      return [
        quiet('estimate-cancel', 'Cancel', () => widget.onDone(false)),
        loud('estimate-create', 'Create draft', ready ? _create : null),
      ];
    }

    if (!canEdit) return const [];

    final save = [if (_dirty) plain('estimate-save', 'Save', () => _patch())];
    void to(String status) => _patch(status: status);

    return switch (_status) {
      'draft' => [
        if (_me.can('estimates.delete'))
          quiet('estimate-delete', 'Delete', _delete),
        ...save,
        loud('estimate-send', 'Mark sent', () => to('sent')),
      ],
      'sent' => [
        quiet('estimate-archive', 'Archive', () => to('archived')),
        ...save,
        plain('estimate-decline', 'Declined', () => to('declined')),
        loud('estimate-accept', 'Mark accepted', () => to('accepted')),
      ],
      'accepted' || 'declined' => [
        ...save,
        plain('estimate-archive', 'Archive', () => to('archived')),
      ],
      _ => const [],
    };
  }
}
