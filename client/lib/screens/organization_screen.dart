import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/logo_badge.dart';
import '../widgets/side_panel.dart';
import 'company_form_panel.dart';

/// The shape of the business (plan §3).
///
/// Organization → Company → Sub Company → Franchise, and the only screen that
/// can change it. Until this existed, the structure could be edited by somebody
/// with a shell on the server and by nobody else — the model has carried Sub
/// Companies since the foundation and no customer could ever create one.
///
/// Rendered as a tree rather than a list, for the reason §3 exists at all: a
/// Sub Company inherits from its parent before the organization, and a flat
/// list says the opposite.
class OrganizationScreen extends StatefulWidget {
  const OrganizationScreen({
    super.key,
    required this.api,
    required this.session,
  });

  final ApiClient api;
  final Session session;

  @override
  State<OrganizationScreen> createState() => _OrganizationScreenState();
}

class _OrganizationScreenState extends State<OrganizationScreen> {
  bool _loading = true;

  /// Which load owns the screen — see customers_screen for the whole argument.
  ///
  /// A write here ends in a refresh, and the control that triggers it is no
  /// longer hidden behind a spinner, so two can be in flight together. Whichever
  /// answer lands LAST used to win regardless of which was asked for last, and
  /// nothing was scheduled afterwards to correct it: the kill switch could sit
  /// reading "Off" for a module that was on, until somebody navigated away.
  int _request = 0;
  String? _error;
  Map<String, dynamic>? _tree;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // A spinner only when there is nothing to keep — see AsyncBody. There is
      // no collection here; the tree IS the screen, and its absence is the
      // honest test. Every edit on this page ends in a refetch, so renaming one
      // place used to tear down the whole structure to show the new name.
      _loading = _tree == null;
      _error = null;
    });

    final response = await widget.api.get('/companies');

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;
      _error = response.ok ? null : response.message;
      _tree = response.ok ? response.body : null;
    });
  }

  Future<void> _send(Future<ApiResponse> Function() call, String done) async {
    final response = await call();

    if (!mounted) return;

    if (!response.ok) {
      // The server's own sentence. Every refusal here explains a rule from §3 —
      // why a company cannot lose its last place, why a sub company cannot sit
      // under a sub company — and a generic "could not save" throws away the
      // only part worth reading.
      _snack(_firstError(response) ?? response.message, isError: true);
      return;
    }

    _snack(done);
    _load();
  }

  String? _firstError(ApiResponse response) {
    final errors = response.body['errors'] as Map<String, dynamic>?;

    if (errors == null || errors.isEmpty) return null;

    final first = errors.values.first;

    return first is List && first.isNotEmpty ? first.first as String : null;
  }

  void _snack(String message, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
        behavior: SnackBarBehavior.floating,
        width: 420,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final tree = _tree;

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: tree == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(24),
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Organization',
                            style: Theme.of(context).textTheme.headlineSmall,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Companies, sub companies and the places work happens. '
                            'Settings cascade down this tree, and nothing here can '
                            'be moved to a different parent once it exists.',
                            style: Theme.of(context).textTheme.bodyMedium,
                          ),
                        ],
                      ),
                    ),
                    if (tree['organization']['may_add_company'] == true)
                      FilledButton.icon(
                        onPressed: () => _createCompany(parent: null),
                        icon: const Icon(Icons.add, size: 18),
                        label: const Text('Add company'),
                      ),
                  ],
                ),
                const SizedBox(height: 24),

                _OrganizationRow(
                  api: widget.api,
                  organization: tree['organization'] as Map<String, dynamic>,
                  onChanged: _load,
                ),

                for (final company
                    in (tree['companies'] as List<dynamic>)
                        .cast<Map<String, dynamic>>())
                  _companyBlock(company, isSub: false),
              ],
            ),
    );
  }

  Widget _companyBlock(Map<String, dynamic> company, {required bool isSub}) {
    final subs = (company['sub_companies'] as List<dynamic>? ?? const [])
        .cast<Map<String, dynamic>>();

    return Padding(
      // Sub companies step in once. The indent IS the relationship — it is what
      // says these settings resolve through the company above before reaching
      // the organization.
      padding: EdgeInsets.only(left: isSub ? 36 : 0, top: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _CompanyCard(
            api: widget.api,
            onChanged: _load,
            company: company,
            isSub: isSub,
            onEdit: () => _editCompany(company),
            onToggleActive: () => _send(
              () => widget.api.patch('/companies/${company['id']}', {
                'is_active': !(company['is_active'] as bool),
              }),
              company['is_active'] as bool
                  ? 'Switched off'
                  : 'Switched back on',
            ),
            onAddFranchise: () => _createFranchise(company),
            // §3: depth is fixed. Only a top-level company may hold one.
            onAddSubCompany: isSub
                ? null
                : () => _createCompany(parent: company),
            onRenameFranchise: (f) => _rename(
              title: 'Rename',
              current: f['name'] as String,
              onSubmit: (name) => _send(
                () =>
                    widget.api.patch('/franchises/${f['id']}', {'name': name}),
                'Renamed',
              ),
            ),
            onToggleFranchise: (f) => _send(
              () => widget.api.patch('/franchises/${f['id']}', {
                'is_active': !(f['is_active'] as bool),
              }),
              f['is_active'] as bool ? 'Switched off' : 'Switched back on',
            ),
          ),
          for (final sub in subs) _companyBlock(sub, isSub: true),
        ],
      ),
    );
  }

  // ── the two creation flows ───────────────────────────────────

  Future<void> _createCompany({Map<String, dynamic>? parent}) async {
    final tree = _tree;

    if (tree == null) return;

    // Top-level companies only — §3 fixes the depth, so a sub company can never
    // be a parent. Filtering here means the form cannot offer a choice the
    // server would refuse.
    final parents = (tree['companies'] as List<dynamic>)
        .cast<Map<String, dynamic>>()
        .map((c) => (id: c['id'] as String, name: c['name'] as String))
        .toList();

    final draft = await showSidePanel<CompanyDraft>(
      context,
      builder: (_) => CompanyFormPanel(
        parents: parents,
        fixedParent: parent == null
            ? null
            : (id: parent['id'] as String, name: parent['name'] as String),
      ),
    );

    if (draft == null) return;

    await _send(
      () => widget.api.post('/companies', draft.toJson()),
      draft.parentId == null ? 'Company added' : 'Sub company added',
    );
  }

  /// Everything about a company that can change, in the form that created it.
  ///
  /// This used to be "Rename", a single field — so an address typed once at
  /// creation was permanent, and a company that moved had no way to say so
  /// short of the API. The name was the only editable thing about the entity
  /// whose address goes on the invoices.
  Future<void> _editCompany(Map<String, dynamic> company) async {
    final draft = await showSidePanel<CompanyDraft>(
      context,
      builder: (_) => CompanyFormPanel.edit(company: company),
    );

    if (draft == null) return;

    await _send(
      () =>
          widget.api.patch('/companies/${company['id']}', draft.toUpdateJson()),
      'Saved',
    );
  }

  Future<void> _createFranchise(Map<String, dynamic> company) async {
    final name = await _prompt(
      title: 'Add to ${company['name']}',
      hint: 'Where the work happens — a state, a city, a region.',
      label: 'Name',
    );

    if (name == null) return;

    await _send(
      () => widget.api.post('/franchises', {
        'company_id': company['id'],
        'name': name,
      }),
      'Added',
    );
  }

  Future<void> _rename({
    required String title,
    required String current,
    required Future<void> Function(String) onSubmit,
  }) async {
    final name = await _prompt(title: title, label: 'Name', initial: current);

    if (name == null || name == current) return;

    await onSubmit(name);
  }

  Future<String?> _prompt({
    required String title,
    required String label,
    String? hint,
    String initial = '',
  }) async {
    final controller = TextEditingController(text: initial);

    final value = await showSidePanel<String>(
      context,
      builder: (context) => SidePanel(
        title: title,
        // The field's helper moves up to the subtitle: one explanation, said
        // once, above the thing it explains.
        subtitle: hint,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(controller.text.trim()),
            child: const Text('Save'),
          ),
        ],
        child: TextField(
          controller: controller,
          autofocus: true,
          decoration: InputDecoration(
            labelText: label,
            border: const OutlineInputBorder(),
          ),
          onSubmitted: (v) => Navigator.of(context).pop(v.trim()),
        ),
      ),
    );

    controller.dispose();

    return (value == null || value.isEmpty) ? null : value;
  }
}

