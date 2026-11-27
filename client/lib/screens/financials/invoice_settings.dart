import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/open_destination.dart';
import '../../widgets/search_picker.dart';

/// Financials › Invoices › Settings, written as sentences: the charge lines
/// (in order, on or off, where each comes from), how invoices are emailed,
/// when they are due and how their numbers start.
class InvoiceSettingsTab extends StatefulWidget {
  const InvoiceSettingsTab({super.key, required this.api, required this.me});

  final ApiClient api;
  final Me me;

  @override
  State<InvoiceSettingsTab> createState() => _InvoiceSettingsTabState();
}

class _ChargeLine {
  _ChargeLine({
    this.id,
    String name = '',
    String? source,
    this.active = true,
    this.label,
  }) : name = TextEditingController(text: name),
       source = TextEditingController(text: source ?? '');

  final String? id;
  final TextEditingController name;
  final TextEditingController source;
  bool active;

  /// Where the server says it comes from, in words.
  final String? label;

  /// A stable key while it is dragged, before the server has given it an id.
  late final Key key = ValueKey(id ?? Object());

  void dispose() {
    name.dispose();
    source.dispose();
  }
}

class _InvoiceSettingsTabState extends State<InvoiceSettingsTab> {
  List<_ChargeLine>? _lines;
  Map<String, Map> _settings = {};
  String? _nextNumber;
  String? _error;
  String? _linesNote;
  bool _linesDirty = false;
  bool _busy = false;

  final _summaryTo = TextEditingController();
  final _prefix = TextEditingController();
  final _whatsappFrom = TextEditingController();
  final _whatsappTemplate = TextEditingController();

  /// What GET /invoices/settings answered for the place chosen: each
  /// channel's readiness, the numbers that can text, the mailbox.
  Map _extras = const {};

  /// The company these settings are for, or null for the organization.
  /// A company view starts on its company and cannot reach the
  /// organization's settings (authority does not flow up).
  late String? _company = widget.me.scope.type == 'organization'
      ? null
      : widget.me.filingCompanyId ?? widget.me.companiesInView.firstOrNull?.id;

  static const _keys = [
    'invoicing.email_mode',
    'invoicing.summary_to',
    'invoicing.channels',
    'invoicing.sms_from',
    'invoicing.whatsapp_from',
    'invoicing.whatsapp_template',
    'money.payment_terms_days',
    'numbering.invoice_prefix',
  ];

  static const _channelNames = {
    'email': 'Email',
    'sms': 'Text',
    'whatsapp': 'WhatsApp',
  };

  bool get _canEdit => widget.me.can('invoices.edit');

  Map<String, String> get _scope => _company == null
      ? {'scope_type': 'organization', 'scope_id': widget.me.organization.id}
      : {'scope_type': 'company', 'scope_id': _company!};

