import 'package:flutter/material.dart';

import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/address_lookup.dart';
import '../../widgets/async_body.dart';
import '../../widgets/not_filled_in.dart';
import '../../widgets/search_picker.dart';
import 'cities_pane.dart';
import 'content_tab.dart';
import 'templates_pane.dart';
import '../../widgets/error_note.dart';
import '../../widgets/shortcode_button.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/tab_bar_like.dart';
import 'hours.dart';
import 'hours_bulk.dart';
import 'seo_helper_pane.dart';

/// The absolute base a copied snippet must carry, and why it cannot be blank.
///
/// `ApiClient.baseUrl` is deliberately EMPTY in the same-origin web build — see
/// api_client.dart, which argues at length that a hostname must never be baked
/// in. That is right for the app talking to itself and exactly wrong for a
/// string somebody pastes into a DIFFERENT website: interpolating an empty base
/// produced `src="/api/v1/web/ezbizi.js"`, which resolves against whatever host
/// the snippet was pasted into. On www.ezbizi.com that is a 404; it only ever
/// worked on app.ezbizi.com, the one host that will never need it.
///
/// So the snippet takes the ORIGIN the app is being served from when no base is
/// configured. Pure and parameterised because the interesting cases — a blank
/// base, a native build with a real base, a test process whose `Uri.base` is a
/// `file:` URL with no origin at all — are worth asserting rather than eyeballing.
String snippetApiBase({required String configured, required Uri pageUrl}) {
  if (configured.isNotEmpty) return configured;

  // `Uri.origin` throws on anything that is not http/https, and a widget test
  // runs with a `file:` base. Falling back to the canonical host is better than
  // handing somebody a relative URL that silently does nothing.
  if (pageUrl.scheme == 'http' || pageUrl.scheme == 'https') {
    return pageUrl.origin;
  }

  return 'https://app.ezbizi.com';
}

/// Where the plugin comes from, and why it is not us any more.
///
/// wordpress.org serves the tag named by `Stable tag`, so this is current the
/// moment an SVN release lands and needs no deploy of ours. The route we still
/// expose, `/web/plugin`, builds a zip from the `wordpress-plugin/` directory
/// ON THE BOX — which is whatever release is deployed, so it lags every plugin
/// release. Jose downloaded 1.0.0 from a button beside a panel describing
/// 1.0.2, which is the second-source-of-truth problem with a version number
/// attached to it.
final Uri pluginDownloadUrl =
    Uri.parse('https://downloads.wordpress.org/plugin/ezbizi.zip');

/// SEO — the hosts a company owns, and the business facts each one publishes.
///
/// ── What this screen is actually for ─────────────────────────────
///
/// EzBizi holds the company's phone number, address, licence and hours. The
/// company's own WordPress renders them through shortcodes. So the work here is
/// small and specific: register each host, hand over its token once, and fill
/// in the boxes per host. A subdomain is a separate host with its own boxes,
/// which is how the New Jersey site answers a New Jersey number.
///
/// ── No pop-ups, which shapes every interaction on this screen ────
///
/// §6: a new screen or a right-side panel, never a modal. So adding a website
/// is a form that opens in place at the top of the list; issuing a token and
/// removing a site are confirm rows that open inside the card they act on; and
/// a freshly issued token appears in that same card rather than in a dialog
/// somebody dismisses before copying. Each of those started as a `showDialog`
/// and was wrong — worth writing down, because the dialog version is the one
/// that comes to mind first every time.
///
/// ── List and detail, and what that cost ──────────────────────────
///
/// This was one column of expanding cards, and the comment here argued for it:
/// a company has three or four sites, and a split needs a wide layout, a narrow
/// layout, and a selection two panes can disagree about. Jose asked for the
/// split on 2026-08-28. The argument was about COST rather than correctness, so
/// it is recorded here rather than defended — and the cost was real, so the
/// three things it predicted are each answered rather than hoped away:
///
///   · the wide layout is `_split`, two panes that each scroll inside
///     themselves — the first attempt let the row size to its tallest child
///     and overflowed the viewport by however far the form ran past it;
///   · the narrow layout is `_stacked`, where the rail IS the screen and
///     choosing a site pushes the detail (§6 allows a screen, not a modal);
///   · the panes cannot disagree because there is ONE `_selectedId`, and at
///     narrow width only one of them is on screen at a time.
///
/// The move also broke something the old shape hid: fetching a site's values
/// was tied to EXPANDING its card, so a pane with no expand fetched nothing and
/// every site rendered empty. See `_SiteDetailState.initState`.
///
/// ── The token is shown once, and the screen says so ──────────────
///
/// The server keeps only a hash, so that block is the only time anybody sees
/// it. Saying "copy it now" beside it is not decoration: the alternative is a
/// support call that ends in "rotate it", which is the same work done twice.
class SeoTab extends StatefulWidget {
  const SeoTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<SeoTab> createState() => _SeoTabState();
}

