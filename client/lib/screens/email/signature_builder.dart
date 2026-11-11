import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/search_picker.dart';
import 'message_html.dart';

/// Email → Google Workspace → a signature template, built without HTML
/// (Jose, 2026-11-09: "feels pretty lacking"; he must be able to rebuild
/// A-1's signature and give it to everyone).
///
/// A design is plain JSON, kept with the template so the builder can reopen
/// it, and [compileSignature] turns it into the HTML that is filled per person
/// and pushed to Gmail. The preview asks the server to fill that same HTML,
/// so what is shown is what is sent. Editing the code by hand drops the
/// design: from then on the template is code.
const Map<String, dynamic> kDefaultSignatureDesign = {
  'layout': 'photo_left', // photo_left | photo_right | none
  'divider': true,
  'divider_color': '#e6e6e6',
  'font': 'Arial,Helvetica,sans-serif',
  'align': 'left',
  'gap': 18,
  'text_color': '#4a4a4a',
  'link_color': '#f49321',
  'photo': {
    'size': 84,
    'round': true,
    'ring_color': '#f49321',
    'ring_width': 3,
    // Used for anyone without a photo of their own; {{email}} is filled.
    'fallback_url': '',
  },
  'logo_url': '',
  'name': {'size': 17, 'color': '#1a1a1a', 'bold': true},
  'title': {'size': 13, 'color': '#f49321', 'bold': true},
  'contact_size': 12,
  'line_height': 20,
  'company_phone': '',
  'lines': {
    'email': true,
    'phone': false,
    'mobile': true,
    'office_phone': false,
    'extension': true,
    'address': false,
  },
  'separator': ' · ',
  'website': '',
  'banner': {
    'show': false,
    'text': '',
    'highlight': '',
    'background': '#1a1a1a',
    'color': '#ffffff',
    'highlight_color': '#f49321',
    'radius': 4,
    'size': 11,
  },
  'socials': {'linkedin': '', 'facebook': '', 'instagram': ''},
  'disclaimer': {'text': '', 'size': 10, 'color': '#9a9a9a'},
};

/// A-1's signature as it was in Gmail on 2026-11-09, as a design.
Map<String, dynamic> a1StandardDesign() => mergeDesign({
  'company_phone': '(888) 984-4344',
  'website': 'www.a-1chimney.com',
  'photo': {
    'fallback_url':
        'https://www.a-1chimney.com/signatures/photos/{{email}}.jpg',
  },
  'banner': {
    'show': true,
    'text': "A-1 Is Not Just Our Name, It's Our Standard",
    'highlight': 'A-1',
  },
});

/// [design] over the defaults, one level deep, so an old design gains new
/// settings with their default values.
Map<String, dynamic> mergeDesign(Map? design) {
  final out = jsonDecode(jsonEncode(kDefaultSignatureDesign)) as Map;
  for (final entry in (design ?? const {}).entries) {
    final base = out[entry.key];
    if (base is Map && entry.value is Map) {
      out[entry.key] = {...base, ...(entry.value as Map)};
    } else {
      out[entry.key] = entry.value;
    }
  }
  return out.cast<String, dynamic>();
}

String _esc(Object? v) => const HtmlEscape().convert('${v ?? ''}');
int _n(Object? v, int fallback) => v is num ? v.toInt() : fallback;
String _c(Object? v, String fallback) {
  final s = '${v ?? ''}'.trim();
  return RegExp(r'^#[0-9a-fA-F]{3,8}$').hasMatch(s) ? s : fallback;
}

String _url(String value) {
  final v = value.trim();
  if (v.isEmpty) return '';
  return v.startsWith('http') ? v : 'https://$v';
}

