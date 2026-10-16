import '../core/phone_numbers.dart';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/numbers.dart';
import '../core/session.dart';
import '../widgets/address_fields.dart';
import '../widgets/async_body.dart';
import '../widgets/data_transfer.dart';
import '../widgets/error_note.dart';
import '../widgets/franchise_field.dart';
import '../widgets/grouped_rows.dart';
import '../widgets/open_destination.dart';
import '../widgets/refused.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/tag.dart';
import 'customers/customer_detail_panel.dart';

/// Customers (plan §5.2).
///
/// The owning-franchise tag is on every row, deliberately. Its whole job is to
/// stop somebody acting on another franchise's customer without realising, so it
/// has to be visible BEFORE they act — not buried in a detail pane they may
/// never open.
///
/// It names the COMPANY as well as the franchise, for the same reason it exists
/// at all. A franchise name does not identify a franchise: two companies in one
/// organization may each have a Florida, and a row tagged "Florida" answers
/// nothing about which one — which is the failure the tag was put there to
/// prevent, quietly restored.
///
/// Said on every row rather than only in the organization view, because the
/// view is not what decides how wide the list is. A SEARCH escapes the view
/// scope on purpose (CustomersController::index — a customer nobody can find is
/// a customer somebody duplicates), so a franchise view searching for "Philip"
/// gets results from across the organization. A rule that showed the company
/// only in the organization view would drop it exactly there.
///
/// Importing and exporting customers is HERE rather than on a tab of its own
/// (§12). It used to be one "Import & export" destination listing every
/// resource, which meant somebody who wanted their customer list in a
/// spreadsheet had to leave the customers. See [DataTransfer].
class CustomersScreen extends StatefulWidget {
  const CustomersScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends State<CustomersScreen> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _customers = [];

  /// Leads the search also found (page one of a search only).
  List<Map<String, dynamic>> _leads = const [];
  int _total = 0;
  // Page one only, until asked. The header used to claim a total the list did
  // not render, with no next-page control and nothing saying records were
  // hidden — which reads as data loss rather than as pagination.
  int _page = 1;
  int _lastPage = 1;

  // A failed NEXT page is a different thing from a failed FIRST page, and
  // collapsing them meant one bad request blanked every row already on screen
  // and dropped the reader back to the top of a list they were halfway down.
  String? _pageError;

  // One request at a time, whichever page it is for.
  //
  // This used to guard the append alone, which was enough while a page-one
  // refetch covered the list with a spinner — the "Load more" control went with
  // it, so nothing could ask for page four mid-refresh. Now that a refresh
  // leaves the rows on screen that control stays reachable, and page one
  // REPLACES the list where page four APPENDS to it: whichever answer lands
  // second wins, and the other page's rows are either lost or shown twice.
  bool _fetching = false;

  /// Which request is the current one.
  ///
  /// A page-one load must be allowed to SUPERSEDE another page-one load rather
  /// than be dropped. Refusing it outright — which a plain in-flight guard does
  /// — loses the newer intent permanently: clearing the search box while the
  /// search is still in flight left the box empty and the list still filtered,
  /// with nothing scheduled to correct it.
  ///
  /// So every load takes a ticket, and a response that is no longer holding the
  /// current one is discarded. That also fixes the out-of-order case, where the
  /// slower of two answers lands last and wins.
  int _request = 0;

  /// Below this the table has no room for its columns — see jobs_screen.
  static const _tableFrom = 900.0;

  /// The column the list is ordered by, and which way. Sent to the server
  /// rather than applied here: the list is paged, and sorting the rows on
  /// screen would sort the page that happened to be loaded and nothing else.
  String _sort = 'name';
  bool _ascending = true;

  /// Everyone, repeat customers, or those who never booked (queue 24).
  String? _segment;
  String? _franchiseId;

  /// What each chip would show, from the server's first page; empty from a
  /// server that does not count, and the chips then carry no numbers.
  Map<String, int> _segmentCounts = const {};

