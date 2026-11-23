/// Review QR (design 204, Jose 2026-11-23): a technician on site types a ZIP,
/// picks a client or a job, taps Generate, and the customer scans the code off
/// the phone. It opens that branch's smiley/sad review page, and every scan
/// counts for whoever made the code (POST customer-experience/review-qr; one
/// code per person per branch, reused for a year).
///
/// One widget, mounted in Customer Experience after Review links and as
/// Inspection's last sub-tab.
library;

import 'dart:async';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../core/theme.dart';
import '../../widgets/list_search.dart';

class ReviewQrPanel extends StatefulWidget {
  const ReviewQrPanel({
    super.key,
    required this.api,
    this.jobId,
    this.customerId,
  });

  final ApiClient api;

  /// Given one, the code is made straight away for that job's or client's
  /// branch.
  final String? jobId;
  final String? customerId;

  @override
  State<ReviewQrPanel> createState() => _ReviewQrPanelState();
}

class _ReviewQrPanelState extends State<ReviewQrPanel> {
  final _search = TextEditingController();
  Timer? _debounce;
  int _request = 0;

  /// Matches for what was typed: ('zip'|'customer'|'job', id/zip, title, sub).
  List<(String, String, String, String)> _matches = const [];
  (String, String, String, String)? _picked;
  bool _busy = false;
  String? _error;
  Map<String, dynamic>? _code;

  static final _zip = RegExp(r'^\d{5}$');

  @override
  void initState() {
    super.initState();
    if (widget.jobId != null) {
      _picked = ('job', widget.jobId!, '', '');
    } else if (widget.customerId != null) {
      _picked = ('customer', widget.customerId!, '', '');
    }
    if (_picked != null) unawaited(_generate());
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _typed(String text) {
    _debounce?.cancel();
    setState(() {
      _picked = null;
      _error = null;
    });
    final term = text.trim();
    if (_zip.hasMatch(term)) {
      setState(() => _matches = [('zip', term, 'Use ZIP $term', '')]);
      return;
    }
    if (term.length < 2) {
      setState(() => _matches = const []);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 300), () => _find(term));
  }

  Future<void> _find(String term) async {
    final request = ++_request;
    final query = {'search': term, 'per_page': '6'};
    final results = await Future.wait([
      widget.api.get('/customers', query),
      widget.api.get('/jobs', query),
    ]);
    if (!mounted || request != _request) return;
    List<Map> rows(ApiResponse r) => [
      if (r.ok && r.data is List)
        for (final m in r.data as List)
          if (m is Map) m,
    ];
    String customerOf(Map j) => switch (j['customer']) {
      final Map c => '${c['name'] ?? ''}',
      final String s => s,
      _ => '${j['customer_name'] ?? ''}',
    };
    setState(
      () => _matches = [
        for (final c in rows(results[0]))
          (
            'customer',
            '${c['id']}',
            '${c['name'] ?? ''}',
            [
              c['address_line1'],
              c['city'],
            ].where((v) => '${v ?? ''}'.isNotEmpty).join(', '),
          ),
        for (final j in rows(results[1]))
          (
            'job',
            '${j['id']}',
            '${j['number'] ?? ''}',
            [
              customerOf(j),
              '${j['title'] ?? ''}',
            ].where((v) => v.isNotEmpty).join(' · '),
          ),
      ],
    );
  }

