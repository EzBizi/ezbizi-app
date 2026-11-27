import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../widgets/catalogue_picker.dart';
import '../../widgets/tag.dart';

/// The Then step's list of steps: one column, its groups as headings, so
/// "For this job", "Send a message", "Tell the team" and "Elsewhere" are all
/// in view at once (UI queue 133, Jose's 1). A search narrows every group.
class StepCatalogue extends StatefulWidget {
  const StepCatalogue({
    super.key,
    required this.groups,
    required this.selected,
    required this.onSelected,
    this.height = 340,
  });

  final List<PickerGroup> groups;
  final String? selected;
  final ValueChanged<String> onSelected;
  final double height;

  @override
  State<StepCatalogue> createState() => _StepCatalogueState();
}

class _StepCatalogueState extends State<StepCatalogue> {
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _search.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  bool _matches(PickerItem item) {
    final query = _search.text.trim().toLowerCase();
    return query.isEmpty ||
        item.label.toLowerCase().contains(query) ||
        (item.description ?? '').toLowerCase().contains(query);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final groups = [
      for (final group in widget.groups)
        (group, group.items.where(_matches).toList()),
    ].where((g) => g.$2.isNotEmpty).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListSearch(
          controller: _search,
          hint: 'Search steps',
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: widget.height,
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border.all(color: scheme.outlineVariant),
              borderRadius: BorderRadius.circular(10),
            ),
            child: groups.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Text(
                        widget.groups.isEmpty
                            ? 'Nothing can be done on this event yet.'
                            : 'No step matches that.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                // Every row built, not lazily: a few dozen at most, and one
                // scrolled out of view must still be there to reach.
                : SingleChildScrollView(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final (group, items) in groups) ...[
                          Padding(
                            key: ValueKey('automation-step-group-${group.key}'),
                            padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
                            child: Text(
                              group.label.toUpperCase(),
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: scheme.onSurfaceVariant,
                                fontWeight: FontWeight.w800,
                                letterSpacing: 0.6,
                              ),
                            ),
                          ),
                          for (final item in items) _row(context, item),
                        ],
                      ],
                    ),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, PickerItem item) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final chosen = item.key == widget.selected;

    return Opacity(
      opacity: item.enabled ? 1 : 0.6,
      child: InkWell(
        key: ValueKey('automation-step-option-${item.key}'),
        onTap: item.enabled ? () => widget.onSelected(item.key) : null,
        child: Container(
          color: chosen ? scheme.primaryContainer : null,
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Wrap(
                spacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(item.label, style: theme.textTheme.titleSmall),
                  if (item.badge != null) Tag(item.badge!, warn: !item.enabled),
                ],
              ),
              if (item.description != null)
                Text(
                  item.description!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
