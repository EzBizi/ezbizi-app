import '../../widgets/list_search.dart';
import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/staff_session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';

/// Development › Phone requests — §4 gate 2, from EzBizi's side.
///
/// ── What this screen is for ──────────────────────────────────────
///
/// Phone is a paid extra, and on the provisioned path the Twilio usage bills
/// EzBizi rather than the customer. So a person reads every request before a
/// company can spend that balance. This is that person's screen.
///
/// Approval does not create anything on Twilio. It opens the door: the customer
/// then activates each company when they are ready, from their own Settings.
/// Which companies actually want a line is their business and may be months
/// apart, and a subaccount costs nothing to create — the money is numbers,
/// usage and A2P brand fees, all of which come later and show on the bill.
///
/// The decision is per company, not per request. A request naming three
/// companies is often answered "yes to that one, not yet to the others", and
/// one status on the whole thing would force a decline — which starts a week's
/// cooling-off, so the customer would wait a week to be told what could have
/// been said now.
class PhoneRequestsScreen extends StatefulWidget {
  const PhoneRequestsScreen({super.key, required this.staff});

  final StaffSession staff;

  @override
  State<PhoneRequestsScreen> createState() => _PhoneRequestsScreenState();
}

class _PhoneRequestsScreenState extends State<PhoneRequestsScreen> {
  bool _loading = true;
  int _request = 0;
  String? _error;
  List<Map<String, dynamic>> _rows = [];

  /// The request being answered, if any. One at a time — deciding is a reading
  /// job, not something to have three of open.
  String? _openId;
  final Set<String> _approve = <String>{};
  final _note = TextEditingController();
  bool _saving = false;
  String? _formError;

  final _search = TextEditingController();
  String _query = '';
  Timer? _debounce;

