/// "View as" (design 205, Jose 2026-11-23): an Administrator who is a
/// developer sees the app exactly as a role at a place, or as one person,
/// to debug permissions. The server (Roles & Permissions) answers every call
/// as them while it is on; `/me.viewing_as` drives the banner.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import 'list_search.dart';
import 'search_picker.dart';
import 'side_panel.dart';

/// Opens the picker. Returns once a view started or the panel closed.
Future<void> showViewAsPanel(
  BuildContext context, {
  required ApiClient api,
  required Session session,
}) => showSidePanel<void>(
  context,
  builder: (_) => ViewAsPanel(api: api, session: session),
);

class ViewAsPanel extends StatefulWidget {
  const ViewAsPanel({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ViewAsPanel> createState() => _ViewAsPanelState();
}

class _ViewAsPanelState extends State<ViewAsPanel> {
  bool _person = false;
  final _search = TextEditingController();
  Timer? _debounce;
  int _request = 0;

  List<Map<String, dynamic>> _roles = const [];
  List<Map<String, dynamic>> _people = const [];
  List<Map<String, dynamic>> _places = const [];
  String? _place;
  String? _role;
  String? _user;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load([String q = '']) async {
    final request = ++_request;
    final r = await widget.api.get('/view-as/options', {
      if (q.isNotEmpty) 'q': q,
    });
    if (!mounted || request != _request) return;
    if (!r.ok || r.data is! Map) {
      setState(() => _error = r.reason);
      return;
    }
    final data = r.data as Map;
    List<Map<String, dynamic>> rows(String k) => [
      for (final m in (data[k] as List? ?? const []))
        if (m is Map) Map<String, dynamic>.from(m),
    ];
    setState(() {
      _error = null;
      _roles = rows('roles');
      _people = rows('people');
      _places = rows('places');
      _place ??= _places.isEmpty
          ? null
          : '${_places.first['type']}:${_places.first['id']}';
    });
  }

  void _typed(String q) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () => _load(q.trim()));
  }

  bool get _ready => _person ? _user != null : _role != null && _place != null;

  Future<void> _start() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final Map<String, dynamic> body;
    if (_person) {
      body = {'user_id': _user};
    } else {
      final cut = _place!.indexOf(':');
      body = {
        'role_id': _role,
        'scope_type': _place!.substring(0, cut),
        'scope_id': _place!.substring(cut + 1),
      };
    }
    final error = await widget.session.startViewAs(body);
    if (!mounted) return;
    if (error != null) {
      setState(() {
        _busy = false;
        _error = error;
      });
      return;
    }
    Navigator.of(context).maybePop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SidePanel(
      key: const ValueKey('view-as-panel'),
      title: 'View as',
      subtitle:
          'See exactly what they see and can do. Your own account is untouched.',
      actions: [
        FilledButton(
          key: const ValueKey('view-as-start'),
          onPressed: _ready && !_busy ? _start : null,
          child: Text(_busy ? 'Starting…' : 'Start viewing'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              key: const ValueKey('view-as-kind'),
              segments: const [
                ButtonSegment(value: false, label: Text('Role')),
                ButtonSegment(value: true, label: Text('Person')),
              ],
              selected: {_person},
              showSelectedIcon: false,
              onSelectionChanged: (v) => setState(() => _person = v.first),
            ),
          ),
          const SizedBox(height: 12),
          if (!_person) ...[
            SearchPickerField<String>(
              key: const ValueKey('view-as-place'),
              label: 'At',
              value: _place,
              options: [
                for (final p in _places)
                  SearchOption(
                    value: '${p['type']}:${p['id']}',
                    label: '${p['path'] ?? p['label'] ?? ''}',
                  ),
              ],
              onChanged: (v) => setState(() => _place = v),
            ),
            const SizedBox(height: 8),
            for (final r in _roles)
              ListTile(
                key: ValueKey('view-as-role-${r['id']}'),
                dense: true,
                selected: _role == '${r['id']}',
                selectedTileColor: theme.colorScheme.primaryContainer
                    .withValues(alpha: .4),
                leading: Icon(
                  _role == '${r['id']}'
                      ? Icons.radio_button_checked
                      : Icons.radio_button_unchecked,
                  size: 20,
                ),
                title: Text('${r['name'] ?? ''}'),
                onTap: () => setState(() => _role = '${r['id']}'),
              ),
          ] else ...[
            ListSearch(
              key: const ValueKey('view-as-search'),
              controller: _search,
              hint: 'Name or email',
              width: 440,
              onChanged: _typed,
            ),
            const SizedBox(height: 8),
            for (final p in _people)
              ListTile(
                key: ValueKey('view-as-person-${p['id']}'),
                dense: true,
                selected: _user == '${p['id']}',
                selectedTileColor: theme.colorScheme.primaryContainer
                    .withValues(alpha: .4),
                leading: const Icon(Icons.person_outline, size: 20),
                title: Text('${p['name'] ?? ''}'),
                subtitle: Text(
                  [
                    p['role_name'],
                    p['email'],
                  ].where((v) => '${v ?? ''}'.isNotEmpty).join(' · '),
                ),
                onTap: () => setState(() => _user = '${p['id']}'),
              ),
            if (_people.isEmpty)
              Text(
                'Nobody matches.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              key: const ValueKey('view-as-error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}

/// The amber strip under the top bar on every screen while viewing as
/// someone: who, Switch, Exit (design 205 banner A).
class ViewingAsStrip extends StatelessWidget {
  const ViewingAsStrip({
    super.key,
    required this.label,
    required this.onSwitch,
    required this.onExit,
  });

  static const double height = 40;

  final String label;
  final VoidCallback onSwitch;
  final VoidCallback onExit;

  @override
  Widget build(BuildContext context) {
    final tone = ToneColours.of(context, Tone.caution);
    final phone = MediaQuery.sizeOf(context).width < 600;
    return Container(
      key: const ValueKey('viewing-as-strip'),
      height: height,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: tone.fill,
        border: Border(bottom: BorderSide(color: tone.ink, width: 2)),
      ),
      child: Row(
        children: [
          Icon(Icons.visibility_outlined, size: 18, color: tone.ink),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              phone
                  ? 'As $label · changes are real'
                  : 'Viewing as $label — changes you make are real and logged as you',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(color: tone.ink, fontWeight: FontWeight.w600),
            ),
          ),
          if (!phone)
            TextButton(
              key: const ValueKey('viewing-as-switch'),
              onPressed: onSwitch,
              style: TextButton.styleFrom(foregroundColor: tone.ink),
              child: const Text('Switch'),
            ),
          FilledButton(
            key: const ValueKey('viewing-as-exit'),
            onPressed: onExit,
            style: FilledButton.styleFrom(
              backgroundColor: tone.ink,
              foregroundColor: tone.fill,
              visualDensity: VisualDensity.compact,
            ),
            child: const Text('Exit'),
          ),
        ],
      ),
    );
  }
}
