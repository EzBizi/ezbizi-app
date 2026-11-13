import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'inventory_place_fields.dart';
import 'item_pane.dart';

/// Add is the same 75% pane as a location, not a modal.
class AddLocationPane extends StatefulWidget {
  const AddLocationPane({
    super.key,
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onSaved,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final VoidCallback onCancel;
  final ValueChanged<Map<String, dynamic>> onSaved;
  final bool showBack;

  @override
  State<AddLocationPane> createState() => _AddLocationPaneState();
}

class _AddLocationPaneState extends State<AddLocationPane> {
  final _name = TextEditingController();
  String? _kind;
  String? _companyId;
  String? _franchiseId;
  String? _vehicleId;
  List<Map<String, dynamic>> _vehicles = [];
  bool _loadingVehicles = false;
  bool _busy = false;
  String? _error;

  Me get _me => widget.session.me!;

  bool get _atOrganization => _me.scope.type == 'organization';

  bool get _fleetView => _me.can('fleet_management.view');

  List<(String, String)> get _kinds => [
    for (final k in inventoryKinds)
      if (k.$1 != 'van' || _fleetView) k,
  ];

  @override
  void initState() {
    super.initState();
    _companyId = _me.filingCompanyId;
    _franchiseId = _me.filingFranchiseId;
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _loadVehicles() async {
    if (!_fleetView) return;

    setState(() {
      _loadingVehicles = true;
      _error = null;
    });

    final response = await widget.api.get('/vehicles');
    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loadingVehicles = false;
        _error = response.message;
      });
      return;
    }

    setState(() {
      _loadingVehicles = false;
      _vehicles = inventoryRows(response.data);
    });
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty || _kind == null) return;
    if (_companyId == null && !_atOrganization) return;
    if (_kind == 'van' && _vehicleId == null) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/inventory/locations', {
      'company_id': ?_companyId,
      'franchise_id': ?_franchiseId,
      'name': _name.text.trim(),
      'kind': _kind,
      if (_kind == 'van') 'vehicle_id': _vehicleId,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    widget.onSaved(Map<String, dynamic>.from(response.data as Map));
  }

  @override
  Widget build(BuildContext context) {
    final canSave =
        !_busy &&
        _name.text.trim().isNotEmpty &&
        _kind != null &&
        (_companyId != null || _atOrganization) &&
        (_kind != 'van' || _vehicleId != null);

    return formPane(
      context,
      'New location',
      showBack: widget.showBack,
      busy: _busy,
      onCancel: widget.onCancel,
      onSave: canSave ? _save : null,
      children: [
        InventoryPlaceFields(
          me: _me,
          companyId: _companyId,
          franchiseId: _franchiseId,
          enabled: !_busy,
          onCompanyChanged: (v) => setState(() {
            _companyId = v;
            _franchiseId = null;
          }),
          onFranchiseChanged: (v) => setState(() => _franchiseId = v),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _name,
          enabled: !_busy,
          decoration: const InputDecoration(labelText: 'Name'),
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final k in _kinds)
              ChoiceChip(
                label: Text(k.$2),
                selected: _kind == k.$1,
                onSelected: _busy
                    ? null
                    : (_) {
                        setState(() {
                          _kind = k.$1;
                          if (_kind != 'van') _vehicleId = null;
                        });
                        if (k.$1 == 'van' && _vehicles.isEmpty) {
                          _loadVehicles();
                        }
                      },
              ),
          ],
        ),
        if (_kind == 'van') ...[
          const SizedBox(height: 12),
          if (_loadingVehicles)
            const LinearProgressIndicator()
          else
            SearchPickerField<String>(
              value: _vehicleId,
              label: 'Vehicle',
              options: [
                for (final v in _vehicles)
                  SearchOption(value: v['id'] as String?, label: v['label'] as String? ??
                          v['number'] as String? ??
                          ''),
              ],
              onChanged: _busy
                  ? null
                  : (v) => setState(() => _vehicleId = v),
            ),
        ],
        if (_error != null) ...[
          const SizedBox(height: 14),
          ErrorNote(_error!),
        ],
      ],
    );
  }
}
