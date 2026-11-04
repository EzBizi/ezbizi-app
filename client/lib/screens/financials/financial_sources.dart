import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// Financials → Settings: which of the organization's Airtable tables is which
/// part of its books, and which column means what. Reports read this map.
///
/// Drawn as a matrix (UI queue 124, option 1 + phone 4): a row per part of the
/// books, a column per branch plus "All branches". A branch with no table of
/// its own that an All-branches table covers reads "uses All"; a cell with
/// neither reads "— · Add". Below 760 px the matrix is one branch's column,
/// picked on top, as a list of rows.
///
/// Each cell says what `/financials/sources` says of the source: its own row
/// count (a shared table's filter applied), Mirror or Native, and when a
/// mirror was last copied, in red once that is more than a day ago.
class FinancialSourcesSettings extends StatefulWidget {
  const FinancialSourcesSettings({
    super.key,
    required this.api,
    required this.me,
  });

  final ApiClient api;
  final Me me;

  @override
  State<FinancialSourcesSettings> createState() =>
      _FinancialSourcesPanelState();
}

/// One column of the matrix: [id] null is All branches.
class _Column {
  const _Column(this.id, this.label, [this.branch]);

  final String? id;
  final String label;
  final FranchiseSummary? branch;
}

/// The value the narrow picker uses for All branches (a picker value cannot
/// be null without reading as "nothing chosen").
const _allBranches = '';

const _roleWidth = 180.0;
const _cellWidth = 200.0;

class _FinancialSourcesPanelState extends State<FinancialSourcesSettings> {
  Map<String, dynamic>? _data;
  String? _error;
  String? _roleFilter;
  String? _branchFilter;
  String _narrowBranch = _allBranches;
  final _search = TextEditingController();

  /// Copy now: the server's answer, shown under the header line.
  String? _copyNote;
  bool _copyFailed = false;
  bool _copying = false;

  /// The list is read again a minute after a copy starts, when it has had
  /// time to land.
  Timer? _refetch;

  /// Copy now asks only for Financials edit (it copies, it changes nothing).
  bool get _canCopy => widget.me.can('financials.edit');