/// The HTML a [design] makes: tables and inline styles, which is what Gmail
/// keeps. `{{#key}}…{{/key}}` is dropped by the server when that person has
/// no value for it, and `{{^key}}…{{/key}}` only then.
String compileSignature(Map<String, dynamic> design) {
  final d = mergeDesign(design);
  final font = _esc(d['font']);
  final text = _c(d['text_color'], '#4a4a4a');
  final link = _c(d['link_color'], '#f49321');
  final size = _n(d['contact_size'], 12);
  final lh = _n(d['line_height'], 20);
  final gap = _n(d['gap'], 18);
  final align = d['align'] == 'center' ? 'center' : 'left';
  final photo = (d['photo'] as Map).cast<String, dynamic>();
  final px = _n(photo['size'], 84);
  final ring = _n(photo['ring_width'], 0);
  final radius = photo['round'] == true ? px ~/ 2 : 6;
  final layout = '${d['layout']}';
  final lines = (d['lines'] as Map).cast<String, dynamic>();
  final sep = _esc(d['separator']);

  String style(Map m, int s, String c) =>
      'font-size:${_n(m['size'], s)}px;line-height:${_n(m['size'], s) + 5}px;'
      '${m['bold'] == true ? 'font-weight:bold;' : ''}'
      'color:${_c(m['color'], c)}';

  String img(String src) =>
      '<img src="$src" width="$px" height="$px" alt="{{name}}" '
      'style="display:block;width:${px}px;height:${px}px;border-radius:${radius}px'
      '${ring > 0 ? ';border:${ring}px solid ${_c(photo['ring_color'], link)}' : ''}">';

  final fallback = '${photo['fallback_url'] ?? ''}'.trim();
  final photoCell = layout == 'none'
      ? ''
      : '{{#photo_url}}${img('{{photo_url}}')}{{/photo_url}}'
            '${fallback.isEmpty ? '' : '{{^photo_url}}${img(_esc(fallback))}{{/photo_url}}'}';
  final hasPhoto = layout != 'none';
  String photoTd(String pad) =>
      '<td style="padding:$pad;vertical-align:top">$photoCell</td>';
  final divider = hasPhoto && d['divider'] == true
      ? '<td style="padding:0;width:1px;background:${_c(d['divider_color'], '#e6e6e6')};font-size:0;line-height:0">&nbsp;</td>'
      : '';

  final a = 'style="color:$text;text-decoration:none"';
  final contact = <String>[
    if ('${d['company_phone']}'.trim().isNotEmpty)
      '<a href="tel:${_esc('${d['company_phone']}'.replaceAll(RegExp(r'[^0-9+]'), ''))}" $a>${_esc(d['company_phone'])}</a>'
          '${lines['extension'] == true ? '{{#extension}} ext. {{extension}}{{/extension}}' : ''}',
    if (lines['email'] == true) '<a href="mailto:{{email}}" $a>{{email}}</a>',
  ];
  final optional = [
    for (final (key, label) in const [
      ('phone', 'P'),
      ('mobile', 'M'),
      ('office_phone', 'O'),
    ])
      if (lines[key] == true)
        '{{#$key}}${contact.isEmpty ? '' : sep}$label <a href="tel:{{$key}}" $a>{{$key}}</a>{{/$key}}',
  ];

  final website = '${d['website'] ?? ''}'.trim();
  final socials = [
    for (final (key, label) in const [
      ('linkedin', 'LinkedIn'),
      ('facebook', 'Facebook'),
      ('instagram', 'Instagram'),
    ])
      if ('${(d['socials'] as Map)[key] ?? ''}'.trim().isNotEmpty)
        '<a href="${_esc(_url('${(d['socials'] as Map)[key]}'))}" style="color:$link;text-decoration:none">$label</a>',
  ];

  final body = StringBuffer()
    ..write(
      '<div style="${style(d['name'] as Map, 17, '#1a1a1a')}">{{name}}</div>',
    )
    ..write(
      '{{#title}}<div style="${style(d['title'] as Map, 13, link)}">{{title}}</div>{{/title}}',
    );
  if (contact.isNotEmpty || optional.isNotEmpty) {
    body.write(
      '<div style="font-size:${size}px;line-height:${lh}px;color:$text;padding-top:8px">'
      '${contact.join(sep)}${optional.join()}</div>',
    );
  }
  if (lines['address'] == true) {
    body.write(
      '{{#address}}<div style="font-size:${size}px;line-height:${lh - 2}px;color:$text">{{address}}</div>{{/address}}',
    );
  }
  if (website.isNotEmpty) {
    body.write(
      '<div style="font-size:${size}px;line-height:${lh - 2}px">'
      '<a href="${_esc(_url(website))}" style="color:$link;text-decoration:none;font-weight:bold">${_esc(website)}</a></div>',
    );
  }
  if (socials.isNotEmpty) {
    body.write(
      '<div style="font-size:${size - 1}px;line-height:${lh - 2}px">${socials.join(sep)}</div>',
    );
  }
  if ('${d['logo_url']}'.trim().isNotEmpty) {
    body.write(
      '<div style="padding-top:8px"><img src="${_esc(d['logo_url'])}" alt="${_esc(d['website'])}" style="display:block;max-height:40px"></div>',
    );
  }

  final textTd =
      '<td style="padding:${layout == 'photo_left'
          ? '0 0 0 ${gap}px'
          : layout == 'photo_right'
          ? '0 ${gap}px 0 0'
          : '0'};vertical-align:top;text-align:$align">$body</td>';

  final cells = switch (layout) {
    'photo_left' => [photoTd('0 ${gap}px 0 0'), divider, textTd],
    'photo_right' => [textTd, divider, photoTd('0 0 0 ${gap}px')],
    _ => [textTd],
  }.where((c) => c.isNotEmpty).toList();

  final out = StringBuffer(
    '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:$font">'
    '<tr>${cells.join()}</tr>',
  );

  final banner = (d['banner'] as Map).cast<String, dynamic>();
  final bannerText = '${banner['text'] ?? ''}';
  if (banner['show'] == true && bannerText.trim().isNotEmpty) {
    final hl = '${banner['highlight'] ?? ''}';
    var inner = _esc(bannerText);
    if (hl.isNotEmpty && bannerText.contains(hl)) {
      inner = inner.replaceFirst(
        _esc(hl),
        '<strong style="color:${_c(banner['highlight_color'], link)}">${_esc(hl)}</strong>',
      );
    }
    out.write(
      '<tr><td colspan="${cells.length}" style="padding:14px 0 0 0">'
      '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse"><tr>'
      '<td style="background:${_c(banner['background'], '#1a1a1a')};padding:9px 16px;border-radius:${_n(banner['radius'], 4)}px;text-align:$align">'
      '<span style="font-family:$font;font-size:${_n(banner['size'], 11)}px;line-height:16px;color:${_c(banner['color'], '#ffffff')};letter-spacing:.4px">$inner</span>'
      '</td></tr></table></td></tr>',
    );
  }

  final disclaimer = (d['disclaimer'] as Map).cast<String, dynamic>();
  if ('${disclaimer['text'] ?? ''}'.trim().isNotEmpty) {
    out.write(
      '<tr><td colspan="${cells.length}" style="padding:10px 0 0 0;font-size:${_n(disclaimer['size'], 10)}px;line-height:14px;color:${_c(disclaimer['color'], '#9a9a9a')}">${_esc(disclaimer['text'])}</td></tr>',
    );
  }

  out.write('</table>');
  return out.toString();
}

/// The builder: a template's name, its design (or code), and a preview of it
/// filled for one person, the way Gmail will show it.
class SignatureBuilder extends StatefulWidget {
  const SignatureBuilder({
    super.key,
    required this.api,
    required this.base,
    required this.people,
    required this.maxLength,
    required this.onSaved,
    this.template,
    this.seed,
  });

  final ApiClient api;

  /// `/email/workspaces/{id}`.
  final String base;
  final List<Map<String, dynamic>> people;
  final int maxLength;

  /// The template being edited; null for a new one.
  final Map<String, dynamic>? template;

  /// A design to start a new template from.
  final Map<String, dynamic>? seed;
  final Future<void> Function() onSaved;

  @override
  State<SignatureBuilder> createState() => _SignatureBuilderState();
}

class _SignatureBuilderState extends State<SignatureBuilder> {
  late final TextEditingController _name;
  late final TextEditingController _code;
  Map<String, dynamic>? _design;
  bool _codeView = false;
  bool _busy = false;
  String? _note;

  String? _previewFor;
  String? _previewHtml;
  bool _dark = false;
  bool _mobile = false;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    final t = widget.template;
    _name = TextEditingController(text: '${t?['name'] ?? ''}');
    final saved = t?['design'];
    _design = saved is Map
        ? mergeDesign(saved)
        : t == null
        ? mergeDesign(widget.seed)
        : null;
    _codeView = _design == null;
    _code = TextEditingController(
      text: _design == null
          ? '${t?['html'] ?? ''}'
          : compileSignature(_design!),
    );
    _code.addListener(_codeChanged);
    _previewFor = widget.people
        .where((p) => p['suspended'] != true)
        .map((p) => '${p['address']}')
        .firstOrNull;
    _schedule();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _name.dispose();
    _code.dispose();
    super.dispose();
  }

  String get _html => _design == null ? _code.text : compileSignature(_design!);

  void _codeChanged() {
    // Typing in the code view makes the template code from now on.
    if (_codeView &&
        _design != null &&
        _code.text != compileSignature(_design!)) {
      setState(() => _design = null);
    }
    _schedule();
  }

  void _set(String path, Object? value) {
    setState(() {
      final parts = path.split('.');
      final d = _design!;
      if (parts.length == 1) {
        d[parts[0]] = value;
      } else {
        d[parts[0]] = {...(d[parts[0]] as Map), parts[1]: value};
      }
      _code.removeListener(_codeChanged);
      _code.text = compileSignature(d);
      _code.addListener(_codeChanged);
    });
    _schedule();
  }

  void _schedule() {
    _timer?.cancel();
    _timer = Timer(const Duration(milliseconds: 350), _preview);
  }

  Future<void> _preview() async {
    final address = _previewFor;
    if (address == null || _html.trim().isEmpty) return;
    final response = await widget.api.post(
      '${widget.base}/signatures/preview',
      {'address': address, 'html': _html},
    );
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    setState(() => _previewHtml = data is Map ? data['html'] as String? : null);
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _note = 'Give the template a name first.');
      return;
    }
    setState(() => _busy = true);
    final body = {'name': name, 'html': _html, 'design': _design};
    final id = widget.template?['id'];
    final response = id == null
        ? await widget.api.post('${widget.base}/signature-templates', body)
        : await widget.api.put('${widget.base}/signature-templates/$id', body);
    if (!mounted) return;
    setState(() {
      _busy = false;
      _note = response.ok
          ? 'Saved. Nobody gets it until you assign it and set it.'
          : response.reason;
    });
    if (response.ok) await widget.onSaved();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final length = _html.length;
    final over = length > widget.maxLength;

    final controls = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('sig-name'),
          controller: _name,
          decoration: const InputDecoration(labelText: 'Template name'),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          children: [
            ChoiceChip(
              key: const ValueKey('sig-view-builder'),
              label: const Text('Builder'),
              selected: !_codeView,
              onSelected: (_) => setState(() {
                _codeView = false;
                _design ??= mergeDesign(null);
                _code.removeListener(_codeChanged);
                _code.text = compileSignature(_design!);
                _code.addListener(_codeChanged);
                _schedule();
              }),
            ),
            ChoiceChip(
              key: const ValueKey('sig-view-code'),
              label: const Text('Code'),
              selected: _codeView,
              onSelected: (_) => setState(() => _codeView = true),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_codeView)
          _codeEditor(theme)
        else
          _DesignControls(design: _design!, set: _set),
      ],
    );

    final preview = _PreviewFrame(
      html: _previewHtml,
      dark: _dark,
      mobile: _mobile,
      people: widget.people,
      previewFor: _previewFor,
      onPerson: (a) {
        setState(() => _previewFor = a);
        _schedule();
      },
      onDark: (v) => setState(() => _dark = v),
      onMobile: (v) => setState(() => _mobile = v),
    );

    final footer = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$length of ${widget.maxLength} characters (Gmail refuses a longer '
          'signature). Pictures must be at a public https address: Gmail '
          'fetches them for every reader.',
          key: const ValueKey('sig-limits'),
          style: theme.textTheme.bodySmall?.copyWith(
            color: over ? theme.colorScheme.error : null,
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            FilledButton(
              key: const ValueKey('sig-save'),
              onPressed: _busy || over ? null : _save,
              child: const Text('Save template'),
            ),
            if (_note != null) ...[
              const SizedBox(width: 12),
              Flexible(
                child: Text(
                  _note!,
                  key: const ValueKey('sig-note'),
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ],
          ],
        ),
      ],
    );

    return LayoutBuilder(
      builder: (context, c) {
        if (c.maxWidth < 900) {
          // Saving and the preview first: the settings run long.
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              footer,
              const SizedBox(height: 16),
              preview,
              const SizedBox(height: 16),
              controls,
            ],
          );
        }
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            footer,
            const SizedBox(height: 16),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(flex: 2, child: controls),
                const SizedBox(width: 20),
                Expanded(flex: 3, child: preview),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _codeEditor(ThemeData theme) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      if (_design != null)
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Text(
            'This is the code the builder makes. Changing it here makes the '
            'template code from then on.',
            style: theme.textTheme.bodySmall,
          ),
        ),
      TextField(
        key: const ValueKey('sig-code'),
        controller: _code,
        minLines: 8,
        maxLines: 18,
        style: theme.textTheme.bodySmall?.copyWith(fontFamily: 'monospace'),
        decoration: const InputDecoration(border: OutlineInputBorder()),
      ),
      const SizedBox(height: 6),
      Text(
        'Fills: {{name}} {{title}} {{email}} {{phone}} {{mobile}} '
        '{{office_phone}} {{extension}} {{photo_url}} {{address}}. '
        '{{#mobile}}…{{/mobile}} shows only when there is one.',
        style: theme.textTheme.bodySmall,
      ),
    ],
  );
}

