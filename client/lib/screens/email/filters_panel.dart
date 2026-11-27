import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';

/// One user-made email filter, as `GET email/filters` returns it.
class EmailFilter {
  const EmailFilter({
    required this.id,
    required this.name,
    required this.fromContains,
    required this.subjectContains,
    required this.action,
    required this.shared,
    required this.scopeType,
    required this.scopeId,
    required this.mayDelete,
  });

  factory EmailFilter.fromJson(Map<String, dynamic> json) {
    String? text(Object? v) => v is String && v.isNotEmpty ? v : null;
    return EmailFilter(
      id: '${json['id']}',
      name: text(json['name']) ?? '',
      fromContains: text(json['from_contains']),
      subjectContains: text(json['subject_contains']),
      action: text(json['action']) ?? '',
      shared: json['shared'] == true,
      scopeType: text(json['scope_type']),
      scopeId: json['scope_id'] == null ? null : '${json['scope_id']}',
      mayDelete: json['may_delete'] == true,
    );
  }

  final String id;
  final String name;
  final String? fromContains;
  final String? subjectContains;
  final String action;
  final bool shared;
  final String? scopeType;
  final String? scopeId;
  final bool mayDelete;
}

/// "Folder" / "Move to Notifications" / "Hide", or the server's word for one it added.
String filterActionLabel(String action) => switch (action) {
  'folder' => 'Folder',
  'notifications' => 'Move to Notifications',
  'hide' => 'Hide',
  _ => action,
};

/// Email › Inbox › Filters: the person's own filters and the shared ones.
///
/// `GET email/filters`, `POST email/filters`, `DELETE email/filters/{id}`.
/// The server applies them in `GET email/inbox`, so the panel pops `true`
/// once anything was saved or deleted and the inbox reloads.
///
/// [prefillFrom] opens straight on the add form with the sender filled in
/// ("Filter mail like this").
class EmailFiltersPanel extends StatefulWidget {
  const EmailFiltersPanel({
    super.key,
    required this.api,
    required this.organizationName,
    this.companies = const [],
    this.branches = const [],
    this.prefillFrom,
  });

  final ApiClient api;
  final String organizationName;
  final List<CompanySummary> companies;
  final List<FranchiseSummary> branches;
  final String? prefillFrom;

  @override
  State<EmailFiltersPanel> createState() => _EmailFiltersPanelState();
}

class _EmailFiltersPanelState extends State<EmailFiltersPanel> {
  bool _loading = true;
  String? _loadError;
  List<EmailFilter> _filters = const [];
  bool _mayShare = false;

  final _search = TextEditingController();
  bool _changed = false;
  String? _deleting;
  String? _listError;

  // The add form.
  bool _adding = false;
  final _name = TextEditingController();
  final _from = TextEditingController();
  final _subject = TextEditingController();

  /// '' is "Just me"; otherwise "type:id" ("organization:" has no id).
  String _scope = '';
  bool _saving = false;
  String? _formError;

