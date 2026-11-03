import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../widgets/async_body.dart';
import 'page_editor.dart';

/// One site's city pages, grouped by the region their owner already types.
///
/// ── Why this is separate from Pages ──
///
/// Mia, 2026-09-18: city pages "just need to be separated from the current
/// pages tab, and moved into a cities tab, i believe toula has already filled a
/// lot of information on them already on ezbizi we dont want to destroy her
/// work."
///
/// Measured on production the same day: 8,956 page paths carry a `city`, and
/// call.a-1chimney.com has 609 of them. In the Pages list they bury every real
/// page somebody was looking for — About Us is on screen four.
///
/// ── Why it reads EzBizi and Pages reads the website ──
///
/// Pages is a window onto the customer's WordPress, because the website is the
/// record of what pages exist. A city page is a window onto something else: the
/// facts EzBizi publishes about a town, which are ours. So this pane opens
/// instantly, needs no credential, and still works on the afternoon somebody's
/// WordPress is down — which is exactly when a person wants to check what we
/// are publishing.
///
/// ── Why the scan is a button ──
///
/// "Which towns does the site have pages for that EzBizi holds nothing about?"
/// needs the live page list and its authors, which is thirteen round trips to a
/// 609-page site. Asking on every open would make a three-second pane, and a
/// three-second pane is one nobody opens. So it is a button, and its answer
/// folds into the same list marked "not in EzBizi".
///
/// NOTHING HERE WRITES. Rows open `PageEditor`, which is the screen she already
/// uses; this pane has no save of its own and no endpoint that would take one.
class CitiesPane extends StatefulWidget {
  const CitiesPane({
    super.key,
    required this.api,
    required this.siteId,
    required this.host,
    this.canEdit = false,
  });

  final ApiClient api;
  final String siteId;
  final String host;

  /// Passed down rather than asked for again, for the reason the content list
  /// gives: two screens answering one permission question differently is how a
  /// read-only user meets an editable box.
  final bool canEdit;

  @override
  State<CitiesPane> createState() => _CitiesPaneState();
}

class _CitiesPaneState extends State<CitiesPane> {
  bool _loading = true;
  bool _scanning = false;
  String? _error;

  /// A note from the server about the scan — a website that refused, or one
  /// that returned nothing. Never fatal: the cities below it came from our own
  /// database and are already correct.
  String? _note;

  List<Map<String, dynamic>> _cities = const [];
  String _search = '';

  /// Groups the person has opened. Collapsed is the default because ten
  /// regions of sixty towns is not a list, and the count on the header is the
  /// thing somebody is actually reading.
  final Set<String> _open = {};

