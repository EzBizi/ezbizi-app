import 'dart:convert';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/dashboard_card.dart';
import 'newsletter_blocks.dart';

/// Newsletter P1b (2026-11-18): the Brand and Saved tabs of the Design
/// panel, beside Add content.
///
/// Brand edits the company's brand (`/newsletter/brand`) and applies it to
/// the open newsletter as a snapshot: the renderer fills only what a block
/// does not set itself. Saved lists the company's saved sections and the
/// code-defined starters, and inserts one as a copy (independent blocks) or
/// linked (a `section_ref` block pinning the section's version).

/// The brand fields the editor offers, with a label each.
const brandColorFields = {
  'primary': 'Primary',
  'text': 'Text',
  'background': 'Background',
  'link': 'Links',
  'button_fill': 'Button fill',
  'button_text': 'Button text',
};

const brandFonts = {
  'system': 'System sans',
  'arial': 'Arial',
  'verdana': 'Verdana',
  'georgia': 'Georgia (serif)',
  'trebuchet': 'Trebuchet',
};

class NewsletterBrandPanel extends StatefulWidget {
  const NewsletterBrandPanel({
    super.key,
    required this.api,
    required this.applied,
    required this.onApply,
    this.locked = false,
  });

  final ApiClient api;

  /// The brand applied to the open newsletter, or null.
  final Map<String, dynamic>? applied;

  /// Applies (a map) or removes (null) the brand on this newsletter.
  final ValueChanged<Map<String, dynamic>?> onApply;
  final bool locked;

  @override
  State<NewsletterBrandPanel> createState() => _NewsletterBrandPanelState();
}