  @override
  void initState() {
    super.initState();
    _search.addListener(() => setState(() {}));
    if (widget.prefillFrom != null) {
      _adding = true;
      _from.text = widget.prefillFrom!;
      _name.text = widget.prefillFrom!;
    }
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _name.dispose();
    _from.dispose();
    _subject.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _filters.isEmpty;
      _loadError = null;
    });
    final response = await widget.api.get('/email/filters');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!response.ok) {
        _loadError = response.message;
        return;
      }
      final data = response.data;
      final map = data is Map ? data : const {};
      final rows = map['filters'];
      _filters = [
        for (final row in (rows is List ? rows : const []))
          if (row is Map<String, dynamic>) EmailFilter.fromJson(row),
      ];
      _mayShare = map['may_share'] == true;
    });
  }

  void _close() => Navigator.of(context).pop(_changed ? true : null);

  // ── scope ───────────────────────────────────────────────────────────

  List<SearchOption<String>> get _scopeOptions => [
    const SearchOption(value: '', label: 'Just me'),
    SearchOption(
      value: 'organization:',
      label: 'Everyone at ${widget.organizationName}',
    ),
    for (final c in switchedOnCompanies(widget.companies))
      SearchOption(value: 'company:${c.id}', label: 'Everyone at ${c.name}'),
    for (final b in branchOptions(
      switchedOnFranchises(widget.branches, widget.companies),
      widget.companies,
    ))
      SearchOption(value: 'franchise:${b.id}', label: 'Everyone at ${b.label}'),
  ];

  String _scopeLabel(EmailFilter f) {
    final id = f.scopeId;
    switch (f.scopeType) {
      case 'organization':
        return widget.organizationName;
      case 'company':
        for (final c in widget.companies) {
          if (c.id == id) return c.name;
        }
        return 'a company';
      case 'franchise':
        for (final b in branchOptions(
          widget.branches,
          widget.companies,
          activeOnly: false,
        )) {
          if (b.id == id) return b.label;
        }
        return 'a branch';
    }
    return 'everyone';
  }

  // ── saving and deleting ─────────────────────────────────────────────

  bool get _hasMatch =>
      _from.text.trim().isNotEmpty || _subject.text.trim().isNotEmpty;

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      setState(() => _formError = 'Give the filter a name.');
      return;
    }
    if (!_hasMatch) {
      setState(
        () => _formError =
            'Fill in From contains or Subject contains, so the filter knows '
            'which mail it is for.',
      );
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final parts = _scope.split(':');
    final response = await widget.api.post('/email/filters', {
      'name': _name.text.trim(),
      if (_from.text.trim().isNotEmpty) 'from_contains': _from.text.trim(),
      if (_subject.text.trim().isNotEmpty)
        'subject_contains': _subject.text.trim(),
      if (_mayShare && _scope.isNotEmpty) 'scope_type': parts.first,
      if (_mayShare && _scope.isNotEmpty && parts.last.isNotEmpty)
        'scope_id': parts.last,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    _changed = true;
    setState(() {
      _saving = false;
      _adding = false;
      _name.clear();
      _from.clear();
      _subject.clear();
      _scope = '';
    });
    await _load();
  }

  Future<void> _delete(EmailFilter f) async {
    setState(() {
      _deleting = f.id;
      _listError = null;
    });
    final response = await widget.api.delete('/email/filters/${f.id}');
    if (!mounted) return;
    setState(() {
      _deleting = null;
      if (!response.ok) {
        _listError = response.reason;
        return;
      }
      _changed = true;
      _filters = [
        for (final x in _filters)
          if (x.id != f.id) x,
      ];
    });
  }

  // ── drawing ─────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title: _adding ? 'Add a filter' : 'Filters',
      subtitle: _adding
          ? 'Mail that matches gets a folder of its own, named after the '
                'filter.'
          : 'What happens to mail before it reaches your inbox.',
      onClose: _close,
      actions: _adding
          ? [
              TextButton(
                onPressed: _saving
                    ? null
                    : () => setState(() {
                        _adding = false;
                        _formError = null;
                      }),
                child: const Text('Cancel'),
              ),
              FilledButton(
                key: const ValueKey('email-filter-save'),
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save filter'),
              ),
            ]
          : [
              TextButton(onPressed: _close, child: const Text('Close')),
              FilledButton.icon(
                key: const ValueKey('email-filter-add'),
                onPressed: () => setState(() => _adding = true),
                icon: const Icon(Icons.add),
                label: const Text('Add filter'),
              ),
            ],
      child: _adding ? _form(context) : _list(context),
    );
  }

  Widget _list(BuildContext context) {
    final theme = Theme.of(context);
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_loadError != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ErrorNote(_loadError!),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(onPressed: _load, child: const Text('Try again')),
          ),
        ],
      );
    }

    final q = _search.text.trim().toLowerCase();
    final shown = [
      for (final f in _filters)
        if (q.isEmpty ||
            [
              f.name,
              f.fromContains ?? '',
              f.subjectContains ?? '',
              filterActionLabel(f.action),
            ].any((s) => s.toLowerCase().contains(q)))
          f,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('email-filters-search'),
          controller: _search,
          decoration: InputDecoration(
            hintText: 'Search filters',
            prefixIcon: const Icon(Icons.search),
            isDense: true,
            suffixIcon: _search.text.isEmpty
                ? null
                : IconButton(
                    tooltip: 'Clear search',
                    icon: const Icon(Icons.close),
                    onPressed: _search.clear,
                  ),
          ),
        ),
        const SizedBox(height: 12),
        if (_listError != null) ...[
          ErrorNote(_listError!),
          const SizedBox(height: 8),
        ],
        if (_filters.isEmpty)
          Text(
            'No filters yet. Add one to give matching mail a folder of its own.',
            key: const ValueKey('email-filters-empty'),
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          )
        else if (shown.isEmpty)
          Text(
            'No filter matches "${_search.text.trim()}".',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        for (final f in shown) _row(context, f),
      ],
    );
  }

  Widget _row(BuildContext context, EmailFilter f) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Container(
      key: ValueKey('email-filter-${f.id}'),
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: theme.dividerColor)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(f.name, style: theme.textTheme.titleSmall),
                if (f.fromContains != null)
                  Text('From contains "${f.fromContains}"', style: muted),
                if (f.subjectContains != null)
                  Text('Subject contains "${f.subjectContains}"', style: muted),
                const SizedBox(height: 4),
                Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  children: [
                    _tag(context, filterActionLabel(f.action)),
                    if (f.shared)
                      _tag(
                        context,
                        'Shared · ${_scopeLabel(f)}',
                        key: ValueKey('email-filter-shared-${f.id}'),
                        strong: true,
                      ),
                  ],
                ),
              ],
            ),
          ),
          if (f.mayDelete)
            IconButton(
              key: ValueKey('email-filter-delete-${f.id}'),
              tooltip: 'Delete filter',
              icon: const Icon(Icons.delete_outline),
              onPressed: _deleting == null ? () => _delete(f) : null,
            ),
        ],
      ),
    );
  }

  Widget _tag(
    BuildContext context,
    String text, {
    Key? key,
    bool strong = false,
  }) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      key: key,
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: strong
            ? scheme.secondaryContainer
            : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          color: strong ? scheme.onSecondaryContainer : scheme.onSurface,
        ),
      ),
    );
  }

  Widget _form(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('email-filter-name'),
          controller: _name,
          enabled: !_saving,
          decoration: const InputDecoration(labelText: 'Name'),
        ),
        const SizedBox(height: 16),
        TextField(
          key: const ValueKey('email-filter-from'),
          controller: _from,
          enabled: !_saving,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(
            labelText: 'From contains',
            helperText: 'An address or part of one, like @example.com.',
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          key: const ValueKey('email-filter-subject'),
          controller: _subject,
          enabled: !_saving,
          decoration: const InputDecoration(labelText: 'Subject contains'),
        ),
        const SizedBox(height: 16),
        if (_mayShare) ...[
          const SizedBox(height: 16),
          SearchPickerField<String>(
            key: const ValueKey('email-filter-scope'),
            label: 'Who it applies to',
            value: _scope,
            options: _scopeOptions,
            helperText: 'Shared filters apply to everyone there.',
            onChanged: _saving ? null : (v) => setState(() => _scope = v ?? ''),
          ),
        ],
        if (_formError != null) ...[
          const SizedBox(height: 14),
          ErrorNote(_formError!),
        ],
      ],
    );
  }
}
