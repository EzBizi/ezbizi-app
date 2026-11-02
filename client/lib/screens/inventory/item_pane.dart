import 'package:flutter/material.dart';


import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';

Map<String, dynamic>? inventoryMap(dynamic value) =>
    value is Map ? Map<String, dynamic>.from(value) : null;

List<Map<String, dynamic>> inventoryMaps(dynamic value) {
  if (value is! List) return const [];
  return [
    for (final row in value)
      if (row is Map) Map<String, dynamic>.from(row),
  ];
}

List<Map<String, dynamic>> inventoryRows(dynamic data) => inventoryMaps(data);

int inventoryLastPage(Map<String, dynamic> body) {
  final meta = body['meta'];
  if (meta is Map && meta['last_page'] is int) {
    return meta['last_page'] as int;
  }
  return 1;
}

int inventoryTotal(Map<String, dynamic> body, int fallback) {
  final meta = body['meta'];
  if (meta is Map && meta['total'] is int) {
    return meta['total'] as int;
  }
  return fallback;
}

String inventoryQty(dynamic raw) {
  final parsed = double.tryParse('$raw');
  if (parsed == null) return '$raw';
  if (parsed == parsed.roundToDouble()) return parsed.round().toString();
  var text = parsed.toStringAsFixed(3);
  while (text.contains('.') && (text.endsWith('0') || text.endsWith('.'))) {
    text = text.substring(0, text.length - 1);
  }
  return text;
}

double? inventoryQtyNumber(dynamic raw) => double.tryParse('$raw');

String? inventoryCents(dynamic cents) {
  if (cents is! num) return null;
  return '\$${(cents / 100).toStringAsFixed(2)}';
}

const inventoryGeneralLabel = 'General';
const inventoryOrgValue = '__org__';
const inventoryGeneralValue = '__pool__';

String inventoryPlaceLabel(Map<String, dynamic> row) {
  final franchise = inventoryMap(row['franchise']);
  if (franchise != null) {
    return franchise['name'] as String? ?? inventoryGeneralLabel;
  }
  return inventoryGeneralLabel;
}

String inventoryOwnerLabel(Map<String, dynamic> row, String organizationName) {
  final company = inventoryMap(row['company']);
  return company?['name'] as String? ?? organizationName;
}

String inventoryUnitLabel(String? unit) => unit ?? '';

String inventoryItemChoiceLabel(Map<String, dynamic> item) {
  final sku = item['sku'] as String?;
  final name = item['name'] as String? ?? '';
  if (sku != null && sku.isNotEmpty) return '$sku · $name';
  return name;
}

String inventoryLocationChoiceLabel(
  Map<String, dynamic> location,
  String organizationName,
) {
  return [
    location['name'],
    inventoryOwnerLabel(location, organizationName),
    inventoryPlaceLabel(location),
  ].where((p) => p != null && '$p'.isNotEmpty).join(' · ');
}

({String? companyId, String? franchiseId}) inventoryPlaceFromScope(Me me) {
  return (companyId: me.filingCompanyId, franchiseId: me.filingFranchiseId);
}

String inventoryKindLabel(String? kind) => switch (kind) {
  'warehouse' => 'Warehouse',
  'shop' => 'Shop',
  'cage' => 'Cage',
  'receiving' => 'Receiving',
  'van' => 'Van',
  'other' => 'Other',
  _ => kind ?? '',
};

String inventoryItemStatusLabel(String? status) => switch (status) {
  'active' => 'Active',
  'discontinued' => 'Discontinued',
  _ => status ?? '',
};

String inventoryLocationStatusLabel(String? status) => switch (status) {
  'active' => 'Active',
  'closed' => 'Closed',
  _ => status ?? '',
};

String inventoryPoStatusLabel(String? status) => switch (status) {
  'draft' => 'Draft',
  'open' => 'Open',
  'received' => 'Received',
  'cancelled' => 'Cancelled',
  _ => status ?? '',
};

String inventorySaleStatusLabel(String? status) => switch (status) {
  'draft' => 'Draft',
  'open' => 'Open',
  'partial' => 'Partial',
  'fulfilled' => 'Fulfilled',
  'cancelled' => 'Cancelled',
  _ => status ?? '',
};

