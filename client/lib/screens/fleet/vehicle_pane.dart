import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tag.dart';
import 'vehicle_assignment_card.dart';
import 'vehicle_card.dart';
import 'vehicle_identity_card.dart';
import 'vehicle_insurance_card.dart';
import 'vehicle_ownership_card.dart';
import 'vehicle_payments_card.dart';
import 'vehicle_photos_card.dart';
import 'vehicle_service_card.dart';

/// One scrolling page of cards for a vehicle (spec §11).
///
/// Order is locked: header, photos, identity, assignment, ownership,
/// insurance, service, then payments only when `/me` holds financials.
/// Photos use `attachable_type=vehicles`. Papers hang off the child row.
class VehiclePane extends StatefulWidget {
  const VehiclePane({
    super.key,
    required this.api,
    required this.session,
    required this.vehicle,
    required this.onBack,
    required this.onChanged,
    required this.onDeleted,
    this.showBack = false,
  });

  final ApiClient api;
  final Session session;
  final Map<String, dynamic> vehicle;
  final VoidCallback onBack;
  final ValueChanged<Map<String, dynamic>> onChanged;
  final VoidCallback onDeleted;
  final bool showBack;

  @override
  State<VehiclePane> createState() => _VehiclePaneState();
}

class _VehiclePaneState extends State<VehiclePane> {
  late Map<String, dynamic> _vehicle = widget.vehicle;
  String? _error;

  @override
  void didUpdateWidget(covariant VehiclePane oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.vehicle != widget.vehicle) {
      _vehicle = widget.vehicle;
    }
  }

  String? get _id => _vehicle['id'] as String?;

  bool get _financials =>
      widget.session.me!.can('fleet_management.financials');

  Future<void> _reload() async {
    final id = _id;
    if (id == null) return;

    final response = await widget.api.get('/vehicles/$id');
    if (!mounted) return;

    if (!response.ok) {
      setState(() => _error = response.message);
      return;
    }

    final row = Map<String, dynamic>.from(response.data as Map);
    setState(() {
      _vehicle = row;
      _error = null;
    });
    widget.onChanged(row);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final id = _id;
    final ownership = fleetMap(_vehicle['ownership']);
    final kind = ownership?['kind'] as String?;
    final assignment = fleetMap(_vehicle['assignment']);
    final user = fleetMap(assignment?['user']);
    final driver = user?['name'] as String?;
    final status = _vehicle['status'] as String? ?? '';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        paneHeader(
          context,
          _vehicle['label'] as String? ?? 'Unidentified vehicle',
          showBack: widget.showBack,
          onBack: widget.onBack,
          ellipsis: true,
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
              Wrap(
                spacing: 0,
                runSpacing: 8,
                children: [
                  if (status.isNotEmpty)
                    Tag(
                      fleetStatusLabel(status),
                      warn:
                          status == 'out_of_service' ||
                          status == 'sold' ||
                          status == 'returned',
                    ),
                  if (kind != null) Tag(fleetKindLabel(kind)),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                [
                  _vehicle['number'],
                  fleetOwnerLabel(
                    _vehicle,
                    widget.session.me!.organization.name,
                  ),
                  fleetPlaceLabel(_vehicle),
                  driver ?? 'Nobody has the keys',
                ].where((p) => p != null && '$p'.isNotEmpty).join(' · '),
                style: theme.textTheme.bodyMedium,
              ),
              if (_error != null) ...[
                const SizedBox(height: 14),
                ErrorNote(_error!),
              ],
              const SizedBox(height: 16),
              if (id != null)
                VehiclePhotosCard(
                  api: widget.api,
                  vehicleId: id,
                  canEdit: widget.session.me!.can('fleet_management.edit'),
                ),
              const SizedBox(height: 16),
              VehicleIdentityCard(
                api: widget.api,
                session: widget.session,
                vehicle: _vehicle,
                onChanged: (row) {
                  setState(() => _vehicle = row);
                  widget.onChanged(row);
                },
                onDeleted: widget.onDeleted,
              ),
              const SizedBox(height: 16),
              VehicleAssignmentCard(
                api: widget.api,
                session: widget.session,
                vehicle: _vehicle,
                onReload: _reload,
              ),
              const SizedBox(height: 16),
              VehicleOwnershipCard(
                api: widget.api,
                session: widget.session,
                vehicle: _vehicle,
                onReload: _reload,
              ),
              const SizedBox(height: 16),
              VehicleInsuranceCard(
                api: widget.api,
                session: widget.session,
                vehicle: _vehicle,
                onReload: _reload,
              ),
              const SizedBox(height: 16),
              VehicleServiceCard(
                api: widget.api,
                session: widget.session,
                vehicle: _vehicle,
                onReload: _reload,
              ),
              if (_financials) ...[
                const SizedBox(height: 16),
                VehiclePaymentsCard(
                  api: widget.api,
                  session: widget.session,
                  vehicle: _vehicle,
                  onReload: _reload,
                ),
              ],
            ],
            ),
          ),
        ),
      ],
    );
  }
}
