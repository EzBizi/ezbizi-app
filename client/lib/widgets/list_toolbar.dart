import 'package:flutter/material.dart';

import 'callout.dart';
import 'list_filters.dart';
import 'side_panel.dart';

/// A saved view: a name over a [ListQuery].
typedef SavedView = ({String id, String name, ListQuery query});

/// One of the toolbar's buttons, for a caller that draws them elsewhere
/// (the Task Board's phone menu).
typedef ListToolbarAction = ({
  String key,
  IconData icon,
  String label,
  int count,
  VoidCallback onTap,
});

/// The one toolbar over every list (monday port, desk 2026-11-09):
/// Search · Person · Filter · Sort · Hide · Group by · Views, drawn over Jobs'
/// [ListQuery]. A button shows only when its input is given; every one opens
/// a SidePanel, never a popup.
class ListToolbar extends StatelessWidget {
  const ListToolbar({
    super.key,
    required this.fields,
    required this.query,
    required this.onChanged,
    this.onSearch,
    this.views,
    this.onSaveView,
    this.onDeleteView,
    this.groupBy,
    this.onGroupBy,
    this.onHide,
    this.hiddenCount = 0,
    this.hide = true,
  });

  final List<FilterField> fields;
  final ListQuery query;
  final ValueChanged<ListQuery> onChanged;

  /// Null hides the search field.
  final ValueChanged<String>? onSearch;

  /// Null hides Views; empty shows it with only "Save as new view".
  final List<SavedView>? views;
  final ValueChanged<String>? onSaveView;
  final ValueChanged<String>? onDeleteView;

  /// The field key grouped by, or null. [onGroupBy] null hides Group by.
  final String? groupBy;
  final ValueChanged<String?>? onGroupBy;

  /// A list whose columns are hidden somewhere else (the Task Board hides
  /// them on the board) opens its own Hide, with [hiddenCount] on the
  /// button: one Hide, not two (hands-on QA 11-16).
  final VoidCallback? onHide;
  final int hiddenCount;

  /// False leaves Hide out.
  final bool hide;

  FilterField? get _people =>
      fields.where((f) => f.kind == 'people').firstOrNull;

  /// The buttons after Search, in order.
  List<ListToolbarAction> actions(BuildContext context) {
    final people = _people;
    final groupable = [
      for (final f in fields)
        if (f.kind == 'options' || f.kind == 'people' || f.kind == 'checkbox')
          f,
    ];

    Future<void> filterPanel() async {
      final next = await showSidePanel<ListQuery>(
        context,
        builder: (_) => FilterPanel(fields: fields, view: query),
      );
      if (next != null) onChanged(next);
    }

    Future<void> hidePanel() async {
      final shown = await showSidePanel<List<String>>(
        context,
        builder: (_) => _Ticks(
          title: 'Columns shown',
          options: [for (final f in fields) (f.key, f.label)],
          picked: [
            for (final f in fields)
              if (!query.hidden.contains(f.key)) f.key,
          ],
        ),
      );
      if (shown != null) {
        onChanged(
          query.copyWith(
            hidden: [
              for (final f in fields)
                if (!shown.contains(f.key)) f.key,
            ],
          ),
        );
      }
    }

    Future<void> groupPanel() async {
      final next = await showSidePanel<String>(
        context,
        builder: (context) => SidePanel(
          title: 'Group by',
          child: RadioGroup<String>(
            groupValue: groupBy ?? '',
            onChanged: (v) => Navigator.of(context).pop(v),
            child: Column(
              children: [
                const RadioListTile(value: '', title: Text('No grouping')),
                for (final f in groupable)
                  RadioListTile(
                    key: Key('list-toolbar-group-${f.key}'),
                    value: f.key,
                    title: Text(f.label),
                  ),
              ],
            ),
          ),
        ),
      );
      if (next != null) onGroupBy!(next.isEmpty ? null : next);
    }

    return [
      if (people != null)
        (
          key: 'person',
          icon: Icons.person_outline,
          label: 'Person',
          count: query.people.length,
          onTap: () async {
            final next = await showSidePanel<List<String>>(
              context,
              builder: (_) => _Ticks(
                title: 'Person',
                options: people.options,
                picked: query.people,
              ),
            );
            if (next != null) onChanged(query.copyWith(people: next));
          },
        ),
      (
        key: 'filter',
        icon: Icons.filter_list,
        label: 'Filter',
        count: query.filters.length,
        onTap: filterPanel,
      ),
      (
        key: 'sort',
        icon: Icons.swap_vert,
        label: 'Sort',
        count: query.sort.length,
        onTap: filterPanel,
      ),
      if (hide)
        (
          key: 'hide',
          icon: Icons.visibility_off_outlined,
          label: 'Hide',
          count: onHide != null ? hiddenCount : query.hidden.length,
          onTap: onHide ?? hidePanel,
        ),
      if (onGroupBy != null && groupable.isNotEmpty)
        (
          key: 'group',
          icon: Icons.view_agenda_outlined,
          label: 'Group by',
          count: groupBy == null ? 0 : 1,
          onTap: groupPanel,
        ),
      if (views != null)
        (
          key: 'views',
          icon: Icons.bookmark_outline,
          label: 'Views',
          count: 0,
          onTap: () {
            showSidePanel<void>(
              context,
              builder: (_) => _Views(
                views: views!,
                onPick: onChanged,
                onSave: onSaveView,
                onDelete: onDeleteView,
              ),
            );
          },
        ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    Widget button(ListToolbarAction a) {
      final label = a.count > 0 ? '${a.label} · ${a.count}' : a.label;
      return a.count > 0
          ? FilledButton.tonalIcon(
              key: Key('list-toolbar-${a.key}'),
              onPressed: a.onTap,
              icon: Icon(a.icon, size: 18),
              label: Text(label),
            )
          : TextButton.icon(
              key: Key('list-toolbar-${a.key}'),
              onPressed: a.onTap,
              icon: Icon(a.icon, size: 18),
              label: Text(label),
            );
    }

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (onSearch != null)
          SizedBox(
            width: 260,
            child: TextField(
              key: const Key('list-toolbar-search'),
              onChanged: onSearch,
              decoration: InputDecoration(
                isDense: true,
                prefixIcon: const Icon(Icons.search, size: 18),
                hintText: 'Search',
              ),
            ),
          ),
        for (final a in actions(context))
          a.key == 'filter'
              ? Callout(
                  id: 'list_toolbar',
                  text: 'New: filter, sort, hide and group any list from here.',
                  child: button(a),
                )
              : button(a),
      ],
    );
  }
}

