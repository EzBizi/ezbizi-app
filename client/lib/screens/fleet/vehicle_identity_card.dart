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
  final _trim = TextEditingController();
  final _plate = TextEditingController();
  final _plateState = TextEditingController();
  final _vin = TextEditingController();
  final _colour = TextEditingController();
  final _odometer = TextEditingController();
  final _nextServiceOn = TextEditingController();
  final _nextServiceMiles = TextEditingController();
  final _registrationExpiresOn = TextEditingController();
  final _inspectionExpiresOn = TextEditingController();
  final _tollDeviceNumber = TextEditingController();
  final _tollPlan = TextEditingController();
  final _tollFlatRate = TextEditingController();
  final _notes = TextEditingController();
  final _counterparty = TextEditingController();
  final _startedOn = TextEditingController();

  String _bodyType = 'van';
  String _fuelType = 'gas';
  String _status = 'active';
  String? _companyId;
  String? _franchiseId;
  String? _kind;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;
  bool get _canEdit => _me.can('fleet_management.edit');
  bool get _financials => _me.can('fleet_management.financials');
  bool get _atOrganization => _me.scope.type == 'organization';
  bool get _showCompanyField => _me.offersCompanyChoice;

  bool get _showFranchiseField =>
      _me.offersFranchiseChoice && _companyId != null;
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
      _trim,
      _plateState,
      _registrationExpiresOn,
      _inspectionExpiresOn,
      _tollDeviceNumber,
      _tollPlan,
      _tollFlatRate,
      _notes,
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
    _trim.text = row['trim'] as String? ?? '';
    _plate.text = row['plate'] as String? ?? '';
    _plateState.text = row['plate_state'] as String? ?? '';
    _vin.text = row['vin'] as String? ?? '';
    _colour.text = row['colour'] as String? ?? '';
    _odometer.text = row['odometer_miles'] == null
        ? ''
        : '${row['odometer_miles']}';
    _nextServiceOn.text = row['next_service_on'] as String? ?? '';
    _nextServiceMiles.text = row['next_service_miles'] == null
        ? ''
        : '${row['next_service_miles']}';
    _registrationExpiresOn.text = row['registration_expires_on'] as String? ?? '';
    _inspectionExpiresOn.text = row['inspection_expires_on'] as String? ?? '';
    _tollDeviceNumber.text = row['toll_device_number'] as String? ?? '';
    _tollPlan.text = row['toll_plan'] as String? ?? '';
    _tollFlatRate.text = row['toll_flat_rate_cents'] is int
        ? ((row['toll_flat_rate_cents'] as int) / 100).toStringAsFixed(2)
        : '';
    _notes.text = row['notes'] as String? ?? '';
    _bodyType = row['body_type'] as String? ?? 'van';
    _fuelType = row['fuel_type'] as String? ?? 'gas';
    _status = row['status'] as String? ?? 'active';
    final company = fleetMap(row['company']);
    _companyId = company?['id'] as String?;
    final franchise = fleetMap(row['franchise']);
    _franchiseId = franchise?['id'] as String?;
    _kind = fleetMap(row['ownership'])?['kind'] as String?;
    if (_startedOn.text.isEmpty) _startedOn.text = fleetToday();
  }

  List<FranchiseSummary> get _places => _me.franchisesOf(_companyId);

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
      'trim': _trim.text.trim().isEmpty ? null : _trim.text.trim(),
      'plate': _plate.text.trim().isEmpty ? null : _plate.text.trim(),
      'plate_state': _plateState.text.trim().isEmpty
          ? null
          : _plateState.text.trim(),
      'vin': _vin.text.trim().isEmpty ? null : _vin.text.trim(),
      'colour': _colour.text.trim().isEmpty ? null : _colour.text.trim(),
      'body_type': _bodyType,
      'fuel_type': _fuelType,
      'odometer_miles': odometer,
      'next_service_on': _nextServiceOn.text.trim().isEmpty
          ? null
          : _nextServiceOn.text.trim(),
      'next_service_miles': nextMiles,
      'registration_expires_on': _registrationExpiresOn.text.trim().isEmpty
          ? null
          : _registrationExpiresOn.text.trim(),
      'inspection_expires_on': _inspectionExpiresOn.text.trim().isEmpty
          ? null
          : _inspectionExpiresOn.text.trim(),
      'toll_device_number': _tollDeviceNumber.text.trim().isEmpty
          ? null
          : _tollDeviceNumber.text.trim(),
      'toll_plan': _tollPlan.text.trim().isEmpty ? null : _tollPlan.text.trim(),
      if (_financials)
        'toll_flat_rate_cents': fleetParseCents(_tollFlatRate.text),
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'status': _status,
      if (_showCompanyField) 'company_id': _companyId,
      'franchise_id': _companyId == null ? null : _franchiseId,
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
          fleetField(_trim, 'Trim', enabled: enabled),
          fleetField(_plate, 'Plate', enabled: enabled),
          fleetField(_plateState, 'Plate state', enabled: enabled),
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
          fleetCalendarDay(
            _nextServiceOn,
            'Next service on',
            formats: _me.display,
            enabled: enabled,
          ),
          fleetField(
            _nextServiceMiles,
            'Next service miles',
            enabled: enabled,
            keyboard: TextInputType.number,
          ),
          fleetCalendarDay(
            _registrationExpiresOn,
            'Registration expires on',
            formats: _me.display,
            enabled: enabled,
          ),
          fleetCalendarDay(
            _inspectionExpiresOn,
            'Inspection expires on',
            formats: _me.display,
            enabled: enabled,
          ),
          fleetField(_tollDeviceNumber, 'Toll device number', enabled: enabled),
          fleetField(_tollPlan, 'Toll plan', enabled: enabled),
          if (_financials)
            fleetField(
              _tollFlatRate,
              'Toll flat rate',
              enabled: enabled,
              keyboard: TextInputType.number,
            ),
          fleetField(
            _notes,
            'Notes',
            enabled: enabled,
            minLines: 2,
            maxLines: 4,
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
          if (_showCompanyField) ...[
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey('company-$_companyId'),
              initialValue: _companyId ?? fleetOrgValue,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: 'Company',
                helperText: _companyId == null && _atOrganization
                    ? '$fleetGeneralLabel — the whole group can see it.'
                    : null,
                helperMaxLines: 3,
              ),
              items: [
                if (_atOrganization)
                  DropdownMenuItem(
                    value: fleetOrgValue,
                    child: Text(_me.organization.name),
                  ),
                for (final c in _me.companiesInView)
                  DropdownMenuItem(value: c.id, child: Text(c.name)),
              ],
              onChanged: enabled
                  ? (v) => setState(() {
                      _companyId = v == fleetOrgValue ? null : v;
                      _franchiseId = null;
                    })
                  : null,
            ),
          ],
          if (_showFranchiseField) ...[
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey('place-$_companyId-$_franchiseId'),
              initialValue: _franchiseId ?? fleetGeneralValue,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: _me.organization.franchiseLabel,
              ),
              items: [
                const DropdownMenuItem(
                  value: fleetGeneralValue,
                  child: Text(fleetGeneralLabel),
                ),
                for (final f in _places)
                  DropdownMenuItem(value: f.id, child: Text(f.name)),
              ],
              onChanged: enabled
                  ? (v) => setState(
                      () => _franchiseId =
                          v == fleetGeneralValue ? null : v,
                    )
                  : null,
            ),
          ],
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
            fleetCalendarDay(
              _startedOn,
              'Started on',
              formats: _me.display,
              enabled: enabled,
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