  Map<String, dynamic>? _openRow;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool scan = false}) async {
    setState(() {
      if (scan) {
        _scanning = true;
      } else {
        _loading = true;
      }
      _error = null;
    });

    final response = await widget.api.get(
      '/web/sites/${widget.siteId}/cities',
      scan ? const {'scan': '1'} : const <String, String>{},
    );

    if (!mounted) return;

    setState(() {
      _loading = false;
      _scanning = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _cities = ((response.body['cities'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>();
      _note = response.body['note'] as String?;
    });
  }

  /// The label a blank group is drawn under.
  ///
  /// A word rather than an empty header: 7,904 of the 8,956 city pages carry a
  /// group, so the ones that do not are a real and much shorter list — the
  /// towns still to be filed — and they are worth naming rather than hiding.
  static const String _ungrouped = 'Not grouped yet';

  String _groupOf(Map<String, dynamic> city) {
    final group = '${city['group'] ?? ''}'.trim();

    return group.isEmpty ? _ungrouped : group;
  }

  /// The cities that match what has been typed, by town, region or path.
  ///
  /// Filtered here rather than at the server because the whole list is already
  /// in this pane — the request that brought it is the only one this screen
  /// makes — so searching costs nothing and works with no network at all.
  List<Map<String, dynamic>> get _matching {
    final needle = _search.trim().toLowerCase();

    if (needle.isEmpty) return _cities;

    return _cities.where((city) {
      return '${city['city'] ?? ''}'.toLowerCase().contains(needle) ||
          '${city['group'] ?? ''}'.toLowerCase().contains(needle) ||
          '${city['state'] ?? ''}'.toLowerCase().contains(needle) ||
          '${city['path'] ?? ''}'.toLowerCase().contains(needle);
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final open = _openRow;

    if (open != null) {
      final path = '${open['path'] ?? ''}';

      return PageEditor(
        api: widget.api,
        siteId: widget.siteId,
        host: widget.host,
        path: path,
        title: _titleFor(open),
        // Built from the host and the path rather than carried: a city row can
        // come from our values alone, where no permalink was ever read, and
        // "open the real page" must work for those too.
        link: 'https://${widget.host}$path',
        canEdit: widget.canEdit,
        onClose: () {
          setState(() => _openRow = null);
          // Reloaded because she may have just filled one in, and the count
          // on the row beside it would otherwise still say what it said
          // before she typed.
          _load();
        },
      );
    }

    final matching = _matching;
    final searching = _search.trim().isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
          child: Row(
            children: [
              Expanded(
                child: ListSearch(
                  key: const ValueKey('tour-seo-cities-search'),
                  hint: 'Search the cities on ${widget.host}',
                  onChanged: (value) => setState(() => _search = value),
                ),
              ),
              const SizedBox(width: 12),
              Text(
                _loading ? '…' : _count(matching.length),
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(width: 8),
              IconButton(
                key: const ValueKey('tour-seo-cities-scan'),
                tooltip: 'Look on the website for city pages EzBizi has '
                    'nothing for yet',
                icon: _scanning
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.travel_explore_outlined, size: 18),
                onPressed: _scanning ? null : () => _load(scan: true),
              ),
            ],
          ),
        ),
        if (_note != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: Text(
              // A note, not an error. The list under it is right.
              _note!,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        const Divider(height: 1),
        Expanded(
          key: const ValueKey('tour-seo-cities-list'),
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: matching.isEmpty
                ? _empty(theme, searching)
                : _groups(theme, matching, searching),
          ),
        ),
      ],
    );
  }

  String _count(int shown) {
    if (shown == _cities.length) {
      return _cities.length == 1 ? '1 city' : '${_cities.length} cities';
    }

    return '$shown of ${_cities.length}';
  }

  Widget _empty(ThemeData theme, bool searching) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Text(
          searching
              ? 'No city on ${widget.host} matches that.'
              : 'EzBizi publishes nothing about a city on this website yet. '
                  'Search the website to find its city pages.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall,
        ),
      ),
    );
  }

  Widget _groups(
    ThemeData theme,
    List<Map<String, dynamic>> cities,
    bool searching,
  ) {
    // The server has already sorted these by group, with the ungrouped last,
    // so insertion order is the display order and nothing is sorted twice.
    final grouped = <String, List<Map<String, dynamic>>>{};

    for (final city in cities) {
      grouped.putIfAbsent(_groupOf(city), () => []).add(city);
    }

    final labels = grouped.keys.toList();

    return ListView.builder(
      itemCount: labels.length,
      itemBuilder: (context, i) {
        final label = labels[i];
        final rows = grouped[label]!;

        return _GroupTile(
          label: label,
          count: rows.length,
          // A search that collapsed its own results would hide the thing
          // somebody just asked for.
          expanded: searching || _open.contains(label),
          onToggle: () => setState(() {
            _open.contains(label) ? _open.remove(label) : _open.add(label);
          }),
          children: [
            for (final city in rows) _row(theme, city),
          ],
        );
      },
    );
  }

  Widget _row(ThemeData theme, Map<String, dynamic> city) {
    final path = '${city['path'] ?? ''}';
    final phone = '${city['phone'] ?? ''}'.trim();
    final held = city['in_ezbizi'] != false;
    final filled = (city['filled'] as num?)?.toInt() ?? 0;

    return ListTile(
      dense: true,
      title: Text(_titleFor(city)),
      subtitle: Text([
        path,
        if (phone.isNotEmpty) phone,
      ].join('  ·  ')),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (!held)
            // The row the scan added: the website has this town and EzBizi
            // publishes nothing about it. This is the work, so it is the one
            // thing on a row worth a badge.
            Chip(
              label: const Text('nothing yet'),
              visualDensity: VisualDensity.compact,
            )
          else
            Text(
              filled == 1 ? '1 value' : '$filled values',
              style: theme.textTheme.bodySmall,
            ),
          IconButton(
            tooltip: 'Open the page on the website',
            icon: const Icon(Icons.open_in_new, size: 18),
            onPressed: () =>
                launchUrl(Uri.parse('https://${widget.host}$path')),
          ),
        ],
      ),
      onTap: () => setState(() => _openRow = city),
    );
  }

  /// The town, as a person would say it.
  ///
  /// The city and state she typed when we have them, the website's own title
  /// when we do not, and the path as the last resort — a blank row reads as a
  /// rendering fault rather than as the page it is.
  String _titleFor(Map<String, dynamic> city) {
    final name = '${city['city'] ?? ''}'.trim();
    final state = '${city['state'] ?? ''}'.trim();
    final title = '${city['title'] ?? ''}'.trim();

    if (name.isNotEmpty) {
      return state.isEmpty ? name : '$name, $state';
    }

    return title.isNotEmpty ? title : '${city['path'] ?? ''}';
  }
}

/// One region, with its towns folded under it.
///
/// Not an `ExpansionTile`: that animates a rebuild of every child on expand,
/// and a region here can hold 258 towns — South Jersey does, on
/// call.a-1chimney.com. This draws the children only while open.
class _GroupTile extends StatelessWidget {
  const _GroupTile({
    required this.label,
    required this.count,
    required this.expanded,
    required this.onToggle,
    required this.children,
  });

  final String label;
  final int count;
  final bool expanded;
  final VoidCallback onToggle;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListTile(
          leading: Icon(
            expanded ? Icons.expand_more : Icons.chevron_right,
            size: 20,
          ),
          title: Text(label, style: theme.textTheme.titleSmall),
          trailing: Text(
            count == 1 ? '1 city' : '$count cities',
            style: theme.textTheme.bodySmall,
          ),
          onTap: onToggle,
        ),
        if (expanded) ...children,
        const Divider(height: 1),
      ],
    );
  }
}
