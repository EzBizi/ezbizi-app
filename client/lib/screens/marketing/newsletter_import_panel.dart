import 'dart:convert';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../core/api_client.dart';
import '../../core/files.dart';
import '../../widgets/dashboard_card.dart';
import '../email/message_html.dart';
import 'newsletter_blocks.dart';

/// What an imported HTML document is refused for, and what it is warned about.
///
/// Mirrors the server's refusal (scripts, inline handlers, `javascript:`) so
/// somebody hears it before a save fails; the server still refuses on its own.
({String? error, List<String> warnings}) checkImportedHtml(String html) {
  if (html.trim().isEmpty) {
    return (error: 'There is no HTML to import.', warnings: const []);
  }
  final unsafe = RegExp(
    r'<\s*script\b|<[^>]*\son[a-z]+\s*=|javascript\s*:',
    caseSensitive: false,
  );
  if (unsafe.hasMatch(html)) {
    return (
      error:
          'This HTML contains scripts, which email cannot run and Mailchimp '
          'refuses. Remove them and import it again.',
      warnings: const [],
    );
  }

  final warnings = <String>[];
  if (!RegExp(
    r'''href\s*=\s*["']\*\|UNSUB\|\*["']''',
    caseSensitive: false,
  ).hasMatch(html)) {
    warnings.add(
      'EzBizi adds the company address and unsubscribe footer at '
      'the end, as it does for blocks.',
    );
  }
  final sources =
      RegExp(r'''\bsrc\s*=\s*["']([^"']*)["']''', caseSensitive: false)
          .allMatches(html)
          .map((m) => m.group(1)!.trim())
          .where((s) => !s.startsWith('https://') && !s.startsWith('http://'))
          .toList();
  if (sources.isNotEmpty) {
    warnings.add(
      'Images need full web addresses to show in an inbox: '
      '${sources.take(3).join(', ')}',
    );
  }
  if (RegExp(
    r'<\s*(form|video|iframe|object|embed)\b',
    caseSensitive: false,
  ).hasMatch(html)) {
    warnings.add(
      'Forms, video and embedded pages do not work in most inboxes.',
    );
  }
  return (error: null, warnings: warnings);
}

/// Draws [svg] to a PNG [width] pixels wide, or null when it cannot.
typedef SvgRasteriser = Future<Uint8List?> Function(String svg, int width);

Future<Uint8List?> rasteriseSvg(String svg, int width) async {
  try {
    final info = await vg.loadPicture(SvgStringLoader(svg), null);
    final size = info.size;
    if (size.width <= 0 || size.height <= 0) return null;
    final scale = width / size.width;
    final recorder = ui.PictureRecorder();
    ui.Canvas(recorder)
      ..scale(scale)
      ..drawPicture(info.picture);
    final image = await recorder.endRecording().toImage(
      width,
      (size.height * scale).round(),
    );
    info.picture.dispose();
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    return bytes?.buffer.asUint8List();
  } catch (_) {
    return null;
  }
}

/// Paste or upload an HTML document, or an SVG design, inline in the composer.
///
/// Nothing changes until "Use this" is pressed, and what was there before is
/// handed to [onUseHtml] / [onUseBlocks]'s caller to keep for Undo. A refused
/// import leaves the draft exactly as it was.
class NewsletterImportPanel extends StatefulWidget {
  const NewsletterImportPanel({
    super.key,
    required this.api,
    required this.onUseHtml,
    required this.onUseBlocks,
    required this.onClose,
    this.rasterise = rasteriseSvg,
  });

  final ApiClient? api;
  final ValueChanged<String> onUseHtml;
  final ValueChanged<List<Map<String, dynamic>>> onUseBlocks;
  final VoidCallback onClose;
  final SvgRasteriser rasterise;

  @override
  State<NewsletterImportPanel> createState() => _NewsletterImportPanelState();
}

class _NewsletterImportPanelState extends State<NewsletterImportPanel> {
  bool _svg = false;
  final TextEditingController _source = TextEditingController();
  String? _error;
  List<String> _warnings = const [];
  String? _checkedHtml;
  List<Map<String, dynamic>>? _svgBlocks;
  String? _artwork;
  String _alt = '';
  bool _busy = false;

  @override
  void dispose() {
    _source.dispose();
    super.dispose();
  }

  void _reset() {
    _error = null;
    _warnings = const [];
    _checkedHtml = null;
    _svgBlocks = null;
    _artwork = null;
  }

  Future<void> _pick() async {
    final file = await Files.pickBytes(accept: _svg ? '.svg' : '.html,.htm');
    if (file == null || !mounted) return;
    setState(() {
      _source.text = utf8.decode(file.bytes, allowMalformed: true);
      _reset();
    });
    await _check();
  }