  /// The requests whose visible text contains the search, case-insensitively.
  ///
  /// Until StaffPhoneRequestsController@index reads q, the loaded rows are
  /// filtered here too.
  List<Map<String, dynamic>> get _shown {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return _rows;
    return _rows.where((row) {
      final companies = (row['companies'] as List? ?? const [])
          .whereType<Map<String, dynamic>>();
      final text = [
        row['organization']?.toString(),
        row['purpose']?.toString(),
        row['requested_by']?.toString(),
        row['expected_volume_label']?.toString(),
        row['decision_note']?.toString(),
        switch (row['status']) {
          'pending' => 'Waiting',
          'approved' => 'Approved',
          'partial' => 'Partly approved',
          _ => 'Declined',
        },
        for (final c in companies) c['name']?.toString(),
      ].whereType<String>().join(' ').toLowerCase();
      return text.contains(q);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      // Not while searching: a spinner would tear the search field down
      // mid-typing when a search comes back empty.
      _loading = _rows.isEmpty && _query.trim().isEmpty;
      _error = null;
    });

    final response = await widget.staff.api.get('/staff/phone-requests', {
      if (_query.trim().isNotEmpty) 'q': _query.trim(),
    });

    // Superseded by a newer load — applying this would undo it.
    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      final data = response.body['data'];
      _rows = data is List
          ? data.whereType<Map<String, dynamic>>().toList()
          : <Map<String, dynamic>>[];
    });
  }

  void _open(Map<String, dynamic> row) {
    setState(() {
      _openId = row['id'] as String?;
      _formError = null;
      _note.clear();

      // Everything ticked to start with: the common answer is yes, and the
      // work of the screen is noticing the exception.
      _approve
        ..clear()
        ..addAll(
          (row['companies'] as List? ?? const [])
              .whereType<Map<String, dynamic>>()
              .map((c) => c['company_id'].toString()),
        );
    });
  }

  Future<void> _decide(Map<String, dynamic> row) async {
    if (_saving) return;

    final all = (row['companies'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map((c) => c['company_id'].toString())
        .toList();

    final everyone = _approve.length == all.length && all.isNotEmpty;

    if (!everyone && _note.text.trim().isEmpty) {
      setState(
        () => _formError = 'Say why, in a sentence. The customer reads it.',
      );
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.staff.api.post(
      '/staff/phone-requests/${row['id']}/decide',
      {
        'approved_company_ids': _approve.toList()..sort(),
        'note': _note.text.trim().isEmpty ? null : _note.text.trim(),
      },
    );

    if (!mounted) return;

    setState(() => _saving = false);

    if (!response.ok) {
      setState(() => _formError = response.message);
      return;
    }

    setState(() {
      _openId = null;
      _approve.clear();
      _note.clear();
    });

    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text('Phone requests', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          Text(
            'Phone service is billed to EzBizi, so every request is read before '
            'a company can spend the balance. Approving opens the door — the '
            'customer activates each company themselves.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerLeft,
            child: SizedBox(
              width: 240,
              child: ListSearch(
                key: const Key('phone-requests-search'),
                controller: _search,
                hint: 'Find a request',
                onChanged: (v) {
                    setState(() => _query = v);
                    _debounce?.cancel();
                    _debounce = Timer(const Duration(milliseconds: 400), _load);
                  },
              ),
            ),
          ),
          const SizedBox(height: 16),

          if (_shown.isEmpty && _query.trim().isNotEmpty)
            Text(
              'No requests match "${_query.trim()}".',
              style: theme.textTheme.bodyMedium,
            )
          else if (_rows.isEmpty)
            Text('Nobody has asked yet.', style: theme.textTheme.bodyMedium)
          else
            for (final row in _shown) _card(row, theme),
        ],
      ),
    );
  }

  Widget _card(Map<String, dynamic> row, ThemeData theme) {
    final status = row['status'] as String? ?? '';
    final pending = status == 'pending';
    final open = _openId == row['id'];
    final companies = (row['companies'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    row['organization']?.toString() ?? 'Unknown',
                    style: theme.textTheme.titleSmall,
                  ),
                ),
                Text(
                  switch (status) {
                    'pending' => 'Waiting',
                    'approved' => 'Approved',
                    'partial' => 'Partly approved',
                    _ => 'Declined',
                  },
                  style: theme.textTheme.labelLarge,
                ),
              ],
            ),
            const SizedBox(height: 8),

            Text(
              '${row['expected_volume_label'] ?? ''} · '
              '${row['wants_sms'] == true ? 'calls and texting' : 'calls only'}'
              '${row['requested_by'] == null ? '' : ' · asked by ${row['requested_by']}'}',
              style: theme.textTheme.bodySmall,
            ),

            const SizedBox(height: 12),
            Text(row['purpose']?.toString() ?? '', style: theme.textTheme.bodyMedium),

            const SizedBox(height: 12),

            if (!open) ...[
              for (final company in companies)
                Padding(
                  padding: const EdgeInsets.only(bottom: 2),
                  child: Text(
                    '${company['name']} — ${switch (company['approved']) {
                      true => 'approved',
                      false => 'declined',
                      _ => 'waiting',
                    }}',
                    style: theme.textTheme.bodyMedium,
                  ),
                ),

              if ((row['decision_note'] as String?)?.isNotEmpty ?? false) ...[
                const SizedBox(height: 8),
                Text(
                  row['decision_note'] as String,
                  style: theme.textTheme.bodySmall,
                ),
              ],

              if (pending) ...[
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: _saving ? null : () => _open(row),
                  child: const Text('Answer this'),
                ),
              ],
            ] else ...[
              Text('Approve which companies', style: theme.textTheme.labelLarge),
              for (final company in companies)
                CheckboxListTile(
                  value: _approve.contains(company['company_id'].toString()),
                  onChanged: _saving
                      ? null
                      : (on) => setState(() {
                          final id = company['company_id'].toString();
                          if (on == true) {
                            _approve.add(id);
                          } else {
                            _approve.remove(id);
                          }
                        }),
                  title: Text(company['name']?.toString() ?? 'A company'),
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                ),

              const SizedBox(height: 8),
              TextField(
                controller: _note,
                decoration: const InputDecoration(
                  labelText: 'What to tell them',
                  helperText:
                      'Shown to the customer word for word. Required unless '
                      'you are approving every company.',
                  helperMaxLines: 3,
                ),
                minLines: 2,
                maxLines: 5,
                enabled: !_saving,
                onChanged: (_) => setState(() {}),
              ),

              if (_formError != null) ...[
                const SizedBox(height: 12),
                ErrorNote(_formError!),
              ],

              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  FilledButton(
                    onPressed: _saving ? null : () => _decide(row),
                    child: Text(
                      _approve.isEmpty
                          ? 'Decline'
                          : _approve.length == companies.length
                          ? 'Approve'
                          : 'Approve ${_approve.length} of ${companies.length}',
                    ),
                  ),
                  TextButton(
                    onPressed: _saving
                        ? null
                        : () => setState(() {
                            _openId = null;
                            _formError = null;
                          }),
                    child: const Text('Cancel'),
                  ),
                ],
              ),

              // A refusal starts a week's cooling-off before they may ask
              // again. Said here, where the decision is made, rather than
              // discovered by the customer.
              if (_approve.length != companies.length)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'Anything refused here cannot be asked for again for a week.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
