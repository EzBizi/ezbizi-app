import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/tag.dart';

/// Customers (plan §5.2).
///
/// The owning-franchise tag is on every row, deliberately. Its whole job is to
/// stop somebody acting on another franchise's customer without realising, so it
/// has to be visible BEFORE they act — not buried in a detail pane they may
/// never open.
class CustomersScreen extends StatefulWidget {
  const CustomersScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends State<CustomersScreen> {
  final _search = TextEditingController();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _customers = [];
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/customers', {
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _customers = List<Map<String, dynamic>>.from(response.data as List);
        _total = (response.body['meta'] as Map<String, dynamic>?)?['total'] as int? ?? _customers.length;
      } else {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final label = me.organization.franchiseLabel;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Customers',
          note: (_loading || _error != null)
              ? 'Shared across the organization — the $label tag shows who is accountable.'
              : '$_total shared across the organization. The $label tag shows who is '
                  'accountable for each relationship.',
          action: FilledButton.icon(
            // The server decides in the end; hiding the button when the user has
            // no reach anywhere just avoids offering a guaranteed failure.
            onPressed: me.franchises.isEmpty ? null : _create,
            icon: const Icon(Icons.person_add_outlined, size: 18),
            label: const Text('Add customer'),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 12),
          child: TextField(
            controller: _search,
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.search),
              hintText: 'Name, email, phone or number',
              suffixIcon: _search.text.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.clear),
                      onPressed: () {
                        _search.clear();
                        _load();
                      },
                    ),
            ),
            onSubmitted: (_) => _load(),
            onChanged: (_) => setState(() {}),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _customers.isEmpty
                ? const Center(child: Text('No customers yet.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _customers.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _customers[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> customer) {
    final owner = customer['owning_franchise'] as Map<String, dynamic>;
    final jobs = customer['jobs_count'] as int? ?? 0;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
        title: Row(
          children: [
            Flexible(
              child: Text(
                customer['name'] as String,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            // §5.2: visible in the list, not only on the detail screen.
            Tag(owner['name'] as String? ?? 'Unassigned'),
            if (customer['is_active'] == false) const Tag('Inactive', warn: true),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            [
              customer['number'],
              if (customer['phone'] != null) customer['phone'],
              if (customer['email'] != null) customer['email'],
              '$jobs ${jobs == 1 ? 'job' : 'jobs'}',
            ].join(' · '),
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      ),
    );
  }

  Future<void> _create() async {
    final created = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _NewCustomerDialog(api: widget.api, session: widget.session),
    );

    if (created == null || !mounted) return;

    final duplicates = created['possible_duplicates'] as List? ?? [];

    if (duplicates.isNotEmpty) {
      // Reported, never merged (§5.2). Automatic merging of customer records is
      // unrecoverable when it is wrong, so this is a notice for a human.
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Possible duplicate'),
          content: SizedBox(
            width: 420,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Saved. These existing customers look similar — nothing has been '
                  'merged, and nothing will be automatically.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 12),
                ...duplicates.map((d) {
                  final dup = d as Map<String, dynamic>;
                  final owner = dup['owning_franchise'] as Map<String, dynamic>;
                  return ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text('${dup['name']} (${dup['number']})'),
                    subtitle: Text('${dup['phone'] ?? dup['email'] ?? ''} · ${owner['name'] ?? ''}'),
                  );
                }),
              ],
            ),
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Got it'),
            ),
          ],
        ),
      );
    }

    if (mounted) _load();
  }
}

class _NewCustomerDialog extends StatefulWidget {
  const _NewCustomerDialog({required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<_NewCustomerDialog> createState() => _NewCustomerDialogState();
}

class _NewCustomerDialogState extends State<_NewCustomerDialog> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();

  String? _franchiseId;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // Only franchises this user can actually act on — the server sends reach,
    // not the full list, so the picker never offers something that fails on save.
    final reachable = widget.session.me!.franchises;
    if (reachable.length == 1) _franchiseId = reachable.first.id;
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/customers', {
      'owning_franchise_id': _franchiseId,
      'name': _name.text.trim(),
      if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
      if (_email.text.trim().isNotEmpty) 'email': _email.text.trim(),
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(response.body);
  }

  String _firstError(ApiResponse response) {
    final errors = response.body['errors'];

    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }

    return response.message;
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;
    final label = me.organization.franchiseLabel;

    return AlertDialog(
      title: const Text('Add customer'),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DropdownButtonFormField<String>(
                initialValue: _franchiseId,
                decoration: InputDecoration(
                  labelText: 'Owning $label',
                  helperText: 'Accountable for the relationship, and counts them '
                      'in their numbers. Changing it later is a separate, audited step.',
                  helperMaxLines: 3,
                ),
                items: me.franchises
                    .map((f) => DropdownMenuItem(value: f.id, child: Text(f.name)))
                    .toList(),
                onChanged: _busy ? null : (v) => setState(() => _franchiseId = v),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _name,
                decoration: const InputDecoration(labelText: 'Name'),
                autofocus: true,
                enabled: !_busy,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _phone,
                decoration: const InputDecoration(labelText: 'Phone'),
                keyboardType: TextInputType.phone,
                enabled: !_busy,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _email,
                decoration: const InputDecoration(labelText: 'Email'),
                keyboardType: TextInputType.emailAddress,
                enabled: !_busy,
              ),
              if (_error != null) ...[
                const SizedBox(height: 14),
                ErrorNote(_error!),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: (_busy || _franchiseId == null) ? null : _submit,
          child: const Text('Create'),
        ),
      ],
    );
  }
}
