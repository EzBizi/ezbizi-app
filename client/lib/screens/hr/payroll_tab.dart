import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/job_money.dart';
import '../../core/onboarding.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/async_body.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

/// Human Resources › Payroll (design 135, options 1 and 4).
///
/// Jose, 2026-10-22: "a new tab in human resources for payroll which basically
/// shows all the employees, and their salary, and if they have a W2 or not …
/// i also want to be able to modify the salary … sort of like a table view."
///
/// One row per person from `GET /hr/payroll`. Salary is the current pay rate
/// and arrives only for `hr.pay.view`; changing it adds a rate to the history
/// (`POST /hr/people/{id}/details/pay-rates`), so a raise keeps the old one.
/// Under 600 px the table becomes cards, and a card opens the person full
/// screen in place.
class PayrollTab extends StatefulWidget {
  const PayrollTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<PayrollTab> createState() => _PayrollTabState();
}

enum _W2 { everyone, signed, unsigned }

/// Employment type (accountant via Jose, 2026-11-02): `?type=`.
enum _Type { all, w2, t1099, unknown }

const _typeWords = {
  _Type.all: 'All types',
  _Type.w2: 'W-2',
  _Type.t1099: '1099',
  _Type.unknown: 'Unknown',
};

class _PayrollTabState extends State<PayrollTab> {
  bool _loading = true;
  String? _error;

  List<Map<String, dynamic>> _rows = const [];
  bool _canSee = false;
  bool _canEdit = false;

  /// Every role and contract seen in any answer, so a filter's own choice
  /// stays in its list after the server has narrowed the rows to it.
  final Map<String, String> _roles = {};
  final Map<String, String> _contracts = {};

  _W2 _w2 = _W2.everyone;
  _Type _type = _Type.all;
  String? _roleId;
  String? _contractId;
  bool _showLeft = true;
  final _search = TextEditingController();

  /// A download that failed, said above the list.
  String? _notice;

