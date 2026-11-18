import 'package:flutter/material.dart';

import '../core/numbers.dart';
import '../core/theme.dart';
import 'side_panel.dart';
import 'status_pill.dart';
import 'tab_bar_like.dart' show pillHitHeight;

/// Filter, sort and person filter for any list (first: Task Board), and the saved views that
/// keep them (Jose, 2026-11-09, monday's Filter / Sort / Person and "Save as
/// new view"). Everything runs over the tasks the board already sent; the
/// server only stores a view's config.

/// What a filter or sort can read from a task. [read] gives: text → String,
/// number → num, date → 'YYYY-MM-DD', options / people → a list of ids,
/// checkbox → bool. Null is empty.
typedef FilterField = ({
  String key,
  String label,
  String kind,
  List<(String, String)> options,
  Object? Function(Map<String, dynamic> task) read,
});

typedef FilterRule = ({String field, String op, Object? value});

typedef SortRule = ({String field, bool desc});

/// A view: its filters (all or any), sort, person filter and hidden columns.
class ListQuery {
  const ListQuery({
    this.any = false,
    this.filters = const [],
    this.sort = const [],
    this.people = const [],
    this.hidden = const [],
    this.layout,
  });

  final bool any;
  final List<FilterRule> filters;
  final List<SortRule> sort;
  final List<String> people;
  final List<String> hidden;

  /// A board view's layout (table, kanban, calendar, timeline); null leaves
  /// the screen's own. Monday's saved views each have one.
  final String? layout;

  bool get isEmpty =>
      filters.isEmpty && sort.isEmpty && people.isEmpty && hidden.isEmpty;

  ListQuery copyWith({
    bool? any,
    List<FilterRule>? filters,
    List<SortRule>? sort,
    List<String>? people,
    List<String>? hidden,
    String? layout,
  }) => ListQuery(
    any: any ?? this.any,
    filters: filters ?? this.filters,
    sort: sort ?? this.sort,
    people: people ?? this.people,
    hidden: hidden ?? this.hidden,
    layout: layout ?? this.layout,
  );

  factory ListQuery.fromJson(Map? c) => ListQuery(
    any: c?['match'] == 'any',
    filters: [
      for (final f in (c?['filters'] as List? ?? const []).whereType<Map>())
        (field: '${f['field']}', op: '${f['op']}', value: f['value']),
    ],
    sort: [
      for (final s in (c?['sort'] as List? ?? const []).whereType<Map>())
        (field: '${s['field']}', desc: s['dir'] == 'desc'),
    ],
    people: [for (final p in (c?['people'] as List? ?? const [])) '$p'],
    hidden: [for (final h in (c?['hidden'] as List? ?? const [])) '$h'],
    layout: c?['layout'] is String ? c!['layout'] as String : null,
  );

  Map<String, dynamic> toJson() => {
    'match': any ? 'any' : 'all',
    'filters': [
      for (final f in filters) {'field': f.field, 'op': f.op, 'value': f.value},
    ],
    'sort': [
      for (final s in sort) {'field': s.field, 'dir': s.desc ? 'desc' : 'asc'},
    ],
    'people': people,
    'hidden': hidden,
    if (layout != null) 'layout': layout,
  };

  /// Whether [task] passes, with [owners] its people for the person filter.
  bool matches(
    Map<String, dynamic> task,
    Map<String, FilterField> fields,
    List<String> owners,
  ) {
    if (people.isNotEmpty && !owners.any(people.contains)) return false;
    final rules = [
      for (final f in filters)
        if (fields[f.field] != null) f,
    ];
    if (rules.isEmpty) return true;
    bool one(FilterRule r) => ruleMatches(r, fields[r.field]!.read(task));
    return any ? rules.any(one) : rules.every(one);
  }

  /// Orders [tasks] by the view's sort; unsorted, as they came.
  List<Map<String, dynamic>> sorted(
    List<Map<String, dynamic>> tasks,
    Map<String, FilterField> fields,
  ) {
    final rules = [
      for (final s in sort)
        if (fields[s.field] != null) s,
    ];
    if (rules.isEmpty) return tasks;
    final out = [...tasks];
    // Dart's sort is not stable: fall back to the original order on a tie.
    final at = {for (final (i, t) in tasks.indexed) identityHashCode(t): i};
    out.sort((a, b) {
      for (final s in rules) {
        final f = fields[s.field]!;
        final va = f.read(a), vb = f.read(b);
        if (_empty(va) != _empty(vb)) return _empty(va) ? 1 : -1;
        final c = compareValues(va, vb, f.options);
        if (c != 0) return s.desc ? -c : c;
      }
      return at[identityHashCode(a)]!.compareTo(at[identityHashCode(b)]!);
    });
    return out;
  }
}

