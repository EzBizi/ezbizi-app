import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/money.dart';
import '../../core/theme.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/tag.dart';

/// Sets the job's technician (performed_by_user_id) from the users who reach
/// its franchise (Jose, 2026-11-12: "there is currently no way to set a
/// technician"). True when it changed.
Future<bool> pickJobTechnician(
  BuildContext context,
  ApiClient api,
  String jobId,
  String? currentId,
) async {
  final messenger = ScaffoldMessenger.of(context);
  final list = await api.get('/jobs/$jobId/performers');
  if (!context.mounted) return false;
  if (!list.ok) {
    messenger.showSnackBar(SnackBar(content: Text(list.reason)));
    return false;
  }
  final picked = await showSearchPicker<String>(
    context,
    title: 'Technician',
    value: currentId ?? '',
    options: [
      const SearchOption(value: '', label: 'Nobody'),
      for (final u in (list.data as List?) ?? const [])
        if (u is Map) SearchOption(value: '${u['id']}', label: '${u['name']}'),
    ],
  );
  if (picked == null || picked == (currentId ?? '')) return false;
  final r = await api.patch('/jobs/$jobId', {
    'performed_by_user_id': picked.isEmpty ? null : picked,
  });
  messenger.showSnackBar(
    SnackBar(content: Text(r.ok ? 'Technician saved.' : r.reason)),
  );
  return r.ok;
}

/// The work on a job: who it is assigned to, who did it, the service lines,
/// and the total — which is summed from those lines, never typed (§12.3).
///
/// This is the fix the module exists for. The old panel had a `Price` field a
/// person filled in once and the server stored verbatim; it could say anything,
/// and nothing kept it in sync with what the job actually turned out to
/// involve. There is no such field here — the total below is `job['amount']`
/// exactly as the server computed it from `lines`, and this widget never sends
/// its own number for that key.
///
/// Adding a service is an inline expanding section, not a dialog — this app
/// does not have those (see [SidePanel]'s doc comment for why).
class JobLinesTab extends StatefulWidget {
  const JobLinesTab({
    super.key,
    required this.api,
    required this.jobId,
    required this.job,
    required this.editable,
    required this.refusalReason,
    required this.onChanged,
    required this.money,
  });

  final ApiClient api;
  final String jobId;

  /// The job as last loaded — read for `lines`, `amount`, `assigned_role`,
  /// `performed_by`. Owned by the caller, not this widget: every mutation here
  /// re-fetches the job and hands the fresh copy back through [onChanged]
  /// rather than keeping a second copy of it.
  final Map<String, dynamic> job;

  /// Whether the reader holds the Financials toggle (`jobs.financials`).
  /// Without it the server sends no `lines` and no `amount`, and this section
  /// shows the role and the performer only.
  final bool money;

  /// Whether a line may be added, changed or removed. Same gate the rest of
  /// the panel edits behind — adding a line is editing the job as far as the
  /// server is concerned (`JobLinesController::store` authorizes `update`).
  final bool editable;

  /// Why not, when [editable] is false because of a missing permission rather
  /// than because the panel is busy or still loading. Shown on the "Add a
  /// service" control the same way [Refused] shows it everywhere else.
  final String? refusalReason;

  /// Called with the freshly reloaded job after any successful add, quantity
  /// change or removal, so the caller can update the rest of the panel (and,
  /// through it, the list underneath).
  final ValueChanged<Map<String, dynamic>> onChanged;

  @override
  State<JobLinesTab> createState() => _JobLinesTabState();
}

class _JobLinesTabState extends State<JobLinesTab> {
  bool _adding = false;
  bool _loadingServices = false;
  bool _busy = false;
  String? _error;
  List<Map<String, dynamic>> _services = [];
  String? _serviceId;
  final _qty = TextEditingController(text: '1');

  /// Lines currently mid-request, keyed by line id — so one line's stepper
  /// disables while a request for THAT line is in flight without freezing the
  /// rest of the list.
  final Set<String> _lineBusy = {};

