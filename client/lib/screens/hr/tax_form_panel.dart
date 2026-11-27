import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../widgets/error_note.dart';
import '../../widgets/pdf_view.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/signature_field.dart';

/// W-4 filing status (Step 1c), in the server's words.
const _filingStatuses = {
  'single': 'Single or Married filing separately',
  'married': 'Married filing jointly or Qualifying surviving spouse',
  'head': 'Head of household',
};

/// W-9 federal tax classification (line 3a).
const _classifications = {
  'individual': 'Individual / sole proprietor',
  'c_corporation': 'C corporation',
  's_corporation': 'S corporation',
  'partnership': 'Partnership',
  'trust_estate': 'Trust / estate',
  'llc': 'LLC',
  'other': 'Other',
};

/// I-9 Section 1 (edition 01/20/25): the attestation, in USCIS's order.
const _i9Statuses = {
  'citizen': 'A citizen of the United States',
  'noncitizen_national': 'A noncitizen national of the United States',
  'permanent_resident': 'A lawful permanent resident',
  'authorized_alien': 'A noncitizen authorized to work',
};

/// Each form's name as the panel says it.
const formLabels = {
  'w4': 'W-4',
  'w9': 'W-9',
  'direct_deposit': 'direct deposit authorization',
  'i9': 'I-9',
  'i9_section2': 'I-9, Section 2',
};

/// The W-4 or W-9 filled in and signed in the app, as A1 Tools had it (HR
/// ee9095f5; Jose, 2026-10-01: "it should be signed within the app as well,
/// check how a1tools did it"). The form's own questions, then the official
/// page with the answers printed in its boxes, then the signature. Name,
/// address and the employer's block are the server's, from the profile and
/// the contract.
class TaxFormPanel extends StatefulWidget {
  const TaxFormPanel({
    super.key,
    required this.api,
    required this.onboardingId,
    required this.kind,
    required this.ssnOnFile,
    required this.einOnFile,
    required this.onSigned,
  });

  final ApiClient api;
  final String onboardingId;

  /// `w4` or `w9`, as the server decided it; `direct_deposit` or `i9` for
  /// the new hire's other forms; `i9_section2` for the employer's half of
  /// the I-9 (Jose, 2026-10-06: "so it's all filled on ezbizi").
  final String kind;

  /// Already answered on the form: the server prints the stored number, so
  /// it is not asked again.
  final bool ssnOnFile;
  final bool einOnFile;

  /// Signed: the refreshed onboarding, as PATCH answers it.
  final ValueChanged<ApiResponse> onSigned;

  @override
  State<TaxFormPanel> createState() => _TaxFormPanelState();
}

class _TaxFormPanelState extends State<TaxFormPanel> {
  final _signature = SignatureFieldController();
  final _boxes = <String, TextEditingController>{};

  String? _status;
  String? _accountType;
  String? _i9Status;
  String _classification = 'individual';
  bool _multipleJobs = false;
  bool _exempt = false;
  bool _foreignPartners = false;

  Uint8List? _pdf;
  String? _previewError;
  Timer? _previewTimer;
  int _previewRun = 0;
  bool _signing = false;
  String? _error;

  bool get _w4 => widget.kind == 'w4';
  String get _label => formLabels[widget.kind] ?? widget.kind;
  String get _path => widget.kind == 'i9_section2'
      ? '/hr/onboardings/${widget.onboardingId}/i9-section2'
      : '/hr/onboardings/${widget.onboardingId}/tax-form';

  TextEditingController _box(String key) =>
      _boxes.putIfAbsent(key, TextEditingController.new);

  @override
  void initState() {
    super.initState();
    _signature.addListener(_redraw);
    _preview();
  }

  void _redraw() => setState(() {});

  @override
  void dispose() {
    _previewTimer?.cancel();
    _signature.dispose();
    for (final box in _boxes.values) {
      box.dispose();
    }
    super.dispose();
  }