// ── rows ───────────────────────────────────────────────────────

class _OrganizationRow extends StatelessWidget {
  const _OrganizationRow({
    required this.api,
    required this.organization,
    required this.onChanged,
  });

  final ApiClient api;
  final Map<String, dynamic> organization;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        LogoBadge(
          api: api,
          path: '/branding/organization',
          version: organization['logo_version'] as String? ?? '',
          hasOwn: organization['has_logo'] == true,
          aspect: organization['logo_aspect'] as String?,
          mayEdit: organization['may_edit'] == true,
          onChanged: onChanged,
          size: 52,
          fallbackIcon: Icons.hub_outlined,
        ),
        const SizedBox(width: 14),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              organization['name'] as String,
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
            Text(
              'the whole business — every company inherits this mark',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ],
    );
  }
}

class _CompanyCard extends StatelessWidget {
  const _CompanyCard({
    required this.api,
    required this.onChanged,
    required this.company,
    required this.isSub,
    required this.onEdit,
    required this.onToggleActive,
    required this.onAddFranchise,
    required this.onAddSubCompany,
    required this.onRenameFranchise,
    required this.onToggleFranchise,
  });

  final ApiClient api;
  final VoidCallback onChanged;
  final Map<String, dynamic> company;
  final bool isSub;
  final VoidCallback onEdit;
  final VoidCallback onToggleActive;
  final VoidCallback onAddFranchise;
  final VoidCallback? onAddSubCompany;
  final void Function(Map<String, dynamic>) onRenameFranchise;
  final void Function(Map<String, dynamic>) onToggleFranchise;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final active = company['is_active'] as bool;
    final mayEdit = company['may_edit'] as bool? ?? false;