  @override
  void dispose() {
    _qty.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> get _lines =>
      List<Map<String, dynamic>>.from(widget.job['lines'] as List? ?? const []);

  Map<String, dynamic>? get _role =>
      widget.job['assigned_role'] as Map<String, dynamic>?;
  Map<String, dynamic>? get _performedBy =>
      widget.job['performed_by'] as Map<String, dynamic>?;
  Map<String, dynamic>? get _amount =>
      widget.job['amount'] as Map<String, dynamic>?;
  String get _currency => _amount?['currency'] as String? ?? 'USD';
  int get _amountMinor => (_amount?['minor'] as num?)?.toInt() ?? 0;

  Future<void> _startAdding() async {
    setState(() {
      _adding = true;
      _error = null;
    });
    if (_services.isEmpty) await _loadServices();
  }

  void _cancelAdding() {
    setState(() {
      _adding = false;
      _error = null;
      _serviceId = null;
      _qty.text = '1';
    });
  }

  /// The catalogue to pick from. Fetched once, on first expand — a job panel
  /// somebody never adds a line to should not cost a request it does not need.
  Future<void> _loadServices() async {
    setState(() => _loadingServices = true);

    final response = await widget.api.get('/services', {'per_page': '200'});

    if (!mounted) return;

    setState(() {
      _loadingServices = false;
      if (response.ok) {
        _services = List<Map<String, dynamic>>.from(response.data as List);
      } else {
        // The server's own words — a 403 here means the role can edit jobs but
        // not see the service catalogue, and that is a sentence only the
        // server can write correctly.
        _error = response.message;
      }
    });
  }

  Future<void> _confirmAdd() async {
    final serviceId = _serviceId;
    if (serviceId == null) return;

    final qty = int.tryParse(_qty.text.trim());
    if (qty == null || qty < 1) {
      setState(() => _error = 'Quantity is a whole number of at least 1.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/jobs/${widget.jobId}/lines', {
      'service_id': serviceId,
      'qty': qty,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _busy = false;
        _error = response.reason;
      });
      return;
    }

    setState(() {
      _busy = false;
      _adding = false;
      _serviceId = null;
      _qty.text = '1';
    });
    await _refresh();
  }

  Future<void> _changeQty(Map<String, dynamic> line, int qty) async {
    if (qty < 1) return;

    final id = line['id'] as String;
    setState(() {
      _lineBusy.add(id);
      _error = null;
    });

    final response = await widget.api.patch('/jobs/${widget.jobId}/lines/$id', {
      'qty': qty,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _lineBusy.remove(id);
        _error = response.reason;
      });
      return;
    }

    await _refresh();
    if (mounted) setState(() => _lineBusy.remove(id));
  }