String _today() => DateTime.now().toIso8601String().substring(0, 10);

bool _empty(Object? v) =>
    v == null || (v is String && v.trim().isEmpty) || (v is List && v.isEmpty);

/// One rule against one value read from a task.
bool ruleMatches(FilterRule r, Object? v) {
  final want = r.value;
  switch (r.op) {
    case 'empty':
      return _empty(v) || v == false;
    case 'not_empty':
      return !_empty(v) && v != false;
    case 'contains':
      return v != null &&
          '$v'.toLowerCase().contains('${want ?? ''}'.toLowerCase());
    case 'before':
    case 'after':
      if (v is! String || v.isEmpty) return false;
      final day = want == 'today' ? _today() : '$want';
      final c = v.substring(0, v.length < 10 ? v.length : 10).compareTo(day);
      return r.op == 'before' ? c < 0 : c > 0;
    case 'is':
    case 'is_not':
      final wanted = want is List ? [for (final w in want) '$w'] : ['$want'];
      final have = v is List
          ? [for (final x in v) '$x']
          : v is bool
          ? [v ? 'true' : 'false']
          : v == null
          ? const <String>[]
          : ['$v'];
      final hit = have.any(
        (h) => wanted.any((w) => w.toLowerCase() == h.toLowerCase()),
      );
      return r.op == 'is' ? hit : !hit;
  }
  return true;
}

/// Empty last; options in their board order; else natural order.
int compareValues(Object? a, Object? b, List<(String, String)> options) {
  if (_empty(a) && _empty(b)) return 0;
  if (_empty(a)) return 1;
  if (_empty(b)) return -1;
  if (a is List) a = a.first;
  if (b is List) b = b.first;
  if (options.isNotEmpty) {
    final ia = options.indexWhere((o) => o.$1 == '$a');
    final ib = options.indexWhere((o) => o.$1 == '$b');
    if (ia != -1 || ib != -1) return ia.compareTo(ib);
  }
  if (a is num && b is num) return a.compareTo(b);
  if (a is bool && b is bool) return (a ? 1 : 0).compareTo(b ? 1 : 0);
  return '$a'.toLowerCase().compareTo('$b'.toLowerCase());
}

/// The conditions a field's kind offers, in words.
List<(String, String)> filterOps(String kind) => [
  if (kind == 'options' || kind == 'people' || kind == 'checkbox') ...[
    ('is', 'is'),
    ('is_not', 'is not'),
  ],
  if (kind == 'text' || kind == 'number') ...[
    ('contains', 'contains'),
    ('is', 'is'),
  ],
  if (kind == 'date') ...[('before', 'is before'), ('after', 'is after')],
  ('empty', 'is empty'),
  ('not_empty', 'is not empty'),
];

/// Filter and sort, in a side panel (house rule: no dialogs). Pops the new
/// [ListQuery], or null when cancelled.
class FilterPanel extends StatefulWidget {
  const FilterPanel({super.key, required this.fields, required this.view});

  final List<FilterField> fields;
  final ListQuery view;

  @override
  State<FilterPanel> createState() => _FilterPanelState();
}

class _FilterPanelState extends State<FilterPanel> {
  late bool _any = widget.view.any;
  late final List<FilterRule> _rules = [...widget.view.filters];
  late final List<SortRule> _sort = [...widget.view.sort];

  FilterField? _field(String key) =>
      widget.fields.where((f) => f.key == key).firstOrNull;

