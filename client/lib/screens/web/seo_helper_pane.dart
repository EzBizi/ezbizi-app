import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/error_note.dart';
import '../../widgets/status_pill.dart';
import '../../widgets/tab_bar_like.dart';

/// Web Management › SEO › SEO Helper: each page's search title, description
/// and focus keyphrase, checked and edited (design 29, Jose's picks 1 and 4,
/// `docs/previews/web-seo-helper-layouts.html`).
///
/// The website keeps the values; its SEO plugin prints them. This reads and
/// writes them through Web Management's endpoints and stores nothing, and it
/// never names the plugin on screen.
///
/// The site's health first, because it is the reason to open the section, then
/// the pages; opening one swaps the list for its editor inside this pane, with
/// a back arrow. Nothing goes full screen: Jose's containment rule.
class SeoHelperPane extends StatefulWidget {
  const SeoHelperPane({
    super.key,
    required this.api,
    required this.siteId,
    required this.host,
    required this.canEdit,
  });

  final ApiClient api;
  final String siteId;
  final String host;

  /// Saving needs web_management.edit; without it the fields are read-only
  /// and Save is not drawn.
  final bool canEdit;

  @override
  State<SeoHelperPane> createState() => _SeoHelperPaneState();
}

/// A check's colour, from the server's three words.
Tone _tone(Object? status) => switch (status) {
  'good' => Tone.ok,
  'ok' => Tone.caution,
  _ => Tone.bad,
};

Widget _dot(BuildContext context, Object? status, {double size = 10}) =>
    Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: ToneColours.of(context, _tone(status)).ink,
        shape: BoxShape.circle,
      ),
    );

/// The list's filters: the health tiles and the chips name the same ones.
enum _Filter {
  all,
  bad,
  noDescription,
  noKeyphrase,
  sharedKeyphrase,
  sharedTitle,
  hidden,
}

class _SeoHelperPaneState extends State<SeoHelperPane> {
  Map<String, dynamic>? _overview;
  bool _loading = true;
  String? _error;
  _Filter _filter = _Filter.all;
  String _find = '';
  bool _fixing = false;

  /// The page open in the editor; null shows the health and the list.
  int? _open;

