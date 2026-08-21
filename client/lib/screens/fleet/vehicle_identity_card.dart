import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import 'vehicle_card.dart';

/// Identity is typed; the display name is a preview, not an input (spec §5).
class VehicleIdentityCard extends StatefulWidget {
  const VehicleIdentityCard({
    super.key,
    required this.api,
    required this.session,
    required this.vehicle,
    required this.onChanged,
    required this.onDeleted,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> vehicle;
  final ValueChanged<Map<String, dynamic>> onChanged;
  final VoidCallback onDeleted;

  @override
  State<VehicleIdentityCard> createState() => _VehicleIdentityCardState();
}

class _VehicleIdentityCardState extends State<VehicleIdentityCard> {
  final _year = TextEditingController();
  final _make = TextEditingController();
  final _model = TextEditingController();
  final _plate = TextEditingController();
  final _vin = TextEditingController();
  final _colour = TextEditingController();
  final _odometer = TextEditingController();
  final _nextServiceOn = TextEditingController();
  final _nextServiceMiles = TextEditingController();
  final _counterparty = TextEditingController();
  final _startedOn = TextEditingController();

  String _bodyType = 'van';
  String _fuelType = 'gas';
  String _status = 'active';
  String? _franchiseId;
  String? _kind;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;
  bool get _canEdit => _me.can('fleet_management.edit');
  bool get _ended => _status == 'sold' || _status == 'returned';

  String? get _editRefusal =>
      _me.refusal('fleet_management.edit', 'change a vehicle');
  String? get _deleteRefusal =>
      _me.refusal('fleet_management.delete', 'delete a vehicle');

  @override
  void initState() {
    super.initState();
    _fill(widget.vehicle);
    for (final c in [_year, _make, _model, _plate, _vin]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void didUpdateWidget(covariant VehicleIdentityCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.vehicle != widget.vehicle) _fill(widget.vehicle);
  }

  @override
  void dispose() {
    for (final c in [
      _year,
      _make,
      _model,
      _plate,
      _vin,
      _colour,
      _odometer,
      _nextServiceOn,
      _nextServiceMiles,
      _counterparty,
      _startedOn,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  void _fill(Map<String, dynamic> row) {
    _year.text = '${row['year'] ?? ''}';
    if (_year.text == 'null') _year.text = '';
    _make.text = row['make'] as String? ?? '';
    _model.text = row['model'] as String? ?? '';
    _plate.text = row['plate'] as String? ?? '';
    _vin.text = row['vin'] as String? ?? '';
    _colour.text = row['colour'] as String? ?? '';
    _odometer.text = row['odometer_miles'] == null
        ? ''
        : '${row['odometer_miles']}';
    _nextServiceOn.text = row['next_service_on'] as String? ?? '';
    _nextServiceMiles.text = row['next_service_miles'] == null
        ? ''
        : '${row['next_service_miles']}';
    _bodyType = row['body_type'] as String? ?? 'van';
    _fuelType = row['fuel_type'] as String? ?? 'gas';
    _status = row['status'] as String? ?? 'active';
    final franchise = fleetMap(row['franchise']);
    _franchiseId = franchise?['id'] as String?;
    _kind = fleetMap(row['ownership'])?['kind'] as String?;
    if (_startedOn.text.isEmpty) _startedOn.text = fleetToday();
  }

  List<FranchiseSummary> get _places {
    final company = fleetMap(widget.vehicle['company']);
    final companyId = company?['id'] as String?;
    if (companyId == null) return const [];
    return _me.franchises.where((f) => f.companyId == companyId).toList();
  }

  Future<void> _save() async {
    int? year;
    int? odometer;
    int? nextMiles;
    try {
      year = fleetParseInt(_year.text, 'Year has to be a number.');
      odometer = fleetParseInt(
        _odometer.text,
        'Odometer has to be a whole number of miles.',
      );
      nextMiles = fleetParseInt(
        _nextServiceMiles.text,
        'Next service miles has to be a whole number.',
      );
    } on FormatException catch (e) {
      setState(() => _error = e.message);
      return;
    }

    final was = widget.vehicle['status'] as String? ?? '';
    final buyingBack =
        (was == 'sold' || was == 'returned') &&
        _status != 'sold' &&
        _status != 'returned';

    if (buyingBack && _kind == null) {
      setState(
        () => _error = 'A vehicle has to be owned or rented. Say which, then save.',
      );
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final body = <String, dynamic>{
      'year': year,
      'make': _make.text.trim().isEmpty ? null : _make.text.trim(),
      'model': _model.text.trim().isEmpty ? null : _model.text.trim(),
      'plate': _plate.text.trim().isEmpty ? null : _plate.text.trim(),
      'vin': _vin.text.trim().isEmpty ? null : _vin.text.trim(),
      'colour': _colour.text.trim().isEmpty ? null : _colour.text.trim(),
      'body_type': _bodyType,
      'fuel_type': _fuelType,
      'odometer_miles': odometer,
      'next_service_on': _nextServiceOn.text.trim().isEmpty
          ? null
          : _nextServiceOn.text.trim(),
      'next_service_miles': nextMiles,
      'status': _status,
      'franchise_id': _franchiseId,
    };

    if (buyingBack) {
      body['ownership'] = {
        'kind': _kind,
        if (_counterparty.text.trim().isNotEmpty)
          'counterparty': _counterparty.text.trim(),
        if (_startedOn.text.trim().isNotEmpty)
          'started_on': _startedOn.text.trim(),
      };
    }

    final response = await widget.api.patch(
      '/vehicles/${widget.vehicle['id']}',
      body,
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    setState(() => _busy = false);
    widget.onChanged(Map<String, dynamic>.from(response.data as Map));
  }

  Future<void> _delete() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.delete(
      '/vehicles/${widget.vehicle['id']}',
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    widget.onDeleted();
  }

  @override
  Widget build(BuildContext context) {
    final enabled = _canEdit && !_busy;
    final preview = fleetPreviewName(
      year: _year.text,
      make: _make.text,
      model: _model.text,
      plate: _plate.text,
      vin: _vin.text,
    );

    return FleetSectionCard(
      title: 'Identity',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Will be listed as $preview',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          fleetField(_year, 'Year', enabled: enabled, keyboard: TextInputType.number),
          fleetField(_make, 'Make', enabled: enabled),
          fleetField(_model, 'Model', enabled: enabled),
          fleetField(_plate, 'Plate', enabled: enabled),
          fleetField(_vin, 'VIN', enabled: enabled),
          fleetField(_colour, 'Colour', enabled: enabled),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: ValueKey('body-$_bodyType'),
            initialValue: _bodyType,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Body type'),
            items: [
              for (final t in fleetBodyTypes)
                DropdownMenuItem(value: t.$1, child: Text(t.$2)),
            ],
            onChanged: enabled
                ? (v) => setState(() => _bodyType = v ?? _bodyType)
                : null,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: ValueKey('fuel-$_fuelType'),
            initialValue: _fuelType,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Fuel type'),
            items: [
              for (final t in fleetFuelTypes)
                DropdownMenuItem(value: t.$1, child: Text(t.$2)),
            ],
            onChanged: enabled
                ? (v) => setState(() => _fuelType = v ?? _fuelType)
                : null,
          ),
          fleetField(
            _odometer,
            'Odometer (miles)',
            enabled: enabled,
            keyboard: TextInputType.number,
          ),
          fleetField(
            _nextServiceOn,
            'Next service on',
            enabled: enabled,
            helper: 'A calendar day — 2026-09-01.',
          ),
          fleetField(
            _nextServiceMiles,
            'Next service miles',
            enabled: enabled,
            keyboard: TextInputType.number,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: ValueKey('status-$_status'),
            initialValue: _status,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Status'),
            items: [
              for (final t in fleetStatuses)
                DropdownMenuItem(value: t.$1, child: Text(t.$2)),
            ],
            onChanged: enabled
                ? (v) => setState(() => _status = v ?? _status)
                : null,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: ValueKey('place-$_franchiseId'),
            initialValue: _franchiseId ?? '__pool__',
            isExpanded: true,
            decoration: InputDecoration(
              labelText: _me.organization.franchiseLabel,
            ),
            items: [
              const DropdownMenuItem(
                value: '__pool__',
                child: Text('Company pool'),
              ),
              for (final f in _places)
                DropdownMenuItem(value: f.id, child: Text(f.name)),
            ],
            onChanged: enabled
                ? (v) => setState(
                    () => _franchiseId = v == '__pool__' ? null : v,
                  )
                : null,
          ),
          if (_ended) ...[
            const SizedBox(height: 16),
            Text(
              'Bring it back with a new ownership period on the same save.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                ChoiceChip(
                  label: const Text('Owned'),
                  selected: _kind == 'owned',
                  onSelected: enabled
                      ? (_) => setState(() => _kind = 'owned')
                      : null,
                ),
                ChoiceChip(
                  label: const Text('Rented'),
                  selected: _kind == 'rented',
                  onSelected: enabled
                      ? (_) => setState(() => _kind = 'rented')
                      : null,
                ),
              ],
            ),
            fleetField(
              _counterparty,
              _kind == 'rented' ? 'Rented from' : 'Bought from',
              enabled: enabled,
            ),
            fleetField(
              _startedOn,
              'Started on',
              enabled: enabled,
              helper: 'A calendar day — 2026-03-08.',
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 14),
            ErrorNote(_error!),
          ],
          if (_canEdit || _me.can('fleet_management.delete')) ...[
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.end,
              children: [
                Refused(
                  reason: _deleteRefusal,
                  child: OutlinedButton(
                    onPressed: (_deleteRefusal == null && !_busy)
                        ? _delete
                        : null,
                    child: const Text('Delete'),
                  ),
                ),
                Refused(
                  reason: _editRefusal,
                  child: FilledButton(
                    onPressed: enabled ? _save : null,
                    child: const Text('Save'),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