  Future<void> _remove(Map<String, dynamic> line) async {
    final id = line['id'] as String;
    setState(() {
      _lineBusy.add(id);
      _error = null;
    });

    final response = await widget.api.delete('/jobs/${widget.jobId}/lines/$id');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _lineBusy.remove(id);
        _error = response.reason;
      });
      return;
    }

    await _refresh();
    if (mounted) setState(() => _lineBusy.remove(id));
  }

  /// Every mutation above changes the total, and the total is computed by the
  /// server — so the only honest way to show it afterwards is to ask again,
  /// the same move `SalePane._fulfill` makes for the same reason.
  Future<void> _refresh() async {
    final response = await widget.api.get('/jobs/${widget.jobId}');

    if (!mounted) return;

    if (response.ok) {
      widget.onChanged(Map<String, dynamic>.from(response.data as Map));
    } else {
      setState(() => _error = response.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final lines = _lines;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Work', style: theme.textTheme.titleMedium),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [Tag(_role?['name'] as String? ?? 'Unassigned')],
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            Flexible(
              child: Text(
                'Performed by ${_performedBy?['name'] as String? ?? 'nobody yet'}',
                style: theme.textTheme.bodyMedium,
              ),
            ),
            if (widget.editable)
              TextButton(
                key: const Key('job-technician-set'),
                onPressed: () async {
                  if (await pickJobTechnician(
                    context,
                    widget.api,
                    widget.jobId,
                    _performedBy?['id'] as String?,
                  )) {
                    await _refresh();
                  }
                },
                child: Text(_performedBy == null ? 'Assign…' : 'Change'),
              ),
          ],
        ),
        // The lines, the total and adding one ARE the job's money: Financials
        // only (Jose, 2026-09-29). The role and the performer above are not.
        if (widget.money) ...[
          const SizedBox(height: 16),
          for (final line in lines) _lineRow(context, line),
          if (lines.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text(
                'No service lines yet.',
                style: theme.textTheme.bodyMedium,
              ),
            ),
          const Divider(height: 24),
          Row(
            children: [
              Expanded(child: Text('Total', style: theme.textTheme.titleSmall)),
              Text(
                _amount?['display'] as String? ?? formatMinor(0, _currency),
                style: theme.textTheme.titleMedium,
              ),
            ],
          ),
          const SizedBox(height: 4),
          // Says where the number above came from, so nobody goes looking for
          // the field that used to let them type over it — except when that
          // would itself be false. A job priced before it had any lines (old
          // data from before this field was removed from Create, or a job still
          // waiting on its first line) shows a total nothing here summed. Saying
          // "Summed from 0 lines." on that number would be the same kind of lie
          // this module exists to remove, just moved into the caption instead of
          // the field.
          Text(
            lines.isEmpty && _amountMinor != 0
                ? 'Set before this job had any service lines.'
                : 'Summed from ${lines.length} line${lines.length == 1 ? '' : 's'}.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          if (!_adding)
            Align(
              alignment: Alignment.centerLeft,
              child: Refused(
                reason: widget.refusalReason,
                child: TextButton.icon(
                  key: const Key('job-add-line'),
                  onPressed: widget.editable ? _startAdding : null,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add a service'),
                ),
              ),
            )
          else
            _addForm(context),
          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
        ],
      ],
    );
  }

  Widget _lineRow(BuildContext context, Map<String, dynamic> line) {
    final theme = Theme.of(context);
    final id = line['id'] as String;
    final qty = (line['qty'] as num?)?.toInt() ?? 1;
    final unitMinor = (line['unit_price_minor'] as num?)?.toInt() ?? 0;
    final amountMinor = (line['amount_minor'] as num?)?.toInt() ?? 0;
    final busy = _lineBusy.contains(id);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  line['name'] as String? ?? 'Service',
                  style: theme.textTheme.bodyLarge,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 8),
              Text(
                formatMinor(amountMinor, _currency),
                style: theme.textTheme.bodyMedium,
              ),
            ],
          ),
          const SizedBox(height: 4),
          // Wrap, not Row+Spacer: at 375px the price and the stepper cluster
          // together are wider than the panel, and a Row would overflow. A
          // Wrap drops the cluster to its own line instead — the same move
          // SidePanel's own action row makes for the same reason.
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text(
                '${formatMinor(unitMinor, _currency)} each',
                style: theme.textTheme.bodySmall,
              ),
              if (widget.editable)
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      key: Key('job-line-$id-dec'),
                      icon: const Icon(Icons.remove_circle_outline, size: 20),
                      tooltip: 'One fewer',
                      onPressed: (busy || qty <= 1)
                          ? null
                          : () => _changeQty(line, qty - 1),
                    ),
                    SizedBox(
                      width: 24,
                      child: Text(
                        '$qty',
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                    IconButton(
                      key: Key('job-line-$id-inc'),
                      icon: const Icon(Icons.add_circle_outline, size: 20),
                      tooltip: 'One more',
                      onPressed: busy ? null : () => _changeQty(line, qty + 1),
                    ),
                    IconButton(
                      key: Key('job-line-$id-remove'),
                      icon: const Icon(Icons.delete_outline, size: 20),
                      tooltip: 'Remove this line',
                      onPressed: busy ? null : () => _remove(line),
                    ),
                  ],
                )
              else
                Text('Qty $qty', style: theme.textTheme.bodySmall),
            ],
          ),
        ],
      ),
    );
  }

  Widget _addForm(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Add a service', style: theme.textTheme.titleSmall),
          const SizedBox(height: 10),
          if (_loadingServices)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: LinearProgressIndicator(),
            )
          else if (_services.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text(
                'No services in the catalogue yet.',
                style: theme.textTheme.bodySmall,
              ),
            )
          else
            SearchPickerField<String>(
              key: const Key('job-line-service-picker'),
              value: _serviceId,
              label: 'Service',
              options: [
                for (final service in _services)
                  SearchOption(
                    value: service['id'] as String?,
                    label: service['name'] as String? ?? '',
                  ),
              ],
              onChanged: _busy ? null : (v) => setState(() => _serviceId = v),
            ),
          const SizedBox(height: 10),
          TextField(
            key: const Key('job-line-qty-field'),
            controller: _qty,
            enabled: !_busy,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Qty'),
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              TextButton(
                onPressed: _busy ? null : _cancelAdding,
                child: const Text('Cancel'),
              ),
              FilledButton(
                key: const Key('job-line-add-confirm'),
                onPressed: (_busy || _serviceId == null) ? null : _confirmAdd,
                child: const Text('Add'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