/// The design's settings, grouped as the signature reads top to bottom.
class _DesignControls extends StatelessWidget {
  const _DesignControls({required this.design, required this.set});

  final Map<String, dynamic> design;
  final void Function(String path, Object? value) set;

  Object? _get(String path) {
    final parts = path.split('.');
    final top = design[parts[0]];
    return parts.length == 1 ? top : (top as Map?)?[parts[1]];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    Widget section(String title, List<Widget> children) => Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: theme.textTheme.titleSmall),
          const SizedBox(height: 6),
          ...children,
        ],
      ),
    );

    Widget text(String path, String label, {String? hint}) => Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: TextFormField(
        key: ValueKey('sig-$path'),
        initialValue: '${_get(path) ?? ''}',
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          isDense: true,
        ),
        onChanged: (v) => set(path, v),
      ),
    );

    Widget colour(String path, String label) => Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: _ColourField(
        key: ValueKey('sig-$path'),
        label: label,
        value: '${_get(path) ?? ''}',
        onChanged: (v) => set(path, v),
      ),
    );

    Widget number(String path, String label, int min, int max) {
      final v = _n(_get(path), min).clamp(min, max);
      return Row(
        children: [
          SizedBox(
            width: 110,
            child: Text(label, style: theme.textTheme.bodySmall),
          ),
          Expanded(
            child: Slider(
              key: ValueKey('sig-$path'),
              value: v.toDouble(),
              min: min.toDouble(),
              max: max.toDouble(),
              divisions: max - min,
              label: '$v',
              onChanged: (x) => set(path, x.round()),
            ),
          ),
          SizedBox(width: 32, child: Text('$v')),
        ],
      );
    }

    Widget toggle(String path, String label) => SwitchListTile(
      key: ValueKey('sig-$path'),
      dense: true,
      contentPadding: EdgeInsets.zero,
      title: Text(label),
      value: _get(path) == true,
      onChanged: (v) => set(path, v),
    );

    Widget choice(String path, List<(String, String)> options) => Wrap(
      spacing: 8,
      runSpacing: 6,
      children: [
        for (final (value, label) in options)
          ChoiceChip(
            key: ValueKey('sig-$path-$value'),
            label: Text(label),
            selected: _get(path) == value,
            onSelected: (_) => set(path, value),
          ),
      ],
    );

    return Column(
      key: const ValueKey('sig-builder'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        section('Layout', [
          choice('layout', const [
            ('photo_left', 'Photo left'),
            ('photo_right', 'Photo right'),
            ('none', 'No photo'),
          ]),
          const SizedBox(height: 6),
          choice('align', const [
            ('left', 'Align left'),
            ('center', 'Centred'),
          ]),
          toggle('divider', 'Line between photo and text'),
          if (design['divider'] == true) colour('divider_color', 'Line colour'),
          number('gap', 'Spacing', 6, 32),
          number('line_height', 'Line height', 14, 28),
          text('font', 'Font', hint: 'Arial,Helvetica,sans-serif'),
        ]),
        if (design['layout'] != 'none')
          section('Photo', [
            Text(
              "Each person's photo is set on their row. Anyone without one gets "
              'the stock picture below, or no photo.',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 6),
            text(
              'photo.fallback_url',
              'Stock picture address',
              hint: 'https://…/{{email}}.jpg',
            ),
            toggle('photo.round', 'Round'),
            number('photo.size', 'Size', 40, 120),
            number('photo.ring_width', 'Ring', 0, 6),
            colour('photo.ring_color', 'Ring colour'),
          ]),
        section('Name and title', [
          number('name.size', 'Name size', 12, 26),
          toggle('name.bold', 'Name bold'),
          colour('name.color', 'Name colour'),
          number('title.size', 'Title size', 10, 20),
          toggle('title.bold', 'Title bold'),
          colour('title.color', 'Title colour'),
        ]),
        section('Contact lines', [
          text('company_phone', 'Company phone (same for everyone)'),
          toggle('lines.extension', "Their extension after it"),
          toggle('lines.email', 'Their email'),
          toggle('lines.phone', 'Their direct phone'),
          toggle('lines.mobile', 'Their mobile'),
          toggle('lines.office_phone', 'Their office phone'),
          toggle('lines.address', 'Their address'),
          text('separator', 'Between items'),
          number('contact_size', 'Text size', 9, 16),
          colour('text_color', 'Text colour'),
          text('website', 'Website'),
          colour('link_color', 'Link colour'),
          text('logo_url', 'Company logo address (optional)'),
        ]),
        section('Banner', [
          toggle('banner.show', 'Show a banner'),
          if ((design['banner'] as Map)['show'] == true) ...[
            text('banner.text', 'Banner text'),
            text('banner.highlight', 'Highlighted words'),
            colour('banner.background', 'Background'),
            colour('banner.color', 'Text colour'),
            colour('banner.highlight_color', 'Highlight colour'),
            number('banner.radius', 'Corner radius', 0, 20),
            number('banner.size', 'Text size', 9, 16),
          ],
        ]),
        section('Social links', [
          text('socials.linkedin', 'LinkedIn'),
          text('socials.facebook', 'Facebook'),
          text('socials.instagram', 'Instagram'),
        ]),
        section('Disclaimer', [
          text('disclaimer.text', 'Disclaimer line (optional)'),
          if ('${(design['disclaimer'] as Map)['text'] ?? ''}'.isNotEmpty) ...[
            number('disclaimer.size', 'Size', 8, 13),
            colour('disclaimer.color', 'Colour'),
          ],
        ]),
      ],
    );
  }
}

