import 'package:flutter/material.dart';

/// A person picker's menu body (Jose 11-24): a search box on top, focused,
/// filtering the names as you type, instead of a long list from "Nobody".
/// [picked] marks who is chosen; [onPick] gets an id, or null for "Nobody"
/// when [nobody] is set. [multi] keeps the menu open and shows checkboxes.
class PeopleSearchList extends StatefulWidget {
  const PeopleSearchList({
    super.key,
    required this.people,
    required this.onPick,
    this.picked = const [],
    this.nobody = false,
    this.multi = false,
    this.keyPrefix = 'person',
  });

  final Map<String, String> people;
  final List<String> picked;
  final bool nobody;
  final bool multi;
  final String keyPrefix;
  final void Function(String? id) onPick;

  /// Other words each person is found by, by id: the start of their email and
  /// their Monday name (Adeline 11-24: "Chris" finds Kristopher). The board
  /// fills it from its people as it loads.
  static final Map<String, String> aliases = {};

  @override
  State<PeopleSearchList> createState() => _PeopleSearchListState();
}

class _PeopleSearchListState extends State<PeopleSearchList> {
  final _q = TextEditingController();

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final q = _q.text.trim().toLowerCase();
    final shown = [
      for (final p in widget.people.entries)
        if (q.isEmpty ||
            p.value.toLowerCase().contains(q) ||
            (PeopleSearchList.aliases[p.key]?.toLowerCase().contains(q) ??
                false))
          p,
    ];
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 6, 8, 4),
          child: SizedBox(
            width: 260,
            child: TextField(
              key: ValueKey('${widget.keyPrefix}-search'),
              controller: _q,
              autofocus: true,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(
                isDense: true,
                hintText: 'Search people',
                prefixIcon: Icon(Icons.search, size: 18),
                border: OutlineInputBorder(),
              ),
            ),
          ),
        ),
        ConstrainedBox(
          constraints: const BoxConstraints(maxHeight: 320),
          child: SingleChildScrollView(
            primary: false,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (widget.nobody && q.isEmpty)
                  MenuItemButton(
                    onPressed: () => widget.onPick(null),
                    child: const Text('Nobody'),
                  ),
                for (final p in shown)
                  widget.multi
                      ? CheckboxMenuButton(
                          key: ValueKey('${widget.keyPrefix}-${p.key}'),
                          value: widget.picked.contains(p.key),
                          closeOnActivate: false,
                          onChanged: (_) => widget.onPick(p.key),
                          child: Text(p.value),
                        )
                      : MenuItemButton(
                          key: ValueKey('${widget.keyPrefix}-${p.key}'),
                          onPressed: () => widget.onPick(p.key),
                          child: Text(p.value),
                        ),
                if (shown.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(12),
                    child: Text('Nobody by that name'),
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
