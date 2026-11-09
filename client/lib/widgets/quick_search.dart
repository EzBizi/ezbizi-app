import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import 'side_panel.dart';

/// One place a quick-search result can take you.
typedef QuickSearchHit = ({
  String module,
  String? record,
  String label,
  String detail,
  IconData icon,
});

/// Ctrl+K / ⌘K (monday port, desk 2026-11-09): one box that finds a screen
/// the person can reach, or a customer, job or lead by name, number or phone.
///
/// Screens are matched locally from [screens] (the shell's own destinations,
/// so permissions are already applied); records come from the existing
/// `search` query on /customers, /jobs and /leads, debounced. Arrow keys move,
/// Enter opens. [onOpen] gets the module and, for a record, its id.
Future<void> showQuickSearch(
  BuildContext context, {
  required ApiClient api,
  required List<QuickSearchHit> screens,
  required void Function(String module, String? record) onOpen,
}) => showSidePanel<void>(
  context,
  builder: (context) => SidePanel(
    title: 'Search',
    scrollable: false,
    child: QuickSearch(api: api, screens: screens, onOpen: onOpen),
  ),
);

class QuickSearch extends StatefulWidget {
  const QuickSearch({
    super.key,
    required this.api,
    required this.screens,
    required this.onOpen,
  });

  final ApiClient api;
  final List<QuickSearchHit> screens;
  final void Function(String module, String? record) onOpen;

  @override
  State<QuickSearch> createState() => _QuickSearchState();
}

class _QuickSearchState extends State<QuickSearch> {
  static const _sources = [
    ('customers', '/customers', 'Customer', Icons.person_outline),
    ('jobs', '/jobs', 'Job', Icons.work_outline),
    ('leads', '/leads', 'Lead', Icons.inbox_outlined),
  ];

  final _focus = FocusNode();
  Timer? _debounce;
  String _term = '';
  List<QuickSearchHit> _records = const [];
  int _at = 0;
  int _asked = 0;

  List<QuickSearchHit> get _hits => [
    for (final s in widget.screens)
      if (_term.isEmpty || s.label.toLowerCase().contains(_term.toLowerCase()))
        s,
    ..._records,
  ];

  @override
  void dispose() {
    _debounce?.cancel();
    _focus.dispose();
    super.dispose();
  }

  void _changed(String value) {
    setState(() {
      _term = value.trim();
      _at = 0;
      if (_term.length < 2) _records = const [];
    });
    _debounce?.cancel();
    if (_term.length < 2) return;
    _debounce = Timer(const Duration(milliseconds: 300), _fetch);
  }

  Future<void> _fetch() async {
    final asked = ++_asked;
    final term = _term;
    final reachable = {for (final s in widget.screens) s.module};
    final found = <QuickSearchHit>[];
    await Future.wait([
      for (final (module, path, kind, icon) in _sources)
        if (reachable.contains(module))
          widget.api.get(path, {'search': term, 'per_page': '5'}).then((r) {
            if (!r.ok || r.data is! List) return;
            for (final row in (r.data as List).whereType<Map>()) {
              found.add((
                module: module,
                record: '${row['id']}',
                label: _labelOf(row),
                detail: kind,
                icon: icon,
              ));
            }
          }),
    ]);
    // A slower answer to an older term must not replace a newer one.
    if (!mounted || asked != _asked) return;
    setState(() => _records = found);
  }

  // ponytail: first label-ish field each endpoint carries; add a per-source
  // formatter if one of them starts reading badly.
  static String _labelOf(Map row) {
    for (final k in ['name', 'display_name', 'title', 'serial_id', 'number']) {
      final v = row[k];
      if (v != null && '$v'.trim().isNotEmpty) return '$v';
    }
    return '#${row['id']}';
  }

  void _open(QuickSearchHit hit) {
    Navigator.of(context).pop();
    widget.onOpen(hit.module, hit.record);
  }

  KeyEventResult _key(FocusNode _, KeyEvent event) {
    if (event is! KeyDownEvent && event is! KeyRepeatEvent) {
      return KeyEventResult.ignored;
    }
    final hits = _hits;
    if (hits.isEmpty) return KeyEventResult.ignored;
    switch (event.logicalKey) {
      case LogicalKeyboardKey.arrowDown:
        setState(() => _at = (_at + 1) % hits.length);
      case LogicalKeyboardKey.arrowUp:
        setState(() => _at = (_at - 1 + hits.length) % hits.length);
      case LogicalKeyboardKey.enter || LogicalKeyboardKey.numpadEnter:
        _open(hits[_at.clamp(0, hits.length - 1)]);
      default:
        return KeyEventResult.ignored;
    }
    return KeyEventResult.handled;
  }

  @override
  Widget build(BuildContext context) {
    final hits = _hits;
    final scheme = Theme.of(context).colorScheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Focus(
          onKeyEvent: _key,
          child: TextField(
            key: const Key('quick-search-field'),
            autofocus: true,
            focusNode: _focus,
            onChanged: _changed,
            decoration: const InputDecoration(
              prefixIcon: Icon(Icons.search),
              hintText: 'Screens, customers, jobs, leads',
            ),
          ),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: hits.isEmpty
              ? Center(
                  child: Text(
                    'Nothing matches “$_term”.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                )
              : ListView.builder(
                  itemCount: hits.length,
                  itemBuilder: (context, i) {
                    final hit = hits[i];
                    return ListTile(
                      key: Key('quick-search-hit-$i'),
                      dense: true,
                      selected: i == _at,
                      selectedTileColor: scheme.secondaryContainer,
                      leading: Icon(hit.icon),
                      title: Text(hit.label),
                      subtitle: Text(hit.detail),
                      onTap: () => _open(hit),
                    );
                  },
                ),
        ),
      ],
    );
  }
}