/// A #hex colour with a swatch of it.
class _ColourField extends StatelessWidget {
  const _ColourField({
    super.key,
    required this.label,
    required this.value,
    required this.onChanged,
  });

  final String label;
  final String value;
  final ValueChanged<String> onChanged;

  static Color? parse(String hex) {
    var h = hex.trim().replaceFirst('#', '');
    if (h.length == 3) h = h.split('').map((c) => '$c$c').join();
    if (h.length != 6) return null;
    final v = int.tryParse(h, radix: 16);
    return v == null ? null : Color(v | 0xFF000000);
  }

  @override
  Widget build(BuildContext context) {
    final swatch = parse(value);
    return TextFormField(
      initialValue: value,
      decoration: InputDecoration(
        labelText: label,
        isDense: true,
        prefixIcon: Padding(
          padding: const EdgeInsets.all(10),
          child: Container(
            width: 18,
            height: 18,
            decoration: BoxDecoration(
              color: swatch,
              border: Border.all(color: Theme.of(context).dividerColor),
              borderRadius: BorderRadius.circular(4),
            ),
          ),
        ),
      ),
      onChanged: onChanged,
    );
  }
}

/// A Gmail-like compose window with the signature at the bottom, in light or
/// dark, at a desktop or a phone width.
class _PreviewFrame extends StatelessWidget {
  const _PreviewFrame({
    required this.html,
    required this.dark,
    required this.mobile,
    required this.people,
    required this.previewFor,
    required this.onPerson,
    required this.onDark,
    required this.onMobile,
  });

