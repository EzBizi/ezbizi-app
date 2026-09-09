import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';

/// Web Management → Widgets: how a site's chat bubble and contact form look,
/// and what the form asks.
///
/// ── Why this is a screen and not shortcode arguments ──
///
/// All of it used to be typed into the WordPress shortcode. That meant the
/// person who could change the wording was whoever could edit the page, the
/// change reached one site, and a company with six subdomains set its colours
/// six times — differently, eventually. Everything here ships down with the
/// values, so a change made once reaches every site that follows the primary,
/// with nobody touching WordPress.
class WidgetsTab extends StatefulWidget {
  const WidgetsTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<WidgetsTab> createState() => _WidgetsTabState();
}

class _WidgetsTabState extends State<WidgetsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _sites = const [];
  String? _selected;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    final response = await widget.api.get('/web/sites');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _sites = ((response.body['data'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>();
      _selected ??= _sites.isEmpty ? null : _sites.first['id'] as String?;
    });
  }

  bool get _canEdit => widget.session.me!.can('web_management.edit');

  @override
  Widget build(BuildContext context) {
    return AsyncBody(
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: _sites.isEmpty ? _noSites(context) : _split(context),
    );
  }

  Widget _noSites(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('No websites yet', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(
              'Register a website under SEO first. The widgets are dressed per '
              'site, so there is nothing to dress until there is one.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }

  Widget _split(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 900;

    final list = ListView(
      children: [
        for (final site in _sites)
          ListTile(
            selected: site['id'] == _selected,
            title: Text('${site['host']}'),
            subtitle: site['is_primary'] == true
                ? const Text('Primary')
                : (site['franchise'] is Map
                    ? Text('${(site['franchise'] as Map)['name']}')
                    : null),
            onTap: () => setState(() => _selected = site['id'] as String?),
          ),
      ],
    );

    final detail = _selected == null
        ? const SizedBox.shrink()
        : _WidgetEditor(
            key: ValueKey(_selected),
            api: widget.api,
            siteId: _selected!,
            canEdit: _canEdit,
          );

    if (!wide) {
      return _selected == null ? list : detail;
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(width: 260, child: list),
        const VerticalDivider(width: 1),
        Expanded(child: detail),
      ],
    );
  }
}

/// One site's widget settings.
class _WidgetEditor extends StatefulWidget {
  const _WidgetEditor({
    super.key,
    required this.api,
    required this.siteId,
    required this.canEdit,
  });

  final ApiClient api;
  final String siteId;
  final bool canEdit;

  @override
  State<_WidgetEditor> createState() => _WidgetEditorState();
}

class _WidgetEditorState extends State<_WidgetEditor> {
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _saved;

  /// True when this site dresses itself; false when it follows the primary.
  bool _own = false;

  Map<String, dynamic> _config = {};
  Map<String, dynamic> _inherited = {};

  /// The colour this business publishes under, or null if it has told us none.
  String? _brand;
  List<String> _fieldTypes = const ['text'];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    final response = await widget.api.get('/web/sites/${widget.siteId}/widgets');

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _config = Map<String, dynamic>.from(
          response.body['config'] as Map? ?? const {});
      _inherited = Map<String, dynamic>.from(
          response.body['inherited'] as Map? ?? const {});
      _own = response.body['own'] == true;
      _brand = response.body['brand'] as String?;
      _fieldTypes = ((response.body['field_types'] as List?) ?? const ['text'])
          .map((type) => '$type')
          .toList();
    });
  }

  Map<String, dynamic> _section(String key) =>
      Map<String, dynamic>.from(_config[key] as Map? ?? {});

  void _set(String section, String key, Object? value) {
    setState(() {
      final part = _section(section);
      part[key] = value;
      _config[section] = part;
      _saved = null;
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api
        .put('/web/sites/${widget.siteId}/widgets', {'config': _config});

    if (!mounted) return;

    setState(() {
      _saving = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _config = Map<String, dynamic>.from(
          response.body['config'] as Map? ?? const {});
      _own = response.body['own'] == true;

      _saved = 'Saved. Every site that follows this one changes with it.';
    });
  }

  Future<void> _followPrimary() async {
    setState(() => _saving = true);

    final response =
        await widget.api.delete('/web/sites/${widget.siteId}/widgets');

    if (!mounted) return;

    setState(() {
      _saving = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _config = Map<String, dynamic>.from(
          response.body['config'] as Map? ?? const {});
      _own = response.body['own'] == true;

      _saved = 'Following the primary website again.';
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    final chat = _section('chat');
    final form = _section('form');
    final style = _section('style');

    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        if (!_own)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(
              'Following the primary website. Change anything here and this '
              'site starts answering for itself.',
              style: theme.textTheme.bodySmall,
            ),
          ),

        Text('Live chat', style: theme.textTheme.titleSmall),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: const Text('Show the chat bubble'),
          value: chat['enabled'] == true,
          onChanged: widget.canEdit
              ? (on) => _set('chat', 'enabled', on)
              : null,
        ),
        _text(theme, 'Title', chat['title'], (v) => _set('chat', 'title', v)),
        _text(theme, 'Opening line', chat['intro'], (v) => _set('chat', 'intro', v)),
        _text(theme, 'Bubble label', chat['button'], (v) => _set('chat', 'button', v)),
        _text(theme, 'Box placeholder', chat['placeholder'],
            (v) => _set('chat', 'placeholder', v)),
        _dropdown(
          theme,
          'Where it sits',
          '${chat['position'] ?? 'bottom-right'}',
          const ['bottom-right', 'bottom-left', 'inline'],
          (v) => _set('chat', 'position', v),
        ),

        const Divider(height: 32),
        Text('Contact form', style: theme.textTheme.titleSmall),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: const Text('Show the contact form'),
          value: form['enabled'] == true,
          onChanged: widget.canEdit
              ? (on) => _set('form', 'enabled', on)
              : null,
        ),
        _text(theme, 'Button', form['button'], (v) => _set('form', 'button', v)),
        _text(theme, 'Thank you message', form['thanks'],
            (v) => _set('form', 'thanks', v)),
        const SizedBox(height: 12),
        _FieldList(
          fields: (form['fields'] as List? ?? const [])
              .whereType<Map>()
              .map((f) => Map<String, dynamic>.from(f))
              .toList(),
          types: _fieldTypes,
          canEdit: widget.canEdit,
          onChanged: (fields) => _set('form', 'fields', fields),
        ),

        const Divider(height: 32),
        Text('Look', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          _brand == null
              // Said plainly rather than hidden: a business that has told us no
              // colour is getting OURS on THEIR website, and the person who
              // would mind is the one least likely to be reading this screen.
              ? 'These widgets appear on your own website, so they start from '
                  'your brand colour — you have not set one, so they are using '
                  'a neutral blue until you do.'
              : 'Starting from your brand colour, $_brand. Change anything here '
                  'and this site keeps its own instead.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 4),
        Text(
          'Shared by both widgets — a site that sets its accent means it for '
          'the chat and the form.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        _text(theme, 'Accent colour (#rrggbb)', style['accent'],
            (v) => _set('style', 'accent', v)),
        _text(theme, 'Text on the accent', style['on_accent'],
            (v) => _set('style', 'on_accent', v)),
        _pair(theme, style['on_accent'], style['accent'], 'the accent'),
        _text(theme, 'Text colour', style['text'], (v) => _set('style', 'text', v)),
        _text(theme, 'Background', style['background'],
            (v) => _set('style', 'background', v)),
        _pair(theme, style['text'], style['background'], 'the background'),
        _text(theme, 'Font family', style['font'], (v) => _set('style', 'font', v)),
        _text(theme, 'Corner radius', '${style['radius'] ?? 12}',
            (v) => _set('style', 'radius', int.tryParse(v) ?? 12)),

        const SizedBox(height: 16),
        if (_error != null) ErrorNote(_error!),
        if (_saved != null)
          Text(_saved!, style: theme.textTheme.bodySmall),
        const SizedBox(height: 8),
        if (widget.canEdit)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              if (_own && _inherited.isNotEmpty)
                TextButton(
                  onPressed: _saving ? null : _followPrimary,
                  child: const Text('Follow the primary again'),
                ),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save'),
              ),
            ],
          ),
      ],
    );
  }

  /// What a visitor will actually see: [fg] rendered on [bg], with the WCAG
  /// contrast of the pair beside it.
  ///
  /// Nothing else on this screen shows the colours rendered, so the two boxes
  /// above are picked blind — and the pair that matters is a *relationship*,
  /// which no single field can be wrong about on its own.
  ///
  /// Derived here and never stored. The ratio is a fact about the two values
  /// beside it, so a saved copy is a copy that can disagree with them.
  ///
  /// **Warn, never block.** A customer's brand may genuinely be low-contrast,
  /// and this is their website, not ours. Saying what a visitor will see is
  /// this screen's job; overruling them is not.
  Widget _pair(ThemeData theme, Object? fg, Object? bg, String what) {
    final f = _colour(fg), b = _colour(bg);
    // Mid-typing (`#0b5`, `#`, empty) is not a finding. Say nothing until both
    // boxes hold a colour the server would actually keep.
    if (f == null || b == null) return const SizedBox.shrink();

    final lf = f.computeLuminance(), lb = b.computeLuminance();
    final ratio = ((lf > lb ? lf : lb) + 0.05) / ((lf < lb ? lf : lb) + 0.05);
    final low = ratio < 4.5;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: b,
              borderRadius: BorderRadius.circular(6),
              // The swatch has to read as a swatch even when the colour it
              // carries is the page's own, or a white-on-white pair shows as
              // nothing at all and looks like a rendering fault.
              border: Border.all(color: theme.dividerColor),
            ),
            child: Text('Send', style: TextStyle(fontSize: 13, color: f)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              low
                  ? 'Contrast against $what is ${ratio.toStringAsFixed(1)}:1. '
                      'Visitors may not be able to read this; 4.5:1 or more is '
                      'the usual floor.'
                  : 'Contrast against $what is ${ratio.toStringAsFixed(1)}:1.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: low ? theme.colorScheme.error : null,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Both hex forms the server keeps (`#rgb` and `#rrggbb`), so a colour that
  /// saves is a colour that gets checked — see `WidgetDefaults::colour`.
  static Color? _colour(Object? v) {
    final m = RegExp(r'^#([0-9a-f]{3}|[0-9a-f]{6})$')
        .firstMatch('${v ?? ''}'.trim().toLowerCase());
    if (m == null) return null;
    final h = m.group(1)!;
    final full = h.length == 3 ? h.split('').map((c) => '$c$c').join() : h;
    return Color(int.parse('ff$full', radix: 16));
  }

  Widget _text(
    ThemeData theme,
    String label,
    Object? value,
    void Function(String) onChanged,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextFormField(
        // Keyed by label so a site swap rebuilds rather than keeping the
        // previous site's text in the box.
        key: ValueKey('$label:${widget.siteId}'),
        initialValue: '${value ?? ''}',
        enabled: widget.canEdit,
        style: theme.textTheme.bodyMedium,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: onChanged,
      ),
    );
  }

  Widget _dropdown(
    ThemeData theme,
    String label,
    String value,
    List<String> options,
    void Function(String) onChanged,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: DropdownButtonFormField<String>(
        initialValue: options.contains(value) ? value : options.first,
        decoration: InputDecoration(labelText: label, isDense: true),
        items: [
          for (final option in options)
            DropdownMenuItem(value: option, child: Text(option)),
        ],
        onChanged: widget.canEdit
            ? (chosen) => chosen == null ? null : onChanged(chosen)
            : null,
      ),
    );
  }
}