  /// The person open full screen on a phone.
  String? _phoneOpen;

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/hr/payroll', _query);
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _rows = [
        for (final row in response.data as List? ?? const [])
          if (row is Map) Map<String, dynamic>.from(row),
      ];
      final meta = response.body['meta'];
      _canSee = meta is Map && meta['can_see_salary'] == true;
      _canEdit = meta is Map && meta['can_edit_salary'] == true;
      for (final row in _rows) {
        if (row['role'] case {'id': final id?, 'name': final name?}) {
          _roles['$id'] = '$name';
        }
        if (row['contract'] case {'id': final id?, 'name': final name?}) {
          _contracts['$id'] = '$name';
        }
      }
    });
  }

  Map<String, String> get _query => {
    if (_w2 != _W2.everyone) 'w2': _w2 == _W2.signed ? 'signed' : 'unsigned',
    if (_type != _Type.all) 'type': _type == _Type.t1099 ? '1099' : _type.name,
    'role_id': ?_roleId,
    'contract_id': ?_contractId,
  };

  /// The same filters as a spreadsheet, from the server.
  Future<void> _export() async {
    setState(() => _notice = null);
    final response = await widget.api.getBytesResponse('/hr/payroll', {
      ..._query,
      'format': 'csv',
    });
    if (!mounted) return;
    final bytes = response.bytes;
    if (!response.ok || bytes == null) {
      setState(
        () => _notice = response.message ?? 'The export could not be made.',
      );
      return;
    }
    Files.download('payroll.csv', bytes);
  }

  Widget _exportButton() => OutlinedButton.icon(
    key: const Key('payroll-export'),
    icon: const Icon(Icons.download, size: 18),
    label: const Text('Export'),
    onPressed: _export,
  );

  Widget _typeChips() => Wrap(
    spacing: 6,
    runSpacing: 6,
    children: [
      for (final t in _Type.values)
        ChoiceChip(
          key: Key('payroll-type-${t.name}'),
          label: Text(_typeWords[t]!),
          selected: _type == t,
          onSelected: (_) {
            setState(() => _type = t);
            _load();
          },
        ),
    ],
  );

  /// "Company · Branch" for each assignment, one per line.
  static String _assigned(Map<String, dynamic> row) {
    final list = row['assigned'] as List? ?? const [];
    final lines = [
      for (final a in list)
        if (a is Map)
          [
            a['company'],
            a['branch'],
          ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
    ].where((l) => l.isNotEmpty);
    return lines.isEmpty ? '—' : lines.join('\n');
  }

  static String _typeOf(Map<String, dynamic> row) =>
      '${row['employment_type'] ?? 'Unknown'}';

  /// Left: switched off, or a termination date on record.
  static bool _left(Map<String, dynamic> row) =>
      row['is_active'] == false || row['terminated_on'] != null;

  /// Searched and, unless asked for, without the people who left; those who
  /// left sort last.
  List<Map<String, dynamic>> get _shown {
    final query = _search.text.trim().toLowerCase();
    final rows = [
      for (final row in _rows)
        if ('${row['name'] ?? ''}'.toLowerCase().contains(query) &&
            (_showLeft || !_left(row)))
          row,
    ];
    final here = [
      for (final r in rows)
        if (!_left(r)) r,
    ];
    final gone = [
      for (final r in rows)
        if (_left(r)) r,
    ];

    return [...here, ...gone];
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      key: const ValueKey('tour-hr-payroll'),
      builder: (context, box) =>
          box.maxWidth < 600 ? _phone(context) : _desktop(context),
    );
  }

  // ── filters ──────────────────────────────────────────────────────

  Widget _w2Segment({bool compact = false}) => SegmentedButton<_W2>(
    key: const Key('payroll-w2-filter'),
    showSelectedIcon: false,
    style: const ButtonStyle(visualDensity: VisualDensity.compact),
    segments: [
      const ButtonSegment(value: _W2.everyone, label: Text('Everyone')),
      ButtonSegment(
        value: _W2.signed,
        label: Text(compact ? 'Signed' : 'W2 signed'),
      ),
      ButtonSegment(
        value: _W2.unsigned,
        label: Text(compact ? 'Not signed' : 'W2 not signed'),
      ),
    ],
    selected: {_w2},
    onSelectionChanged: (picked) {
      setState(() => _w2 = picked.first);
      _load();
    },
  );

  Widget _rolePicker() => SearchPickerField<String>(
    key: const Key('payroll-role-filter'),
    value: _roleId ?? '',
    label: 'Role',
    isDense: true,
    border: const OutlineInputBorder(),
    options: [
      const SearchOption(value: '', label: 'Any role'),
      for (final MapEntry(:key, :value) in _roles.entries)
        SearchOption(value: key, label: value),
    ],
    onChanged: (value) {
      setState(() => _roleId = (value ?? '').isEmpty ? null : value);
      _load();
    },
  );

  Widget _contractPicker() => SearchPickerField<String>(
    key: const Key('payroll-contract-filter'),
    value: _contractId ?? '',
    label: 'Contract',
    isDense: true,
    border: const OutlineInputBorder(),
    options: [
      const SearchOption(value: '', label: 'Any contract'),
      for (final MapEntry(:key, :value) in _contracts.entries)
        SearchOption(value: key, label: value),
    ],
    onChanged: (value) {
      setState(() => _contractId = (value ?? '').isEmpty ? null : value);
      _load();
    },
  );

  Widget _searchBox() => TextField(
    key: const Key('payroll-search'),
    controller: _search,
    onChanged: (_) => setState(() {}),
    decoration: const InputDecoration(
      hintText: 'Search people',
      prefixIcon: Icon(Icons.search),
      isDense: true,
    ),
  );

  Widget _showLeftChip() => FilterChip(
    key: const Key('payroll-show-left'),
    label: const Text('Show people who left'),
    selected: _showLeft,
    onSelected: (on) => setState(() => _showLeft = on),
  );

  // ── desktop 1: the table ─────────────────────────────────────────

  static const _flex = [16, 14, 9, 7, 16, 9, 9, 10, 14];

  Widget _desktop(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          key: const ValueKey('tour-hr-payroll-filters'),
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Wrap(
            spacing: 12,
            runSpacing: 10,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              _w2Segment(),
              Text('Type', style: Theme.of(context).textTheme.labelMedium),
              const SizedBox(height: 4),
              _typeChips(),
              SizedBox(width: 200, child: _rolePicker()),
              SizedBox(width: 220, child: _contractPicker()),
              SizedBox(width: 220, child: _searchBox()),
              KeyedSubtree(
                key: const ValueKey('tour-hr-payroll-left'),
                child: _showLeftChip(),
              ),
              _exportButton(),
            ],
          ),
        ),
        if (_notice != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: ErrorNote(_notice!),
          ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: LayoutBuilder(
              builder: (context, box) {
                // Seven columns need room; narrower, the table scrolls
                // sideways rather than squeezing a date onto three lines.
                final width = box.maxWidth < 1240 ? 1240.0 : box.maxWidth;
                final rows = _shown;

                return SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: SizedBox(
                    width: width,
                    child: Column(
                      key: const ValueKey('tour-hr-payroll-table'),
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _header(context),
                        const Divider(height: 1),
                        Expanded(
                          child: rows.isEmpty
                              ? Padding(
                                  padding: const EdgeInsets.all(16),
                                  child: Text(
                                    _rows.isEmpty
                                        ? 'Nobody to show yet.'
                                        : 'Nobody matches.',
                                    style: theme.textTheme.bodyMedium,
                                  ),
                                )
                              : ListView.separated(
                                  padding: const EdgeInsets.only(bottom: 24),
                                  itemCount: rows.length,
                                  separatorBuilder: (_, _) =>
                                      const Divider(height: 1),
                                  itemBuilder: (context, i) =>
                                      _tableRow(context, rows[i]),
                                ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ],
    );
  }

  Widget _cell(int column, Widget child) => Expanded(
    flex: _flex[column],
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: child,
    ),
  );

  Widget _header(BuildContext context) {
    final theme = Theme.of(context);
    Widget label(String text, [Key? key]) => Text(
      text.toUpperCase(),
      key: key,
      style: theme.textTheme.labelSmall?.copyWith(
        letterSpacing: 1.1,
        color: theme.colorScheme.onSurfaceVariant,
      ),
    );

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      child: Row(
        children: [
          _cell(0, label('Employee')),
          _cell(1, label('Salary', const ValueKey('tour-hr-payroll-salary'))),
          _cell(2, label('W2', const ValueKey('tour-hr-payroll-w2'))),
          _cell(3, label('Type')),
          _cell(4, label('Assigned')),
          _cell(5, label('Start date')),
          _cell(6, label('Terminated')),
          _cell(7, label('Role')),
          _cell(
            8,
            label('Contract', const ValueKey('tour-hr-payroll-contract')),
          ),
        ],
      ),
    );
  }

  Widget _tableRow(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final id = '${row['id']}';
    final role = row['role'] is Map ? (row['role'] as Map)['name'] : null;

    return Opacity(
      key: Key('payroll-row-$id'),
      opacity: _left(row) ? 0.55 : 1,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
        child: Row(
          children: [
            _cell(
              0,
              Text(
                '${row['name'] ?? ''}',
                style: theme.textTheme.titleSmall,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            _cell(1, _salaryCell(context, row)),
            _cell(2, _w2Cell(context, row)),
            _cell(3, Text(_typeOf(row), key: Key('payroll-type-of-$id'))),
            _cell(4, Text(_assigned(row), key: Key('payroll-assigned-$id'))),
            _cell(5, Text(_date(row['started_on']))),
            _cell(6, Text(_date(row['terminated_on']))),
            _cell(
              7,
              Text(
                role == null ? '—' : '$role',
                overflow: TextOverflow.ellipsis,
              ),
            ),
            _cell(8, _contractCell(context, row)),
          ],
        ),
      ),
    );
  }

  Widget _salaryCell(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final id = '${row['id']}';

    if (!_canSee) {
      return Tooltip(
        key: Key('payroll-salary-hidden-$id'),
        message: 'Your role cannot see pay, so salaries are not shown.',
        child: const Text('—'),
      );
    }

    final salary = row['salary'] is Map
        ? Map<String, dynamic>.from(row['salary'] as Map)
        : null;

    return InkWell(
      key: Key('payroll-salary-$id'),
      onTap: () => _openSalary(row),
      borderRadius: BorderRadius.circular(EzBiziTheme.controlRadius),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              salary == null ? 'No rate recorded' : _rate(salary),
              style: salary == null
                  ? theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.outline,
                    )
                  : theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.primary,
                      fontWeight: FontWeight.w600,
                    ),
            ),
            if (salary?['effective_on'] != null)
              Text(
                'since ${_date(salary!['effective_on'])}',
                style: theme.textTheme.bodySmall,
              ),
          ],
        ),
      ),
    );
  }

  Widget _w2Chip(Map<String, dynamic> row) {
    final signed = (row['w2'] as Map?)?['signed'] == true;

    return signed
        ? const StatusPill('✓ Signed', tone: Tone.ok)
        : const StatusPill('✕ Not signed', tone: Tone.bad);
  }

  Widget _w2Cell(BuildContext context, Map<String, dynamic> row) {
    final attachment = (row['w2'] as Map?)?['attachment_id'];

    return Row(
      children: [
        Flexible(child: _w2Chip(row)),
        if (attachment != null)
          IconButton(
            key: Key('payroll-w2-download-${row['id']}'),
            tooltip: 'Download the signed W2',
            visualDensity: VisualDensity.compact,
            iconSize: 18,
            icon: const Icon(Icons.download_outlined),
            onPressed: () => _downloadW2(row),
          ),
      ],
    );
  }

  /// A contract that has been signed, as far as the employee's half: what
  /// is still being filled in has no PDF to give yet.
  static bool _contractDownloadable(Map<String, dynamic> contract) =>
      contract['onboarding_id'] != null && contract['status'] != 'in_progress';

  Widget _contractCell(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final contract = row['contract'] is Map
        ? Map<String, dynamic>.from(row['contract'] as Map)
        : null;
    if (contract == null) return const Text('—');

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${contract['name'] ?? 'Contract'}',
                overflow: TextOverflow.ellipsis,
              ),
              if (contract['status'] != null)
                Text(
                  onboardingStatusLabel('${contract['status']}'),
                  style: theme.textTheme.bodySmall,
                ),
            ],
          ),
        ),
        if (_contractDownloadable(contract))
          IconButton(
            key: Key('payroll-contract-download-${row['id']}'),
            tooltip: 'Download the contract',
            visualDensity: VisualDensity.compact,
            iconSize: 18,
            icon: const Icon(Icons.download_outlined),
            onPressed: () => _downloadContract(row),
          ),
      ],
    );
  }

  // ── phone 4: chips and cards ─────────────────────────────────────

  Widget _phone(BuildContext context) {
    final theme = Theme.of(context);

    if (_phoneOpen case final id?) {
      for (final row in _rows) {
        if ('${row['id']}' == id) {
          return SidePanel(
            key: const Key('payroll-phone-person'),
            docked: true,
            title: '${row['name'] ?? ''}',
            onBack: () => setState(() => _phoneOpen = null),
            child: _person(context, row),
          );
        }
      }
    }

    final rows = _shown;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
        children: [
          Column(
            key: const ValueKey('tour-hr-payroll-filters'),
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Two chip rows both said W-2; a label names each question.
              Text('W-2 form', style: Theme.of(context).textTheme.labelMedium),
              const SizedBox(height: 4),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final (value, label) in const [
                    (_W2.everyone, 'Everyone'),
                    (_W2.signed, 'W2 signed'),
                    (_W2.unsigned, 'W2 not signed'),
                  ])
                    ChoiceChip(
                      key: Key('payroll-w2-chip-${value.name}'),
                      label: Text(label),
                      selected: _w2 == value,
                      onSelected: (_) {
                        setState(() => _w2 = value);
                        _load();
                      },
                    ),
                  KeyedSubtree(
                    key: const ValueKey('tour-hr-payroll-left'),
                    child: _showLeftChip(),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              _typeChips(),
              const SizedBox(height: 10),
              _rolePicker(),
              const SizedBox(height: 10),
              _searchBox(),
              const SizedBox(height: 10),
              _exportButton(),
            ],
          ),
          if (_notice != null) ...[
            const SizedBox(height: 10),
            ErrorNote(_notice!),
          ],
          const SizedBox(height: 12),
          if (rows.isEmpty)
            Text(
              _rows.isEmpty ? 'Nobody to show yet.' : 'Nobody matches.',
              style: theme.textTheme.bodyMedium,
            ),
          Column(
            key: const ValueKey('tour-hr-payroll-table'),
            children: [for (final row in rows) _card(context, row)],
          ),
        ],
      ),
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final salary = row['salary'] is Map
        ? Map<String, dynamic>.from(row['salary'] as Map)
        : null;
    final role = row['role'] is Map ? (row['role'] as Map)['name'] : null;
    final assigned = _assigned(row);
    final line = [
      if (!_canSee) 'Pay hidden' else if (salary != null) _rate(salary),
      ?role?.toString(),
      if (row['started_on'] != null) 'since ${_date(row['started_on'])}',
    ].join(' · ');

    return Opacity(
      opacity: _left(row) ? 0.55 : 1,
      child: Card(
        key: Key('payroll-card-${row['id']}'),
        margin: const EdgeInsets.only(bottom: 8),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => setState(() => _phoneOpen = '${row['id']}'),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${row['name'] ?? ''}',
                        style: theme.textTheme.titleSmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: 8),
                    StatusPill(_typeOf(row)),
                    const SizedBox(width: 6),
                    _w2Chip(row),
                  ],
                ),
                if (line.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(line, style: theme.textTheme.bodySmall),
                ],
                if (assigned != '—')
                  Text(
                    assigned.replaceAll('\n', ', '),
                    style: theme.textTheme.bodySmall,
                  ),
                if (row['terminated_on'] != null)
                  Text(
                    'Left ${_date(row['terminated_on'])}',
                    style: theme.textTheme.bodySmall,
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// One person on a phone: their documents and dates, then their pay.
  Widget _person(BuildContext context, Map<String, dynamic> row) {
    final theme = Theme.of(context);
    final contract = row['contract'] is Map
        ? Map<String, dynamic>.from(row['contract'] as Map)
        : null;
    final role = row['role'] is Map ? (row['role'] as Map)['name'] : null;
    Widget fact(String label, String value) => Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.outline,
              ),
            ),
          ),
          Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_notice != null) ...[
          ErrorNote(_notice!),
          const SizedBox(height: 10),
        ],
        fact('Role', role == null ? '—' : '$role'),
        fact('Start date', _date(row['started_on'])),
        fact('Terminated', _date(row['terminated_on'])),
        const SizedBox(height: 4),
        Row(
          children: [
            SizedBox(
              width: 110,
              child: Text(
                'W2',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ),
            Expanded(child: _w2Cell(context, row)),
          ],
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            SizedBox(
              width: 110,
              child: Text(
                'Contract',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.outline,
                ),
              ),
            ),
            Expanded(
              child: contract == null
                  ? const Text('—')
                  : _contractCell(context, row),
            ),
          ],
        ),
        const SizedBox(height: 20),
        if (_canSee)
          SalaryPane(
            api: widget.api,
            formats: _formats,
            personId: '${row['id']}',
            current: row['salary'] is Map
                ? Map<String, dynamic>.from(row['salary'] as Map)
                : null,
            canEdit: _canEdit,
            onSaved: _load,
          )
        else
          Text(
            'Your role cannot see pay, so this person’s salary is not shown.',
            style: theme.textTheme.bodySmall,
          ),
      ],
    );
  }

  // ── acts ─────────────────────────────────────────────────────────

  Future<void> _openSalary(Map<String, dynamic> row) => showSidePanel<void>(
    context,
    builder: (panel) => SidePanel(
      key: const Key('payroll-salary-pane'),
      title: '${row['name'] ?? ''}',
      subtitle: 'Salary',
      onClose: () => Navigator.of(panel).pop(),
      child: SalaryPane(
        api: widget.api,
        formats: _formats,
        personId: '${row['id']}',
        current: row['salary'] is Map
            ? Map<String, dynamic>.from(row['salary'] as Map)
            : null,
        canEdit: _canEdit,
        onSaved: _load,
      ),
    ),
  );

  Future<void> _downloadW2(Map<String, dynamic> row) async {
    final id = (row['w2'] as Map?)?['attachment_id'];
    if (id == null) return;
    setState(() => _notice = null);
    final response = await widget.api.getBytesResponse('/attachments/$id');
    if (!mounted) return;
    final bytes = response.bytes;
    if (!response.ok || bytes == null) {
      setState(
        () => _notice = response.message ?? 'The W2 could not be downloaded.',
      );
      return;
    }
    // The signed form is a picture of the page since HR caae3171; one signed
    // before that is a PDF.
    final pdf =
        bytes.length > 4 && String.fromCharCodes(bytes.take(4)) == '%PDF';
    Files.download(
      'W2 - ${row['name'] ?? 'signed'}.${pdf ? 'pdf' : 'png'}',
      bytes,
    );
  }

  Future<void> _downloadContract(Map<String, dynamic> row) async {
    final contract = row['contract'] as Map?;
    final onboarding = contract?['onboarding_id'];
    if (onboarding == null) return;
    setState(() => _notice = null);
    final response = await widget.api.getBytesResponse(
      '/hr/onboardings/$onboarding/document/pdf',
    );
    if (!mounted) return;
    final bytes = response.bytes;
    if (!response.ok || bytes == null) {
      setState(
        () => _notice = response.message ?? 'The contract could not be made.',
      );
      return;
    }
    final name = [
      if (contract?['name'] != null) '${contract!['name']}',
      if (row['name'] != null) '${row['name']}',
    ].join(' - ');
    Files.download('${name.isEmpty ? 'Contract' : name}.pdf', bytes);
  }

  String _date(Object? iso) {
    final at = iso is String ? DateTime.tryParse(iso) : null;

    return at == null ? '—' : _formats.formatDate(at);
  }
}