class _SeoTabState extends State<SeoTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _sites = [];

  /// What the search bar over the site list holds; matched against what each
  /// tile shows.
  String _query = '';

  List<Map<String, dynamic>> get _shownSites {
    final needle = _query.trim().toLowerCase();
    if (needle.isEmpty) return _sites;
    return [
      for (final site in _sites)
        if ([
          '${site['host'] ?? ''}',
          site['connected_at'] != null ? 'Connected' : 'Waiting for the plugin',
        ].any((text) => text.toLowerCase().contains(needle)))
          site,
    ];
  }

  bool _adding = false;

  /// Tokens issued in this session, by site — shown in the card they belong to
  /// until the person dismisses them. Never fetched, because the server cannot
  /// answer for one.
  final Map<String, String> _freshTokens = {};

  /// Which site the detail pane is showing. Null until the first load, and
  /// falls back to the first site rather than an empty pane.
  String? _selectedId;

  /// The hours editor for several websites, open in this tab, and the site it
  /// was opened from. Null when the tab shows its websites.
  String? _bulkFrom;

  /// A phone's pushed site page, closed before the editor opens in the tab so
  /// the editor does not open behind it.
  Route<void>? _pushedSite;

  bool get _canEdit => widget.session.me!.can('web_management.edit');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _sites.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/web/sites');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (response.ok) {
        _sites = (response.body['data'] as List<dynamic>)
            .cast<Map<String, dynamic>>();
        _sections = [
          // The server's sections are groups of FIELDS, which is why these two
          // are added here instead. Pages and Posts are not fields — they are
          // the site's own content, read live — and putting them in
          // SeoFields::sections would make the field catalogue describe two
          // things it does not contain.
          ...((response.body['sections'] as List<dynamic>?) ?? [])
              .cast<Map<String, dynamic>>(),
          const {'key': _pagesKey, 'label': 'Pages'},
          /*
           * Cities sits next to Pages because it IS pages — the ones Toula has
           * published a town onto, 8,956 of them, which Pages no longer lists.
           * See `PageKinds` for what separates the two lists and why they can
           * never both claim a page.
           */
          const {'key': _citiesKey, 'label': 'Cities'},
          const {'key': _postsKey, 'label': 'Posts'},
          const {'key': _templatesKey, 'label': 'Templates'},
          // Design 29: the site's search health and each page's title and
          // description, checked. Last, because it reads across the others.
          const {'key': _seoHelperKey, 'label': 'SEO Helper'},
        ];

        /*
         * The selection is a KEY and is re-found here, because this list is
         * rebuilt every time a different site is chosen and the field sections
         * come from the server — so its LENGTH and its ORDER both change under
         * somebody who is reading a pane.
         *
         * It used to be an index with a `>= length` guard, which survived the
         * list getting shorter and quietly moved you to a different pane when
         * it changed shape. Inserting Cities in the middle is what turns that
         * from a latent defect into a visible one: sitting on Posts and
         * switching site would land on Cities. It is the same trap Settings
         * described for tab bars (`docs/agents/inbox/0051`), one level down, in
         * a strip of panes rather than tabs — and it needs no permissions to
         * bite, only a list that changes length.
         */
        if (!_sections.any((section) => section['key'] == _sectionKey)) {
          _sectionKey = _generalKey;
        }
      } else {
        _error = response.message;
      }
    });
  }

  /// Below this, two panes do not fit and the rail becomes the screen.
  ///
  /// 900 rather than a tidier number because the detail pane needs about 560
  /// for a field row plus its shortcode chip without the chip wrapping, and the
  /// rail needs 280 to show a subdomain without truncating it.
  static const double _splitFrom = 900;

  Map<String, dynamic>? get _selected {
    if (_sites.isEmpty) return null;

    for (final site in _sites) {
      if (site['id'] == _selectedId) return site;
    }

    return _sites.first;
  }

  /// The sections inside SEO, as the server names them.
  ///
  /// Sent with the sites rather than mirrored here: a section is a property of
  /// the field catalogue, and a copy in Dart is a second place to edit and a
  /// second place to be wrong. Empty until the first load, which is why the
  /// strip is hidden rather than empty below.
  /// The sections that show content rather than fields.
  static const String _pagesKey = 'pages';
  static const String _postsKey = 'posts';

  /// Her city pages, separated out of Pages on 2026-09-18.
  static const String _citiesKey = 'cities';

  /// The one the strip falls back to. Named rather than "the first", because
  /// the first is only General by convention and the fallback has to be a pane
  /// that exists whatever the server sent.
  static const String _generalKey = 'general';

  /// Text shared by many pages, so it belongs beside Pages and Posts rather
  /// than among the field groups: like them it is content, not a field group.
  static const String _templatesKey = 'templates';

  /// Every page's search title, description and keyphrase, checked (design 29).
  static const String _seoHelperKey = 'seo_helper';

  bool get _showingContent =>
      _sectionKey == _pagesKey || _sectionKey == _postsKey;

  List<Map<String, dynamic>> _sections = [];

  /// Which pane is showing, BY KEY.
  ///
  /// Not an index. See `_load`.
  String _sectionKey = _generalKey;

  @override
  Widget build(BuildContext context) {
    // In the tab, never over it: a full-screen page breaks containment.
    final bulkFrom = _bulkFrom;
    if (bulkFrom != null) {
      return HoursBulkPane(
        api: widget.api,
        formats: widget.session.me!.display,
        startWith: bulkFrom,
        companies: widget.session.me!.companies,
        onClose: () {
          setState(() => _bulkFrom = null);
          _load();
        },
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // The app's one strip (UI/UX, 2026-09-28): a single scrolling row,
        // the chosen section filled. The underline row wrapped Templates onto
        // a second line on a phone.
        if (_sections.isNotEmpty) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 8),
            child: TabBarLike(
              level: TabLevel.tertiary,
              key: const ValueKey('seo-sections'),
              labels: [
                for (final section in _sections)
                  (section['label'] as String, _sectionIcon(section['key'] as String)),
              ],
              // The key is re-found on every load, so it is always in the list.
              selected: _sections.indexWhere((s) => s['key'] == _sectionKey).clamp(0, _sections.length - 1),
              onSelected: (i) => setState(
                () => _sectionKey = _sections[i]['key'] as String,
              ),
            ),
          ),
          const Divider(height: 1),
        ],
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: LayoutBuilder(
              builder: (context, constraints) =>
                  constraints.maxWidth >= _splitFrom
                      ? _split()
                      : _stacked(context),
            ),
          ),
        ),
      ],
    );
  }

  /// Wide: list left, detail right, one selection shared by both.
  Widget _split() {
    final selected = _selected;

    /*
     * `stretch` rather than `start`: each pane must take the full height and
     * scroll INSIDE itself. With `start` the row was as tall as its tallest
     * child, so a site with eight fields overflowed the viewport by however
     * much the form exceeded it — three pixels in the test fixture, and a lot
     * more with real content.
     */
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          width: 288,
          child: _rail(
            onOpen: (site) async =>
                setState(() => _selectedId = site['id'] as String),
          ),
        ),
        const VerticalDivider(width: 1),
        Expanded(
          child: selected == null
              ? const Center(
                  child: Padding(
                    padding: EdgeInsets.all(32),
                    child: Text('Add a website to set what it publishes.'),
                  ),
                )
              : _paneFor(selected),
        ),
      ],
    );
  }

  /// Narrow: the rail IS the screen, and choosing a site pushes the detail.
  ///
  /// §6 allows a new screen and forbids a modal, so this is a push rather than
  /// a sheet. It is also why the two panes never disagree about the selection
  /// at this width — there is only ever one of them on screen.
  Widget _stacked(BuildContext context) {
    return _rail(
      onOpen: (site) async {
        setState(() => _selectedId = site['id'] as String);

        final page = MaterialPageRoute<void>(
          builder: (_) => Scaffold(
            appBar: AppBar(title: Text(site['host'] as String)),
            body: SafeArea(
              child: _paneFor(site),
            ),
          ),
        );
        _pushedSite = page;
        await Navigator.of(context).push(page);
        _pushedSite = null;

        // The detail can rename, remove or reconnect a site, so the list it
        // came back to has to be re-read rather than trusted.
        await _load();
      },
    );
  }

  Widget _rail({required Future<void> Function(Map<String, dynamic>) onOpen}) {
    return ListView(
      key: const ValueKey('tour-web_management-sites'),
      padding: const EdgeInsets.all(16),
      children: [
        // No headline: the module title above already says where this is
        // (UI/UX, 2026-09-28), and no other module draws a second one.
        PanelHeader(
          note:
              'Every website and subdomain this company runs. Each one keeps '
              'its own details, so a branch site can answer its own phone '
              'number.',
          action: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              // As tall as the buttons beside it (Jose, 2026-10-20).
              SizedBox(
                width: 240,
                height: 40,
                child: TextField(
                  key: const Key('web-seo-sites-search'),
                  textAlignVertical: TextAlignVertical.center,
                  decoration: const InputDecoration(
                    isDense: true,
                    contentPadding: EdgeInsets.symmetric(horizontal: 12),
                    prefixIconConstraints: BoxConstraints(minWidth: 36),
                    prefixIcon: Icon(Icons.search, size: 18),
                    hintText: 'Find a website',
                    border: OutlineInputBorder(),
                  ),
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
              if (_canEdit && !_adding)
                FilledButton.icon(
                  key: const ValueKey('tour-web_management-add-site'),
                  onPressed: () => setState(() => _adding = true),
                  icon: const Icon(Icons.add),
                  label: const Text('Add a website'),
                ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        if (_adding)
          _AddSiteForm(
            api: widget.api,
            session: widget.session,
            onCancel: () => setState(() => _adding = false),
            onAdded: (site, token) async {
              setState(() {
                _adding = false;
                _selectedId = site['id'] as String;
                _freshTokens[site['id'] as String] = token;
              });
              await _load();
            },
          ),
        if (_sites.isEmpty && !_adding)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 32),
            child: Center(
              child: Text(
                'No websites yet. Add the first one to get its plugin token.',
              ),
            ),
          ),
        if (_sites.isNotEmpty && _shownSites.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Text(
              'No websites match "${_query.trim()}".',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        for (final site in _shownSites)
          _SiteTile(
            key: ValueKey('tile-${site['id']}'),
            site: site,
            selected: site['id'] == _selected?['id'],
            onTap: () => onOpen(site),
          ),
      ],
    );
  }

  /// The host this site inherits from — its company's primary, unless it is
  /// the primary itself, in which case nothing sits above it.
  String? _primaryHostFor(Map<String, dynamic> site) {
    if (site['is_primary'] == true) return null;

    for (final other in _sites) {
      if (other['is_primary'] == true &&
          other['company']?['id'] == site['company']?['id']) {
        return other['host'] as String?;
      }
    }

    return null;
  }

  /// The fields for this site, or its pages or posts.
  ///
  /// The content list scrolls itself and must NOT be wrapped: handing a list
  /// unbounded height inside a scroll view builds every row at once and takes
  /// the scrolling with it.
  Widget _paneFor(Map<String, dynamic> site) {
    if (_sectionKey == _seoHelperKey) {
      return SeoHelperPane(
        key: ValueKey('${site['id']}:seo-helper'),
        api: widget.api,
        siteId: site['id'] as String,
        host: site['host'] as String,
        canEdit: _canEdit,
      );
    }

    if (_sectionKey == _templatesKey) {
      return TemplatesPane(
        key: ValueKey('${site['id']}:templates'),
        api: widget.api,
        siteId: site['id'] as String,
        host: site['host'] as String,
        canEdit: _canEdit,
      );
    }

    if (_sectionKey == _citiesKey) {
      return CitiesPane(
        key: ValueKey('${site['id']}:cities'),
        api: widget.api,
        siteId: site['id'] as String,
        host: site['host'] as String,
        canEdit: _canEdit,
      );
    }

    if (_showingContent) {
      return SiteContentList(
        // Keyed by site AND section, so switching either refetches rather than
        // leaving one site's pages under another site's name.
        key: ValueKey('${site['id']}:$_sectionKey'),
        api: widget.api,
        siteId: site['id'] as String,
        host: site['host'] as String,
        type: _sectionKey == _pagesKey ? 'page' : 'post',
        // Asked here, where the answer already is. A page's screen must not
        // decide the permission for itself and reach a different answer.
        canEdit: _canEdit,
      );
    }

    return SingleChildScrollView(child: _detailFor(site));
  }

  Widget _detailFor(Map<String, dynamic> site) => _SiteDetail(
        key: ValueKey('detail-${site['id']}'),
        api: widget.api,
        site: site,
        canEdit: _canEdit,
        section: _sectionKey,
        primaryHost: _primaryHostFor(site),
        freshToken: _freshTokens[site['id'] as String],
        onForgetToken: () =>
            setState(() => _freshTokens.remove(site['id'] as String)),
        onToken: (token) =>
            setState(() => _freshTokens[site['id'] as String] = token),
        onChanged: _load,
        // The branches of THIS website's company, for "where its enquiries land".
        branches: [
          for (final f in widget.session.me!.franchises)
            if (f.companyId == (site['company'] as Map?)?['id']) f,
        ],
        onBulkHours: _canEdit
            ? () {
                final pushed = _pushedSite;
                if (pushed != null) Navigator.of(context).removeRoute(pushed);
                setState(() => _bulkFrom = site['id'] as String);
              }
            : null,
      );
}

/// Each SEO section's icon, by key; a section the server adds later gets a plain one.
IconData _sectionIcon(String key) => switch (key) {
      'general' => Icons.tune,
      'social' => Icons.share_outlined,
      'pages' => Icons.article_outlined,
      'cities' => Icons.location_city_outlined,
      'posts' => Icons.edit_note,
      'templates' => Icons.content_copy_outlined,
      'seo_helper' => Icons.insights_outlined,
      _ => Icons.label_outline,
    };

/// One row in the rail: host, whether a plugin has ever read it, and nothing
/// else. The detail pane is where everything about a site lives, so a tile that
/// summarised more would be a second place to keep in step.
class _SiteTile extends StatelessWidget {
  const _SiteTile({
    super.key,
    required this.site,
    required this.selected,
    required this.onTap,
  });

  final Map<String, dynamic> site;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final connected = site['connected_at'] != null;
    final missing = (site['missing'] as num?)?.toInt() ?? 0;

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Material(
        color: selected
            ? theme.colorScheme.primaryContainer.withValues(alpha: 0.45)
            : Colors.transparent,
        borderRadius: BorderRadius.circular(8),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(8),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 6, right: 10),
                  child: Icon(
                    connected ? Icons.circle : Icons.circle_outlined,
                    size: 9,
                    color: connected
                        ? theme.colorScheme.primary
                        : theme.colorScheme.outline,
                  ),
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        site['host'] as String,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      Text(
                        connected
                            ? 'Connected'
                            : 'Waiting for the plugin',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.outline,
                        ),
                      ),
                    ],
                  ),
                ),
                // Jose, 2026-09-25: a counter beside each website of the
                // fields it is missing. General fields only, counted by the
                // server with the rule the boxes are highlighted by.
                if (missing > 0)
                  Padding(
                    padding: const EdgeInsets.only(left: 8, top: 2),
                    child: MissingCount(
                      missing,
                      tooltip: '$missing general '
                          '${missing == 1 ? 'field' : 'fields'} not filled in',
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Register a host. Opens in place, above the list it will join.
class _AddSiteForm extends StatefulWidget {
  const _AddSiteForm({
    required this.api,
    required this.session,
    required this.onCancel,
    required this.onAdded,
  });

  final ApiClient api;
  final Session session;
  final VoidCallback onCancel;
  final Future<void> Function(Map<String, dynamic> site, String token) onAdded;

  @override
  State<_AddSiteForm> createState() => _AddSiteFormState();
}

class _AddSiteFormState extends State<_AddSiteForm> {
  final TextEditingController _host = TextEditingController();
  String? _companyId;
  String? _error;
  bool _saving = false;

  @override
  void initState() {
    super.initState();

    final me = widget.session.me!;

    // The view's company, never `companies.first` — reach is not the same
    // question as what is on screen, and the first reachable company is often
    // a sibling of the one being looked at.
    _companyId = me.filingCompanyId ??
        (me.companiesInView.length == 1 ? me.companiesInView.first.id : null);
  }

  @override
  void dispose() {
    _host.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final companyId = _companyId;

    if (companyId == null) {
      setState(() => _error = 'Choose which company owns this website.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    final response = await widget.api.post('/web/sites', {
      'company_id': companyId,
      'host': _host.text,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _error = firstApiError(response);
      });
      return;
    }

    await widget.onAdded(
      response.body['data'] as Map<String, dynamic>,
      response.body['token'] as String,
    );
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me!;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Add a website', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 12),
            if (me.offersCompanyChoice)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: SearchPickerField<String>(
                         value: _companyId,
                         label: 'Company',
                         options: [
                    for (final company in me.companiesInView)
                      SearchOption(value: company.id, label: company.name),
                  ],
                         onChanged: (value) => setState(() => _companyId = value),
                       ),
              ),
            TextField(
              controller: _host,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'Website address',
                hintText: 'nj.a-1chimney.com',
                helperText: 'A subdomain is its own website, with its own details.',
              ),
              onSubmitted: (_) => _saving ? null : _save(),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ErrorNote(_error!),
              ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.end,
              children: [
                TextButton(
                  onPressed: _saving ? null : widget.onCancel,
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: _saving ? null : _save,
                  child: const Text('Add'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// A validation message the person can act on, rather than "HTTP 422".
String firstApiError(ApiResponse response) {
  final errors = response.body['errors'];

  if (errors is Map<String, dynamic>) {
    for (final messages in errors.values) {
      if (messages is List && messages.isNotEmpty) {
        return messages.first.toString();
      }
    }
  }

  return response.message;
}

class _SiteDetail extends StatefulWidget {
  const _SiteDetail({
    this.primaryHost,
    super.key,
    required this.api,
    required this.site,
    required this.canEdit,
    required this.section,
    required this.freshToken,
    required this.onForgetToken,
    required this.onToken,
    required this.onChanged,
    this.onBulkHours,
    this.branches = const [],
  });

  final ApiClient api;
  final Map<String, dynamic> site;

  /// The company's branches this person can reach (Jose, 2026-10-05: a
  /// Branch picker per website, after A1 - Chimney MA was created).
  final List<FranchiseSummary> branches;
  final bool canEdit;

  /// Which section of the catalogue to draw. The site's values are loaded
  /// whole and filtered here, so switching sections costs no request.
  final String section;

  /// The host values fall back to, for the label on an inherited field. Null
  /// when this company has no primary, or when this site IS the primary.
  final String? primaryHost;
  final String? freshToken;
  final VoidCallback onForgetToken;
  final void Function(String token) onToken;
  final Future<void> Function() onChanged;

  /// Opens the several-websites hours editor; null without edit rights.
  final VoidCallback? onBulkHours;

  @override
  State<_SiteDetail> createState() => _SiteDetailState();
}

class _SiteDetailState extends State<_SiteDetail> {
  bool _loading = false;
  String? _error;
  String? _saved;

  /// The zone the hours were typed in, when not the site's own (Rodrigo).
  String? _hoursZone;

  /// null, 'rotate' or 'remove' — the inline confirm row standing in for the
  /// dialog §6 forbids.
  String? _confirming;

  bool _downloading = false;

  List<Map<String, dynamic>> _fields = [];
  final Map<String, TextEditingController> _controllers = {};

  String get _id => widget.site['id'] as String;
  String get _host => widget.site['host'] as String;
  bool get _connected => widget.site['connected_at'] != null;

  @override
  void initState() {
    super.initState();

    /*
     * Unconditionally, which it did not used to be: fetching was tied to
     * EXPANDING the card, and when the card became a detail pane that has no
     * expand, the fetch went with it. The pane rendered its heading and no
     * fields at all — every site looked empty. The pane is keyed by site id, so
     * choosing another site remounts this state and fetches for that one.
     */
    _loadValues();
  }

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  /// What this site would publish if it said nothing — the primary's values.
  ///
  /// Held apart from `_controllers` on purpose. A blank box on a branch is not
  /// empty, it is INHERITED, and a screen that cannot tell those apart leaves
  /// somebody wondering why their footer shows a phone number they never typed.
  Map<String, String> _inherited = {};

  String? get _primaryHost => widget.primaryHost;

  Future<void> _loadValues() async {
    setState(() {
      _loading = _fields.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/web/sites/$_id/values');

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _loading = false;
        _error = response.message;
      });
      return;
    }

    /*
     * `_loading` is cleared in a finally, and that is the important half.
     *
     * It used to be set inside the success path, so ANY throw between the await
     * and that line left the spinner turning for as long as somebody was
     * willing to watch it. The first version of this method threw on exactly
     * that path — see the map decoding below — and the failure a person saw was
     * not an error message but a panel that never opened. An exception here
     * should show as a broken panel, never as a busy one.
     */
    try {
      final data = response.body['data'] as Map<String, dynamic>;
      final values = _asMap(data['values']);

      _inherited = _asMap(data['inherited'])
          .map((k, v) => MapEntry(k, (v as String?) ?? ''));

      /*
       * Page fields and templates belong to the Pages and Templates screens.
       * Holding them here sent every page's text back as SITE-WIDE on Save,
       * which the server refuses, so on a site with page text (every one the
       * A1 swap filled) this Save could never succeed — Rodrigo, 2026-09-29.
       */
      _fields = (data['fields'] as List<dynamic>? ?? const [])
          .cast<Map<String, dynamic>>()
          .where((f) => f['section'] != 'page' && f['section'] != 'template')
          .toList();

      for (final field in _fields) {
        final key = field['key'] as String;
        _controllers.putIfAbsent(key, TextEditingController.new).text =
            (values[key] as String?) ?? '';
      }
    } catch (_) {
      _error = 'This site\'s details could not be read.';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// A JSON map that might arrive as a list, because an empty one used to.
  ///
  /// PHP encodes an empty associative array as `[]` rather than `{}`, so this
  /// field was an object on a site with details and an array on a site without.
  /// The server now always sends an object; this stays because a client that
  /// hard-casts a wire type it does not control is one deploy away from the
  /// same deadlock, and an older client will meet the old shape forever.
  Map<String, dynamic> _asMap(Object? raw) =>
      raw is Map<String, dynamic> ? raw : <String, dynamic>{};

  Future<void> _save() async {
    setState(() {
      _error = null;
      _saved = null;
    });

    final response = await widget.api.put('/web/sites/$_id/values', {
      'values': {
        for (final entry in _controllers.entries) entry.key: entry.value.text,
      },
      'hours_timezone': ?_hoursZone,
    });

    if (!mounted) return;

    setState(() {
      if (response.ok) {
        _saved = 'Saved. The website shows it within a few seconds.';
        if (_hoursZone != null) {
          // Now in the site's own time: show what was stored, and stop converting.
          final values = _asMap(_asMap(response.body['data'])['values']);
          _controllers['hours']?.text = (values['hours'] as String?) ?? '';
          _hoursZone = null;
        }
      } else {
        _error = firstApiError(response);
      }
    });

    // The count beside each website in the list.
    if (response.ok) await widget.onChanged();
  }

  /// The plugin, as a zip WordPress will accept from its own uploader.
  ///
  /// Fetched rather than linked, because the endpoint is authenticated and a
  /// plain `<a href>` carries no token.
  /// Where the plugin comes from, and why it is no longer us.
  ///
  /// This used to fetch `/web/plugin`, which builds a zip from the
  /// `wordpress-plugin/` directory ON THE BOX at request time. That was right
  /// while we were the only distributor and wrong the moment the directory
  /// approved us: the box's copy is whatever release is deployed, so it lags
  /// every plugin release until the next deploy. Jose downloaded 1.0.0 from a
  /// button beside a panel describing 1.0.2.
  ///
  /// It is the second-source-of-truth problem in miniature, and the fix is the
  /// same as everywhere else — have one source. wordpress.org serves the tag
  /// named by `Stable tag`, which is current the moment an SVN release lands
  /// and needs no deploy of ours at all.
  ///
  /// LAUNCHED, NOT FETCHED. A cross-origin XHR for the zip would need CORS
  /// headers from wordpress.org, which it does not send; a navigation has no
  /// such requirement. That is also why the server route is left in place
  /// rather than redirected — a 302 into a fetch hits the same wall, and
  /// clients already deployed still call it.
  Future<void> _downloadPlugin() async {
    setState(() {
      _downloading = true;
      _error = null;
    });

    final opened = await launchUrl(
      pluginDownloadUrl,
      mode: LaunchMode.externalApplication,
    );

    if (!mounted) return;

    setState(() {
      _downloading = false;
      if (!opened) {
        _error =
            'The download could not be opened. It is at '
            'wordpress.org/plugins/ezbizi.';
      }
    });
  }

  Future<void> _rotate() async {
    final response = await widget.api.post('/web/sites/$_id/token', {});

    if (!mounted) return;

    setState(() {
      _confirming = null;
      if (!response.ok) _error = response.message;
    });

    if (response.ok) {
      widget.onToken(response.body['token'] as String);
      await widget.onChanged();
    }
  }

  /// The browser key is public, so this is not a leak response — it is the
  /// answer to somebody polling it, or to a key that ended up somewhere the
  /// customer would rather it had not.
  ///
  /// Unlike the token there is no show-once ceremony: the new key comes back
  /// in the site payload like any other field, because it was never a secret.
  Future<void> _rotatePublicKey() async {
    final response = await widget.api.post(
      '/web/sites/$_id/public-key',
      {},
    );

    if (!mounted) return;

    setState(() {
      _confirming = null;
      if (response.ok) {
        _saved = 'New browser key issued. Paste the snippet again.';
      } else {
        _error = response.message;
      }
    });

    if (response.ok) await widget.onChanged();
  }

  /// Make this the site the company's others fall back to.
  ///
  /// No confirm row, unlike removing or rotating: nothing is destroyed and it
  /// is one click to move it elsewhere. What it DOES change is what every other
  /// site of this company publishes, so the list is reloaded afterwards — their
  /// inherited values have just moved.
  /// Where this website's enquiries land: a branch, or the company itself.
  /// Only new ones move; the leads already filed keep their place.
  Future<void> _setBranch(String? franchiseId) async {
    final response = await widget.api.patch('/web/sites/$_id', {'franchise_id': franchiseId});

    if (!mounted) return;

    setState(() {
      if (response.ok) {
        _saved = 'New enquiries from $_host now go to '
            '${franchiseId == null ? 'the company' : widget.branches.firstWhere((b) => b.id == franchiseId).name}.';
      } else {
        _error = response.message;
      }
    });

    if (response.ok) await widget.onChanged();
  }

  Widget _branchPicker(ThemeData theme) {
    final company = '${(widget.site['company'] as Map?)?['name'] ?? 'the company'}';
    final current = (widget.site['franchise'] as Map?)?['id'] as String?;

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: SearchPickerField<String?>(
        key: const Key('seo-branch'),
        value: current,
        label: 'Branch',
        helperText: 'Where chats, forms and reviews from this website land in Leads.',
        options: [
          SearchOption<String?>(value: null, label: 'No branch ($company)'),
          for (final b in widget.branches) SearchOption<String?>(value: b.id, label: b.name),
        ],
        onChanged: widget.canEdit ? (id) { if (id != current) _setBranch(id); } : null,
      ),
    );
  }

  Future<void> _makePrimary() async {
    final response = await widget.api.post('/web/sites/$_id/primary', {});

    if (!mounted) return;

    setState(() {
      if (response.ok) {
        _saved = '$_host is now the primary site.';
      } else {
        _error = response.message;
      }
    });

    if (response.ok) {
      await widget.onChanged();
      await _loadValues();
    }
  }

  Future<void> _remove() async {
    final response = await widget.api.delete('/web/sites/$_id');

    if (!mounted) return;

    setState(() {
      _confirming = null;
      if (!response.ok) _error = response.message;
    });

    if (response.ok) await widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      key: const ValueKey('tour-seo-site-detail'),
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(_host, style: theme.textTheme.titleLarge),
                        ),
                        if (widget.site['is_primary'] == true) ...[
                          const SizedBox(width: 8),
                          const Chip(
                            label: Text('Primary'),
                            visualDensity: VisualDensity.compact,
                            avatar: Icon(Icons.star, size: 14),
                          ),
                        ],
                      ],
                    ),
                    Text(
                      _connected
                          ? 'Connected · ${widget.site['company']?['name'] ?? ''}'
                          : 'Waiting for the plugin · ${widget.site['company']?['name'] ?? ''}',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.outline,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          if (_loading)
            const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: CircularProgressIndicator(),
              ),
            )
          else ...[
            if (widget.freshToken != null) _tokenBlock(theme),

            /*
             * An unconnected site cannot publish anything, so the instructions
             * for connecting it come FIRST. Once it is connected they drop
             * below the fields: setting up happens once, and editing the
             * details is what somebody opens this screen for every time after.
             */
            if (!_connected) _guideButtons(theme),

            if (widget.section == 'general' && widget.branches.isNotEmpty) _branchPicker(theme),
            _addressBlock(theme),
            _fieldGrid(theme, _plainFields),
            if (_hoursField != null) ...[
              const SizedBox(height: 4),
              HoursField(
                controller: _controllers['hours']!,
                enabled: widget.canEdit,
                label: _hoursField!['label'] as String,
                shortcode: _hoursField!['shortcode'] as String,
                inherited: _inherited['hours'] ?? '',
                inheritedFrom: _primaryHost,
              ),
              if (widget.canEdit)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 320),
                    child: SearchPickerField<String?>(
                      key: const Key('seo-hours-zone'),
                      value: _hoursZone,
                      label: 'Convert hours from',
                      helperText: _hoursZone == null
                          ? "The hours above are in this website's own time."
                          : 'The hours above are in this zone; Save converts '
                                "them into this website's time.",
                      helperMaxLines: 3,
                      options: [
                        const SearchOption<String?>(
                          value: null,
                          label: "No — this website's own time",
                        ),
                        for (final (id, label) in hourZones)
                          SearchOption<String?>(value: id, label: label),
                      ],
                      onChanged: (zone) => setState(() => _hoursZone = zone),
                    ),
                  ),
                ),
              if (widget.onBulkHours != null)
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton.icon(
                    key: const Key('seo-bulk-hours'),
                    icon: const Icon(Icons.edit_calendar_outlined, size: 18),
                    label: const Text('Edit hours on several websites'),
                    // This detail is rebuilt when the editor closes, so it
                    // reads the hours again, including any the editor wrote.
                    onPressed: widget.onBulkHours,
                  ),
                ),
              const SizedBox(height: 10),
            ],

            if (_connected) ...[
              const SizedBox(height: 8),
              _guideButtons(theme),
            ],

            if (_error != null) ErrorNote(_error!),
                        if (_saved != null)
                          Text(_saved!, style: theme.textTheme.bodySmall),
                        const SizedBox(height: 8),
                        if (widget.canEdit)
                          if (_confirming != null)
                            _confirmRow(theme)
                          else
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              alignment: WrapAlignment.end,
                              children: [
                                if (widget.site['is_primary'] != true)
                                  TextButton(
                                    onPressed: _makePrimary,
                                    child: const Text('Make primary'),
                                  ),
                                TextButton(
                                  onPressed: () =>
                                      setState(() => _confirming = 'remove'),
                                  child: const Text('Remove website'),
                                ),
                                OutlinedButton(
                                  onPressed: () =>
                                      setState(() => _confirming = 'rotate'),
                                  child: const Text('Issue a new token'),
                                ),
                                FilledButton(
                                  key: const ValueKey('tour-seo-save'),
                                  onPressed: _save,
                                  child: const Text('Save'),
                                ),
                              ],
                            ),
          ],
        ],
      ),
    );
  }

  /// Everything that is a plain box — not the address parts, not the composed
  /// line, not hours, each of which has its own treatment.
  List<Map<String, dynamic>> get _plainFields => _sectionFields
      .where((f) =>
          !_addressParts.contains(f['key']) &&
          f['key'] != 'address' &&
          f['key'] != 'hours')
      .toList(growable: false);

  /// The catalogue narrowed to the section on screen.
  ///
  /// A field the server has not put in a section still draws, under General —
  /// a client one release behind a new section shows the field in the wrong
  /// place, which is recoverable, rather than hiding a phone number, which is
  /// the failure this module exists to prevent.
  List<Map<String, dynamic>> get _sectionFields => _fields
      .where((f) => (f['section'] as String? ?? 'general') == widget.section)
      .toList(growable: false);

  Map<String, dynamic>? get _hoursField {
    for (final f in _sectionFields) {
      if (f['key'] == 'hours') return f;
    }
    return null;
  }

  /// The five parts of an address, in the order a form asks for them.
  ///
  /// Grouped rather than loose among the other fields because they are one
  /// answer typed in five boxes — and because the composed one-liner sits under
  /// them, which only reads as a result if the parts are visibly above it.
  static const List<String> _addressParts = [
    'address_line1',
    'address_line2',
    'city',
    'state',
    'postal_code',
  ];

  /// One field: a small box, its shortcode, and — when the value is not this
  /// site's own — what it inherits and where from.
  ///
  /// ── Why inherited values need their own treatment ──
  ///
  /// On a branch site most boxes are legitimately empty, because the primary
  /// answers for them. An empty box that publishes a phone number is a screen
  /// lying by omission: somebody would clear a field, see it stay blank, and
  /// find the old number still on their website. So an inherited field shows
  /// the value it will publish, greyed, with the site it came from — and typing
  /// over it is what makes this site answer for itself.
  Widget _fieldRow(ThemeData theme, Map<String, dynamic> field) {
    final key = field['key'] as String;
    final controller = _controllers[key]!;
    final inherited = _inherited[key] ?? '';
    final isInheriting = controller.text.trim().isEmpty && inherited.isNotEmpty;
    final isMissing = _isMissing(field);

    final input = TextField(
      controller: controller,
      enabled: widget.canEdit,
      style: theme.textTheme.bodyMedium,
      onChanged: (_) => setState(() {}),
      // Empty and inheriting nothing: amber, "Not filled in", label above
      // (Jose, 2026-09-25: "it's still hard to differentiate what is a filled
      // field vs what is a placeholder"). HR's record draws the same.
      decoration: notFilledIn(
        context,
        InputDecoration(
          labelText: field['label'] as String,
          isDense: true,
          // The inherited value sits in the box as a hint, so what the site
          // publishes is legible without pretending somebody typed it — in
          // italics, which nothing typed ever is.
          hintText: isInheriting ? inherited : null,
          hintStyle: theme.textTheme.bodyMedium?.copyWith(
            fontStyle: FontStyle.italic,
            color: theme.colorScheme.outline,
          ),
          filled: false,
          helperText: isInheriting
              ? 'From ${_primaryHost ?? 'the primary site'}'
              : (field['hint'] as String).isEmpty
                  ? null
                  : field['hint'] as String,
          helperMaxLines: 2,
        ),
        empty: isMissing,
      ),
    );

    return _withShortcode(theme, input, field['shortcode'] as String);
  }

  /// Publishes nothing: no value of its own and none inherited.
  ///
  /// The rule `SeoFields::missing` counts by for the sidebar, so the number
  /// beside a website is the number of amber boxes on it: General only, and
  /// not the two fields the site fills in for itself.
  bool _isMissing(Map<String, dynamic> field) {
    final key = field['key'] as String;

    if ((field['section'] as String? ?? 'general') != 'general') return false;
    if (key == 'address' || key == 'website_url') return false;

    return (_controllers[key]?.text.trim() ?? '').isEmpty &&
        (_inherited[key] ?? '').trim().isEmpty;
  }

  /// Beside the field when there is room, underneath when there is not.
  ///
  /// `[ezbizi_company_name]` in a monospace chip is about 190 logical pixels,
  /// so on a phone it would either overflow the row or squeeze the input it
  /// belongs to down to nothing.
  Widget _withShortcode(ThemeData theme, Widget input, String code) =>
      withShortcode(input, code);

  /// Two columns where there is room for two, one where there is not.
  ///
  /// The fields were full-width rows, which made eight of them a long scroll
  /// for what is mostly short text — a ZIP code does not need 900 pixels.
  Widget _fieldGrid(ThemeData theme, List<Map<String, dynamic>> fields) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= 760 ? 2 : 1;

        if (columns == 1) {
          return Column(
            children: [
              for (final f in fields)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _fieldRow(theme, f),
                ),
            ],
          );
        }

        final rows = <Widget>[];

        for (var i = 0; i < fields.length; i += 2) {
          final left = fields[i];
          final right = i + 1 < fields.length ? fields[i + 1] : null;

          rows.add(Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: _fieldRow(theme, left)),
                const SizedBox(width: 16),
                Expanded(
                  child: right == null
                      ? const SizedBox.shrink()
                      : _fieldRow(theme, right),
                ),
              ],
            ),
          ));
        }

        return Column(children: rows);
      },
    );
  }

  /// The address: its parts, then the line they build.
  Widget _addressBlock(ThemeData theme) {
    final parts = _sectionFields
        .where((f) => _addressParts.contains(f['key']))
        .toList(growable: false);

    final composed =
        _sectionFields.where((f) => f['key'] == 'address').toList();

    if (parts.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Address', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          if (widget.canEdit) ...[
            AddressLookup(
              api: widget.api,
              onPicked: _applyAddress,
              label: 'Full address',
            ),
            const SizedBox(height: 12),
          ],
          _fieldGrid(theme, parts),
          if (composed.isNotEmpty) ...[
            const Divider(height: 20),
            Text(
              'This is the line your website renders. It is built from the '
              'parts above, so correcting one corrects the footer.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.outline,
              ),
            ),
            const SizedBox(height: 8),
            _fieldRow(theme, composed.first),
          ],
        ],
      ),
    );
  }

  /// A picked suggestion, spread across the boxes it belongs to.
  ///
  /// The suite is deliberately left alone: no lookup knows one, and clearing a
  /// typed "Suite 4" because somebody re-searched the street would lose the one
  /// part of the address only a person can supply.
  void _applyAddress(Map<String, dynamic> parts) {
    const map = {
      'address_line1': 'address_line1',
      'city': 'city',
      'state': 'state',
      'postal_code': 'postal_code',
    };

    setState(() {
      map.forEach((key, from) {
        final value = parts[from];
        if (value is String) _controllers[key]?.text = value;
      });
    });
  }

  Widget _confirmRow(ThemeData theme) {
    final removing = _confirming == 'remove';
    final rekeying = _confirming == 'rotate-key';

    if (rekeying) {
      return Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          border: Border.all(color: theme.colorScheme.outlineVariant),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Issue a new browser key? Any page still carrying the old one '
              'stops updating — it keeps whatever text is written between the '
              'tags, so nothing goes blank, but it stops following $_host '
              'until you paste the new snippet in.',
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.end,
              children: [
                TextButton(
                  onPressed: () => setState(() => _confirming = null),
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: _rotatePublicKey,
                  child: const Text('Issue a new browser key'),
                ),
              ],
            ),
          ],
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            removing
                ? 'Remove $_host? Its details and its token go with it. The '
                      'plugin on that website keeps showing what it last read '
                      'until somebody removes it there too.'
                : 'Issue a new token? The one installed on $_host stops working '
                      'straight away, and that site shows its last known '
                      'details until somebody pastes the new one in.',
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            alignment: WrapAlignment.end,
            children: [
              TextButton(
                onPressed: () => setState(() => _confirming = null),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: removing ? _remove : _rotate,
                child: Text(removing ? 'Remove' : 'Issue a new token'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// The token, in the card it belongs to, until it is dismissed.
  Widget _tokenBlock(ThemeData theme) {
    final token = widget.freshToken!;

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Token for $_host', style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          const Text(
            'Copy this now. It is shown once and cannot be looked up again — '
            'if it is lost, issue a new one, which takes a moment and breaks '
            'nothing except the old copy.',
          ),
          const SizedBox(height: 8),
          SelectableText(
            token,
            style: const TextStyle(fontFamily: 'monospace'),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(
                onPressed: () =>
                    Clipboard.setData(ClipboardData(text: token)),
                icon: const Icon(Icons.copy, size: 18),
                label: const Text('Copy'),
              ),
              TextButton(
                onPressed: widget.onForgetToken,
                child: const Text('I have copied it'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Shown until the plugin has read once, and not after.
  ///
  /// The steps are the whole of the setup, in the order somebody does them.
  /// They stay while the site is unconnected because that is exactly when they
  /// are needed, and go once it has read, because a permanent install guide
  /// above a working site is noise on every later visit.
  /// The other way in, for a site that is not WordPress.
  ///
  /// ── Why this block exists at all ──
  ///
  /// The public key shipped in the API and had nowhere to appear, so the only
  /// credential this screen showed was the SECRET token. Asked for the browser
  /// key, the honest answer from anybody looking at this panel was to hand over
  /// the `ezb_` one, because it was the only key on screen. That is not a user
  /// error; a screen that shows one credential and needs another is the error.
  ///
  /// So the two are shown TOGETHER and named for what they are: `ezb_` is the
  /// plugin's, never public; `pk_` is the browser's, printed in page source on
  /// purpose. Seeing them side by side is what makes the difference legible.
  /// The two references, behind buttons.
  ///
  /// Both used to sit open on the page: five numbered steps and a script tag,
  /// above and below the fields somebody actually came here to edit. They are
  /// read once per website and never again, so they were spending the top of
  /// the screen on a first day that happens once.
  Widget _guideButtons(ThemeData theme) {
    final key = widget.site['public_key'] as String? ?? '';

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          OutlinedButton.icon(
            key: const ValueKey('tour-seo-guide'),
            onPressed: () => showSidePanel<void>(
              context,
              builder: (_) => SidePanel(
                title: 'Connection guide',
                subtitle: _host,
                child: _guideBody(theme),
              ),
            ),
            icon: const Icon(Icons.help_outline, size: 18),
            label: const Text('Connection guide'),
          ),
          if (key.isNotEmpty)
            OutlinedButton.icon(
              onPressed: () => showSidePanel<void>(
                context,
                builder: (_) => SidePanel(
                  title: 'Browser snippet',
                  subtitle: 'For a site that is not WordPress',
                  child: _snippetBody(theme, key),
                ),
              ),
              icon: const Icon(Icons.code, size: 18),
              label: const Text('Browser snippet'),
            ),
        ],
      ),
    );
  }

  Widget _guideBody(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('1. Get the EzBizi plugin from wordpress.org, below.'),
        const Text(
          '2. In WordPress, open Plugins › Add New › Upload Plugin, choose '
          'the file, and activate it.',
        ),
        const Text('3. Click EzBizi in the WordPress sidebar.'),
        const Text('4. Paste the token this site was given and save.'),
        const Text('5. Use the shortcodes beside each field anywhere on the site.'),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: _downloading ? null : _downloadPlugin,
          icon: const Icon(Icons.download_outlined, size: 18),
          label: Text(_downloading ? 'Opening…' : 'Get the plugin'),
        ),
        const SizedBox(height: 12),
        Text(
          'Lost the token? Close this and use "Issue a new token" — it takes a '
          'moment and breaks nothing except the old copy.',
          style: theme.textTheme.bodySmall,
        ),
      ],
    );
  }

  Widget _snippetBody(ThemeData theme, String key) {
    final base = snippetApiBase(
      configured: ApiClient.baseUrl,
      pageUrl: Uri.base,
    );

    final snippet =
        '<script src="$base/api/v1/web/ezbizi.js" '
        'data-key="$key"></script>';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Put this one line in the page, then mark any element with '
          'data-ezbizi to fill it:',
        ),
        const SizedBox(height: 8),
        SelectableText(
          snippet,
          style: theme.textTheme.bodySmall?.copyWith(fontFamily: 'monospace'),
        ),
        const SizedBox(height: 8),
        ShortcodeButton(code: snippet, label: 'Copy the snippet'),
        const SizedBox(height: 16),
        const SelectableText(
          '<span data-ezbizi="phone">555-0100</span>',
          style: TextStyle(fontFamily: 'monospace'),
        ),
        const SizedBox(height: 8),
        const Text(
          'Whatever is already between the tags stays if the value is blank, '
          'so the page never shows an empty gap — and a search engine still '
          'reads a real value.',
        ),
        const Divider(height: 32),
        Text('This site’s browser key', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        SelectableText(
          key,
          style: theme.textTheme.bodySmall?.copyWith(fontFamily: 'monospace'),
        ),
        const SizedBox(height: 8),
        Text(
          'Safe in public page source — it reads only what this site already '
          'publishes. The plugin token is not: it starts ezb_ and belongs on '
          'your server only.',
          style: theme.textTheme.bodySmall,
        ),
        if (widget.canEdit) ...[
          const SizedBox(height: 12),
          TextButton(
            // Closes first: the confirmation it opens is drawn on the page
            // behind this panel, where nobody would see it.
            onPressed: () {
              Navigator.of(context).pop();
              setState(() => _confirming = 'rotate-key');
            },
            child: const Text('Issue a new browser key'),
          ),
        ],
      ],
    );
  }
}