  /// The customer open beside the list on a wide screen. Null shows the table.
  String? _openId;
  final _openDirty = ValueNotifier<bool>(false);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _openDirty.dispose();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    // A second tap while the first request is in flight appends the same page
    // twice, so the list grows with duplicate rows.
    // Appends only. Two appends at once duplicate rows, and an append landing
    // during a page-one refresh appends to a list that is being replaced. A
    // page-one load is never refused — it supersedes instead, via _request.
    if (page > 1 && _fetching) return;

    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. A search,
      // a cleared search and the refetch after creating a customer all come
      // through here with rows already on screen, and those rows stay until the
      // new ones arrive. A later page never blanks anything either way.
      _loading = page == 1 && _customers.isEmpty;
      _fetching = true;
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/customers', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
      'segment': ?_segment,
      'franchise_id': ?_franchiseId,
      'sort': _sort,
      'direction': _ascending ? 'asc' : 'desc',
    });

    // Superseded while this was in flight — a newer load owns the screen now,
    // and applying this answer would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _fetching = false;

      if (!response.ok) {
        // A first-page failure takes over the screen. Any rows still in hand
        // are from before whatever the write did, and a refusal to re-read them
        // is not something to whisper — the retry has to be the obvious next
        // thing. A later page failing leaves page one intact, so that one
        // reports beside the rows rather than over them.
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _customers = page == 1 ? rows : [..._customers, ...rows];
      if (page == 1) {
        _leads = (response.body['leads'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .toList();
      }

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _customers.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;

      if (page == 1) {
        final counts = meta?['segment_counts'];
        _segmentCounts = counts is Map
            ? {
                for (final e in counts.entries)
                  if (e.value is int) '${e.key}': e.value as int,
              }
            : const {};
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final label = me.organization.franchiseLabel;

    // Two gates, two remedies, and this screen tested neither out loud: the
    // button was disabled on reach alone, silently, and `customers.create` was
    // never consulted at all. See jobs_screen for the same pair.
    final refused =
        me.refusal('customers.create', 'add a customer') ??
        (me.franchises.isEmpty
            ? me.nowhereToAct(
                'add a customer',
                // Why a customer needs one at all, which is the part somebody
                // reasonably expects not to be true: customers are shared
                // organization-wide (§5.2) and a reader can be forgiven for
                // assuming a shared record needs no place.
                note:
                    'A ${label.toLowerCase()} has to be accountable for the '
                    'relationship.',
              )
            : null);

    return LayoutBuilder(
      builder: (context, constraints) => _page0(
        context,
        me: me,
        label: label,
        refused: refused,
        wide: constraints.maxWidth >= _tableFrom,
      ),
    );
  }

  Widget _page0(
    BuildContext context, {
    required Me me,
    required String label,
    required String? refused,
    required bool wide,
  }) {
    return HeaderAboveList(
      header: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PanelHeader(
            note: (_loading || _error != null)
                ? 'Shared across the organization — the $label tag shows who is accountable.'
                : _customers.length < _total
                ? 'Showing ${_customers.length} of ${formatCount(_total)} shared across the '
                      'organization. The $label tag shows who is accountable.'
                : '${formatCount(_total)} shared across the organization. The $label tag shows who is '
                      'accountable for each relationship.',
            // A Wrap rather than a Row: on a narrow window — and on a phone, which
            // is coming — three controls side by side either overflow or squeeze
            // the labels out. They fall onto a second line instead, in the same
            // order.
            action: Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                // Deliberately a plain outlined button with no icon. Every icon
                // that reads as "duplicates" -- merge arrows, a copy glyph --
                // promises the one thing this does not do: nothing here merges
                // anything, ever (§5.2), and a control that looks like a merge
                // button is a worse lie than a wordy one.
                OutlinedButton(
                  key: const ValueKey('tour-customers-duplicates'),
                  onPressed: _duplicates,
                  child: const Text('Possible duplicates'),
                ),
                // Transfer sits before the primary action rather than after it,
                // so "Add customer" stays the last and most prominent thing in
                // the header.
                DataTransfer(
                  api: widget.api,
                  session: widget.session,
                  resource: 'customers',
                  // An import writes rows this list is showing, so it has to be
                  // read again — otherwise the count in the note above is a
                  // number the screen has already been told is wrong.
                  onImported: _load,
                ),
                Refused(
                  // The server decides in the end. This decides what is OFFERED,
                  // and a control that cannot be used stays on screen saying why
                  // rather than going dark or disappearing.
                  reason: refused,
                  child: FilledButton.icon(
                    key: const ValueKey('tour-customers-add'),
                    onPressed: refused == null ? _create : null,
                    icon: const Icon(Icons.person_add_outlined, size: 18),
                    label: const Text('Add customer'),
                  ),
                ),
              ],
            ),
          ),
          if (wide) _segmentChips(context),
          _filters(context, me, wide: wide),
        ],
      ),
      list: AsyncBody(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: _body(context, wide: wide),
      ),
    );
  }

  Widget _segmentChips(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    Widget chip(String? value, String label, String key) {
      final n = _segmentCounts[key];

      return ChoiceChip(
        key: ValueKey('segment-chip-${value ?? 'all'}'),
        showCheckmark: false,
        selected: _segment == value,
        label: Text.rich(
          TextSpan(
            text: label,
            children: [
              if (n != null)
                TextSpan(
                  text: '  ${formatCount(n)}',
                  style: TextStyle(color: muted, fontWeight: FontWeight.w500),
                ),
            ],
          ),
        ),
        onSelected: (_) {
          setState(() => _segment = value);
          _load();
        },
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 10),
      child: Wrap(
        key: const ValueKey('tour-customers-segments'),
        spacing: 6,
        runSpacing: 6,
        children: [
          chip(null, 'All', 'all'),
          chip('repeat', 'Repeat customers', 'repeat'),
          chip('none', 'No jobs yet', 'none'),
        ],
      ),
    );
  }

  Widget _filters(BuildContext context, Me me, {required bool wide}) {
    final franchises = me.franchisesInView;
    final label = me.organization.franchiseLabel;

    String counted(String word, String key) {
      final n = _segmentCounts[key];
      return n == null ? word : '$word (${formatCount(n)})';
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
      child: LayoutBuilder(
        builder: (context, constraints) {
          // On a phone the search takes the line and the two pickers split the
          // next one; at a fixed 160 they left a gap on the right and cut
          // "Everyone (13)" to "Everyone (1…".
          final half = wide ? 220.0 : (constraints.maxWidth - 12) / 2;

          return Wrap(
            spacing: 12,
            runSpacing: 12,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: wide ? 360 : constraints.maxWidth,
                child: TextField(
                  key: const ValueKey('tour-customers-search'),
                  controller: _search,
                  decoration: InputDecoration(
                    prefixIcon: const Icon(Icons.search),
                    hintText: 'Name, email, phone or number',
                    suffixIcon: _search.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.clear),
                            tooltip: 'Clear the search',
                            onPressed: () {
                              _search.clear();
                              _load();
                            },
                          ),
                  ),
                  onSubmitted: (_) => _load(),
                  onChanged: (_) => setState(() {}),
                ),
              ),
              // The chips' choice, as a picker where three chips would wrap.
              if (!wide)
                SizedBox(
                  width: half,
                  child: SearchPickerField<String?>(
                    value: _segment,
                    label: 'Show',
                    options: [
                      SearchOption<String?>(
                        value: null,
                        label: counted('Everyone', 'all'),
                      ),
                      SearchOption<String?>(
                        value: 'repeat',
                        label: counted('Repeat customers', 'repeat'),
                      ),
                      SearchOption<String?>(
                        value: 'none',
                        label: counted('No jobs yet', 'none'),
                      ),
                    ],
                    onChanged: (value) {
                      setState(() => _segment = value);
                      _load();
                    },
                  ),
                ),
              // Narrowing, not a boundary — customers are shared, see the
              // controller. One franchise is no choice, so no picker.
              if (franchises.length > 1)
                SizedBox(
                  width: half,
                  child: SearchPickerField<String?>(
                    key: const ValueKey('tour-customers-franchise'),
                    value: _franchiseId,
                    label: label,
                    options: [
                      SearchOption<String?>(
                        value: null,
                        label: 'All ${label.toLowerCase()}s',
                      ),
                      for (final f in franchises)
                        SearchOption<String?>(value: f.id, label: f.name),
                    ],
                    onChanged: (value) {
                      setState(() => _franchiseId = value);
                      _load();
                    },
                  ),
                ),
              // Wherever the list is drawn WITHOUT column headers, the same
              // sort is offered here in words (UI/UX, 2026-09-28). The rule is
              // "no headers -> this picker", not a width: below the table's
              // breakpoint there is no table, and at a desk with a record
              // docked the table becomes the 380px sidebar list, which has no
              // headers either. Keyed on width alone, a docked record left no
              // way to change the sort at all.
              //
              // A full line on a phone -- "Created, newest first" does not fit
              // in half of one -- and a fixed width beside the search at a desk.
              if (!wide || _openId != null)
                SizedBox(
                  width: wide ? 260 : constraints.maxWidth,
                  child: SearchPickerField<String>(
                    value: '$_sort:${_ascending ? 'asc' : 'desc'}',
                    label: 'Sort',
                    options: [
                      for (final (key, up, down) in _sortWords(label))
                        for (final ascending in _firstDirection(key)
                            ? [true, false]
                            : [false, true])
                          SearchOption<String>(
                            value: '$key:${ascending ? 'asc' : 'desc'}',
                            label: ascending ? up : down,
                          ),
                    ],
                    onChanged: (value) {
                      if (value == null) return;
                      final [key, direction] = value.split(':');
                      setState(() {
                        _sort = key;
                        _ascending = direction == 'asc';
                      });
                      _load();
                    },
                  ),
                ),
            ],
          );
        },
      ),
    );
  }

  /// Each sortable column in words, ascending then descending. The same keys
  /// the table headers send, so a phone and a desk sort the same list the same
  /// way. [owner] is the organization's own word for a franchise.
  static List<(String, String, String)> _sortWords(String owner) => [
    ('name', 'Name, A to Z', 'Name, Z to A'),
    ('created', 'Created, oldest first', 'Created, newest first'),
    ('jobs', 'Jobs, fewest first', 'Jobs, most first'),
    ('city', 'City, A to Z', 'City, Z to A'),
    ('owner', '$owner, A to Z', '$owner, Z to A'),
    ('email', 'Email, A to Z', 'Email, Z to A'),
    ('phone', 'Phone, low to high', 'Phone, high to low'),
    ('number', 'Customer number, low to high', 'Customer number, high to low'),
  ];

  /// Whether a column's natural first direction is ascending -- the same rule
  /// a header click follows, so the picker lists the likelier choice first.
  static bool _firstDirection(String key) => !(key == 'created' || key == 'jobs');

  /// People found by the search who are still only leads (2026-10-16): their
  /// own rows above the customers, never mixed in, each opening the lead.
  Widget _leadRows(BuildContext context) {
    final theme = Theme.of(context);
    final openLead = OpenDestination.leadOf(context);
    String when(String? iso) {
      final at = DateTime.tryParse(iso ?? '')?.toLocal();
      if (at == null) return '';
      final days = DateTime.now().difference(at).inDays;
      return days == 0
          ? 'today'
          : days == 1
          ? 'yesterday'
          : '${at.month}/${at.day}/${at.year}';
    }

    return Card(
      key: const ValueKey('customers-search-leads'),
      margin: const EdgeInsets.fromLTRB(24, 4, 24, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 2),
            child: Text(
              _leads.length == 1
                  ? '1 lead, not yet a customer'
                  : '${_leads.length} leads, not yet customers',
              style: theme.textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          for (final lead in _leads)
            ListTile(
              key: ValueKey('customers-lead-${lead['id']}'),
              dense: true,
              leading: const Icon(Icons.call_received, size: 20),
              title: Text(
                [
                  '${lead['name'] ?? ''}'.trim(),
                  formatPhoneNumber(lead['phone'] as String?),
                ].where((s) => s.isNotEmpty).join(' · '),
              ),
              subtitle: Text(
                [
                  'Lead',
                  '${lead['source_label'] ?? lead['source'] ?? ''}',
                  when(lead['created_at'] as String?),
                  '${lead['status'] ?? ''}',
                ].where((s) => s.isNotEmpty).join(' · '),
              ),
              trailing: openLead == null
                  ? null
                  : const Icon(Icons.chevron_right),
              onTap: openLead == null
                  ? null
                  : () => openLead('${lead['id']}'),
            ),
        ],
      ),
    );
  }

  Widget _body(BuildContext context, {required bool wide}) {
    if (_leads.isEmpty || _search.text.trim().isEmpty) {
      return _customersBody(context, wide: wide);
    }
    if (_customers.isEmpty) return ListView(children: [_leadRows(context)]);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _leadRows(context),
        Expanded(child: _customersBody(context, wide: wide)),
      ],
    );
  }

  Widget _customersBody(BuildContext context, {required bool wide}) {
    if (_customers.isEmpty) {
      final term = _search.text.trim();

      return Center(
        child: Text(
          term.isNotEmpty
              // "No customers yet" after a search reads as data loss.
              ? 'Nothing matches "$term".'
              : _segment != null
              ? 'Nobody here yet.'
              : 'No customers yet.',
        ),
      );
    }

    // Letter headings only while the list is in name order. Sorted by city or
    // by date, "A" over a run of customers who share nothing but a first
    // letter would be a heading that is true and says nothing.
    final groups = _sort == 'name'
        ? _byLetter(_customers)
        : [RowGroup(heading: '', rows: _customers)];
    final footer = _page < _lastPage ? _loadMore() : null;

    if (!wide) {
      return GroupedList<Map<String, dynamic>>(
        key: const ValueKey('tour-customers-list'),
        groups: groups,
        idOf: _id,
        tile: _tile,
        onOpen: _open,
        footer: footer,
      );
    }

    if (_openId == null) {
      return GroupedTable<Map<String, dynamic>>(
        key: const ValueKey('tour-customers-list'),
        columns: _columns(context),
        groups: groups,
        idOf: _id,
        onOpen: _dock,
        footer: footer,
        sortKey: _sort,
        sortAscending: _ascending,
        onSort: _sortBy,
      );
    }

    final scheme = Theme.of(context).colorScheme;

    return Container(
      margin: const EdgeInsets.fromLTRB(24, 4, 24, 24),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      clipBehavior: Clip.antiAlias,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 380,
            child: GroupedList<Map<String, dynamic>>(
              groups: groups,
              idOf: _id,
              tile: _tile,
              onOpen: _dock,
              selectedId: _openId,
              footer: footer,
            ),
          ),
          VerticalDivider(width: 1, color: scheme.outlineVariant),
          Expanded(
            child: CustomerDetailPanel(
              key: ValueKey('open-$_openId'),
              api: widget.api,
              session: widget.session,
              id: _openId!,
              onDone: _undock,
              dirty: _openDirty,
            ),
          ),
        ],
      ),
    );
  }

  Widget _loadMore() => Padding(
    padding: const EdgeInsets.symmetric(vertical: 12),
    child: Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_pageError != null) ...[
            ErrorNote(_pageError!),
            const SizedBox(height: 8),
          ],
          TextButton(
            onPressed: _fetching ? null : () => _load(page: _page + 1),
            child: Text(
              _fetching
                  ? 'Loading…'
                  : _pageError != null
                  ? 'Try again'
                  : 'Load more (${formatCount(_total - _customers.length)} left)',
            ),
          ),
        ],
      ),
    ),
  );

  List<TableColumn<Map<String, dynamic>>> _columns(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;

    return [
      TableColumn(
        'Customer',
        width: 96,
        sortKey: 'number',
        cell: (_, c) =>
            Text('${c['number'] ?? ''}', style: TextStyle(color: muted)),
      ),
      // Workiz's own client numbers beside EzBizi's (Jose, 2026-09-29):
      // several when one person had more than one Workiz record. Only when a
      // loaded customer has one: other organizations have no Workiz, and a
      // column of dashes on every row is dead weight (Customers' review).
      if (_customers.any((c) => workizClientNumbers(c) != null))
        TableColumn(
          'Workiz',
          width: 96,
          cell: (_, c) => Text(
            workizClientNumbers(c) ?? '—',
            style: TextStyle(color: muted),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      TableColumn(
        'Name',
        flex: 3,
        sortKey: 'name',
        cell: (_, c) => Row(
          children: [
            Flexible(
              child: Text(
                '${c['name'] ?? ''}',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            if (c['is_active'] == false) const Tag('Inactive', warn: true),
          ],
        ),
      ),
      TableColumn(
        'Phone',
        width: 140,
        sortKey: 'phone',
        cell: (_, c) => Text(
          c['phone'] == null ? '' : formatPhoneNumber(c['phone'] as String?),
        ),
      ),
      TableColumn(
        'Email',
        flex: 3,
        sortKey: 'email',
        cell: (_, c) =>
            Text('${c['email'] ?? ''}', style: TextStyle(color: muted)),
      ),
      TableColumn(
        'City',
        flex: 2,
        sortKey: 'city',
        cell: (_, c) => Text(_place(c)),
      ),
      // §5.2: visible in the list, not only on the detail screen — on every
      // row, company included; see the class docblock for why.
      TableColumn(
        widget.session.me!.organization.franchiseLabel,
        flex: 3,
        sortKey: 'owner',
        cell: (context, c) => _owner(context, c),
      ),
      TableColumn(
        'Jobs',
        width: 60,
        numeric: true,
        sortKey: 'jobs',
        cell: (_, c) {
          final jobs = c['jobs_count'] as int? ?? 0;
          return Text(
            '$jobs',
            style: jobs == 0 ? TextStyle(color: muted) : null,
          );
        },
      ),
      // When the RECORD was made here, not when they became a customer: every
      // row the Workiz import wrote reads the day of the import. The heading
      // says "Created" rather than "Since" so it cannot claim more than that.
      TableColumn(
        'Created',
        width: 104,
        sortKey: 'created',
        cell: (_, c) => Text(
          _createdOn(c),
          style: TextStyle(color: muted),
        ),
      ),
    ];
  }

  /// The date the record was made, in the organization's own date format.
  /// A date and never a clock: a Created column reading "14:02" for today and a
  /// date for everything else would be two kinds of answer in one column.
  String _createdOn(Map<String, dynamic> customer) {
    final raw = customer['created_at'];
    final at = raw is String ? DateTime.tryParse(raw)?.toLocal() : null;

    return at == null ? '' : widget.session.me!.display.formatDate(at);
  }

  /// A header click. The same column flips the direction; a new one starts in
  /// the direction somebody sorting by it almost always wants -- newest first
  /// for a date and most first for a count, A to Z for everything else.
  void _sortBy(String key) {
    setState(() {
      if (key == _sort) {
        _ascending = !_ascending;
      } else {
        _sort = key;
        _ascending = _firstDirection(key);
      }
    });
    _load();
  }

  Widget _tile(BuildContext context, Map<String, dynamic> customer) {
    final jobs = customer['jobs_count'] as int? ?? 0;

    return TwoLineTile(
      title: '${customer['name'] ?? ''}',
      badge: customer['is_active'] == false
          ? const Tag('Inactive', warn: true)
          : null,
      topRight: jobs == 0
          ? 'No jobs yet'
          : '$jobs ${jobs == 1 ? 'job' : 'jobs'}',
      subtitle: [
        _place(customer),
        _ownerText(customer),
      ].where((s) => s.isNotEmpty).join(' · '),
    );
  }

  /// "EzBizi · Florida", with the company quieter than the franchise — both
  /// named, because the franchise alone does not say which Florida. Just the
  /// franchise on a server that does not send the company yet, so the older
  /// shape reads as it always did rather than as a row with something missing.
  Widget _owner(BuildContext context, Map<String, dynamic> customer) {
    final (company, franchise) = _ownerParts(customer);
    if (company == null) return Text(franchise);

    return Text.rich(
      TextSpan(
        children: [
          TextSpan(
            text: '$company · ',
            style: TextStyle(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
          TextSpan(text: franchise),
        ],
      ),
    );
  }

  String _ownerText(Map<String, dynamic> customer) {
    final (company, franchise) = _ownerParts(customer);
    return company == null ? franchise : '$company · $franchise';
  }

  static (String?, String) _ownerParts(Map<String, dynamic> customer) {
    final owner = customer['owning_franchise'] as Map<String, dynamic>? ?? {};
    final company = (owner['company'] as Map<String, dynamic>?)?['name'];
    final franchise = owner['name'] as String? ?? 'Unassigned';

    return (
      company is String && company.isNotEmpty ? company : null,
      franchise,
    );
  }

  static String _place(Map<String, dynamic> customer) => [
    customer['city'],
    customer['state'],
  ].whereType<String>().where((s) => s.trim().isNotEmpty).join(', ');

  static String _id(Map<String, dynamic> customer) => customer['id'] as String;

  /// Letter headings over a list the server already sorted by name.
  ///
  /// By the first character as stored, with accented capitals read as their
  /// letter — the database's collation sorts "Ángela" among the A's, so a
  /// heading that filed her under # would split the A's in two. Anything else
  /// that is not a letter is #, and sorts first, as it does on the server.
  static List<RowGroup<Map<String, dynamic>>> _byLetter(
    List<Map<String, dynamic>> customers,
  ) {
    final groups = <String, List<Map<String, dynamic>>>{};

    for (final customer in customers) {
      final name = '${customer['name'] ?? ''}';
      final first = name.isEmpty ? '' : name[0].toUpperCase();
      final letter = _unaccented[first] ?? first;
      final key = RegExp(r'^[A-Z]$').hasMatch(letter) ? letter : '#';
      (groups[key] ??= []).add(customer);
    }

    return [
      for (final MapEntry(:key, :value) in groups.entries)
        RowGroup(heading: key, rows: value),
    ];
  }

  static const _unaccented = {
    'Á': 'A', 'À': 'A', 'Â': 'A', 'Ä': 'A', 'Ã': 'A', 'Å': 'A', //
    'É': 'E', 'È': 'E', 'Ê': 'E', 'Ë': 'E', //
    'Í': 'I', 'Ì': 'I', 'Î': 'I', 'Ï': 'I', //
    'Ó': 'O', 'Ò': 'O', 'Ô': 'O', 'Ö': 'O', 'Õ': 'O', //
    'Ú': 'U', 'Ù': 'U', 'Û': 'U', 'Ü': 'U', //
    'Ñ': 'N', 'Ç': 'C', 'Ý': 'Y',
  };

  Future<void> _create() async {
    final created = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) =>
          _NewCustomerPanel(api: widget.api, session: widget.session),
    );

    if (created == null || !mounted) return;

    final duplicates = created['possible_duplicates'] as List? ?? [];

    if (duplicates.isNotEmpty) {
      // Reported, never merged (§5.2). Automatic merging of customer records is
      // unrecoverable when it is wrong, so this is a notice for a human.
      await showSidePanel<void>(
        context,
        builder: (context) => SidePanel(
          title: 'Possible duplicate',
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Got it'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Saved. These existing customers look similar — nothing has been '
                'merged, and nothing will be automatically.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              const SizedBox(height: 12),
              ...duplicates.map((d) {
                final dup = d as Map<String, dynamic>;
                final owner = dup['owning_franchise'] as Map<String, dynamic>;
                return ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text('${dup['name']} (${dup['number']})'),
                  subtitle: Text(
                    '${dup['phone'] != null ? formatPhoneNumber(dup['phone'] as String?) : (dup['email'] ?? '')} · ${owner['name'] ?? ''}',
                  ),
                );
              }),
            ],
          ),
        ),
      );
    }

    if (mounted) _load();
  }

  /// The sweep, as opposed to the check that rides along with a create.
  ///
  /// Reachable from the header rather than only after saving somebody, because
  /// the duplicates worth finding are the ones nobody typed -- an import writes
  /// them in bulk and no create-time check ever sees them.
  Future<void> _duplicates() async {
    await showSidePanel<void>(
      context,
      builder: (_) => _DuplicatesPanel(api: widget.api),
    );
  }

  /// Open beside the list. Refused while the open customer has unsaved
  /// changes — see jobs_screen's `_dock`.
  void _dock(Map<String, dynamic> customer) {
    final id = customer['id'] as String;
    if (id == _openId) return;

    if (_openId != null && _openDirty.value) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Save or cancel the changes to this customer first.'),
        ),
      );
      return;
    }

    setState(() => _openId = id);
  }

  void _undock(bool changed) {
    _openDirty.value = false;
    setState(() => _openId = null);
    if (changed) _load();
  }

  /// Open over the list — on a phone, where there is no room beside it.
  Future<void> _open(Map<String, dynamic> customer) async {
    final changed = await showSidePanel<bool>(
      context,
      builder: (_) => CustomerDetailPanel(
        api: widget.api,
        session: widget.session,
        id: customer['id'] as String,
      ),
    );

    if (changed == true && mounted) _load();
  }
}

