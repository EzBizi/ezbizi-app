import 'package:flutter/material.dart';

import '../../core/session.dart';
import 'item_pane.dart';

/// Company and General / franchise, locked to the current view.
class InventoryPlaceFields extends StatelessWidget {
  const InventoryPlaceFields({
    super.key,
    required this.me,
    required this.companyId,
    required this.franchiseId,
    required this.onCompanyChanged,
    required this.onFranchiseChanged,
    this.enabled = true,
  });

  final Me me;
  final String? companyId;
  final String? franchiseId;
  final ValueChanged<String?> onCompanyChanged;
  final ValueChanged<String?> onFranchiseChanged;
  final bool enabled;

  bool get _atOrganization => me.scope.type == 'organization';

  List<FranchiseSummary> get _places => me.franchisesOf(companyId);

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (me.offersCompanyChoice) ...[
          DropdownButtonFormField<String>(
            initialValue: companyId ?? inventoryOrgValue,
            isExpanded: true,
            decoration: InputDecoration(
              labelText: 'Company',
              helperText: companyId == null && _atOrganization
                  ? '$inventoryGeneralLabel — the whole group can see it.'
                  : null,
              helperMaxLines: 3,
            ),
            items: [
              if (_atOrganization)
                DropdownMenuItem(
                  value: inventoryOrgValue,
                  child: Text(me.organization.name),
                ),
              for (final c in me.companiesInView)
                DropdownMenuItem(value: c.id, child: Text(c.name)),
            ],
            onChanged: enabled
                ? (v) => onCompanyChanged(v == inventoryOrgValue ? null : v)
                : null,
          ),
          const SizedBox(height: 12),
        ],
        if (me.offersFranchiseChoice && companyId != null)
          DropdownButtonFormField<String>(
            key: ValueKey(companyId),
            initialValue: franchiseId ?? inventoryGeneralValue,
            isExpanded: true,
            decoration: InputDecoration(
              labelText: me.organization.franchiseLabel,
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
            onChanged: enabled
                ? (v) =>
                      onFranchiseChanged(v == inventoryGeneralValue ? null : v)
                : null,
          ),
      ],
    );
  }
}