/// A searchable list of ticks; pops the ticked ids.
class _Ticks extends StatefulWidget {
  const _Ticks({
    required this.title,
    required this.options,
    required this.picked,
  });

  final String title;
  final List<(String, String)> options;
  final List<String> picked;

  @override
  State<_Ticks> createState() => _TicksState();
}

class _TicksState extends State<_Ticks> {
  late final _picked = {...widget.picked};
  String _q = '';

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: widget.title,
      actions: [
        FilledButton(
          key: const Key('list-toolbar-apply'),
          onPressed: () => Navigator.of(context).pop([
            for (final (id, _) in widget.options)
              if (_picked.contains(id)) id,
          ]),
          child: const Text('Apply'),
        ),
      ],
      child: Column(
        children: [
          TextField(
            onChanged: (v) => setState(() => _q = v.toLowerCase()),
            decoration: const InputDecoration(
              isDense: true,
              prefixIcon: Icon(Icons.search, size: 18),
              hintText: 'Search',
            ),
          ),
          for (final (id, label) in widget.options)
            if (label.toLowerCase().contains(_q))
              CheckboxListTile(
                key: Key('list-toolbar-tick-$id'),
                value: _picked.contains(id),
                title: Text(label),
                onChanged: (v) => setState(
                  () => v == true ? _picked.add(id) : _picked.remove(id),
                ),
              ),
        ],
      ),
    );
  }
}

class _Views extends StatefulWidget {
  const _Views({
    required this.views,
    required this.onPick,
    this.onSave,
    this.onDelete,
  });

  final List<SavedView> views;
  final ValueChanged<ListQuery> onPick;
  final ValueChanged<String>? onSave;
  final ValueChanged<String>? onDelete;

  @override
  State<_Views> createState() => _ViewsState();
}

class _ViewsState extends State<_Views> {
  final _name = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: 'Views',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final v in widget.views)
            ListTile(
              key: Key('list-toolbar-view-${v.id}'),
              title: Text(v.name),
              onTap: () {
                widget.onPick(v.query);
                Navigator.of(context).pop();
              },
              trailing: widget.onDelete == null
                  ? null
                  : IconButton(
                      tooltip: 'Delete view',
                      icon: const Icon(Icons.delete_outline, size: 18),
                      onPressed: () {
                        widget.onDelete!(v.id);
                        Navigator.of(context).pop();
                      },
                    ),
            ),
          if (widget.onSave != null) ...[
            const Divider(height: 24),
            TextField(
              key: const Key('list-toolbar-view-name'),
              controller: _name,
              decoration: const InputDecoration(labelText: 'New view name'),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                key: const Key('list-toolbar-view-save'),
                onPressed: () {
                  final name = _name.text.trim();
                  if (name.isEmpty) return;
                  widget.onSave!(name);
                  Navigator.of(context).pop();
                },
                child: const Text('Save as new view'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