class _NewCustomerPanel extends StatefulWidget {
  const _NewCustomerPanel({required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<_NewCustomerPanel> createState() => _NewCustomerPanelState();
}

class _NewCustomerPanelState extends State<_NewCustomerPanel> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _line1 = TextEditingController();
  final _line2 = TextEditingController();
  final _city = TextEditingController();
  final _state = TextEditingController();
  final _postal = TextEditingController();

  String? _franchiseId;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // The current view, not every franchise this person can ever reach.
    // Standing in A-1 Chimney must not offer Everdoors' Connecticut.
    final reachable = widget.session.me!.franchisesInView;
    if (reachable.length == 1) _franchiseId = reachable.first.id;
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    _line1.dispose();
    _line2.dispose();
    _city.dispose();
    _state.dispose();
    _postal.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/customers', {
      'owning_franchise_id': _franchiseId,
      'name': _name.text.trim(),
      if (_phone.text.trim().isNotEmpty) 'phone': toE164(_phone.text),
      if (_email.text.trim().isNotEmpty) 'email': _email.text.trim(),
      if (_line1.text.trim().isNotEmpty) 'address_line1': _line1.text.trim(),
      if (_line2.text.trim().isNotEmpty) 'address_line2': _line2.text.trim(),
      if (_city.text.trim().isNotEmpty) 'city': _city.text.trim(),
      if (_state.text.trim().isNotEmpty)
        'state': _state.text.trim().toUpperCase(),
      if (_postal.text.trim().isNotEmpty) 'postal_code': _postal.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    Navigator.of(context).pop(response.body);
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final label = me.organization.franchiseLabel;

    return SidePanel(
      title: 'Add customer',
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: (_busy || _franchiseId == null) ? null : _submit,
          child: const Text('Create'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FranchiseField(
            me: me,
            value: _franchiseId,
            label: 'Owning $label',
            helperText:
                'Accountable for the relationship, and counts them '
                'in their numbers. Changing it later is a separate, audited step.',
            enabled: !_busy,
            onChanged: (v) => setState(() => _franchiseId = v),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _name,
            decoration: const InputDecoration(labelText: 'Name'),
            autofocus: true,
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _phone,
            decoration: const InputDecoration(labelText: 'Phone'),
            keyboardType: TextInputType.phone,
            inputFormatters: const [PhoneNumberInputFormatter()],
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _email,
            decoration: const InputDecoration(labelText: 'Email'),
            keyboardType: TextInputType.emailAddress,
            enabled: !_busy,
          ),
          const SizedBox(height: 12),
          // Found with the lookup, kept in parts (Jose, 2026-09-30).
          AddressFields(
            api: widget.api,
            line1: _line1,
            line2: _line2,
            city: _city,
            state: _state,
            postal: _postal,
            enabled: !_busy,
            dense: false,
          ),
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}

/// Customers that look like each other, across the whole organization.
///
/// Informational on purpose. It lists and it explains; it offers no merge and
/// no delete, because merging customer records is unrecoverable when it is
/// wrong and two people on one household line are two people. The decision and
/// the edit both belong to a person, on the record itself.
class _DuplicatesPanel extends StatefulWidget {
  const _DuplicatesPanel({required this.api});

  final ApiClient api;

  @override
  State<_DuplicatesPanel> createState() => _DuplicatesPanelState();
}

class _DuplicatesPanelState extends State<_DuplicatesPanel> {
  bool _loading = true;
  String? _error;

  /// The server's sentence when detection is switched off. Held separately from
  /// an empty list because "we looked and found none" and "nobody is looking"
  /// are different answers, and an empty panel would show the first for both.
  String? _note;

  List<Map<String, dynamic>> _groups = const [];
  bool _limited = false;

  /// How many groups there ACTUALLY are, which stops being the number on screen
  /// the moment the cap bites. A-1's book holds 149 of them, so this is not a
  /// hypothetical: "showing 200" and "showing 200 of 1,490" are different
  /// facts and only the second tells somebody whether they have seen it all.
  int _total = 0;

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

    final response = await widget.api.get('/customers/duplicates', const {});

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _groups = List<Map<String, dynamic>>.from(response.data as List);
      _note = response.body['message'] as String?;
      _limited = response.body['limited'] as bool? ?? false;
      _total = response.body['total'] as int? ?? _groups.length;
    });
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return SidePanel(
      title: 'Possible duplicates',
      subtitle: 'Across the whole organization — the same person booked by two '
          'branches is the one most worth finding.',
      actions: [
        FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Close'),
        ),
      ],
      child: AsyncBody(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (_note != null)
              Text(_note!, style: text.bodyMedium)
            else if (_groups.isEmpty)
              Text('Nothing looks duplicated.', style: text.bodyMedium)
            else ...[
              // Says only what a person can actually do. It used to say
              // "correct or remove one", and a real duplicate always has jobs,
              // which is exactly when removing is refused -- so the one action
              // it offered was the one the server says no to.
              Text(
                'Nothing has been merged, and nothing will be automatically. '
                'Open a customer to correct it. One with no jobs can be removed; '
                'one with jobs cannot.',
                style: text.bodyMedium,
              ),
              const SizedBox(height: 16),
              ..._groups.map(_group),
              if (_limited)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'Showing ${_groups.length} of $_total.',
                    style: text.bodySmall,
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _group(Map<String, dynamic> group) {
    final text = Theme.of(context).textTheme;
    final by = group['by'] as String? ?? '';
    final value = group['value'] as String? ?? '';
    final members = List<Map<String, dynamic>>.from(
      group['customers'] as List? ?? const [],
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Same $by · ${by == 'phone' ? formatPhoneNumber(value) : value}',
            style: text.labelLarge,
          ),
          ...members.map((member) {
            final owner = member['owning_franchise'] as Map<String, dynamic>?;
            final company = (owner?['company'] as Map<String, dynamic>?)?['name'];
            final franchise = owner?['name'] ?? '';

            return ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text('${member['name']} (${member['number']})'),
              // The owner tag, as everywhere else a customer appears: merging
              // two of these is a decision about which branch keeps the
              // relationship, not only about which row survives.
              subtitle: Text(
                company is String && company.isNotEmpty
                    ? '$company · $franchise'
                    : '$franchise',
              ),
            );
          }),
        ],
      ),
    );
  }
}
