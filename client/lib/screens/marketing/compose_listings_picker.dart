import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../widgets/social_network_mark.dart';

/// Compose's Business Profile listings (Jose, 2026-10-26: "maybe a toggle
/// instead for the Business profile listings to select them all at once, with
/// an expand feature to show them all").
///
/// One row: a switch that ticks every listing that can take a post, how many
/// are ticked, and Expand / Collapse. Expanded, the listings are grouped by
/// branch, each group with its own tick-all, and each listing is named by
/// where it is rather than by the company name every one of them shares.
///
/// [picked] is the composer's own set; this widget changes it and calls
/// [onChanged], so what Publish sends is exactly what it sent before.
class ComposeListingsPicker extends StatefulWidget {
  const ComposeListingsPicker({
    super.key,
    required this.places,
    required this.picked,
    required this.onChanged,
    required this.expanded,
    required this.onExpandedChanged,
    this.enabled = true,
  });

  final List<Map<String, dynamic>> places;
  final Set<String> picked;
  final VoidCallback onChanged;
  final bool expanded;
  final ValueChanged<bool> onExpandedChanged;
  final bool enabled;

  @override
  State<ComposeListingsPicker> createState() => _ComposeListingsPickerState();
}

/// The listing's own name in a list already headed by its company: the place
/// part of "A1 Chimney · Stamford, CT", else its address, else its title.
String listingRowLabel(Map<String, dynamic> place) {
  final label = '${place['label'] ?? ''}';
  final cut = label.indexOf(' · ');
  if (cut >= 0 && cut + 3 < label.length) return label.substring(cut + 3);
  final address = '${place['address'] ?? ''}'.trim();
  if (address.isNotEmpty) return address;

  return '${place['title'] ?? place['label'] ?? ''}';
}

/// "Company · Branch" for a group, or the one name when they are the same.
String listingGroupLabel(Map<String, dynamic> place) {
  final branch = '${place['branch'] ?? ''}'.trim();
  final company = '${place['company'] ?? ''}'.trim();
  if (company.isEmpty || company == branch) return branch;
  if (branch.isEmpty) return company;

  return '$company · $branch';
}

String? _stateNote(Map<String, dynamic> place) {
  if (place['postable'] != false) return null;
  final state = '${place['state'] ?? ''}'.trim().toLowerCase();
  if (state.isEmpty) return 'Cannot post';

  return state[0].toUpperCase() + state.substring(1).replaceAll('_', ' ');
}

class _ComposeListingsPickerState extends State<ComposeListingsPicker> {
  final TextEditingController _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  String _id(Map<String, dynamic> p) => '${p['id']}';

  Iterable<Map<String, dynamic>> get _postable =>
      widget.places.where((p) => p['postable'] != false);

  bool get _allOn =>
      _postable.isNotEmpty &&
      _postable.every((p) => widget.picked.contains(_id(p)));

  void _setAll(bool on) {
    on ? widget.picked.addAll(_postable.map(_id)) : widget.picked.clear();
    widget.onChanged();
  }

  void _toggleGroup(List<Map<String, dynamic>> group) {
    final able = group.where((p) => p['postable'] != false).map(_id).toList();
    final all = able.isNotEmpty && able.every(widget.picked.contains);
    all ? widget.picked.removeAll(able) : widget.picked.addAll(able);
    widget.onChanged();
  }

