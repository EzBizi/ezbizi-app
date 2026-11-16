import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../widgets/list_search.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'financial_cells.dart';

String _money(int cents) =>
    formatCell(const {'type': 'currency', 'options': {}}, cents / 100);

/// Financials → Bills: a place's bills for a period, read from a photo and
/// saved once the lines add up to the photo's total, to the cent.
class FinancialBillsTab extends StatefulWidget {
  const FinancialBillsTab({super.key, required this.api, required this.me});

  final ApiClient api;
  final Me me;

  @override
  State<FinancialBillsTab> createState() => _FinancialBillsTabState();
}

class _FinancialBillsTabState extends State<FinancialBillsTab> {
  List<Map>? _bills;
  String? _error;
  bool _reading = false;

  /// The search box, sent as `q`, debounced.
  final _search = TextEditingController();
  Timer? _debounce;
  String _q = '';

  bool get _canEdit => widget.me.can('financials.edit');

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  void _searched(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted) return;
      setState(() => _q = text.trim());
      _load();
    });
  }

  /// FinancialBillsController@index does not read `q` yet (it returns the
  /// newest 500), so the loaded bills are also narrowed here, by place,
  /// period and line names. Once the server honours `q` this is a no-op.
  List<Map> _matching(List<Map> bills) {
    final q = _q.toLowerCase();
    if (q.isEmpty) return bills;
    return [
      for (final b in bills)
        if ([
          b['place'] ?? 'Whole organization',
          b['period_from'],
          b['period_to'],
          b['source'],
          for (final l in (b['lines'] as List? ?? const []))
            if (l is Map) l['name'],
        ].whereType<Object>().join(' ').toLowerCase().contains(q))
          b,
    ];
  }

  Future<void> _load() async {
    final r = await widget.api.get(
      '/financials/bills',
      _q.isEmpty ? null : {'q': _q},
    );
    if (!mounted) return;
    setState(() {
      _bills = r.ok ? (r.body['data'] as List).cast<Map>() : null;
      _error = r.ok ? null : r.message;
    });
  }

  Future<void> _read() async {
    final file = await Files.pickBytes(accept: 'image/*');
    if (file == null || !mounted) return;
    setState(() => _reading = true);
    final r = await widget.api.postFile(
      '/financials/bills/read',
      'image',
      file.name,
      file.bytes,
    );
    if (!mounted) return;
    setState(() => _reading = false);
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.message)));
      return;
    }
    _open(draft: r.body['data'] as Map);
  }

  Future<void> _open({Map? draft}) async {
    final saved = await showSidePanel<bool>(
      context,
      builder: (_) =>
          _BillPanel(api: widget.api, me: widget.me, draft: draft ?? const {}),
    );
    if (saved == true) await _load();
  }

  Future<void> _show(Map bill) => showSidePanel<void>(
    context,
    builder: (_) => _BillView(
      api: widget.api,
      bill: bill,
      canDelete: widget.me.can('financials.delete'),
      onDeleted: _load,
    ),
  );

  @override
  Widget build(BuildContext context) {
    final bills = _matching(_bills ?? const <Map>[]);
    return ListView(
      key: const ValueKey('tour-financials-bills'),
      padding: const EdgeInsets.all(16),
      children: [
        // Below 600 the title gets its own line and the controls wrap under
        // it: in one row they squeezed it to "Bill / s" (phone review
        // 2026-11-13, item 5).
        if (MediaQuery.sizeOf(context).width < 600) ...[
          Text('Bills', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ListSearch(
                key: const Key('financials-bills-search'),
                controller: _search,
                onChanged: _searched,
                hint: 'Place, period or line',
              ),
              if (_canEdit) ...[
                OutlinedButton(
                  onPressed: () => _open(),
                  child: const Text('Type one in'),
                ),
                FilledButton.icon(
                  icon: _reading
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.photo_camera_outlined),
                  label: const Text('Read a photo'),
                  onPressed: _reading ? null : _read,
                ),
              ],
            ],
          ),
        ] else
          Row(
            children: [
              Expanded(
                child: Text(
                  'Bills',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              Flexible(
                child: ListSearch(
                  key: const Key('financials-bills-search'),
                  controller: _search,
                  onChanged: _searched,
                  hint: 'Place, period or line',
                ),
              ),
              if (_canEdit) ...[
                const SizedBox(width: 8),
                OutlinedButton(
                  onPressed: () => _open(),
                  child: const Text('Type one in'),
                ),
                const SizedBox(width: 8),
                FilledButton.icon(
                  icon: _reading
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.photo_camera_outlined),
                  label: const Text('Read a photo'),
                  onPressed: _reading ? null : _read,
                ),
              ],
            ],
          ),
        const SizedBox(height: 4),
        const Text(
          "A photo of a period's bills is read into lines; it is saved once they add up to its total.",
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Text(_error!),
          ),
        if (_bills != null && bills.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 24),
            child: Text(
              _q.isEmpty ? 'No bills yet.' : 'No bill matches "$_q".',
            ),
          ),
        for (final b in bills)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: Icon(
              b['has_image'] == true
                  ? Icons.image_outlined
                  : Icons.receipt_long_outlined,
            ),
            title: Text(
              '${b['place'] ?? 'Whole organization'} · ${b['period_from']} to ${b['period_to']}',
            ),
            subtitle: Text(
              '${(b['lines'] as List).length} lines${b['source'] == 'whatsapp' ? ' · from WhatsApp' : ''}',
            ),
            trailing: Text(_money(b['total_cents'] as int)),
            onTap: () => _show(b),
          ),
      ],
    );
  }
}

