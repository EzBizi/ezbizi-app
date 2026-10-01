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

  /// `w4` or `w9`, as the server decided it.
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
  String get _label => _w4 ? 'W-4' : 'W-9';
  String get _path => '/hr/onboardings/${widget.onboardingId}/tax-form';

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
      'llc_code': text('llc_code'),
      'other_classification': text('other_classification'),
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

  bool get _ready => !_signing && _signature.ready && (!_w4 || _status != null);

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
      title: 'Your $_label',
      subtitle: 'The IRS form, filled in from your answers',
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
          ..._w4 ? _w4Questions(theme) : _w9Questions(theme),
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
            Text(
              _w4 && _status == null
                  ? 'Choose your filing status, then sign.'
                  : 'Draw your signature to sign.',
              style: theme.textTheme.bodySmall,
            ),
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
}
