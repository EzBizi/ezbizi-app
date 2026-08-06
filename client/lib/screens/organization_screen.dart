import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';

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
  const OrganizationScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<OrganizationScreen> createState() => _OrganizationScreenState();
}

class _OrganizationScreenState extends State<OrganizationScreen> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _tree;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/companies');

    if (!mounted) return;

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
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(message),
      backgroundColor: isError ? Theme.of(context).colorScheme.error : null,
      behavior: SnackBarBehavior.floating,
      width: 420,
    ));
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
                          Text('Organization',
                              style: Theme.of(context).textTheme.headlineSmall),
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

                _OrganizationRow(name: tree['organization']['name'] as String),

                for (final company
                    in (tree['companies'] as List<dynamic>).cast<Map<String, dynamic>>())
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
            company: company,
            isSub: isSub,
            onRename: () => _rename(
              title: isSub ? 'Rename sub company' : 'Rename company',
              current: company['name'] as String,
              onSubmit: (name) => _send(
                () => widget.api.patch('/companies/${company['id']}', {'name': name}),
                'Renamed',
              ),
            ),
            onToggleActive: () => _send(
              () => widget.api.patch('/companies/${company['id']}',
                  {'is_active': !(company['is_active'] as bool)}),
              company['is_active'] as bool ? 'Switched off' : 'Switched back on',
            ),
            onAddFranchise: () => _createFranchise(company),
            // §3: depth is fixed. Only a top-level company may hold one.
            onAddSubCompany:
                isSub ? null : () => _createCompany(parent: company),
            onRenameFranchise: (f) => _rename(
              title: 'Rename',
              current: f['name'] as String,
              onSubmit: (name) => _send(
                () => widget.api.patch('/franchises/${f['id']}', {'name': name}),
                'Renamed',
              ),
            ),
            onToggleFranchise: (f) => _send(
              () => widget.api.patch('/franchises/${f['id']}',
                  {'is_active': !(f['is_active'] as bool)}),
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
    final result = await showDialog<({String name, String franchise})>(
      context: context,
      builder: (_) => _CompanyDialog(parentName: parent?['name'] as String?),
    );

    if (result == null) return;

    await _send(
      () => widget.api.post('/companies', {
        'name': result.name,
        if (parent != null) 'parent_company_id': parent['id'],
        // §3: a company cannot exist without a franchise, so the form asks for
        // the first one in the same breath rather than leaving somebody with
        // half a company if they close the tab.
        'franchise': {'name': result.franchise},
      }),
      parent == null ? 'Company added' : 'Sub company added',
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

    final value = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: SizedBox(
          width: 380,
          child: TextField(
            controller: controller,
            autofocus: true,
            decoration: InputDecoration(
              labelText: label,
              helperText: hint,
              border: const OutlineInputBorder(),
            ),
            onSubmitted: (v) => Navigator.of(context).pop(v.trim()),
          ),
        ),
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
      ),
    );

    controller.dispose();

    return (value == null || value.isEmpty) ? null : value;
  }
}

// ── rows ───────────────────────────────────────────────────────

class _OrganizationRow extends StatelessWidget {
  const _OrganizationRow({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Row(children: [
      Icon(Icons.hub_outlined, size: 20, color: scheme.primary),
      const SizedBox(width: 10),
      Text(name,
          style: Theme.of(context)
              .textTheme
              .titleMedium
              ?.copyWith(fontWeight: FontWeight.w700)),
      const SizedBox(width: 10),
      Text('the whole business',
          style: Theme.of(context).textTheme.bodySmall),
    ]);
  }
}

class _CompanyCard extends StatelessWidget {
  const _CompanyCard({
    required this.company,
    required this.isSub,
    required this.onRename,
    required this.onToggleActive,
    required this.onAddFranchise,
    required this.onAddSubCompany,
    required this.onRenameFranchise,
    required this.onToggleFranchise,
  });

  final Map<String, dynamic> company;
  final bool isSub;
  final VoidCallback onRename;
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

    final franchises =
        (company['franchises'] as List<dynamic>).cast<Map<String, dynamic>>();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Icon(Icons.apartment_outlined,
                  size: 18,
                  color: active ? scheme.onSurface : scheme.onSurfaceVariant),
              const SizedBox(width: 10),
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
                Text('sub company',
                    style: Theme.of(context).textTheme.bodySmall),
              ],
              if (!active) ...[
                const SizedBox(width: 8),
                Text('switched off',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.error)),
              ],
              const Spacer(),
              if (mayEdit)
                PopupMenuButton<String>(
                  icon: const Icon(Icons.more_horiz, size: 20),
                  itemBuilder: (_) => [
                    const PopupMenuItem(value: 'rename', child: Text('Rename')),
                    PopupMenuItem(
                      value: 'franchise',
                      child: Text('Add a place'),
                    ),
                    if (onAddSubCompany != null)
                      const PopupMenuItem(
                          value: 'sub', child: Text('Add a sub company')),
                    PopupMenuItem(
                      value: 'active',
                      child: Text(active ? 'Switch off' : 'Switch back on'),
                    ),
                  ],
                  onSelected: (v) => switch (v) {
                    'rename' => onRename(),
                    'franchise' => onAddFranchise(),
                    'sub' => onAddSubCompany?.call(),
                    _ => onToggleActive(),
                  },
                ),
            ]),
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
        avatar: Icon(Icons.storefront_outlined,
            size: 15, color: active ? null : scheme.onSurfaceVariant),
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

/// Adding a company, and its first place, in one form.
///
/// Two fields rather than two steps, because §3 makes them one act: a company
/// cannot exist without a franchise. A wizard would leave somebody able to
/// close the tab halfway and produce exactly the state the rule forbids.
class _CompanyDialog extends StatefulWidget {
  const _CompanyDialog({this.parentName});

  final String? parentName;

  @override
  State<_CompanyDialog> createState() => _CompanyDialogState();
}

class _CompanyDialogState extends State<_CompanyDialog> {
  final _name = TextEditingController();
  final _franchise = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    _franchise.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isSub = widget.parentName != null;

    return AlertDialog(
      title: Text(isSub ? 'Add a sub company' : 'Add a company'),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (isSub)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(
                  'Under ${widget.parentName}. It will inherit that company\'s '
                  'settings before the organization\'s — and it cannot be moved '
                  'to a different parent later.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            TextField(
              controller: _name,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'Name',
                border: OutlineInputBorder(),
              ),
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _franchise,
              decoration: const InputDecoration(
                labelText: 'Its first place',
                helperText: 'A company must have somewhere work happens. '
                    'A state, a city, a region — you can add more later.',
                border: OutlineInputBorder(),
              ),
              onChanged: (_) => setState(() {}),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _name.text.trim().isEmpty || _franchise.text.trim().isEmpty
              ? null
              : () => Navigator.of(context).pop((
                  name: _name.text.trim(),
                  franchise: _franchise.text.trim(),
                )),
          child: const Text('Add'),
        ),
      ],
    );
  }
}