  String get _base => '/web/sites/${widget.siteId}/seo-helper';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool fresh = false}) async {
    setState(() {
      _loading = _overview == null;
      _error = null;
    });

    final response = await widget.api.get(
      _base,
      fresh ? const {'fresh': '1'} : null,
    );
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _overview = response.body['data'] as Map<String, dynamic>?;
      } else {
        _error = response.message;
      }
    });
  }

  /// A site issue's own fix, sent as the server wrote it.
  Future<void> _fix(Map<String, dynamic> fix) async {
    setState(() => _fixing = true);
    final response = await widget.api.put('$_base/site', {
      fix['field'] as String: fix['value'],
    });
    if (!mounted) return;

    setState(() {
      _fixing = false;
      if (response.ok) {
        _overview = response.body['data'] as Map<String, dynamic>?;
      } else {
        _error = response.message;
      }
    });
  }

  List<Map<String, dynamic>> get _pages =>
      ((_overview?['pages'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();

  Map<String, dynamic> get _summary =>
      (_overview?['summary'] as Map?)?.cast<String, dynamic>() ?? const {};

  int _count(String key) => (_summary[key] as num?)?.toInt() ?? 0;

  static bool _hasIssue(Map<String, dynamic> page, String key) =>
      ((page['issues'] as List?) ?? const []).any(
        (issue) => issue is Map && issue['key'] == key,
      );

  bool _matches(Map<String, dynamic> page) {
    final seo = (page['seo'] as Map?) ?? const {};
    final shown = switch (_filter) {
      _Filter.all => true,
      _Filter.bad => page['score'] == 'bad',
      _Filter.noDescription =>
        '${(page['final'] as Map?)?['description'] ?? ''}'.trim().isEmpty,
      _Filter.noKeyphrase => '${seo['keyphrase'] ?? ''}'.trim().isEmpty,
      _Filter.sharedKeyphrase => _hasIssue(page, 'keyphrase_unique'),
      _Filter.sharedTitle => _hasIssue(page, 'title_unique'),
      _Filter.hidden => seo['noindex'] == true,
    };
    if (!shown) return false;

    final needle = _find.trim().toLowerCase();

    return needle.isEmpty ||
        '${page['name'] ?? ''} ${page['url'] ?? ''}'.toLowerCase().contains(
          needle,
        );
  }

  @override
  Widget build(BuildContext context) {
    final open = _open;
    if (open != null) {
      return _SeoPageEditor(
        key: ValueKey('seo-page-$open'),
        api: widget.api,
        base: _base,
        pageId: open,
        host: widget.host,
        canEdit: widget.canEdit,
        onOpen: (id) => setState(() => _open = id),
        onClose: () {
          setState(() => _open = null);
          // A save can change the site's health; re-read the list quietly.
          _load();
        },
      );
    }

    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_overview == null) {
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ErrorNote(_error ?? 'The SEO Helper could not read this website.'),
        ],
      );
    }

    final shown = _pages.where(_matches).toList();

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_error != null) ...[ErrorNote(_error!), const SizedBox(height: 12)],
        _health(context),
        const SizedBox(height: 12),
        _listHead(context),
        for (final page in shown) _row(context, page),
        if (shown.isEmpty)
          Padding(
            padding: const EdgeInsets.all(20),
            child: Text(
              'No page matches that.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
      ],
    );
  }

  // ── the site's health ─────────────────────────────────────────────

  Widget _health(BuildContext context) {
    final theme = Theme.of(context);
    final good = _count('good');
    final ok = _count('ok');
    final bad = _count('bad');
    final issues = ((_overview?['site_issues'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();

    Widget share(int n, Tone tone) => n == 0
        ? const SizedBox.shrink()
        : Expanded(
            flex: n,
            child: Container(color: ToneColours.of(context, tone).ink),
          );

    return DashboardCard(
      key: const ValueKey('tour-seo-helper-health'),
      title: 'Site health',
      icon: Icons.monitor_heart_outlined,
      controls: TextButton.icon(
        key: const Key('seo-read-again'),
        onPressed: () => _load(fresh: true),
        icon: const Icon(Icons.refresh, size: 18),
        label: const Text('Read again'),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            '${_count('pages')} pages and posts',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(5),
            child: SizedBox(
              height: 10,
              child: Row(
                children: [
                  share(good, Tone.ok),
                  share(ok, Tone.caution),
                  share(bad, Tone.bad),
                ],
              ),
            ),
          ),
          const SizedBox(height: 6),
          Text(
            '$good good · $ok could be better · $bad need work',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 12),
          // One size for every tile, whatever its words: Jose's rule for
          // siblings, and a count is only comparable at a glance that way.
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _tile(
                context,
                _count('missing_description'),
                'no description',
                Tone.bad,
                _Filter.noDescription,
              ),
              _tile(
                context,
                _count('missing_keyphrase'),
                'no focus keyphrase',
                Tone.caution,
                _Filter.noKeyphrase,
              ),
              _tile(
                context,
                _count('shared_keyphrase'),
                'share a keyphrase with another page',
                Tone.bad,
                _Filter.sharedKeyphrase,
              ),
              _tile(
                context,
                _count('shared_title'),
                'share a title with another page',
                Tone.caution,
                _Filter.sharedTitle,
              ),
              _tile(
                context,
                _count('hidden'),
                'hidden from Google',
                Tone.neutral,
                _Filter.hidden,
              ),
            ],
          ),
          for (final issue in issues) ...[
            const SizedBox(height: 10),
            _siteIssue(context, issue),
          ],
        ],
      ),
    );
  }

  Widget _tile(
    BuildContext context,
    int n,
    String what,
    Tone tone,
    _Filter filter,
  ) {
    final theme = Theme.of(context);
    final colours = ToneColours.of(context, n == 0 ? Tone.neutral : tone);
    final on = _filter == filter;

    return SizedBox(
      width: 150,
      height: 84,
      child: Material(
        color: n == 0 ? theme.colorScheme.surface : colours.fill,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(
            color: on ? colours.ink : theme.colorScheme.outlineVariant,
            width: on ? 2 : 1,
          ),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: Key('seo-tile-${filter.name}'),
          onTap: () => setState(() => _filter = on ? _Filter.all : filter),
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('$n', style: theme.textTheme.titleLarge),
                Text(
                  what,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _siteIssue(BuildContext context, Map<String, dynamic> issue) {
    final theme = Theme.of(context);
    final fix = issue['fix'] as Map<String, dynamic>?;

    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: ToneColours.of(context, _tone(issue['status'])).fill,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: _dot(context, issue['status']),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              // The server quotes the template; its variables read as words.
              _toWords('${issue['text'] ?? ''}'),
              style: theme.textTheme.bodyMedium,
            ),
          ),
          if (fix != null && widget.canEdit) ...[
            const SizedBox(width: 10),
            FilledButton.tonal(
              key: Key('seo-fix-${issue['key']}'),
              onPressed: _fixing ? null : () => _fix(fix),
              child: Text(_fixing ? 'Fixing…' : 'Fix it'),
            ),
          ],
        ],
      ),
    );
  }

  // ── the pages ─────────────────────────────────────────────────────

  Widget _listHead(BuildContext context) {
    ChoiceChip chip(_Filter filter, String label, int n) => ChoiceChip(
      key: Key('seo-filter-${filter.name}'),
      label: Text('$label $n'),
      selected: _filter == filter,
      showCheckmark: false,
      onSelected: (_) => setState(() => _filter = filter),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 320),
            child: TextField(
              key: const Key('seo-find'),
              decoration: const InputDecoration(
                isDense: true,
                prefixIcon: Icon(Icons.search, size: 18),
                hintText: 'Find a page',
              ),
              onChanged: (value) => setState(() => _find = value),
            ),
          ),
          chip(_Filter.all, 'All', _count('pages')),
          chip(_Filter.bad, 'Need work', _count('bad')),
          chip(
            _Filter.noDescription,
            'No description',
            _count('missing_description'),
          ),
          chip(
            _Filter.sharedKeyphrase,
            'Shared keyphrase',
            _count('shared_keyphrase'),
          ),
        ],
      ),
    );
  }

  /// The one thing a row should say is what to do first.
  static (String, Tone)? _headline(Map<String, dynamic> page) {
    final issues = ((page['issues'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    if ((page['seo'] as Map?)?['noindex'] == true) {
      return ('Hidden from Google', Tone.neutral);
    }
    if (issues.isEmpty) return null;

    final first = issues.firstWhere(
      (i) => i['status'] == 'bad',
      orElse: () => issues.first,
    );
    final label = switch (first['key']) {
      'keyphrase' => 'No keyphrase',
      'keyphrase_unique' => 'Shared keyphrase',
      'title_unique' => 'Shared title',
      'description_unique' => 'Shared description',
      'title_length' => 'Title length',
      'description_length' =>
        '${(page['final'] as Map?)?['description'] ?? ''}'.trim().isEmpty
            ? 'No description'
            : 'Description length',
      'title_keyphrase' => 'Keyphrase not in title',
      'description_keyphrase' => 'Keyphrase not in description',
      'canonical' => 'Points elsewhere',
      _ => 'Could be better',
    };

    return (label, _tone(first['status']));
  }

  Widget _row(BuildContext context, Map<String, dynamic> page) {
    final theme = Theme.of(context);
    final headline = _headline(page);
    final path = Uri.tryParse('${page['url'] ?? ''}')?.path ?? '';
    final firstIssue = ((page['issues'] as List?) ?? const [])
        .whereType<Map>()
        .map((i) => '${i['text'] ?? ''}')
        .firstOrNull;

    return InkWell(
      key: ValueKey('seo-row-${page['id']}'),
      onTap: () => setState(() => _open = (page['id'] as num).toInt()),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 4),
        decoration: BoxDecoration(
          border: Border(
            bottom: BorderSide(color: theme.colorScheme.outlineVariant),
          ),
        ),
        child: Row(
          children: [
            _dot(context, page['score']),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${page['name'] ?? ''}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    [path, ?firstIssue].where((s) => s.isNotEmpty).join(' · '),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            if (headline != null) ...[
              const SizedBox(width: 8),
              StatusPill(headline.$1, tone: headline.$2),
            ],
          ],
        ),
      ),
    );
  }
}