  bool get _canEdit =>
      widget.me.can('financials.edit') &&
      widget.me.scope.type == 'organization';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _refetch?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/financials/sources');
    if (!mounted) return;
    setState(() {
      _data = r.ok ? r.body : null;
      _error = r.ok ? null : r.message;
    });
  }

  List<Map> get _sources => ((_data?['data'] as List?) ?? const []).cast<Map>();

  Map<String, dynamic> get _roles =>
      ((_data?['roles'] as Map?) ?? const {}).cast<String, dynamic>();

  Map? _tableOf(Map s) {
    for (final t in (_data?['airtable_tables'] as List? ?? const [])) {
      if (t['base_id'] == s['base_id'] && t['table_id'] == s['table_id']) {
        return t as Map;
      }
    }
    return null;
  }

  String _tableName(Map s) {
    final t = _tableOf(s);
    return t == null
        ? '${s['base_id']} / ${s['table_id']}'
        : '${t['table_name']}';
  }

  /// All branches, then every branch in view and every branch a source
  /// names, labelled "Company · Branch" and in that order.
  List<_Column> get _allColumns {
    final me = widget.me;
    final inView = {for (final f in me.franchisesInView) f.id};
    final named = {
      for (final s in _sources)
        if (s['franchise_id'] != null) '${s['franchise_id']}',
    };
    final columns = <_Column>[
      const _Column(null, 'All branches'),
      for (final b in branchOptions(
        me.franchises,
        me.companies,
        activeOnly: false,
      ))
        if (named.contains(b.id) ||
            (inView.contains(b.id) && b.branch.isActive))
          _Column(b.id, b.label, b.branch),
    ];
    final known = {for (final c in columns) c.id};
    for (final s in _sources) {
      final id = s['franchise_id'] as String?;
      if (id != null && known.add(id)) {
        columns.add(_Column(id, '${s['place'] ?? id}'));
      }
    }
    return columns;
  }

  List<Map> _own(String role, String? branchId) => [
    for (final s in _sources)
      if (s['role'] == role && s['franchise_id'] == branchId) s,
  ];

  /// An All-branches source that covers [column]'s branch: one for the whole
  /// organization, or for the branch's company or a company above it.
  Map? _inherited(String role, _Column column) {
    final byId = {for (final c in widget.me.companies) c.id: c};
    final above = <String>{};
    String? id = column.branch?.companyId;
    while (id != null && above.add(id)) {
      id = byId[id]?.parentCompanyId;
    }
    for (final s in _own(role, null)) {
      final company = s['company_id'] as String?;
      if (company == null || above.contains(company)) return s;
    }
    return null;
  }

  bool _matches(Map s) {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return true;
    return [
      _tableName(s),
      s['base_id'],
      s['table_id'],
    ].any((v) => '${v ?? ''}'.toLowerCase().contains(q));
  }

  List<String> get _visibleRoles => [
    for (final role in _roles.keys)
      if ((_roleFilter == null || _roleFilter == role) &&
          (_search.text.trim().isEmpty ||
              _sources.any((s) => s['role'] == role && _matches(s))))
        role,
  ];

  Future<void> _edit(Map? source, {String? role, String? branchId}) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) => _SourcePanel(
        api: widget.api,
        me: widget.me,
        data: _data!,
        onCopyNow: _canCopy ? _copyNow : null,
        source: source,
        initialRole: role,
        initialPlace: branchId,
      ),
    );
    if (saved == true) await _load();
  }

  /// POST copy-now. Returns the sentence to show, and whether it is a refusal.
  Future<({String text, bool failed})> _copyNow() async {
    setState(() => _copying = true);
    final r = await widget.api.post('/financials/sources/copy-now', const {});
    final failed = !r.ok;
    final text = failed
        ? r.reason
        : '${(r.body['data'] as Map?)?['message'] ?? 'Copying from Airtable now.'}';
    if (!failed) {
      _refetch?.cancel();
      _refetch = Timer(const Duration(seconds: 60), () {
        if (mounted) _load();
      });
    }
    if (mounted) {
      setState(() {
        _copying = false;
        _copyNote = text;
        _copyFailed = failed;
      });
    }
    return (text: text, failed: failed);
  }

  /// "last copy 4 min ago" for a mirror, "never copied" for a mirror with
  /// none; nothing for a native table.
  String? _copyLabel(Map s) {
    if (s['kind'] != 'mirror') return null;
    final at = s['last_copied_at'] as String?;
    return at == null ? 'never copied' : 'last copy ${sourcesAgo(at)}';
  }

  bool _stale(Map s) {
    final at = DateTime.tryParse('${s['last_copied_at'] ?? ''}');
    return s['kind'] == 'mirror' &&
        at != null &&
        DateTime.now().difference(at.toLocal()) > const Duration(hours: 24);
  }

  String _detail(Map s) {
    final rows = s['rows'];
    return [
      if (rows != null) '$rows row${rows == 1 ? '' : 's'}',
      ?_copyLabel(s),
      if (s['franchise_id'] == null && s['company_id'] != null)
        '${s['place'] ?? 'One company'} only',
      if (s['valid_from'] != null) 'from ${s['valid_from']}',
      if (s['valid_until'] != null) 'until ${s['valid_until']}',
    ].join(' · ');
  }

  double get _entryHeight => MediaQuery.textScalerOf(context).scale(44);

  /// What one cell says. Fixed line heights so the pinned role column and
  /// the scrolling cells keep the same row height.
  Widget _cell(String role, _Column column) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final own = _own(role, column.id);
    final key = ValueKey('source-cell-$role-${column.id ?? 'all'}');
    if (own.isNotEmpty) {
      return Column(
        key: key,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final s in own)
            InkWell(
              onTap: _canEdit ? () => _edit(s) : null,
              borderRadius: BorderRadius.circular(8),
              child: SizedBox(
                height: _entryHeight,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            _tableName(s),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        if (s['kind'] == 'mirror' || s['kind'] == 'native') ...[
                          const SizedBox(width: 6),
                          StatusPill(
                            s['kind'] == 'native' ? 'Native' : 'Mirror',
                            tone: s['kind'] == 'native'
                                ? Tone.ok
                                : Tone.neutral,
                          ),
                        ],
                      ],
                    ),
                    Text(
                      _detail(s),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: _stale(s)
                          ? muted?.copyWith(color: theme.colorScheme.error)
                          : muted,
                    ),
                  ],
                ),
              ),
            ),
        ],
      );
    }
    if (column.id != null && _inherited(role, column) != null) {
      return SizedBox(
        key: key,
        height: _entryHeight,
        child: Align(
          alignment: Alignment.centerLeft,
          child: Text('uses All', style: muted),
        ),
      );
    }
    return SizedBox(
      key: key,
      height: _entryHeight,
      child: Align(
        alignment: Alignment.centerLeft,
        child: _canEdit
            ? InkWell(
                onTap: () => _edit(null, role: role, branchId: column.id),
                borderRadius: BorderRadius.circular(8),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Text(
                    '— · Add',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.primary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              )
            : Text('—', style: muted),
      ),
    );
  }

  double _rowHeight(String role, List<_Column> columns) {
    var most = 1;
    for (final c in columns) {
      final n = _own(role, c.id).length;
      if (n > most) most = n;
    }
    return most * _entryHeight + 17; // padding and the rule below
  }

  Widget _matrix(List<_Column> columns, List<String> roles) {
    final theme = Theme.of(context);
    final line = BorderSide(color: theme.colorScheme.outlineVariant);
    final heading = theme.textTheme.labelMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w700,
    );
    final headerHeight = MediaQuery.textScalerOf(context).scale(36);
    Widget box(double width, double height, Widget child) => Container(
      width: width,
      height: height,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(border: Border(bottom: line)),
      child: child,
    );
    return Container(
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      clipBehavior: Clip.antiAlias,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // The role column stays put; only the branches scroll sideways.
          Container(
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerLow,
              border: Border(right: line),
            ),
            child: Column(
              children: [
                box(_roleWidth, headerHeight, const SizedBox()),
                for (final role in roles)
                  box(
                    _roleWidth,
                    _rowHeight(role, columns),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: Text(
                        '${_roles[role] ?? role}',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: SingleChildScrollView(
              key: const ValueKey('sources-matrix-horizontal'),
              scrollDirection: Axis.horizontal,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      for (final c in columns)
                        box(
                          _cellWidth,
                          headerHeight,
                          Align(
                            alignment: Alignment.centerLeft,
                            child: Text(
                              c.label.toUpperCase(),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: heading,
                            ),
                          ),
                        ),
                    ],
                  ),
                  for (final role in roles)
                    Row(
                      children: [
                        for (final c in columns)
                          box(
                            _cellWidth,
                            _rowHeight(role, columns),
                            _cell(role, c),
                          ),
                      ],
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Phone: one branch's column as a list of role rows.
  List<Widget> _narrow(List<_Column> columns, List<String> roles) {
    final theme = Theme.of(context);
    final column = columns.firstWhere(
      (c) => (c.id ?? _allBranches) == _narrowBranch,
      orElse: () => columns.first,
    );
    return [
      SearchPickerField<String>(
        key: const ValueKey('sources-narrow-branch'),
        label: 'Branch',
        value: column.id ?? _allBranches,
        options: [
          for (final c in columns)
            SearchOption(value: c.id ?? _allBranches, label: c.label),
        ],
        onChanged: (v) => setState(() => _narrowBranch = v ?? _allBranches),
      ),
      for (final role in roles)
        Container(
          margin: const EdgeInsets.only(top: 8),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            border: Border.all(color: theme.colorScheme.outlineVariant),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                '${_roles[role] ?? role}',
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              _cell(role, column),
            ],
          ),
        ),
    ];
  }

  Widget _filters(List<_Column> columns) {
    final role = SearchPickerField<String>(
      key: const ValueKey('sources-role-filter'),
      label: 'Role',
      hint: 'Any role',
      allowClear: true,
      isDense: true,
      value: _roleFilter,
      options: [
        for (final e in _roles.entries)
          SearchOption(value: e.key, label: '${e.value}'),
      ],
      onChanged: (v) => setState(() => _roleFilter = v),
    );
    final branch = SearchPickerField<String>(
      key: const ValueKey('sources-branch-filter'),
      label: 'Branch',
      hint: 'All branches',
      allowClear: true,
      isDense: true,
      value: _branchFilter,
      options: [
        for (final c in columns)
          if (c.id != null) SearchOption(value: c.id!, label: c.label),
      ],
      onChanged: (v) => setState(() => _branchFilter = v),
    );
    final search = ListSearch(
      key: const ValueKey('sources-search'),
      controller: _search,
      hint: 'Table or base',
      onChanged: (_) => setState(() {}),
    );
    return Wrap(
      spacing: 12,
      runSpacing: 12,
      children: [
        SizedBox(width: 220, child: role),
        SizedBox(width: 240, child: branch),
        SizedBox(width: 240, child: search),
      ],
    );
  }

  /// "N mapped · M branches · last copy …", and Copy now (it copies every
  /// table, so it sits here rather than on one cell).
  Widget _headerLine(int branches) {
    final theme = Theme.of(context);
    final newest = [
      for (final s in _sources)
        if (s['kind'] == 'mirror')
          ?DateTime.tryParse('${s['last_copied_at'] ?? ''}'),
    ]..sort();
    final text = [
      '${_sources.length} mapped',
      '$branches branch${branches == 1 ? '' : 'es'}',
      if (newest.isNotEmpty)
        'last copy ${sourcesAgo(newest.last.toIso8601String())}',
    ].join(' · ');
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              text,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            OutlinedButton.icon(
              key: const ValueKey('sources-copy-now'),
              icon: const Icon(Icons.sync),
              label: const Text('Copy now'),
              onPressed: _canCopy && !_copying ? _copyNow : null,
            ),
          ],
        ),
        if (!_canCopy)
          Text(
            'Copy now needs Financials edit.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        if (_copyNote != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              _copyNote!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: _copyFailed ? theme.colorScheme.error : null,
              ),
            ),
          ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final all = _data == null ? const <_Column>[] : _allColumns;
    final roles = _visibleRoles;
    return LayoutBuilder(
      builder: (context, constraints) {
        final narrow = constraints.maxWidth < 760;
        final columns = [
          for (final c in all)
            if (narrow ||
                _branchFilter == null ||
                c.id == null ||
                c.id == _branchFilter)
              c,
        ];
        final branches = all.isEmpty ? 0 : all.length - 1;
        return ListView(
          key: const ValueKey('tour-financials-sources'),
          padding: const EdgeInsets.all(16),
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    'Airtable sources',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                if (_canEdit && _data != null)
                  FilledButton.icon(
                    icon: const Icon(Icons.add),
                    label: const Text('Add source'),
                    onPressed: () => _edit(null),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            const Text(
              'Say which Airtable table holds which part of your books. The '
              'hourly Airtable copy and the weekly and monthly reports read '
              'this list.',
            ),
            if (!_canEdit)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Text(
                  'Changing these needs Financials edit, from the organization view.',
                ),
              ),
            if (_error != null) Text(_error!),
            if (_data == null && _error == null)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              ),
            if (_data != null) ...[
              const SizedBox(height: 8),
              _headerLine(branches),
              const SizedBox(height: 12),
              if (!narrow) ...[
                _filters(all),
                const SizedBox(height: 12),
                if (roles.isEmpty)
                  const Text('Nothing matches.')
                else
                  _matrix(columns, roles),
              ] else
                ..._narrow(columns, roles),
            ],
          ],
        );
      },
    );
  }
}