const _periods = {
  'hour': 'an hour',
  'week': 'a week',
  'month': 'a month',
  'year': 'a year',
};

/// "$52,000 a year", "$24.50 an hour".
String _rate(Map<String, dynamic> rate) {
  final amount = num.tryParse('${rate['amount'] ?? ''}');
  final money = amount == null
      ? '${rate['amount'] ?? ''}'
      : () {
          final minor = (amount * 100).round();
          return formatMoney(minor, cents: minor % 100 != 0);
        }();

  return '$money ${_periods[rate['period']] ?? rate['period'] ?? ''}'.trim();
}

/// One person's pay: a new rate (for `hr.pay.edit`) and the history under
/// it. A raise is a new rate from a new date; the old one stays listed.
class SalaryPane extends StatefulWidget {
  const SalaryPane({
    super.key,
    required this.api,
    required this.formats,
    required this.personId,
    required this.current,
    required this.canEdit,
    required this.onSaved,
  });

  final ApiClient api;
  final DisplayFormats formats;
  final String personId;
  final Map<String, dynamic>? current;
  final bool canEdit;
  final VoidCallback onSaved;

  @override
  State<SalaryPane> createState() => _SalaryPaneState();
}

class _SalaryPaneState extends State<SalaryPane> {
  late final _amount = TextEditingController(
    text: '${widget.current?['amount'] ?? ''}',
  );
  late final _from = TextEditingController(
    text: formatCalendarDay(DateTime.now()),
  );
  final _note = TextEditingController();
  late String _period = '${widget.current?['period'] ?? 'year'}';
  late String _basis = '${widget.current?['basis'] ?? 'salary'}';