// ── the page editor ─────────────────────────────────────────────────

/// Words for the variables a title template knows, and back. The field shows
/// the words; the site stores the variables.
const _tokens = {
  '%%title%%': '{Page title}',
  '%%sep%%': '{Separator}',
  '%%sitename%%': '{Site name}',
  '%%currentyear%%': '{Year}',
  '%%page%%': '{Page number}',
};

String _toWords(String raw) {
  var out = raw;
  for (final e in _tokens.entries) {
    out = out.replaceAll(e.key, e.value);
  }
  return out;
}

String _toRaw(String words) {
  var out = words;
  for (final e in _tokens.entries) {
    out = out.replaceAll(e.value, e.key);
  }
  return out;
}

/// Google's own colours, because this is a picture of Google's result: the
/// theme's would make it look like ours. Sanctioned in theme_conventions_test.
const _googleLink = Color(0xFF1A0DAB);
const _googleText = Color(0xFF4D5156);
const _googleInk = Color(0xFF202124);
const _googlePaper = Color(0xFFFFFFFF);

class _SeoPageEditor extends StatefulWidget {
  const _SeoPageEditor({
    super.key,
    required this.api,
    required this.base,
    required this.pageId,
    required this.host,
    required this.canEdit,
    required this.onOpen,
    required this.onClose,
  });