class _SourcePanel extends StatefulWidget {
  const _SourcePanel({
    required this.api,
    required this.me,
    required this.data,
    this.source,
    this.initialRole,
    this.initialPlace,
    this.onCopyNow,
  });

  final ApiClient api;
  final Me me;
  final Map<String, dynamic> data;
  final Map? source;

  /// A new source opened from an empty matrix cell starts on that cell's
  /// part of the books and branch.
  final String? initialRole;
  final String? initialPlace;

  /// Copy now, or null when this person may not (the panel says why).
  final Future<({String text, bool failed})> Function()? onCopyNow;

  @override
  State<_SourcePanel> createState() => _SourcePanelState();
}

class _SourcePanelState extends State<_SourcePanel> {
  late String? _role = widget.source?['role'] as String? ?? widget.initialRole;
  late String? _table = widget.source == null
      ? null
      : '${widget.source!['base_id']}/${widget.source!['table_id']}';
  late String? _place = widget.source == null
      ? widget.initialPlace
      : (widget.source!['franchise_id'] ?? widget.source!['company_id'])
            as String?;
  late final Map<String, TextEditingController> _map = {
    for (final k in (widget.data['field_keys'] as List).cast<String>())
      k: TextEditingController(
        text: (widget.source?['field_map'] as Map?)?[k] as String?,
      ),
  };
  late final _validFrom = TextEditingController(
    text: widget.source?['valid_from'] as String?,
  );
  late final _validUntil = TextEditingController(
    text: widget.source?['valid_until'] as String?,
  );
  late final _filterField = TextEditingController(
    text: widget.source?['filter_field'] as String?,
  );
  late final _filterValue = TextEditingController(
    text: widget.source?['filter_value'] as String?,
  );
  late final Map<String, TextEditingController> _markers = {
    for (final k
        in ((widget.data['marker_keys'] as List?) ?? const []).cast<String>())
      k: TextEditingController(
        text: (widget.source?['markers'] as Map?)?[k] as String?,
      ),
  };
  String? _error;
  bool _busy = false;