  final String? html;
  final bool dark;
  final bool mobile;
  final List<Map<String, dynamic>> people;
  final String? previewFor;
  final ValueChanged<String?> onPerson;
  final ValueChanged<bool> onDark;
  final ValueChanged<bool> onMobile;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // The mail client's own surface, not the app's: Gmail is white, or
    // near-black in dark mode, whatever EzBizi's theme is.
    final scheme = ColorScheme.fromSeed(
      seedColor: theme.colorScheme.primary,
      brightness: dark ? Brightness.dark : Brightness.light,
    );
    final paper = dark ? scheme.surfaceContainerLowest : scheme.surface;

    return Column(
      key: const ValueKey('sig-preview'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SearchPickerField<String>(
          key: const ValueKey('sig-preview-person'),
          value: previewFor,
          label: 'Preview as',
          isDense: true,
          options: [
            for (final p in people)
              if (p['suspended'] != true)
                SearchOption<String>(
                  value: '${p['address']}',
                  label: '${p['name'] ?? p['address']}',
                  detail: '${p['address']}',
                ),
          ],
          onChanged: onPerson,
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          children: [
            ChoiceChip(
              key: const ValueKey('sig-preview-light'),
              label: const Text('Light'),
              selected: !dark,
              onSelected: (_) => onDark(false),
            ),
            ChoiceChip(
              key: const ValueKey('sig-preview-dark'),
              label: const Text('Dark'),
              selected: dark,
              onSelected: (_) => onDark(true),
            ),
            ChoiceChip(
              key: const ValueKey('sig-preview-desktop'),
              label: const Text('Desktop'),
              selected: !mobile,
              onSelected: (_) => onMobile(false),
            ),
            ChoiceChip(
              key: const ValueKey('sig-preview-mobile'),
              label: const Text('Phone'),
              selected: mobile,
              onSelected: (_) => onMobile(true),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: mobile ? 360 : 640),
            child: Theme(
              data: ThemeData(colorScheme: scheme),
              child: Material(
                color: paper,
                elevation: 2,
                borderRadius: BorderRadius.circular(8),
                clipBehavior: Clip.antiAlias,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Container(
                      color: scheme.surfaceContainerHigh,
                      padding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 8,
                      ),
                      child: Text(
                        'New Message',
                        style: TextStyle(color: scheme.onSurface),
                      ),
                    ),
                    for (final label in const ['To', 'Subject'])
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 8,
                        ),
                        decoration: BoxDecoration(
                          border: Border(
                            bottom: BorderSide(color: scheme.outlineVariant),
                          ),
                        ),
                        child: Text(
                          label,
                          style: TextStyle(color: scheme.outline),
                        ),
                      ),
                    if (html == null)
                      Padding(
                        padding: const EdgeInsets.all(12),
                        child: Text(
                          'Getting the preview…',
                          style: TextStyle(color: scheme.outline),
                        ),
                      )
                    else
                      // A real browser engine, as Gmail is one: a Flutter HTML
                      // renderer stretched the table rows and showed the photo
                      // as its alt text (Jose, 2026-11-10).
                      SizedBox(
                        key: const ValueKey('sig-preview-html'),
                        height: mobile ? 340 : 280,
                        child: MessageHtmlView(
                          html: signaturePreviewPage(html!, dark: dark),
                          ownHtml: true,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// The compose body with the signature under it, as one page for the
/// preview's browser engine. Dark is Gmail's dark: the page darkens, the
/// signature keeps the colours it was written with.
String signaturePreviewPage(String signature, {required bool dark}) =>
    '<!doctype html><html><head><meta charset="utf-8">'
    '<meta name="viewport" content="width=device-width,initial-scale=1">'
    '<style>body{margin:12px;font:14px Arial,Helvetica,sans-serif;'
    'background:${dark ? '#202124' : '#ffffff'};'
    'color:${dark ? '#e8eaed' : '#202124'}}</style></head><body>'
    'Hi,<br><br>Thanks for reaching out.<br><br>--<br>$signature</body></html>';
