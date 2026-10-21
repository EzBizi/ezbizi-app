import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../widgets/grouped_rows.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';
import 'invoice_common.dart';
import 'invoice_panel.dart';
import 'invoice_settings.dart';
import 'invoice_sheets.dart';

/// Financials › Invoices (design 128, options 1 and 4): Charge sheets |
/// Invoices | Settings, each on its own tick in Roles & permissions.
class InvoicesTab extends StatefulWidget {
  const InvoicesTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<InvoicesTab> createState() => _InvoicesTabState();
}

class _InvoicesTabState extends State<InvoicesTab> {
  String _tab = 'charge_sheets';

  /// An invoice a sheet's chip asked for, opened when the list draws.
  String? _openInvoice;

  /// Bumped to make the list load again after Make invoices.
  int _listGeneration = 0;

  Me get _me => widget.session.me!;

  @override
  Widget build(BuildContext context) {
    final tabs = [
      if (_me.can('invoices.charge_sheets.view'))
        ('charge_sheets', 'Charge sheets', Icons.grid_on_outlined),
      if (_me.can('invoices.invoices.view'))
        ('invoices', 'Invoices', Icons.receipt_outlined),
      // The registry's Settings tab is the edit tick.
      if (_me.can('invoices.edit')) ('settings', 'Settings', Icons.tune),
    ];
    if (tabs.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text('No Invoices tabs have been granted to your role.'),
        ),
      );
    }
    final keys = [for (final t in tabs) t.$1];
    final selected = keys.contains(_tab) ? _tab : keys.first;

    void go(String key) => setState(() => _tab = key);

    final body = switch (selected) {
      'charge_sheets' => ChargeSheetsTab(
        api: widget.api,
        me: _me,
        onMade: () => setState(() {
          _listGeneration++;
          if (keys.contains('invoices')) _tab = 'invoices';
        }),
        onOpenInvoice: (id) => setState(() {
          _openInvoice = id;
          if (keys.contains('invoices')) _tab = 'invoices';
        }),
      ),
      'invoices' => InvoiceListTab(
        key: ValueKey('invoice-list-$_listGeneration'),
        api: widget.api,
        me: _me,
        initialOpenId: _openInvoice,
        onOpened: () => _openInvoice = null,
      ),
      _ => InvoiceSettingsTab(api: widget.api, me: _me),
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TabBarLike(
          key: const ValueKey('tour-invoices-tabs'),
          level: TabLevel.secondary,
          labels: [for (final t in tabs) (t.$2, t.$3)],
          selected: keys.indexOf(selected),
          onSelected: (i) => go(keys[i]),
        ),
        Expanded(child: body),
      ],
    );
  }
}

/// The invoices: totals, filters, a table (cards on a phone), and the open
/// invoice docked beside it, or in place of the list on a phone.
class InvoiceListTab extends StatefulWidget {
  const InvoiceListTab({
    super.key,
    required this.api,
    required this.me,
    this.initialOpenId,
    this.onOpened,
  });

  final ApiClient api;
  final Me me;
  final String? initialOpenId;
  final VoidCallback? onOpened;

  @override
  State<InvoiceListTab> createState() => _InvoiceListTabState();
}

class _InvoiceListTabState extends State<InvoiceListTab> {
  List<Map<String, dynamic>>? _rows;
  Map _totals = const {};
  String? _error;
  String? _status;

  /// `YYYY-MM` of the period shown, or null for any time.
  String? _month;

  /// The branch shown, or null for every branch in view.
  String? _branch;
  late String? _openId = widget.initialOpenId;

  static const _statuses = [
    (null, 'Any status'),
    ('draft', 'Draft'),
    ('approved', 'Approved'),
    ('sent', 'Sent'),
    ('paid', 'Paid'),
    ('overdue', 'Overdue'),
    ('void', 'Void'),
  ];

  @override
  void initState() {
    super.initState();
    if (widget.initialOpenId != null) widget.onOpened?.call();
    _load();
  }

