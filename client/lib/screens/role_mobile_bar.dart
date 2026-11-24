/// The phone's bottom bar for one role (design 208, Jose 2026-11-23: "a
/// screen on roles and permissions where we can choose what tabs or even sub
/// tabs show there… ideally this screen… will also have a mobile render").
///
/// Four slots, each a module or one of its tabs, beside a live phone that
/// redraws on every change. More is always the fifth. Saved on its own
/// (PATCH roles/{id} with `mobile_bar` only), apart from the permission grid.
// ponytail: no drag-and-drop (gallery option 2); the arrows order the slots.
library;

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/module_screens.dart';
import '../core/permissions.dart';
import '../widgets/error_note.dart';
import '../widgets/search_picker.dart';

class RoleMobileBar extends StatefulWidget {
  const RoleMobileBar({
    super.key,
    required this.api,
    required this.role,
    required this.writable,
    required this.onSaved,
  });

  final ApiClient api;
  final RoleSummary role;
  final bool writable;
  final VoidCallback onSaved;

  /// The shell's default, the same four it fills an unset bar with.
  static const fallback = ['jobs', 'scheduling', 'leads', 'phone'];

  @override
  State<RoleMobileBar> createState() => _RoleMobileBarState();
}

class _RoleMobileBarState extends State<RoleMobileBar> {
  /// 'module' or 'module:tab', left to right.
  late List<String> _slots = [
    for (final s in widget.role.mobileBar ?? const [])
      s.tab == null ? s.module : '${s.module}:${s.tab}',
  ];

  /// module → (label, [(tab, label)]), only what this role can reach.
  Map<String, (String, List<(String, String)>)> _modules = const {};
  bool _loaded = false;
  bool _open = false;
  bool _busy = false;
  String? _error;

  Future<void> _load() async {
    final r = await widget.api.get(
      '/roles/${widget.role.id}/mobile-bar/options',
    );
    if (!mounted) return;
    final data = r.data is Map ? (r.data as Map)['modules'] : null;
    setState(() {
      _loaded = true;
      if (!r.ok || data is! List) {
        _error = r.reason;
        return;
      }
      _modules = {
        for (final m in data)
          if (m is Map && m['module'] is String)
            '${m['module']}': (
              '${m['label'] ?? m['module']}',
              [
                for (final t in (m['tabs'] as List? ?? const []))
                  if (t is Map && t['tab'] is String)
                    ('${t['tab']}', '${t['label'] ?? t['tab']}'),
              ],
            ),
      };
    });
  }

  String _labelOf(String slot, {bool short = false}) {
    final cut = slot.indexOf(':');
    final module = cut < 0 ? slot : slot.substring(0, cut);
    final m = _modules[module];
    final name = m?.$1 ?? module;
    if (cut < 0) return name;
    final tab = slot.substring(cut + 1);
    final tabLabel = m?.$2.where((t) => t.$1 == tab).firstOrNull?.$2 ?? tab;
    return short ? tabLabel : '$name › $tabLabel';
  }

  IconData _iconOf(String slot) =>
      moduleScreens[slot.split(':').first]?.icon ?? Icons.apps_outlined;

  /// What the phone shows: the chosen slots, then the default's.
  List<(String, bool)> get _shown {
    final out = [for (final s in _slots) (s, true)];
    for (final id in [...RoleMobileBar.fallback, ..._modules.keys]) {
      if (out.length >= 4) break;
      if (_modules.containsKey(id) && !out.any((s) => s.$1 == id)) {
        out.add((id, false));
      }
    }
    return out;
  }

