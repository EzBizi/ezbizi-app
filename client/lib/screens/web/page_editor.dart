import 'package:flutter/material.dart';

import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/shortcode_button.dart';

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
/// ── Why this is not a screen ──
///
/// It draws inside the pane the list was in, with a back control, and takes
/// over no more of the window than the list did. It was a pushed route for one
/// commit and Jose named it immediately: going full screen breaks containment,
/// and a page's fields are a detail of the site in the rail beside them, not a
/// place you travel to.
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
    required this.onClose,
    this.wpAuthor,
    this.wpCategories = const [],
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

  /// Back to the list this opened from, inside the same pane.
  final VoidCallback onClose;

  /// What the WEBSITE already says about this page: WordPress's own author id
  /// and category ids, straight off the row in the list.
  ///
  /// Shown when EzBizi holds no override of its own, because the alternative is
  /// an empty Author box on a page that plainly has an author — a screen
  /// stating something false about the customer's own site. Names are resolved
  /// against the lists from /content/options, which is where the names live.
  final int? wpAuthor;
  final List<int> wpCategories;

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

  /// What the website itself says, for the two fields WordPress already holds.
  ///
  /// Empty when the lists have not arrived, which is why it is only ever used
  /// as a fallback: an unpaired site has no names to resolve against and must
  /// still show whatever EzBizi has stored.
  String _fromWebsite(String key) {
    if (key == 'author') {
      return _nameOf(_authors, widget.wpAuthor);
    }

    if (key == 'category') {
      // The first, because the box holds one and WordPress allows several.
      // Naming one of three is better than naming none, and anybody who wants
      // a different one picks it.
      return widget.wpCategories.isEmpty
          ? ''
          : _nameOf(_categories, widget.wpCategories.first);
    }

    return '';
  }

  String _nameOf(List<Map<String, dynamic>> options, int? id) {
    if (id == null) return '';

    for (final option in options) {
      if (option['id'] == id) return '${option['name']}';
    }

    return '';
  }

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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The header the pane needs in place of an app bar: where you are, the
        // way back, and the way out to the real page.
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 16, 8),
          child: Row(
            children: [
              IconButton(
                tooltip: 'Back to the list',
                icon: const Icon(Icons.arrow_back, size: 20),
                onPressed: widget.onClose,
              ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      widget.title.trim().isEmpty ? widget.path : widget.title,
                      style: theme.textTheme.titleSmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                    // The path, because two pages are often called the same
                    // thing, and it is what identifies the page to EzBizi.
                    Text(
                      '${widget.host}${widget.path}',
                      style: theme.textTheme.bodySmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              if (widget.link.isNotEmpty)
                IconButton(
                  tooltip: 'Open the page on the website',
                  icon: const Icon(Icons.open_in_new, size: 18),
                  onPressed: () => launchUrl(Uri.parse(widget.link)),
                ),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: ListView(
              key: const ValueKey('tour-seo-page-editor'),
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
              children: [
                // The three the server sections as `page`. Named here rather
                // than looped, because each is drawn differently — one plain
                // box and two dropdowns filled from the website.
                _heading(theme, 'This page'),
                _text(theme, 'group'),
                _choice(theme, 'author', _authors),
                _choice(theme, 'category', _categories),
                _text(theme, 'street_view_url'),
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
                // The SITE's fields, which a page may override. Filtered by
                // section rather than by exclusion: the page's own three and
                // its text fields carry their own sections now, so a list that
                // named what to leave out would need editing every time one
                // was added.
                for (final field in _fields)
                  if (field['section'] == 'general') _text(theme, '${field['key']}'),

                const SizedBox(height: 8),
                _heading(theme, 'Text on this page'),
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(
                    'For the words themselves. Each one becomes a shortcode you '
                    'can paste into the page.',
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
        ),
        // In the pane rather than floating over it: a FloatingActionButton
        // anchors to the window, which is the thing this screen is not.
        if (widget.canEdit && _edits.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
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
                label: Text(
                  _saving
                      ? 'Saving'
                      : 'Save ${_edits.length} change'
                          '${_edits.length == 1 ? '' : 's'}',
                ),
              ),
            ),
          ),
      ],
    );
  }

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
      // With its shortcode, like the General tab. Jose: "the fields do not have
      // the shortcodes to be able to copy them like on the general one" — and a
      // value you cannot paste anywhere is a value with nowhere to go.
      child: withShortcode(
        TextField(
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
        _shortcodeFor(key),
      ),
    );
  }

  /// The catalogue's own spelling when it sent one, and the same rule when it
  /// did not — a field added in this sitting has no server row yet.
  String _shortcodeFor(String key) => '${_field(key)['shortcode'] ?? '[ezbizi_$key]'}';

  /// A dropdown when the website could tell us the choices, and a plain box
  /// when it could not — an unpaired site must not lock the field.
  Widget _choice(ThemeData theme, String key, List<Map<String, dynamic>> options) {
    if (options.isEmpty) return _text(theme, key);

    final field = _field(key);
    final names = options.map((o) => '${o['name']}').toList();

    /*
     * EzBizi's override first, then what the website already says. Jose:
     * "some pages already have them, so they should be loaded, cause right now
     * they all show as empty even if that is not true".
     *
     * Nothing is SAVED by showing it — the box starts on the website's answer
     * and no edit is recorded until somebody changes it, so opening a page and
     * leaving writes nothing.
     */
    final stored = _current(key);
    final current = stored.isNotEmpty ? stored : _fromWebsite(key);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: withShortcode(
        SearchPickerField<String>(
          value: names.contains(current) ? current : null,
          label: '${field['label']}',
          isDense: true,
          helperText: stored.isEmpty && current.isNotEmpty
              // Said out loud, so nobody reads the website's own answer as
              // something EzBizi has been told to publish.
              ? 'From the website. Change it to publish something else.'
              : '${field['hint']}',
          options: [
            const SearchOption(value: '', label: 'Not set'),
            for (final name in names) SearchOption(value: name, label: name),
          ],
          onChanged: !widget.canEdit
              ? null
              : (value) => setState(() => _edits[key] = value ?? ''),
        ),
        _shortcodeFor(key),
      ),
    );
  }

  Widget _paragraph(ThemeData theme, String key) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // The shortcode as a button rather than as helper text: it is the
          // only reason the field is worth filling in, and it has to be
          // copyable like every other one.
          withShortcode(
            TextField(
              controller: _box(key),
              enabled: widget.canEdit,
              minLines: 3,
              maxLines: 10,
              decoration: InputDecoration(
                labelText: '${_field(key)['label']}',
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
                onPressed: () => _removeTextField(key),
                icon: const Icon(Icons.delete_outline, size: 16),
                label: const Text('Remove'),
              ),
            ),
        ],
      ),
    );
  }

  /// The next name for this page.
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