class _NewsletterBrandPanelState extends State<NewsletterBrandPanel> {
  Map<String, dynamic>? _settings;
  bool _mayEdit = false;
  String? _error;
  String? _note;
  bool _saving = false;
  final _logo = TextEditingController();
  final _footer = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _logo.dispose();
    _footer.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.api.get('/newsletter/brand');
    if (!mounted) return;
    final data = r.data;
    setState(() {
      if (!r.ok || data is! Map) {
        _error = r.reason;
        return;
      }
      final s = data['settings'];
      _settings = s is Map ? Map<String, dynamic>.from(s) : {};
      _mayEdit = data['may_edit'] == true;
      _logo.text = '${_settings!['logo_url'] ?? ''}';
      _footer.text = '${_settings!['footer_note'] ?? ''}';
    });
  }

  Map<String, dynamic> _current() => {
    ...?_settings,
    'logo_url': _logo.text.trim(),
    'footer_note': _footer.text.trim(),
  }..removeWhere((_, v) => v == null || v == '');

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _note = null;
    });
    final r = await widget.api.put('/newsletter/brand', {
      'settings': _current(),
    });
    if (!mounted) return;
    setState(() {
      _saving = false;
      if (r.ok && r.data is Map && r.data['settings'] is Map) {
        _settings = Map<String, dynamic>.from(r.data['settings'] as Map);
        _note = 'Brand saved.';
      } else {
        _note = r.reason;
      }
    });
  }

  void _set(String key, Object? value) => setState(() {
    if (value == null) {
      _settings!.remove(key);
    } else {
      _settings![key] = value;
    }
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_error != null) return DashboardNote(_error!);
    final s = _settings;
    if (s == null) {
      return const Padding(
        padding: EdgeInsets.all(12),
        child: LinearProgressIndicator(),
      );
    }
    final editable = _mayEdit && !widget.locked;
    final applied = widget.applied != null;
    final upToDate =
        applied && jsonEncode(widget.applied) == jsonEncode(_current());

    return Column(
      key: const ValueKey('newsletter-brand-panel'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Company brand', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'Applied brand fills what a block does not set itself; '
          'anything you styled on a block stays.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        for (final e in brandColorFields.entries)
          _colorRow(e.key, e.value, '${s[e.key] ?? ''}', editable),
        const SizedBox(height: 8),
        Text('Font', style: theme.textTheme.labelMedium),
        Wrap(
          key: const ValueKey('newsletter-brand-font'),
          spacing: 4,
          runSpacing: 4,
          children: [
            for (final f in brandFonts.entries)
              ChoiceChip(
                label: Text(f.value),
                selected: (s['font'] ?? 'system') == f.key,
                onSelected: editable ? (_) => _set('font', f.key) : null,
              ),
          ],
        ),
        const SizedBox(height: 8),
        const SizedBox(height: 8),
        Text('Button corners', style: theme.textTheme.labelMedium),
        Wrap(
          key: const ValueKey('newsletter-brand-radius'),
          spacing: 4,
          children: [
            for (final r in const {0: 'Square', 6: 'Rounded', 12: 'Soft', 24: 'Pill'}.entries)
              ChoiceChip(
                label: Text(r.value),
                selected: (s['button_radius'] ?? 6) == r.key,
                onSelected: editable ? (_) => _set('button_radius', r.key) : null,
              ),
          ],
        ),
        SwitchListTile(
          key: const ValueKey('newsletter-brand-full-width'),
          contentPadding: EdgeInsets.zero,
          dense: true,
          title: const Text('Buttons full width'),
          value: s['button_full_width'] == true,
          onChanged: editable ? (v) => _set('button_full_width', v) : null,
        ),
        TextField(
          key: const ValueKey('newsletter-brand-logo'),
          controller: _logo,
          enabled: editable,
          decoration: const InputDecoration(
            labelText: 'Logo picture address (https)',
            isDense: true,
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          key: const ValueKey('newsletter-brand-footer'),
          controller: _footer,
          enabled: editable,
          maxLines: 2,
          decoration: const InputDecoration(
            labelText: 'Footer note',
            isDense: true,
          ),
        ),
        const SizedBox(height: 10),
        if (editable)
          FilledButton(
            key: const ValueKey('newsletter-brand-save'),
            onPressed: _saving ? null : _save,
            child: Text(_saving ? 'Saving…' : 'Save brand'),
          ),
        if (_note != null) ...[
          const SizedBox(height: 6),
          Text(_note!, key: const ValueKey('newsletter-brand-note')),
        ],
        const SizedBox(height: 10),
        OutlinedButton.icon(
          key: const ValueKey('newsletter-brand-apply'),
          onPressed: widget.locked || upToDate
              ? null
              : () => widget.onApply(_current()),
          icon: const Icon(Icons.format_paint_outlined, size: 16),
          label: Text(
            upToDate
                ? 'Brand applied'
                : applied
                ? 'Re-apply brand'
                : 'Apply brand',
          ),
        ),
        if (applied)
          TextButton(
            key: const ValueKey('newsletter-brand-remove'),
            onPressed: widget.locked ? null : () => widget.onApply(null),
            child: const Text('Remove brand from this newsletter'),
          ),
      ],
    );
  }

  Widget _colorRow(String key, String label, String value, bool editable) {
    const swatches = [
      '#00708F',
      '#060808',
      '#3A4650',
      '#FFFFFF',
      '#B3261E',
      '#E67E22',
      '#2E7D32',
      '#EDF1F4',
    ];
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          SizedBox(
            width: 82,
            child: Text(label, style: const TextStyle(fontSize: 12)),
          ),
          Expanded(
            child: Wrap(
              key: ValueKey('newsletter-brand-$key'),
              spacing: 4,
              runSpacing: 4,
              children: [
                for (final sw in swatches)
                  InkWell(
                    customBorder: const CircleBorder(),
                    onTap: editable ? () => _set(key, sw) : null,
                    child: Container(
                      width: 18,
                      height: 18,
                      decoration: BoxDecoration(
                        color: blockColor({'c': sw}, 'c', Colors.transparent),
                        shape: BoxShape.circle,
                        border: Border.all(
                          width: value.toUpperCase() == sw ? 3 : 1,
                          color: value.toUpperCase() == sw
                              ? Theme.of(context).colorScheme.primary
                              : Theme.of(context).colorScheme.outline,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A saved section or a starter, as the Saved tab lists it.
class NewsletterSavedSection {
  const NewsletterSavedSection({
    required this.id,
    required this.name,
    required this.version,
    required this.blocks,
    this.starter = false,
    this.mayEdit = false,
  });

  final String id;
  final String name;
  final int version;
  final List<Map<String, dynamic>> blocks;
  final bool starter;
  final bool mayEdit;

  static List<Map<String, dynamic>> _blocks(Object? raw) => [
    for (final b in (raw as List? ?? const []))
      if (b is Map) Map<String, dynamic>.from(jsonDecode(jsonEncode(b)) as Map),
  ];

  factory NewsletterSavedSection.fromJson(Map json, {bool starter = false}) =>
      NewsletterSavedSection(
        id: '${json[starter ? 'key' : 'id'] ?? ''}',
        name: '${json['name'] ?? ''}',
        version: (json['version'] as num?)?.toInt() ?? 1,
        blocks: _blocks(json['blocks']),
        starter: starter,
        mayEdit: json['may_edit'] == true,
      );

  /// Fresh, independent copies of the blocks.
  List<Map<String, dynamic>> copyBlocks() => _blocks(blocks);

  /// A linked use: the reference the server expands, with a copy of the
  /// pinned version's blocks for the canvas to draw.
  Map<String, dynamic> reference() => {
    'type': 'section_ref',
    'section_id': id,
    'version': version,
    'name': name,
    'preview_blocks': copyBlocks(),
  };
}

/// Loads `/newsletter/sections`: saved ones first, then the starters.
Future<List<NewsletterSavedSection>?> loadSavedSections(ApiClient api) async {
  final r = await api.get('/newsletter/sections');
  if (!r.ok) return null;
  return [
    for (final s in (r.data as List? ?? const []))
      if (s is Map) NewsletterSavedSection.fromJson(s),
    for (final s in (r.body['starters'] as List? ?? const []))
      if (s is Map) NewsletterSavedSection.fromJson(s, starter: true),
  ];
}

class NewsletterSavedPanel extends StatelessWidget {
  const NewsletterSavedPanel({
    super.key,
    required this.sections,
    required this.onInsertCopy,
    required this.onInsertLinked,
    this.onPublishFromSelection,
    this.locked = false,
  });

  /// Null while loading.
  final List<NewsletterSavedSection>? sections;
  final ValueChanged<NewsletterSavedSection> onInsertCopy;
  final ValueChanged<NewsletterSavedSection> onInsertLinked;

  /// Publishes the selected block as this section's next version; null
  /// when nothing is selected.
  final ValueChanged<NewsletterSavedSection>? onPublishFromSelection;
  final bool locked;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final all = sections;
    if (all == null) {
      return const Padding(
        padding: EdgeInsets.all(12),
        child: LinearProgressIndicator(),
      );
    }
    final saved = all.where((s) => !s.starter).toList();
    final starters = all.where((s) => s.starter).toList();

    return Column(
      key: const ValueKey('newsletter-saved-panel'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Saved sections', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        if (saved.isEmpty)
          Text(
            'Select a block and use "Save as section" in its toolbar.',
            style: theme.textTheme.bodySmall,
          ),
        for (final s in saved) _tile(context, s),
        const SizedBox(height: 12),
        Text('Starters', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        for (final s in starters) _tile(context, s),
      ],
    );
  }

  Widget _tile(BuildContext context, NewsletterSavedSection s) {
    final theme = Theme.of(context);
    return Card(
      key: ValueKey('newsletter-saved-${s.id}'),
      margin: const EdgeInsets.only(bottom: 8),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // A small rendered preview: the section's own blocks, scaled down.
          SizedBox(
            height: 70,
            child: IgnorePointer(
              child: ClipRect(
                child: FittedBox(
                  fit: BoxFit.fitWidth,
                  alignment: Alignment.topCenter,
                  child: Container(
                    width: 600,
                    color: EmailInk.paper,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (final b in s.blocks.take(3))
                          EmailBlockView(block: b),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 6, 8, 2),
            child: Text(
              s.starter ? s.name : '${s.name} · v${s.version}',
              style: theme.textTheme.labelLarge,
            ),
          ),
          Wrap(
            spacing: 4,
            children: [
              TextButton(
                key: ValueKey('newsletter-saved-copy-${s.id}'),
                onPressed: locked ? null : () => onInsertCopy(s),
                child: const Text('Insert copy'),
              ),
              if (!s.starter)
                TextButton(
                  key: ValueKey('newsletter-saved-linked-${s.id}'),
                  onPressed: locked ? null : () => onInsertLinked(s),
                  child: const Text('Insert linked'),
                ),
              if (!s.starter && s.mayEdit && onPublishFromSelection != null)
                TextButton(
                  key: ValueKey('newsletter-saved-publish-${s.id}'),
                  onPressed: locked ? null : () => onPublishFromSelection!(s),
                  child: const Text('Publish selection as new version'),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