  /// What the form is filled with. Amounts go as typed; Step 3's total is
  /// the sum of the two lines above it, as the form says.
  Map<String, dynamic> get _values {
    String text(String key) => _boxes[key]?.text.trim() ?? '';
    switch (widget.kind) {
      case 'direct_deposit':
        return {'account_type': _accountType, 'amount': text('amount')};
      case 'i9':
        return {
          'status': _i9Status,
          'other_last_names': text('other_last_names'),
          'lpr_number': text('lpr_number'),
          'work_until': text('work_until'),
          'uscis_number': text('uscis_number'),
          'i94_number': text('i94_number'),
          'passport': text('passport'),
          if (!widget.ssnOnFile) 'ssn': text('ssn'),
        };
      case 'i9_section2':
        return {
          for (final list in ['a', 'b', 'c'])
            for (final part in ['title', 'authority', 'number', 'expires'])
              '${list}_$part': text('${list}_$part'),
          'title': text('title'),
          'first_day': text('first_day'),
          'additional': text('additional'),
        };
    }
    if (_w4) {
      double? amount(String key) =>
          double.tryParse(text(key).replaceAll(',', ''));
      final children = amount('children_amount');
      final others = amount('other_dependents_amount');
      final total = (children ?? 0) + (others ?? 0);
      return {
        if (!widget.ssnOnFile) 'ssn': text('ssn'),
        'filing_status': _status,
        'multiple_jobs': _multipleJobs,
        'children_amount': text('children_amount'),
        'other_dependents_amount': text('other_dependents_amount'),
        'credits_total': children == null && others == null
            ? ''
            : total.toStringAsFixed(total == total.roundToDouble() ? 0 : 2),
        'other_income': text('other_income'),
        'deductions': text('deductions'),
        'extra_withholding': text('extra_withholding'),
        'exempt': _exempt,
      };
    }
    return {
      'business_name': text('business_name'),
      'classification': _classification,
      // Only for the classification that asks for it (audit, 2026-11-27).
      'llc_code': _classification == 'llc' ? text('llc_code') : '',
      'other_classification': _classification == 'other'
          ? text('other_classification')
          : '',
      'exempt_payee_code': text('exempt_payee_code'),
      'fatca_code': text('fatca_code'),
      'foreign_partners': _foreignPartners,
      'account_numbers': text('account_numbers'),
      if (!widget.ssnOnFile && !widget.einOnFile) 'ssn': text('ssn'),
    };
  }

  void _changed() {
    setState(() {});
    _previewTimer?.cancel();
    _previewTimer = Timer(const Duration(milliseconds: 700), _preview);
  }

  /// The page as it would be signed: `?preview=1` draws it and keeps nothing.
  Future<void> _preview() async {
    final run = ++_previewRun;
    final response = await widget.api.postBytesResponse(
      _path,
      {'kind': widget.kind, 'values': _values},
      {'preview': '1'},
    );
    if (!mounted || run != _previewRun) return;
    final bytes = response.bytes;
    setState(() {
      _previewError = response.ok ? null : response.message;
      if (response.ok && bytes != null) _pdf = Uint8List.fromList(bytes);
    });
  }

  bool get _ready =>
      !_signing &&
      _signature.ready &&
      switch (widget.kind) {
        'w4' => _status != null,
        'direct_deposit' => _accountType != null,
        'i9' => _i9Status != null,
        'i9_section2' =>
          (_boxes['a_title']?.text.trim() ?? '').isNotEmpty ||
              ((_boxes['b_title']?.text.trim() ?? '').isNotEmpty &&
                  (_boxes['c_title']?.text.trim() ?? '').isNotEmpty),
        _ => true,
      };

  String get _waitingFor => switch (widget.kind) {
    'w4' when _status == null => 'Choose your filing status, then sign.',
    'direct_deposit' when _accountType == null =>
      'Choose checking or savings, then sign.',
    'i9' when _i9Status == null =>
      'Choose the statement that is true of you, then sign.',
    'i9_section2' when !_signature.ready =>
      'Enter the documents you examined and draw your signature.',
    'i9_section2' =>
      'Enter the List A document, or one from List B and one from List C.',
    _ => 'Draw your signature to sign.',
  };

