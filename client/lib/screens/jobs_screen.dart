import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/error_note.dart';
import '../widgets/tag.dart';

/// Jobs (plan §5.2).
///
/// The opposite shape to customers: the server scopes this list to the caller's
/// reach, because jobs are never shared. A company-level user sees every
/// franchise beneath them — that is the roll-up, and it comes from the server so
/// the client never has to work out who can see what.
class JobsScreen extends StatefulWidget {
  const JobsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<JobsScreen> createState() => _JobsScreenState();
}

class _JobsScreenState extends State<JobsScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _jobs = [];
  int _total = 0;

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

    final response = await widget.api.get('/jobs');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _jobs = List<Map<String, dynamic>>.from(response.data as List);
        _total = (response.body['meta'] as Map<String, dynamic>?)?['total'] as int? ?? _jobs.length;
      } else {
        _error = response.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHeader(
          title: 'Jobs',
          note: (_loading || _error != null)
              ? 'Everything you can reach.'
              : '$_total across the ${me.organization.franchiseLabel.toLowerCase()}s you can reach.',
          action: FilledButton.icon(
            onPressed: me.franchises.isEmpty ? null : _create,
            icon: const Icon(Icons.add, size: 18),
            label: const Text('Add job'),
          ),
        ),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _jobs.isEmpty
                ? const Center(child: Text('No jobs yet.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                    itemCount: _jobs.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) => _row(context, _jobs[i]),
                  ),
          ),
        ),
      ],
    );
  }

  Widget _row(BuildContext context, Map<String, dynamic> job) {
    final customer = job['customer'] as Map<String, dynamic>;
    final franchise = job['franchise'] as Map<String, dynamic>;
    final amount = job['amount'] as Map<String, dynamic>;
    final ownedHere = customer['owned_by_this_franchise'] as bool? ?? true;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
        title: Row(
          children: [
            Flexible(
              child: Text(
                job['title'] as String,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            Tag(job['status'] as String),
            // So it is obvious before anyone edits anything that this work is
            // for a customer another franchise is accountable for (§5.2).
            if (!ownedHere) const Tag('Shared customer', warn: true),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            [
              job['number'],
              customer['name'] ?? '',
              franchise['name'] ?? '',
              if (job['scheduled_for'] != null) _stamp(job['scheduled_for']),
              if (amount['display'] != null) '${amount['currency']} ${amount['display']}',
            ].join(' · '),
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
      ),
    );
  }

  String _stamp(dynamic iso) {
    final at = DateTime.tryParse(iso as String)?.toLocal();
    if (at == null) return '';

    String two(int n) => n.toString().padLeft(2, '0');

    return '${at.year}-${two(at.month)}-${two(at.day)} ${two(at.hour)}:${two(at.minute)}';
  }

  Future<void> _create() async {
    final created = await showDialog<bool>(
      context: context,
      builder: (_) => _NewJobDialog(api: widget.api, session: widget.session),
    );

    if (created == true && mounted) _load();
  }
}

class _NewJobDialog extends StatefulWidget {
  const _NewJobDialog({required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<_NewJobDialog> createState() => _NewJobDialogState();
}

class _NewJobDialogState extends State<_NewJobDialog> {
  final _title = TextEditingController();
  final _amount = TextEditingController();

  String? _franchiseId;
  String? _customerId;
  String _status = 'scheduled';

  List<Map<String, dynamic>> _customers = [];
  List<String> _statuses = const ['scheduled'];

  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();

    final reachable = widget.session.me!.franchises;
    if (reachable.length == 1) _franchiseId = reachable.first.id;

    _loadOptions();
  }

  @override
  void dispose() {
    _title.dispose();
    _amount.dispose();
    super.dispose();
  }

  /// Statuses come from the organization's settings, not from a constant here —
  /// an organization can rename them without a new build (§2.1).
  Future<void> _loadOptions() async {
    final customers = await widget.api.get('/customers', {'per_page': '200'});
    final settings = await widget.api.get('/settings');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (customers.ok) {
        _customers = List<Map<String, dynamic>>.from(customers.data as List);
      } else {
        _error = customers.message;
      }

      if (settings.ok) {
        final all = settings.data;
        final value = all is Map<String, dynamic> ? all['jobs.statuses'] : null;
        final resolved = value is Map<String, dynamic> ? value['value'] : value;

        if (resolved is List && resolved.isNotEmpty) {
          _statuses = resolved.map((s) => s.toString()).toList();
          _status = _statuses.first;
        }
      }
    });
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    final cents = int.tryParse(_amount.text.replaceAll(RegExp(r'[^0-9]'), ''));

    final response = await widget.api.post('/jobs', {
      'franchise_id': _franchiseId,
      'customer_id': _customerId,
      'title': _title.text.trim(),
      'status': _status,
      // Minor units, always — the field takes cents and this is where they
      // become an integer. Money never travels as a float (§7).
      'amount_minor': ?cents,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = _firstError(response);
      });
      return;
    }

    Navigator.of(context).pop(true);
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

    return AlertDialog(
      title: const Text('Add job'),
      content: SizedBox(
        width: 440,
        child: _loading
            ? const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              )
            : SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    DropdownButtonFormField<String>(
                      initialValue: _franchiseId,
                      decoration: InputDecoration(
                        labelText: me.organization.franchiseLabel,
                      ),
                      items: me.franchises
                          .map((f) => DropdownMenuItem(value: f.id, child: Text(f.name)))
                          .toList(),
                      onChanged: _busy ? null : (v) => setState(() => _franchiseId = v),
                    ),
                    const SizedBox(height: 14),
                    DropdownButtonFormField<String>(
                      initialValue: _customerId,
                      decoration: const InputDecoration(
                        labelText: 'Customer',
                        helperText: 'Any customer in the organization — doing work for '
                            'another franchise\'s customer does not move them.',
                        helperMaxLines: 3,
                      ),
                      items: _customers
                          .map((c) => DropdownMenuItem(
                                value: c['id'] as String,
                                child: Text('${c['name']} · '
                                    '${(c['owning_franchise'] as Map)['name'] ?? ''}'),
                              ))
                          .toList(),
                      onChanged: _busy ? null : (v) => setState(() => _customerId = v),
                    ),
                    const SizedBox(height: 14),
                    TextField(
                      controller: _title,
                      decoration: const InputDecoration(labelText: 'What is the job'),
                      enabled: !_busy,
                    ),
                    const SizedBox(height: 14),
                    DropdownButtonFormField<String>(
                      initialValue: _status,
                      decoration: const InputDecoration(labelText: 'Status'),
                      items: _statuses
                          .map((s) => DropdownMenuItem(value: s, child: Text(s)))
                          .toList(),
                      onChanged: _busy ? null : (v) => setState(() => _status = v ?? _status),
                    ),
                    const SizedBox(height: 14),
                    TextField(
                      controller: _amount,
                      decoration: const InputDecoration(
                        labelText: 'Price in cents',
                        helperText: '19999 for \$199.99. Stored as an integer so it '
                            'cannot drift by a cent.',
                        helperMaxLines: 2,
                      ),
                      keyboardType: TextInputType.number,
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
          onPressed: _busy ? null : () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: (_busy || _loading || _franchiseId == null || _customerId == null)
              ? null
              : _submit,
          child: const Text('Create'),
        ),
      ],
    );
  }
}