  final ApiClient api;
  final String base;
  final int pageId;
  final String host;
  final bool canEdit;
  final ValueChanged<int> onOpen;
  final VoidCallback onClose;

  @override
  State<_SeoPageEditor> createState() => _SeoPageEditorState();
}

class _SeoPageEditorState extends State<_SeoPageEditor> {
  Map<String, dynamic>? _page;
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String? _saved;
  bool _phone = false;
  bool _readability = false;
  Timer? _debounce;

  final _keyphrase = TextEditingController();
  final _title = TextEditingController();
  final _description = TextEditingController();
  final _socialTitle = TextEditingController();
  final _socialDescription = TextEditingController();
  final _socialImage = TextEditingController();
  final _canonical = TextEditingController();
  bool _noindex = false;

  /// What the site holds, to know what changed and to put it back.
  Map<String, dynamic> _stored = const {};

  String get _path => '${widget.base}/${widget.pageId}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    for (final c in [
      _keyphrase,
      _title,
      _description,
      _socialTitle,
      _socialDescription,
      _socialImage,
      _canonical,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final response = await widget.api.get(_path);
    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _take(response.body['data'] as Map<String, dynamic>, fields: true);
      } else {
        _error = response.message;
      }
    });
  }

  /// A page as the server answered it; [fields] also resets the boxes.
  void _take(Map<String, dynamic> page, {bool fields = false}) {
    _page = page;
    if (!fields) return;

    final seo = (page['seo'] as Map?)?.cast<String, dynamic>() ?? const {};
    _stored = Map.of(seo);
    _keyphrase.text = '${seo['keyphrase'] ?? ''}';
    _title.text = _toWords('${seo['title'] ?? ''}');
    _description.text = '${seo['description'] ?? ''}';
    _socialTitle.text = '${seo['social_title'] ?? ''}';
    _socialDescription.text = '${seo['social_description'] ?? ''}';
    _socialImage.text = '${seo['social_image'] ?? ''}';
    _canonical.text = '${seo['canonical'] ?? ''}';
    _noindex = seo['noindex'] == true;
  }

  /// What the boxes say, in the shape the server stores.
  Map<String, dynamic> get _draft => {
    'keyphrase': _keyphrase.text.trim(),
    'title': _toRaw(_title.text.trim()),
    'description': _description.text.trim(),
    'social_title': _socialTitle.text.trim(),
    'social_description': _socialDescription.text.trim(),
    'social_image': _socialImage.text.trim(),
    'canonical': _canonical.text.trim(),
    'noindex': _noindex,
  };

  /// Only the fields that differ from what the site holds. An empty string
  /// clears one, as the server reads it.
  Map<String, dynamic> get _changes => {
    for (final e in _draft.entries)
      if (e.value != (_stored[e.key] ?? (e.value is bool ? false : '')))
        e.key: e.value,
  };

  /// Re-checked as somebody types, after a pause: nothing is saved.
  void _edited() {
    setState(() => _saved = null);
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 600), _analyze);
  }

  Future<void> _analyze() async {
    final draft = _draft;
    final response = await widget.api.post('$_path/analyze', {
      'title': draft['title'],
      'description': draft['description'],
      'keyphrase': draft['keyphrase'],
      'noindex': draft['noindex'],
      if ((draft['canonical'] as String).isNotEmpty)
        'canonical': draft['canonical'],
    });
    if (!mounted || !response.ok) return;

    setState(() => _take(response.body['data'] as Map<String, dynamic>));
  }

  Future<void> _save() async {
    final changes = _changes;
    if (changes.isEmpty) return;

    setState(() {
      _saving = true;
      _error = null;
    });
    final response = await widget.api.put(_path, changes);
    if (!mounted) return;

    setState(() {
      _saving = false;
      if (response.ok) {
        _take(response.body['data'] as Map<String, dynamic>, fields: true);
        _saved = 'Saved to ${widget.host}.';
      } else {
        _error = response.message;
      }
    });
  }

  void _discard() {
    final page = _page;
    if (page == null) return;
    setState(() => _take(page..['seo'] = _stored, fields: true));
    _analyze();
  }

  void _insert(String token) {
    final text = _title.text;
    final at = _title.selection.isValid ? _title.selection.start : text.length;
    final before = text.substring(0, at);
    final gap = before.isEmpty || before.endsWith(' ') ? '' : ' ';
    _title.value = TextEditingValue(
      text: '$before$gap$token${text.substring(at)}',
      selection: TextSelection.collapsed(
        offset: at + gap.length + token.length,
      ),
    );
    _edited();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final page = _page;

    final head = Padding(
      padding: const EdgeInsets.fromLTRB(8, 8, 16, 0),
      child: Row(
        children: [
          // The way back, and what Android's Back presses.
          IconButton(
            tooltip: 'Back to the pages',
            icon: const Icon(Icons.arrow_back),
            onPressed: widget.onClose,
          ),
          const SizedBox(width: 4),
          Expanded(
            child: Text(
              '${page?['name'] ?? 'Page'}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleMedium,
            ),
          ),
          if (page != null) ...[
            _dot(
              context,
              (page['analysis'] as Map?)?['score'] ?? page['score'],
            ),
            const SizedBox(width: 6),
            Text(switch ((page['analysis'] as Map?)?['score'] ??
                page['score']) {
              'good' => 'good',
              'ok' => 'could be better',
              _ => 'needs work',
            }, style: theme.textTheme.bodySmall),
          ],
        ],
      ),
    );

    if (_loading) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          head,
          const Expanded(child: Center(child: CircularProgressIndicator())),
        ],
      );
    }
    if (page == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          head,
          Padding(
            padding: const EdgeInsets.all(16),
            child: ErrorNote(_error ?? 'This page could not be read.'),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        head,
        Expanded(
          child: LayoutBuilder(
            builder: (context, box) {
              final fields = _fields(context);
              final preview = _looks(context);
              final checks = _checks(context, _page!, _analysis);

              // Wide: what you type on the left, what Google shows and what
              // the checks say on the right. Narrow: one column, the preview
              // first (design 29, 1 and 4).
              if (box.maxWidth >= 760) {
                return SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(child: fields),
                      const SizedBox(width: 16),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            preview,
                            const SizedBox(height: 10),
                            checks,
                          ],
                        ),
                      ),
                    ],
                  ),
                );
              }

              // Not a ListView: that builds lazily, and the fields under the
              // preview would not exist until scrolled to.
              return SingleChildScrollView(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  // Preview, what you type, then the checks: on a phone the
                  // fields must not sit under a long list of checks.
                  children: [
                    preview,
                    const SizedBox(height: 16),
                    fields,
                    const SizedBox(height: 16),
                    checks,
                  ],
                ),
              );
            },
          ),
        ),
        _bar(context),
      ],
    );
  }

  Widget _bar(BuildContext context) {
    final theme = Theme.of(context);
    final changed = _changes.keys.toList();
    final note =
        _error ??
        _saved ??
        (changed.isEmpty
            ? 'Nothing changed.'
            : 'Changed: ${changed.map((k) => k.replaceAll('_', ' ')).join(', ')} · checked as you type, nothing saved yet');

    return Material(
      color: theme.colorScheme.surface,
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
        decoration: BoxDecoration(
          border: Border(
            top: BorderSide(color: theme.colorScheme.outlineVariant),
          ),
        ),
        child: Row(
          children: [
            Expanded(
              child: Text(
                note,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: _error == null
                      ? null
                      : ToneColours.of(context, Tone.bad).ink,
                ),
              ),
            ),
            if (widget.canEdit) ...[
              if (changed.isNotEmpty)
                TextButton(
                  onPressed: _saving ? null : _discard,
                  child: const Text('Discard'),
                ),
              const SizedBox(width: 8),
              FilledButton(
                key: const Key('seo-save'),
                onPressed: _saving || changed.isEmpty ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  // ── what you type ────────────────────────────────────────────────

  Widget _label(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Text(text, style: Theme.of(context).textTheme.labelLarge),
  );

  Widget _meter(BuildContext context, double share, Tone tone, String words) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(3),
            child: LinearProgressIndicator(
              value: share.clamp(0.0, 1.0),
              minHeight: 6,
              color: ToneColours.of(context, tone).ink,
              backgroundColor: theme.colorScheme.surfaceContainerHighest,
            ),
          ),
          const SizedBox(height: 3),
          Text(words, style: theme.textTheme.bodySmall),
        ],
      ),
    );
  }

  /// How wide the title prints, measured as Google sets it (20 px).
  double _titleWidth(String title) {
    final painter = TextPainter(
      text: TextSpan(text: title, style: const TextStyle(fontSize: 20)),
      textDirection: TextDirection.ltr,
      maxLines: 1,
    )..layout();
    final width = painter.width;
    painter.dispose();
    return width;
  }

  Widget _fields(BuildContext context) {
    final finalTitle = '${(_page?['final'] as Map?)?['title'] ?? ''}';
    final finalDescription =
        '${(_page?['final'] as Map?)?['description'] ?? ''}';
    final px = _titleWidth(finalTitle).round();
    final chars = finalDescription.length;
    final enabled = widget.canEdit && !_saving;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _label(context, 'Focus keyphrase'),
        TextField(
          key: const Key('seo-keyphrase'),
          controller: _keyphrase,
          enabled: enabled,
          onChanged: (_) => _edited(),
          decoration: const InputDecoration(
            isDense: true,
            helperText: 'What somebody types into Google to find this page.',
          ),
        ),
        const SizedBox(height: 16),
        _label(context, 'SEO title'),
        TextField(
          key: const Key('seo-title'),
          controller: _title,
          enabled: enabled,
          onChanged: (_) => _edited(),
          decoration: const InputDecoration(isDense: true),
        ),
        _meter(
          context,
          px / 600,
          px == 0
              ? Tone.bad
              : (px > 600 ? Tone.bad : (px > 560 ? Tone.caution : Tone.ok)),
          '~$px of 600 px',
        ),
        if (widget.canEdit) ...[
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('Insert', style: Theme.of(context).textTheme.bodySmall),
              for (final (words, key) in [
                ('Page title', 'title'),
                ('Separator', 'sep'),
                ('Site name', 'sitename'),
                ('Year', 'year'),
              ])
                ActionChip(
                  key: Key('seo-insert-$key'),
                  label: Text('+ $words'),
                  onPressed: enabled ? () => _insert('{$words}') : null,
                ),
            ],
          ),
        ],
        const SizedBox(height: 16),
        _label(context, 'Meta description'),
        TextField(
          key: const Key('seo-description'),
          controller: _description,
          enabled: enabled,
          minLines: 3,
          maxLines: 5,
          onChanged: (_) => _edited(),
          decoration: const InputDecoration(isDense: true),
        ),
        _meter(context, chars / 155, switch (chars) {
          0 => Tone.bad,
          < 70 => Tone.bad,
          < 120 => Tone.caution,
          <= 156 => Tone.ok,
          <= 170 => Tone.caution,
          _ => Tone.bad,
        }, '$chars of about 155 characters'),
        const SizedBox(height: 12),
        _fold(
          context,
          'Social preview',
          'title, description and image when the page is shared',
          [
            TextField(
              controller: _socialTitle,
              enabled: enabled,
              onChanged: (_) => _edited(),
              decoration: const InputDecoration(
                isDense: true,
                labelText: 'Title when shared',
                hintText: 'Uses the SEO title',
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _socialDescription,
              enabled: enabled,
              onChanged: (_) => _edited(),
              decoration: const InputDecoration(
                isDense: true,
                labelText: 'Description when shared',
                hintText: 'Uses the meta description',
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _socialImage,
              enabled: enabled,
              onChanged: (_) => _edited(),
              decoration: const InputDecoration(
                isDense: true,
                labelText: 'Image address',
                hintText: 'https://…',
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        _fold(context, 'Advanced', 'hide from Google · canonical URL', [
          SwitchListTile(
            key: const Key('seo-noindex'),
            contentPadding: EdgeInsets.zero,
            value: _noindex,
            onChanged: enabled
                ? (on) {
                    setState(() => _noindex = on);
                    _edited();
                  }
                : null,
            title: const Text('Hide this page from Google'),
            subtitle: const Text(
              'Google stops showing it in results. For thank-you pages and '
              'duplicates, not for pages that bring customers.',
            ),
          ),
          TextField(
            controller: _canonical,
            enabled: enabled,
            onChanged: (_) => _edited(),
            decoration: const InputDecoration(
              isDense: true,
              labelText: 'Canonical URL',
              hintText: 'Leave empty: the page is its own original',
            ),
          ),
        ]),
      ],
    );
  }

  Widget _fold(
    BuildContext context,
    String title,
    String note,
    List<Widget> children,
  ) {
    final theme = Theme.of(context);

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: ExpansionTile(
        shape: const Border(),
        collapsedShape: const Border(),
        title: Text(title, style: theme.textTheme.titleSmall),
        subtitle: Text(note, style: theme.textTheme.bodySmall),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        children: children,
      ),
    );
  }

  // ── what Google shows, and what the checks say ───────────────────

  Map<String, dynamic> get _analysis =>
      (_page?['analysis'] as Map?)?.cast<String, dynamic>() ?? const {};

  /// What Google shows, and what the live page was read as.
  Widget _looks(BuildContext context) {
    final theme = Theme.of(context);
    final page = _page!;
    final analysis = _analysis;
    final words = (analysis['words'] as num?)?.toInt() ?? 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text('Google preview', style: theme.textTheme.titleSmall),
            ),
            SegmentedButton<bool>(
              key: const Key('seo-device'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: false, label: Text('Desktop')),
                ButtonSegment(value: true, label: Text('Phone')),
              ],
              selected: {_phone},
              onSelectionChanged: (picked) =>
                  setState(() => _phone = picked.first),
            ),
          ],
        ),
        const SizedBox(height: 8),
        _serp(context, page),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: ToneColours.of(context, Tone.info).fill,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            words > 0
                ? 'Checked against the live page: $words words. The checks read what Google reads, not the editor.'
                : 'The live page could not be read, so its words were not checked.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: ToneColours.of(context, Tone.info).ink,
            ),
          ),
        ),
      ],
    );
  }

  Widget _serp(BuildContext context, Map<String, dynamic> page) {
    final finalTitle = '${(page['final'] as Map?)?['title'] ?? ''}';
    var description = '${(page['final'] as Map?)?['description'] ?? ''}';
    // Google cuts a description at about 155 characters on a desktop and a
    // little less on a phone, with an ellipsis.
    final cut = _phone ? 120 : 155;
    if (description.length > cut) {
      description = '${description.substring(0, cut).trimRight()}…';
    }
    final uri = Uri.tryParse('${page['url'] ?? ''}');
    final crumbs = [
      if (uri != null) '${uri.scheme}://${uri.host}',
      ...?uri?.pathSegments.where((s) => s.isNotEmpty),
    ].join(' › ');

    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: ConstrainedBox(
        // The title is cut by width, not by letters: 600 px on a desktop.
        constraints: BoxConstraints(maxWidth: _phone ? 360 : 632),
        child: Container(
          key: const Key('seo-serp'),
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: _googlePaper,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: Theme.of(context).colorScheme.outlineVariant,
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                widget.host,
                style: const TextStyle(fontSize: 13, color: _googleInk),
              ),
              Text(
                crumbs,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 12, color: _googleText),
              ),
              const SizedBox(height: 4),
              Text(
                finalTitle.isEmpty ? '${page['name'] ?? ''}' : finalTitle,
                maxLines: _phone ? 2 : 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: _phone ? 18 : 20,
                  color: _googleLink,
                  height: 1.3,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                description.isEmpty
                    ? 'No description: Google picks a sentence from the page itself.'
                    : description,
                style: const TextStyle(
                  fontSize: 14,
                  color: _googleText,
                  height: 1.5,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _checks(
    BuildContext context,
    Map<String, dynamic> page,
    Map<String, dynamic> analysis,
  ) {
    final theme = Theme.of(context);
    List<Map<String, dynamic>> of(String group) {
      final list = ((analysis[group] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      const order = {'bad': 0, 'ok': 1, 'good': 2};
      list.sort(
        (a, b) => (order[a['status']] ?? 3).compareTo(order[b['status']] ?? 3),
      );
      return list;
    }

    final seo = of('seo');
    final readability = of('readability');
    final shown = _readability ? readability : seo;
    int toFix(List<Map<String, dynamic>> l) =>
        l.where((c) => c['status'] != 'good').length;
    final sharedWith =
        (page['shared_with'] as Map?)?.cast<String, dynamic>() ?? const {};

    // The switch is in the card, not its header: a header's controls cannot
    // shrink, and on a phone the two labels ran 171 px past the edge.
    return DashboardCard(
      title: 'Checks',
      icon: Icons.checklist,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TabBarLike(
            level: TabLevel.tertiary,
            key: const Key('seo-check-group'),
            labels: [
              ('SEO · ${toFix(seo)}', Icons.search),
              ('Readability · ${toFix(readability)}', Icons.menu_book_outlined),
            ],
            selected: _readability ? 1 : 0,
            onSelected: (i) => setState(() => _readability = i == 1),
          ),
          const SizedBox(height: 8),
          if (shown.isEmpty)
            Text('Nothing to check yet.', style: theme.textTheme.bodySmall),
          for (final check in shown)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 5),
                    child: _dot(context, check['status']),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${check['text'] ?? ''}',
                          style: theme.textTheme.bodyMedium,
                        ),
                        // A shared value names the other pages, and opens them.
                        for (final other in _others(sharedWith, check['key']))
                          TextButton(
                            style: TextButton.styleFrom(
                              padding: EdgeInsets.zero,
                              visualDensity: VisualDensity.compact,
                              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                            ),
                            onPressed: () =>
                                widget.onOpen((other['id'] as num).toInt()),
                            child: Text('${other['name'] ?? 'Another page'} ›'),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  static List<Map<String, dynamic>> _others(
    Map<String, dynamic> sharedWith,
    Object? key,
  ) {
    final kind = switch (key) {
      'keyphrase_unique' => 'keyphrase',
      'title_unique' => 'title',
      'description_unique' => 'description',
      _ => null,
    };
    if (kind == null) return const [];

    return ((sharedWith[kind] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
  }
}
