import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'invoice_common.dart';

enum _Mode { view, send, pay, voiding }

/// One invoice, docked beside the list (or in place of it on a phone).
///
/// Steps Draft → Approved → Sent → Paid, the invoice laid out like its PDF,
/// lines editable while it is a draft, and the next action as the main
/// button. Which buttons show is the server's `can_*` for this person.
/// Send, Record payment and Void change the panel rather than opening
/// anything over it.
class InvoicePanel extends StatefulWidget {
  const InvoicePanel({
    super.key,
    required this.api,
    required this.me,
    required this.invoiceId,
    required this.inPlace,
    required this.onClose,
    required this.onChanged,
  });

  final ApiClient api;
  final Me me;
  final String invoiceId;

  /// Drawn in place of the list (a phone): a ← back instead of ✕.
  final bool inPlace;
  final VoidCallback onClose;

  /// The invoice as the server now has it, after any action.
  final ValueChanged<Map> onChanged;

  @override
  State<InvoicePanel> createState() => _InvoicePanelState();
}

class _InvoicePanelState extends State<InvoicePanel> {
  Map? _invoice;
  String? _error;
  bool _busy = false;
  _Mode _mode = _Mode.view;

  /// A refusal to send because the business has no mail server of its own.
  bool _needsMailServer = false;

  // Lines, while a draft.
  List<(TextEditingController, TextEditingController)> _lines = [];
  bool _linesDirty = false;

  // Send.
  Map? _preview;
  final _ticked = <String, bool>{};
  final _address = TextEditingController();

  /// Phones a text or WhatsApp goes to, ticked.
  final _tickedPhones = <String, bool>{};
  final _phone = TextEditingController();

  // Who it bills, editable until it is sent.
  final _newEmail = TextEditingController();
  final _newPhone = TextEditingController();

  // Payment.
  late final _paidOn = TextEditingController(
    text: formatCalendarDay(DateTime.now()),
  );
  final _amount = TextEditingController();
  final _reference = TextEditingController();
  String _method = 'check';
  String _kind = 'charge';

  // Void.
  final _reason = TextEditingController();

  DisplayFormats get _formats => widget.me.display;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(InvoicePanel old) {
    super.didUpdateWidget(old);
    if (old.invoiceId != widget.invoiceId) {
      setState(() {
        _invoice = null;
        _mode = _Mode.view;
      });
      _load();
    }
  }

