import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';
import 'page_editor.dart';

/// One site's pages, or one site's posts, read live from the site itself.
///
/// ── Why this is a list and not a screen ──
///
/// Pages and Posts are sections of SEO rather than tabs of their own (Jose,
/// 2026-09-15), so the website is already chosen by the rail on the left and
/// this widget is handed the answer. It owned a site list of its own for one
/// commit; two rails choosing the same thing is two things to keep in step.
///
/// ── Why nothing here is editable ──
///
/// This shows what is on the customer's WordPress. The account we hold could
/// write to it, so the restraint is deliberate rather than technical: a row
/// opens the real thing in a browser. Publishing from EzBizi is a decision
/// nobody has taken.
class SiteContentList extends StatefulWidget {
  const SiteContentList({
    super.key,
    required this.api,
    required this.siteId,
    required this.host,
    required this.type,
    this.canEdit = false,
  });

  final ApiClient api;
  final String siteId;
  final String host;

  /// Passed down rather than asked for again: the rail that chose the site
  /// already knows, and two screens answering the same permission question
  /// differently is how a read-only user meets an editable box.
  final bool canEdit;
  /// `page` or `post` — the only thing that differs between the two.
  final String type;

  @override
  State<SiteContentList> createState() => _SiteContentListState();
}

class _SiteContentListState extends State<SiteContentList> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = const [];
  int _total = 0;
  int _pages = 0;
  int _page = 1;
  String _search = '';

  /// The row whose page is open in this pane, or null for the list.
  Map<String, dynamic>? _openRow;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String get _plural => widget.type == 'page' ? 'pages' : 'posts';

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final response = await widget.api.get('/web/sites/${widget.siteId}/content', {
      'type': widget.type,
      'page': '$_page',
      if (_search.trim().isNotEmpty) 'search': _search.trim(),
    });

    if (!mounted) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        _items = const [];
        return;
      }

      _items = ((response.body['items'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>();
      _total = (response.body['total'] as num?)?.toInt() ?? 0;
      _pages = (response.body['pages'] as num?)?.toInt() ?? 0;
      _movedToCities =
          (response.body['moved_to_cities'] as num?)?.toInt() ?? 0;
    });
  }

  /// How many rows of THIS batch went to the Cities pane instead.
  ///
  /// Said out loud because the alternative is somebody counting. Most city
  /// pages never reach here at all — WordPress excludes them by author, so
  /// they are not in the total either — and this covers the other way of being
  /// one: a page she typed a city onto that the brand account authored. A
  /// batch of fifty arriving as forty-eight with nothing to explain it is the
  /// kind of thing that gets reported as a bug.
  int _movedToCities = 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final open = _openRow;

    if (open != null) {
      return PageEditor(
        api: widget.api,
        siteId: widget.siteId,
        host: widget.host,
        path: _pathOf(open),
        title: '${open['title'] ?? ''}',
        link: '${open['link'] ?? ''}',
        canEdit: widget.canEdit,
        // What the website already says, so the two boxes WordPress fills in
        // are not shown empty on a page that has both.
        wpAuthor: open['author'] is int ? open['author'] as int : null,
        wpCategories: ((open['categories'] as List<dynamic>?) ?? const [])
            .whereType<int>()
            .toList(),
        onClose: () => setState(() => _openRow = null),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
          child: Row(
            children: [
              Expanded(
                child: ListSearch(
                  key: const ValueKey('tour-seo-content-search'),
                  hint: 'Search ${widget.host}',
                  onSubmitted: (value) {
                      _search = value;
                      _page = 1;
                      _load();
                    },
                ),
              ),
              const SizedBox(width: 12),
              // Live every time, so the count is the site's own rather than
              // ours. Said out loud because a stale list that looks fresh is
              // the failure this screen exists to avoid.
              Text(
                _loading ? '…' : '$_total $_plural',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
        if (_movedToCities > 0)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: Text(
              _movedToCities == 1
                  ? '1 city page on this list is in Cities instead.'
                  : '$_movedToCities city pages on this list are in Cities '
                      'instead.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        const Divider(height: 1),
        Expanded(
          key: const ValueKey('tour-seo-content-list'),
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _items.isEmpty ? _empty(theme) : _rows(theme),
          ),
        ),
        if (_pages > 1) _pager(theme),
      ],
    );
  }

  Widget _empty(ThemeData theme) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Text(
          _search.trim().isEmpty
              ? 'No $_plural to show.'
              : 'Nothing on ${widget.host} matches that.',
          style: theme.textTheme.bodySmall,
        ),
      ),
    );
  }

  Widget _rows(ThemeData theme) {
    return ListView.separated(
      itemCount: _items.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, i) {
        final item = _items[i];
        final status = '${item['status'] ?? ''}';
        final link = '${item['link'] ?? ''}';

        return ListTile(
          title: Text(
            '${item['title'] ?? ''}'.trim().isEmpty
                // WordPress allows an untitled draft, and a blank row reads as
                // a rendering fault rather than as the page it is.
                ? 'Untitled'
                : '${item['title']}',
          ),
          subtitle: Text(_line(item)),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (status != 'publish')
                // Only the ones that are NOT live are worth marking. A badge on
                // every row is a column of noise saying what the reader assumes.
                Chip(
                  label: Text(status),
                  visualDensity: VisualDensity.compact,
                ),
              if (link.isNotEmpty)
                IconButton(
                  tooltip: 'Open the page on the website',
                  icon: const Icon(Icons.open_in_new, size: 18),
                  onPressed: () => launchUrl(Uri.parse(link)),
                ),
            ],
          ),
          /*
           * The row opens the page's own EzBizi screen, and the icon beside it
           * opens the real page. It was the other way round for one release,
           * and Jose's words were "clicking them leads u to said page, which is
           * fine but not the main purpose" — a list of a website's pages inside
           * EzBizi exists to edit what EzBizi publishes onto them.
           */
          onTap: () => _open(item),
        );
      },
    );
  }

  /// This page's own screen.
  ///
  /// The PATH is what identifies a page to EzBizi, not the WordPress id: values
  /// are stored against a path because the plugin only knows what it is
  /// rendering, and a post id means nothing to a shortcode on a page.
  void _open(Map<String, dynamic> item) {
    final path = _pathOf(item);

    if (path.isEmpty) return;

    // In this pane, not a pushed route. Jose named it the moment he saw it:
    // going full screen breaks containment, and a page's fields are a detail
    // of the site in the rail beside them rather than a place you travel to.
    setState(() => _openRow = item);
  }

  /// The page's path on its own site.
  ///
  /// Taken from the live link rather than built from the slug: WordPress puts
  /// posts under a permalink structure the slug alone cannot reconstruct, and a
  /// path we guessed would store values against a page that does not exist.
  String _pathOf(Map<String, dynamic> item) {
    final link = '${item['link'] ?? ''}';

    if (link.isNotEmpty) {
      final parsed = Uri.tryParse(link);

      if (parsed != null && parsed.path.isNotEmpty && parsed.path != '/') {
        return parsed.path.endsWith('/')
            ? parsed.path.substring(0, parsed.path.length - 1)
            : parsed.path;
      }
    }

    final slug = '${item['slug'] ?? ''}'.trim();

    return slug.isEmpty ? '' : '/$slug';
  }

  /// The slug and when it last changed — the two things that tell one page from
  /// another when six of them are called "Services".
  String _line(Map<String, dynamic> item) {
    final slug = '${item['slug'] ?? ''}';
    final modified = '${item['modified'] ?? ''}';
    final when = modified.length >= 10 ? modified.substring(0, 10) : modified;

    if (slug.isEmpty) return when;
    if (when.isEmpty) return '/$slug';

    return '/$slug · changed $when';
  }

  Widget _pager(ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          Text('Page $_page of $_pages', style: theme.textTheme.bodySmall),
          const SizedBox(width: 12),
          IconButton(
            tooltip: 'Previous',
            icon: const Icon(Icons.chevron_left),
            onPressed: _page <= 1
                ? null
                : () {
                    _page -= 1;
                    _load();
                  },
          ),
          IconButton(
            tooltip: 'Next',
            icon: const Icon(Icons.chevron_right),
            onPressed: _page >= _pages
                ? null
                : () {
                    _page += 1;
                    _load();
                  },
          ),
        ],
      ),
    );
  }
}
