import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';
import '../../widgets/shortcode_button.dart';

/// Text written once and used on as many pages as want it.
///
/// ── What tells a template from a page field ──
///
/// A page field says something about one page: the paragraph at the top of
/// About us. A template is the sentence that appears on nine pages — the
/// licence line, the guarantee, the bit about serving the tri-state area since
/// 1974 — and the point of it is that changing it changes all nine.
///
/// So a template is stored against the SITE, not a page, and `[ezbizi_template_1]`
/// means the same thing wherever it is pasted. The server refuses one saved onto
/// a page for that reason: a template that could differ per page would be the
/// opposite of a template, and somebody would discover it by reading two pages
/// side by side rather than from any error.
///
/// ── Why the numbers are not tidied up ──
///
/// Template 2 can be removed and leave a gap. The name is a shortcode already
/// pasted into pages we cannot see, so renumbering would repoint live text on
/// somebody's website. A gap is cheap; the alternative is silent.
class TemplatesPane extends StatefulWidget {
  const TemplatesPane({
    super.key,
    required this.api,
    required this.siteId,
    required this.host,
    required this.canEdit,
  });

  final ApiClient api;
  final String siteId;
  final String host;
  final bool canEdit;

  @override
  State<TemplatesPane> createState() => _TemplatesPaneState();
}

class _TemplatesPaneState extends State<TemplatesPane> {
  bool _loading = true;
  bool _saving = false;
  String? _error;

  List<Map<String, dynamic>> _templates = const [];
  Map<String, dynamic> _values = const {};

  final Map<String, String> _edits = {};
  final Map<String, TextEditingController> _boxes = {};
  final List<String> _fresh = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final box in _boxes.values) {
      box.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/web/sites/${widget.siteId}/values', {});

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      final data = (response.body['data'] as Map<String, dynamic>?) ?? const {};

      _templates = ((data['fields'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>()
          .where((f) => f['template'] == true)
          .toList();
      _values = (data['values'] as Map<String, dynamic>?) ?? const {};
    });
  }

  List<String> get _keys {
    final keys = _templates.map((f) => '${f['key']}').toList();

    for (final key in _fresh) {
      if (!keys.contains(key)) keys.add(key);
    }

    return keys;
  }

  String _current(String key) => _edits[key] ?? '${_values[key] ?? ''}';

  TextEditingController _box(String key) => _boxes.putIfAbsent(
        key,
        () => TextEditingController(text: _current(key)),
      );

  Future<void> _save() async {
    if (_edits.isEmpty) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    // No path: a template belongs to the site, and sending one would be
    // refused — which is the server saying the same thing this screen does.
    final response = await widget.api.put(
      '/web/sites/${widget.siteId}/values',
      {'values': _edits},
    );

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = response.message;
      });

      return;
    }

    _edits.clear();
    _fresh.clear();
    setState(() => _saving = false);
    await _load();
  }

  void _add() {
    var highest = 0;

    for (final key in _keys) {
      final match = RegExp(r'^template_([0-9]+)$').firstMatch(key);

      if (match != null) {
        final n = int.parse(match.group(1)!);
        highest = n > highest ? n : highest;
      }
    }

    setState(() => _fresh.add('template_${highest + 1}'));
  }

  /// Emptying it is what removes it: the server deletes a row whose value is
  /// blank, and a template is its text.
  void _remove(String key) {
    setState(() {
      _edits[key] = '';
      _fresh.remove(key);
      _boxes[key]?.text = '';
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: ListView(
              key: const ValueKey('tour-seo-templates'),
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
              children: [
                Text(
                  'Text you write once and use on as many pages as you like. '
                  'Change it here and it changes everywhere it appears.',
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 16),
                if (_keys.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    child: Text(
                      'No templates yet.',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                for (final key in _keys) _template(theme, key),
                if (widget.canEdit)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      key: const ValueKey('tour-seo-templates-add'),
                      onPressed: _add,
                      icon: const Icon(Icons.add, size: 18),
                      label: const Text('Add a template'),
                    ),
                  ),
              ],
            ),
          ),
        ),
        if (widget.canEdit && _edits.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
            child: Align(
              alignment: Alignment.centerRight,
              child: FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.check, size: 18),
                label: Text(_saving ? 'Saving' : 'Save'),
              ),
            ),
          ),
      ],
    );
  }

  Widget _template(ThemeData theme, String key) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // The shortcode as a button, the same control the General tab uses:
          // pasting it is the whole point of writing the text, and helper text
          // is something you retype rather than copy.
          withShortcode(
            TextField(
              controller: _box(key),
              enabled: widget.canEdit,
              minLines: 3,
              maxLines: 12,
              decoration: InputDecoration(
                labelText: _label(key),
                isDense: true,
              ),
              onChanged: (value) => setState(() => _edits[key] = value),
            ),
            '[ezbizi_$key]',
          ),
          if (widget.canEdit)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: () => _remove(key),
                icon: const Icon(Icons.delete_outline, size: 16),
                label: const Text('Remove'),
              ),
            ),
        ],
      ),
    );
  }

  String _label(String key) {
    final field = _templates.firstWhere(
      (f) => f['key'] == key,
      orElse: () => const {},
    );

    return '${field['label'] ?? 'Template ${key.replaceAll('template_', '')}'}';
  }
}