  Widget _value(int i, FilterRule r) {
    final f = _field(r.field);
    if (f == null || r.op == 'empty' || r.op == 'not_empty') {
      return const SizedBox.shrink();
    }
    void set(Object? v) =>
        setState(() => _rules[i] = (field: r.field, op: r.op, value: v));
    if (f.kind == 'checkbox') {
      return DropdownMenu<String>(
        key: ValueKey('filter-value-$i'),
        enableFilter: true,
        requestFocusOnTap: true,
        initialSelection: '${r.value ?? 'true'}',
        onSelected: set,
        dropdownMenuEntries: const [
          DropdownMenuEntry(value: 'true', label: 'Ticked'),
          DropdownMenuEntry(value: 'false', label: 'Not ticked'),
        ],
      );
    }
    if (f.options.isNotEmpty) {
      final picked = r.value is List
          ? [for (final v in r.value as List) '$v']
          : <String>[];
      return Wrap(
        key: ValueKey('filter-value-$i'),
        spacing: 6,
        runSpacing: 6,
        children: [
          for (final (id, label) in f.options)
            FilterChip(
              label: Text(label),
              selected: picked.contains(id),
              onSelected: (on) => set([
                for (final p in picked)
                  if (p != id) p,
                if (on) id,
              ]),
            ),
        ],
      );
    }
    return TextFormField(
      key: ValueKey('filter-value-$i'),
      initialValue: r.value == null ? '' : '${r.value}',
      decoration: InputDecoration(
        isDense: true,
        border: const OutlineInputBorder(),
        hintText: f.kind == 'date' ? 'YYYY-MM-DD or today' : 'Value',
      ),
      onChanged: (v) => _rules[i] = (field: r.field, op: r.op, value: v),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    DropdownMenu<String> fieldMenu(
      Key key,
      String? value,
      ValueChanged<String> onPick,
    ) => DropdownMenu<String>(
      key: key,
      enableFilter: true,
      requestFocusOnTap: true,
      width: 200,
      hintText: 'Column',
      initialSelection: value,
      onSelected: (v) => v == null ? null : onPick(v),
      dropdownMenuEntries: [
        for (final f in widget.fields)
          DropdownMenuEntry(value: f.key, label: f.label),
      ],
    );

    return SidePanel(
      title: 'Filter and sort',
      actions: [
        TextButton(
          key: const Key('filter-clear'),
          onPressed: () => Navigator.of(
            context,
          ).pop(widget.view.copyWith(filters: const [], sort: const [])),
          child: const Text('Clear all'),
        ),
        FilledButton(
          key: const Key('filter-apply'),
          onPressed: () => Navigator.of(context).pop(
            widget.view.copyWith(
              any: _any,
              filters: [
                for (final r in _rules)
                  if (_field(r.field) != null) r,
              ],
              sort: _sort,
            ),
          ),
          child: const Text('Apply'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('Show tasks that match', style: theme.textTheme.bodyMedium),
              SegmentedButton<bool>(
                key: const Key('filter-match'),
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(value: false, label: Text('all')),
                  ButtonSegment(value: true, label: Text('any')),
                ],
                selected: {_any},
                onSelectionChanged: (v) => setState(() => _any = v.first),
              ),
            ],
          ),
          const SizedBox(height: 12),
          for (final (i, r) in _rules.indexed)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: 8,
                    runSpacing: 6,
                    crossAxisAlignment: WrapCrossAlignment.center,

                    children: [
                      fieldMenu(
                        ValueKey('filter-field-$i'),
                        r.field.isEmpty ? null : r.field,
                        (v) => setState(
                          () => _rules[i] = (
                            field: v,
                            op: filterOps(_field(v)!.kind).first.$1,
                            value: null,
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      if (_field(r.field) case final f?)
                        DropdownMenu<String>(
                          key: ValueKey('filter-op-$i'),
                          enableFilter: true,
                          requestFocusOnTap: true,
                          width: 160,
                          initialSelection: r.op,
                          onSelected: (v) => setState(
                            () => _rules[i] = (
                              field: r.field,
                              op: v ?? r.op,
                              value: r.value,
                            ),
                          ),
                          dropdownMenuEntries: [
                            for (final (op, label) in filterOps(f.kind))
                              DropdownMenuEntry(value: op, label: label),
                          ],
                        ),
                      IconButton(
                        tooltip: 'Remove',
                        icon: const Icon(Icons.close, size: 18),
                        onPressed: () => setState(() => _rules.removeAt(i)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  _value(i, r),
                ],
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const Key('filter-add'),
              onPressed: () => setState(
                () => _rules.add((field: '', op: 'is', value: null)),
              ),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add a filter'),
            ),
          ),
          const Divider(height: 28),
          Text('Sort', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          for (final (i, s) in _sort.indexed)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Wrap(
                spacing: 8,
                runSpacing: 6,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  fieldMenu(
                    ValueKey('sort-field-$i'),
                    s.field,
                    (v) => setState(() => _sort[i] = (field: v, desc: s.desc)),
                  ),
                  SegmentedButton<bool>(
                    key: ValueKey('sort-dir-$i'),
                    showSelectedIcon: false,
                    segments: const [
                      ButtonSegment(value: false, label: Text('Ascending')),
                      ButtonSegment(value: true, label: Text('Descending')),
                    ],
                    selected: {s.desc},
                    onSelectionChanged: (v) => setState(
                      () => _sort[i] = (field: s.field, desc: v.first),
                    ),
                  ),
                  IconButton(
                    tooltip: 'Remove',
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: () => setState(() => _sort.removeAt(i)),
                  ),
                ],
              ),
            ),
          if (_sort.length < 3)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const Key('sort-add'),
                onPressed: widget.fields.isEmpty
                    ? null
                    : () => setState(
                        () => _sort.add((
                          field: widget.fields.first.key,
                          desc: false,
                        )),
                      ),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add a sort'),
              ),
            ),
        ],
      ),
    );
  }
}

