import '../../core/branch_options.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/not_filled_in.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/tag.dart';
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
  final _odometerReadOn = TextEditingController();
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

  /// Sent only when somebody actually changes it. The stamp arrives as an
  /// instant and this field is a day, so echoing it back untouched would
  /// silently move every reading to midnight.
  String _odometerReadOnLoaded = '';

  /// Tolls opens itself when it has something to show and starts folded when
  /// it does not. Keyed by vehicle so a rebuild cannot shut a fold the reader
  /// opened — the fold is theirs once they touch it (design §2, layout 2).
  bool _tollsOpen = false;
  String? _foldedFor;

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
    for (final c in [
      _year,
      _make,
      _model,
      _plate,
      _vin,
      _nextServiceOn,
      _nextServiceMiles,
    ]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void didUpdateWidget(covariant VehicleIdentityCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    // A live re-read (somebody else's edit) fills the fields only when
    // nothing is typed here; a different vehicle always fills.
    if (oldWidget.vehicle != widget.vehicle &&
        (oldWidget.vehicle['id'] != widget.vehicle['id'] ||
            (!_busy && _typed == _loadedForm))) {
      _fill(widget.vehicle);
    }
  }

  /// What the form holds, to tell an edit in progress from a filled form.
  String get _typed => [
    for (final c in [
      _year, _make, _model, _trim, _plate, _plateState, _vin, _colour,
      _odometer, _nextServiceOn, _nextServiceMiles, _registrationExpiresOn,
      _inspectionExpiresOn, _tollDeviceNumber, _tollPlan, _tollFlatRate,
      _odometerReadOn, _notes, _counterparty, _startedOn,
    ])
      c.text,
    _bodyType, _fuelType, _status, _companyId, _franchiseId, _kind,
  ].join('\u0001');

  /// [_typed] as last filled from the server.
  String _loadedForm = '';

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
      _odometerReadOn,
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
    _odometerReadOn.text = fleetDay(row['odometer_updated_at'] as String?);
    _odometerReadOnLoaded = _odometerReadOn.text;

    final id = row['id'] as String?;
    if (_foldedFor != id) {
      _foldedFor = id;
      _tollsOpen = _tollDeviceNumber.text.isNotEmpty ||
          _tollPlan.text.isNotEmpty ||
          _tollFlatRate.text.isNotEmpty;
    }
    _bodyType = row['body_type'] as String? ?? 'van';
    _fuelType = row['fuel_type'] as String? ?? 'gas';
    _status = row['status'] as String? ?? 'active';
    final company = fleetMap(row['company']);
    _companyId = company?['id'] as String?;
    final franchise = fleetMap(row['franchise']);
    _franchiseId = franchise?['id'] as String?;
    _kind = fleetMap(row['ownership'])?['kind'] as String?;
    if (_startedOn.text.isEmpty) _startedOn.text = fleetToday();
    _loadedForm = _typed;
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
      if (_odometerReadOn.text.trim() != _odometerReadOnLoaded)
        'odometer_updated_at': _odometerReadOn.text.trim().isEmpty
            ? null
            : _odometerReadOn.text.trim(),
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

    final saved = Map<String, dynamic>.from(response.data as Map);
    setState(() {
      _busy = false;
      _fill(saved);
    });
    widget.onChanged(saved);
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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _identity(context, enabled),
        const SizedBox(height: 16),
        _plates(context, enabled),
        const SizedBox(height: 16),
        _odometerAndService(enabled),
        const SizedBox(height: 16),
        _tolls(context, enabled),
        const SizedBox(height: 16),
        _notesGroup(enabled),
        if (_error != null) ...[
          const SizedBox(height: 14),
          ErrorNote(_error!),
        ],
        if (_canEdit || _me.can('fleet_management.delete')) ...[
          const SizedBox(height: 16),
          _actions(enabled),
        ],
      ],
    );
  }

  /// "3 of 9 filled" — what the group holds, read before its fields.
  ///
  /// Counted rather than hidden: six of these fields are empty on every one of
  /// A-1's vehicles, and an editable field that hides when empty can never be
  /// filled in. The count is the honest version of the same information.
  static Tag _filled(List<String> values) {
    final filled = values.where((v) => v.trim().isNotEmpty).length;

    return Tag('$filled of ${values.length} filled');
  }

  Widget _menu(
    String label,
    String? value,
    List<SearchOption<String>> items,
    void Function(String?)? onChanged, {
    Key? fieldKey,
    String? helper,
  }) {
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: SearchPickerField<String>(
        key: fieldKey,
        value: value,
        label: label,
        helperText: helper,
        helperMaxLines: 3,
        options: items,
        onChanged: onChanged,
      ),
    );
  }

  Widget _identity(BuildContext context, bool enabled) {
    final preview = fleetPreviewName(
      year: _year.text,
      make: _make.text,
      model: _model.text,
      plate: _plate.text,
      vin: _vin.text,
    );

    return FleetSectionCard(
      title: 'Identity',
      tags: [
        _filled([
          _year.text, _make.text, _model.text, _trim.text, _colour.text,
          _vin.text, _bodyType, _fuelType, _status,
        ]),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Will be listed as $preview',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          fleetFieldRow([
            fleetField(
              _year,
              'Year',
              enabled: enabled,
              keyboard: TextInputType.number,
              flagEmpty: true,
            ),
            fleetField(_make, 'Make', enabled: enabled, flagEmpty: true),
            fleetField(_model, 'Model', enabled: enabled, flagEmpty: true),
          ]),
          fleetFieldRow([
            fleetField(_trim, 'Trim', enabled: enabled),
            fleetField(_colour, 'Colour', enabled: enabled, flagEmpty: true),
            _menu(
              'Status',
              _status,
              [
                for (final t in fleetStatuses)
                  SearchOption(value: t.$1, label: t.$2),
              ],
              enabled ? (v) => setState(() => _status = v ?? _status) : null,
              fieldKey: ValueKey('status-$_status'),
            ),
          ]),
          fleetFieldRow([
            fleetField(_vin, 'VIN', enabled: enabled, flagEmpty: true),
            _menu(
              'Body type',
              _bodyType,
              [
                for (final t in fleetBodyTypes)
                  SearchOption(value: t.$1, label: t.$2),
              ],
              enabled
                  ? (v) => setState(() => _bodyType = v ?? _bodyType)
                  : null,
              fieldKey: ValueKey('body-$_bodyType'),
            ),
            _menu(
              'Fuel type',
              _fuelType,
              [
                for (final t in fleetFuelTypes)
                  SearchOption(value: t.$1, label: t.$2),
              ],
              enabled
                  ? (v) => setState(() => _fuelType = v ?? _fuelType)
                  : null,
              fieldKey: ValueKey('fuel-$_fuelType'),
            ),
          ]),
          if (_showCompanyField)
            _menu(
              'Company',
              _companyId ?? fleetOrgValue,
              [
                if (_atOrganization)
                  SearchOption(
                    value: fleetOrgValue,
                    label: _me.organization.name,
                  ),
                for (final c in _me.companiesKeeping(
                  _me.companiesInView,
                  _companyId,
                ))
                  SearchOption(value: c.id, label: c.name),
              ],
              enabled
                  ? (v) => setState(() {
                      _companyId = v == fleetOrgValue ? null : v;
                      _franchiseId = null;
                    })
                  : null,
              fieldKey: ValueKey('company-$_companyId'),
              helper: _companyId == null && _atOrganization
                  ? '$fleetGeneralLabel — the whole group can see it.'
                  : null,
            ),
          if (_showFranchiseField)
            _menu(
              _me.organization.franchiseLabel,
              _franchiseId ?? fleetGeneralValue,
              [
                const SearchOption(
                  value: fleetGeneralValue,
                  label: fleetGeneralLabel,
                ),
                for (final f in branchOptions(
                  _me.franchisesKeeping(_places, _franchiseId),
                  _me.companies,
                  activeOnly: false,
                ))
                  SearchOption(value: f.id, label: f.label),
              ],
              enabled
                  ? (v) => setState(
                      () => _franchiseId = v == fleetGeneralValue ? null : v,
                    )
                  : null,
              fieldKey: ValueKey('place-$_companyId-$_franchiseId'),
            ),
          // Buying it back is a consequence of changing Status, so it stays
          // beside Status rather than becoming a sixth group nobody sees.
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
        ],
      ),
    );
  }

  Widget _plates(BuildContext context, bool enabled) {
    final hasState = _plateState.text.trim().isNotEmpty;
    final hasNumber = _plate.text.trim().isNotEmpty;

    return FleetSectionCard(
      title: 'Plates & registration',
      tags: [
        if (hasState && !hasNumber)
          const Tag('Plate number missing', warn: true)
        else
          _filled([
            _plate.text, _registrationExpiresOn.text,
            _inspectionExpiresOn.text,
          ]),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          fleetFieldRow([
            _plateControl(context, enabled),
            fleetCalendarDay(
              _registrationExpiresOn,
              'Registration expires on',
              formats: _me.display,
              enabled: enabled,
              flagEmpty: true,
            ),
          ]),
          fleetCalendarDay(
            _inspectionExpiresOn,
            'Inspection expires on',
            formats: _me.display,
            enabled: enabled,
          ),
        ],
      ),
    );
  }

  /// State and number as one control.
  ///
  /// Twenty-four of A-1's vehicles carry a state and nine carry a number, so
  /// drawing them as two fields renders "CT" beside an empty box fifteen times
  /// — a filled field next to an empty one, when it is one incomplete fact.
  Widget _plateControl(BuildContext context, bool enabled) {
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 92,
                child: TextField(
                  controller: _plateState,
                  enabled: enabled,
                  textCapitalization: TextCapitalization.characters,
                  onChanged: (_) => setState(() {}),
                  decoration: notFilledIn(
                    context,
                    const InputDecoration(labelText: 'State'),
                    empty: _plateState.text.trim().isEmpty,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  controller: _plate,
                  enabled: enabled,
                  textCapitalization: TextCapitalization.characters,
                  onChanged: (_) => setState(() {}),
                  decoration: notFilledIn(
                    context,
                    const InputDecoration(labelText: 'Plate number'),
                    empty: _plate.text.trim().isEmpty,
                  ),
                ),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(top: 6, left: 12),
            child: Text(
              'State and number are one plate.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }

  Widget _odometerAndService(bool enabled) {
    return FleetSectionCard(
      title: 'Odometer & service',
      tags: [
        _filled([
          _odometer.text, _odometerReadOn.text, _nextServiceOn.text,
          _nextServiceMiles.text,
        ]),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          fleetFieldRow([
            fleetField(
              _odometer,
              'Odometer (miles)',
              enabled: enabled,
              keyboard: TextInputType.number,
              flagEmpty: true,
            ),
            fleetCalendarDay(
              _odometerReadOn,
              'Odometer read on',
              formats: _me.display,
              enabled: enabled,
            ),
          ]),
          fleetFieldRow([
            fleetCalendarDay(
              _nextServiceOn,
              'Next service on',
              formats: _me.display,
              enabled: enabled,
              flagEmpty: _nextServiceMiles.text.trim().isEmpty,
            ),
            fleetField(
              _nextServiceMiles,
              'Next service at (miles)',
              enabled: enabled,
              keyboard: TextInputType.number,
              flagEmpty: _nextServiceOn.text.trim().isEmpty,
            ),
          ]),
        ],
      ),
    );
  }

  Widget _tolls(BuildContext context, bool enabled) {
    final values = [_tollDeviceNumber.text, _tollPlan.text, _tollFlatRate.text];
    final empty = values.every((v) => v.trim().isEmpty);

    return FleetSectionCard(
      title: 'Tolls',
      tags: [
        if (empty) const Tag('Nothing recorded') else _filled(values),
        if (!_financials) const Tag('Flat rate needs Financials'),
      ],
      child: _tollsOpen
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                fleetFieldRow([
                  fleetField(
                    _tollDeviceNumber,
                    'Toll device number',
                    enabled: enabled,
                  ),
                  fleetField(_tollPlan, 'Toll plan', enabled: enabled),
                ]),
                // Money: the server strips the amount without financials, so
                // for those roles this is a disabled control that says why,
                // never an empty box that looks like a missing value.
                Refused(
                  reason: _me.refusal(
                    'fleet_management.financials',
                    'see amounts on the fleet',
                  ),
                  child: fleetField(
                    _tollFlatRate,
                    'Toll flat rate',
                    enabled: enabled && _financials,
                    keyboard: TextInputType.number,
                  ),
                ),
              ],
            )
          : Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: () => setState(() => _tollsOpen = true),
                icon: const Icon(Icons.chevron_right),
                label: const Text('Open to fill these in'),
              ),
            ),
    );
  }

  Widget _notesGroup(bool enabled) {
    return FleetSectionCard(
      title: 'Notes',
      // The card is already called Notes; repeating it inside the box says
      // nothing twice. The label names what the note is about instead.
      child: fleetField(
        _notes,
        'Notes about this vehicle',
        enabled: enabled,
        minLines: 2,
        maxLines: 4,
      ),
    );
  }

  Widget _actions(bool enabled) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      alignment: WrapAlignment.end,
      children: [
        Refused(
          reason: _deleteRefusal,
          child: OutlinedButton(
            onPressed: (_deleteRefusal == null && !_busy) ? _delete : null,
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
    );
  }
}