String inventorySalePaymentLabel(String? status) => switch (status) {
  'unpaid' => 'Unpaid',
  'paid' => 'Paid',
  _ => status ?? '',
};

const inventoryUnits = <(String, String)>[
  ('each', 'Each'),
  ('box', 'Box'),
  ('ft', 'Ft'),
  ('in', 'In'),
  ('gal', 'Gal'),
  ('lb', 'Lb'),
  ('kit', 'Kit'),
  ('other', 'Other'),
];

const inventoryKinds = <(String, String)>[
  ('warehouse', 'Warehouse'),
  ('shop', 'Shop'),
  ('cage', 'Cage'),
  ('receiving', 'Receiving'),
  ('van', 'Van'),
  ('other', 'Other'),
];

/// One item: identity, balances across places, and a counted-as adjust.
class ItemPane extends StatefulWidget {
  const ItemPane({
    super.key,
    required this.api,
    required this.session,
    required this.itemId,
    required this.onBack,
    this.seed,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final String itemId;
  final Map<String, dynamic>? seed;
  final VoidCallback onBack;
  final bool showBack;

  @override
  State<ItemPane> createState() => _ItemPaneState();
}

class _ItemPaneState extends State<ItemPane> {
  Map<String, dynamic>? _item;
  List<Map<String, dynamic>> _balances = [];
  List<Map<String, dynamic>> _locations = [];
  String? _error;
  bool _loading = true;
  bool _stockFailed = false;

  /// What happened to this item, newest first (`GET /inventory/items/{id}/
  /// movements`, Inventory 6cbdc919; UI queue 43, option 1's History). Asked
  /// only with `inventory.stock.view`, the same gate as on-hand.
  List<Map<String, dynamic>> _history = [];
  int _historyPage = 0;
  int _historyLast = 1;
  bool _historyBusy = false;
  String? _historyError;

  final _countedAs = TextEditingController();
  final _reason = TextEditingController();
  String? _adjustLocationId;
  bool _adjusting = false;
  String? _adjustError;

  bool get _financials => widget.session.me!.can('inventory.financials');
  bool get _maySeeStock =>
      widget.session.me?.permissions.contains('inventory.stock.view') ?? false;
  bool get _canEdit => widget.session.me!.can('inventory.edit');

  @override
  void initState() {
    super.initState();
    _item = widget.seed;
    _load();
  }

  @override
  void didUpdateWidget(covariant ItemPane oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.itemId != widget.itemId) {
      _item = widget.seed;
      _adjustLocationId = null;
      _countedAs.clear();
      _reason.clear();
      _stockFailed = false;
      _load();
    }
  }

  @override
  void dispose() {
    _countedAs.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _item == null;
      _error = null;
      _stockFailed = false;
    });

    final itemRes = await widget.api.get('/inventory/items/${widget.itemId}');

    /*
     * On-hand is the Stock tab's answer, and `/inventory/stock` is gated on
     * `inventory.stock.view`. A role granted Items and not Stock is being told
     * "the catalogue, not the quantities", so this does not ask — asking and
     * swallowing the refusal would put a permission message in `_error` on a
     * tab the person legitimately holds, which reads as broken rather than as
     * scoped.
     */
    final maySeeStock =
        widget.session.me?.permissions.contains('inventory.stock.view') ??
        false;

    final stockRes = maySeeStock
        ? await widget.api.get('/inventory/stock', {'item_id': widget.itemId})
        : null;

    final locRes = await widget.api.get('/inventory/locations');

    if (!mounted) return;

    if (!itemRes.ok) {
      setState(() {
        _loading = false;
        _error = itemRes.message;
      });
      return;
    }

    final item = Map<String, dynamic>.from(itemRes.data as Map);

    // Null is "did not ask", which is not a failure. Only a request that was
    // made and refused sets `_stockFailed` and surfaces a message.
    final stockFailed = stockRes != null && !stockRes.ok;
    final mine = (stockRes == null || stockFailed)
        ? <Map<String, dynamic>>[]
        : [
            for (final row in inventoryRows(stockRes.data))
              if (inventoryMap(row['item'])?['id'] == widget.itemId) row,
          ];
    final balances = mine;

