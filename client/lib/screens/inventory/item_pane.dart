import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
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

bool inventoryIsLow(Map<String, dynamic>? item, dynamic qty) {
  if (item == null) return false;
  final reorder = item['reorder_at'];
  if (reorder == null || '$reorder'.isEmpty) return false;
  final at = inventoryQtyNumber(reorder);
  final onHand = inventoryQtyNumber(qty);
  if (at == null || onHand == null) return false;
  return onHand <= at;
}

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

  final _countedAs = TextEditingController();
  final _reason = TextEditingController();
  String? _adjustLocationId;
  bool _adjusting = false;
  String? _adjustError;

  bool get _financials => widget.session.me!.can('inventory.financials');
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
    });

    final itemRes = await widget.api.get('/inventory/items/${widget.itemId}');
    final stockRes = await widget.api.get('/inventory/stock');
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
    final balances = inventoryRows(stockRes.ok ? stockRes.data : const []);
    final mine = [
      for (final row in balances)
        if (inventoryMap(row['item'])?['id'] == widget.itemId) row,
    ];

    setState(() {
      _loading = false;
      _item = item;
      _balances = mine;
      _locations = locRes.ok ? inventoryRows(locRes.data) : const [];
      _adjustLocationId ??= mine.isEmpty
          ? null
          : inventoryMap(mine.first['location'])?['id'] as String?;
      if (!stockRes.ok) {
        _error = stockRes.message;
      }
    });
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
        Padding(
          padding: EdgeInsets.fromLTRB(widget.showBack ? 8 : 20, 12, 20, 8),
          child: Row(
            children: [
              if (widget.showBack)
                IconButton(
                  icon: const Icon(Icons.arrow_back),
                  tooltip: 'Back to the list',
                  onPressed: widget.onBack,
                ),
              Expanded(
                child: Text(
                  name,
                  style: theme.textTheme.titleMedium,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
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
                          const Text('None on hand at a visible location.')
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
                          DropdownButtonFormField<String>(
                            initialValue: _adjustLocationId,
                            isExpanded: true,
                            decoration: const InputDecoration(
                              labelText: 'Location',
                            ),
                            items: [
                              for (final loc in _locations)
                                DropdownMenuItem(
                                  value: loc['id'] as String?,
                                  child: Text(
                                    loc['name'] as String? ?? '',
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
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
                      ],
                    ],
                  ),
                ),
        ),
      ],
    );
  }
}