/// The questions the form asks, in the order it asks them.
class _FieldList extends StatelessWidget {
  const _FieldList({
    required this.fields,
    required this.types,
    required this.canEdit,
    required this.onChanged,
  });

  final List<Map<String, dynamic>> fields;
  final List<String> types;
  final bool canEdit;
  final void Function(List<Map<String, dynamic>>) onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Questions', style: theme.textTheme.bodyMedium),
        const SizedBox(height: 4),
        Text(
          'Name, Email and Phone are recognised and fill in the enquirer’s '
          'details. Anything else becomes a line of the message.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        for (var i = 0; i < fields.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  flex: 3,
                  child: TextFormField(
                    key: ValueKey('label:$i:${fields[i]['label']}'),
                    initialValue: '${fields[i]['label'] ?? ''}',
                    enabled: canEdit,
                    decoration: const InputDecoration(
                      labelText: 'Question',
                      isDense: true,
                    ),
                    onChanged: (value) => _replace(i, 'label', value),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  flex: 2,
                  child: DropdownButtonFormField<String>(
                    initialValue: types.contains(fields[i]['type'])
                        ? '${fields[i]['type']}'
                        : types.first,
                    decoration: const InputDecoration(
                      labelText: 'Answer',
                      isDense: true,
                    ),
                    items: [
                      for (final type in types)
                        DropdownMenuItem(value: type, child: Text(type)),
                    ],
                    onChanged: canEdit
                        ? (value) =>
                            value == null ? null : _replace(i, 'type', value)
                        : null,
                  ),
                ),
                IconButton(
                  tooltip: 'Remove',
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: canEdit ? () => _removeAt(i) : null,
                ),
              ],
            ),
          ),
        if (canEdit)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => onChanged([
                ...fields,
                {'label': '', 'type': 'text', 'required': false},
              ]),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add a question'),
            ),
          ),
      ],
    );
  }

  void _replace(int index, String key, Object? value) {
    final next = [for (final field in fields) Map<String, dynamic>.from(field)];
    next[index][key] = value;
    onChanged(next);
  }

  void _removeAt(int index) {
    final next = [for (final field in fields) Map<String, dynamic>.from(field)];
    next.removeAt(index);
    onChanged(next);
  }
}