  bool _matches(Map<String, dynamic> p, String q) {
    if (q.isEmpty) return true;
    return [
      listingRowLabel(p),
      p['address'],
      p['title'],
      p['branch'],
      p['company'],
    ].any((v) => '${v ?? ''}'.toLowerCase().contains(q));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // Counted against the listings that can take a post; the rest are
    // named apart, so "36 of 39" never reads as three forgotten.
    final total = _postable.length;
    final blocked = widget.places.length - total;
    final ticked = widget.places
        .where((p) => widget.picked.contains(_id(p)))
        .length;

    final summary = Wrap(
      key: const ValueKey('social-places'),
      spacing: 12,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Switch(
              key: const ValueKey('social-places-all'),
              value: _allOn,
              onChanged: widget.enabled && _postable.isNotEmpty
                  ? _setAll
                  : null,
            ),
            const SizedBox(width: 8),
            Flexible(
              child: Text(
                'Post to all Business Profile listings',
                style: theme.textTheme.bodyMedium,
              ),
            ),
          ],
        ),
        Text(
          '$ticked of $total selected',
          key: const ValueKey('social-places-count'),
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        if (blocked > 0)
          Text(
            '$blocked cannot post (suspended or unverified)',
            key: const ValueKey('social-places-blocked'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        TextButton.icon(
          key: const ValueKey('social-places-expand'),
          onPressed: () => widget.onExpandedChanged(!widget.expanded),
          icon: Icon(widget.expanded ? Icons.expand_less : Icons.expand_more),
          label: Text(widget.expanded ? 'Collapse' : 'Expand'),
        ),
      ],
    );

    if (!widget.expanded) return summary;

    final q = _search.text.trim().toLowerCase();
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final p in widget.places) {
      groups.putIfAbsent(listingGroupLabel(p), () => []).add(p);
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        summary,
        const SizedBox(height: 6),
        ListSearch(
          key: const ValueKey('social-places-search'),
          controller: _search,
          hint: 'Find a listing',
          onChanged: (_) => setState(() {}),
        ),
        const SizedBox(height: 6),
        for (final (i, entry) in groups.entries.indexed)
          if (entry.value.any((p) => _matches(p, q)))
            _group(theme, i, entry.key, entry.value, q),
      ],
    );
  }

  Widget _group(
    ThemeData theme,
    int index,
    String name,
    List<Map<String, dynamic>> group,
    String q,
  ) {
    final able = group.where((p) => p['postable'] != false).map(_id).toList();
    final on = able.where(widget.picked.contains).length;
    final bool? state = able.isEmpty || on == 0
        ? false
        : on == able.length
        ? true
        : null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        InkWell(
          key: ValueKey('social-places-group-$index'),
          onTap: widget.enabled && able.isNotEmpty
              ? () => _toggleGroup(group)
              : null,
          child: Row(
            children: [
              Checkbox(
                tristate: true,
                value: state,
                onChanged: widget.enabled && able.isNotEmpty
                    ? (_) => _toggleGroup(group)
                    : null,
              ),
              Expanded(
                child: Text(
                  name.isEmpty ? 'Business Profile' : name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelLarge?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
        ),
        for (final place in group)
          if (_matches(place, q)) _row(theme, place),
        const SizedBox(height: 4),
      ],
    );
  }

  Widget _row(ThemeData theme, Map<String, dynamic> place) {
    final id = _id(place);
    final note = _stateNote(place);
    final text = note == null
        ? listingRowLabel(place)
        : '${listingRowLabel(place)} · $note';
    // A listing Google will not take a post for is shown, never ticked.
    final able = widget.enabled && note == null;
    void flip() {
      widget.picked.contains(id)
          ? widget.picked.remove(id)
          : widget.picked.add(id);
      widget.onChanged();
    }

    final address = '${place['address'] ?? ''}'.trim();
    final row = InkWell(
      key: ValueKey('social-place-$id'),
      onTap: able ? flip : null,
      child: Padding(
        padding: const EdgeInsets.only(left: 24),
        child: Row(
          children: [
            Checkbox(
              value: widget.picked.contains(id),
              onChanged: able ? (_) => flip() : null,
            ),
            const SocialNetworkMark(
              network: 'google_business_profile',
              size: 14,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                text,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: note == null
                      ? null
                      : theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          ],
        ),
      ),
    );

    return address.isEmpty ? row : Tooltip(message: address, child: row);
  }
}