  bool _loadingHistory = true;
  String? _historyError;
  List<Map<String, dynamic>> _history = const [];

  bool _saving = false;
  String? _problem;
  bool _saved = false;

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  @override
  void dispose() {
    _amount.dispose();
    _from.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _loadHistory() async {
    final response = await widget.api.get('/hr/people/${widget.personId}');
    if (!mounted) return;
    setState(() {
      _loadingHistory = false;
      if (!response.ok) {
        _historyError = response.reason;
        return;
      }
      final data = response.data;
      _history = [
        if (data is Map)
          for (final row in data['pay_rates'] as List? ?? const [])
            if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  Future<void> _save() async {
    final amount = num.tryParse(_amount.text.trim().replaceAll(',', ''));
    final from = _from.text.trim();
    setState(() {
      _saved = false;
      _problem = amount == null || amount < 0
          ? 'Enter the amount as a number.'
          : from.isEmpty
          ? 'Enter the date it takes effect.'
          : null;
    });
    if (_problem != null) return;

    setState(() => _saving = true);
    final note = _note.text.trim();
    final response = await widget.api
        .post('/hr/people/${widget.personId}/details/pay-rates', {
          'basis': _basis,
          'amount': amount,
          'period': _period,
          'effective_on': from,
          if (note.isNotEmpty) 'note': note,
        });
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (!response.ok) {
        _problem = response.reason;
        return;
      }
      _saved = true;
      _note.clear();
    });
    if (!response.ok) return;
    widget.onSaved();
    await _loadHistory();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final heading = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.outline,
      fontWeight: FontWeight.w700,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Now', style: heading),
        const SizedBox(height: 4),
        Text(
          widget.current == null ? 'No rate recorded.' : _rate(widget.current!),
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 20),
        if (widget.canEdit) ...[
          Text('Change it', style: heading),
          const SizedBox(height: 8),
          TextField(
            key: const Key('payroll-rate-amount'),
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'New amount',
              prefixText: r'$ ',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          Text('Per', style: theme.textTheme.bodySmall),
          const SizedBox(height: 4),
          SegmentedButton<String>(
            key: const Key('payroll-rate-period'),
            showSelectedIcon: false,
            style: const ButtonStyle(visualDensity: VisualDensity.compact),
            segments: const [
              ButtonSegment(value: 'hour', label: Text('Hour')),
              ButtonSegment(value: 'week', label: Text('Week')),
              ButtonSegment(value: 'month', label: Text('Month')),
              ButtonSegment(value: 'year', label: Text('Year')),
            ],
            selected: {_period},
            onSelectionChanged: (v) => setState(() => _period = v.first),
          ),
          const SizedBox(height: 12),
          Text('Paid', style: theme.textTheme.bodySmall),
          const SizedBox(height: 4),
          SegmentedButton<String>(
            key: const Key('payroll-rate-basis'),
            showSelectedIcon: false,
            style: const ButtonStyle(visualDensity: VisualDensity.compact),
            segments: const [
              ButtonSegment(value: 'salary', label: Text('Salary')),
              ButtonSegment(value: 'hourly', label: Text('Hourly')),
            ],
            selected: {_basis},
            onSelectionChanged: (v) => setState(() => _basis = v.first),
          ),
          const SizedBox(height: 12),
          CalendarDayField(
            key: const Key('payroll-rate-from'),
            controller: _from,
            formats: widget.formats,
            label: 'Takes effect',
            firstDate: DateTime(1900),
            lastDate: DateTime(DateTime.now().year + 2),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('payroll-rate-note'),
            controller: _note,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Note',
              hintText: 'For example, annual raise',
              border: OutlineInputBorder(),
            ),
          ),
          if (_problem != null) ...[
            const SizedBox(height: 10),
            ErrorNote(_problem!),
          ],
          if (_saved) ...[
            const SizedBox(height: 10),
            Text(
              'Saved. The old rate stays in the history below.',
              key: const Key('payroll-rate-saved'),
              style: theme.textTheme.bodySmall,
            ),
          ],
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              key: const Key('payroll-rate-save'),
              onPressed: _saving ? null : _save,
              child: Text(_saving ? 'Saving…' : 'Save the new rate'),
            ),
          ),
          const SizedBox(height: 20),
        ] else ...[
          Text(
            'Your role can see pay but not change it.',
            key: const Key('payroll-rate-read-only'),
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 20),
        ],
        Text('History', style: heading),
        const SizedBox(height: 6),
        if (_loadingHistory)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: LinearProgressIndicator(),
          )
        else if (_historyError != null)
          ErrorNote(_historyError!)
        else if (_history.isEmpty)
          Text('No rate recorded.', style: theme.textTheme.bodySmall)
        else
          Column(
            key: const Key('payroll-history'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final rate in _history)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Text(
                    [
                      _day(rate['effective_on']),
                      _rate(rate),
                      if ('${rate['note'] ?? ''}'.trim().isNotEmpty)
                        '${rate['note']}'.trim(),
                    ].join(' · '),
                    style: theme.textTheme.bodyMedium,
                  ),
                ),
            ],
          ),
      ],
    );
  }

  String _day(Object? iso) {
    final at = iso is String ? DateTime.tryParse(iso) : null;

    return at == null ? '—' : widget.formats.formatDate(at);
  }
}