  Future<void> _load() async {
    final query = <String, Object>{
      'status': ?_status,
      'franchise_id': ?_branch,
    };
    if (_month case final m?) {
      final first = DateTime.parse('$m-01');
      query['from'] = isoDay(first);
      query['to'] = isoDay(DateTime(first.year, first.month + 1, 0));
    }
    final r = await widget.api.get('/invoices', query.isEmpty ? null : query);
    if (!mounted) return;
    setState(() {
      if (r.ok) {
        _rows = (r.body['data'] as List).cast<Map<String, dynamic>>();
        _totals = r.body['totals'] as Map? ?? const {};
        _error = null;
      } else {
        _rows = const [];
        _error = r.reason;
      }
    });
  }

  void _changed(Map invoice) {
    final rows = _rows;
    if (rows == null) return;
    setState(() {
      final i = rows.indexWhere((r) => r['id'] == invoice['id']);
      if (i >= 0) rows[i] = invoice.cast<String, dynamic>();
    });
    // The totals move with a payment or a send.
    _load();
  }

  List<SearchOption<String>> get _months {
    final now = DateTime.now();
    return [
      const SearchOption(value: null, label: 'Any time'),
      for (var i = 0; i < 18; i++)
        () {
          final d = DateTime(now.year, now.month - i, 1);
          final key = '${d.year}-${d.month.toString().padLeft(2, '0')}';
          return SearchOption(
            value: key,
            label: '${_monthNames[d.month - 1]} ${d.year}',
          );
        }(),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    if (rows == null) return const Center(child: CircularProgressIndicator());

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < invoicesPhoneWidth;
        final open = _openId;
        if (phone && open != null) return _panel(open, inPlace: true);

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: EdgeInsets.fromLTRB(
                phone ? 12 : 24,
                12,
                phone ? 12 : 24,
                4,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _totalsRow(context, phone: phone),
                  const SizedBox(height: 8),
                  _filters(context, phone: phone),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        _error!,
                        key: const ValueKey('invoice-list-error'),
                      ),
                    ),
                ],
              ),
            ),
            Expanded(child: _body(context, rows, phone: phone)),
          ],
        );
      },
    );
  }

  Widget _totalsRow(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    Widget total(String key, String label, Object? cents) => Padding(
      padding: const EdgeInsets.only(right: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: theme.textTheme.bodySmall),
          Text(
            invoiceMoney((cents as num?)?.toInt() ?? 0),
            key: ValueKey(key),
            style: theme.textTheme.titleMedium,
          ),
        ],
      ),
    );
    return Wrap(
      key: const ValueKey('tour-invoices-totals'),
      runSpacing: 8,
      children: [
        if (!phone) total('invoice-total-sent', 'Sent', _totals['sent_cents']),
        total('invoice-total-unpaid', 'Unpaid', _totals['unpaid_cents']),
        total('invoice-total-overdue', 'Overdue', _totals['overdue_cents']),
      ],
    );
  }

  Widget _filters(BuildContext context, {required bool phone}) {
    return Wrap(
      key: const ValueKey('tour-invoices-filters'),
      spacing: 6,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (final (value, label) in _statuses)
          ChoiceChip(
            key: ValueKey('invoice-filter-${value ?? 'any'}'),
            label: Text(label),
            selected: _status == value,
            onSelected: (_) {
              setState(() => _status = value);
              _load();
            },
          ),
        // The branches in the current view, never all of reach.
        if (branchOptionsInView(widget.me, activeOnly: false)
            case final branches when branches.length > 1)
          SizedBox(
            width: phone ? double.infinity : 260,
            child: SearchPickerField<String>(
              key: const ValueKey('invoice-filter-branch'),
              label: 'Branch',
              isDense: true,
              value: _branch,
              options: [
                const SearchOption(value: null, label: 'All branches'),
                for (final b in branches)
                  SearchOption(value: b.id, label: b.label),
              ],
              onChanged: (id) {
                setState(() => _branch = id);
                _load();
              },
            ),
          ),
        SizedBox(
          width: phone ? double.infinity : 220,
          child: SearchPickerField<String>(
            key: const ValueKey('invoice-filter-period'),
            label: 'Period',
            isDense: true,
            value: _month,
            options: _months,
            onChanged: (m) {
              setState(() => _month = m);
              _load();
            },
          ),
        ),
      ],
    );
  }

  Widget _panel(String id, {required bool inPlace}) => InvoicePanel(
    key: ValueKey('invoice-panel-$id'),
    api: widget.api,
    me: widget.me,
    invoiceId: id,
    inPlace: inPlace,
    onClose: () => setState(() => _openId = null),
    onChanged: _changed,
  );

  String _id(Map<String, dynamic> row) => '${row['id']}';

  Widget _pill(Map row) {
    final s = invoiceStatus(row);
    return StatusPill(s.$1, tone: s.$2);
  }

  Widget _tile(BuildContext context, Map<String, dynamic> row) => TwoLineTile(
    key: ValueKey('invoice-card-${row['id']}'),
    title: '${row['number']} · ${(row['bill_to'] as Map?)?['name'] ?? ''}',
    subtitle:
        '${invoiceMoney((row['total_cents'] as num?)?.toInt() ?? 0)} · due '
        '${invoiceDay(row['due_on'], widget.me.display)}',
    bottomRight: _pill(row),
  );

  Widget _body(
    BuildContext context,
    List<Map<String, dynamic>> rows, {
    required bool phone,
  }) {
    if (rows.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            _status == null && _month == null && _branch == null
                ? 'No invoices yet. They are made from a charge sheet.'
                : 'No invoices match.',
          ),
        ),
      );
    }
    final groups = [RowGroup(heading: '', rows: rows)];
    void onOpen(Map<String, dynamic> row) => setState(() => _openId = _id(row));

    if (phone) {
      return GroupedList<Map<String, dynamic>>(
        key: const ValueKey('tour-invoices-list'),
        groups: groups,
        idOf: _id,
        tile: _tile,
        onOpen: onOpen,
      );
    }

    final open = _openId;
    if (open == null) {
      return GroupedTable<Map<String, dynamic>>(
        key: const ValueKey('tour-invoices-list'),
        groups: groups,
        idOf: _id,
        onOpen: onOpen,
        columns: [
          TableColumn(
            'No.',
            width: 110,
            cell: (_, r) => Text('${r['number']}'),
          ),
          TableColumn(
            'Bill to',
            flex: 2,
            cell: (_, r) => Text(
              '${(r['bill_to'] as Map?)?['name'] ?? ''}',
              overflow: TextOverflow.ellipsis,
            ),
          ),
          TableColumn(
            'Total',
            numeric: true,
            cell: (_, r) =>
                Text(invoiceMoney((r['total_cents'] as num?)?.toInt() ?? 0)),
          ),
          TableColumn(
            'Due',
            cell: (_, r) => Text(invoiceDay(r['due_on'], widget.me.display)),
          ),
          TableColumn(
            'Amount due',
            numeric: true,
            cell: (_, r) => Text(
              r['status'] == 'void'
                  ? '—'
                  : invoiceMoney((r['amount_due_cents'] as num?)?.toInt() ?? 0),
            ),
          ),
          TableColumn('Status', width: 110, cell: (_, r) => _pill(r)),
        ],
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
            width: 340,
            child: GroupedList<Map<String, dynamic>>(
              key: const ValueKey('tour-invoices-list'),
              groups: groups,
              idOf: _id,
              tile: _tile,
              onOpen: onOpen,
              selectedId: open,
            ),
          ),
          VerticalDivider(width: 1, color: scheme.outlineVariant),
          Expanded(child: _panel(open, inPlace: false)),
        ],
      ),
    );
  }
}

const _monthNames = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];
