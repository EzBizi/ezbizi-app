import 'package:flutter/material.dart';

import '../../widgets/tag.dart';

/// One event a rule can react to, as the When step draws it.
class WhenEvent {
  const WhenEvent({
    required this.key,
    required this.label,
    this.description,
    this.isRaised = true,
    this.count,
    this.details,
    this.rules,
  });

  final String key;
  final String label;
  final String? description;
  final bool isRaised;

  /// Times in the last 30 days. Null when the server did not say: a missing
  /// number is not a zero.
  final int? count;

  /// How many details it carries, once they have been read for it.
  final int? details;

  /// Rules already reacting to it.
  final int? rules;
}

/// One entry of the rail: a module, with the sidebar's icon for it.
class WhenCategory {
  const WhenCategory({
    required this.key,
    required this.label,
    required this.icon,
    required this.events,
    this.ruleAbove = false,
  });

  final String key;
  final String label;
  final IconData icon;
  final List<WhenEvent> events;

  /// The sidebar draws a rule above this module, so the rail does too.
  final bool ruleAbove;
}

/// Choosing what sets a rule off (UI queue 130, Jose's 1 and 4).
///
/// A rail of modules with the sidebar's icons, "Most used" first; compact
/// cards; and a details panel drawn at once beside them, so nothing moves when
/// the details finish loading. On a phone the rail is a row of icon chips and
/// ⓘ opens the details in place of the list.
class WhenPicker extends StatefulWidget {
  const WhenPicker({
    super.key,
    required this.categories,
    required this.selected,
    required this.onSelected,
    required this.details,
    required this.narrow,
    this.height = 520,
  });

  final List<WhenCategory> categories;
  final String? selected;
  final ValueChanged<String> onSelected;

  /// The panel for one event, or for none yet. Drawn by the builder, which
  /// holds what the event carries.
  final Widget Function(String? key) details;
  final bool narrow;
  final double height;

  static const mostUsedKey = '_most';
  static const mostUsedCount = 8;

  @override
  State<WhenPicker> createState() => _WhenPickerState();
}

class _WhenPickerState extends State<WhenPicker> {
  final _search = TextEditingController();
  String? _category;

  /// Phone only: the event whose details fill the screen.
  String? _info;

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

  String get _query => _search.text.trim().toLowerCase();

  bool _matches(WhenEvent event) =>
      _query.isEmpty ||
      event.label.toLowerCase().contains(_query) ||
      event.key.toLowerCase().contains(_query) ||
      (event.description ?? '').toLowerCase().contains(_query);

  /// Never-raised events last, each group in the catalogue's order.
  List<WhenEvent> _ordered(List<WhenEvent> events) => [
    for (final e in events)
      if (e.isRaised) e,
    for (final e in events)
      if (!e.isRaised) e,
  ];

  /// The rail: "Most used" (the busiest eight here) on top, then the modules,
  /// leaving out any with nothing to offer.
  List<WhenCategory> get _rail {
    final all = [
      for (final c in widget.categories)
        for (final e in c.events) e,
    ];
    final busy = [
      for (final e in all)
        if ((e.count ?? 0) > 0) e,
    ]..sort((a, b) => b.count!.compareTo(a.count!));

    return [
      if (busy.isNotEmpty)
        WhenCategory(
          key: WhenPicker.mostUsedKey,
          label: 'Most used',
          icon: Icons.star_outline,
          events: busy.take(WhenPicker.mostUsedCount).toList(),
        ),
      for (final c in widget.categories)
        if (c.events.isNotEmpty) c,
    ];
  }