/// One choice in a [FilterPills] row.
class FilterPill<T> {
  const FilterPill(this.value, this.label, {this.count, this.tone, this.key});

  final T value;
  final String label;
  final int? count;
  final Tone? tone;
  final Key? key;
}

/// The one sideways filter strip (mobile review 2026-11-13, P5; option A of
/// mobile-review-fixes.html §2). Jobs had chips, a segmented control and a
/// picker in two rows; HR and Reports used segmented controls that squeezed
/// their labels at 375 px.
///
/// The same pills as a second-row [TabBarLike]: each takes taps over 40 px,
/// the row starts at the page [Gutter], and on a phone it scrolls sideways
/// and fades at the edge while more pills lie past it. Wider, it wraps: a
/// mouse cannot drag a strip.
class FilterPills<T> extends StatefulWidget {
  const FilterPills({
    super.key,
    required this.items,
    required this.selected,
    required this.onSelected,
    this.padding,
  });

  final List<FilterPill<T>> items;
  final T selected;
  final ValueChanged<T> onSelected;

  /// Around the row; the page gutter at the sides when null.
  final EdgeInsetsGeometry? padding;

  @override
  State<FilterPills<T>> createState() => _FilterPillsState<T>();
}

class _FilterPillsState<T> extends State<FilterPills<T>> {
  final _scroll = ScrollController();
  bool _more = false;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_measure);
    WidgetsBinding.instance.addPostFrameCallback((_) => _measure());
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void _measure() {
    if (!mounted) return;
    final more =
        _scroll.hasClients &&
        _scroll.position.hasContentDimensions &&
        _scroll.offset < _scroll.position.maxScrollExtent - 1;
    if (more != _more) setState(() => _more = more);
  }

  Widget _pill(BuildContext context, FilterPill<T> item) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final on = item.value == widget.selected;
    final ink = on ? scheme.onPrimaryContainer : scheme.onSurface;
    final chip = Theme(
      data: theme.copyWith(canvasColor: Colors.transparent),
      child: ChoiceChip(
        key: item.key,
        avatar: item.tone == null ? null : ToneDot(item.tone!),
        showCheckmark: false,
        selected: on,
        color: WidgetStatePropertyAll(
          on ? scheme.primaryContainer : Colors.transparent,
        ),
        side: on
            ? BorderSide.none
            : BorderSide(color: EzBiziTheme.edge(context)),
        shape: const StadiumBorder(),
        visualDensity: VisualDensity.compact,
        materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
        label: Text.rich(
          TextSpan(
            text: item.label,
            style: TextStyle(color: ink, fontWeight: FontWeight.w600),
            children: [
              if (item.count != null)
                TextSpan(
                  text: '  ${formatCount(item.count!)}',
                  style: TextStyle(
                    color: on ? ink : scheme.onSurfaceVariant,
                    fontWeight: FontWeight.w500,
                  ),
                ),
            ],
          ),
        ),
        onSelected: (_) => widget.onSelected(item.value),
      ),
    );
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => widget.onSelected(item.value),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: pillHitHeight),
        child: Center(widthFactor: 1, child: chip),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final gutter = Gutter.of(context);
    final padding = widget.padding ?? EdgeInsets.symmetric(horizontal: gutter);
    final pills = [for (final item in widget.items) _pill(context, item)];

    if (MediaQuery.sizeOf(context).width >= 600) {
      return Padding(
        padding: padding,
        child: Align(
          alignment: AlignmentDirectional.centerStart,
          child: Wrap(spacing: 6, runSpacing: 4, children: pills),
        ),
      );
    }

    final opaque = Theme.of(context).colorScheme.surface;
    return NotificationListener<ScrollMetricsNotification>(
      onNotification: (_) {
        WidgetsBinding.instance.addPostFrameCallback((_) => _measure());
        return false;
      },
      child: ShaderMask(
        key: const Key('filter-pills-strip'),
        blendMode: BlendMode.dstIn,
        shaderCallback: (bounds) => LinearGradient(
          colors: [opaque, opaque, _more ? Colors.transparent : opaque],
          stops: const [0, 0.8, 1],
        ).createShader(bounds),
        child: SingleChildScrollView(
          controller: _scroll,
          scrollDirection: Axis.horizontal,
          padding: padding,
          child: Row(
            spacing: 6,
            children: [
              ...pills,
              if (_more) const SizedBox.shrink(key: Key('filter-pills-more')),
            ],
          ),
        ),
      ),
    );
  }
}