  Future<void> _check() async {
    setState(_reset);
    final text = _source.text;
    if (!_svg) {
      final result = checkImportedHtml(text);
      setState(() {
        _error = result.error;
        _warnings = result.warnings;
        _checkedHtml = result.error == null ? text : null;
      });
      return;
    }

    if (text.trim().isEmpty) {
      setState(() => _error = 'There is no SVG to import.');
      return;
    }
    final api = widget.api;
    if (api == null) return;
    setState(() => _busy = true);
    final response = await api.post('/newsletter/import/svg', {'svg': text});
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final data = response.data as Map? ?? const {};
      _svgBlocks = [
        for (final b in (data['blocks'] as List? ?? const []))
          if (b is Map) Map<String, dynamic>.from(b),
      ];
      _artwork = data['artwork_svg'] as String?;
      _alt = data['alt'] as String? ?? '';
      _warnings = [
        for (final w in (data['warnings'] as List? ?? const [])) '$w',
        if (_artwork != null)
          'The shapes and pictures become one image, with “$_alt” as its '
              'description for inboxes that hide images.',
      ];
    });
  }

  Future<void> _useSvg() async {
    final blocks = [...?_svgBlocks];
    final artwork = _artwork;
    if (artwork != null) {
      setState(() => _busy = true);
      final png = await widget.rasterise(artwork, 1120);
      String? url;
      if (png != null && widget.api != null) {
        final response = await widget.api!.post('/newsletter/import/artwork', {
          'png': base64Encode(png),
        });
        if (response.ok) url = (response.data as Map?)?['url'] as String?;
      }
      if (!mounted) return;
      setState(() => _busy = false);
      if (url == null) {
        // The explicit fallback: the editable parts still arrive, and the
        // person is told the picture did not.
        setState(
          () => _error =
              'The artwork could not be turned into a '
              'picture, so only the text and buttons were imported.',
        );
      } else {
        blocks.insert(0, {'type': 'image', 'src': url, 'alt': _alt});
      }
    }
    widget.onUseBlocks(blocks);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final svgBlocks = _svgBlocks;

    return Container(
      key: const ValueKey('newsletter-import-panel'),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Import a design',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              IconButton(
                tooltip: 'Close',
                onPressed: widget.onClose,
                icon: const Icon(Icons.close, size: 18),
              ),
            ],
          ),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: false, label: Text('HTML')),
                ButtonSegment(value: true, label: Text('SVG design')),
              ],
              selected: {_svg},
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() {
                _svg = s.first;
                _source.clear();
                _reset();
              }),
            ),
          ),
          const SizedBox(height: 8),
          DashboardNote(
            _svg
                ? 'Text and links become blocks you can edit; shapes and '
                      'pictures become one image.'
                : 'Paste the whole email, or choose an .html file.',
          ),
          const SizedBox(height: 8),
          TextField(
            key: const ValueKey('newsletter-import-source'),
            controller: _source,
            minLines: 4,
            maxLines: 8,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 13),
            decoration: InputDecoration(
              hintText: _svg ? '<svg …>' : '<html>…</html>',
            ),
            onChanged: (_) => setState(_reset),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              OutlinedButton.icon(
                onPressed: _busy ? null : _pick,
                icon: const Icon(Icons.upload_file, size: 16),
                label: Text(_svg ? 'Choose .svg file' : 'Choose .html file'),
              ),
              OutlinedButton(
                key: const ValueKey('newsletter-import-check'),
                onPressed: _busy ? null : _check,
                child: const Text('Check and preview'),
              ),
            ],
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              key: const ValueKey('newsletter-import-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
          for (final w in _warnings) ...[
            const SizedBox(height: 4),
            DashboardNote('• $w'),
          ],
          if (_checkedHtml != null) ...[
            const SizedBox(height: 8),
            SizedBox(
              height: 320,
              child: MessageHtmlView(html: _checkedHtml!, ownHtml: true),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                key: const ValueKey('newsletter-import-use'),
                onPressed: () => widget.onUseHtml(_checkedHtml!),
                child: const Text('Use this HTML'),
              ),
            ),
          ],
          if (svgBlocks != null) ...[
            const SizedBox(height: 8),
            if (_artwork != null)
              SizedBox(
                height: 160,
                child: SvgPicture.string(_artwork!, fit: BoxFit.contain),
              ),
            NewsletterEmail(blocks: svgBlocks, company: '', phone: false),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                key: const ValueKey('newsletter-import-use'),
                onPressed: _busy ? null : _useSvg,
                child: const Text('Use these blocks'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