  WhenCategory? _showing(List<WhenCategory> rail) {
    bool has(WhenCategory c) => c.events.any(_matches);

    for (final c in rail) {
      if (c.key == _category && has(c)) return c;
    }
    if (_category == null && widget.selected != null) {
      for (final c in rail) {
        if (c.key != WhenPicker.mostUsedKey &&
            c.events.any((e) => e.key == widget.selected) &&
            has(c)) {
          return c;
        }
      }
    }
    for (final c in rail) {
      if (has(c)) return c;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final rail = _rail;
    final showing = _showing(rail);
    final events = showing == null
        ? const <WhenEvent>[]
        : _ordered(showing.events.where(_matches).toList());

    final search = TextField(
      controller: _search,
      decoration: InputDecoration(
        isDense: true,
        hintText: 'Search every event: “invitation”, “paid”, “review”…',
        prefixIcon: const Icon(Icons.search, size: 18),
        suffixIcon: _search.text.isEmpty
            ? null
            : IconButton(
                icon: const Icon(Icons.clear, size: 18),
                tooltip: 'Clear',
                onPressed: _search.clear,
              ),
      ),
    );

    if (widget.narrow) {
      final info = _info;

      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          search,
          const SizedBox(height: 8),
          SizedBox(
            height: 44,
            // Every chip built, not lazily: the modules are a short list, and
            // one scrolled out of view must still be there to reach.
            child: SingleChildScrollView(
              key: const ValueKey('automation-when-chips'),
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final c in rail)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        avatar: Icon(c.icon, size: 16),
                        showCheckmark: false,
                        label: Text(c.label),
                        selected: c.key == showing?.key,
                        onSelected: c.events.any(_matches)
                            ? (_) => setState(() {
                                _category = c.key;
                                _info = null;
                              })
                            : null,
                      ),
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
          SizedBox(
            height: widget.height,
            child: info != null
                ? Column(
                    key: const ValueKey('automation-when-info-page'),
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton.icon(
                          key: const ValueKey('automation-when-info-back'),
                          onPressed: () => setState(() => _info = null),
                          icon: const Icon(Icons.arrow_back, size: 18),
                          label: const Text('Back to the events'),
                        ),
                      ),
                      Expanded(
                        child: SingleChildScrollView(
                          child: widget.details(info),
                        ),
                      ),
                    ],
                  )
                : _empty(context, events) ??
                      ListView(
                        children: [
                          for (final e in events)
                            Padding(
                              padding: const EdgeInsets.only(bottom: 8),
                              child: _card(context, e, compact: true),
                            ),
                        ],
                      ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        search,
        const SizedBox(height: 10),
        SizedBox(
          height: widget.height,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(width: 200, child: _railColumn(context, rail, showing)),
              const SizedBox(width: 12),
              Expanded(
                child: _empty(context, events) ?? _grid(context, events),
              ),
              const SizedBox(width: 12),
              SizedBox(
                width: 300,
                child: SingleChildScrollView(
                  child: widget.details(widget.selected),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget? _empty(BuildContext context, List<WhenEvent> events) {
    if (events.isNotEmpty) return null;

    return Center(
      child: Text(
        _query.isEmpty
            ? 'There is nothing to choose from here yet.'
            : 'Nothing matches “${_search.text.trim()}”.',
        textAlign: TextAlign.center,
        style: Theme.of(context).textTheme.bodyMedium,
      ),
    );
  }

  Widget _railColumn(
    BuildContext context,
    List<WhenCategory> rail,
    WhenCategory? showing,
  ) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: ListView(
        key: const ValueKey('automation-when-rail'),
        padding: const EdgeInsets.symmetric(vertical: 6),
        children: [
          for (final c in rail) ...[
            if (c.ruleAbove)
              const Divider(height: 9, indent: 10, endIndent: 10),
            Material(
              color: c.key == showing?.key
                  ? scheme.primaryContainer
                  : Colors.transparent,
              child: InkWell(
                key: ValueKey('automation-when-category-${c.key}'),
                onTap: c.events.any(_matches)
                    ? () => setState(() => _category = c.key)
                    : null,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
                  child: Row(
                    children: [
                      Icon(c.icon, size: 18, color: scheme.onSurfaceVariant),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          c.label,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: c.key == showing?.key
                                ? FontWeight.w700
                                : null,
                          ),
                        ),
                      ),
                      Text(
                        '${c.events.where(_matches).length}',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _grid(BuildContext context, List<WhenEvent> events) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = (constraints.maxWidth / 230).floor().clamp(1, 3);
        final rows = <List<WhenEvent>>[
          for (var i = 0; i < events.length; i += columns)
            events.sublist(i, (i + columns).clamp(0, events.length)),
        ];

        return ListView(
          key: const ValueKey('automation-when-grid'),
          children: [
            for (final row in rows)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: IntrinsicHeight(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (var c = 0; c < columns; c++) ...[
                        if (c > 0) const SizedBox(width: 10),
                        Expanded(
                          child: c < row.length
                              ? _card(context, row[c])
                              : const SizedBox.shrink(),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  Widget _card(BuildContext context, WhenEvent event, {bool compact = false}) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final chosen = event.key == widget.selected;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );

    final footer = <Widget>[
      if (!event.isRaised)
        const Tag('Not raised yet', warn: true)
      else ...[
        if (event.count case final n?)
          Text(
            '$n in 30 days',
            style: theme.textTheme.labelSmall?.copyWith(
              color: scheme.primary,
              fontWeight: FontWeight.w700,
            ),
          ),
        if (event.details case final n?)
          Text('$n ${n == 1 ? 'detail' : 'details'}', style: muted),
        if (event.rules case final n? when n > 0)
          Text('$n ${n == 1 ? 'rule' : 'rules'}', style: muted),
      ],
    ];

    final body = Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 6, 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  event.label,
                  style: theme.textTheme.titleSmall?.copyWith(
                    color: chosen ? scheme.onPrimaryContainer : null,
                  ),
                ),
                if (event.description != null && !compact) ...[
                  const SizedBox(height: 2),
                  Text(
                    event.description!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: muted,
                  ),
                ],
                if (footer.isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 2,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      for (var i = 0; i < footer.length; i++) ...[
                        if (i > 0) Text('·', style: muted),
                        footer[i],
                      ],
                    ],
                  ),
                ],
              ],
            ),
          ),
          if (chosen)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: Icon(Icons.check_circle, size: 18, color: scheme.primary),
            ),
          if (compact)
            IconButton(
              key: ValueKey('automation-when-info-${event.key}'),
              tooltip: 'What it carries',
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.info_outline, size: 20),
              // Chosen as well: the details are read for the chosen event.
              onPressed: () {
                widget.onSelected(event.key);
                setState(() => _info = event.key);
              },
            ),
        ],
      ),
    );

    return Opacity(
      opacity: event.isRaised ? 1 : 0.6,
      child: Material(
        color: chosen ? scheme.primaryContainer : scheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(10),
          side: BorderSide(
            color: chosen ? scheme.primary : scheme.outlineVariant,
            width: chosen ? 2 : 1,
          ),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: ValueKey('automation-when-card-${event.key}'),
          onTap: () => widget.onSelected(event.key),
          child: body,
        ),
      ),
    );
  }
}