  Future<void> _sign() async {
    final drawing = await _signature.dataUrl();
    if (drawing == null || !mounted) return;
    setState(() {
      _signing = true;
      _error = null;
    });
    final response = await widget.api.post(_path, {
      'kind': widget.kind,
      'values': _values,
      'drawing': drawing,
    });
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _signing = false;
        _error = response.reason;
      });
      return;
    }
    widget.onSigned(response);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SidePanel(
      title: widget.kind == 'i9_section2' ? _label : 'Your $_label',
      subtitle: switch (widget.kind) {
        'direct_deposit' => 'The form, filled in from your bank answers',
        'i9' => 'The USCIS form, filled in from your answers',
        'i9_section2' => 'The documents you examined, and your signature',
        _ => 'The IRS form, filled in from your answers',
      },
      width: 600,
      actions: [
        FilledButton(
          key: const Key('tax-form-sign'),
          onPressed: _ready ? _sign : null,
          child: Text(_signing ? 'Signing…' : 'Sign the $_label'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ...switch (widget.kind) {
            'w4' => _w4Questions(theme),
            'direct_deposit' => _depositQuestions(theme),
            'i9' => _i9Questions(theme),
            'i9_section2' => _section2Questions(theme),
            _ => _w9Questions(theme),
          },
          const SizedBox(height: 18),
          Text('The form', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          if (_previewError != null)
            ErrorNote(_previewError!)
          else if (_pdf == null)
            Text('Drawing the form…', style: theme.textTheme.bodyMedium)
          else
            PdfView(key: const Key('tax-form-preview'), bytes: _pdf!),
          const SizedBox(height: 18),
          Text('Sign here', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          SignatureField(
            api: widget.api,
            controller: _signature,
            enabled: !_signing,
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            ErrorNote(_error!),
          ],
          if (!_ready && !_signing) ...[
            const SizedBox(height: 8),
            Text(_waitingFor, style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );
  }

  Widget _field(
    String key,
    String label, {
    String? prefix,
    bool amount = false,
    String? hint,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 10),
    child: TextField(
      key: Key('tax-form-$key'),
      controller: _box(key),
      enabled: !_signing,
      keyboardType: amount
          ? const TextInputType.numberWithOptions(decimal: true)
          : null,
      inputFormatters: amount
          ? [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))]
          : null,
      onChanged: (_) => _changed(),
      decoration: InputDecoration(
        labelText: label,
        prefixText: prefix,
        hintText: hint,
        border: const OutlineInputBorder(),
      ),
    ),
  );

  List<Widget> _w4Questions(ThemeData theme) => [
    if (!widget.ssnOnFile)
      _field('ssn', 'Social Security number (Step 1b)', hint: '123-45-6789'),
    Text('Filing status (Step 1c)', style: theme.textTheme.titleSmall),
    RadioGroup<String>(
      groupValue: _status,
      onChanged: (value) {
        if (_signing || value == null) return;
        _status = value;
        _changed();
      },
      child: Column(
        children: [
          for (final MapEntry(key: value, value: label)
              in _filingStatuses.entries)
            RadioListTile<String>(
              key: Key('tax-form-status-$value'),
              value: value,
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(label),
            ),
        ],
      ),
    ),
    // Steps 2 to 4 are optional, so they wait folded (A1 Tools: "skip if
    // they don't apply").
    ExpansionTile(
      key: const Key('tax-form-adjustments'),
      tilePadding: EdgeInsets.zero,
      title: Text(
        'Dependents and adjustments (optional)',
        style: theme.textTheme.titleSmall,
      ),
      subtitle: const Text('Steps 2 to 4: skip them if they do not apply'),
      children: [
        CheckboxListTile(
          key: const Key('tax-form-multiple-jobs'),
          value: _multipleJobs,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text(
            'Step 2(c): I hold two jobs in total, of similar pay',
          ),
          onChanged: _signing
              ? null
              : (on) {
                  _multipleJobs = on ?? false;
                  _changed();
                },
        ),
        _field(
          'children_amount',
          'Step 3: qualifying children under 17 (amount)',
          prefix: r'$',
          amount: true,
        ),
        _field(
          'other_dependents_amount',
          'Step 3: other dependents (amount)',
          prefix: r'$',
          amount: true,
        ),
        _field(
          'other_income',
          '4(a) Other income, not from jobs',
          prefix: r'$',
          amount: true,
        ),
        _field('deductions', '4(b) Deductions', prefix: r'$', amount: true),
        _field(
          'extra_withholding',
          '4(c) Extra withholding each pay period',
          prefix: r'$',
          amount: true,
        ),
        CheckboxListTile(
          key: const Key('tax-form-exempt'),
          value: _exempt,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('I claim exemption from withholding'),
          onChanged: _signing
              ? null
              : (on) {
                  _exempt = on ?? false;
                  _changed();
                },
        ),
      ],
    ),
  ];

  List<Widget> _w9Questions(ThemeData theme) => [
    _field('business_name', 'Business name, if different from yours (line 2)'),
    Text(
      'Federal tax classification (line 3a)',
      style: theme.textTheme.titleSmall,
    ),
    RadioGroup<String>(
      groupValue: _classification,
      onChanged: (value) {
        if (_signing || value == null) return;
        _classification = value;
        _changed();
      },
      child: Column(
        children: [
          for (final MapEntry(key: value, value: label)
              in _classifications.entries)
            RadioListTile<String>(
              key: Key('tax-form-class-$value'),
              value: value,
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(label),
            ),
        ],
      ),
    ),
    if (_classification == 'llc')
      _field('llc_code', 'LLC tax classification (C, S or P)'),
    if (_classification == 'other')
      _field('other_classification', 'Which classification'),
    if (!widget.ssnOnFile && !widget.einOnFile)
      _field('ssn', 'Social Security number', hint: '123-45-6789'),
    ExpansionTile(
      key: const Key('tax-form-exemptions'),
      tilePadding: EdgeInsets.zero,
      title: Text('Exemptions (optional)', style: theme.textTheme.titleSmall),
      subtitle: const Text('Line 4, and line 3b: most people skip these'),
      children: [
        CheckboxListTile(
          value: _foreignPartners,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text(
            'Line 3b: the partnership, trust or estate has foreign partners, '
            'owners or beneficiaries',
          ),
          onChanged: _signing
              ? null
              : (on) {
                  _foreignPartners = on ?? false;
                  _changed();
                },
        ),
        _field('exempt_payee_code', 'Exempt payee code'),
        _field('fatca_code', 'FATCA exemption code'),
        _field('account_numbers', 'Account numbers (line 7)'),
      ],
    ),
  ];

  Widget _choices(
    ThemeData theme,
    String title,
    Map<String, String> options,
    String? value,
    ValueChanged<String> pick,
    String keyPrefix,
  ) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Text(title, style: theme.textTheme.titleSmall),
      RadioGroup<String>(
        groupValue: value,
        onChanged: (picked) {
          if (_signing || picked == null) return;
          pick(picked);
          _changed();
        },
        child: Column(
          children: [
            for (final MapEntry(key: option, value: label) in options.entries)
              RadioListTile<String>(
                key: Key('$keyPrefix-$option'),
                value: option,
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(label),
              ),
          ],
        ),
      ),
    ],
  );

  List<Widget> _depositQuestions(ThemeData theme) => [
    Text(
      'Your bank name, routing and account numbers come from your answers.',
      style: theme.textTheme.bodySmall,
    ),
    const SizedBox(height: 10),
    _choices(
      theme,
      'Account type',
      const {'checking': 'Checking', 'savings': 'Savings'},
      _accountType,
      (v) => _accountType = v,
      'tax-form-account',
    ),
    _field(
      'amount',
      'Amount to deposit to this account',
      hint: '100% if you leave it empty',
    ),
  ];

  List<Widget> _i9Questions(ThemeData theme) => [
    Text(
      'Your name, address, date of birth, Social Security number, email '
      'and phone come from your answers.',
      style: theme.textTheme.bodySmall,
    ),
    const SizedBox(height: 10),
    if (!widget.ssnOnFile)
      _field('ssn', 'Social Security number', hint: '123-45-6789'),
    _field(
      'other_last_names',
      'Other last names used (if any)',
      hint: 'N/A if none',
    ),
    _choices(
      theme,
      'I attest that I am',
      _i9Statuses,
      _i9Status,
      (v) => _i9Status = v,
      'tax-form-i9',
    ),
    if (_i9Status == 'permanent_resident')
      _field('lpr_number', 'USCIS number or A-Number'),
    if (_i9Status == 'authorized_alien') ...[
      _field('work_until', 'Authorized to work until (mm/dd/yyyy, if any)'),
      Text(
        'And one of these:',
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 6),
      _field('uscis_number', 'USCIS A-Number'),
      _field('i94_number', 'Form I-94 admission number'),
      _field('passport', 'Foreign passport number and country of issuance'),
    ],
  ];

  List<Widget> _section2Questions(ThemeData theme) {
    List<Widget> document(String list, String title) => [
      Text(title, style: theme.textTheme.titleSmall),
      const SizedBox(height: 6),
      _field('${list}_title', 'Document title'),
      _field('${list}_authority', 'Issuing authority'),
      _field('${list}_number', 'Document number (if any)'),
      _field('${list}_expires', 'Expiration date (mm/dd/yyyy, if any)'),
    ];
    return [
      Text(
        'Examine the original documents, then enter one from List A, or one '
        'from List B and one from List C. The business name and address '
        'come from the contract.',
        style: theme.textTheme.bodySmall,
      ),
      const SizedBox(height: 10),
      ...document('a', 'List A (identity and work authorization)'),
      ExpansionTile(
        key: const Key('tax-form-lists-bc'),
        tilePadding: EdgeInsets.zero,
        title: Text('Or List B and List C', style: theme.textTheme.titleSmall),
        children: [
          ...document('b', 'List B (identity)'),
          ...document('c', 'List C (work authorization)'),
        ],
      ),
      _field('title', 'Your title', hint: 'e.g. Office Manager'),
      _field(
        'first_day',
        "Employee's first day of employment (mm/dd/yyyy)",
        hint: 'From the contract if you leave it empty',
      ),
      _field('additional', 'Additional information (optional)'),
    ];
  }
}
