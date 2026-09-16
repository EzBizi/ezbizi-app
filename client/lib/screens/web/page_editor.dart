import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';

/// One page of a customer's website, and everything EzBizi publishes onto it.
///
/// ── What it is for ──
///
/// The SEO tab answers for a whole site. This answers for one page of it, which
/// is where a business actually differs from itself: the contact page carries a
/// different number, the Dallas page a different address. The storage for that
/// has existed since the first migration — `web_values` has always been keyed by
/// (site, path, key) and the plugin has always published a pages map — and no
/// screen ever wrote a path, so none of it could be used.
///
/// ── Why an empty box means "inherit" ──
///
/// Every field here is an OVERRIDE. Left blank the page publishes the site's
/// answer, and the site's answer is shown under the box so the blank is legible
/// as inheritance rather than as nothing. Clearing a box deletes the override,
/// which is the only way back to inheriting — a page that could only ever gain
/// values would be a page nobody could put right.
///
/// ── Why the text fields are different from the rest ──
///
/// The fields above come from a fixed catalogue: a phone number, a licence.
/// These are the words on the page, and nobody knows in advance how many
/// paragraphs the About page has. Each becomes `[ezbizi_about_us_field_1]` on
/// the customer's site, so the NAME is a promise — the server hands out the next
/// one and never reuses a number, because a shortcode already pasted into a live
/// page must not quietly start showing different words.
class PageEditor extends StatefulWidget {
  const PageEditor({
    super.key,
    required this.api,
    required this.siteId,
    required this.host,
    required this.path,
    required this.title,
    required this.link,
    required this.canEdit,
  });

  final ApiClient api;
  final String siteId;
  final String host;

  /// The page's own path, e.g. `/about-us`. Never empty: an empty path is the
  /// site itself, which the SEO tab already answers for.
  final String path;

  final String title;
  final String link;
  final bool canEdit;

  @override
  State<PageEditor> createState() => _PageEditorState();
}

class _PageEditorState extends State<PageEditor> {
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _optionsError;

  List<Map<String, dynamic>> _fields = const [];
  Map<String, dynamic> _siteValues = const {};
  Map<String, dynamic> _pageValues = const {};

  List<Map<String, dynamic>> _authors = const [];
  List<Map<String, dynamic>> _categories = const [];

  /// Edited values, by key. Only what somebody touched is sent.
  final Map<String, String> _edits = {};
  final Map<String, TextEditingController> _boxes = {};

  /// Keys added in this sitting that have no value yet. Without this an empty
  /// new field would vanish on the next rebuild, before anybody typed into it.
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

      _fields = ((data['fields'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>();
      _siteValues = (data['values'] as Map<String, dynamic>?) ?? const {};
      _pageValues =
          ((data['pages'] as Map<String, dynamic>?) ?? const {})[widget.path]
                  as Map<String, dynamic>? ??
              const {};
    });

    _loadOptions();
  }

  /// The two dropdowns, from the website itself.
  ///
  /// Its own request and its own error: a site that has not paired cannot offer
  /// a list of authors, and that must not stop somebody editing a phone number.
  Future<void> _loadOptions() async {
    final response =
        await widget.api.get('/web/sites/${widget.siteId}/content/options', {});

    if (!mounted) return;

    setState(() {
      if (!response.ok) {
        _optionsError = response.message;
        return;
      }

      _optionsError = null;
      _authors = ((response.body['authors'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>();
      _categories = ((response.body['categories'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>();
    });
  }

  String _current(String key) =>
      _edits[key] ?? '${_pageValues[key] ?? ''}';

  String _inherited(String key) => '${_siteValues[key] ?? ''}';

  TextEditingController _box(String key) => _boxes.putIfAbsent(
        key,
        () => TextEditingController(text: _current(key)),
      );

  /// The custom text fields on this page, in the order the server names them.
  List<String> get _textKeys {
    final keys = _fields
        .where((f) => f['path'] == widget.path)
        .map((f) => '${f['key']}')
        .toList();

    for (final key in _fresh) {
      if (!keys.contains(key)) keys.add(key);
    }

    return keys;
  }

  Future<void> _save() async {
    if (_edits.isEmpty) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.put(
      '/web/sites/${widget.siteId}/values',
      {'path': widget.path, 'values': _edits},
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

    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Saved for ${widget.path}.')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title.trim().isEmpty ? widget.path : widget.title),
        // The path, because two pages are often called the same thing.
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(20),
          child: Padding(
            padding: const EdgeInsets.only(left: 16, bottom: 8),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                '${widget.host}${widget.path}',
                style: theme.textTheme.bodySmall,
              ),
            ),
          ),
        ),
        actions: [
          if (widget.link.isNotEmpty)
            IconButton(
              tooltip: 'Open the page',
              icon: const Icon(Icons.open_in_new, size: 18),
              onPressed: () => launchUrl(Uri.parse(widget.link)),
            ),
        ],
      ),
      floatingActionButton: !widget.canEdit || _edits.isEmpty
          ? null
          : FloatingActionButton.extended(
              onPressed: _saving ? null : _save,
              icon: _saving
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.check),
              label: Text(_saving ? 'Saving' : 'Save ${_edits.length} change'
                  '${_edits.length == 1 ? '' : 's'}'),
            ),
      body: AsyncBody(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 96),
          children: [
            _heading(theme, 'This page'),
            _text(theme, 'group'),
            _choice(theme, 'author', _authors),
            _choice(theme, 'category', _categories),
            if (_optionsError != null)
              Padding(
                padding: const EdgeInsets.only(top: 4, bottom: 12),
                child: Text(_optionsError!, style: theme.textTheme.bodySmall),
              ),

            const SizedBox(height: 8),
            _heading(theme, 'Values on this page'),
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                'Leave a box empty and this page publishes the site\'s answer.',
                style: theme.textTheme.bodySmall,
              ),
            ),
            for (final field in _fields)
              if (field['path'] == null && !_pageOnly.contains(field['key']))
                _text(theme, '${field['key']}'),

            const SizedBox(height: 8),
            _heading(theme, 'Text on this page'),
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                'For the words themselves. Each one becomes a shortcode you can '
                'paste into the page.',
                style: theme.textTheme.bodySmall,
              ),
            ),
            for (final key in _textKeys) _paragraph(theme, key),
            if (widget.canEdit)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: _addTextField,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('Add a text field'),
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// Shown on their own at the top rather than among the site's values, because
  /// none of them means anything site-wide.
  static const _pageOnly = {'group', 'author', 'category'};

