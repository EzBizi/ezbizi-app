import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';

/// Scheduling › Settings › Schedule: every Workiz technician, and which EzBizi
/// user they are.
///
/// Jose, 2026-09-25: "it should list all the technicians from Workiz, so we can
/// link them to the EzBizi user counterpart." The sync proposes a link when the
/// emails match exactly and marks it "Matched by email"; choosing here makes it
/// a decision the sync never reverts. See SchedulingTechniciansController.
class TechnicianLinks extends StatefulWidget {
  const TechnicianLinks({super.key, required this.api, required this.visible});

  final ApiClient api;
  final bool visible;

  @override
  State<TechnicianLinks> createState() => _TechnicianLinksState();
}

class _TechnicianLinksState extends State<TechnicianLinks> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  String? _saveError;
  bool _unlinkedOnly = false;
  bool _includeInactive = false;
  List<Map<String, dynamic>> _members = const [];
  List<Map<String, dynamic>> _users = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant TechnicianLinks old) {
    super.didUpdateWidget(old);
    if (widget.visible && !old.visible) _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _members.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/scheduling/technicians', {
      if (_includeInactive) 'all': '1',
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _members = (response.body['data'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
      _users = (response.body['users'] as List? ?? const [])
          .cast<Map<String, dynamic>>();
    });
  }

  Future<void> _link(Map<String, dynamic> member, String? userId) async {
    final before = member['user'];
    final after = userId == null
        ? null
        : _users.firstWhere((u) => u['id'] == userId);

    // Shown at once; put back if the server says no.
    setState(() {
      member['user'] = after;
      member['auto_matched'] = false;
      _saveError = null;
    });

    final response = await widget.api.put(
      '/scheduling/technicians/${member['id']}',
      {'user_id': userId},
    );

    if (!mounted || response.ok) return;

    setState(() {
      member['user'] = before;
      _saveError = 'Could not link ${member['name']}: ${response.message}';
    });
  }

  List<Map<String, dynamic>> get _shown {
    final q = _search.text.trim().toLowerCase();

    return [
      for (final m in _members)
        if ((!_unlinkedOnly || m['user'] == null) &&
            (q.isEmpty ||
                '${m['name']} ${m['email'] ?? ''} ${m['franchise_name'] ?? ''}'
                    .toLowerCase()
                    .contains(q)))
          m,
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final linked = _members.where((m) => m['user'] != null).length;
    final shown = _shown;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        key: const ValueKey('tour-scheduling-technicians'),
        padding: const EdgeInsets.all(16),
        children: [
          Text('Workiz technicians', style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(
            '$linked of ${_members.length} linked to an EzBizi user.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            key: const ValueKey('tour-scheduling-technicians-find'),
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              SizedBox(
                width: 280,
                child: TextField(
                  controller: _search,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(
                    labelText: 'Name, email or franchise',
                    prefixIcon: Icon(Icons.search),
                    isDense: true,
                  ),
                ),
              ),
              FilterChip(
                label: const Text('Unlinked only'),
                selected: _unlinkedOnly,
                onSelected: (v) => setState(() => _unlinkedOnly = v),
              ),
              FilterChip(
                label: const Text('Include inactive'),
                selected: _includeInactive,
                onSelected: (v) {
                  setState(() => _includeInactive = v);
                  _load();
                },
              ),
            ],
          ),
          if (_saveError != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_saveError!),
          ],
          const SizedBox(height: 12),
          if (shown.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                _members.isEmpty
                    ? 'No technicians from Workiz yet. They arrive with the '
                          'Workiz sync.'
                    : 'Nobody matches.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ),
          for (final m in shown) _row(context, m),
        ],
      ),
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> m) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final user = m['user'] as Map<String, dynamic>?;

    final who = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('${m['name']}', style: theme.textTheme.titleSmall),
            if (m['is_active'] != true)
              Text('Inactive', style: TextStyle(color: muted, fontSize: 12)),
            if (m['auto_matched'] == true && user != null)
              Chip(
                label: const Text('Matched by email'),
                visualDensity: VisualDensity.compact,
                labelStyle: theme.textTheme.labelSmall,
              ),
          ],
        ),
        Text(
          [
            m['franchise_name'],
            m['role'],
            m['email'],
          ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
      ],
    );

    final picker = SearchPickerField<String>(
      value: user?['id'] as String?,
      hint: 'Not linked',
      allowClear: true,
      isDense: true,
      options: [
        for (final u in _users)
          SearchOption(
            value: u['id'] as String,
            label: '${u['name']}',
            detail: u['email'] as String?,
          ),
      ],
      onChanged: (id) => _link(m, id),
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: LayoutBuilder(
        builder: (context, c) => c.maxWidth >= 640
            ? Row(
                children: [
                  Expanded(child: who),
                  const SizedBox(width: 16),
                  SizedBox(width: 300, child: picker),
                ],
              )
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [who, const SizedBox(height: 6), picker],
              ),
      ),
    );
  }
}
