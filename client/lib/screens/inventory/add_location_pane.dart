import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
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

  bool get _showCompanyField =>
      _atOrganization || _me.companies.length > 1;

  bool get _fleetView => _me.can('fleet_management.view');

  List<(String, String)> get _kinds => [
    for (final k in inventoryKinds)
      if (k.$1 != 'van' || _fleetView) k,
  ];

  @override
  void initState() {
    super.initState();
    final scope = _me.scope;
    if (scope.type == 'organization') {
      _companyId = null;
    } else if (scope.type == 'company') {
      _companyId = scope.id;
    } else if (scope.type == 'franchise') {
      final place = _me.franchises.where((f) => f.id == scope.id).firstOrNull;
      _companyId = place?.companyId;
      _franchiseId = place?.id;
    } else if (_me.companies.length == 1) {
      _companyId = _me.companies.first.id;
    }
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  List<FranchiseSummary> get _places {
    final companyId = _companyId;
    if (companyId == null) return const [];
    return _me.franchises.where((f) => f.companyId == companyId).toList();
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
    final theme = Theme.of(context);
    final canSave =
        !_busy &&
        _name.text.trim().isNotEmpty &&
        _kind != null &&
        (_companyId != null || _atOrganization) &&
        (_kind != 'van' || _vehicleId != null);

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
                  onPressed: _busy ? null : widget.onCancel,
                ),
              Expanded(
                child: Text('New location', style: theme.textTheme.titleMedium),
              ),
            ],
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            clipBehavior: Clip.none,
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_showCompanyField) ...[
                  DropdownButtonFormField<String>(
                    initialValue: _companyId ?? inventoryOrgValue,
                    isExpanded: true,
                    decoration: InputDecoration(
                      labelText: 'Company',
                      helperText: _companyId == null && _atOrganization
                          ? '$inventoryGeneralLabel — the whole group can see it.'
                          : null,
                      helperMaxLines: 3,
                    ),
                    items: [
                      if (_atOrganization)
                        DropdownMenuItem(
                          value: inventoryOrgValue,
                          child: Text(_me.organization.name),
                        ),
                      for (final c in _me.companies)
                        DropdownMenuItem(value: c.id, child: Text(c.name)),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(() {
                            _companyId = v == inventoryOrgValue ? null : v;
                            _franchiseId = null;
                          }),
                  ),
                  const SizedBox(height: 12),
                ],
                if (_companyId != null)
                  DropdownButtonFormField<String>(
                    key: ValueKey(_companyId),
                    initialValue: _franchiseId ?? inventoryGeneralValue,
                    isExpanded: true,
                    decoration: InputDecoration(
                      labelText: _me.organization.franchiseLabel,
                      helperText:
                          '$inventoryGeneralLabel is visible to everyone who can see this company.',
                      helperMaxLines: 3,
                    ),
                    items: [
                      const DropdownMenuItem(
                        value: inventoryGeneralValue,
                        child: Text(inventoryGeneralLabel),
                      ),
                      for (final f in _places)
                        DropdownMenuItem(value: f.id, child: Text(f.name)),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) => setState(
                            () => _franchiseId =
                                v == inventoryGeneralValue ? null : v,
                          ),
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
                    DropdownButtonFormField<String>(
                      initialValue: _vehicleId,
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'Vehicle',
                      ),
                      items: [
                        for (final v in _vehicles)
                          DropdownMenuItem(
                            value: v['id'] as String?,
                            child: Text(
                              v['label'] as String? ??
                                  v['number'] as String? ??
                                  '',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
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
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              TextButton(
                onPressed: _busy ? null : widget.onCancel,
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: canSave ? _save : null,
                child: const Text('Save'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
