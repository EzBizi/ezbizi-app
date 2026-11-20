import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/branch_options.dart';
import '../../core/deep_links.dart';
import '../../core/phone_numbers.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/load_more.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
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

/// Kind chips, in Shoval's order (design 202): what came from Google,
/// the website, a call, then what came by letter or was typed in.
const _kinds = {
  'bad_review': 'Google',
  'bad_rating': 'Website',
  'call_complaint': 'Call',
  'bbb': 'BBB',
  'attorney_general': 'Attorney General',
  'lawsuit': 'Lawsuit',
  'other': 'Other',
};

/// What "+ New complaint" can enter by hand (the server's list).
const _handKinds = {
  'bbb': 'BBB',
  'attorney_general': 'Attorney General',
  'lawsuit': 'Lawsuit',
  'other': 'Other (Yelp, a letter…)',
};

/// "5 h left", "40 min left", "Overdue 2 h": the clock on a priority one.
(String, bool)? dueClock(Object? dueIso, {DateTime? now}) {
  final due = DateTime.tryParse('${dueIso ?? ''}');
  if (due == null) return null;
  final left = due.difference((now ?? DateTime.now()).toUtc());
  String span(Duration d) {
    final m = d.inMinutes.abs();
    if (m < 60) return '$m min';
    if (m < 48 * 60) return '${(m / 60).floor()} h';
    return '${(m / 1440).floor()} d';
  }

  return left.isNegative
      ? ('Overdue ${span(left)}', true)
      : ('${span(left)} left', false);
}

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

  /// Open priority complaints under the filters, soonest due first, from the
  /// server whatever page or status is shown (design 202).
  List<Map<String, dynamic>> _due = [];
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
    // A complaint logged, moved or noted elsewhere: page one re-reads
    // quietly (the board, filters and the open one kept), and the open one
    // re-reads in place (its notes and history; the note being typed kept).
    _live = LiveChanges.of(widget.api).subscribe({'complaints'}, (batch) async {
      final open = _open?['id'];
      await Future.wait([
        if (!_loading && !_loadingMore && _page == 1) _load(quiet: true),
        if (open != null &&
            (batch.reload || batch.changedOf('complaints').contains(open)))
          _refreshOpen('$open'),
      ]);
    }, active: LiveChanges.visible(this));
  }

  /// The open complaint read again quietly; skipped mid-write, and a
  /// failure keeps what is shown.
  Future<void> _refreshOpen(String id) async {
    if (_busy) return;
    final response = await widget.api.get('/complaints/$id');
    if (!mounted || !response.ok || _busy || _open?['id'] != id) return;
    _take(response);
  }

  LiveSubscription? _live;

  @override
  void dispose() {
    _live?.cancel();
    _debounce?.cancel();
    _note.dispose();
    _search.dispose();
    super.dispose();
  }

  static List<Map<String, dynamic>> _maps(Object? list) => [
    for (final m in list as List? ?? const [])
      if (m is Map) m.cast<String, dynamic>(),
  ];

  Map<String, String> _query(int page) => {
    // The board is a column per status, so it asks for every status.
    'status': _board ? 'all' : _status,
    'kind': ?_kind,
    'owner': ?_owner,
    'branch': ?_branch,
    if (_days != null)
      'from': formatCalendarDay(
        DateTime.now().subtract(Duration(days: _days!)),
      ),
    if (_q.isNotEmpty) 'q': _q,
    'per_page': '${ComplaintsTab.perPage}',
    if (page > 1) 'page': '$page',
  };

  /// [quiet]: a live re-read of page one; a failure keeps the rows.
  Future<void> _load({bool more = false, bool quiet = false}) async {
    final request = ++_request;
    final page = more ? _page + 1 : 1;

    if (!quiet) {
      setState(() {
        if (more) {
          _loadingMore = true;
          _moreError = null;
        } else {
          _loading = _rows.isEmpty;
          _error = null;
        }
      });
    }

    final response = await widget.api.get('/complaints', _query(page));
    if (!mounted || request != _request) return;
    if (quiet && !response.ok) return;

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
      _due = _maps(meta['due']);
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
    final String k when _kinds.containsKey(k) => _kinds[k]!,
    _ => '${row['kind'] ?? ''}',
  };

  static IconData _kindIcon(Map<String, dynamic> row) => switch (row['kind']) {
    'bad_review' => Icons.reviews_outlined,
    'bad_rating' => Icons.sentiment_dissatisfied_outlined,
    'call_complaint' => Icons.phone_outlined,
    'bbb' => Icons.verified_outlined,
    'attorney_general' => Icons.account_balance_outlined,
    'lawsuit' => Icons.gavel_outlined,
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

  /// Client / Not a client, or nothing when nobody could tell.
  Widget? _client(Map<String, dynamic> row) => switch (row['client_status']) {
    'client' => StatusPill(
      'Client',
      key: ValueKey('complaint-client-${row['id']}'),
      tone: Tone.ok,
      dense: true,
    ),
    'not_client' => StatusPill(
      'Not a client',
      key: ValueKey('complaint-client-${row['id']}'),
      dense: true,
    ),
    _ => null,
  };

  /// A priority one's clock, red once it is overdue; null for the rest and
  /// for one already closed.
  Widget? _clock(Map<String, dynamic> row) {
    if (row['priority'] != true ||
        row['status'] == 'solved' ||
        row['status'] == 'not_solvable') {
      return null;
    }
    final clock = dueClock(row['due_at']);
    if (clock == null) return null;
    return StatusPill(
      clock.$1,
      key: ValueKey('complaint-due-${row['id']}'),
      tone: Tone.bad,
      solid: clock.$2,
      dense: true,
    );
  }

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
            _kindChips(),
            if (_due.isNotEmpty) _dueStrip(theme, narrow),
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
          if (_manage) ...[
            narrow
                ? IconButton.filled(
                    key: const ValueKey('complaints-new'),
                    tooltip: 'New complaint',
                    onPressed: _newComplaint,
                    icon: const Icon(Icons.add),
                  )
                : FilledButton.icon(
                    key: const ValueKey('complaints-new'),
                    onPressed: _newComplaint,
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('New complaint'),
                  ),
            const SizedBox(width: 12),
          ],
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
            child: ListSearch(
              key: const ValueKey('complaints-search'),
              controller: _search,
              hint: 'Name or what they said',
              onChanged: _searched,
            ),
          ),
        ],
      ),
    );
  }

  /// All · Google · Website · Call · BBB · Attorney General · Lawsuit · Other.
  Widget _kindChips() => SingleChildScrollView(
    key: const ValueKey('complaints-kinds'),
    scrollDirection: Axis.horizontal,
    padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
    child: Row(
      children: [
        for (final (key, label) in [
          (null, 'All kinds'),
          for (final e in _kinds.entries) (e.key, e.value),
        ])
          Padding(
            padding: const EdgeInsets.only(right: 6),
            child: ChoiceChip(
              key: ValueKey('complaints-kind-${key ?? 'all'}'),
              showCheckmark: false,
              label: Text(label),
              selected: _kind == key,
              onSelected: (_) => _refilter(() => _kind = key),
            ),
          ),
      ],
    ),
  );

  /// Design 202: the open priority ones on top, soonest due first.
  Widget _dueStrip(ThemeData theme, bool narrow) {
    final bad = ToneColours.of(context, Tone.bad);
    return Container(
      key: const ValueKey('complaints-due'),
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
      padding: const EdgeInsets.fromLTRB(10, 8, 10, 4),
      decoration: BoxDecoration(
        color: bad.fill,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'DUE WITHIN 24 H · ${_due.length}',
            style: theme.textTheme.labelSmall?.copyWith(
              color: bad.ink,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.6,
            ),
          ),
          for (final row in _due)
            Padding(
              padding: const EdgeInsets.only(top: 4, bottom: 4),
              child: Material(
                color: theme.colorScheme.surface,
                borderRadius: BorderRadius.circular(8),
                child: InkWell(
                  key: ValueKey('complaint-due-row-${row['id']}'),
                  borderRadius: BorderRadius.circular(8),
                  onTap: () => _openRow(row),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 7,
                    ),
                    child: Row(
                      children: [
                        ?_clock(row),
                        const SizedBox(width: 10),
                        Expanded(child: _from(theme, row)),
                        if (!narrow) ...[
                          Text(
                            [_kindText(row), ?_branchOf(row)].join(' · '),
                            style: theme.textTheme.bodySmall,
                          ),
                          const SizedBox(width: 10),
                        ],
                        ?_client(row),
                      ],
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Future<void> _newComplaint() async {
    final made = await showSidePanel<bool>(
      context,
      builder: (_) =>
          _NewComplaintPanel(api: widget.api, session: widget.session),
    );
    if (made == true && mounted) await _load();
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
                cell(2, Text('CLIENT', style: head)),
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
                cell(
                  3,
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _from(theme, row),
                      if (_clock(row) case final c?) ...[
                        const SizedBox(height: 3),
                        c,
                      ],
                    ],
                  ),
                ),
                cell(
                  2,
                  Align(
                    alignment: Alignment.centerLeft,
                    child: _client(row) ?? Text('—', style: small),
                  ),
                ),
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
            if (_clock(row) != null || _client(row) != null) ...[
              const SizedBox(height: 4),
              Wrap(spacing: 6, children: [?_clock(row), ?_client(row)]),
            ],
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
              ?_clock(row),
              ?_client(row),
            ],
          ),
          if (row['google_report'] case {
            'url': final String url,
            'evidence': final String evidence,
          }) ...[
            _section(theme, 'Not a client: report it to Google'),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                evidence,
                key: const ValueKey('complaint-google-evidence'),
                style: small,
              ),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.icon(
                key: const ValueKey('complaint-report-google'),
                onPressed: () => _reportOnGoogle(url, evidence),
                icon: const Icon(Icons.flag_outlined, size: 18),
                label: const Text('Report on Google'),
              ),
            ),
            Text(
              "Opens Google's review report form and copies the reason above.",
              style: small,
            ),
          ],
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
  /// Google has no API to report a review: the evidence is copied, and its
  /// form opens for the person to paste it and send.
  Future<void> _reportOnGoogle(String url, String evidence) async {
    await Clipboard.setData(ClipboardData(text: evidence));
    var opened = false;
    try {
      opened = await launchUrl(
        Uri.parse(url),
        mode: LaunchMode.externalApplication,
      );
    } catch (_) {
      // No browser to hand it to: the snackbar gives the address instead.
    }
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          opened
              ? "Copied. Paste it into Google's form."
              : "Copied. Google's form did not open: $url",
        ),
      ),
    );
  }

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