  @override
  void dispose() {
    for (final (a, b) in _lines) {
      a.dispose();
      b.dispose();
    }
    _address.dispose();
    _phone.dispose();
    _newEmail.dispose();
    _newPhone.dispose();
    _paidOn.dispose();
    _amount.dispose();
    _reference.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/invoices/${widget.invoiceId}');
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _error = r.reason);
      return;
    }
    _adopt(r.body['data'] as Map);
  }

  void _adopt(Map invoice) {
    for (final (a, b) in _lines) {
      a.dispose();
      b.dispose();
    }
    setState(() {
      _invoice = invoice;
      _error = null;
      _needsMailServer = false;
      _linesDirty = false;
      _lines = [
        for (final l in (invoice['lines'] as List? ?? const []).cast<Map>())
          (
            TextEditingController(text: '${l['description']}'),
            TextEditingController(
              text: (((l['amount_cents'] as num?)?.toInt() ?? 0) / 100)
                  .toStringAsFixed(2),
            ),
          ),
      ];
    });
  }

  /// POST or PATCH, then take the invoice it answers.
  Future<void> _act(
    Future<ApiResponse> Function() call, {
    bool sending = false,
  }) async {
    setState(() {
      _busy = true;
      _error = null;
      _needsMailServer = false;
    });
    final r = await call();
    if (!mounted) return;
    setState(() => _busy = false);
    if (!r.ok) {
      final errors = r.body['errors'];
      setState(() {
        _error = r.reason;
        // Only a refusal about mail points at the sending server.
        _needsMailServer =
            sending &&
            errors is Map &&
            errors.containsKey('send') &&
            RegExp('mail server|mailbox').hasMatch(r.reason);
      });
      return;
    }
    final data = r.body['data'];
    final invoice = data is List ? data.cast<Map>().first : data as Map;
    _adopt(invoice);
    setState(() => _mode = _Mode.view);
    widget.onChanged(invoice);
  }

  String get _id => widget.invoiceId;

  Future<void> _approve() =>
      _act(() => widget.api.post('/invoices/$_id/approve', {}));

  Future<void> _saveLines() async {
    final lines = <Map<String, dynamic>>[];
    for (final (d, a) in _lines) {
      final cents = parseMoneyCents(a.text);
      if (cents == null) {
        setState(() => _error = '"${a.text}" is not an amount.');
        return;
      }
      if (d.text.trim().isEmpty) {
        setState(() => _error = 'Every line needs a description.');
        return;
      }
      lines.add({'description': d.text.trim(), 'cents': cents});
    }
    if (lines.isEmpty) {
      setState(() => _error = 'An invoice needs at least one line.');
      return;
    }
    await _act(() => widget.api.patch('/invoices/$_id', {'lines': lines}));
  }

  Future<void> _openSend() async {
    setState(() {
      _mode = _Mode.send;
      _preview = null;
      _error = null;
      _needsMailServer = false;
    });
    final r = await widget.api.post('/invoices/send-preview', {
      'ids': [_id],
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() => _error = r.reason);
      return;
    }
    final preview = r.body['data'] as Map;
    final channels = _channelsOf(preview);
    final billTo = (_invoice?['bill_to'] as Map?) ?? const {};
    setState(() {
      _preview = preview;
      _ticked
        ..clear()
        ..addAll({
          for (final c in channels)
            if (c['channel'] == 'email')
              for (final a in (c['to'] as List? ?? const [])) '$a': true,
          for (final a in (preview['to'] as List? ?? const [])) '$a': true,
          for (final a in (billTo['emails'] as List? ?? const [])) '$a': true,
        });
      _tickedPhones
        ..clear()
        ..addAll({
          for (final c in channels)
            if (c['channel'] != 'email')
              for (final p in (c['to'] as List? ?? const [])) '$p': true,
          for (final p in (billTo['phones'] as List? ?? const [])) '$p': true,
        });
    });
  }

  /// The channels a send would use. A server that does not say sends by
  /// email alone.
  static List<Map> _channelsOf(Map preview) {
    final listed = (preview['channels'] as List?)?.cast<Map>();
    if (listed != null && listed.isNotEmpty) return listed;
    return [
      {'channel': 'email', 'ready': true, 'to': preview['to'] ?? const []},
    ];
  }

  bool get _sendBlocked =>
      _preview == null || _channelsOf(_preview!).any((c) => c['ready'] != true);

  void _addPhone() {
    final p = _phone.text.trim();
    if (p.isEmpty) return;
    setState(() {
      _tickedPhones[p] = true;
      _phone.clear();
    });
  }

  /// PATCHes who it bills: `bill_to_emails` or `bill_to_phones`.
  Future<void> _saveContacts(String field, List<String> values) =>
      _act(() => widget.api.patch('/invoices/$_id', {field: values}));

  void _addAddress() {
    final a = _address.text.trim();
    if (a.isEmpty) return;
    setState(() {
      _ticked[a] = true;
      _address.clear();
    });
  }

  Future<void> _send() {
    final channels = {
      for (final c in _channelsOf(_preview ?? const {})) '${c['channel']}',
    };
    final to = [
      if (channels.contains('email'))
        for (final e in _ticked.entries)
          if (e.value) e.key,
    ];
    final phones = [
      if (channels.contains('sms') || channels.contains('whatsapp'))
        for (final e in _tickedPhones.entries)
          if (e.value) e.key,
    ];
    return _act(
      () => widget.api.post('/invoices/send', {
        'ids': [_id],
        if (to.isNotEmpty) 'to': to,
        if (phones.isNotEmpty) 'phones': phones,
      }),
      sending: true,
    );
  }

  void _openPay() => setState(() {
    _mode = _Mode.pay;
    _error = null;
    final due = (_invoice?['amount_due_cents'] as num?)?.toInt() ?? 0;
    _amount.text = due > 0 ? (due / 100).toStringAsFixed(2) : '';
  });

  Future<void> _pay() async {
    final cents = parseMoneyCents(_amount.text);
    if (cents == null || cents <= 0) {
      setState(() => _error = 'Say how much was paid, above zero.');
      return;
    }
    await _act(
      () => widget.api.post('/invoices/$_id/payments', {
        'paid_on': _paidOn.text,
        // Dollars, as the API takes them.
        'amount': cents / 100,
        'method': _method,
        'kind': _kind,
        if (_reference.text.trim().isNotEmpty)
          'reference': _reference.text.trim(),
      }),
    );
  }

  Future<void> _void() async {
    if (_reason.text.trim().isEmpty) {
      setState(() => _error = 'Say why it is void.');
      return;
    }
    await _act(
      () => widget.api.post('/invoices/$_id/void', {
        'reason': _reason.text.trim(),
      }),
    );
  }

  Future<void> _pdf() async {
    final bytes = await widget.api.getBytes('/invoices/$_id/pdf');
    if (!mounted) return;
    if (bytes == null) {
      setState(() => _error = 'Could not download the PDF.');
      return;
    }
    Files.download('${_invoice?['number'] ?? 'invoice'}.pdf', bytes);
  }

  // ── Layout ───────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final invoice = _invoice;
    return SidePanel(
      key: const ValueKey('invoice-panel'),
      title: invoice == null ? 'Invoice' : '${invoice['number']}',
      subtitle: invoice == null
          ? null
          : '${(invoice['bill_to'] as Map?)?['name'] ?? ''}',
      docked: true,
      onClose: widget.onClose,
      onBack: widget.inPlace ? widget.onClose : null,
      actions: invoice == null ? const [] : _actions(invoice),
      child: invoice == null
          ? Padding(
              padding: const EdgeInsets.all(32),
              child: Center(
                child: _error == null
                    ? const CircularProgressIndicator()
                    : Text(_error!),
              ),
            )
          : switch (_mode) {
              _Mode.view => _view(context, invoice),
              _Mode.send => _sendBody(context, invoice),
              _Mode.pay => _payBody(context),
              _Mode.voiding => _voidBody(context, invoice),
            },
    );
  }

  List<Widget> _actions(Map invoice) {
    final back = TextButton(
      key: const ValueKey('invoice-cancel'),
      onPressed: _busy
          ? null
          : () => setState(() {
              _mode = _Mode.view;
              _error = null;
              _needsMailServer = false;
            }),
      child: const Text('Cancel'),
    );
    switch (_mode) {
      case _Mode.send:
        return [
          back,
          FilledButton(
            key: const ValueKey('invoice-send-confirm'),
            onPressed: _busy || _sendBlocked ? null : _send,
            child: const Text('Send'),
          ),
        ];
      case _Mode.pay:
        return [
          back,
          FilledButton(
            key: const ValueKey('invoice-pay-confirm'),
            onPressed: _busy ? null : _pay,
            child: const Text('Record payment'),
          ),
        ];
      case _Mode.voiding:
        return [
          back,
          FilledButton(
            key: const ValueKey('invoice-void-confirm'),
            onPressed: _busy ? null : _void,
            child: const Text('Void invoice'),
          ),
        ];
      case _Mode.view:
        break;
    }

    final canApprove = invoice['can_approve'] == true;
    final canSend = invoice['can_send'] == true;
    final canPay = invoice['can_record_payment'] == true;
    final due = (invoice['amount_due_cents'] as num?)?.toInt() ?? 0;
    final status = invoice['status'];

    // The next step is the main button; the rest sit under More.
    final Widget? primary = canApprove
        ? FilledButton(
            key: const ValueKey('invoice-approve'),
            onPressed: _busy ? null : _approve,
            child: const Text('Approve'),
          )
        : canSend && status == 'approved'
        ? FilledButton(
            key: const ValueKey('invoice-send'),
            onPressed: _busy ? null : _openSend,
            child: const Text('Send…'),
          )
        : canPay && due > 0
        ? FilledButton(
            key: const ValueKey('invoice-pay'),
            onPressed: _busy ? null : _openPay,
            child: const Text('Record payment'),
          )
        : null;
    final primaryKey = (primary?.key as ValueKey?)?.value;

    final more = <(String, String, VoidCallback)>[
      if (invoice['has_pdf'] == true)
        ('invoice-more-pdf', 'Download PDF', _pdf),
      if (canSend && status == 'sent' && primaryKey != 'invoice-send')
        ('invoice-more-send', 'Send again…', _openSend),
      if (canPay && primaryKey != 'invoice-pay')
        ('invoice-more-pay', 'Record payment', _openPay),
      if (invoice['can_void'] == true)
        (
          'invoice-more-void',
          'Void',
          () => setState(() {
            _mode = _Mode.voiding;
            _error = null;
          }),
        ),
    ];

    return [
      if (more.isNotEmpty)
        PopupMenuButton<int>(
          key: const ValueKey('invoice-more'),
          tooltip: 'More',
          onSelected: (i) => more[i].$3(),
          itemBuilder: (_) => [
            for (final (i, m) in more.indexed)
              PopupMenuItem(value: i, key: ValueKey(m.$1), child: Text(m.$2)),
          ],
          child: const Padding(
            padding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [Text('More'), Icon(Icons.arrow_drop_down)],
            ),
          ),
        ),
      ?primary,
    ];
  }

  Widget _errorNote(BuildContext context) {
    if (_error == null) return const SizedBox.shrink();
    final open = OpenDestination.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _error!,
            key: const ValueKey('invoice-error'),
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
          if (_needsMailServer) ...[
            const SizedBox(height: 4),
            const Text(
              'Connect it in Settings › Integrations › Sending server.',
            ),
            if (open != null)
              TextButton(
                key: const ValueKey('invoice-open-settings'),
                onPressed: () => open('settings'),
                child: const Text('Open Settings'),
              ),
          ],
        ],
      ),
    );
  }

  Widget _steps(BuildContext context, Map invoice) {
    final scheme = Theme.of(context).colorScheme;
    const order = ['draft', 'approved', 'sent', 'paid'];
    final at = order.indexOf('${invoice['status']}');
    return Wrap(
      key: const ValueKey('invoice-steps'),
      spacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (final (i, s) in order.indexed) ...[
          if (i > 0) Icon(Icons.chevron_right, size: 16, color: scheme.outline),
          Text(
            s[0].toUpperCase() + s.substring(1),
            style: TextStyle(
              fontWeight: i == at ? FontWeight.w700 : FontWeight.w400,
              color: at >= 0 && i <= at
                  ? scheme.primary
                  : scheme.onSurfaceVariant,
            ),
          ),
        ],
      ],
    );
  }

  Widget _view(BuildContext context, Map invoice) {
    final theme = Theme.of(context);
    final status = invoiceStatus(invoice);
    final payments = (invoice['payments'] as List? ?? const []).cast<Map>();
    final approvedBy = invoice['approved_by_name'] as String?;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            StatusPill(status.$1, tone: status.$2),
            _steps(context, invoice),
          ],
        ),
        const SizedBox(height: 8),
        if (invoice['approved_at'] != null)
          Text(
            'Approved${approvedBy == null ? '' : ' by $approvedBy'} · '
            '${formatStamp(invoice['approved_at'], '', formats: _formats)}',
            key: const ValueKey('invoice-approved'),
            style: theme.textTheme.bodySmall,
          ),
        if (invoice['sent_at'] != null)
          Text(
            'Sent · ${formatStamp(invoice['sent_at'], '', formats: _formats)}',
            key: const ValueKey('invoice-sent'),
            style: theme.textTheme.bodySmall,
          ),
        if (invoice['status'] == 'void')
          Text(
            'Void${invoice['void_reason'] == null ? '' : ': ${invoice['void_reason']}'}',
            key: const ValueKey('invoice-void-reason'),
            style: theme.textTheme.bodySmall,
          ),
        if (invoice['status'] == 'draft' && invoice['can_approve'] != true)
          Text(
            'Somebody whose role can approve invoices approves this one before it is sent.',
            key: const ValueKey('invoice-who-approves'),
            style: theme.textTheme.bodySmall,
          ),
        const SizedBox(height: 12),
        _document(context, invoice),
        _contacts(context, invoice),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: Text('Amount due', style: theme.textTheme.titleSmall),
            ),
            Text(
              invoiceMoney((invoice['amount_due_cents'] as num?)?.toInt() ?? 0),
              key: const ValueKey('invoice-amount-due'),
              style: theme.textTheme.titleMedium,
            ),
          ],
        ),
        if (payments.isNotEmpty) ...[
          const SizedBox(height: 12),
          Text('Payments', style: theme.textTheme.titleSmall),
          for (final p in payments)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      [
                        invoiceDay(p['paid_on'], _formats),
                        '${p['method']}',
                        if (p['kind'] == 'refund') 'refund',
                        if (p['reference'] != null) '${p['reference']}',
                        if (p['recorded_by_name'] != null)
                          'by ${p['recorded_by_name']}',
                      ].join(' · '),
                    ),
                  ),
                  Text(
                    '${p['kind'] == 'refund' ? '−' : ''}'
                    '${invoiceMoney((p['amount_cents'] as num?)?.toInt() ?? 0)}',
                  ),
                ],
              ),
            ),
        ],
        _errorNote(context),
      ],
    );
  }

  /// Laid out like the PDF: issuer, INVOICE, number and dates, Bill to, the
  /// summary, Expenses | Amount, total.
  Widget _document(BuildContext context, Map invoice) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    // Who issued it, as the PDF shows it; the organization only when the
    // server does not say.
    final issuer = invoice['issuer'] as Map?;
    final org = widget.me.organization;
    final issuerName = issuer == null ? org.name : '${issuer['name'] ?? ''}';
    final issuerAddress = issuer == null
        ? org.address
        : issuer['address'] as String?;
    final logo = issuer?['logo_url'] as String?;
    final billTo = (invoice['bill_to'] as Map?) ?? const {};
    final editing = invoice['can_edit'] == true;
    final head = theme.textTheme.labelMedium;

    return Container(
      key: const ValueKey('invoice-document'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scheme.surface,
        border: Border.all(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (logo != null)
                      _IssuerLogo(
                        key: ValueKey('invoice-issuer-logo-$logo'),
                        api: widget.api,
                        path: logo,
                      ),
                    Text(
                      issuerName,
                      key: const ValueKey('invoice-issuer-name'),
                      style: theme.textTheme.titleSmall,
                    ),
                    if (issuerAddress != null && issuerAddress.isNotEmpty)
                      Text(
                        issuerAddress,
                        key: const ValueKey('invoice-issuer-address'),
                        style: theme.textTheme.bodySmall,
                      ),
                  ],
                ),
              ),
              Text('INVOICE', style: theme.textTheme.titleMedium),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'No. ${invoice['number']} · Date ${invoiceDay(invoice['issued_on'], _formats)} · '
            'Due ${invoiceDay(invoice['due_on'], _formats)}',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 12),
          Text('Bill to', style: head),
          Text('${billTo['name'] ?? ''}'),
          if (billTo['address'] != null)
            Text('${billTo['address']}', style: theme.textTheme.bodySmall),
          if ((invoice['summary'] ?? '') != '') ...[
            const SizedBox(height: 8),
            Text('${invoice['summary']}'),
          ],
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(child: Text('EXPENSES', style: head)),
              Text('AMOUNT', style: head),
            ],
          ),
          const Divider(),
          if (editing)
            ..._editableLines(context)
          else
            for (final l in (invoice['lines'] as List? ?? const []).cast<Map>())
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  children: [
                    Expanded(child: Text('${l['description']}')),
                    Text(
                      invoiceMoney((l['amount_cents'] as num?)?.toInt() ?? 0),
                    ),
                  ],
                ),
              ),
          const Divider(),
          Row(
            children: [
              Expanded(child: Text('Total', style: theme.textTheme.titleSmall)),
              Text(
                invoiceMoney((invoice['total_cents'] as num?)?.toInt() ?? 0),
                key: const ValueKey('invoice-total'),
                style: theme.textTheme.titleSmall,
              ),
            ],
          ),
          if ((invoice['notes'] ?? '') != '') ...[
            const SizedBox(height: 8),
            Text('${invoice['notes']}', style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }

  List<Widget> _editableLines(BuildContext context) => [
    for (final (i, (d, a)) in _lines.indexed)
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          children: [
            Expanded(
              child: TextField(
                key: ValueKey('invoice-line-$i-description'),
                controller: d,
                decoration: const InputDecoration(isDense: true),
                onChanged: (_) => setState(() => _linesDirty = true),
              ),
            ),
            const SizedBox(width: 8),
            SizedBox(
              width: 110,
              child: TextField(
                key: ValueKey('invoice-line-$i-amount'),
                controller: a,
                textAlign: TextAlign.right,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                  signed: true,
                ),
                decoration: const InputDecoration(
                  isDense: true,
                  prefixText: r'$',
                ),
                onChanged: (_) => setState(() => _linesDirty = true),
              ),
            ),
            IconButton(
              tooltip: 'Remove line',
              icon: const Icon(Icons.close, size: 18),
              onPressed: () => setState(() {
                _lines.removeAt(i);
                _linesDirty = true;
              }),
            ),
          ],
        ),
      ),
    Wrap(
      spacing: 8,
      children: [
        TextButton.icon(
          key: const ValueKey('invoice-line-add'),
          onPressed: () => setState(() {
            _lines.add((TextEditingController(), TextEditingController()));
            _linesDirty = true;
          }),
          icon: const Icon(Icons.add, size: 18),
          label: const Text('Add a line'),
        ),
        if (_linesDirty)
          OutlinedButton(
            key: const ValueKey('invoice-lines-save'),
            onPressed: _busy ? null : _saveLines,
            child: const Text('Save lines'),
          ),
      ],
    ),
  ];

  Widget _sendBody(BuildContext context, Map invoice) {
    final theme = Theme.of(context);
    final preview = _preview;
    if (preview == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_error == null)
            const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            ),
          _errorNote(context),
        ],
      );
    }
    final channels = _channelsOf(preview);
    final names = {'email': 'Email', 'sms': 'Text', 'whatsapp': 'WhatsApp'};
    final email = channels.where((c) => c['channel'] == 'email').firstOrNull;
    final texting = channels.where((c) => c['channel'] != 'email').toList();

    Widget why(Map c) => c['ready'] == true
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Text(
              '${c['why_not'] ?? 'Not set up yet.'}',
              key: ValueKey('invoice-send-why-${c['channel']}'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          );

    Widget ticks(Map<String, bool> ticked, String prefix) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final e in ticked.entries)
          CheckboxListTile(
            key: ValueKey('$prefix-${e.key}'),
            contentPadding: EdgeInsets.zero,
            dense: true,
            controlAffinity: ListTileControlAffinity.leading,
            value: e.value,
            title: Text(e.key),
            onChanged: (v) => setState(() => ticked[e.key] = v ?? false),
          ),
      ],
    );

    Widget adder(
      String key,
      TextEditingController controller,
      String hint,
      VoidCallback add,
      TextInputType type,
    ) => Row(
      children: [
        Expanded(
          child: TextField(
            key: ValueKey(key),
            controller: controller,
            keyboardType: type,
            decoration: InputDecoration(isDense: true, hintText: hint),
            onSubmitted: (_) => add(),
          ),
        ),
        TextButton(onPressed: add, child: const Text('Add')),
      ],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (email != null) ...[
          Text(
            'Email to',
            key: const ValueKey('invoice-send-group-email'),
            style: theme.textTheme.titleSmall,
          ),
          why(email),
          Text(
            preview['mode'] == 'summary'
                ? 'One summary email, with every PDF attached'
                : 'One email, with its PDF attached',
            style: theme.textTheme.bodySmall,
          ),
          ticks(_ticked, 'invoice-to'),
          adder(
            'invoice-to-add',
            _address,
            'Add an address',
            _addAddress,
            TextInputType.emailAddress,
          ),
          const SizedBox(height: 12),
          Text('Subject', style: theme.textTheme.titleSmall),
          Text(
            '${preview['subject'] ?? ''}',
            key: const ValueKey('invoice-send-subject'),
          ),
          const SizedBox(height: 12),
          Text('The email', style: theme.textTheme.titleSmall),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              border: Border.all(color: theme.colorScheme.outlineVariant),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              _plainText('${preview['html'] ?? ''}'),
              key: const ValueKey('invoice-send-body'),
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
        if (texting.isNotEmpty) ...[
          if (email != null) const SizedBox(height: 16),
          // Texts and WhatsApp go to the same phones.
          Text(
            '${texting.map((c) => names[c['channel']] ?? '${c['channel']}').join(' and ')} to',
            key: const ValueKey('invoice-send-group-phones'),
            style: theme.textTheme.titleSmall,
          ),
          for (final c in texting) why(c),
          ticks(_tickedPhones, 'invoice-phone-to'),
          adder(
            'invoice-phone-to-add',
            _phone,
            'Add a phone',
            _addPhone,
            TextInputType.phone,
          ),
          if (texting.any((c) => c['channel'] == 'sms') &&
              preview['text'] != null) ...[
            const SizedBox(height: 12),
            Text('The text', style: theme.textTheme.titleSmall),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                border: Border.all(color: theme.colorScheme.outlineVariant),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                '${preview['text']}',
                key: const ValueKey('invoice-send-text'),
                style: theme.textTheme.bodySmall,
              ),
            ),
          ],
        ],
        _errorNote(context),
      ],
    );
  }

  /// Who it bills by email and phone, editable until it is sent.
  Widget _contacts(BuildContext context, Map invoice) {
    final theme = Theme.of(context);
    final billTo = (invoice['bill_to'] as Map?) ?? const {};
    final emails = [
      for (final e in (billTo['emails'] as List? ?? const [])) '$e',
    ];
    final phones = [
      for (final p in (billTo['phones'] as List? ?? const [])) '$p',
    ];
    final editable =
        ['draft', 'approved'].contains(invoice['status']) &&
        widget.me.can('invoices.edit');

    Widget row(
      String label,
      String field,
      List<String> values,
      String prefix,
      TextEditingController controller,
      String hint,
      TextInputType type,
    ) {
      void add() {
        final v = controller.text.trim();
        if (v.isEmpty || values.contains(v)) return;
        controller.clear();
        _saveContacts(field, [...values, v]);
      }

      return Padding(
        padding: const EdgeInsets.only(top: 6),
        child: Wrap(
          spacing: 6,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(label, style: theme.textTheme.labelLarge),
            if (values.isEmpty && !editable) const Text('—'),
            for (final v in values)
              editable
                  ? InputChip(
                      key: ValueKey('$prefix-$v'),
                      label: Text(v),
                      onDeleted: _busy
                          ? null
                          : () => _saveContacts(field, [
                              for (final o in values)
                                if (o != v) o,
                            ]),
                    )
                  : Chip(key: ValueKey('$prefix-$v'), label: Text(v)),
            if (editable)
              SizedBox(
                width: 200,
                child: TextField(
                  key: ValueKey('$prefix-add'),
                  controller: controller,
                  keyboardType: type,
                  decoration: InputDecoration(isDense: true, hintText: hint),
                  onSubmitted: (_) => add(),
                ),
              ),
          ],
        ),
      );
    }

    return Padding(
      key: const ValueKey('invoice-contacts'),
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Sends to', style: theme.textTheme.titleSmall),
          row(
            'Emails',
            'bill_to_emails',
            emails,
            'invoice-email',
            _newEmail,
            'Add an address',
            TextInputType.emailAddress,
          ),
          row(
            'Phones',
            'bill_to_phones',
            phones,
            'invoice-phone',
            _newPhone,
            'Add a phone',
            TextInputType.phone,
          ),
        ],
      ),
    );
  }

  Widget _payBody(BuildContext context) {
    const methods = ['cash', 'check', 'card', 'transfer', 'other'];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        CalendarDayField(controller: _paidOn, formats: _formats, label: 'Date'),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('invoice-pay-amount'),
          controller: _amount,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: 'Amount',
            prefixText: r'$',
          ),
        ),
        const SizedBox(height: 12),
        Text('Method', style: Theme.of(context).textTheme.labelLarge),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final m in methods)
              ChoiceChip(
                key: ValueKey('invoice-pay-method-$m'),
                label: Text(m[0].toUpperCase() + m.substring(1)),
                selected: _method == m,
                onSelected: (_) => setState(() => _method = m),
              ),
          ],
        ),
        const SizedBox(height: 12),
        SegmentedButton<String>(
          key: const ValueKey('invoice-pay-kind'),
          segments: const [
            ButtonSegment(value: 'charge', label: Text('Payment')),
            ButtonSegment(value: 'refund', label: Text('Refund')),
          ],
          selected: {_kind},
          onSelectionChanged: (s) => setState(() => _kind = s.first),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('invoice-pay-reference'),
          controller: _reference,
          decoration: const InputDecoration(labelText: 'Reference (optional)'),
        ),
        _errorNote(context),
      ],
    );
  }

  Widget _voidBody(BuildContext context, Map invoice) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text(
        'A void invoice stays on record with its number; it is never deleted.',
        style: Theme.of(context).textTheme.bodySmall,
      ),
      const SizedBox(height: 12),
      TextField(
        key: const ValueKey('invoice-void-reason-field'),
        controller: _reason,
        maxLines: 3,
        decoration: const InputDecoration(labelText: 'Why is it void?'),
      ),
      _errorNote(context),
    ],
  );
}