class _BillPanel extends StatefulWidget {
  const _BillPanel({required this.api, required this.me, required this.draft});

  final ApiClient api;
  final Me me;
  final Map draft;

  @override
  State<_BillPanel> createState() => _BillPanelState();
}

class _BillPanelState extends State<_BillPanel> {
  late final List<(TextEditingController, TextEditingController)> _lines = [
    for (final l in (widget.draft['lines'] as List? ?? const []))
      (
        TextEditingController(text: '${l['name']}'),
        TextEditingController(
          text: ((l['cents'] as int) / 100).toStringAsFixed(2),
        ),
      ),
    if ((widget.draft['lines'] as List? ?? const []).isEmpty)
      (TextEditingController(), TextEditingController()),
  ];
  late final _total = TextEditingController(
    text: widget.draft['total_cents'] == null
        ? ''
        : ((widget.draft['total_cents'] as int) / 100).toStringAsFixed(2),
  );
  late final _from = TextEditingController(
    text: widget.draft['period_from'] as String?,
  );
  late final _to = TextEditingController(
    text: widget.draft['period_to'] as String?,
  );
  late String? _place =
      widget.me.filingFranchiseId ?? widget.me.filingCompanyId;
  String? _error;
  bool _busy = false;

  int? _cents(String t) {
    final v = double.tryParse(
      t.replaceAll(r'$', '').replaceAll(',', '').trim(),
    );
    return v == null ? null : (v * 100).round();
  }

  int get _sum => _lines.fold(0, (s, l) => s + (_cents(l.$2.text) ?? 0));

  Future<void> _save() async {
    final total = _cents(_total.text);
    if (total == null) {
      return setState(() => _error = 'Type the total printed on the photo.');
    }
    final franchise = widget.me.franchisesInView
        .where((f) => f.id == _place)
        .firstOrNull;
    setState(() => _busy = true);
    final r = await widget.api.post('/financials/bills', {
      'franchise_id': franchise?.id,
      'company_id': franchise?.companyId ?? (franchise == null ? _place : null),
      'period_from': _from.text.trim(),
      'period_to': _to.text.trim(),
      'total_cents': total,
      'image_token': widget.draft['image_token'],
      'lines': [
        for (final l in _lines)
          if (l.$1.text.trim().isNotEmpty)
            {'name': l.$1.text.trim(), 'cents': _cents(l.$2.text) ?? 0},
      ],
    });
    if (!mounted) return;
    setState(() => _busy = false);
    r.ok ? Navigator.of(context).pop(true) : setState(() => _error = r.message);
  }

