import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';

/// The `services` field: work this finding recommends, priced.
///
/// ── It is a quote in the making ──────────────────────────────────
///
/// What the technician picks here becomes lines on the job when they submit, so
/// the numbers on this screen are the numbers a customer will be shown. That is
/// why the total is here rather than only on the report: somebody recommending
/// three thousand pounds of work should see three thousand pounds before they
/// press Submit, not afterwards.
///
/// ── The picks are snapshots ──────────────────────────────────────
///
/// The server resolves each pick against the catalogue as it is saved and
/// stores the name and price with it. This end shows what came back rather than
/// what it sent, so the price on screen is the price that was recorded — if the
/// two ever disagree, the screen is wrong and the record is right.
class InspectionServicesField extends StatefulWidget {
  const InspectionServicesField({
    super.key,
    required this.api,
    required this.label,
    required this.picks,
    required this.catalogue,
    required this.onChanged,
  });

  final ApiClient api;
  final String label;

  /// `{service_id, name, unit_price_minor, qty}` as the server resolved them.
  final List<Map<String, dynamic>> picks;

  /// The organization's services, from the run payload.
  final List<Map<String, dynamic>> catalogue;

  final ValueChanged<List<Map<String, dynamic>>> onChanged;

  @override
  State<InspectionServicesField> createState() => _InspectionServicesFieldState();
}

class _InspectionServicesFieldState extends State<InspectionServicesField> {
  String? _error;

  int get _total => widget.picks.fold(
        0,
        (sum, p) =>
            sum + ((p['unit_price_minor'] as num? ?? 0).toInt() * (p['qty'] as num? ?? 1).toInt()),
      );

  String _money(int minor) => (minor / 100).toStringAsFixed(2);

  void _add(String serviceId) {
    // Already recommended? Bump the quantity rather than listing it twice —
    // two lines for one service is a quote nobody can read.
    final at = widget.picks.indexWhere((p) => '${p['service_id']}' == serviceId);

    if (at >= 0) {
      final next = [...widget.picks];
      next[at] = {...next[at], 'qty': ((next[at]['qty'] as num? ?? 1).toInt()) + 1};
      widget.onChanged(next);
      return;
    }

    final service = widget.catalogue.firstWhere(
      (s) => '${s['id']}' == serviceId,
      orElse: () => const {},
    );

    if (service.isEmpty) {
      setState(() => _error = 'That service is no longer in the catalogue.');
      return;
    }

    widget.onChanged([
      ...widget.picks,
      {
        'service_id': serviceId,
        // Sent for display only; the server re-resolves and returns the
        // authoritative snapshot.
        'name': service['name'],
        'unit_price_minor': service['price_minor'] ?? 0,
        'qty': 1,
      },
    ]);
  }

  void _setQty(int index, int qty) {
    if (qty < 1) return;
    final next = [...widget.picks];
    next[index] = {...next[index], 'qty': qty};
    widget.onChanged(next);
  }

  void _remove(int index) {
    final next = [...widget.picks]..removeAt(index);
    widget.onChanged(next);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(widget.label, style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        for (var i = 0; i < widget.picks.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              children: [
                Expanded(child: Text('${widget.picks[i]['name'] ?? ''}')),
                IconButton(
                  tooltip: 'Fewer',
                  onPressed: () =>
                      _setQty(i, (widget.picks[i]['qty'] as num? ?? 1).toInt() - 1),
                  icon: const Icon(Icons.remove_circle_outline, size: 20),
                ),
                Text('${widget.picks[i]['qty'] ?? 1}'),
                IconButton(
                  tooltip: 'More',
                  onPressed: () =>
                      _setQty(i, (widget.picks[i]['qty'] as num? ?? 1).toInt() + 1),
                  icon: const Icon(Icons.add_circle_outline, size: 20),
                ),
                SizedBox(
                  width: 84,
                  child: Text(
                    _money(((widget.picks[i]['unit_price_minor'] as num? ?? 0).toInt()) *
                        ((widget.picks[i]['qty'] as num? ?? 1).toInt())),
                    textAlign: TextAlign.right,
                  ),
                ),
                IconButton(
                  tooltip: 'Remove',
                  onPressed: () => _remove(i),
                  icon: const Icon(Icons.close, size: 18),
                ),
              ],
            ),
          ),
        if (widget.picks.isNotEmpty) ...[
          const Divider(height: 12),
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              Text('Total ', style: theme.textTheme.labelLarge),
              SizedBox(
                width: 84,
                child: Text(_money(_total),
                    textAlign: TextAlign.right,
                    style: theme.textTheme.labelLarge),
              ),
              const SizedBox(width: 48),
            ],
          ),
          const SizedBox(height: 8),
        ],
        if (widget.catalogue.isEmpty)
          Text(
            'This organization has no priced services yet.',
            style: theme.textTheme.bodySmall,
          )
        else
          DropdownButtonFormField<String>(
            key: Key('services-add-${widget.label}'),
            initialValue: null,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Add work'),
            items: [
              for (final service in widget.catalogue)
                DropdownMenuItem(
                  value: '${service['id']}',
                  child: Text(
                    '${service['name']} — ${_money((service['price_minor'] as num? ?? 0).toInt())}',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: (value) {
              if (value != null) _add(value);
            },
          ),
        if (_error != null) ...[
          const SizedBox(height: 8),
          ErrorNote(_error!),
        ],
      ],
    );
  }
}
