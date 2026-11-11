import '../../core/branch_options.dart';
import 'package:flutter/material.dart';

import '../../core/session.dart';
import '../../widgets/search_picker.dart';
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
          SearchPickerField<String>(
            value: companyId ?? inventoryOrgValue,
            label: 'Company',
            helperText: companyId == null && _atOrganization
                ? '$inventoryGeneralLabel — the whole group can see it.'
                : null,
            helperMaxLines: 3,
            options: [
              if (_atOrganization)
                SearchOption(
                  value: inventoryOrgValue,
                  label: me.organization.name,
                ),
              for (final c in me.companiesKeeping(
                me.companiesInView,
                companyId,
              ))
                SearchOption(value: c.id, label: c.name),
            ],
            onChanged: enabled
                ? (v) => onCompanyChanged(v == inventoryOrgValue ? null : v)
                : null,
          ),
          const SizedBox(height: 12),
        ],
        if (me.offersFranchiseChoice && companyId != null)
          SearchPickerField<String>(
            key: ValueKey(companyId),
            value: franchiseId ?? inventoryGeneralValue,
            label: me.organization.franchiseLabel,
            helperText:
                '$inventoryGeneralLabel is visible to everyone who can see this company.',
            helperMaxLines: 3,
            options: [
              const SearchOption(
                value: inventoryGeneralValue,
                label: inventoryGeneralLabel,
              ),
              for (final f in branchOptions(
                me.franchisesKeeping(_places, franchiseId),
                me.companies,
                activeOnly: false,
              ))
                SearchOption(value: f.id, label: f.label),
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