  Future<void> _save(List<String>? slots) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final r = await widget.api.patch('/roles/${widget.role.id}', {
      'mobile_bar': slots == null
          ? null
          : [
              for (final s in slots)
                {
                  'module': s.split(':').first,
                  'tab': s.contains(':')
                      ? s.substring(s.indexOf(':') + 1)
                      : null,
                },
            ],
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!r.ok) _error = r.reason;
      if (r.ok && slots == null) _slots = [];
    });
    if (r.ok) widget.onSaved();
  }

  void _move(int i, int by) => setState(() {
    final s = _slots.removeAt(i);
    _slots.insert(i + by, s);
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final options = [
      for (final e in _modules.entries) ...[
        SearchOption(value: e.key, label: e.value.$1),
        for (final t in e.value.$2)
          SearchOption(
            value: '${e.key}:${t.$1}',
            label: '${e.value.$1} › ${t.$2}',
          ),
      ],
    ];

    Widget row(int i) {
      final has = i < _slots.length;
      return Padding(
        key: ValueKey('role-mobile-bar-slot-$i'),
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(
          children: [
            CircleAvatar(
              radius: 12,
              backgroundColor: theme.colorScheme.secondaryContainer,
              child: Text('${i + 1}', style: theme.textTheme.labelMedium),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: SearchPickerField<String>(
                value: has ? _slots[i] : null,
                hint: 'Choose a module or tab…',
                isDense: true,
                border: const OutlineInputBorder(),
                options: [
                  for (final o in options)
                    if (o.value == (has ? _slots[i] : null) ||
                        !_slots.contains(o.value))
                      o,
                ],
                onChanged: widget.writable && !_busy
                    ? (v) => setState(() {
                        if (v == null) return;
                        if (has) {
                          _slots[i] = v;
                        } else {
                          _slots.add(v);
                        }
                      })
                    : null,
              ),
            ),
            if (has && widget.writable) ...[
              IconButton(
                tooltip: 'Move left',
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.arrow_upward, size: 18),
                onPressed: i == 0 ? null : () => _move(i, -1),
              ),
              IconButton(
                tooltip: 'Move right',
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.arrow_downward, size: 18),
                onPressed: i == _slots.length - 1 ? null : () => _move(i, 1),
              ),
              IconButton(
                key: ValueKey('role-mobile-bar-clear-$i'),
                tooltip: 'Clear',
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.close, size: 18),
                onPressed: () => setState(() => _slots.removeAt(i)),
              ),
            ],
          ],
        ),
      );
    }

    final pickers = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < (_slots.length + 1).clamp(1, 4); i++) row(i),
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 8)],
        if (widget.writable)
          Wrap(
            spacing: 8,
            children: [
              OutlinedButton(
                key: const ValueKey('role-mobile-bar-reset'),
                onPressed: _busy ? null : () => _save(null),
                child: const Text('Reset to default'),
              ),
              FilledButton(
                key: const ValueKey('role-mobile-bar-save'),
                onPressed: _busy ? null : () => _save(List.of(_slots)),
                child: Text(_busy ? 'Saving…' : 'Save bar'),
              ),
            ],
          ),
      ],
    );

    return Card(
      key: const ValueKey('role-mobile-bar'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // A row that opens: the role page is long, and the bar is a
            // setting most visits do not touch.
            InkWell(
              key: const ValueKey('role-mobile-bar-open'),
              onTap: () {
                setState(() => _open = !_open);
                if (_open && !_loaded) _load();
              },
              child: Row(
                children: [
                  const Icon(Icons.smartphone_outlined),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Mobile bar', style: theme.textTheme.titleMedium),
                        Text(
                          'What ${widget.role.name} sees at the bottom of the '
                          'phone app. More is always last; an empty slot '
                          'takes the default.',
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                  Icon(_open ? Icons.expand_less : Icons.expand_more),
                ],
              ),
            ),
            if (!_open)
              const SizedBox.shrink()
            else if (!_loaded)
              const Padding(
                padding: EdgeInsets.only(top: 12),
                child: LinearProgressIndicator(minHeight: 2),
              )
            else
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: LayoutBuilder(
                  builder: (context, box) => box.maxWidth >= 640
                      ? Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(child: pickers),
                            const SizedBox(width: 20),
                            _phone(theme),
                          ],
                        )
                      : Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Center(child: _phone(theme)),
                            const SizedBox(height: 12),
                            pickers,
                          ],
                        ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// The live phone: the bar as somebody with this role will see it.
  Widget _phone(ThemeData theme) {
    final scheme = theme.colorScheme;
    final shown = _shown;
    Widget slot(
      IconData icon,
      String label, {
      bool on = false,
      bool dim = false,
    }) => Expanded(
      child: Opacity(
        opacity: dim ? .55 : 1,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 34,
              height: 22,
              decoration: BoxDecoration(
                color: on ? scheme.secondaryContainer : null,
                borderRadius: BorderRadius.circular(11),
              ),
              child: Icon(icon, size: 16),
            ),
            const SizedBox(height: 2),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                label,
                maxLines: 1,
                style: theme.textTheme.labelSmall,
              ),
            ),
          ],
        ),
      ),
    );
    return Container(
      key: const ValueKey('role-mobile-bar-phone'),
      width: 220,
      height: 360,
      decoration: BoxDecoration(
        color: theme.scaffoldBackgroundColor,
        borderRadius: BorderRadius.circular(26),
        border: Border.all(color: scheme.outline, width: 5),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          Expanded(
            child: Center(
              child: Text(
                shown.isEmpty ? '' : _labelOf(shown.first.$1),
                style: theme.textTheme.bodySmall,
              ),
            ),
          ),
          Container(
            color: scheme.surfaceContainer,
            padding: const EdgeInsets.fromLTRB(2, 6, 2, 10),
            child: Row(
              children: [
                for (final (i, (s, chosen)) in shown.indexed)
                  slot(
                    _iconOf(s),
                    _labelOf(s, short: true),
                    on: i == 0,
                    dim: !chosen,
                  ),
                slot(Icons.menu, 'More'),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