/// The email's HTML as plain lines, for a preview without a web view.
String _plainText(String html) => html
    .replaceAll(
      RegExp(r'<(style|script)[^>]*>[\s\S]*?</\1>', caseSensitive: false),
      '',
    )
    .replaceAll(
      RegExp(r'<br\s*/?>|</(p|div|tr|h[1-6]|li|table)>', caseSensitive: false),
      '\n',
    )
    .replaceAll(RegExp(r'</t[dh]>', caseSensitive: false), '  ')
    .replaceAll(RegExp(r'<[^>]+>'), '')
    .replaceAll('&nbsp;', ' ')
    .replaceAll('&amp;', '&')
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&#039;', "'")
    .replaceAll(RegExp(r'[ \t]+'), ' ')
    .replaceAll(RegExp(r'\n\s*\n+'), '\n')
    .trim();

/// The issuer's logo, fetched with the session's token (the branding paths
/// are authenticated). Nothing is drawn while it loads, when it fails, or
/// when the bytes are not an image.
class _IssuerLogo extends StatefulWidget {
  const _IssuerLogo({super.key, required this.api, required this.path});

  final ApiClient api;
  final String path;

  @override
  State<_IssuerLogo> createState() => _IssuerLogoState();
}

class _IssuerLogoState extends State<_IssuerLogo> {
  Uint8List? _bytes;

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  Future<void> _fetch() async {
    final bytes = await widget.api.getBytes(widget.path);
    if (!mounted || bytes == null || bytes.isEmpty) return;
    setState(() => _bytes = Uint8List.fromList(bytes));
  }

  @override
  Widget build(BuildContext context) {
    final bytes = _bytes;
    if (bytes == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Image.memory(
        bytes,
        key: const ValueKey('invoice-issuer-logo-image'),
        height: 40,
        fit: BoxFit.contain,
        alignment: Alignment.centerLeft,
        errorBuilder: (_, _, _) => const SizedBox.shrink(),
      ),
    );
  }
}
