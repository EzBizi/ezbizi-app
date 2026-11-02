import 'package:flutter/material.dart';

import '../../core/money.dart';
import '../../widgets/search_picker.dart';
import 'item_pane.dart';

// The lines a new purchase order and a new sale share: an item, a quantity,
// and the unit money (a cost on an order, a price on a sale).

class OrderLine {
  String? itemId;
  final qty = TextEditingController();
  final amount = TextEditingController();

  void dispose() {
    qty.dispose();
    amount.dispose();
  }
}

/// The lines with an item and a quantity.
List<OrderLine> readyOrderLines(List<OrderLine> lines) => [
  for (final line in lines)
    if (line.itemId != null && line.qty.text.trim().isNotEmpty) line,
];

/// [ready] as the server reads them, the money under [amountKey] when
/// [financials]. Throws the [FormatException] of an amount it cannot read.
List<Map<String, dynamic>> orderLinesPayload(
  List<OrderLine> ready, {
  required bool financials,
  required String amountKey,
}) => [
  for (final line in ready)
    {
      'item_id': line.itemId,
      'qty': line.qty.text.trim(),
      if (financials) amountKey: ?parseAmountMinor(line.amount.text),
    },
];

/// Every line's item, quantity and money fields, then "Add line". [changed]
/// runs after a line or the list changes.
List<Widget> orderLineFields({
  required List<OrderLine> lines,
  required List<Map<String, dynamic>> items,
  required bool busy,
  required bool financials,
  required String keyPrefix,
  required String amountLabel,
  required VoidCallback changed,
}) => [
  for (var i = 0; i < lines.length; i++) ...[
    const SizedBox(height: 12),
    SearchPickerField<String>(
      key: ValueKey('$keyPrefix-item-$i-${lines[i].itemId}'),
      value: lines[i].itemId,
      label: 'Item',
      options: [
        for (final item in items)
          SearchOption(
            value: item['id'] as String?,
            label: inventoryItemChoiceLabel(item),
          ),
      ],
      onChanged: busy
          ? null
          : (v) {
              lines[i].itemId = v;
              changed();
            },
    ),
    const SizedBox(height: 12),
    TextField(
      controller: lines[i].qty,
      enabled: !busy,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      decoration: const InputDecoration(labelText: 'Qty ordered'),
      onChanged: (_) => changed(),
    ),
    if (financials) ...[
      const SizedBox(height: 12),
      TextField(
        controller: lines[i].amount,
        enabled: !busy,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        decoration: InputDecoration(labelText: amountLabel),
      ),
    ],
  ],
  Align(
    alignment: Alignment.centerLeft,
    child: TextButton(
      onPressed: busy
          ? null
          : () {
              lines.add(OrderLine());
              changed();
            },
      child: const Text('Add line'),
    ),
  ),
];