  /// Remove asks once: the first press turns the button into the real one.
  bool _confirmRemove = false;

  ({String text, bool failed})? _copied;

  Future<void> _copyNow() async {
    setState(() => _busy = true);
    final result = await widget.onCopyNow!();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _copied = result;
    });
  }

  static const _markerLabels = {
    'summary': 'Value marking a summary row (e.g. Weekly Summary)',
    'job': 'Value marking a job row (e.g. Job)',
    'skip': 'Value marking a row to leave out (e.g. Total)',
  };

  static const _keyLabels = {
    'amount': 'Amount column',
    'date': 'Date column',
    'company': 'Company column',
    'technician': 'Technician column',
    'team': 'Team column',
    'job_number': 'Job number column',
    'category': 'Category column',
    'kind': 'Row kind column',
    'status': 'Status column',
    'reason': 'Reason column',
    'total': 'Total column',
    'parts': 'Parts cost column',
    'tech_share': 'Technician share column',
    'external_share': 'External company share column',
    'contracting': 'Contracting profit column',
    'employee': 'Employee cost column',
    'helper': 'Helper cost column',
    'gas': 'Gas cost column',
    'operation': 'Operating cost column',
    'hours': 'Hours column',
  };

  Future<void> _save() async {
    if (_role == null || _table == null) {
      return setState(() => _error = 'Choose the part and the Airtable table.');
    }
    final parts = _table!.split('/');
    final franchise = widget.me.franchisesInView
        .where((f) => f.id == _place)
        .firstOrNull;
    final body = {
      'base_id': parts[0],
      'table_id': parts[1],
      'role': _role,
      'franchise_id': franchise?.id,
      'company_id': franchise?.companyId ?? (franchise == null ? _place : null),
      'valid_from': _validFrom.text.trim().isEmpty
          ? null
          : _validFrom.text.trim(),
      'valid_until': _validUntil.text.trim().isEmpty
          ? null
          : _validUntil.text.trim(),
      'filter_field': _filterField.text.trim().isEmpty
          ? null
          : _filterField.text.trim(),
      'filter_value': _filterValue.text.trim().isEmpty
          ? null
          : _filterValue.text.trim(),
      'field_map': {for (final e in _map.entries) e.key: e.value.text.trim()},
      'markers': {for (final e in _markers.entries) e.key: e.value.text.trim()},
    };
    setState(() => _busy = true);
    final r = widget.source == null
        ? await widget.api.post('/financials/sources', body)
        : await widget.api.patch(
            '/financials/sources/${widget.source!['id']}',
            body,
          );
    if (!mounted) return;
    setState(() => _busy = false);
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  Future<void> _delete() async {
    final r = await widget.api.delete(
      '/financials/sources/${widget.source!['id']}',
    );
    if (!mounted) return;
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  @override
  Widget build(BuildContext context) {
    final roles = (widget.data['roles'] as Map).cast<String, dynamic>();
    final tables = (widget.data['airtable_tables'] as List).cast<Map>();
    return SidePanel(
      title: widget.source == null ? 'Add source' : 'Edit source',
      subtitle: widget.source == null
          ? null
          : 'Base ${widget.source!['base_id']} · Table ${widget.source!['table_id']}',
      actions: [
        if (widget.source != null)
          TextButton(
            onPressed: _busy
                ? null
                : _confirmRemove
                ? _delete
                : () => setState(() => _confirmRemove = true),
            style: TextButton.styleFrom(
              foregroundColor: Theme.of(context).colorScheme.error,
            ),
            child: Text(_confirmRemove ? 'Remove for good' : 'Remove'),
          ),
        FilledButton(
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.source != null) ...[
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                key: const ValueKey('source-panel-copy-now'),
                icon: const Icon(Icons.sync),
                label: const Text('Copy now'),
                onPressed: widget.onCopyNow == null || _busy ? null : _copyNow,
              ),
            ),
            Padding(
              padding: const EdgeInsets.only(top: 4, bottom: 12),
              child: Text(
                _copied?.text ??
                    (widget.onCopyNow == null
                        ? 'Copy now needs Financials edit.'
                        : 'Copies every Airtable table, this one included.'),
                style: TextStyle(
                  color: _copied?.failed == true
                      ? Theme.of(context).colorScheme.error
                      : null,
                ),
              ),
            ),
          ],
          if (_confirmRemove)
            const Padding(
              padding: EdgeInsets.only(bottom: 12),
              child: Text(
                'Remove this source? The reports stop reading this table for '
                'this place. Press Remove for good to go ahead.',
              ),
            ),
          if (_error != null)
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          SearchPickerField<String>(
            label: 'Part of the books',
            value: _role,
            options: [
              for (final e in roles.entries)
                SearchOption(value: e.key, label: '${e.value}'),
            ],
            onChanged: (v) => setState(() => _role = v),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            label: 'Airtable table',
            value: _table,
            helperText: tables.isEmpty
                ? 'Nothing copied yet. Connect Airtable in Integrations.'
                : null,
            options: [
              for (final t in tables)
                SearchOption(
                  value: '${t['base_id']}/${t['table_id']}',
                  label: '${t['table_name']} (${t['rows']} rows)',
                ),
            ],
            onChanged: (v) => setState(() => _table = v),
          ),
          const SizedBox(height: 12),
          SearchPickerField<String>(
            label: 'Which company or place it covers',
            hint: 'All branches',
            allowClear: true,
            value: _place,
            options: [
              for (final c in widget.me.companiesInView)
                SearchOption(value: c.id, label: c.name),
              for (final b in branchOptionsInView(widget.me))
                SearchOption(value: b.id, label: b.label),
            ],
            onChanged: (v) => setState(() => _place = v),
          ),
          const SizedBox(height: 16),
          Text(
            'When it applies',
            style: Theme.of(context).textTheme.titleSmall,
          ),
          const Text(
            'Leave blank unless this table holds the books only for a stretch of time.',
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _validFrom,
              decoration: const InputDecoration(
                labelText: 'From (YYYY-MM-DD)',
                isDense: true,
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _validUntil,
              decoration: const InputDecoration(
                labelText: 'Until (YYYY-MM-DD)',
                isDense: true,
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text('Shared table', style: Theme.of(context).textTheme.titleSmall),
          const Text(
            'If this table holds rows for several places, say which rows are this '
            "place's: the column and the value it has on them.",
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _filterField,
              decoration: const InputDecoration(
                labelText: 'Column (e.g. Company Name)',
                isDense: true,
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextField(
              controller: _filterValue,
              decoration: const InputDecoration(
                labelText: 'Value (e.g. A1 CHIMNEY NJ)',
                isDense: true,
              ),
            ),
          ),
          const SizedBox(height: 16),
          Text('Row markers', style: Theme.of(context).textTheme.titleSmall),
          for (final e in _markers.entries)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: TextField(
                controller: e.value,
                decoration: InputDecoration(
                  labelText: _markerLabels[e.key] ?? e.key,
                  isDense: true,
                ),
              ),
            ),
          const SizedBox(height: 16),
          Text('Columns', style: Theme.of(context).textTheme.titleSmall),
          const Text(
            'Type the Airtable column name for each meaning this table has. Leave the rest blank.',
          ),
          for (final e in _map.entries)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: TextField(
                controller: e.value,
                decoration: InputDecoration(
                  labelText: _keyLabels[e.key] ?? e.key,
                  isDense: true,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// "4 min ago", "3 h ago", "2 d ago".
String sourcesAgo(String iso, {DateTime? now}) {
  final at = DateTime.tryParse(iso)?.toLocal();
  if (at == null) return 'at an unknown time';
  final minutes = (now ?? DateTime.now()).difference(at).inMinutes;
  if (minutes < 1) return 'just now';
  if (minutes < 60) return '$minutes min ago';
  if (minutes < 60 * 24) return '${minutes ~/ 60} h ago';
  return '${minutes ~/ (60 * 24)} d ago';
}