  @override
  Widget build(BuildContext context) {
    final total = _cents(_total.text);
    final addsUp = total != null && total == _sum;
    final theme = Theme.of(context);
    return SidePanel(
      title: widget.draft.isEmpty ? 'New bill' : 'Check the bill',
      subtitle: widget.draft.isEmpty
          ? null
          : 'Read from the photo. Fix anything misread before saving.',
      actions: [
        FilledButton(
          onPressed: _busy ? null : _save,
          child: const Text('Save'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error != null)
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          SearchPickerField<String>(
            label: 'Place',
            hint: 'Whole organization',
            allowClear: true,
            value: _place,
            options: [
              for (final c in widget.me.companiesInView)
                SearchOption(value: c.id, label: c.name),
              for (final b in branchOptionsInView(widget.me))
                SearchOption(value: b.id, label: b.label),
            ],
            onChanged: (v) => setState(() => _place = v),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _from,
                  decoration: const InputDecoration(
                    labelText: 'From (YYYY-MM-DD)',
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  controller: _to,
                  decoration: const InputDecoration(
                    labelText: 'To (YYYY-MM-DD)',
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          for (var i = 0; i < _lines.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _lines[i].$1,
                      decoration: const InputDecoration(
                        isDense: true,
                        hintText: 'Bill',
                      ),
                    ),
                  ),
                  const SizedBox(width: 6),
                  SizedBox(
                    width: 110,
                    child: TextField(
                      controller: _lines[i].$2,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: const InputDecoration(
                        isDense: true,
                        prefixText: r'$ ',
                      ),
                      onChanged: (_) => setState(() {}),
                    ),
                  ),
                  IconButton(
                    tooltip: 'Remove line',
                    icon: const Icon(Icons.close),
                    onPressed: () => setState(() => _lines.removeAt(i)),
                  ),
                ],
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              icon: const Icon(Icons.add),
              label: const Text('Add line'),
              onPressed: () => setState(
                () => _lines.add((
                  TextEditingController(),
                  TextEditingController(),
                )),
              ),
            ),
          ),
          TextField(
            controller: _total,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
              labelText: 'Total on the photo',
              prefixText: r'$ ',
            ),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          Text(
            addsUp
                ? 'The lines add up to the total.'
                : 'Lines add up to ${_money(_sum)}; they must equal the total.',
            style: TextStyle(
              color: addsUp
                  ? theme.colorScheme.primary
                  : theme.colorScheme.error,
            ),
          ),
        ],
      ),
    );
  }
}

class _BillView extends StatefulWidget {
  const _BillView({
    required this.api,
    required this.bill,
    required this.canDelete,
    required this.onDeleted,
  });

  final ApiClient api;
  final Map bill;
  final bool canDelete;
  final VoidCallback onDeleted;

  @override
  State<_BillView> createState() => _BillViewState();
}

class _BillViewState extends State<_BillView> {
  List<int>? _image;
  bool _confirm = false;

  @override
  void initState() {
    super.initState();
    if (widget.bill['has_image'] == true) {
      widget.api.getBytes('/financials/bills/${widget.bill['id']}/image').then((
        b,
      ) {
        if (mounted) setState(() => _image = b);
      });
    }
  }

  Future<void> _delete() async {
    final r = await widget.api.delete('/financials/bills/${widget.bill['id']}');
    if (!mounted) return;
    if (r.ok) {
      widget.onDeleted();
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = widget.bill;
    return SidePanel(
      title: '${b['place'] ?? 'Whole organization'}',
      subtitle: '${b['period_from']} to ${b['period_to']}',
      actions: [
        if (widget.canDelete)
          TextButton(
            onPressed: () =>
                _confirm ? _delete() : setState(() => _confirm = true),
            child: Text(_confirm ? 'Press again to delete' : 'Delete'),
          ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final l in (b['lines'] as List))
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Row(
                children: [
                  Expanded(child: Text('${l['name']}')),
                  Text(_money(l['cents'] as int)),
                ],
              ),
            ),
          const Divider(),
          Row(
            children: [
              const Expanded(child: Text('Total')),
              Text(_money(b['total_cents'] as int)),
            ],
          ),
          if (_image != null)
            Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Image.memory(Uint8List.fromList(_image!)),
            ),
        ],
      ),
    );
  }
}
