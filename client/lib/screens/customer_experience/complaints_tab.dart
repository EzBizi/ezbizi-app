import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/deep_links.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/load_more.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';

/// Customer Experience › Complaints (Jose, 2026-10-20): every bad review, bad
/// rating and complaint call, and how far each got. UI queue: option 1 (a
/// table like Task Board, Board one click away) and phone 4 (cards, tap opens
/// in place).
///
/// The customer's name and words are read from the review or lead each time,
/// so a customer erased there arrives here with neither: the row says
/// "Removed at the source" rather than drawing a blank.
///
/// Anyone with `customer_experience.complaints.view` reads; changing the
/// status, the owner or the notes is `.manage`, and without it those are text,
/// not controls that answer 403.
class ComplaintsTab extends StatefulWidget {
  const ComplaintsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Narrower than this the rows are cards and an opened one replaces the
  /// list, with a back arrow (phone 4).
  static const double narrowBelow = 760;

  static const int perPage = 50;

  @override
  State<ComplaintsTab> createState() => _ComplaintsTabState();
}

/// Where a complaint has got to, as the server stores it.
const complaintStatuses = {
  'new': ('New', Tone.caution),
  'in_progress': ('In progress', Tone.info),
  'solved': ('Solved', Tone.ok),
  'not_solvable': ("Can't be solved", Tone.neutral),
};

/// The segment across the top: Open is New + In progress.
const _segments = [
  ('open', 'Open'),
  ('new', 'New'),
  ('in_progress', 'In progress'),
  ('solved', 'Solved'),
  ('not_solvable', "Can't be solved"),
  ('all', 'All'),
];

const _kinds = {
  'bad_review': 'Bad review',
  'bad_rating': 'Bad rating',
  'call_complaint': 'Complaint call',
};

const _removed = 'Removed at the source';
const _nameless = 'No name given';

class _ComplaintsTabState extends State<ComplaintsTab> {
  bool _loading = true;
  bool _loadingMore = false;
  String? _error;
  String? _moreError;
  String? _notice;
  bool _busy = false;

  List<Map<String, dynamic>> _rows = [];
  Map<String, int> _counts = {};
  List<Map<String, dynamic>> _people = [];
  int _total = 0;
  int _page = 1;
  int _request = 0;

  String _status = 'open';
  String? _kind;
  String? _owner;
  String? _branch;
  int? _days;
  String _q = '';
  bool _board = false;

  /// The one column a phone's board shows.
  String _boardStatus = 'new';

  Map<String, dynamic>? _open;
  final _note = TextEditingController();
  final _search = TextEditingController();
  Timer? _debounce;

  bool _can(String key) => widget.session.me?.can(key) ?? false;

