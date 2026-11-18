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

/// Where an import comes from (step 1).
enum ImportSource { paste, file, svg }

/// What the import does with it (step 2).
enum ImportMode { keep, convert, svgBlocks, artwork }

/// One concrete thing the compare step found, as the server words it.
class ImportIssue {
  const ImportIssue(this.kind, this.message);
  final String kind;
  final String message;
}

/// The import wizard (newsletter P1c, 2026-11-18): Source → Mode → Compare →
/// Apply, inline in the composer.
///
/// 1. Source: paste HTML, upload an .html file, or an SVG design (distinct).
/// 2. Mode, each with what stays editable: keep the HTML as written, convert
///    compatible HTML to blocks (server-side, nothing saved), SVG text as
///    blocks with the artwork as one picture, or the whole design as one
///    picture.
/// 3. Compare the original and the result side by side, desktop or phone
///    width, with the concrete issues; losses must be accepted before Apply.
/// 4. Apply to this draft — after [onBeforeApply] has kept a "Before import"
///    restore point, so history can undo it — or save it as a new draft.
///
/// Any failure (refused HTML, a server error, a restore point that could not
/// be kept, artwork that would not upload) leaves the editor's content as it
/// was: nothing reaches [onUseHtml] / [onUseBlocks] until every step worked.
class NewsletterImportPanel extends StatefulWidget {
  const NewsletterImportPanel({
    super.key,
    required this.api,
    required this.onUseHtml,
    required this.onUseBlocks,
    required this.onClose,
    this.rasterise = rasteriseSvg,
    this.newsletterId,
    this.subject = '',
    this.onBeforeApply,
    this.onSavedAsNew,
  });

  final ApiClient? api;
  final ValueChanged<String> onUseHtml;
  final ValueChanged<List<Map<String, dynamic>>> onUseBlocks;
  final VoidCallback onClose;
  final SvgRasteriser rasterise;

  /// The draft being edited, so the server previews with its footer and
  /// files the original beside it.
  final String? newsletterId;

  /// The draft's subject, for "Save as new draft".
  final String subject;

  /// Keeps a "Before import" restore point; false stops the apply.
  final Future<bool> Function()? onBeforeApply;

  /// A new draft was saved from the import; its id.
  final ValueChanged<String>? onSavedAsNew;

  @override
  State<NewsletterImportPanel> createState() => _NewsletterImportPanelState();
}

class _NewsletterImportPanelState extends State<NewsletterImportPanel> {
  int _step = 0;
  ImportSource _source = ImportSource.paste;
  ImportMode _mode = ImportMode.keep;
  final TextEditingController _text = TextEditingController();
  String? _fileName;
  String? _error;
  String? _done;
  bool _busy = false;
  bool _phone = false;
  bool _acceptLosses = false;

  // Step 3's result.
  String? _resultHtml;
  String? _previewHtml;
  List<Map<String, dynamic>>? _resultBlocks;
  List<ImportIssue> _issues = const [];
  List<String> _lost = const [];
  String? _artwork;
  String _alt = '';

  bool get _svg => _source == ImportSource.svg;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _clearResult() {
    _error = null;
    _done = null;
    _resultHtml = null;
    _previewHtml = null;
    _resultBlocks = null;
    _issues = const [];
    _lost = const [];
    _artwork = null;
    _acceptLosses = false;
  }

  Future<void> _pick() async {
    final file = await Files.pickBytes(accept: _svg ? '.svg' : '.html,.htm');
    if (file == null || !mounted) return;
    setState(() {
      _text.text = utf8.decode(file.bytes, allowMalformed: true);
      _fileName = file.name;
      _clearResult();
    });
  }

  /// Step 1 → 2: there must be something, and HTML must not carry scripts.
  void _next() {
    final text = _text.text;
    setState(() {
      _clearResult();
      if (text.trim().isEmpty) {
        _error = _svg ? 'There is no SVG to import.' : 'There is no HTML to import.';
        return;
      }
      if (!_svg) {
        final refused = checkImportedHtml(text).error;
        if (refused != null) {
          _error = refused;
          return;
        }
      }
      _mode = _svg
          ? (_mode == ImportMode.artwork ? ImportMode.artwork : ImportMode.svgBlocks)
          : (_mode == ImportMode.convert ? ImportMode.convert : ImportMode.keep);
      _step = 1;
    });
  }