  Widget _heading(ThemeData theme, String text) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Text(text, style: theme.textTheme.titleSmall),
    );
  }

  Map<String, dynamic> _field(String key) => _fields.firstWhere(
        (f) => f['key'] == key,
        orElse: () => {'key': key, 'label': key, 'hint': ''},
      );

  Widget _text(ThemeData theme, String key) {
    final field = _field(key);
    final inherited = _inherited(key);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextField(
        controller: _box(key),
        enabled: widget.canEdit,
        decoration: InputDecoration(
          labelText: '${field['label']}',
          isDense: true,
          // The site's answer, so an empty box reads as inheritance rather
          // than as a value somebody forgot.
          helperText: inherited.isEmpty
              ? '${field['hint']}'
              : 'The site publishes: $inherited',
        ),
        onChanged: (value) => setState(() => _edits[key] = value),
      ),
    );
  }

  /// A dropdown when the website could tell us the choices, and a plain box
  /// when it could not — an unpaired site must not lock the field.
  Widget _choice(ThemeData theme, String key, List<Map<String, dynamic>> options) {
    if (options.isEmpty) return _text(theme, key);

    final field = _field(key);
    final names = options.map((o) => '${o['name']}').toList();
    final current = _current(key);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: DropdownButtonFormField<String>(
        initialValue: names.contains(current) ? current : null,
        decoration: InputDecoration(
          labelText: '${field['label']}',
          isDense: true,
          helperText: '${field['hint']}',
        ),
        items: [
          const DropdownMenuItem(value: '', child: Text('Not set')),
          for (final name in names)
            DropdownMenuItem(value: name, child: Text(name)),
        ],
        onChanged: !widget.canEdit
            ? null
            : (value) => setState(() => _edits[key] = value ?? ''),
      ),
    );
  }

  Widget _paragraph(ThemeData theme, String key) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TextField(
            controller: _box(key),
            enabled: widget.canEdit,
            minLines: 3,
            maxLines: 10,
            decoration: InputDecoration(
              labelText: '${_field(key)['label']}',
              isDense: true,
              // The shortcode, beside the box, because it is the only reason
              // the field is worth filling in.
              helperText: '[ezbizi_$key]',
            ),
            onChanged: (value) => setState(() => _edits[key] = value),
          ),
          if (widget.canEdit)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: () => _removeTextField(key),
                icon: const Icon(Icons.delete_outline, size: 16),
                label: const Text('Remove'),
              ),
            ),
        ],
      ),
    );
  }

  /// The next name for this page, worked out the way the server does it.
  ///
  /// Numbered from the names already present rather than from how many there
  /// are: removing field 2 of three must not hand field 3's name to a new field
  /// whose words are different, on a page where `[ezbizi_x_field_3]` is already
  /// pasted into the template.
  void _addTextField() {
    final slug = _slug(widget.path);
    var highest = 0;

    for (final key in _textKeys) {
      final match = RegExp('^${RegExp.escape(slug)}_field_([0-9]+)\$').firstMatch(key);

      if (match != null) {
        highest = highest > int.parse(match.group(1)!)
            ? highest
            : int.parse(match.group(1)!);
      }
    }

    setState(() => _fresh.add('${slug}_field_${highest + 1}'));
  }

  /// Emptying it is what removes it: the server deletes a row whose value is
  /// blank, and the field is its value.
  void _removeTextField(String key) {
    setState(() {
      _edits[key] = '';
      _fresh.remove(key);
      _boxes[key]?.text = '';
    });
  }

  /// `/about-us/` becomes `about_us`; the site root becomes `home`.
  static String _slug(String path) {
    final slug = path
        .toLowerCase()
        .replaceAll(RegExp('[^a-z0-9]+'), '_')
        .replaceAll(RegExp('^_+|_+\$'), '');

    return slug.isEmpty ? 'home' : slug;
  }
}