  Future<void> _generate() async {
    final picked = _picked;
    if (picked == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final r = await widget.api.post('/customer-experience/review-qr', {
      switch (picked.$1) {
        'zip' => 'zip',
        'customer' => 'customer_id',
        _ => 'job_id',
      }: picked.$2,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (r.ok && r.data is Map) {
        _code = Map<String, dynamic>.from(r.data as Map);
      } else {
        _error = r.reason;
      }
    });
  }

  void _restart() => setState(() {
    _code = null;
    _picked = null;
    _matches = const [];
    _error = null;
    _search.clear();
  });

  String get _branch {
    final b = _code?['branch'];
    return b is Map ? '${b['name'] ?? ''}' : '';
  }

  Future<void> _download() async {
    final url = '${_code?['url'] ?? ''}';
    final image = await QrPainter(
      data: url,
      version: QrVersions.auto,
      gapless: true,
      // The printed copy is always dark on white, whatever the theme.
      eyeStyle: const QrEyeStyle(
        eyeShape: QrEyeShape.square,
        color: EzBiziTheme.paperInk,
      ),
      dataModuleStyle: const QrDataModuleStyle(
        dataModuleShape: QrDataModuleShape.square,
        color: EzBiziTheme.paperInk,
      ),
    ).toImageData(1024, format: ui.ImageByteFormat.png);
    if (!mounted || image == null) return;
    final slug = _branch
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
        .replaceAll(RegExp(r'^-+|-+$'), '');
    Files.download(
      'review-qr${slug.isEmpty ? '' : '-$slug'}.png',
      image.buffer.asUint8List(),
    );
  }

  Future<void> _copy() async {
    await Clipboard.setData(ClipboardData(text: '${_code?['url'] ?? ''}'));
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(
      context,
    )?.showSnackBar(const SnackBar(content: Text('Link copied.')));
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: _code == null ? _form(context) : _result(context, box),
          ),
        ),
      ),
    );
  }

  Widget _form(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Column(
      key: const ValueKey('review-qr-form'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Make a review QR code',
          textAlign: TextAlign.center,
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: 4),
        Text(
          "The customer scans it and lands on their branch's review page. "
          'Every scan counts for you.',
          textAlign: TextAlign.center,
          style: muted,
        ),
        const SizedBox(height: 16),
        ListSearch(
          key: const ValueKey('review-qr-search'),
          controller: _search,
          hint: 'ZIP, client or job number',
          width: 460,
          onChanged: _typed,
          onClear: () => _typed(''),
        ),
        if (_picked == null && _matches.isNotEmpty) ...[
          const SizedBox(height: 4),
          Container(
            decoration: BoxDecoration(
              border: Border.all(color: EzBiziTheme.edge(context)),
              borderRadius: BorderRadius.circular(10),
            ),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                for (final m in _matches)
                  ListTile(
                    key: ValueKey('review-qr-match-${m.$1}-${m.$2}'),
                    dense: true,
                    leading: Icon(switch (m.$1) {
                      'zip' => Icons.place_outlined,
                      'customer' => Icons.person_outline,
                      _ => Icons.handyman_outlined,
                    }, size: 20),
                    title: Text(m.$3),
                    subtitle: m.$4.isEmpty ? null : Text(m.$4),
                    onTap: () => setState(() {
                      _picked = m;
                      _search.text = m.$1 == 'zip' ? m.$2 : m.$3;
                    }),
                  ),
              ],
            ),
          ),
        ] else if (_picked == null &&
            _search.text.trim().length >= 2 &&
            !_zip.hasMatch(_search.text.trim())) ...[
          const SizedBox(height: 8),
          Text(
            'Type a 5-digit ZIP, or pick a client or job from the list.',
            style: muted,
            textAlign: TextAlign.center,
          ),
        ],
        if (_error != null) ...[
          const SizedBox(height: 8),
          Text(
            _error!,
            key: const ValueKey('review-qr-error'),
            textAlign: TextAlign.center,
            style: TextStyle(color: theme.colorScheme.error),
          ),
        ],
        const SizedBox(height: 16),
        Center(
          child: FilledButton.icon(
            key: const ValueKey('review-qr-generate'),
            onPressed: _picked == null || _busy ? null : _generate,
            icon: const Icon(Icons.qr_code_2),
            label: Text(_busy ? 'Making the code…' : 'Generate'),
          ),
        ),
      ],
    );
  }

  Widget _result(BuildContext context, BoxConstraints box) {
    final theme = Theme.of(context);
    final url = '${_code?['url'] ?? ''}';
    final expires = DateTime.tryParse('${_code?['expires_at'] ?? ''}');
    final size = (box.maxWidth - 64).clamp(180.0, 300.0);
    return Column(
      key: const ValueKey('review-qr-result'),
      children: [
        // Dark on white in either theme: a phone camera reads that best.
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: EzBiziTheme.paper,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: EzBiziTheme.edge(context)),
          ),
          child: SizedBox.square(
            dimension: size,
            child: QrImageView(
              key: const ValueKey('review-qr-image'),
              data: url,
              padding: EdgeInsets.zero,
              eyeStyle: const QrEyeStyle(
                eyeShape: QrEyeShape.square,
                color: EzBiziTheme.paperInk,
              ),
              dataModuleStyle: const QrDataModuleStyle(
                dataModuleShape: QrDataModuleShape.square,
                color: EzBiziTheme.paperInk,
              ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        Text(_branch, style: theme.textTheme.titleMedium),
        const SizedBox(height: 2),
        Text(
          [
            'Scans count for you',
            if (expires != null)
              'valid until ${MaterialLocalizations.of(context).formatFullDate(expires.toLocal())}',
          ].join(' · '),
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 16),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          alignment: WrapAlignment.center,
          children: [
            OutlinedButton.icon(
              key: const ValueKey('review-qr-download'),
              onPressed: _download,
              icon: const Icon(Icons.download_outlined, size: 18),
              label: const Text('Download PNG'),
            ),
            OutlinedButton.icon(
              key: const ValueKey('review-qr-copy'),
              onPressed: _copy,
              icon: const Icon(Icons.link, size: 18),
              label: const Text('Copy link'),
            ),
            OutlinedButton.icon(
              key: const ValueKey('review-qr-new'),
              onPressed: _restart,
              icon: const Icon(Icons.refresh, size: 18),
              label: const Text('New code'),
            ),
          ],
        ),
      ],
    );
  }
}