  /// Step 2 → 3: asks the server what the chosen mode makes of it. Nothing saves.
  Future<void> _compare() async {
    final api = widget.api;
    if (api == null) return;
    setState(() {
      _clearResult();
      _busy = true;
    });
    final text = _text.text;
    final ApiResponse response;
    if (_svg) {
      response = await api.post('/newsletter/import/svg', {'svg': text});
    } else {
      response = await api.post('/newsletter/import/html', {
        'html': text,
        'mode': _mode == ImportMode.convert ? 'convert' : 'keep',
        if (widget.newsletterId != null) 'newsletter_id': widget.newsletterId,
      });
    }
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      final data = response.data as Map? ?? const {};
      if (_svg) {
        _artwork = data['artwork_svg'] as String?;
        _alt = data['alt'] as String? ?? '';
        final warnings = [for (final w in (data['warnings'] as List? ?? const [])) '$w'];
        if (_mode == ImportMode.artwork) {
          _resultBlocks = const [];
          _issues = [
            const ImportIssue('note', 'The whole design becomes one picture: its words cannot be edited or read by inboxes that hide pictures.'),
          ];
        } else {
          _resultBlocks = [
            for (final b in (data['blocks'] as List? ?? const []))
              if (b is Map) Map<String, dynamic>.from(b),
          ];
          _issues = [
            for (final w in warnings) ImportIssue('note', w),
            if (_artwork != null)
              ImportIssue('note', 'The shapes and pictures become one image, with “$_alt” as its description for inboxes that hide images.'),
          ];
        }
      } else {
        _previewHtml = data['preview_html'] as String?;
        _resultHtml = data['html_override'] as String?;
        final blocks = data['blocks'];
        _resultBlocks = blocks is List
            ? [for (final b in blocks) if (b is Map) Map<String, dynamic>.from(b)]
            : null;
        _issues = [
          for (final i in (data['issues'] as List? ?? const []))
            if (i is Map) ImportIssue('${i['kind'] ?? 'note'}', '${i['message'] ?? ''}'),
        ];
        _lost = [for (final l in (data['lost'] as List? ?? const [])) '$l'];
      }
      _step = 2;
    });
  }

  bool get _canApply {
    if (_busy) return false;
    if (_lost.isNotEmpty && !_acceptLosses) return false;
    if (_mode == ImportMode.keep) return _resultHtml != null;
    if (_mode == ImportMode.convert) return (_resultBlocks ?? const []).isNotEmpty;
    return _resultBlocks != null;
  }

  /// The SVG modes' blocks, with the artwork uploaded as a picture; null when
  /// the picture could not be made (the error says so and nothing applies).
  Future<List<Map<String, dynamic>>?> _svgContent() async {
    final whole = _mode == ImportMode.artwork;
    final art = whole ? _text.text : _artwork;
    final blocks = whole ? <Map<String, dynamic>>[] : [...?_resultBlocks];
    if (art == null) return blocks;
    final png = await widget.rasterise(art, 1120);
    String? url;
    if (png != null && widget.api != null) {
      final response = await widget.api!.post('/newsletter/import/artwork', {'png': base64Encode(png)});
      if (response.ok) url = (response.data as Map?)?['url'] as String?;
    }
    if (url == null) {
      if (whole) {
        _error = 'The design could not be turned into a picture, so nothing was imported.';
        return null;
      }
      // The explicit fallback: the editable parts still arrive, and the
      // person is told the picture did not.
      _error = 'The artwork could not be turned into a picture, so only the text and buttons were imported.';
      return blocks;
    }
    blocks.insert(0, {'type': 'image', 'src': url, 'alt': _alt});
    return blocks;
  }

  Future<void> _apply() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    List<Map<String, dynamic>>? blocks;
    if (_svg) {
      blocks = await _svgContent();
      if (blocks == null) {
        if (mounted) setState(() => _busy = false);
        return;
      }
    }
    final warning = _error;
    final before = widget.onBeforeApply;
    if (before != null && !await before()) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = 'A restore point could not be saved first, so nothing was changed. Try again.';
      });
      return;
    }
    if (!mounted) return;
    setState(() => _busy = false);
    if (_svg || _mode == ImportMode.convert) {
      widget.onUseBlocks(blocks ?? [...?_resultBlocks]);
    } else {
      widget.onUseHtml(_resultHtml!);
    }
    if (warning != null && mounted) setState(() => _error = warning);
  }

  Future<void> _saveAsNew() async {
    final api = widget.api;
    if (api == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    List<Map<String, dynamic>>? blocks;
    if (_svg) {
      blocks = await _svgContent();
      if (blocks == null) {
        if (mounted) setState(() => _busy = false);
        return;
      }
    }
    final subject = widget.subject.trim().isEmpty ? 'Imported newsletter' : widget.subject.trim();
    final html = !_svg && _mode == ImportMode.keep;
    final response = await api.post('/newsletters', {
      'subject': subject,
      'name': 'Imported ${_fileName ?? 'design'}',
      if (html) 'html_override': _resultHtml else 'blocks': blocks ?? _resultBlocks ?? const [],
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _done = 'Saved as a new draft. This draft was not changed.';
    });
    final id = (response.data as Map?)?['id'] as String?;
    if (id != null) widget.onSavedAsNew?.call(id);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('newsletter-import-panel'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: theme.colorScheme.surface,
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text('Import a design', style: theme.textTheme.titleMedium)),
              IconButton(
                tooltip: 'Close',
                onPressed: widget.onClose,
                icon: const Icon(Icons.close, size: 18),
              ),
            ],
          ),
          _stepper(theme),
          const SizedBox(height: 16),
          ...switch (_step) {
            0 => _sourceStep(theme),
            1 => _modeStep(theme),
            _ => _compareStep(theme),
          },
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              key: const ValueKey('newsletter-import-error'),
              style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.error),
            ),
          ],
          if (_done != null) ...[
            const SizedBox(height: 8),
            Text(_done!, key: const ValueKey('newsletter-import-done')),
          ],
        ],
      ),
    );
  }

  Widget _stepper(ThemeData theme) {
    const names = ['Source', 'Mode', 'Compare', 'Apply'];
    final current = _step >= 2 ? 2 : _step;
    return Wrap(
      key: const ValueKey('newsletter-import-steps'),
      spacing: 16,
      children: [
        for (var i = 0; i < names.length; i++)
          Text(
            '${i + 1}. ${names[i]}',
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: i == current ? FontWeight.w700 : FontWeight.w400,
              color: i <= current ? theme.colorScheme.onSurface : theme.colorScheme.onSurfaceVariant,
            ),
          ),
      ],
    );
  }

  List<Widget> _sourceStep(ThemeData theme) => [
    SegmentedButton<ImportSource>(
      key: const ValueKey('newsletter-import-source-kind'),
      segments: const [
        ButtonSegment(value: ImportSource.paste, label: Text('Paste HTML'), icon: Icon(Icons.content_paste, size: 16)),
        ButtonSegment(value: ImportSource.file, label: Text('Upload HTML file'), icon: Icon(Icons.upload_file, size: 16)),
        ButtonSegment(value: ImportSource.svg, label: Text('SVG or artwork'), icon: Icon(Icons.image_outlined, size: 16)),
      ],
      selected: {_source},
      showSelectedIcon: false,
      onSelectionChanged: (s) => setState(() {
        _source = s.first;
        _text.clear();
        _fileName = null;
        _clearResult();
      }),
    ),
    const SizedBox(height: 8),
    DashboardNote(switch (_source) {
      ImportSource.paste => 'Paste the whole email’s HTML.',
      ImportSource.file => 'Choose an .html file exported from another email tool.',
      ImportSource.svg => 'Choose or paste an SVG design. Its text can become blocks; shapes and pictures become one image.',
    }),
    const SizedBox(height: 8),
    if (_source != ImportSource.paste)
      Align(
        alignment: Alignment.centerLeft,
        child: OutlinedButton.icon(
          key: const ValueKey('newsletter-import-pick'),
          onPressed: _busy ? null : _pick,
          icon: const Icon(Icons.upload_file, size: 16),
          label: Text(_fileName ?? (_svg ? 'Choose .svg file' : 'Choose .html file')),
        ),
      ),
    const SizedBox(height: 8),
    TextField(
      key: const ValueKey('newsletter-import-source'),
      controller: _text,
      minLines: 4,
      maxLines: 8,
      style: const TextStyle(fontFamily: 'monospace', fontSize: 13),
      decoration: InputDecoration(hintText: _svg ? '<svg …>' : '<html>…</html>'),
      onChanged: (_) => setState(_clearResult),
    ),
    const SizedBox(height: 8),
    Align(
      alignment: Alignment.centerRight,
      child: FilledButton(
        key: const ValueKey('newsletter-import-next'),
        onPressed: _busy ? null : _next,
        child: const Text('Next: choose how'),
      ),
    ),
  ];

  List<Widget> _modeStep(ThemeData theme) {
    final options = _svg
        ? const [
            (ImportMode.svgBlocks, 'Text as blocks, artwork as a picture',
                'Text and links become blocks you can edit. Shapes and pictures become one image.'),
            (ImportMode.artwork, 'Use the whole design as one picture',
                'Looks exactly like the design. Nothing stays editable, and the words are only in the picture.'),
          ]
        : const [
            (ImportMode.keep, 'Keep the HTML as written',
                'Sends exactly as it is. Edit it only as HTML; the blocks editor is not used.'),
            (ImportMode.convert, 'Convert compatible HTML to blocks',
                'Headings, paragraphs, pictures, buttons, dividers and simple columns become blocks you can edit. '
                    'Anything else is kept as an HTML block or listed as lost before you apply.'),
          ];
    return [
      RadioGroup<ImportMode>(
        groupValue: _mode,
        onChanged: (m) => setState(() => _mode = m ?? _mode),
        child: Column(
          children: [
            for (final (mode, title, note) in options)
              RadioListTile<ImportMode>(
                key: ValueKey('newsletter-import-mode-${mode.name}'),
                value: mode,
                contentPadding: EdgeInsets.zero,
                title: Text(title),
                subtitle: Text(note),
              ),
          ],
        ),
      ),
      const SizedBox(height: 8),
      Row(
        children: [
          TextButton(
            onPressed: _busy ? null : () => setState(() => _step = 0),
            child: const Text('Back'),
          ),
          const Spacer(),
          FilledButton(
            key: const ValueKey('newsletter-import-check'),
            onPressed: _busy ? null : _compare,
            child: Text(_busy ? 'Checking…' : 'Check and compare'),
          ),
        ],
      ),
    ];
  }

  List<Widget> _compareStep(ThemeData theme) {
    final width = _phone ? 375.0 : 600.0;
    Widget frame(String label, Widget child) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Container(
          height: 360,
          decoration: BoxDecoration(border: Border.all(color: theme.colorScheme.outlineVariant)),
          child: Center(child: SizedBox(width: width, child: child)),
        ),
      ],
    );

    final original = _svg
        ? SvgPicture.string(_text.text, fit: BoxFit.contain)
        : MessageHtmlView(html: _text.text, ownHtml: true);
    final Widget result;
    if (_svg && _mode == ImportMode.artwork) {
      result = SvgPicture.string(_text.text, fit: BoxFit.contain);
    } else if (_svg) {
      result = SingleChildScrollView(
        child: Column(
          children: [
            if (_artwork != null) SizedBox(height: 120, child: SvgPicture.string(_artwork!, fit: BoxFit.contain)),
            NewsletterEmail(blocks: _resultBlocks ?? const [], company: '', phone: _phone),
          ],
        ),
      );
    } else {
      result = MessageHtmlView(html: _previewHtml ?? _resultHtml ?? '', ownHtml: true);
    }

    return [
      Align(
        alignment: Alignment.centerLeft,
        child: SegmentedButton<bool>(
          key: const ValueKey('newsletter-import-device'),
          segments: const [
            ButtonSegment(value: false, label: Text('Desktop'), icon: Icon(Icons.desktop_windows_outlined, size: 16)),
            ButtonSegment(value: true, label: Text('Phone'), icon: Icon(Icons.smartphone, size: 16)),
          ],
          selected: {_phone},
          showSelectedIcon: false,
          onSelectionChanged: (s) => setState(() => _phone = s.first),
        ),
      ),
      const SizedBox(height: 8),
      LayoutBuilder(
        builder: (context, box) {
          final a = frame('Original', original);
          final b = frame('Result', result);
          if (box.maxWidth < 900) {
            return Column(children: [a, const SizedBox(height: 8), b]);
          }
          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [Expanded(child: a), const SizedBox(width: 12), Expanded(child: b)],
          );
        },
      ),
      const SizedBox(height: 12),
      Column(
        key: const ValueKey('newsletter-import-issues'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _issues.isEmpty ? 'No issues found.' : '${_issues.length} to know before applying',
            style: theme.textTheme.titleSmall,
          ),
          for (final i in _issues)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    i.kind == 'asset' || i.kind == 'unsupported' || i.kind == 'malformed' || i.kind == 'nothing'
                        ? Icons.warning_amber_rounded
                        : Icons.info_outline,
                    size: 16,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  const SizedBox(width: 6),
                  Expanded(child: Text(i.message)),
                ],
              ),
            ),
        ],
      ),
      if (_lost.isNotEmpty) ...[
        const SizedBox(height: 8),
        Text('Not converted (lost if you apply)', style: theme.textTheme.titleSmall),
        for (final l in _lost) Text('• $l', key: const ValueKey('newsletter-import-lost')),
        CheckboxListTile(
          key: const ValueKey('newsletter-import-accept-losses'),
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _acceptLosses,
          onChanged: (v) => setState(() => _acceptLosses = v ?? false),
          title: const Text('I accept these losses'),
        ),
      ],
      const SizedBox(height: 8),
      Wrap(
        alignment: WrapAlignment.end,
        spacing: 8,
        runSpacing: 8,
        children: [
          TextButton(
            onPressed: _busy ? null : () => setState(() => _step = 1),
            child: const Text('Back'),
          ),
          if (widget.api != null)
            OutlinedButton(
              key: const ValueKey('newsletter-import-new'),
              onPressed: _canApply ? _saveAsNew : null,
              child: const Text('Save as new draft'),
            ),
          FilledButton(
            key: const ValueKey('newsletter-import-use'),
            onPressed: _canApply ? _apply : null,
            child: const Text('Apply to this draft'),
          ),
        ],
      ),
      const SizedBox(height: 4),
      const DashboardNote('Applying keeps the current version in History as “Before import”, so it can be restored.'),
    ];
  }
}
