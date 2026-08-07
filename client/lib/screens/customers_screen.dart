import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/franchise_field.dart';
import '../widgets/side_panel.dart';
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
  // Page one only, until asked. The header used to claim a total the list did
  // not render, with no next-page control and nothing saying records were
  // hidden — which reads as data loss rather than as pagination.
  int _page = 1;
  int _lastPage = 1;

  // A failed NEXT page is a different thing from a failed FIRST page, and
  // collapsing them meant one bad request blanked every row already on screen
  // and dropped the reader back to the top of a list they were halfway down.
  String? _pageError;
  bool _loadingMore = false;

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

  Future<void> _load({int page = 1}) async {
    // A second tap while the first request is in flight appends the same page
    // twice, so the list grows with duplicate rows.
    if (_loadingMore) return;

    setState(() {
      if (page == 1) {
        _loading = true;
      } else {
        _loadingMore = true;
      }
      _error = null;
      _pageError = null;
    });

    final response = await widget.api.get('/customers', {
      'page': '$page',
      if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
    });

    if (!mounted) return;

    setState(() {
      _loading = false;
      _loadingMore = false;

      if (!response.ok) {
        // Only a first-page failure takes over the screen — there is nothing to
        // preserve in that case. A later page reports beside the rows.
        if (page == 1) {
          _error = response.message;
        } else {
          _pageError = response.message;
        }
        return;
      }

      final rows = List<Map<String, dynamic>>.from(response.data as List);
      _customers = page == 1 ? rows : [..._customers, ...rows];

      final meta = response.body['meta'] as Map<String, dynamic>?;
      _total = meta?['total'] as int? ?? _customers.length;
      _lastPage = meta?['last_page'] as int? ?? 1;
      _page = page;
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
              : _customers.length < _total
              ? 'Showing ${_customers.length} of $_total shared across the '
                    'organization. The $label tag shows who is accountable.'
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
                ? Center(
                    child: Text(
                      _search.text.trim().isEmpty
                          ? 'No customers yet.'
                          // "No customers yet" after a search reads as data loss.
                          : 'Nothing matches "${_search.text.trim()}".',
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _customers.length + (_page < _lastPage ? 1 : 0),
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => i == _customers.length
                        ? Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                if (_pageError != null) ...[
                                  ErrorNote(_pageError!),
                                  const SizedBox(height: 8),
                                ],
                                TextButton(
                                  onPressed: _loadingMore
                                      ? null
                                      : () => _load(page: _page + 1),
                                  child: Text(
                                    _loadingMore
                                        ? 'Loading…'
                                        : _pageError != null
                                        ? 'Try again'
                                        : 'Load more (${_total - _customers.length} left)',
                                  ),
                                ),
                              ],
                            ),
                          )
                        : _row(context, _customers[i]),
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
            if (customer['is_active'] == false)
              const Tag('Inactive', warn: true),
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
    final created = await showSidePanel<Map<String, dynamic>>(
      context,
      builder: (_) =>
          _NewCustomerPanel(api: widget.api, session: widget.session),
    );

    if (created == null || !mounted) return;

    final duplicates = created['possible_duplicates'] as List? ?? [];

    if (duplicates.isNotEmpty) {
      // Reported, never merged (§5.2). Automatic merging of customer records is
      // unrecoverable when it is wrong, so this is a notice for a human.
      await showSidePanel<void>(
        context,
        builder: (context) => SidePanel(
          title: 'Possible duplicate',
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Got it'),
            ),
          ],
          child: Column(
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
                  subtitle: Text(
                    '${dup['phone'] ?? dup['email'] ?? ''} · ${owner['name'] ?? ''}',
                  ),
                );
              }),
            ],
          ),
        ),
      );
    }

    if (mounted) _load();
  }
}

class _NewCustomerPanel extends StatefulWidget {
  const _NewCustomerPanel({required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<_NewCustomerPanel> createState() => _NewCustomerPanelState();
}

class _NewCustomerPanelState extends State<_NewCustomerPanel> {
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

    return SidePanel(
      title: 'Add customer',
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FranchiseField(
            me: me,
            value: _franchiseId,
            label: 'Owning $label',
            helperText:
                'Accountable for the relationship, and counts them '
                'in their numbers. Changing it later is a separate, audited step.',
            enabled: !_busy,
            onChanged: (v) => setState(() => _franchiseId = v),
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
    );
  }
}
