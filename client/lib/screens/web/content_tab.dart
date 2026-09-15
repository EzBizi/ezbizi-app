import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';

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
  });

  final ApiClient api;
  final String siteId;
  final String host;
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
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  decoration: InputDecoration(
                    labelText: 'Search ${widget.host}',
                    isDense: true,
                    prefixIcon: const Icon(Icons.search, size: 18),
                  ),
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
        const Divider(height: 1),
        Expanded(
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
          trailing: status == 'publish'
              ? null
              // Only the ones that are NOT live are worth marking. A badge on
              // every row is a column of noise saying what the reader assumes.
              : Chip(
                  label: Text(status),
                  visualDensity: VisualDensity.compact,
                ),
          onTap: link.isEmpty ? null : () => launchUrl(Uri.parse(link)),
        );
      },
    );
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