    final franchises = (company['franchises'] as List<dynamic>)
        .cast<Map<String, dynamic>>();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                LogoBadge(
                  api: api,
                  path: '/branding/companies/${company['id']}',
                  version: company['logo_version'] as String? ?? '',
                  hasOwn: company['has_logo'] == true,
                  aspect: company['logo_aspect'] as String?,
                  mayEdit: mayEdit,
                  onChanged: onChanged,
                  size: 36,
                ),
                const SizedBox(width: 12),
                Text(
                  company['name'] as String,
                  style: TextStyle(
                    fontWeight: FontWeight.w600,
                    fontSize: 15,
                    color: active ? null : scheme.onSurfaceVariant,
                  ),
                ),
                if (isSub) ...[
                  const SizedBox(width: 8),
                  Text(
                    'sub company',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
                if (!active) ...[
                  const SizedBox(width: 8),
                  Text(
                    'switched off',
                    style: Theme.of(
                      context,
                    ).textTheme.bodySmall?.copyWith(color: scheme.error),
                  ),
                ],
                const Spacer(),
                if (mayEdit)
                  PopupMenuButton<String>(
                    icon: const Icon(Icons.more_horiz, size: 20),
                    itemBuilder: (_) => [
                      const PopupMenuItem(
                        value: 'edit',
                        child: Text('Edit details'),
                      ),
                      PopupMenuItem(
                        value: 'franchise',
                        child: Text('Add a place'),
                      ),
                      if (onAddSubCompany != null)
                        const PopupMenuItem(
                          value: 'sub',
                          child: Text('Add a sub company'),
                        ),
                      PopupMenuItem(
                        value: 'active',
                        child: Text(active ? 'Switch off' : 'Switch back on'),
                      ),
                    ],
                    onSelected: (v) => switch (v) {
                      'edit' => onEdit(),
                      'franchise' => onAddFranchise(),
                      'sub' => onAddSubCompany?.call(),
                      _ => onToggleActive(),
                    },
                  ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final f in franchises)
                  _FranchiseChip(
                    franchise: f,
                    onRename: () => onRenameFranchise(f),
                    onToggle: () => onToggleFranchise(f),
                  ),
                ActionChip(
                  avatar: const Icon(Icons.add, size: 15),
                  label: const Text('Add a place'),
                  onPressed: mayEdit ? onAddFranchise : null,
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _FranchiseChip extends StatelessWidget {
  const _FranchiseChip({
    required this.franchise,
    required this.onRename,
    required this.onToggle,
  });

  final Map<String, dynamic> franchise;
  final VoidCallback onRename;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final active = franchise['is_active'] as bool;
    final mayEdit = franchise['may_edit'] as bool? ?? false;
    final scheme = Theme.of(context).colorScheme;

    return PopupMenuButton<String>(
      enabled: mayEdit,
      tooltip: '${franchise['timezone']} · ${franchise['currency']}',
      itemBuilder: (_) => [
        const PopupMenuItem(value: 'rename', child: Text('Rename')),
        PopupMenuItem(
          value: 'active',
          child: Text(active ? 'Switch off' : 'Switch back on'),
        ),
      ],
      onSelected: (v) => v == 'rename' ? onRename() : onToggle(),
      child: Chip(
        avatar: Icon(
          Icons.storefront_outlined,
          size: 15,
          color: active ? null : scheme.onSurfaceVariant,
        ),
        label: Text(
          franchise['name'] as String,
          style: TextStyle(
            color: active ? null : scheme.onSurfaceVariant,
            decoration: active ? null : TextDecoration.lineThrough,
          ),
        ),
      ),
    );
  }
}