    setState(() {
      _loading = false;
      _item = item;
      _balances = balances;
      _stockFailed = stockFailed;
      _locations = locRes.ok ? inventoryRows(locRes.data) : const [];
      _adjustLocationId ??= balances.isEmpty
          ? null
          : inventoryMap(balances.first['location'])?['id'] as String?;
      if (stockFailed) {
        _error = stockRes.message;
      }
    });

    _history = [];
    _historyPage = 0;
    _historyLast = 1;
    if (maySeeStock) await _moreHistory();
  }

  Future<void> _moreHistory() async {
    if (_historyBusy || _historyPage >= _historyLast) return;
    setState(() {
      _historyBusy = true;
      _historyError = null;
    });
    final response = await widget.api.get(
      '/inventory/items/${widget.itemId}/movements',
      {'page': '${_historyPage + 1}', 'per_page': '20'},
    );
    if (!mounted) return;
    setState(() {
      _historyBusy = false;
      if (!response.ok) {
        _historyError = response.message;
        return;
      }
      _history = [..._history, ...inventoryRows(response.data)];
      _historyPage++;
      _historyLast = inventoryLastPage(response.body);
    });
  }

  /// "Sep 30 · −1 at Van 12 (Marco), issued to job J-10482". A transfer's end
  /// the caller cannot open arrives null and is said so, never invented.
  String _movementLine(Map<String, dynamic> m) {
    final unit = inventoryUnitLabel(_item?['unit'] as String?);
    final raw = inventoryQtyNumber(m['qty']) ?? 0;
    final size = inventoryQty(raw.abs());
    final signed = raw < 0 ? '\u2212$size' : raw > 0 ? '+$size' : size;
    String place(Object? l) =>
        '${inventoryMap(l)?['name'] ?? 'a place you cannot open'}';
    final reference = inventoryMap(m['reference']);
    final number = reference?['number'] as String?;
    final note = (m['note'] as String?)?.trim();
    final when = parseCalendarDay(m['occurred_at'] as String?);
    final by = inventoryMap(m['by'])?['name'] as String?;

    final what = switch (m['kind']) {
      'received' => [
        '$signed $unit received at ${place(m['location'])}',
        ?number,
        if (_financials && inventoryCents(m['unit_cost_minor']) != null)
          '${inventoryCents(m['unit_cost_minor'])} each',
      ].join(', '),
      'issued' =>
        '$signed $unit at ${place(m['location'])}, '
            '${reference?['type'] == 'job' && number != null ? 'issued to job $number' : 'issued${note == null || note.isEmpty ? '' : ': $note'}'}',
      'transferred' =>
        '$size $unit moved ${place(m['location'])} \u2192 '
            '${place(m['to_location'])}${number == null ? '' : ', $number'}',
      'adjusted' =>
        '$signed $unit counted at ${place(m['location'])}'
            '${note == null || note.isEmpty ? '' : ': $note'}',
      'sold' =>
        '$signed $unit at ${place(m['location'])}, sold'
            '${number == null ? '' : ' on $number'}',
      _ => '$signed $unit at ${place(m['location'])}',
    };

    return [
      if (when != null) widget.session.me!.display.formatDate(when),
      what,
      ?by,
    ].join(' · ');
  }

  Future<void> _adjust() async {
    final locationId = _adjustLocationId;
    final counted = _countedAs.text.trim();
    final reason = _reason.text.trim();
    if (locationId == null || counted.isEmpty || reason.isEmpty) return;

    setState(() {
      _adjusting = true;
      _adjustError = null;
    });

    final response = await widget.api.post('/inventory/adjustments', {
      'location_id': locationId,
      'item_id': widget.itemId,
      'counted_as': counted,
      'reason': reason,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _adjusting = false;
        _adjustError = response.reason;
      });
      return;
    }

    setState(() {
      _adjusting = false;
      _countedAs.clear();
      _reason.clear();
    });
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final item = _item;
    final name = item?['name'] as String? ?? 'Item';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          name,
          showBack: widget.showBack,
          onBack: widget.onBack,
          ellipsis: true,
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (_error != null) ...[
                        ErrorNote(_error!),
                        const SizedBox(height: 14),
                      ],
                      if (item != null) ...[
                        Wrap(
                          spacing: 0,
                          runSpacing: 8,
                          children: [
                            Tag(
                              inventoryItemStatusLabel(
                                item['status'] as String?,
                              ),
                              warn: item['status'] == 'discontinued',
                            ),
                            if ((item['unit'] as String?)?.isNotEmpty == true)
                              Tag(inventoryUnitLabel(item['unit'] as String?)),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text(
                          [
                            item['number'],
                            item['sku'],
                            inventoryOwnerLabel(
                              item,
                              widget.session.me!.organization.name,
                            ),
                            inventoryPlaceLabel(item),
                          ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                          style: theme.textTheme.bodyMedium,
                        ),
                        if (_financials &&
                            inventoryCents(item['last_cost_minor']) !=
                                null) ...[
                          const SizedBox(height: 8),
                          const Text('Last cost'),
                          Text(
                            inventoryCents(item['last_cost_minor'])!,
                            style: theme.textTheme.bodyMedium,
                          ),
                        ],
                        if (item['reorder_at'] != null) ...[
                          const SizedBox(height: 8),
                          Text(
                            'Reorder at ${inventoryQty(item['reorder_at'])}',
                            style: theme.textTheme.bodyMedium,
                          ),
                        ],
                        const SizedBox(height: 16),
                        Text('On-hand', style: theme.textTheme.titleMedium),
                        const SizedBox(height: 8),
                        if (_balances.isEmpty)
                          _stockFailed
                              ? const SizedBox.shrink()
                              : const Text(
                                  'None on hand at a visible location.',
                                )
                        else
                          for (final row in _balances)
                            Padding(
                              padding: const EdgeInsets.only(bottom: 8),
                              child: Text(
                                '${inventoryQty(row['qty_on_hand'])} ${inventoryUnitLabel(inventoryMap(row['item'])?['unit'] as String?)} · ${inventoryMap(row['location'])?['name'] ?? ''}',
                              ),
                            ),
                        if (_canEdit) ...[
                          const SizedBox(height: 16),
                          Text(
                            'Counted as',
                            style: theme.textTheme.titleMedium,
                          ),
                          const SizedBox(height: 8),
                          SearchPickerField<String>(
                            value: _adjustLocationId,
                            label: 'Location',
                            options: [
                              for (final loc in _locations)
                                SearchOption(value: loc['id'] as String?, label: loc['name'] as String? ?? ''),
                            ],
                            onChanged: _adjusting
                                ? null
                                : (v) =>
                                      setState(() => _adjustLocationId = v),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _countedAs,
                            enabled: !_adjusting,
                            keyboardType: const TextInputType.numberWithOptions(
                              decimal: true,
                            ),
                            decoration: const InputDecoration(
                              labelText: 'Counted as',
                            ),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _reason,
                            enabled: !_adjusting,
                            decoration: const InputDecoration(
                              labelText: 'Reason',
                            ),
                          ),
                          if (_adjustError != null) ...[
                            const SizedBox(height: 12),
                            ErrorNote(_adjustError!),
                          ],
                          const SizedBox(height: 12),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: FilledButton(
                              onPressed: _adjusting ? null : _adjust,
                              child: const Text('Save count'),
                            ),
                          ),
                        ],
                        if (_maySeeStock) ...[
                          const SizedBox(height: 20),
                          Text('History', style: theme.textTheme.titleMedium),
                          const SizedBox(height: 8),
                          Column(
                            key: const Key('item-history'),
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              if (_history.isEmpty && !_historyBusy)
                                Text(
                                  _historyError == null
                                      ? 'Nothing has moved yet.'
                                      : 'The history could not be read: '
                                            '$_historyError',
                                  style: theme.textTheme.bodySmall,
                                ),
                              for (final (i, m) in _history.indexed)
                                Padding(
                                  key: Key('movement-$i'),
                                  padding: const EdgeInsets.only(bottom: 6),
                                  child: Text(
                                    _movementLine(m),
                                    style: theme.textTheme.bodyMedium,
                                  ),
                                ),
                              if (_historyPage < _historyLast &&
                                  _history.isNotEmpty)
                                Align(
                                  alignment: Alignment.centerLeft,
                                  child: TextButton(
                                    onPressed: _historyBusy
                                        ? null
                                        : _moreHistory,
                                    child: Text(
                                      _historyBusy ? 'Loading…' : 'Show more',
                                    ),
                                  ),
                                ),
                            ],
                          ),
                        ],
                      ],
                    ],
                  ),
                ),
        ),
      ],
    );
  }
}