/// "+ New complaint" (design 202): BBB, Attorney General, a lawsuit, or
/// anything else that came by letter or a site with no API, typed in.
class _NewComplaintPanel extends StatefulWidget {
  const _NewComplaintPanel({required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<_NewComplaintPanel> createState() => _NewComplaintPanelState();
}

class _NewComplaintPanelState extends State<_NewComplaintPanel> {
  String _kind = 'bbb';
  final _title = TextEditingController();
  final _summary = TextEditingController();
  final _came = TextEditingController();
  String? _branch;
  bool? _client;
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    _title.dispose();
    _summary.dispose();
    _came.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_title.text.trim().isEmpty) {
      setState(() => _error = 'Give it a title: who, and what it is about.');
      return;
    }
    final at = parseWallDateTime(_came.text);
    setState(() => _busy = true);
    final r = await widget.api.post('/complaints', {
      'kind': _kind,
      'title': _title.text.trim(),
      if (_summary.text.trim().isNotEmpty) 'summary': _summary.text.trim(),
      'franchise_id': ?_branch,
      if (at != null)
        'raised_at': DateTime(
          at.day.year,
          at.day.month,
          at.day.day,
          at.hour,
          at.minute,
        ).toUtc().toIso8601String(),
      'client': ?_client,
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _busy = false;
        _error = r.reason;
      });
      return;
    }
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me;
    final branches = me == null ? const [] : branchOptionsInView(me);
    final priority = const {
      'bbb',
      'attorney_general',
      'lawsuit',
    }.contains(_kind);
    return SidePanel(
      key: const ValueKey('complaints-new-panel'),
      title: 'New complaint',
      actions: [
        FilledButton(
          key: const ValueKey('complaints-new-save'),
          onPressed: _busy ? null : _save,
          child: const Text('Add complaint'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null) ...[
            Text(
              _error!,
              key: const ValueKey('complaints-new-error'),
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
            const SizedBox(height: 8),
          ],
          SearchPickerField<String>(
            key: const ValueKey('complaints-new-kind'),
            label: 'Kind',
            value: _kind,
            options: [
              for (final e in _handKinds.entries)
                SearchOption(value: e.key, label: e.value),
            ],
            onChanged: (v) => setState(() => _kind = v ?? 'bbb'),
          ),
          if (priority)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'Priority: due 24 hours after it came in.',
                key: const ValueKey('complaints-new-priority'),
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: ToneColours.of(context, Tone.bad).ink,
                ),
              ),
            ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('complaints-new-title'),
            controller: _title,
            decoration: const InputDecoration(labelText: 'Title'),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('complaints-new-summary'),
            controller: _summary,
            minLines: 3,
            maxLines: 8,
            decoration: const InputDecoration(labelText: 'What it says'),
          ),
          const SizedBox(height: 10),
          if (branches.isNotEmpty) ...[
            SearchPickerField<String?>(
              key: const ValueKey('complaints-new-branch'),
              label: 'Branch',
              value: _branch,
              options: [
                const SearchOption(value: null, label: 'Not one branch'),
                for (final b in branches)
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: (v) => setState(() => _branch = v),
            ),
            const SizedBox(height: 10),
          ],
          if (me != null)
            CalendarDayField(
              key: const ValueKey('complaints-new-came'),
              controller: _came,
              formats: me.display,
              label: 'Came in (empty: now)',
              withTime: true,
            ),
          const SizedBox(height: 10),
          SearchPickerField<bool?>(
            key: const ValueKey('complaints-new-client'),
            label: 'A client?',
            value: _client,
            options: const [
              SearchOption(value: null, label: "Don't know"),
              SearchOption(value: true, label: 'Client'),
              SearchOption(value: false, label: 'Not a client'),
            ],
            onChanged: (v) => setState(() => _client = v),
          ),
        ],
      ),
    );
  }
}