  Future<ApiResponse> _getExtras() => widget.api.get(
    '/invoices/settings',
    _company == null ? null : {'company_id': _company!},
  );

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final l in _lines ?? const <_ChargeLine>[]) {
      l.dispose();
    }
    _summaryTo.dispose();
    _prefix.dispose();
    _whatsappFrom.dispose();
    _whatsappTemplate.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.api.get('/invoices/charge-columns'),
      widget.api.get('/settings', _scope),
      _getExtras(),
    ]);
    if (!mounted) return;
    final [columns, settings, extras] = results;
    setState(() {
      _error = columns.ok ? null : columns.reason;
      _adoptLines(
        columns.ok ? (columns.body['data'] as List).cast<Map>() : const [],
      );
      if (settings.ok && settings.data is List) {
        _settings = {
          for (final s in (settings.data as List).cast<Map>())
            if (_keys.contains(s['key'])) '${s['key']}': s,
        };
        _summaryTo.text =
            '${_settings['invoicing.summary_to']?['value'] ?? ''}';
        _prefix.text =
            '${_settings['numbering.invoice_prefix']?['value'] ?? ''}';
        _whatsappFrom.text =
            '${_settings['invoicing.whatsapp_from']?['value'] ?? ''}';
        _whatsappTemplate.text =
            '${_settings['invoicing.whatsapp_template']?['value'] ?? ''}';
      }
      _adoptExtras(extras);
    });
  }

  void _adoptExtras(ApiResponse extras) {
    _extras = extras.ok ? (extras.body['data'] as Map? ?? const {}) : const {};
    _nextNumber = _extras['next_number'] as String?;
  }

  Future<void> _reloadExtras() async {
    final extras = await _getExtras();
    if (mounted) setState(() => _adoptExtras(extras));
  }

  /// Another place: its own settings, read again.
  Future<void> _choosePlace(String? company) async {
    setState(() => _company = company);
    final results = await Future.wait([
      widget.api.get('/settings', _scope),
      _getExtras(),
    ]);
    if (!mounted) return;
    final [settings, extras] = results;
    setState(() {
      if (settings.ok && settings.data is List) {
        _settings = {
          for (final s in (settings.data as List).cast<Map>())
            if (_keys.contains(s['key'])) '${s['key']}': s,
        };
        _summaryTo.text =
            '${_settings['invoicing.summary_to']?['value'] ?? ''}';
        _prefix.text =
            '${_settings['numbering.invoice_prefix']?['value'] ?? ''}';
        _whatsappFrom.text =
            '${_settings['invoicing.whatsapp_from']?['value'] ?? ''}';
        _whatsappTemplate.text =
            '${_settings['invoicing.whatsapp_template']?['value'] ?? ''}';
      }
      _adoptExtras(extras);
    });
  }

  void _adoptLines(List<Map> columns) {
    for (final l in _lines ?? const <_ChargeLine>[]) {
      l.dispose();
    }
    _lines = [
      for (final c in columns)
        _ChargeLine(
          id: '${c['id']}',
          name: '${c['name']}',
          source: c['source_column'] as String?,
          active: c['is_active'] != false,
          label: c['source_label'] as String?,
        ),
    ];
    _linesDirty = false;
  }

  Future<void> _saveLines() async {
    setState(() {
      _busy = true;
      _linesNote = null;
    });
    final r = await widget.api.put('/invoices/charge-columns', {
      'columns': [
        for (final l in _lines!)
          {
            'id': ?l.id,
            'name': l.name.text.trim(),
            'source_column': l.source.text.trim().isEmpty
                ? null
                : l.source.text.trim(),
            'is_active': l.active,
          },
      ],
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (r.ok) {
        _adoptLines((r.body['data'] as List).cast<Map>());
        _linesNote = 'Saved.';
      } else {
        _linesNote = r.reason;
      }
    });
  }

  Future<void> _set(String key, Object? value) async {
    final r = await widget.api.patch('/settings/$key', {
      'value': value,
    }, _scope);
    if (!mounted) return;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return;
    }
    setState(() => _settings[key] = {...?_settings[key], 'value': value});
    // Readiness and the next number follow these.
    if (key == 'numbering.invoice_prefix' || key.startsWith('invoicing.')) {
      await _reloadExtras();
    }
  }

  List<String> get _chosen {
    final value = _settings['invoicing.channels']?['value'];
    final chosen = [
      for (final c in (value is List ? value : const ['email']))
        if (_channelNames.containsKey('$c')) '$c',
    ];
    return chosen.isEmpty ? const ['email'] : chosen;
  }

  Map? _readiness(String channel) =>
      ((_extras['channels'] as List?) ?? const []).cast<Map>().firstWhere(
        (c) => c['channel'] == channel,
        orElse: () => const {},
      );

  bool _mayEdit(String key) => _settings[key]?['may_edit'] == true;

  @override
  Widget build(BuildContext context) {
    final lines = _lines;
    if (lines == null) return const Center(child: CircularProgressIndicator());
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Charge lines', style: theme.textTheme.titleMedium),
        Text(
          'The columns of every new charge sheet, in this order. Drag to reorder.',
          style: theme.textTheme.bodySmall,
        ),
        if (_error != null) Text(_error!),
        ReorderableListView(
          key: const ValueKey('tour-invoices-columns'),
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          buildDefaultDragHandles: false,
          onReorder: (from, to) => setState(() {
            if (to > from) to--;
            lines.insert(to, lines.removeAt(from));
            _linesDirty = true;
          }),
          children: [
            for (final (i, l) in lines.indexed) _lineRow(context, l, i),
          ],
        ),
        Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (_canEdit)
              TextButton.icon(
                key: const ValueKey('invoice-column-add'),
                onPressed: () => setState(() {
                  lines.add(_ChargeLine());
                  _linesDirty = true;
                }),
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add a line'),
              ),
            if (_canEdit)
              FilledButton(
                key: const ValueKey('invoice-columns-save'),
                onPressed: _busy || !_linesDirty ? null : _saveLines,
                child: const Text('Save'),
              ),
            if (_linesNote != null)
              Text(_linesNote!, key: const ValueKey('invoice-columns-note')),
          ],
        ),
        const SizedBox(height: 24),
        Column(
          key: const ValueKey('tour-invoices-sentences'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Invoicing', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            _placePicker(context),
            if (_settings.containsKey('invoicing.channels')) ...[
              _channelsSentence(context),
              const SizedBox(height: 12),
            ],
            if (_settings.containsKey('invoicing.email_mode') &&
                _chosen.contains('email'))
              _emailSentence(context),
            if (_settings.containsKey('money.payment_terms_days')) ...[
              const SizedBox(height: 12),
              _dueSentence(context),
            ],
            if (_settings.containsKey('numbering.invoice_prefix')) ...[
              const SizedBox(height: 12),
              _prefixSentence(context),
            ],
            if (_extras['payments'] case {'configured': true}) ...[
              const SizedBox(height: 12),
              _paymentsSentence(context),
            ],
          ],
        ),
      ],
    );
  }

  Widget _lineRow(BuildContext context, _ChargeLine l, int i) {
    final theme = Theme.of(context);
    return Padding(
      key: l.key,
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ReorderableDragStartListener(
            index: i,
            enabled: _canEdit,
            child: const Padding(
              padding: EdgeInsets.only(top: 12, right: 4),
              child: Icon(Icons.drag_indicator),
            ),
          ),
          Checkbox(
            key: ValueKey('invoice-column-$i-active'),
            value: l.active,
            onChanged: _canEdit
                ? (v) => setState(() {
                    l.active = v ?? false;
                    _linesDirty = true;
                  })
                : null,
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    SizedBox(
                      width: 220,
                      child: TextField(
                        key: ValueKey('invoice-column-$i-name'),
                        controller: l.name,
                        enabled: _canEdit,
                        decoration: const InputDecoration(
                          isDense: true,
                          labelText: 'Name',
                        ),
                        onChanged: (_) => setState(() => _linesDirty = true),
                      ),
                    ),
                    SizedBox(
                      width: 240,
                      child: TextField(
                        key: ValueKey('invoice-column-$i-source'),
                        controller: l.source,
                        enabled: _canEdit,
                        decoration: const InputDecoration(
                          isDense: true,
                          labelText: 'Source column',
                          hintText: 'Empty: typed in on the sheet',
                        ),
                        onChanged: (_) => setState(() => _linesDirty = true),
                      ),
                    ),
                  ],
                ),
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    l.active ? (l.label ?? 'Not saved yet') : 'Off',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              ],
            ),
          ),
          if (_canEdit)
            IconButton(
              tooltip: 'Remove line',
              icon: const Icon(Icons.close, size: 18),
              onPressed: () => setState(() {
                _lines!.removeAt(i).dispose();
                _linesDirty = true;
              }),
            ),
        ],
      ),
    );
  }

  Widget _inline(Widget child, double width) =>
      SizedBox(width: width, child: child);

  Widget _emailSentence(BuildContext context) {
    final mode =
        '${_settings['invoicing.email_mode']?['value'] ?? 'per_branch'}';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            const Text('Send'),
            _inline(
              SearchPickerField<String>(
                key: const ValueKey('invoice-setting-email-mode'),
                isDense: true,
                value: mode,
                options: const [
                  SearchOption(
                    value: 'per_branch',
                    label: 'One email per branch',
                  ),
                  SearchOption(
                    value: 'summary',
                    label: 'One summary email to accounting',
                  ),
                ],
                onChanged: _mayEdit('invoicing.email_mode')
                    ? (v) {
                        if (v != null) _set('invoicing.email_mode', v);
                      }
                    : null,
              ),
              300,
            ),
          ],
        ),
        if (mode == 'summary' && _settings.containsKey('invoicing.summary_to'))
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Wrap(
              spacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                const Text('to'),
                _inline(
                  TextField(
                    key: const ValueKey('invoice-setting-summary-to'),
                    controller: _summaryTo,
                    enabled: _mayEdit('invoicing.summary_to'),
                    decoration: const InputDecoration(
                      isDense: true,
                      hintText: 'accounting@…, separated by commas',
                    ),
                    onSubmitted: (v) => _set('invoicing.summary_to', v.trim()),
                  ),
                  300,
                ),
                if (_mayEdit('invoicing.summary_to'))
                  TextButton(
                    key: const ValueKey('invoice-setting-summary-to-save'),
                    onPressed: () =>
                        _set('invoicing.summary_to', _summaryTo.text.trim()),
                    child: const Text('Save'),
                  ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _dueSentence(BuildContext context) {
    final row = _settings['money.payment_terms_days']!;
    final value = (row['value'] as num?)?.toInt();
    final options = [
      for (final o in (row['options'] as List? ?? const []))
        (o is Map ? (o['value'] as num?)?.toInt() : (o as num?)?.toInt()),
    ].whereType<int>().toList();
    return Wrap(
      spacing: 8,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        const Text('Invoices are due'),
        _inline(
          SearchPickerField<int>(
            key: const ValueKey('invoice-setting-due-days'),
            isDense: true,
            value: value,
            options: [
              for (final o in options) SearchOption(value: o, label: '$o'),
              if (value != null && !options.contains(value))
                SearchOption(value: value, label: '$value'),
            ],
            onChanged: _mayEdit('money.payment_terms_days')
                ? (v) {
                    if (v != null) _set('money.payment_terms_days', v);
                  }
                : null,
          ),
          100,
        ),
        const Text("days after they're dated."),
      ],
    );
  }

  Widget _prefixSentence(BuildContext context) => Wrap(
    spacing: 8,
    runSpacing: 6,
    crossAxisAlignment: WrapCrossAlignment.center,
    children: [
      const Text('Numbers start with'),
      _inline(
        TextField(
          key: const ValueKey('invoice-setting-prefix'),
          controller: _prefix,
          enabled: _mayEdit('numbering.invoice_prefix'),
          decoration: const InputDecoration(isDense: true),
          onSubmitted: (v) => _set('numbering.invoice_prefix', v.trim()),
        ),
        120,
      ),
      if (_mayEdit('numbering.invoice_prefix'))
        TextButton(
          key: const ValueKey('invoice-setting-prefix-save'),
          onPressed: () =>
              _set('numbering.invoice_prefix', _prefix.text.trim()),
          child: const Text('Save'),
        ),
      if (_nextNumber != null)
        Text(
          'next: $_nextNumber',
          key: const ValueKey('invoice-next-number'),
          style: Theme.of(context).textTheme.bodySmall,
        ),
    ],
  );

  /// Card and bank payments: the company's own Stripe account. Once it can
  /// take charges, every invoice it sends carries a Pay link.
  Widget _paymentsSentence(BuildContext context) {
    final p = _extras['payments'] as Map;
    final live = p['charges_enabled'] == true;
    final started = p['stripe_account_id'] != null;
    final theme = Theme.of(context);
    return Wrap(
      key: const ValueKey('invoice-setting-payments'),
      spacing: 8,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text(
          live
              ? 'Customers can pay online by card or bank transfer: every invoice carries a Pay link.'
              : 'Let customers pay invoices online by card or bank transfer.',
        ),
        if (live && p['payouts_enabled'] != true)
          Text(
            'Stripe still needs something before it can pay out to your bank.',
            style: TextStyle(color: theme.colorScheme.error),
          ),
        if (p['may_connect'] == true && (!live || p['payouts_enabled'] != true))
          FilledButton(
            key: const ValueKey('invoice-payments-connect'),
            onPressed: _busy ? null : _connectStripe,
            child: Text(started ? 'Continue Stripe setup' : 'Connect Stripe'),
          ),
      ],
    );
  }

  Future<void> _connectStripe() async {
    setState(() => _busy = true);
    final r = await widget.api.post('/invoices/payments-account/onboarding', {
      'company_id': _company,
    });
    if (!mounted) return;
    setState(() => _busy = false);
    final data = r.ok ? r.body['data'] : null;
    final url = data is Map ? data['url'] as String? : null;
    if (url == null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return;
    }
    await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  }

  /// Which place these settings are for: the organization (from an
  /// organization view) or one of the companies in view.
  Widget _placePicker(BuildContext context) {
    final me = widget.me;
    final companies = me.companiesInView;
    final orgView = me.scope.type == 'organization';
    if (companies.isEmpty || (!orgView && companies.length < 2)) {
      return const SizedBox.shrink();
    }
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          const Text('For'),
          _inline(
            SearchPickerField<String>(
              key: const ValueKey('invoice-setting-place'),
              isDense: true,
              value: _company,
              options: [
                if (orgView)
                  SearchOption(
                    value: null,
                    label: '${me.organization.name} (every company)',
                  ),
                for (final c in companies)
                  SearchOption(value: c.id, label: c.name),
              ],
              onChanged: (c) {
                if (c != _company) _choosePlace(c);
              },
            ),
            300,
          ),
        ],
      ),
    );
  }

  Widget _channelsSentence(BuildContext context) {
    final theme = Theme.of(context);
    final chosen = _chosen;
    final mayEdit = _mayEdit('invoicing.channels');
    return Column(
      key: const ValueKey('invoice-setting-channels'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 6,
          runSpacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            const Text('Send invoices by'),
            for (final MapEntry(key: c, value: label) in _channelNames.entries)
              FilterChip(
                key: ValueKey('invoice-channel-$c'),
                label: Text(label),
                selected: chosen.contains(c),
                onSelected: !mayEdit
                    ? null
                    : (on) {
                        final next = [
                          for (final k in _channelNames.keys)
                            if (k == c ? on : chosen.contains(k)) k,
                        ];
                        if (next.isEmpty) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text(
                                'Invoices need at least one way to be sent.',
                              ),
                            ),
                          );
                          return;
                        }
                        _set('invoicing.channels', next);
                      },
              ),
          ],
        ),
        for (final c in _channelNames.keys)
          if (chosen.contains(c))
            Padding(
              padding: const EdgeInsets.only(top: 8, left: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_channelNames[c]!, style: theme.textTheme.labelLarge),
                  _readinessLine(context, c),
                  if (c == 'sms') _smsPicker(context),
                  if (c == 'whatsapp') _whatsappFields(context),
                ],
              ),
            ),
      ],
    );
  }

  Widget _readinessLine(BuildContext context, String channel) {
    final theme = Theme.of(context);
    final r = _readiness(channel) ?? const {};
    if (r.isEmpty) return const SizedBox.shrink();
    if (r['ready'] == true) {
      final from = r['from'] as String?;
      final mailbox = _extras['mailbox'] as Map?;
      final text = channel == 'email' && mailbox == null
          ? 'from ${from == null || from.isEmpty ? 'your' : from} own mail server'
          : 'from ${from ?? ''}';
      return Text(
        text,
        key: ValueKey('invoice-channel-$channel-ready'),
        style: theme.textTheme.bodySmall,
      );
    }
    final why = '${r['why_not'] ?? 'Not set up yet.'}';
    final open = OpenDestination.of(context);
    final where = why.contains('Integrations')
        ? ('settings', 'Open Settings › Integrations')
        : why.contains('in Phone')
        ? ('phone', 'Open Phone')
        : null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          why,
          key: ValueKey('invoice-channel-$channel-why'),
          style: TextStyle(color: theme.colorScheme.error),
        ),
        if (open != null && where != null)
          TextButton(
            key: ValueKey('invoice-channel-$channel-open'),
            onPressed: () => open(where.$1),
            child: Text(where.$2),
          ),
      ],
    );
  }

  Widget _smsPicker(BuildContext context) {
    final numbers = ((_extras['sms_numbers'] as List?) ?? const []).cast<Map>();
    final value = '${_settings['invoicing.sms_from']?['value'] ?? ''}';
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          const Text('Texted from'),
          _inline(
            SearchPickerField<String>(
              key: const ValueKey('invoice-setting-sms-from'),
              isDense: true,
              hint: numbers.isEmpty ? 'No number can text' : 'Choose a number',
              value: numbers.any((n) => '${n['id']}' == value) ? value : null,
              options: [
                for (final n in numbers)
                  SearchOption(
                    value: '${n['id']}',
                    label: '${n['e164']}',
                    detail: n['label'] as String?,
                  ),
              ],
              onChanged: _mayEdit('invoicing.sms_from') && numbers.isNotEmpty
                  ? (id) {
                      if (id != null) _set('invoicing.sms_from', id);
                    }
                  : null,
            ),
            260,
          ),
        ],
      ),
    );
  }

  Widget _whatsappFields(BuildContext context) {
    Widget field(
      String key,
      String label,
      TextEditingController controller,
      String hint,
    ) => Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(label),
          _inline(
            TextField(
              key: ValueKey('invoice-setting-$key'),
              controller: controller,
              enabled: _mayEdit('invoicing.$key'),
              decoration: InputDecoration(isDense: true, hintText: hint),
              onSubmitted: (v) => _set('invoicing.$key', v.trim()),
            ),
            260,
          ),
          if (_mayEdit('invoicing.$key'))
            TextButton(
              key: ValueKey('invoice-setting-$key-save'),
              onPressed: () => _set('invoicing.$key', controller.text.trim()),
              child: const Text('Save'),
            ),
        ],
      ),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (_settings.containsKey('invoicing.whatsapp_from'))
          field(
            'whatsapp_from',
            'From',
            _whatsappFrom,
            'Only if you have more than one number',
          ),
        if (_settings.containsKey('invoicing.whatsapp_template'))
          field(
            'whatsapp_template',
            'Template',
            _whatsappTemplate,
            'The approved template name',
          ),
      ],
    );
  }
}