  bool get _manage => _can('customer_experience.complaints.manage');

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _note.dispose();
    _search.dispose();
    super.dispose();
  }

  static List<Map<String, dynamic>> _maps(Object? list) => [
    for (final m in list as List? ?? const [])
      if (m is Map) m.cast<String, dynamic>(),
  ];

  static String _ymd(DateTime d) =>
      '${d.year}-${'${d.month}'.padLeft(2, '0')}-${'${d.day}'.padLeft(2, '0')}';

  Map<String, String> _query(int page) => {
    // The board is a column per status, so it asks for every status.
    'status': _board ? 'all' : _status,
    'kind': ?_kind,
    'owner': ?_owner,
    'branch': ?_branch,
    if (_days != null)
      'from': _ymd(DateTime.now().subtract(Duration(days: _days!))),
    if (_q.isNotEmpty) 'q': _q,
    'per_page': '${ComplaintsTab.perPage}',
    if (page > 1) 'page': '$page',
  };

  Future<void> _load({bool more = false}) async {
    final request = ++_request;
    final page = more ? _page + 1 : 1;

    setState(() {
      if (more) {
        _loadingMore = true;
        _moreError = null;
      } else {
        _loading = _rows.isEmpty;
        _error = null;
      }
    });

    final response = await widget.api.get('/complaints', _query(page));
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _loadingMore = false;
      if (!response.ok) {
        if (more) {
          _moreError = response.message;
        } else {
          _error = response.message;
        }
        return;
      }

      final rows = _maps(response.data);
      final meta = (response.body['meta'] as Map?) ?? const {};
      _rows = more ? [..._rows, ...rows] : rows;
      _page = page;
      _total = (meta['total'] as num?)?.toInt() ?? _rows.length;
      _counts = {
        for (final e in ((meta['counts'] as Map?) ?? const {}).entries)
          '${e.key}': (e.value as num?)?.toInt() ?? 0,
      };
      _people = _maps(meta['people']);
      final open = _open;
      if (open != null) {
        final fresh = _rows.where((r) => r['id'] == open['id']);
        if (fresh.isNotEmpty) _open = {...open, ...fresh.first};
      }
    });
  }

  void _refilter(VoidCallback change) {
    setState(change);
    _load();
  }

  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted || text.trim() == _q) return;
      _q = text.trim();
      _load();
    });
  }

  // ------------------------------------------------------------- writes

  Future<void> _openRow(Map<String, dynamic> row) async {
    setState(() {
      _open = row;
      _note.clear();
    });
    final response = await widget.api.get('/complaints/${row['id']}');
    if (!mounted || _open?['id'] != row['id']) return;
    _take(response);
  }

  /// Every write answers with the same body as GET /complaints/{id}.
  void _take(ApiResponse response) {
    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }
    final data = response.data;
    if (data is Map<String, dynamic> && data['id'] == _open?['id']) {
      setState(() => _open = {..._open!, ...data});
    }
  }

  Future<void> _write(Future<ApiResponse> call) async {
    setState(() => _busy = true);
    final response = await call;
    if (!mounted) return;
    setState(() => _busy = false);
    _take(response);
    if (response.ok) await _load();
  }

  /// A card dropped on another column of the board.
  Future<void> _move(Map<String, dynamic> row, String status) async {
    if (row['status'] == status) return;
    setState(() => _busy = true);
    final response = await widget.api.patch('/complaints/${row['id']}', {
      'status': status,
    });
    if (!mounted) return;
    setState(() => _busy = false);
    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }
    if (_open?['id'] == row['id']) _take(response);
    await _load();
  }

  // ------------------------------------------------------------- words

  /// The name, else the caller's number (most complaint calls give no name),
  /// else null - and null means "Removed at the source" only when the server
  /// says the review or lead is gone (desk, 2026-10-20).
  static String? _title(Map<String, dynamic> row) {
    final name = (row['title'] as String?)?.trim() ?? '';
    if (name.isNotEmpty) return name;
    final phone = (row['phone'] as String?)?.trim() ?? '';
    if (phone.isNotEmpty) return formatPhoneNumber(phone);
    return row['source_missing'] == true ? null : _nameless;
  }

  static String? _summary(Map<String, dynamic> row) =>
      (row['summary'] as String?)?.trim().isNotEmpty == true
      ? (row['summary'] as String).trim()
      : null;

  /// Gone only when the server says the review or lead row no longer exists;
  /// a caller with no name and no summary is still there to open.
  static bool _gone(Map<String, dynamic> row) => row['source_missing'] == true;

  static String _kindText(Map<String, dynamic> row) => switch (row['kind']) {
    'bad_review' =>
      row['rating'] == null ? 'Review' : '★${row['rating']} Review',
    'bad_rating' => 'Rating',
    'call_complaint' => 'Call',
    _ => '${row['kind'] ?? ''}',
  };

  static IconData _kindIcon(Map<String, dynamic> row) => switch (row['kind']) {
    'bad_review' => Icons.reviews_outlined,
    'bad_rating' => Icons.sentiment_dissatisfied_outlined,
    'call_complaint' => Icons.phone_outlined,
    _ => Icons.report_outlined,
  };

  static String? _branchOf(Map<String, dynamic> row) =>
      (row['branch'] as Map?)?['name'] as String?;

  static String? _ownerOf(Map<String, dynamic> row) =>
      (row['owner'] as Map?)?['name'] as String?;

  String _came(Map<String, dynamic> row) => formatListStamp(
    row['raised_at'],
    '—',
    formats: widget.session.me!.display,
  );

  String _stamp(Object? iso) =>
      formatStamp(iso, '—', formats: widget.session.me!.display);

  Widget _pill(Map<String, dynamic> row) {
    final s = complaintStatuses[row['status']];
    return s == null ? const SizedBox.shrink() : StatusPill(s.$1, tone: s.$2);
  }

  Widget _kindLine(ThemeData theme, Map<String, dynamic> row) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(_kindIcon(row), size: 15, color: theme.colorScheme.onSurfaceVariant),
      const SizedBox(width: 4),
      Flexible(
        child: Text(
          _kindText(row),
          style: theme.textTheme.bodySmall,
          overflow: TextOverflow.ellipsis,
        ),
      ),
    ],
  );

  Widget _from(ThemeData theme, Map<String, dynamic> row, {TextStyle? style}) {
    final title = _title(row);
    if (title != null) {
      return Text(
        title,
        style: style ?? theme.textTheme.titleSmall,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      );
    }
    return Text(
      _removed,
      style: (style ?? theme.textTheme.titleSmall)?.copyWith(
        fontStyle: FontStyle.italic,
        color: theme.colorScheme.onSurfaceVariant,
      ),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );
  }

  Widget _notes(ThemeData theme, Map<String, dynamic> row) {
    final n = (row['note_count'] as num?)?.toInt() ?? 0;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(
          Icons.chat_bubble_outline,
          size: 14,
          color: theme.colorScheme.onSurfaceVariant,
        ),
        const SizedBox(width: 4),
        Text('$n', style: theme.textTheme.bodySmall),
      ],
    );
  }

  // ------------------------------------------------------------- the frame

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        final narrow = box.maxWidth < ComplaintsTab.narrowBelow;
        final open = _open;

        // Phone 4: an opened complaint replaces the list, with a back arrow.
        if (narrow && open != null) return _pane(theme, open, inPlace: true);

        final list = Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _header(theme, narrow),
            _filters(theme),
            if (_notice != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(_notice!, style: theme.textTheme.bodySmall),
                    ),
                    IconButton(
                      tooltip: 'Dismiss',
                      icon: const Icon(Icons.close, size: 16),
                      onPressed: () => setState(() => _notice = null),
                    ),
                  ],
                ),
              ),
            const Divider(height: 1),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _body(theme, narrow),
              ),
            ),
          ],
        );

        if (open == null) return list;

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: list),
            const VerticalDivider(width: 1),
            SizedBox(width: 380, child: _pane(theme, open, inPlace: false)),
          ],
        );
      },
    );
  }

  Widget _header(ThemeData theme, bool narrow) {
    final Widget left;
    if (!_board) {
      left = SingleChildScrollView(
        key: const ValueKey('tour-complaints-status'),
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            for (final (key, label) in _segments)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ChoiceChip(
                  key: ValueKey('complaints-status-$key'),
                  showCheckmark: false,
                  label: Text(
                    key == 'all' ? label : '$label · ${_counts[key] ?? 0}',
                  ),
                  selected: _status == key,
                  onSelected: (_) => _refilter(() => _status = key),
                ),
              ),
          ],
        ),
      );
    } else if (narrow) {
      // A phone's board is one column at a time, chosen here.
      left = Align(
        alignment: Alignment.centerLeft,
        child: SizedBox(
          width: 220,
          child: SearchPickerField<String>(
            key: const ValueKey('complaints-board-status'),
            value: _boardStatus,
            isDense: true,
            options: [
              for (final e in complaintStatuses.entries)
                SearchOption(
                  value: e.key,
                  label: '${e.value.$1} · ${_counts[e.key] ?? 0}',
                ),
            ],
            onChanged: (v) => setState(() => _boardStatus = v ?? 'new'),
          ),
        ),
      );
    } else {
      left = const SizedBox.shrink();
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: Row(
        children: [
          Expanded(child: left),
          const SizedBox(width: 12),
          SegmentedButton<bool>(
            key: const ValueKey('tour-complaints-view'),
            showSelectedIcon: false,
            segments: [
              ButtonSegment(
                value: false,
                icon: const Icon(Icons.table_rows_outlined, size: 18),
                label: narrow ? null : const Text('Table'),
                tooltip: narrow ? 'Table' : null,
              ),
              ButtonSegment(
                value: true,
                icon: const Icon(Icons.view_column_outlined, size: 18),
                label: narrow ? null : const Text('Board'),
                tooltip: narrow ? 'Board' : null,
              ),
            ],
            selected: {_board},
            onSelectionChanged: (v) => _refilter(() => _board = v.single),
          ),
        ],
      ),
    );
  }

  Widget _picker<T>({
    required Key key,
    required T value,
    required List<SearchOption<T>> options,
    required ValueChanged<T> onChanged,
    double width = 150,
  }) => SizedBox(
    width: width,
    child: SearchPickerField<T>(
      key: key,
      value: value,
      border: InputBorder.none,
      isDense: true,
      options: options,
      onChanged: (v) => _refilter(() => onChanged(v as T)),
    ),
  );

  Widget _filters(ThemeData theme) {
    final branches = widget.session.me?.franchisesInView ?? const [];
    final people = _people;

    return Padding(
      key: const ValueKey('tour-complaints-filters'),
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
      child: Wrap(
        spacing: 12,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          _picker<String?>(
            key: const ValueKey('complaints-kind'),
            value: _kind,
            options: [
              const SearchOption(value: null, label: 'Any kind'),
              for (final e in _kinds.entries)
                SearchOption(value: e.key, label: e.value),
            ],
            onChanged: (v) => _kind = v,
          ),
          _picker<String?>(
            key: const ValueKey('complaints-owner'),
            value: _owner,
            width: 160,
            options: [
              const SearchOption(value: null, label: 'Anyone'),
              const SearchOption(value: 'none', label: 'Nobody'),
              for (final p in people)
                SearchOption(value: '${p['id']}', label: '${p['name']}'),
            ],
            onChanged: (v) => _owner = v,
          ),
          if (branches.isNotEmpty)
            _picker<String?>(
              key: const ValueKey('complaints-branch'),
              value: _branch,
              width: 180,
              options: [
                const SearchOption(value: null, label: 'All branches'),
                for (final b in branchOptionsInView(widget.session.me!))
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: (v) => _branch = v,
            ),
          _picker<int?>(
            key: const ValueKey('complaints-dates'),
            value: _days,
            width: 150,
            options: const [
              SearchOption(value: null, label: 'All time'),
              SearchOption(value: 7, label: 'Last 7 days'),
              SearchOption(value: 30, label: 'Last 30 days'),
              SearchOption(value: 90, label: 'Last 90 days'),
              SearchOption(value: 365, label: 'Last 12 months'),
            ],
            onChanged: (v) => _days = v,
          ),
          SizedBox(
            width: 240,
            child: TextField(
              key: const ValueKey('complaints-search'),
              controller: _search,
              onChanged: _searched,
              decoration: const InputDecoration(
                isDense: true,
                prefixIcon: Icon(Icons.search, size: 18),
                hintText: 'Name or what they said',
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _body(ThemeData theme, bool narrow) {
    if (_board) return narrow ? _boardColumn(theme) : _boardView(theme);
    if (_rows.isEmpty) return _empty();
    return narrow ? _cards(theme) : _table(theme);
  }

  bool get _filtered =>
      _kind != null ||
      _owner != null ||
      _branch != null ||
      _days != null ||
      _q.isNotEmpty;

  /// Each status says its own nothing; filters say they are the reason.
  Widget _empty() {
    final (title, message) = switch (_status) {
      'new' => (
        'Nothing new',
        'No complaint is waiting for somebody to pick it up.',
      ),
      'in_progress' => (
        'Nothing in progress',
        'Nobody is working on a complaint right now.',
      ),
      'solved' => (
        'Nothing solved yet',
        'Complaints marked Solved are listed here.',
      ),
      'not_solvable' => (
        "Nothing marked can't be solved",
        "Complaints that could not be put right are listed here.",
      ),
      'all' => (
        'No complaints',
        'Bad reviews, bad ratings and complaint calls arrive here on their own.',
      ),
      _ => (
        'No open complaints',
        'Nothing is new or in progress. Solved ones are under Solved.',
      ),
    };

    return EmptyState(
      key: const ValueKey('tour-complaints-empty'),
      icon: Icons.sentiment_satisfied_outlined,
      title: title,
      message: _filtered ? 'None match these filters.' : message,
    );
  }

  Widget? _more() => _rows.length < _total
      ? Padding(
          padding: const EdgeInsets.symmetric(vertical: 12),
          child: LoadMore(
            key: const ValueKey('complaints-more'),
            shown: _rows.length,
            total: _total,
            busy: _loadingMore,
            error: _moreError,
            onMore: () => _load(more: true),
          ),
        )
      : null;

  // ------------------------------------------------------------- the table

  Widget _table(ThemeData theme) {
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w800,
    );
    final small = theme.textTheme.bodySmall;
    final more = _more();

    Widget cell(int flex, Widget child) => Expanded(
      flex: flex,
      child: Padding(padding: const EdgeInsets.only(right: 10), child: child),
    );

    return ListView.separated(
      key: const ValueKey('tour-complaints-list'),
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: _rows.length + 1 + (more == null ? 0 : 1),
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == 0) {
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Row(
              children: [
                cell(2, Text('CAME IN', style: head)),
                cell(2, Text('KIND', style: head)),
                cell(3, Text('FROM', style: head)),
                cell(5, Text('WHAT THEY SAID', style: head)),
                cell(2, Text('BRANCH', style: head)),
                cell(2, Text('OWNER', style: head)),
                cell(2, Text('STATUS', style: head)),
                cell(1, Text('NOTES', style: head)),
              ],
            ),
          );
        }
        if (index > _rows.length) return more!;

        final row = _rows[index - 1];
        final selected = _open?['id'] == row['id'];

        return InkWell(
          key: ValueKey('complaint-row-${row['id']}'),
          onTap: () => _openRow(row),
          child: Container(
            color: selected ? theme.colorScheme.primaryContainer : null,
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                cell(2, Text(_came(row), style: small)),
                cell(2, _kindLine(theme, row)),
                cell(3, _from(theme, row)),
                cell(
                  5,
                  Text(
                    _summary(row) ?? '',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: small,
                  ),
                ),
                cell(
                  2,
                  Text(
                    _branchOf(row) ?? '—',
                    style: small,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                cell(
                  2,
                  Text(
                    _ownerOf(row) ?? 'Nobody',
                    style: small,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                cell(
                  2,
                  Align(alignment: Alignment.centerLeft, child: _pill(row)),
                ),
                cell(1, _notes(theme, row)),
              ],
            ),
          ),
        );
      },
    );
  }

  // ------------------------------------------------------------- phone list

  Widget _card(ThemeData theme, Map<String, dynamic> row) {
    final branch = _branchOf(row);
    final summary = _summary(row);

    return InkWell(
      key: ValueKey('complaint-row-${row['id']}'),
      onTap: () => _openRow(row),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: _from(theme, row)),
                const SizedBox(width: 8),
                _pill(row),
              ],
            ),
            const SizedBox(height: 4),
            Row(
              children: [
                Flexible(child: _kindLine(theme, row)),
                if (branch != null)
                  Flexible(
                    child: Text(
                      ' · $branch',
                      style: theme.textTheme.bodySmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                const Spacer(),
                _notes(theme, row),
              ],
            ),
            if (summary != null) ...[
              const SizedBox(height: 4),
              Text(
                summary,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _cards(ThemeData theme) {
    final more = _more();
    return ListView.separated(
      key: const ValueKey('tour-complaints-list'),
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: _rows.length + (more == null ? 0 : 1),
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) =>
          index == _rows.length ? more! : _card(theme, _rows[index]),
    );
  }

  // ------------------------------------------------------------- the board

  Widget _boardCard(ThemeData theme, Map<String, dynamic> row) {
    final card = Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        onTap: () => _openRow(row),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _from(theme, row),
              const SizedBox(height: 4),
              Row(
                children: [
                  Flexible(child: _kindLine(theme, row)),
                  const Spacer(),
                  _notes(theme, row),
                ],
              ),
              if (_summary(row) != null) ...[
                const SizedBox(height: 4),
                Text(
                  _summary(row)!,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall,
                ),
              ],
              if (_branchOf(row) != null || _ownerOf(row) != null) ...[
                const SizedBox(height: 4),
                Text(
                  [?_branchOf(row), ?_ownerOf(row)].join(' · '),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ],
          ),
        ),
      ),
    );

    // Dragging moves the status, so it is offered only to who may move it.
    if (!_manage) {
      return KeyedSubtree(
        key: ValueKey('complaint-card-${row['id']}'),
        child: card,
      );
    }
    return Draggable<Map<String, dynamic>>(
      key: ValueKey('complaint-card-${row['id']}'),
      data: row,
      feedback: Material(
        color: Colors.transparent,
        child: SizedBox(width: 240, child: card),
      ),
      childWhenDragging: Opacity(opacity: 0.4, child: card),
      child: card,
    );
  }

  Widget _column(ThemeData theme, String status, {bool header = true}) {
    final cards = [
      for (final r in _rows)
        if (r['status'] == status) r,
    ];
    final label = complaintStatuses[status]!.$1;

    return DragTarget<Map<String, dynamic>>(
      key: ValueKey('complaints-column-$status'),
      onWillAcceptWithDetails: (d) => _manage && d.data['status'] != status,
      onAcceptWithDetails: (d) => _move(d.data, status),
      builder: (context, candidate, _) => Container(
        decoration: BoxDecoration(
          color: candidate.isNotEmpty
              ? theme.colorScheme.primaryContainer
              : theme.colorScheme.surfaceContainerLow,
          borderRadius: BorderRadius.circular(10),
        ),
        padding: const EdgeInsets.all(8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (header)
              Padding(
                padding: const EdgeInsets.fromLTRB(4, 2, 4, 8),
                child: Text(
                  '$label · ${_counts[status] ?? cards.length}',
                  style: theme.textTheme.titleSmall,
                ),
              ),
            Expanded(
              child: cards.isEmpty
                  ? Center(
                      child: Text(
                        'Nothing here',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    )
                  : ListView(
                      children: [for (final r in cards) _boardCard(theme, r)],
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _boardView(ThemeData theme) {
    final more = _more();
    return Padding(
      key: const ValueKey('tour-complaints-list'),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final (i, status) in complaintStatuses.keys.indexed) ...[
                  if (i > 0) const SizedBox(width: 10),
                  Expanded(child: _column(theme, status)),
                ],
              ],
            ),
          ),
          ?more,
        ],
      ),
    );
  }

  Widget _boardColumn(ThemeData theme) {
    final more = _more();
    return Padding(
      key: const ValueKey('tour-complaints-list'),
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(child: _column(theme, _boardStatus, header: false)),
          ?more,
        ],
      ),
    );
  }

  // ------------------------------------------------------------- the pane

  Widget _section(ThemeData theme, String label) => Padding(
    padding: const EdgeInsets.only(top: 16, bottom: 6),
    child: Text(
      label.toUpperCase(),
      style: theme.textTheme.labelSmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.6,
      ),
    ),
  );

  Widget _pane(
    ThemeData theme,
    Map<String, dynamic> row, {
    required bool inPlace,
  }) {
    final manage = _manage;
    final small = theme.textTheme.bodySmall;
    final notes = _maps(row['notes']);
    final owner = row['owner'] as Map?;
    final summary = _summary(row);

    return Material(
      key: const ValueKey('complaint-pane'),
      color: theme.colorScheme.surface,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Row(
            children: [
              IconButton(
                key: const ValueKey('complaint-pane-close'),
                tooltip: inPlace ? 'Back to the complaints' : 'Close',
                icon: Icon(inPlace ? Icons.arrow_back : Icons.close),
                onPressed: () => setState(() => _open = null),
              ),
              Expanded(
                child: _from(theme, row, style: theme.textTheme.titleMedium),
              ),
            ],
          ),
          Wrap(
            spacing: 10,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              _kindLine(theme, row),
              if (_branchOf(row) != null) Text(_branchOf(row)!, style: small),
              _pill(row),
            ],
          ),
          const SizedBox(height: 6),
          Text('Came in ${_stamp(row['raised_at'])}', style: small),
          if (row['solved_at'] != null)
            Text('Closed ${_stamp(row['solved_at'])}', style: small),
          const SizedBox(height: 10),
          Text(
            summary ??
                (_gone(row)
                    ? 'The review or lead this came from was erased, so what '
                          'they said is no longer kept.'
                    : 'Nothing written.'),
            style: theme.textTheme.bodyMedium,
          ),

          _section(theme, 'Status'),
          if (manage)
            Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: 220,
                child: SearchPickerField<String>(
                  key: const ValueKey('complaint-status'),
                  value: row['status'] as String?,
                  isDense: true,
                  options: [
                    for (final e in complaintStatuses.entries)
                      SearchOption(value: e.key, label: e.value.$1),
                  ],
                  onChanged: _busy
                      ? null
                      : (v) {
                          if (v == null || v == row['status']) return;
                          _write(
                            widget.api.patch('/complaints/${row['id']}', {
                              'status': v,
                            }),
                          );
                        },
                ),
              ),
            )
          else
            Align(alignment: Alignment.centerLeft, child: _pill(row)),

          _section(theme, 'Owner'),
          if (manage)
            Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: 220,
                child: SearchPickerField<String?>(
                  key: const ValueKey('complaint-owner'),
                  value: owner?['id'] as String?,
                  isDense: true,
                  hint: 'Nobody',
                  options: [
                    const SearchOption(value: null, label: 'Nobody'),
                    for (final p in _people)
                      SearchOption(value: '${p['id']}', label: '${p['name']}'),
                    if (owner != null &&
                        !_people.any((p) => p['id'] == owner['id']))
                      SearchOption(
                        value: '${owner['id']}',
                        label: '${owner['name']}',
                      ),
                  ],
                  onChanged: _busy
                      ? null
                      : (v) {
                          if (v == owner?['id']) return;
                          _write(
                            widget.api.patch('/complaints/${row['id']}', {
                              'owner_user_id': v,
                            }),
                          );
                        },
                ),
              ),
            )
          else
            Text(_ownerOf(row) ?? 'Nobody', style: theme.textTheme.bodyMedium),

          _section(theme, 'Notes'),
          if (notes.isEmpty)
            Text('No notes yet.', style: small)
          else
            for (final n in notes)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(
                  '${_stamp(n['at'])}'
                  '${n['by'] == null ? '' : ' · ${n['by']}'}: ${n['text']}',
                  style: small,
                ),
              ),
          if (manage) ...[
            TextField(
              key: const ValueKey('complaint-note'),
              controller: _note,
              minLines: 1,
              maxLines: 4,
              maxLength: 4000,
              decoration: const InputDecoration(
                isDense: true,
                labelText: 'Add a note',
              ),
              onChanged: (_) => setState(() {}),
            ),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                key: const ValueKey('complaint-note-add'),
                onPressed: _busy || _note.text.trim().isEmpty
                    ? null
                    : () {
                        final text = _note.text.trim();
                        _note.clear();
                        _write(
                          widget.api.post('/complaints/${row['id']}/notes', {
                            'text': text,
                          }),
                        );
                      },
                child: const Text('Add note'),
              ),
            ),
          ],
          ?_source(row),
        ],
      ),
    );
  }

  /// Where it came from, by the app's own ways there. Left out when the source
  /// is gone, or when this person could not open it anyway.
  Widget? _source(Map<String, dynamic> row) {
    if (_gone(row)) return null;
    final source = (row['source'] as Map?) ?? const {};
    final id = source['id'];
    if (id == null) return null;

    final VoidCallback? go;
    final String label;
    if (source['type'] == 'review') {
      label = 'Open the review';
      go = widget.session.me?.reaches('reviews') == true
          ? () => DeepLinks.open(
              DeepLink(module: 'reviews', type: 'review', id: '$id'),
            )
          : null;
    } else if (source['type'] == 'lead') {
      label = 'Open the lead';
      final openLead = OpenDestination.leadOf(context);
      go = openLead == null ? null : () => openLead('$id');
    } else {
      return null;
    }
    if (go == null) return null;

    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: Align(
        alignment: Alignment.centerLeft,
        child: OutlinedButton.icon(
          key: const ValueKey('complaint-open-source'),
          onPressed: go,
          icon: const Icon(Icons.open_in_new, size: 18),
          label: Text(label),
        ),
      ),
    );
  }
}
