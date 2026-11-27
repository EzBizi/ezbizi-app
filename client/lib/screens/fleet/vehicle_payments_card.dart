import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import 'vehicle_card.dart';
import 'vehicle_papers.dart';

/// Drawn only when the session holds `fleet_management.financials` (spec §8).
class VehiclePaymentsCard extends StatefulWidget {
  const VehiclePaymentsCard({
    super.key,
    required this.api,
    required this.session,
    required this.vehicle,
    required this.onReload,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> vehicle;
  final Future<void> Function() onReload;

  @override
  State<VehiclePaymentsCard> createState() => _VehiclePaymentsCardState();
}

class _VehiclePaymentsCardState extends State<VehiclePaymentsCard> {
  bool _recording = false;
  String _category = 'loan';
  String _method = 'ach';
  final _amount = TextEditingController();
  final _occurredOn = TextEditingController();
  final _note = TextEditingController();
  String? _link;
  bool _busy = false;
  String? _error;

  bool get _canEdit => widget.session.me!.can('fleet_management.financials');

  List<Map<String, dynamic>> get _payments {
    final rows = [...fleetMaps(widget.vehicle['payments'])];
    rows.sort(
      (a, b) => fleetDay(
        b['occurred_on'] as String?,
      ).compareTo(fleetDay(a['occurred_on'] as String?)),
    );
    return rows;
  }

  List<(String, String)> get _links {
    final items = <(String, String)>[];
    for (final p in fleetMaps(widget.vehicle['ownership_periods'])) {
      final id = p['id'] as String?;
      if (id == null) continue;
      items.add((
        'o:$id',
        'Ownership ${fleetKindLabel(p['kind'] as String?)} ${fleetDay(p['started_on'] as String?)}'
            .trim(),
      ));
    }
    for (final p in fleetMaps(widget.vehicle['insurance_policies'])) {
      final id = p['id'] as String?;
      if (id == null) continue;
      items.add(('i:$id', 'Insurance ${p['carrier'] ?? ''}'.trim()));
    }
    for (final p in fleetMaps(widget.vehicle['service_visits'])) {
      final id = p['id'] as String?;
      if (id == null) continue;
      items.add((
        's:$id',
        'Service ${fleetPairLabel(fleetServiceKinds, p['kind'] as String?)} ${fleetDay(p['occurred_on'] as String?)}'
            .trim(),
      ));
    }
    return items;
  }

  @override
  void initState() {
    super.initState();
    _occurredOn.text = fleetToday();
  }

  @override
  void dispose() {
    _amount.dispose();
    _occurredOn.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final int? cents;
    try {
      cents = fleetParseCents(_amount.text);
    } on FormatException catch (e) {
      setState(() => _error = e.message);
      return;
    }

    if (cents == null) {
      setState(() => _error = 'Enter the amount in dollars.');
      return;
    }

    if (_occurredOn.text.trim().isEmpty) {
      setState(() => _error = 'When did this payment happen?');
      return;
    }

    final body = <String, dynamic>{
      'category': _category,
      'amount_cents': cents,
      'occurred_on': _occurredOn.text.trim(),
      'method': _method,
      if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
    };

    final link = _link;
    if (link != null && link.contains(':')) {
      final id = link.substring(2);
      switch (link[0]) {
        case 'o':
          body['ownership_period_id'] = id;
        case 'i':
          body['insurance_policy_id'] = id;
        case 's':
          body['service_visit_id'] = id;
      }
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post(
      '/vehicles/${widget.vehicle['id']}/payments',
      body,
    );

    if (!mounted) return;

    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }

    setState(() => _recording = false);
    // Emptied once saved, so the next entry does not start as a copy of
    // this one and get saved twice (audit 2026-11-27).
    for (final c in [_amount, _note]) {
      c.clear();
    }
    await widget.onReload();
  }

  @override
  Widget build(BuildContext context) {
    final payments = _payments;

    return FleetSectionCard(
      title: 'Payments',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (payments.isEmpty)
            Text(
              'No payments recorded.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          for (final payment in payments) _payment(context, payment),
          if (_canEdit && !_recording) ...[
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: FilledButton.tonal(
                onPressed: () => setState(() => _recording = true),
                child: const Text('Record a payment'),
              ),
            ),
          ],
          if (_recording) _form(),
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }

  Widget _payment(BuildContext context, Map<String, dynamic> payment) {
    final lines = <String>[
      [
        fleetPairLabel(fleetPaymentCategories, payment['category'] as String?),
        fleetCents(payment['amount_cents']) ?? '',
        fleetPairLabel(fleetPaymentMethods, payment['method'] as String?),
        fleetDay(payment['occurred_on'] as String?),
      ].where((s) => s.isNotEmpty).join(' · '),
      if ((payment['note'] as String?)?.isNotEmpty == true)
        payment['note'] as String,
    ];

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final line in lines)
            if (line.isNotEmpty)
              Text(line, style: Theme.of(context).textTheme.bodyMedium),
          if (payment['id'] != null) ...[
            const SizedBox(height: 8),
            VehiclePapers(
              api: widget.api,
              attachableType: 'vehicle_payments',
              attachableId: payment['id'] as String,
              canEdit: _canEdit,
            ),
          ],
        ],
      ),
    );
  }

  Widget _form() {
    final links = _links;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 8),
        SearchPickerField<String>(
          value: _category,
          label: 'Category',
          options: [
            for (final t in fleetPaymentCategories)
              SearchOption(value: t.$1, label: t.$2),
          ],
          onChanged: _busy
              ? null
              : (v) => setState(() => _category = v ?? _category),
        ),
        fleetField(
          _amount,
          'Amount',
          enabled: !_busy,
          helper: 'In dollars. Stored as whole cents.',
          keyboard: const TextInputType.numberWithOptions(decimal: true),
        ),
        const SizedBox(height: 12),
        SearchPickerField<String>(
          value: _method,
          label: 'Method',
          options: [
            for (final t in fleetPaymentMethods)
              SearchOption(value: t.$1, label: t.$2),
          ],
          onChanged: _busy
              ? null
              : (v) => setState(() => _method = v ?? _method),
        ),
        fleetCalendarDay(
          _occurredOn,
          'Occurred on',
          formats: widget.session.me!.display,
          enabled: !_busy,
        ),
        fleetField(_note, 'Note', enabled: !_busy),
        if (links.isNotEmpty) ...[
          const SizedBox(height: 12),
          SearchPickerField<String>(
            value: _link ?? '__none__',
            label: 'Linked to',
            helperText: 'Optional. At most one ownership, policy, or visit.',
            helperMaxLines: 2,
            options: [
              const SearchOption(value: '__none__', label: 'Nothing'),
              for (final t in links) SearchOption(value: t.$1, label: t.$2),
            ],
            onChanged: _busy
                ? null
                : (v) => setState(() => _link = v == '__none__' ? null : v),
          ),
        ],
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          alignment: WrapAlignment.end,
          children: [
            TextButton(
              onPressed: _busy
                  ? null
                  : () => setState(() => _recording = false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: _busy ? null : _save,
              child: const Text('Record payment'),
            ),
          ],
        ),
      ],
    );
  }
}
